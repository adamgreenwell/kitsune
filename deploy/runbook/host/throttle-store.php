<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/*
 * Which login-throttle bucket actually filled, asked of the host's own application — the instrument the
 * throttle family reads (issue #111). It arrives on stdin and runs as the release owner:
 *
 *   sudo -n -u <owner> php -d display_errors=stderr -- <hex of a JSON request>
 *
 * ⚠️ WHY THE HOST'S OWN CODE ANSWERS, AND NOT THE RESPONSES ALONE. A sixth login attempt that is
 * throttled proves only that six requests shared A bucket, never that it is the requester's. "Every
 * visitor is 127.0.0.1" and "the key is one constant per address family" both produce exactly the same
 * five failures and a throttle. Only naming the bucket separates them, and only the host can name it: the
 * key is `'livewire-rate-limiter:'.sha1($component.'|'.$method.'|'.request()->ip())`, so this binds a
 * request whose peer is the address being asked about and lets the vendored trait build the key itself.
 * That is also why it computes nothing by hand — a key formula this file copied would agree with itself
 * on a host whose package had moved on.
 *
 * ⚠️ AN INSTRUMENT, NOT A FAMILY — SO IT EMITS NO VERDICTS. run.sh dispatches one script per family named
 * in the manifest. This is driven twice around a window by the throttle family instead. Promising a check
 * id here would make the completeness gate demand a verdict from a script run.sh never dispatches. So it
 * refuses loudly — non-zero, with the reason on stderr — and prints lines its caller parses:
 *
 *   STORE {json}                 the store the limiter really uses, its prefix, the host clock, the base,
 *                                and the release's own numbers for the checksum-failure limiter
 *   BUCKET <label> {json}        one address's key, its attempts and the raw timer behind it
 *   CHECKSUM <label> {json}      the same for Livewire's checksum-failure limiter, which can 429 a run
 *
 * ⚠️ IT READS, AND READING IS NOT FREE UNLESS IT IS CHOSEN CAREFULLY. `RateLimiter::tooManyAttempts()`
 * RESETS a bucket that is at its limit with no timer (RateLimiter.php:128-139), and `availableIn()`
 * reports 0 for a timer that is absent, which reads exactly like a timer that has just expired. So this
 * calls neither: attempts come from `RateLimiter::attempts()`, which only gets, and the timer is read
 * from the store as the value it is, with `null` reported as null rather than as a number.
 *
 * ⚠️ AND NEVER AS ROOT. It boots application code; the release owner is the only user that may run it,
 * and a root euid is refused before anything is loaded. It writes nothing: no cache is warmed, no
 * migration runs, and the console kernel is bootstrapped only far enough to resolve configuration.
 */

const OWNER_REFUSED = 'throttle-store.php must run as the release owner';

/** Refuse loudly: an instrument opens no verdict stream, so its refusal is stderr and a non-zero exit. */
function storeRefuse(string $reason): never
{
    fwrite(STDERR, 'Refusing to read the store: '.$reason."\n");

    exit(1);
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    storeRefuse(OWNER_REFUSED.', never as root: application code must not be run by a user the site cannot be');
}

$argument = $argv[1] ?? '';

// ⚠️ HEX, NOT THE JSON ITSELF. The request carries a PHP class name — `Filament\Auth\Pages\Login` — and
// the argument crosses `ssh`, `sudo` and a remote shell before PHP sees it. Every one of them has its own
// opinion about a backslash, and the one that eats it produces a key for a class nobody named.
$request = json_decode((string) @hex2bin($argument), true);

if (! is_array($request)) {
    storeRefuse('the request must be the hex of a JSON object, and ['.substr($argument, 0, 60).'] is not');
}

$base = is_string($request['base'] ?? null) ? $request['base'] : '';
$component = is_string($request['component'] ?? null) ? $request['component'] : '';
$method = is_string($request['method'] ?? null) ? $request['method'] : '';
$labels = is_array($request['labels'] ?? null) ? $request['labels'] : [];

if ($base === '' || $component === '' || $method === '' || $labels === []) {
    storeRefuse('the request needs base, component, method and at least one label');
}

$real = realpath($base);

if ($real === false || ! is_file($real.'/bootstrap/app.php') || ! is_file($real.'/vendor/autoload.php')) {
    storeRefuse("[{$base}] is not a booted Laravel release: it has no bootstrap/app.php and vendor/autoload.php");
}

// The release's own autoloader and its own container: the answer has to come from the code that is
// serving the site, not from whatever this file could assemble.
require $real.'/vendor/autoload.php';

$app = require $real.'/bootstrap/app.php';

if (! $app instanceof Application) {
    storeRefuse("[{$real}]/bootstrap/app.php did not return an application");
}

$app->make(Kernel::class)->bootstrap();

/**
 * The key the host's own trait builds for a request from one address.
 *
 * ⚠️ THE TRAIT COMPUTES IT, NOT THIS FILE. `getRateLimitKey` is protected, so an anonymous class using the
 * vendored trait is the only way to ask for the real formula. A copy of it here would keep agreeing with
 * itself after the package changed, and the family's PASS would then be about a bucket nothing writes.
 */
$limiter = new class
{
    use WithRateLimiting;

    public function keyFor(string $component, string $method): string
    {
        return $this->getRateLimitKey($method, $component);
    }
};

$keys = [];
$store = config('cache.limiter') ?: config('cache.default');
$cache = Cache::store(is_string($store) ? $store : null);

/**
 * One of Livewire's own numbers for the checksum-failure limiter, or null when this release does not
 * carry it.
 *
 * ⚠️ THE PACKAGE'S NUMBER, NOT A COPY OF IT — the reason the key formula above is asked for rather than
 * computed. `Checksum::$maxFailures` failures inside `$decaySeconds` answer every later request with a
 * 429 (Checksum.php:11-12, refused at `>=`), both are protected statics, and a copy of either in the
 * runbook would keep agreeing with itself after the package moved it — which is how the family came to
 * refuse at Filament's five on a limiter that refuses at ten. Absent or not a count is reported as null,
 * never as a number the caller would then believe.
 */
$livewireLimit = static function (string $property): ?int {
    if (! class_exists(Checksum::class) || ! property_exists(Checksum::class, $property)) {
        return null;
    }

    $declared = new ReflectionProperty(Checksum::class, $property);

    // ⚠️ AND STATIC, OR NOTHING. `getValue()` takes an instance for a property that is not static, and
    // throws without one — so a package that made this one an instance property would kill the instrument,
    // and a run would be voided entirely over a number this only has to admit it could not read.
    if (! $declared->isStatic()) {
        return null;
    }

    $held = $declared->getValue();

    return is_int($held) && $held > 0 ? $held : null;
};

echo 'STORE '.json_encode([
    'base' => $real,
    'driver' => is_string($store) ? $store : (string) config('cache.default'),
    'prefix' => (string) config('cache.prefix'),
    'livewire_prefix' => EndpointResolver::prefix(),
    'component' => $component,
    'method' => $method,
    'checksum_max' => $livewireLimit('maxFailures'),
    'checksum_decay' => $livewireLimit('decaySeconds'),
    // The host's clock, for a window the operator's clock is measuring from its own side. The two are
    // never subtracted from each other: each is compared only with itself.
    'now' => time(),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

foreach ($labels as $label => $address) {
    if (! is_string($label) || ! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
        storeRefuse('every label must name a valid address, and ['.substr((string) $label, 0, 40).'] does not');
    }

    $bound = Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => $address]);
    $app->instance('request', $bound);

    $key = $limiter->keyFor($component, $method);

    // ⚠️ TWO LABELS THAT SHARE A KEY WOULD BE COMPARED WITH THEMSELVES. The whole answer this instrument
    // gives is "which of these addresses filled a bucket", and that only means something while each
    // address has a bucket of its own. Two labels naming one address is a caller's mistake; EVERY label
    // sharing one key is the package having stopped keying on `request()->ip()` at all, which would make
    // the family read one bucket for all of them and report a host as sound because nothing moved.
    if (in_array($key, $keys, true)) {
        storeRefuse("[{$label}] and [".array_search($key, $keys, true).'] produce the same rate-limiter key, so their buckets could not be told apart');
    }

    $keys[$label] = $key;
    $attempts = RateLimiter::attempts($key);

    // ⚠️ A COUNT THAT IS NOT A NUMBER IS NOT A ZERO. Some stores hand back a string, and one hands back
    // whatever was serialized into that key by something else entirely. The family compares counts, so a
    // value it cannot compare is refused here rather than being cast into a quiet 0.
    if (! is_int($attempts) && ! (is_string($attempts) && preg_match('/^\d+$/', $attempts) === 1)) {
        storeRefuse("the attempts for [{$label}] came back as ".get_debug_type($attempts).', which is not a count');
    }

    $timer = $cache->get($key.':timer');

    echo 'BUCKET '.$label.' '.json_encode([
        'address' => $address,
        'key' => $key,
        'attempts' => (int) $attempts,
        // null, not 0: an absent timer and a timer of zero decide a window differently.
        'timer' => is_numeric($timer) ? (int) $timer : null,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

    // Livewire's own limiter, keyed on the same ip(): `checksum_max` failures inside `checksum_decay`
    // answer every later request with a 429, which would void a run for a reason that has nothing to do
    // with the throttle. The count is here and the maximum is on the STORE line, because the maximum is
    // the release's, not this address's.
    $checksum = 'livewire-checksum-failures:'.$address;

    echo 'CHECKSUM '.$label.' '.json_encode([
        'key' => $checksum,
        'attempts' => (int) RateLimiter::attempts($checksum),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
}

echo 'STATE store read '.count($labels)." labels\n";
