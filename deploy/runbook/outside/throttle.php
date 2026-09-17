<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);
use Dom\HTMLDocument;

require_once __DIR__.'/lib.php';

/*
 * What the login throttle counts by, measured from outside — issue #111.
 *
 * ADR-034 lets the skeleton trust `127.0.0.1` and `::1` for `X-Forwarded-For`. Everything downstream of
 * that trust believes `request()->ip()`, and Filament's login throttle is the part of it a visitor can
 * reach without an account: five failures per address per minute. If a forged header could reach `ip()`,
 * the throttle would count by a value the attacker chooses — five attempts under one forged address, five
 * more under the next — and the protection would be gone while every response still looked right.
 *
 * ⚠️ IT RUNS ON THE OPERATOR'S MACHINE, NOT ON THE HOST, AND THAT IS THE POINT. A request made on the
 * server would traverse neither the edge nor the tunnel, and both are what decide which address arrives.
 *
 * ⚠️ THREE CHANNELS, BECAUSE THE RESPONSES ALONE CANNOT SEPARATE A SOUND HOST FROM A BROKEN ONE. A
 * throttled sixth attempt proves that six requests shared A bucket, never whose. "Every visitor is
 * 127.0.0.1" and "one bucket per address family" produce the same five rejections and the same throttle.
 * So this agrees three independent answers before it will report a PASS:
 *
 *   1. the responses, classified STRUCTURALLY from `memo.errors` decoded out of the Livewire snapshot;
 *   2. the probe log, which says what nginx received — that the forged header really arrived;
 *   3. the host's own rate-limiter store, read through host/throttle-store.php, which NAMES the bucket.
 *
 * ⚠️ NEVER BY BODY TEXT. Every 200 re-renders the login form, and that form contains `data.email`, so
 * "the body mentions data.email" is true of a throttled answer too. A family that matched on text would
 * report five rejections and a rejection, and call a throttle a failure to throttle.
 *
 * ⚠️ WHAT A RUN DOES TO THE SERVER. It signs in wrongly eight times and then leaves the login throttle
 * tripped for the operator's egress address for up to 60 seconds. The key carries no hostname, so that
 * lockout covers EVERY hostname the app serves at once, for anyone sharing that egress. Run it against
 * stage or a not-yet-live alpha, in a maintenance window. It installs the probe log, which undoes itself,
 * and writes nothing else.
 *
 * ⚠️ THREE CHECK IDS, NOT ONE PER HOSTNAME. manifest.txt is static and committed, and a server's
 * hostnames are its own property, so a per-hostname id could never be promised in advance. THR-1 covers
 * the requests across every hostname the server serves, THR-2 covers what the bucket is keyed on, and
 * THR-3 covers the probe log being removed afterwards.
 */

const FAMILY = 'throttle';

/**
 * Every check this family can report, and nothing else — the outside counterpart of a host family's
 * `family` line. verdict() refuses any other id, and RunbookManifestTest holds this list to the rows
 * manifest.txt promises the family.
 */
const CHECKS = ['THR-1', 'THR-2', 'THR-3'];

/**
 * ⚠️ TUNNEL ALONE, FOR NOW, AND THE MANIFEST SAYS THE SAME. The throttle is meaningful without a tunnel,
 * and the dns-only rows land with the alpha bring-up, when a real dns-only host has proved them — the
 * runbook's rule is that a family is promised only for a topology some host has exercised
 * (deploy/runbook/README.md). Declaring dns-only here before then would promise a verdict no run has ever
 * produced. Sent to any other topology this voids its checks and says so, rather than skipping, which the
 * completeness gate would read as a family that died.
 */
const TOPOLOGIES = ['tunnel'];

/** Filament's `rateLimit(5)` on the login page: five hits fill the bucket, and the sixth is thrown. */
const LIMIT = 5;

/**
 * The forged left-hand entries, one per attempt.
 *
 * ⚠️ DISTINCT PER ATTEMPT, VALID, AND UNTRUSTED. Identical values would share one forged bucket and still
 * throttle at six — a forged header that reached `ip()` would then read exactly like a sound host. Symfony
 * drops an entry that `filter_var` rejects and drops `127.0.0.1`/`::1` as trusted, so a forgery in either
 * of those shapes would prove nothing about what the host does with one it keeps. RFC 5737 documentation
 * space is valid, kept, and not routable.
 */
const FORGED_DASHED = '192.0.2.';

/** The same, for the underscore spelling, which FPM maps onto the same PHP variable. */
const FORGED_UNDERSCORE = '198.51.100.';

/** RFC 6761 reserves `.invalid`, so no address here can belong to a real account. */
const EMAIL_DOMAIN = '@kitsune-runbook.invalid';

/** Fixed, and not a password: the check never sends a credential that could be right. */
const NOT_A_PASSWORD = 'kitsune-runbook-not-a-password';

/**
 * How long after the first attempt the family still trusts the window, and how long it will wait out a
 * bucket somebody else filled. Seams, because the only other way to exercise either is a real 60 seconds.
 */
function windowGuard(): int
{
    $seam = getenv('KITSUNE_THROTTLE_WINDOW_GUARD');

    return is_string($seam) && preg_match('/^\d+$/', $seam) === 1 ? (int) $seam : 40;
}

function maxWait(): int
{
    $seam = getenv('KITSUNE_THROTTLE_MAX_WAIT');

    return is_string($seam) && preg_match('/^\d+$/', $seam) === 1 ? (int) $seam : 65;
}

/**
 * The running configuration, as words: every directive and every block, with quoting and comments already
 * accounted for.
 *
 * ⚠️ A TOKENIZER, NOT A LINE SCAN OR A REGEX. This family needs each hostname's document root, and a root
 * only means something inside the `server` block that names the hostname — which no line-based scan can
 * tie together. A regex over the dump would repeat #118. And the dump this reads contains the probe log's
 * own snippet, whose `log_format` holds braces, semicolons and double quotes INSIDE single-quoted strings:
 * a brace counter that did not know about quotes would close the http block in the middle of a string and
 * read every server after it as nested. So quotes come first, then comments, then the punctuation.
 *
 * @return list<array{0: string, 1: list<string>}> the terminator, and the words before it
 */
function nginxTokens(string $dump): array
{
    $tokens = [];
    $words = [];
    $word = '';
    $quote = '';
    $length = strlen($dump);

    for ($at = 0; $at < $length; $at++) {
        $char = $dump[$at];

        if ($quote !== '') {
            if ($char === $quote) {
                $quote = '';
            } else {
                $word .= $char;
            }

            continue;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;

            continue;
        }

        if ($char === '#') {
            while ($at < $length && $dump[$at] !== "\n") {
                $at++;
            }

            $char = ' ';
        }

        if ($char === ' ' || $char === "\t" || $char === "\n" || $char === "\r") {
            if ($word !== '') {
                $words[] = $word;
                $word = '';
            }

            continue;
        }

        if ($char === ';' || $char === '{' || $char === '}') {
            if ($word !== '') {
                $words[] = $word;
                $word = '';
            }

            $tokens[] = [$char, $words];
            $words = [];

            continue;
        }

        $word .= $char;
    }

    return $tokens;
}

/**
 * Each site hostname the server serves, and the document roots the blocks naming it declare.
 *
 * A `root` inside a `location` is that location's, not the site's, so only a root at the server block's
 * own level counts. The catch-all's `_` is not a site.
 *
 * @return array<string, list<string>>
 */
function siteRoots(string $dump): array
{
    $sites = [];
    $depth = 0;
    $serverAt = null;
    $names = [];
    $roots = [];

    foreach (nginxTokens($dump) as [$terminator, $words]) {
        if ($terminator === '{') {
            $depth++;

            if (($words[0] ?? '') === 'server' && $serverAt === null) {
                $serverAt = $depth;
            }

            continue;
        }

        if ($terminator === '}') {
            if ($serverAt === $depth) {
                // The block is closed where it closes: a by-reference closure put every write to these
                // out of reach of the analyser, which then read each of these loops as one over nothing.
                foreach ($names as $name) {
                    $sites[$name] = array_replace($sites[$name] ?? [], array_fill_keys($roots, true));
                }

                $names = [];
                $roots = [];
                $serverAt = null;
            }

            $depth--;

            continue;
        }

        if ($serverAt !== $depth) {
            continue;
        }

        if (($words[0] ?? '') === 'server_name') {
            foreach (array_slice($words, 1) as $name) {
                if ($name !== '' && $name !== '_') {
                    $names[] = $name;
                }
            }
        }

        if (($words[0] ?? '') === 'root' && isset($words[1])) {
            $roots[] = rtrim($words[1], '/');
        }
    }

    $found = [];

    foreach ($sites as $name => $declared) {
        $found[$name] = array_keys($declared);
    }

    ksort($found);

    return $found;
}

/**
 * The one release base every served hostname points at.
 *
 * ⚠️ ONE BASE, OR NONE. The store instrument boots the application at this path, and a host whose
 * hostnames resolve to two different applications has two different caches and two different throttles:
 * which one a bucket belongs to could not be said, so it is unmeasurable rather than a guess.
 *
 * @param  array<string, list<string>>  $sites
 * @return array{0: string, 1: string} the base, and why there is none when there is none
 */
function releaseBase(array $sites): array
{
    $bases = [];

    foreach ($sites as $name => $roots) {
        $public = array_values(array_filter($roots, static fn (string $root): bool => str_ends_with($root, '/public')));

        if ($public === []) {
            return ['', "[{$name}] has no server-level root ending in /public in the running configuration ("
                .($roots === [] ? 'it declares none' : 'it declares '.implode(', ', $roots)).')'];
        }

        foreach ($public as $root) {
            $bases[substr($root, 0, -strlen('/public'))] = true;
        }
    }

    $bases = array_keys($bases);

    if (count($bases) !== 1) {
        return ['', $bases === []
            ? 'no served hostname declares a document root, so there is no release to read the store from'
            : 'the served hostnames point at '.count($bases).' different releases ('.implode(', ', $bases).')'];
    }

    return [$bases[0], ''];
}

/**
 * The login page, fetched into its own cookie jar.
 *
 * ⚠️ curl OWNS THE JAR, AND PHP NEVER PARSES IT. The session cookie's line begins `#HttpOnly_`, and a
 * reader that skips `#` lines — every naive cookie-file parser does — would drop exactly the cookie the
 * POST needs and turn every attempt into a 419 that looks like a host rejecting the token. The jar is also
 * per hostname, because the cookie is host-only.
 *
 * @return array{0: array<string, string>, 1: string} the page, and why there is none when there is none
 */
function loginPage(string $hostname, string $jar, string $work, string $tag): array
{
    $body = $work.'/page-'.$tag;
    [$status, $out, $err] = run([
        'curl', '-4', '-sS', '--max-time', '20', '-c', $jar, '-b', $jar,
        '-w', '%{http_code} %{content_type}\n', '-o', $body,
        'https://'.$hostname.'/admin/login',
    ], '', 60);

    if ($status !== 0) {
        return [[], "{$hostname}: the login page could not be fetched ({$status}): ".substr(oneLine($err), 0, 160)];
    }

    $code = preg_split('/\s+/', trim($out)) ?: [];

    if (($code[0] ?? '') !== '200') {
        return [[], "{$hostname}: /admin/login answered ".($code[0] ?? '?').', so there was no page to sign in from'];
    }

    return parseLogin((string) @file_get_contents($body), $hostname);
}

/**
 * The token, the update path and the login component's own snapshot, read from the page's markup.
 *
 * ⚠️ THE SNAPSHOT IS SELECTED BY THE FORM, NOT BY POSITION. The page carries two `wire:snapshot`
 * attributes — the login page's and Filament's notifications component — and which comes first is a
 * property of the template, not of the protocol. The one this needs is the ancestor of the form whose
 * `wire:submit` is `authenticate`.
 *
 * ⚠️ AND IT IS SENT BACK VERBATIM. The server recomputes the checksum over its own re-encoding of what it
 * decodes, so re-encoding is safe in itself — but re-encoding through an associative array is not, because
 * it can reorder keys. The attribute's value, as the DOM gives it, is what goes back.
 *
 * ⚠️ AND THE PATH, NOT THE ABSOLUTE URI. `data-update-uri` is built with `url()`, which follows the
 * request's host, but a canonical-host configuration could still name another hostname — and posting there
 * would drop the host-only session cookie and produce a 419 that reads like a host rejecting the token.
 *
 * @return array{0: array<string, string>, 1: string}
 */
function parseLogin(string $html, string $hostname): array
{
    if (trim($html) === '') {
        return [[], "{$hostname}: the login page was empty"];
    }

    $document = @HTMLDocument::createFromString($html, LIBXML_NOERROR);

    $meta = '';

    foreach ($document->getElementsByTagName('meta') as $tag) {
        if ($tag->getAttribute('name') === 'csrf-token') {
            $meta = (string) $tag->getAttribute('content');
        }
    }

    $uri = '';
    $csrf = '';

    foreach ($document->getElementsByTagName('script') as $tag) {
        if ($tag->hasAttribute('data-update-uri')) {
            $uri = (string) $tag->getAttribute('data-update-uri');
            $csrf = (string) $tag->getAttribute('data-csrf');
        }
    }

    if ($meta === '' || $uri === '') {
        return [[], "{$hostname}: the login page carries no csrf-token meta or no data-update-uri, so it is not a Livewire login page"];
    }

    // Two spellings of one token: if they disagree, which one the server would accept is a guess.
    if ($csrf !== '' && ! hash_equals($meta, $csrf)) {
        return [[], "{$hostname}: the page's csrf-token meta and its data-csrf differ, so which token the server holds cannot be told"];
    }

    $path = (string) parse_url($uri, PHP_URL_PATH);
    $host = (string) parse_url($uri, PHP_URL_HOST);

    if (preg_match('#^/livewire-[0-9a-f]{8}/update$#', $path) !== 1) {
        return [[], "{$hostname}: the update URI [{$uri}] does not name a Livewire update endpoint"];
    }

    if ($host !== '' && $host !== $hostname) {
        return [[], "{$hostname}: the page's update URI names [{$host}], so a POST would leave the hostname the session belongs to"];
    }

    $snapshot = '';

    foreach ($document->getElementsByTagName('form') as $form) {
        if ($form->getAttribute('wire:submit') !== 'authenticate') {
            continue;
        }

        for ($node = $form; $node !== null; $node = $node->parentElement) {
            if ($node->hasAttribute('wire:snapshot')) {
                $snapshot = (string) $node->getAttribute('wire:snapshot');

                break 2;
            }
        }
    }

    if ($snapshot === '') {
        return [[], "{$hostname}: no form with wire:submit=authenticate sits inside a wire:snapshot, so there is no login component to call"];
    }

    $decoded = json_decode($snapshot, true);
    $memo = is_array($decoded) ? ($decoded['memo'] ?? []) : [];

    if (! is_array($memo) || ! is_string($memo['name'] ?? null) || ! is_string($memo['id'] ?? null)) {
        return [[], "{$hostname}: the login component's snapshot has no memo naming it"];
    }

    // ⚠️ A PAGE THAT ALREADY CARRIES AN ERROR IS NOT A STARTING POINT. Errors ride in the snapshot and are
    // restored when the server hydrates it, so a page fetched with one would put a `data.email` into the
    // first attempt, and the attempt after the throttle would look like a rejection.
    if (($memo['errors'] ?? []) !== []) {
        return [[], "{$hostname}: the login page arrived carrying validation errors, so an attempt from it would not start clean"];
    }

    return [[
        'hostname' => $hostname,
        'token' => $meta,
        'path' => $path,
        'snapshot' => $snapshot,
        'name' => $memo['name'],
        'id' => $memo['id'],
    ], ''];
}

/**
 * The request body one attempt sends, matching what the Livewire client sends for a submitted form.
 *
 * @param  array<string, string>  $page
 */
function attemptBody(array $page, string $email): string
{
    return (string) json_encode([
        '_token' => $page['token'],
        'components' => [[
            'snapshot' => $page['snapshot'],
            'updates' => ['data.email' => $email, 'data.password' => NOT_A_PASSWORD],
            'calls' => [['method' => 'authenticate', 'params' => [], 'metadata' => new stdClass]],
        ]],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * One attempt: the edge's view of the requester, the POST, and the edge's view again — on one connection.
 *
 * ⚠️ `-4`/`-6` GOES ON EVERY GROUP. curl resets the local options at each `--next`, so a family flag given
 * once binds only the first transfer and the rest resolve however the resolver likes. The family it
 * actually used is then read back out of `%{json}`-style statistics rather than assumed from the flag.
 *
 * ⚠️ AND "THROUGH THE EDGE" IS NOT A RESPONSE HEADER. `server: cloudflare` and `cf-ray` are writable by any
 * origin; what an origin cannot do is answer `/cdn-cgi/trace` on the same connection as the request.
 *
 * @param  array<string, string>  $page
 * @return array{0: int, 1: list<list<string>>, 2: array<string, string>, 3: string}
 */
function attempt(array $page, string $family, string $jar, string $probeId, ?int $forged, string $email, string $work, string $tag): array
{
    $hostname = $page['hostname'];
    $trace = 'https://'.$hostname.'/cdn-cgi/trace';
    $stats = '%{http_code} %{num_connects} %{local_ip} %{local_port} %{remote_ip} %{content_type}\n';
    $paths = [
        'before' => $work.'/trace-before-'.$tag,
        'body' => $work.'/body-'.$tag,
        'after' => $work.'/trace-after-'.$tag,
        'payload' => $work.'/payload-'.$tag.'.json',
    ];

    file_put_contents($paths['payload'], attemptBody($page, $email));

    $headers = [
        '-H', 'X-Livewire: 1',
        '-H', 'Content-Type: application/json',
        '-H', 'Accept: application/json',
        '-H', 'X-Kitsune-Probe: '.$probeId,
    ];

    if ($forged !== null) {
        // The canonical spelling first, then the underscore one: FPM keeps the later of two headers that
        // map onto the same variable, so this order is what puts the underscore value in front of PHP.
        $headers[] = '-H';
        $headers[] = 'X-Forwarded-For: '.FORGED_DASHED.$forged;
        $headers[] = '-H';
        $headers[] = 'X_Forwarded_For: '.FORGED_UNDERSCORE.$forged;
    }

    [$status, $out, $err] = run([
        'curl', $family, '-sS', '--max-time', '20', '-w', $stats, '-o', $paths['before'], $trace,
        '--next', $family, '-sS', '--max-time', '20', '-w', $stats, '-o', $paths['body'],
        '-c', $jar, '-b', $jar, ...$headers,
        '--data-binary', '@'.$paths['payload'],
        'https://'.$hostname.$page['path'],
        '--next', $family, '-sS', '--max-time', '20', '-w', $stats, '-o', $paths['after'], $trace,
    ], '', 90);

    $rows = [];

    foreach (preg_split('/\r?\n/', trim($out)) ?: [] as $row) {
        if (trim($row) !== '') {
            $rows[] = preg_split('/\s+/', trim($row)) ?: [];
        }
    }

    return [$status, $rows, $paths, $err];
}

/**
 * What the server made of one attempt, read from the Livewire snapshot in its answer.
 *
 * ⚠️ STRUCTURALLY, NEVER BY BODY TEXT. Every 200 re-renders the form, and the form contains `data.email`,
 * so a text match would call a throttle a rejection. A rejection is `memo.errors` carrying `data.email`
 * with no notification; a throttle is empty `memo.errors` with the notification Filament sends when it
 * catches the limiter's exception. Anything else — any other status, any other component, a shape this
 * does not recognise — is unmeasurable, and the caller stops rather than guessing.
 *
 * @param  array<string, string>  $page
 * @param  list<string>  $row
 * @return array{0: string, 1: string} FAILED, THROTTLED or VOID, and its detail
 */
function classify(array $page, array $row, string $bodyPath): array
{
    $code = $row[0] ?? '';
    $type = $row[5] ?? '';

    if ($code !== '200') {
        return ['VOID', "it answered {$code}, so the request never reached the throttle"
            .($code === '419' ? ' (a token, a checksum or a release-token guard answered first)' : '')];
    }

    $body = (string) @file_get_contents($bodyPath);

    if (! str_contains($type, 'json')) {
        return ['VOID', "it answered 200 as [{$type}] rather than JSON, so it is not a Livewire answer"];
    }

    $decoded = json_decode($body, true);
    $components = is_array($decoded) ? ($decoded['components'] ?? null) : null;

    if (! is_array($components) || count($components) !== 1) {
        return ['VOID', 'the answer carried '.(is_array($components) ? count($components) : 'no').' components, and an attempt calls exactly one'];
    }

    $snapshot = json_decode((string) ($components[0]['snapshot'] ?? ''), true);
    $memo = is_array($snapshot) ? ($snapshot['memo'] ?? []) : [];

    if (! is_array($memo)) {
        return ['VOID', "the answer's snapshot has no memo"];
    }

    if (($memo['name'] ?? '') !== $page['name'] || ($memo['id'] ?? '') !== $page['id']) {
        return ['VOID', 'the answer is for ['.(string) ($memo['name'] ?? '?').'], not the login component the page carried'];
    }

    $errors = $memo['errors'] ?? null;
    $notified = false;

    foreach ((array) ($components[0]['effects']['dispatches'] ?? []) as $dispatch) {
        if (is_array($dispatch) && ($dispatch['name'] ?? '') === 'notificationsSent') {
            $notified = true;
        }
    }

    $said = is_array($errors) ? ($errors['data.email'] ?? null) : null;

    if (is_array($said) && $said !== [] && ! $notified) {
        return ['FAILED', (string) ($said[0] ?? '')];
    }

    if ($errors === [] && $notified) {
        return ['THROTTLED', ''];
    }

    return ['VOID', 'the answer carried '.(is_array($errors) ? count($errors) : 0).' validation errors and '
        .($notified ? 'a notification' : 'no notification').', which is neither a rejected sign-in nor a throttle'];
}

/**
 * Ask the host's own application which buckets hold what.
 *
 * @param  array<string, string>  $labels
 * @return array{0: array<string, mixed>, 1: string}
 */
function storeRead(string $host, string $owner, string $base, array $labels, string $component, string $source): array
{
    $request = bin2hex((string) json_encode([
        'base' => $base,
        'component' => $component,
        'method' => 'authenticate',
        'labels' => $labels,
    ], JSON_THROW_ON_ERROR));

    // ⚠️ AS THE OWNER, NEVER AS ROOT, AND NEVER THROUGH A SHELL. The release's own user is the only one
    // whose view of the cache is the site's; and the request is one argv word of hex, so no shell between
    // here and PHP can eat the backslashes in the component's class name.
    [$status, $out, $err] = run([
        'ssh', '-o', 'BatchMode=yes', '-o', 'ClearAllForwardings=yes', '-o', 'ConnectTimeout=10',
        $host, 'sudo', '-n', '-u', $owner, 'php', '-d', 'display_errors=stderr', '--', $request,
    ], $source, 120);

    if ($status !== 0) {
        return [[], 'the store could not be read ('.$status.'): '.substr(oneLine($err !== '' ? $err : $out), 0, 220)];
    }

    $read = ['store' => [], 'buckets' => [], 'checksums' => []];

    foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
        $fields = explode(' ', $line, 3);

        if ($fields[0] === 'STORE') {
            $read['store'] = json_decode(substr($line, strlen('STORE ')), true) ?: [];
        } elseif ($fields[0] === 'BUCKET' && isset($fields[2])) {
            $read['buckets'][$fields[1]] = json_decode($fields[2], true) ?: [];
        } elseif ($fields[0] === 'CHECKSUM' && isset($fields[2])) {
            $read['checksums'][$fields[1]] = json_decode($fields[2], true) ?: [];
        }
    }

    if ($read['store'] === [] || count($read['buckets']) !== count($labels)) {
        return [[], 'the store instrument answered with '.count($read['buckets']).' of '.count($labels)
            .' buckets: '.substr(oneLine($out), 0, 220)];
    }

    return [$read, ''];
}

/**
 * The attempts a store read reports for one label, or -1 when it reported none.
 *
 * @param  array<string, mixed>  $read
 */
function bucket(array $read, string $label): int
{
    $attempts = $read['buckets'][$label]['attempts'] ?? null;

    return is_int($attempts) ? $attempts : -1;
}

/**
 * The probe line nginx wrote for one attempt.
 *
 * @return array{0: array<string, string>, 1: string}
 */
function probeLine(string $host, string $nonce, string $probeId, string $payload): array
{
    [$status, $out, $err] = instrument($host, ['collect', $nonce, $probeId], $payload);

    if ($status !== 0) {
        return [[], 'the probe line could not be collected: '.substr(oneLine($err !== '' ? $err : $out), 0, 200)];
    }

    foreach (preg_split('/\r?\n/', $out) ?: [] as $row) {
        if (str_starts_with($row, 'PROBE '.$probeId.' ')) {
            $decoded = json_decode(substr($row, strlen('PROBE '.$probeId.' ')), true);

            if (is_array($decoded)) {
                return [array_map('strval', $decoded), ''];
            }
        }
    }

    return [[], "the instrument printed no line for {$probeId}"];
}

/**
 * Everything one attempt's transport had to be, before anything it answered is allowed to mean something.
 *
 * @param  list<list<string>>  $rows
 * @param  array<string, string>  $paths
 * @return array{0: string, 1: string} the edge's view of the requester, and why there is none
 */
function edgeAddress(array $rows, array $paths, string $family, string $hostname): array
{
    if (count($rows) !== 3) {
        return ['', "{$hostname}: the three transfers did not complete, so this attempt did not cross the edge as one connection"];
    }

    foreach ([$rows[1], $rows[2]] as $row) {
        if (($row[1] ?? '') !== '0') {
            return ['', "{$hostname}: a transfer after the first opened a new connection, so the trace and the attempt did not share one"];
        }
    }

    if (array_slice($rows[0], 2, 2) !== array_slice($rows[2], 2, 2)) {
        return ['', "{$hostname}: the local address moved between the first and last transfer, so these were not one connection"];
    }

    $local = $rows[1][2] ?? '';
    $remote = $rows[1][4] ?? '';
    $wanted = $family === '-6' ? 'v6' : 'v4';

    // ⚠️ THE FAMILY IS READ, NOT ASSUMED. A `-6` group that resolved over IPv4 anyway, or a mapped
    // `::ffff:` address, would fill the very bucket this is trying to show is separate.
    foreach (['local' => $local, 'remote' => $remote] as $end => $address) {
        $is = str_contains($address, ':') && ! str_starts_with($address, '::ffff:') ? 'v6' : 'v4';

        if ($is !== $wanted) {
            return ['', "{$hostname}: a {$family} attempt had a {$is} {$end} address [{$address}], so it did not leave over the family it was meant to"];
        }
    }

    $before = traceAddress($paths['before']);
    $after = traceAddress($paths['after']);

    if ($before === '' || $before !== $after) {
        return ['', "{$hostname}: the edge reported the requester as [{$before}] then [{$after}]"];
    }

    return [$before, ''];
}

/**
 * The edge's view of the requester over one address family, before the window opens.
 *
 * @return array{0: string, 1: string} the address, and why there is none when there is none
 */
function preflight(string $hostname, string $family, string $work): array
{
    $path = $work.'/preflight'.$family;
    [$status, , $err] = run([
        'curl', $family, '-sS', '--max-time', '20', '-w', '%{http_code}\n', '-o', $path,
        'https://'.$hostname.'/cdn-cgi/trace',
    ], '', 40);

    if ($status !== 0) {
        return ['', "curl {$family} could not reach {$hostname} ({$status}): ".substr(oneLine($err), 0, 160)];
    }

    $address = traceAddress($path);

    return $address === ''
        ? ['', "the edge's trace over {$family} named no requester"]
        : [$address, ''];
}

/**
 * Everything the two measured checks need, gathered in one pass over one 60-second window.
 *
 * ⚠️ ONE WINDOW, AND ONE BUCKET FOR EVERY HOSTNAME. The key holds the component, the method and the
 * address — and no hostname (WithRateLimiting.php:27), so all of an app's hostnames share one bucket per
 * address. Five independent first attempts per hostname would therefore contaminate each other inside one
 * window; the full five-and-a-sixth runs once, and every other hostname is proved with a single already
 * throttled probe. What makes that single probe mean something is the store read, which names the bucket
 * it landed in.
 *
 * ⚠️ AND THE CLOCK THAT MATTERS IS THE HOST'S. The 60 seconds are armed by the bucket's FIRST hit and
 * never refreshed, so the operator's elapsed time is only ever used as a duration: the two clocks are
 * never subtracted from one another.
 *
 * @return array{0: array{0: string, 1: string}, 1: array{0: string, 1: string}}
 */
function examine(string $host, string $nonce, string $payload, string $storeSource, string $work): array
{
    $both = static fn (string $reason): array => [['VOID', $reason], ['VOID', $reason]];

    [$dump, $unreadable] = nginxDump($host);

    if ($unreadable !== '') {
        return $both('the running configuration could not be read, so there was nothing to sign in to: '.$unreadable);
    }

    $sites = siteRoots($dump);

    if ($sites === []) {
        return $both('no site hostname was found in the running configuration, so there was nothing to sign in to');
    }

    [$base, $why] = releaseBase($sites);

    if ($base === '') {
        return $both('the release the throttle belongs to could not be named: '.$why);
    }

    [$status, $out, $err] = run(['ssh', '-n', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10',
        $host, 'stat', '-c', '%U', '--', $base.'/bootstrap/app.php'], '', 60);
    $owner = trim($out);

    if ($status !== 0 || preg_match('/^[a-z_][a-z0-9_-]*$/', $owner) !== 1) {
        return $both("the owner of {$base}/bootstrap/app.php could not be read, so the store cannot be asked as the site: "
            .substr(oneLine($err !== '' ? $err : $out), 0, 160));
    }

    if ($owner === 'root') {
        return $both("{$base}/bootstrap/app.php is owned by root, and this will not run application code as root");
    }

    $hostnames = array_keys($sites);
    $first = $hostnames[0];

    // ⚠️ THE EDGE'S OWN VIEW, BEFORE ANYTHING IS COUNTED. The bucket is keyed on the address the host
    // resolves, which behind a tunnel is the entry the edge appends — so the label the store is asked
    // about has to be the address the edge says it sees, not a guess about the operator's network.
    [$edge4, $noV4] = preflight($first, '-4', $work);

    if ($edge4 === '') {
        return $both("the edge's view of this machine over IPv4 could not be read, so no bucket could be named: ".$noV4);
    }

    [$edge6, $noV6] = preflight($first, '-6', $work);

    // The pages, one session per hostname, and a second session on the first for the attempt that proves
    // the key is not per session.
    $pages = [];
    $jars = [];

    foreach ($hostnames as $index => $hostname) {
        $jars[$hostname] = $work.'/jar-'.$index;
        [$page, $unusable] = loginPage($hostname, $jars[$hostname], $work, (string) $index);

        if ($page === []) {
            return $both('a login page could not be read, so no attempt would have meant anything: '.$unusable);
        }

        $pages[$hostname] = $page;
    }

    $secondJar = $work.'/jar-second';
    [$secondPage, $unusable] = loginPage($first, $secondJar, $work, 'second');

    if ($secondPage === []) {
        return $both('a second session on '.$first.' could not be opened, and without one the key cannot be shown to be free of the session: '.$unusable);
    }

    // ⚠️ A SECOND SESSION, PROVEN TO BE ONE. The attempt that shows the key holds no session needs a
    // session that is actually different: a page served from the same session carries the same component
    // id and the same token, and an attempt from it would test nothing at all.
    if ($secondPage['id'] === $pages[$first]['id'] || $secondPage['token'] === $pages[$first]['token']) {
        return $both('the second login page came back as the same session as the first, so an attempt from it would prove nothing about the key');
    }

    // Every address a bucket could have been written under, named before anything is written.
    $forgeries = LIMIT + 1 + count($hostnames) - 1;
    $labels = ['v4' => $edge4, 'lo4' => '127.0.0.1', 'lo6' => '::1'];

    if ($edge6 !== '') {
        $labels['v6'] = $edge6;
    }

    for ($n = 1; $n <= $forgeries; $n++) {
        $labels['xff-'.$n] = FORGED_DASHED.$n;
        $labels['usc-'.$n] = FORGED_UNDERSCORE.$n;
    }

    $component = 'Filament\\Auth\\Pages\\Login';
    [$pre, $unreadable] = storeRead($host, $owner, $base, $labels, $component, $storeSource);

    if ($pre === []) {
        return $both('the rate-limiter store could not be read before the window, so a bucket this run filled could not be told from one it found: '.$unreadable);
    }

    record('THR-1', 'the store is ['.(string) ($pre['store']['driver'] ?? '?').'] with prefix ['
        .(string) ($pre['store']['prefix'] ?? '').'], the release is '.(string) ($pre['store']['base'] ?? '?')
        .', owned by '.$owner);

    // ⚠️ THE PREFIX THE SERVER SERVES AND THE ONE THE PAGE CARRIES MUST BE THE SAME INSTALL. The endpoint
    // prefix is derived from APP_KEY, so a mismatch means the release the store was read from is not the
    // one that answered the page — and the bucket read would belong to another application.
    $prefix = (string) ($pre['store']['livewire_prefix'] ?? '');

    if ($prefix !== '' && ! str_starts_with($pages[$first]['path'], $prefix.'/')) {
        return $both("the release at {$base} serves Livewire under [{$prefix}] and {$first} served the page under ["
            .$pages[$first]['path'].'], so the store read belongs to another application');
    }

    foreach ($pre['checksums'] as $label => $checksum) {
        if ((int) ($checksum['attempts'] ?? 0) >= LIMIT) {
            return $both("Livewire's checksum-failure limiter already holds ".(int) $checksum['attempts']
                ." failures for [{$label}], so this run's attempts could be answered 429 before they reach the throttle");
        }
    }

    // A bucket somebody else filled: wait it out once, within a bound, then refuse to measure through it.
    $dirty = [];

    foreach (array_keys($labels) as $label) {
        if (bucket($pre, $label) > 0) {
            $dirty[] = $label.' at '.bucket($pre, $label);
        }
    }

    if ($dirty !== []) {
        $timers = array_filter(array_map(static fn (array $read): mixed => $read['timer'] ?? null, $pre['buckets']), 'is_int');
        $wait = $timers === [] ? 0 : max($timers) - (int) ($pre['store']['now'] ?? 0) + 2;

        if ($wait <= 0 || $wait > maxWait()) {
            return $both('a login bucket was already filled before this run began ('.implode(', ', $dirty)
                .') and it does not expire within '.maxWait().'s, so nothing this run wrote could be told from it');
        }

        record('THR-1', 'waiting '.$wait.'s for a bucket this run did not fill to expire ('.implode(', ', $dirty).')');
        sleep($wait);

        [$pre, $unreadable] = storeRead($host, $owner, $base, $labels, $component, $storeSource);

        if ($pre === []) {
            return $both('the store could not be read again after waiting out an earlier bucket: '.$unreadable);
        }

        foreach (array_keys($labels) as $label) {
            if (bucket($pre, $label) > 0) {
                return $both("the bucket for [{$label}] still held ".bucket($pre, $label)
                    .' after the wait, so nothing this run wrote could be told from it');
            }
        }
    }

    // --- the window ---------------------------------------------------------------------------------
    $started = hrtime(true);
    $elapsed = static fn (): float => (hrtime(true) - $started) / 1e9;
    $records = [];
    $answers = [];
    $voids = [];
    $twoFails = [];
    $twoVoids = [];

    /*
     * ⚠️ A VOID BELONGS TO THE CHECK WHOSE ATTEMPT IT WAS. Attempts 1 to 6 and the other hostnames' probes
     * are THR-1's; the second address and the fresh session are THR-2's. Pushed into one list, an operator
     * whose machine has an odd IPv6 route would make a sound host unmeasurable for THR-1 as well — a
     * limitation of the machine reported as a limitation of the server.
     */
    $send = static function (array $page, string $family, string $jar, int $number, ?int $forged, string $email, string $check) use (
        $host, $nonce, $payload, $work, &$records, &$answers, &$voids, &$twoVoids, $elapsed
    ): string {
        $hostname = $page['hostname'];
        $unmeasured = static function (string $reason) use ($check, &$voids, &$twoVoids): string {
            if ($check === 'THR-2') {
                $twoVoids[] = $reason;
            } else {
                $voids[] = $reason;
            }

            return 'VOID';
        };

        if ($elapsed() > windowGuard()) {
            return $unmeasured(sprintf('%s: attempt %d was not sent, because %.0fs of the 60-second window had already gone',
                $hostname, $number, $elapsed()));
        }

        $probeId = $nonce.'-'.$number;
        $tag = (string) $number;
        [$status, $rows, $paths, $error] = attempt($page, $family, $jar, $probeId, $forged, $email, $work, $tag);

        if ($status !== 0) {
            return $unmeasured("{$hostname}: attempt {$number} did not complete ({$status}): ".substr(oneLine($error), 0, 160));
        }

        [$edge, $notOneConnection] = edgeAddress($rows, $paths, $family, $hostname);

        if ($edge === '') {
            return $unmeasured("attempt {$number}: ".$notOneConnection);
        }

        [$outcome, $detail] = classify($page, $rows[1] ?? [], $paths['body']);

        // ⚠️ AND AN ANSWER THIS CANNOT READ SAYS WHAT IT WAS. Without this the reason reaching the operator
        // was whatever the store read made of a window no attempt ever opened — true, and no help at all in
        // finding the guard that answered instead of the throttle.
        if ($outcome === 'VOID') {
            return $unmeasured("{$hostname}: attempt {$number} could not be judged: ".$detail);
        }

        [$line, $noLine] = probeLine($host, $nonce, $probeId, $payload);

        if ($line === []) {
            return $unmeasured("{$hostname}: attempt {$number} was answered, and nginx's own record of it is missing, so what arrived cannot be said: ".$noLine);
        }

        $answers[$number] = [
            'hostname' => $hostname,
            'family' => $family,
            'forged' => $forged,
            'edge' => $edge,
            'outcome' => $outcome,
            'detail' => $detail,
            'line' => $line,
        ];

        $records[] = sprintf('%s: attempt %d over %s, the edge saw %s, nginx logged %s %s status %s upstream %s xff [%s] — %s',
            $hostname, $number, $family === '-6' ? 'IPv6' : 'IPv4', $edge,
            $line['request_method'] ?? '?', $line['request_uri'] ?? '?', $line['status'] ?? '?',
            $line['upstream_addr'] ?? '?', $line['xff'] ?? '', $outcome);

        return $outcome;
    };

    $email = 'runbook-'.substr($nonce, 0, 8).EMAIL_DOMAIN;
    $other = 'runbook-second-'.substr($nonce, 0, 8).EMAIL_DOMAIN;
    $stopped = '';

    for ($number = 1; $number <= LIMIT + 1; $number++) {
        $outcome = $send($pages[$first], '-4', $jars[$first], $number, $number, $email, 'THR-1');

        if ($outcome === 'VOID') {
            $stopped = "attempt {$number} could not be judged";

            break;
        }

        if ($number <= LIMIT && $outcome !== 'FAILED') {
            $voids[] = "{$first}: attempt {$number} of the first ".LIMIT." was {$outcome}, so the bucket did not start empty and nothing after it can be told apart";
            $stopped = "attempt {$number} was {$outcome}";

            break;
        }
    }

    $throttled = ($answers[LIMIT + 1]['outcome'] ?? '') === 'THROTTLED';
    $probes = [];

    // ⚠️ ONLY AGAINST A FULL BUCKET. The single probe per hostname means something because the shared
    // bucket is already full when it arrives; with no throttle to meet it proves nothing, and sending it
    // would lock another hostname's sign-in out for no measurement at all.
    if ($stopped === '' && $throttled) {
        foreach (array_slice($hostnames, 1) as $index => $hostname) {
            $number = LIMIT + 2 + $index;
            $probes[$hostname] = $send($pages[$hostname], '-4', $jars[$hostname], $number, $number, $email, 'THR-1');

            if ($probes[$hostname] === 'VOID') {
                break;
            }
        }
    }

    $sixth = LIMIT + 1;
    $seventh = $sixth + count($hostnames);
    $eighth = $seventh + 1;
    $ipv6 = 'not attempted';
    $fresh = 'not attempted';

    if ($stopped === '' && $throttled && $edge6 !== '') {
        $ipv6 = $send($pages[$first], '-6', $jars[$first], $seventh, null, $email, 'THR-2');

        if ($ipv6 === 'FAILED') {
            $fresh = $send($secondPage, '-4', $secondJar, $eighth, null, $other, 'THR-2');
        }
    }

    [$post, $unreadable] = $answers === []
        ? [[], 'no attempt reached the host, so there was nothing to read a bucket for']
        : storeRead($host, $owner, $base, $labels, $component, $storeSource);

    foreach ($records as $fact) {
        record('THR-1', $fact);
    }

    // --- what it all adds up to ---------------------------------------------------------------------
    $fails = [];

    if ($post === []) {
        $voids[] = 'the store could not be read after the window, and without it a throttled attempt names no bucket: '.$unreadable;
    } else {
        record('THR-1', 'after the window the store holds '.implode(', ', array_map(
            static fn (string $label): string => $label.'='.bucket($post, $label),
            array_keys($labels),
        )));

        $now = (int) ($post['store']['now'] ?? 0);
        $timer = $post['buckets']['v4']['timer'] ?? null;

        if ($stopped !== '') {
            // No window was opened, and the reason is already recorded: the timer says nothing here.
        } elseif (! is_int($timer)) {
            $voids[] = 'the requester\'s bucket carries no timer after the window, so the 60 seconds this measured were not the ones the host armed';
        } elseif ($now >= $timer) {
            $voids[] = 'the 60-second window had already closed when the store was read, so what it holds is not what this run wrote';
        } elseif (($now - ($timer - 60)) > $elapsed() + 5) {
            $voids[] = sprintf('the requester\'s bucket was first hit %ds before the store was read, and this run began %.0fs before it, so the bucket it read was already running',
                $now - ($timer - 60), $elapsed());
        }

        foreach ($labels as $label => $address) {
            if (str_starts_with($label, 'xff-') || str_starts_with($label, 'usc-')) {
                if (bucket($post, $label) > 0) {
                    $fails[] = "a forged forwarding header reached request()->ip(): the bucket for [{$address}] holds "
                        .bucket($post, $label).' attempts, so the throttle counts by a value the caller chooses';
                }
            }
        }

        foreach (['lo4', 'lo6'] as $label) {
            if (bucket($post, $label) > bucket($pre, $label)) {
                $fails[] = "every request is being counted as the loopback address: the bucket for [{$labels[$label]}] grew from "
                    .bucket($pre, $label).' to '.bucket($post, $label).', so one bucket holds every visitor';
            }
        }

        $driver = (string) ($post['store']['driver'] ?? '');

        // ⚠️ A DRIVER THAT FORGETS IS A FAIL, NOT AN UNMEASURABLE RUN. Every bucket reads 0 here, exactly as
        // it does when the instrument looked at the wrong store — but the STORE line names the driver, and
        // a limiter on a store that cannot hold a counter between two requests can never throttle anything.
        if (! $throttled && in_array($driver, ['array', 'null'], true)) {
            $fails[] = "the login throttle is counting into the [{$driver}] store, which cannot hold a bucket from one "
                .'request to the next, so the sixth attempt was answered like the first and no number of attempts would ever be refused';
        }

        if ($fails === [] && $stopped === '') {
            if (bucket($post, 'v4') === 0) {
                $voids[] = 'no bucket this run could have written holds anything: the store read finds 0 for the requester and 0 for every forged and loopback address, '
                    .'so the writes went somewhere this instrument did not look (another store, another prefix, or after they expired), which is unmeasurable';
            } elseif (! $throttled) {
                $fails[] = 'the sixth attempt from one address was not throttled, and the requester\'s own bucket holds '
                    .bucket($post, 'v4').' attempts, so the login throttle does not hold on this host';
            } elseif (bucket($post, 'v4') !== LIMIT) {
                $voids[] = 'the requester\'s bucket holds '.bucket($post, 'v4').' attempts rather than exactly '.LIMIT
                    .', so it is not only this run\'s writes and the sixth attempt cannot be attributed to them';
            }
        }
    }

    foreach ($answers as $number => $answer) {
        $line = $answer['line'];
        $hostname = $answer['hostname'];

        if (($line['request_method'] ?? '') !== 'POST' || ($line['request_uri'] ?? '') !== $pages[$hostname]['path']) {
            $voids[] = "{$hostname}: attempt {$number} was logged as ".($line['request_method'] ?? '?').' '
                .($line['request_uri'] ?? '?').', which is not the attempt that was sent';
        }

        if (($line['host'] ?? '') !== $hostname) {
            $voids[] = "{$hostname}: attempt {$number} arrived for host [".($line['host'] ?? '').']';
        }

        if (preg_match('#^unix:/.*\.sock$#', $line['upstream_addr'] ?? '') !== 1) {
            $voids[] = "{$hostname}: attempt {$number} was answered by [".($line['upstream_addr'] ?? '').'], so PHP did not answer it';
        }

        $tokens = array_values(array_filter(array_map('trim', explode(',', $line['xff'] ?? '')), static fn (string $t): bool => $t !== ''));

        if ($answer['forged'] === null) {
            // ⚠️ AN ATTEMPT THAT WAS MEANT TO CARRY NOTHING MUST HAVE CARRIED NOTHING. A forged entry on the
            // second-address attempt would bucket separately and read as "a different address is not
            // throttled" — the exact false PASS this family exists to refuse.
            if (count($tokens) > 1) {
                $voids[] = "{$hostname}: attempt {$number} was sent with no forged entry and arrived with "
                    .count($tokens).' forwarded entries ['.($line['xff'] ?? '').']';
            }
        } elseif ($tokens === [] || $tokens[0] !== FORGED_DASHED.$answer['forged']) {
            $voids[] = "{$hostname}: the forged entry ".FORGED_DASHED.$answer['forged'].' did not arrive at nginx for attempt '
                .$number.' (X-Forwarded-For was ['.($line['xff'] ?? '').']), so this run never tested a forwarded header at all';
        } elseif (end($tokens) !== $answer['edge']) {
            $voids[] = "{$hostname}: attempt {$number} arrived with [".end($tokens).'] as the last forwarded entry and the edge saw ['
                .$answer['edge'].']';
        }
    }

    foreach ($probes as $hostname => $outcome) {
        if ($outcome === 'FAILED') {
            $fails[] = "{$hostname} answered a sign-in normally while the shared bucket was full, so it counts under a different key than "
                .$first.' — a forged or host-derived value';
        } elseif ($outcome !== 'THROTTLED') {
            $voids[] = "{$hostname}: the probe that should have met a full bucket could not be judged";
        }
    }

    $missing = array_diff(array_slice($hostnames, 1), array_keys($probes));

    if ($missing !== []) {
        $voids[] = 'no attempt was made against '.implode(', ', $missing).', so this says nothing about those hostnames';
    }

    $messages = array_unique(array_map(
        static fn (array $answer): string => $answer['detail'],
        array_filter($answers, static fn (array $answer, int $number): bool => $number <= LIMIT, ARRAY_FILTER_USE_BOTH),
    ));

    if (count($messages) > 1) {
        $voids[] = 'the first '.LIMIT.' attempts were rejected with different messages ('.implode(' / ', $messages)
            .'), so they were not all the same kind of rejection';
    }

    $one = $fails !== []
        ? ['FAIL', implode('; ', $fails)]
        : ($voids !== []
            ? ['VOID', implode('; ', $voids)]
            : ['PASS', 'across '.implode(', ', $hostnames).', '.LIMIT.' rejected sign-ins from one address filled that address\'s bucket and only it — '
                .'every forged X-Forwarded-For and X_Forwarded_For arrived at nginx and filled nothing — and the sixth was throttled on every hostname']);

    // --- THR-2: what the bucket is keyed on ---------------------------------------------------------
    if ($one[0] === 'FAIL') {
        $twoVoids[] = 'the address the throttle counts by is already wrong: '.$one[1];
    } elseif (! $throttled || $stopped !== '') {
        $twoVoids[] = 'no attempt was ever throttled, so there was no full bucket to test a second address against'
            .($stopped === '' ? '' : ' ('.$stopped.')');
    } elseif ($edge6 === '') {
        $twoVoids[] = 'this machine has no second address to try: '.$noV6
            .' — and an unthrottled second address is the whole of this check, so it is unmeasured rather than held';
    } elseif ($ipv6 === 'THROTTLED') {
        $twoFails[] = 'an attempt from a different address was throttled by the bucket the first address filled, so the key is not the address: '
            ."[{$edge6}] met [{$edge4}]'s bucket";
    } elseif ($ipv6 !== 'FAILED') {
        $twoVoids[] = 'the attempt from the second address could not be judged';
    } elseif ($fresh === 'FAILED') {
        $twoFails[] = 'a new session with a new address of its own was not throttled from the address that is, so the key holds the session or the email rather than the address alone';
    } elseif ($fresh !== 'THROTTLED') {
        $twoVoids[] = 'the attempt from a fresh session could not be judged';
    } elseif ($post === []) {
        $twoVoids[] = 'the store could not be read after the window, so the second address\'s own bucket was never named';
    } elseif (bucket($post, 'v4') !== LIMIT) {
        // ⚠️ THE SAME RULE THR-1 HOLDS TO. A bucket read of 0 for the second address is only evidence if the
        // read positively holds this run's own writes for the first; otherwise it is a store the instrument
        // could not see, and calling it a FAIL would accuse the host of a fault nothing here can measure.
        $twoVoids[] = 'the store read holds no positive record of this run\'s writes for '.$edge4
            .' ('.bucket($post, 'v4').' attempts, not '.LIMIT.'), so what it says about '.$edge6.' cannot be relied on';
    } elseif (bucket($post, 'v6') !== 1) {
        $twoFails[] = "the attempt from [{$edge6}] was not counted under its own address: that bucket holds "
            .bucket($post, 'v6').' attempts, not 1';
    }

    $two = $twoFails !== []
        ? ['FAIL', implode('; ', $twoFails)]
        : ($twoVoids !== []
            ? ['VOID', implode('; ', $twoVoids)]
            : ['PASS', "an attempt from {$edge6} was rejected rather than throttled while {$edge4}'s bucket was full, and filled a bucket of its own; "
                .'a new session with a new email from '.$edge4.' was still throttled, so the bucket is the address and nothing else']);

    return [$one, $two];
}

// --- the run ---------------------------------------------------------------------------------------

// stdout is the verdict stream run.sh judges; an uncaught refusal belongs with the other complaints.
ini_set('display_errors', 'stderr');

// ⚠️ ONE COLON, NOT TWO, EVEN FOR AN OPTIONAL OPTION. A double colon means the VALUE is optional, and
// getopt then accepts only `--runbook=value`: given `--runbook /path` it returns false, and a family
// silently fell back to its own directory and reported a pass against the wrong tree (tunnel-log.php).
//
// ⚠️ AND EVERY OPTION run.sh FORWARDS IS DECLARED, EVEN THE ONES THIS IGNORES. getopt stops at an option
// it does not know: an undeclared `--egress-trace` ahead of `--host` empties the whole set, and the family
// then refuses for want of a host it was given.
$options = getopt('', ['host:', 'expect:', 'token-file:', 'egress-trace:', 'runbook:']);
$host = is_string($options['host'] ?? null) ? $options['host'] : '';
$expect = is_string($options['expect'] ?? null) ? $options['expect'] : '';
$runbook = is_string($options['runbook'] ?? null) ? $options['runbook'] : dirname(__DIR__);
$egressTrace = is_string($options['egress-trace'] ?? null) ? $options['egress-trace'] : '';

if ($host === '' || ! in_array($expect, ['tunnel', 'dns-only'], true)) {
    fwrite(STDERR, "Refusing to check: --host and --expect tunnel|dns-only are required\n");
    exit(1);
}

$verdicts = [];
$payload = '';

/** Void every check this family declares, close the stream, and stop. */
$refuseAll = static function (string $one, string $three) use (&$verdicts): never {
    verdict('THR-1', 'VOID', $one, $verdicts);
    verdict('THR-2', 'VOID', $one, $verdicts);
    verdict('THR-3', 'VOID', $three, $verdicts);
    sentinel(FAMILY, $verdicts);

    exit(1);
};

// ⚠️ READ BEFORE ANYTHING IS SENT. An instrument that could not be read would fail start and stop alike,
// and a stop that never ran cannot say whether the probe is on the server.
foreach ([$runbook.'/host/common.sh', $runbook.'/host/probe-log.sh'] as $file) {
    $contents = @file_get_contents($file);

    if ($contents === false) {
        $refuseAll(is_file($file) ? "the instrument at {$file} could not be read" : "the instrument is missing at {$file}",
            'nothing was installed: nothing was sent to the host');
    }

    $payload .= $contents;
}

$storeSource = @file_get_contents($runbook.'/host/throttle-store.php');

if ($storeSource === false) {
    $refuseAll('the instrument is missing at '.$runbook.'/host/throttle-store.php, and without it a throttled attempt names no bucket',
        'nothing was installed: nothing was sent to the host');
}

if ($egressTrace !== '' && preg_match('#^https://[A-Za-z0-9._~-]+(:\d+)?(/|$)#', $egressTrace) !== 1) {
    $refuseAll("--egress-trace [{$egressTrace}] is not an https URL, and a plaintext or malformed one cannot be trusted to say what this machine's address is",
        'nothing was installed: nothing was sent to the host');
}

if (! in_array($expect, TOPOLOGIES, true)) {
    // ⚠️ SAID, NOT SKIPPED. alpha's dns-only rows land with its bring-up, when a real dns-only host has
    // produced them; until then this family is promised for tunnel alone, and a silent skip would read to
    // the completeness gate as a family that died.
    $refuseAll("this family is promised for a tunnel host, and the topology is {$expect}: the dns-only rows land with the alpha bring-up",
        'no probe was installed on a host this family does not check');
}

$nonce = bin2hex(random_bytes(16));
[$status, $out, $err] = instrument($host, ['start', $nonce], $payload);

if ($status !== 0) {
    // The start may have installed the probe before it failed, so THR-3 is what stop finds (see removal).
    $reason = 'the probe log did not start, so what arrived at nginx could not be read: '.substr(oneLine($err !== '' ? $err : $out), 0, 260);
    verdict('THR-1', 'VOID', $reason, $verdicts);
    verdict('THR-2', 'VOID', $reason, $verdicts);
    [$outcome, $removed] = $status === NOT_STARTED
        ? ['VOID', 'nothing was installed: nothing was sent to the host']
        : removal($host, $nonce, $payload);
    verdict('THR-3', $outcome, $removed, $verdicts);
    sentinel(FAMILY, $verdicts);

    exit(1);
}

record('THR-1', $out);

$work = sys_get_temp_dir().'/kitsune-throttle-'.$nonce;
mkdir($work, 0700, true);

$refusal = null;

try {
    [$one, $two] = examine($host, $nonce, $payload, $storeSource, $work);
    verdict('THR-1', $one[0], $one[1], $verdicts);
    verdict('THR-2', $two[0], $two[1], $verdicts);
} catch (LogicException $refused) {
    // Held, not lost: the probe is still removed and THR-3 still reported, and then it is thrown again.
    $refusal = $refused;
} finally {
    // ⚠️ THE JARS HOLD LIVE SESSION COOKIES. They are the one thing this writes that is worth anything to
    // anybody, and they go before the verdicts are closed, on every path including a fatal.
    foreach (glob($work.'/*') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($work);

    [$outcome, $removed] = removal($host, $nonce, $payload);
    verdict('THR-3', $outcome, $removed, $verdicts);

    // ⚠️ NO SENTINEL AFTER A REFUSAL (see refuse()). A refusal in THR-3's own verdict never reaches this
    // line; one from `try` is held above, and would otherwise be closed over as if it had not happened.
    if ($refusal === null) {
        sentinel(FAMILY, $verdicts);
    }
}

if ($refusal !== null) {
    throw $refusal;
}

$passed = array_filter($verdicts, static fn (array $verdict): bool => $verdict[1] === 'PASS');

exit(count($passed) === count($verdicts) ? 0 : 1);
