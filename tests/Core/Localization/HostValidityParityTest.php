<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Site;
use Symfony\Component\HttpFoundation\Request;

/**
 * A host this application will STORE is one the framework will DELIVER.
 *
 * ⚠️ `Site::canonicalHost()` carries a COPY of `Request::isHostValid()`'s name rule, because
 * calling `getHost()` would also consult trusted proxies and the trusted-host regexp — runtime
 * configuration with no business deciding whether an address can be saved. A copy drifts, so this
 * file is what keeps it honest: it asks Symfony the same question about the same corpus and
 * compares the answers, rather than trusting that the copy is still faithful.
 */
$corpus = [
    // Names — the branch that is a copy of Symfony's rule.
    'x.test', 'example.test', 'sub.example.test', 'a_b.test', '-x.test', 'x-.test',
    'x..test', '.x.test', 'x.test.', 'xn--bcher-kva.example', 'xn--a', 'localhost',
    str_repeat('a', 63).'.test', str_repeat('a', 64).'.test',
    // Numeric spellings, where this refuses more than Symfony does, on purpose.
    '127.0.0.1', '127.1', '010.1', '0x7f.0.0.1', '2130706433', '0177.0.0.1', '1.2.3.4.5',
    '255.255.255.255', '256.1.1.1',
    // Bracketed IPv6, likewise.
    '[::1]', '[0:0:0:0:0:0:0:1]', '[0::1]', '[::ffff:127.0.0.1]', '[::ffff:7f00:1]',
    '[2001:db8::1]', '[notv6]', '[::1',
    // Shapes neither should take.
    'x test', 'x/test', 'x:test', 'x@test', '%65xample.test',
];

/** Whether Symfony would let this host through on the request path. */
$symfonyTakes = function (string $host): bool {
    $request = Request::create('http://placeholder/');
    $request->headers->set('Host', $host);

    try {
        $request->getHost();

        return true;
    } catch (Throwable) {
        return false;
    }
};

/** Whether this application would store it. */
$kitsuneTakes = function (string $host): bool {
    try {
        Site::canonicalHost($host);

        return true;
    } catch (Throwable) {
        return false;
    }
};

it('never stores a host the framework would refuse to deliver', function () use ($corpus, $symfonyTakes, $kitsuneTakes): void {
    /*
     * ⚠️ THE ONE-DIRECTIONAL INVARIANT, and the one that actually matters. This is deliberately
     * STRICTER than Symfony on numeric aliases and IPv6 spellings, so the two predicates are not
     * equal — but a host this stores and the framework rejects is a site unreachable at the
     * address its operator typed, which is the defect review found with `x..test`.
     */
    $storedButUndeliverable = array_values(array_filter(
        $corpus,
        fn (string $host): bool => $kitsuneTakes($host) && ! $symfonyTakes($host),
    ));

    expect($storedButUndeliverable)->toBe([]);
});

it('agrees with Symfony exactly on name-shaped hosts, which is the copied rule', function () use ($corpus, $symfonyTakes, $kitsuneTakes): void {
    /*
     * ⚠️ EXACT agreement is asserted only for NAMES, because that branch is the copy. The numeric
     * and bracketed branches are this application's own stricter rules, asserted in
     * `PublicSiteLocaleTest` against the spellings a browser actually sends.
     */
    $names = array_values(array_filter(
        $corpus,
        fn (string $host): bool => ! str_starts_with($host, '[')
            && ! str_contains($host, '%')
            && preg_match('/(?:^|\.)(?:0[xX][0-9a-fA-F]*|[0-9]+)$/D', $host) !== 1,
    ));

    // Non-empty, or the loop below would assert nothing at all.
    expect($names)->not->toBe([]);

    foreach ($names as $host) {
        expect($kitsuneTakes($host))->toBe(
            $symfonyTakes($host),
            "[{$host}]: this application and Symfony disagree, so the copied rule has drifted",
        );
    }
});
