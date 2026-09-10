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
    // Bracketed IPv6, likewise — and these are the shapes the earlier three-character probe was
    // structurally unable to contain, which is how seven request-path 500s got past it.
    '[::1]', '[0:0:0:0:0:0:0:1]', '[0::1]', '[::0:1]', '[::ffff:127.0.0.1]', '[::ffff:7f00:1]',
    '[2001:db8::1]', '[2001:0db8::1]', '[::]', '[0:0:0:0:0:0:0:0]', '[notv6]', '[::1', '[]',
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

it('never throws for a host the framework will deliver', function () use ($corpus, $symfonyTakes): void {
    /*
     * ⚠️ THE GUARD THAT WOULD HAVE CAUGHT A 500 I SHIPPED. Review asked for a `catch` on the
     * request path; I declined, having probed all 9,261 three-character hosts for one Symfony
     * accepts and `canonicalHost()` refuses, and found none. That was true of the code as it then
     * stood. The next commit added the numeric and IPv6 rules, and those refuse seven spellings
     * Symfony delivers happily:
     *
     *     2130706433  [0:0:0:0:0:0:0:1]  [0::1]  [::0:1]
     *     [::ffff:127.0.0.1]  [::ffff:7f00:1]  [2001:0db8::1]
     *
     * A three-character corpus cannot contain a bracketed IPv6 address, so the probe could not have
     * found them however exhaustive it was over its own alphabet. The lesson is about the corpus
     * rather than the conclusion, and this is the corpus.
     *
     * ⚠️ ASSERTED ON `requestHost()`, which is the method the middleware calls. `canonicalHost()`
     * is allowed to throw — that is its job — and the two are deliberately different functions
     * rather than one function with a flag, so that neither call site can pick the wrong behaviour
     * by forgetting an argument.
     */
    $throwsForDeliverable = [];

    foreach ($corpus as $host) {
        if (! $symfonyTakes($host)) {
            continue;
        }

        try {
            Site::requestHost($host);
        } catch (Throwable $e) {
            $throwsForDeliverable[] = $host.' => '.$e::class;
        }
    }

    expect($throwsForDeliverable)->toBe([]);
});

it('resolves a long IPv6 form to the same host as the short one', function (): void {
    /*
     * ⚠️ NORMALISING BEATS CATCHING, which is why `requestHost()` does the former. `[::1]` and
     * `[0:0:0:0:0:0:0:1]` are one address: a request carrying the long form should reach the site
     * that stored the short one. A `catch` returning "unresolvable" would have stopped the 500 and
     * still given the wrong answer, quietly.
     */
    expect(Site::requestHost('[0:0:0:0:0:0:0:1]'))->toBe('[::1]')
        ->and(Site::requestHost('[0::1]'))->toBe('[::1]')
        ->and(Site::requestHost('[::0:1]'))->toBe('[::1]')
        ->and(Site::requestHost('[0:0:0:0:0:0:0:0]'))->toBe('[::]')
        ->and(Site::requestHost('[::1]'))->toBe('[::1]');

    // ⚠️ And it agrees with what storage accepts, or the normalisation would be pointless.
    expect(Site::canonicalHost('[::1]'))->toBe('[::1]');
});

it('leaves a host it cannot normalise alone rather than blanking it', function (): void {
    /*
     * ⚠️ NOT MAPPED TO THE EMPTY STRING, because `''` is what a host-less claim stores and
     * `resolve()` already tries those separately for every request. Returning the host as it came
     * means it matches no stored row — storage refuses these spellings — while a path site still
     * answers on any host, which is what a host-less claim means.
     */
    foreach (['2130706433', '%65xample.test', '[notv6]', 'x..test'] as $unmatchable) {
        expect(Site::requestHost($unmatchable))
            ->not->toBe('', "[{$unmatchable}] was blanked into the host-less wildcard");
    }
});
