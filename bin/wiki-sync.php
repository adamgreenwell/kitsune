#!/usr/bin/env php
<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * Regenerates the wiki pages that are DERIVED from docs/.
 *
 * The split matters. docs/ is the source of truth: it is versioned with the
 * code and gated by CONTRIBUTING's rule that changing a decision means
 * amending its ADR. A wiki page restating any of that would drift, because
 * nothing gates the wiki.
 *
 * So derived pages are generated from docs/ and carry a do-not-edit banner,
 * and everything else in the wiki is hand-written material docs/ does not
 * cover - FAQs, how-tos, orientation. Those two sets never overlap.
 *
 * Usage: php bin/wiki-sync.php /path/to/kitsune.wiki
 */

$target = $argv[1] ?? null;

if ($target === null || ! is_dir($target)) {
    fwrite(STDERR, "usage: php bin/wiki-sync.php /path/to/kitsune.wiki\n");
    exit(1);
}

$root = dirname(__DIR__);
$repo = 'https://github.com/adamgreenwell/kitsune';

$banner = static fn (string $source): string => <<<MD
    > **Generated from [`{$source}`]({$repo}/blob/main/{$source}) — do not edit here.**
    > Edit the source file and run `php bin/wiki-sync.php`. Changing a settled
    > decision means amending its ADR, not editing a wiki page.

    MD;

// ── ADR index ────────────────────────────────────────────────────────────────
$log = file_get_contents("{$root}/docs/decision-log.md");
preg_match_all('/^## (ADR-(\d+)) — (.+)$\n\n\*\*Status:\*\* (.+)$/m', $log, $m, PREG_SET_ORDER);

$rows = [];
foreach ($m as $adr) {
    $status = trim($adr[4]);
    $revised = str_contains($status, 'Revised') || str_contains($status, 'Amended')
        || str_contains($status, 'Supersedes') || str_contains($status, 'REVERSES');
    // GitHub's slugger: lowercase, strip everything that is not a letter,
    // number, space, underscore or hyphen, then spaces to hyphens. It does
    // NOT collapse repeated hyphens - an em-dash between two spaces leaves a
    // double hyphen, and getting that wrong breaks every link on the page.
    $anchor = mb_strtolower("{$adr[1]} — {$adr[3]}", 'UTF-8');
    $anchor = (string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $anchor);
    $anchor = str_replace(' ', '-', $anchor);
    $rows[] = sprintf(
        '| [%s](%s/blob/main/docs/decision-log.md#%s) | %s | %s |',
        $adr[1],
        $repo,
        trim((string) $anchor, '-'),
        str_replace('|', '\|', $adr[3]),
        $revised ? '⚠️ amended' : 'decided',
    );
}

$adrPage = $banner('docs/decision-log.md')."\n# Decision log\n\n"
    .'Every architectural decision, every alternative considered, and the specific reason each one lost. '
    ."Two entries are reversals kept as failures rather than edited out — that is the standard the log is held to.\n\n"
    .'**'.count($rows)." decisions recorded.**\n\n"
    ."| ADR | Decision | Status |\n|---|---|---|\n".implode("\n", $rows)."\n\n"
    ."Read the full log for the reasoning and the rejected alternatives — the summaries above are navigation, not substance.\n";

file_put_contents("{$target}/Decision-Log.md", $adrPage);

// ── roadmap status ───────────────────────────────────────────────────────────
$roadmap = file_get_contents("{$root}/docs/roadmap.md");
preg_match_all('/^## ((?:Phase \d+|v1\.\d\+?)[^\n]*)$(.*?)(?=^## |\z)/ms', $roadmap, $phases, PREG_SET_ORDER);

$lines = [];
foreach ($phases as $phase) {
    $done = preg_match_all('/^- \[x\]/m', $phase[2]);
    $open = preg_match_all('/^- \[ \]/m', $phase[2]);
    $total = $done + $open;

    if ($total === 0) {
        continue;
    }

    $pct = (int) round($done / $total * 100);
    $bar = str_repeat('█', (int) round($pct / 10)).str_repeat('░', 10 - (int) round($pct / 10));
    $lines[] = sprintf('| %s | `%s` | %d/%d |', trim($phase[1]), $bar, $done, $total);
}

$roadmapPage = $banner('docs/roadmap.md')."\n# Roadmap status\n\n"
    .'Progress by phase. There is no external deadline and scope is not cut to hit a date — '
    ."the estimates in the roadmap are a cost estimate, not a schedule.\n\n"
    ."| Phase | Progress | Items |\n|---|---|---|\n".implode("\n", $lines)."\n";

file_put_contents("{$target}/Roadmap-Status.md", $roadmapPage);

printf("generated:\n  Decision-Log.md   (%d ADRs)\n  Roadmap-Status.md (%d phases)\n", count($rows), count($lines));
