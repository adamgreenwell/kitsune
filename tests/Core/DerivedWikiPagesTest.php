<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/*
 * The wiki's derived index may not report a decision as more settled than its own entry says it is.
 *
 * ⚠️ THIS IS THE SAME DEFECT AS `PromisedDocumentsTest`, one layer out: a rule held by attention, in a
 * file nothing gates. The wiki's `Decision-Log.md` is GENERATED from `docs/decision-log.md` on every push
 * to main, and its Status column had two values while the log has three. What that published:
 *
 *   ADR-032 | The mark reduces to a unit ... and it is adopted provisionally | decided
 *
 * The entry's own status line reads *Provisional — adopted for use, not for registration*, because name
 * clearance in the software classes is still open (issue #1, labelled `blocks-spend`). The index is the
 * page a reader meets BEFORE the log, and it said the trademark question was closed.
 *
 * ⚠️ AND THE AMENDED FLAG WAS KEYWORD-MATCHED ON THE STATUS LINE ALONE, so three entries whose prose
 * records an amendment were published as plain `decided` — ADR-021 (amended three times while public site
 * resolution was built), ADR-032, and ADR-033 (the owner bypass moved out of `Gate::before`, and the scope
 * hatch stopped suspending the authority guards). A log whose index understates its own reversals is the
 * failure mode the log exists to prevent: it is kept BECAUSE two entries are reversals.
 *
 * ⚠️ THE TEST RUNS THE REAL GENERATOR rather than restating its rules. A second copy of the
 * classification here would be a second thing to keep in sync — which is the defect, not the fix — and
 * running `bin/wiki-sync.php` also means the suite fails if the generator itself stops working, which
 * nothing else checks: its workflow runs only on pushes to main, after review is over.
 */

/**
 * The status cell the generator publishes for each ADR, keyed by id.
 *
 * @return array<string, string>
 */
function publishedAdrStatuses(): array
{
    /** @var array<string, string>|null $published */
    static $published = null;

    if ($published !== null) {
        return $published;
    }

    $root = dirname(__DIR__, 2);
    $target = sys_get_temp_dir().'/kitsune-wiki-'.bin2hex(random_bytes(8));

    mkdir($target);

    try {
        $result = Process::run([PHP_BINARY, $root.'/bin/wiki-sync.php', $target]);

        expect($result->successful())->toBeTrue('bin/wiki-sync.php failed: '.$result->errorOutput());

        $page = (string) file_get_contents($target.'/Decision-Log.md');
    } finally {
        foreach ((array) glob($target.'/*') as $file) {
            unlink((string) $file);
        }

        rmdir($target);
    }

    preg_match_all('/^\| \[(ADR-\d+)\]\([^)]+\) \| .+ \| (.+) \|$/m', $page, $rows, PREG_SET_ORDER);

    $published = [];

    foreach ($rows as $row) {
        $published[$row[1]] = trim($row[2]);
    }

    return $published;
}

/**
 * Every entry in the log, keyed by id, as the text between its heading and the next one.
 *
 * @return array<string, string>
 */
function recordedAdrEntries(): array
{
    $log = (string) file_get_contents(dirname(__DIR__, 2).'/docs/decision-log.md');

    /* ⚠️ `[^\n]+` for the heading, not `.+`: under /s a greedy dot eats the file and every entry
     * becomes one. It failed loudly here — the non-vacuity assertions below found no provisional entry
     * at all — which is the only reason the regex and not the log got blamed. */
    preg_match_all('/^## (ADR-\d+) — [^\n]+$(.*?)(?=^## ADR-|\z)/ms', $log, $entries, PREG_SET_ORDER);

    $recorded = [];

    foreach ($entries as $entry) {
        $recorded[$entry[1]] = $entry[2];
    }

    return $recorded;
}

it('publishes a row for every entry in the log', function (): void {
    $published = publishedAdrStatuses();
    $recorded = recordedAdrEntries();

    /*
     * ⚠️ Equality, not "at least one". Both halves parse the same file with different expressions, and a
     * row silently dropped — by a heading the generator's regex stops matching, say — would take every
     * assertion below with it and leave them green.
     */
    expect(array_keys($published))->toBe(array_keys($recorded))
        ->and(count($recorded))->toBeGreaterThan(30);
});

it('never publishes a settled status for an entry that records an amendment', function (): void {
    $published = publishedAdrStatuses();

    /* A bold marker, deliberately literal: prose about amending something else is not an amendment. */
    $amended = array_keys(array_filter(
        recordedAdrEntries(),
        static fn (string $entry): bool => preg_match('/\*\*(?:Amended|Revised)\b/u', $entry) === 1,
    ));

    expect($amended)->toContain('ADR-021');
    expect($amended)->toContain('ADR-033');

    $understated = [];

    foreach ($amended as $adr) {
        if (! str_contains($published[$adr] ?? '', 'amended')) {
            $understated[] = $adr.' records an amendment and is published as "'.($published[$adr] ?? '—').'"';
        }
    }

    expect($understated)->toBe([], "the wiki index understates these entries:\n".implode("\n", $understated));
});

it('never publishes a provisional decision as settled', function (): void {
    $published = publishedAdrStatuses();

    $provisional = array_keys(array_filter(
        recordedAdrEntries(),
        static fn (string $entry): bool => preg_match('/^\*\*Status:\*\* Provisional\b/mu', $entry) === 1,
    ));

    expect($provisional)->toContain('ADR-032');

    $understated = [];

    foreach ($provisional as $adr) {
        if (! str_contains($published[$adr] ?? '', 'provisional')) {
            $understated[] = $adr.' is provisional and is published as "'.($published[$adr] ?? '—').'"';
        }
    }

    expect($understated)->toBe([], "the wiki index publishes these as settled:\n".implode("\n", $understated));
});
