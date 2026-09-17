<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * The instrument that names the bucket — deploy/runbook/host/throttle-store.php (issue #111).
 *
 * ⚠️ THIS IS THE CHANNEL THAT MAKES THE THROTTLE FAMILY'S PASS MEAN SOMETHING. The responses to eight
 * sign-in attempts are identical on a sound host and on one where every visitor is `127.0.0.1`, or where
 * the key is one constant per address family. Only reading the host's own rate-limiter store separates
 * them, so this instrument has to be right about three things: the key the site writes under, the count
 * it holds, and the fact that reading it changes nothing.
 *
 * ⚠️ SO IT IS RUN AGAINST A REAL BOOTED RELEASE, NOT A FAKE ONE. The cases below build a Laravel
 * application in a temporary directory, hit the REAL `WithRateLimiting` trait through the REAL limiter on
 * a file cache, and then ask the instrument what it sees. A stub store would prove only that this file
 * can print the numbers it was given.
 *
 * Needs only PHP, so it holds invariant 11: no services, no network, no Docker.
 */

beforeEach(function (): void {
    $repo = dirname(__DIR__, 3);

    $this->instrument = $repo.'/deploy/runbook/host/throttle-store.php';
    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-store-'.bin2hex(random_bytes(6));
    $this->base = $this->dir.'/releases/2026-09-17';

    foreach (['bootstrap/cache', 'config', 'storage/framework/cache/data', 'vendor'] as $path) {
        File::makeDirectory($this->base.'/'.$path, 0755, true);
    }

    // The release's own autoloader, which on a host is its own vendor tree. Here it points at the
    // repository's, so the instrument resolves the same framework and the same rate-limiting package the
    // site would.
    File::put($this->base.'/vendor/autoload.php', "<?php\n\nrequire ".var_export($repo.'/vendor/autoload.php', true).";\n");
    File::put($this->base.'/bootstrap/app.php', "<?php\n\nreturn Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->create();\n");
    File::put($this->base.'/bootstrap/providers.php', "<?php\n\nreturn [];\n");
    File::put($this->base.'/config/app.php', "<?php\n\nreturn ['key' => 'base64:".base64_encode(str_repeat('k', 32))."', 'cipher' => 'AES-256-CBC', 'env' => 'production', 'debug' => false];\n");
    File::put($this->base.'/config/cache.php', "<?php\n\nreturn ['default' => 'file', 'stores' => ['file' => ['driver' => 'file', 'path' => dirname(__DIR__).'/storage/framework/cache/data']], 'prefix' => 'kitsune-cache-'];\n");
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

const STORE_COMPONENT = 'Filament\Auth\Pages\Login';

/** Run the instrument the way the family does: the script on stdin, the request as one argv word of hex. */
function storeRun(string $instrument, array $request, array $env = []): Process
{
    $process = new Process(
        ['php', '-d', 'display_errors=stderr', '--', bin2hex((string) json_encode($request))],
        null,
        array_replace(['HOME' => (string) getenv('HOME')], $env),
        (string) File::get($instrument),
    );
    $process->setTimeout(120);
    $process->run();

    return $process;
}

/**
 * Fill a bucket the way the login page does: through the real trait, on the real limiter, in a process of
 * its own so the instrument reads a store somebody else wrote.
 */
function storeSeed(string $base, string $address, int $hits, bool $dropTimer = false): void
{
    $script = <<<'PHP'
    <?php

    [, $base, $address, $hits, $dropTimer, $component] = $argv;

    require $base.'/vendor/autoload.php';

    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $app->instance('request', Illuminate\Http\Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => $address]));

    $page = new class
    {
        use DanHarrin\LivewireRateLimiting\WithRateLimiting;

        public function hit(string $component, string $method): void
        {
            $this->hitRateLimiter($method, 60, $component);
        }

        public function keyFor(string $component, string $method): string
        {
            return $this->getRateLimitKey($method, $component);
        }
    };

    for ($at = 0; $at < (int) $hits; $at++) {
        $page->hit($component, 'authenticate');
    }

    $key = $page->keyFor($component, 'authenticate');

    if ($dropTimer === '1') {
        Illuminate\Support\Facades\Cache::store('file')->forget($key.':timer');
    }

    echo $key;
    PHP;

    $process = new Process(
        ['php', '--', $base, $address, (string) $hits, $dropTimer ? '1' : '0', STORE_COMPONENT],
        null,
        ['HOME' => (string) getenv('HOME')],
        $script,
    );
    $process->setTimeout(120);
    $process->mustRun();
}

/** The STORE line the instrument printed, decoded. */
function storeHeader(Process $run): ?array
{
    foreach (explode("\n", $run->getOutput()) as $line) {
        if (str_starts_with($line, 'STORE ')) {
            return json_decode(substr($line, strlen('STORE ')), true);
        }
    }

    return null;
}

/** The BUCKET and CHECKSUM lines the instrument printed, by label. */
function storeLines(Process $run, string $kind): array
{
    $lines = [];

    foreach (explode("\n", $run->getOutput()) as $line) {
        $fields = explode(' ', $line, 3);

        if (($fields[0] ?? '') === $kind && isset($fields[2])) {
            $lines[$fields[1]] = json_decode($fields[2], true);
        }
    }

    return $lines;
}

it('reads the bucket the release itself filled, and names it by the key the trait builds', function (): void {
    /*
     * ⚠️ THE NUMBER AND THE KEY, BOTH FROM THE HOST. A family that could only see responses cannot tell
     * whose bucket filled; this is the answer that makes its PASS mean something, so it is checked against
     * a bucket the real trait really wrote — five hits for one address, nothing for the others.
     */
    storeSeed($this->base, '203.0.113.50', 5);

    $run = storeRun($this->instrument, [
        'base' => $this->base,
        'component' => STORE_COMPONENT,
        'method' => 'authenticate',
        'labels' => ['v4' => '203.0.113.50', 'xff-1' => '192.0.2.1', 'lo4' => '127.0.0.1'],
    ]);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput());

    $buckets = storeLines($run, 'BUCKET');
    $store = storeHeader($run);

    expect($buckets['v4']['attempts'])->toBe(5)
        ->and($buckets['v4']['timer'])->toBeGreaterThan(time())
        ->and($buckets['xff-1']['attempts'])->toBe(0)
        ->and($buckets['xff-1']['timer'])->toBeNull()
        ->and($buckets['lo4']['attempts'])->toBe(0);

    // ⚠️ THE KEY IS THE PACKAGE'S, NOT THIS FILE'S IDEA OF IT. Asserted against the formula the vendored
    // trait documents, so a package that changed it would fail here rather than silently reading a bucket
    // nothing writes.
    expect($buckets['v4']['key'])
        ->toBe('livewire-rate-limiter:'.sha1(STORE_COMPONENT.'|authenticate|203.0.113.50'))
        ->and($buckets['xff-1']['key'])->not->toBe($buckets['v4']['key']);

    expect($store)->toBeArray()
        ->and($store['driver'])->toBe('file')
        ->and($store['prefix'])->toBe('kitsune-cache-')
        ->and($store['livewire_prefix'])->toStartWith('/livewire-')
        ->and($store['base'])->toBe(realpath($this->base))
        ->and($store['now'])->toBeGreaterThan(time() - 120);

    expect(storeLines($run, 'CHECKSUM')['v4']['key'])->toBe('livewire-checksum-failures:203.0.113.50');
});

it('leaves a full bucket exactly as it found it, twice over', function (): void {
    /*
     * ⚠️ THE READ THAT WOULD HAVE CHANGED THE ANSWER. `RateLimiter::tooManyAttempts()` is the obvious way
     * to ask "is this address throttled", and on a bucket at its limit whose timer has gone it RESETS the
     * count (RateLimiter.php:128-139) — so the instrument would clear the very evidence the family is about
     * to read, and the post-window read would report a host that never throttled. `availableIn()` is the
     * other trap: it answers 0 for a timer that is absent, which reads exactly like one that just expired.
     *
     * The bucket here is at five with its timer dropped — the state that makes the reset fire.
     */
    storeSeed($this->base, '203.0.113.50', 5, dropTimer: true);

    $request = [
        'base' => $this->base,
        'component' => STORE_COMPONENT,
        'method' => 'authenticate',
        'labels' => ['v4' => '203.0.113.50'],
    ];

    $first = storeRun($this->instrument, $request);
    $second = storeRun($this->instrument, $request);

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and(storeLines($first, 'BUCKET')['v4']['attempts'])->toBe(5)
        ->and(storeLines($first, 'BUCKET')['v4']['timer'])->toBeNull()
        // Read twice, and still five: nothing this instrument did touched the count.
        ->and(storeLines($second, 'BUCKET')['v4']['attempts'])->toBe(5);
});

it('refuses two labels whose buckets could not be told apart', function (): void {
    /*
     * ⚠️ THE READING THAT WOULD COMPARE A BUCKET WITH ITSELF. The family's whole question is which of these
     * addresses filled a bucket, and it is only a question while each has one of its own. Two labels for
     * one address is the caller's mistake; every label sharing a key would be the package having stopped
     * keying on the requester at all — which would make every bucket read the same and a broken host look
     * untouched.
     */
    $run = storeRun($this->instrument, [
        'base' => $this->base,
        'component' => STORE_COMPONENT,
        'method' => 'authenticate',
        'labels' => ['v4' => '203.0.113.50', 'also-v4' => '203.0.113.50'],
    ]);

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('produce the same rate-limiter key')
        ->and($run->getOutput())->not->toContain('BUCKET also-v4');
});

it('refuses a request it cannot trust, rather than reporting a zero', function (array $request, string $named): void {
    /*
     * Every one of these would otherwise print a full set of zeroes, which the family would read as "the
     * store holds nothing this run wrote" — unmeasurable, which is safe, but blames the wrong thing. A
     * refusal on stderr with a non-zero exit names the real cause, and the family quotes it.
     */
    $run = storeRun($this->instrument, array_replace([
        'base' => $this->base,
        'component' => STORE_COMPONENT,
        'method' => 'authenticate',
        'labels' => ['v4' => '203.0.113.50'],
    ], $request));

    /*
     * ⚠️ AND NO BUCKET LINE. The STORE line is printed as soon as the release boots, so a refusal after it
     * still leaves a stream — what must not be there is a reading, which the family would otherwise take
     * for a measured zero.
     */
    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain($named)
        ->and($run->getOutput())->not->toContain('BUCKET ');
})->with([
    'a base that is not a release' => [['base' => '/tmp'], 'is not a booted Laravel release'],
    'no base at all' => [['base' => ''], 'needs base, component, method and at least one label'],
    'no labels' => [['labels' => []], 'needs base, component, method and at least one label'],
    'a label that is not an address' => [['labels' => ['v4' => 'stage.kitsune.test']], 'must name a valid address'],
    'a label naming a hostname-shaped value' => [['labels' => ['v4' => '203.0.113.50/32']], 'must name a valid address'],
]);

it('refuses an argument that is not the hex of a request', function (string $argument): void {
    // The request crosses ssh, sudo and a remote shell; hex is what keeps the backslashes in the component's
    // class name intact, and anything else is a caller this instrument must not answer.
    $process = new Process(
        ['php', '-d', 'display_errors=stderr', '--', $argument],
        null,
        ['HOME' => (string) getenv('HOME')],
        (string) File::get($this->instrument),
    );
    $process->setTimeout(60);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('must be the hex of a JSON object');
})->with([
    'plain JSON' => ['{"base":"/srv"}'],
    'not hex at all' => ['zzzz'],
    'nothing' => [''],
]);

it('reads the store the limiter really uses, not the default one', function (): void {
    /*
     * ⚠️ `cache.limiter` DECIDES, AND IT IS USUALLY UNSET. When it is set, the limiter writes to that store
     * and the default one holds nothing — so an instrument that read `cache.default` would report zeroes
     * for a host that was throttling perfectly well, and the family would call a measured run unmeasurable.
     */
    File::put($this->base.'/config/cache.php', "<?php\n\nreturn ['default' => 'file', 'limiter' => 'limits', 'stores' => ["
        ."'file' => ['driver' => 'file', 'path' => dirname(__DIR__).'/storage/framework/cache/data'],"
        ."'limits' => ['driver' => 'file', 'path' => dirname(__DIR__).'/storage/framework/cache/limits'],"
        ."], 'prefix' => 'kitsune-cache-'];\n");

    storeSeed($this->base, '203.0.113.50', 5);

    $run = storeRun($this->instrument, [
        'base' => $this->base,
        'component' => STORE_COMPONENT,
        'method' => 'authenticate',
        'labels' => ['v4' => '203.0.113.50'],
    ]);

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput())
        ->and(storeHeader($run)['driver'])->toBe('limits')
        ->and(storeLines($run, 'BUCKET')['v4']['attempts'])->toBe(5)
        ->and(storeLines($run, 'BUCKET')['v4']['timer'])->toBeGreaterThan(time());
});
