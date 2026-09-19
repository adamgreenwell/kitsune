<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * Every instant the admin lists or takes is built by `SiteTime`, so none of them is shown in UTC by omission.
 *
 * ⚠️ THE REACH OF A CORRECT RULE, which is what issue #39 kept getting wrong: `dir="auto"` was right on one title
 * column and missing from three more, found one review at a time, because every screen decided for itself. The site's
 * timezone is applied in one class, and this fails when anything in `packages/core/src` outside it formats a time —
 * `dateTime()`, `time()`, their `iso…` forms and their tooltips — sets a timezone, or builds, or aliases, a date-time
 * or time picker.
 *
 * ⚠️ WHAT IT CANNOT SEE, stated so it is not mistaken for coverage. `since()` prints a duration ("3 hours ago"), the
 * same in every timezone, and is allowed. `date()`, `isoDate()`, `dateTooltip()`, `isoDateTooltip()` and
 * `DatePicker` are allowed and must be — a date is not an instant (see `Cell::Date`) — so one pointed at an instant
 * column shows that instant's UTC calendar day and passes, and so does `date()` given a format with a time in it or a
 * `timezone:` argument of its own. So does an instant formatted by hand, in `formatStateUsing()` or a view. Those are
 * review's to catch.
 */

/**
 * The code of a PHP file with its comments removed, so prose about `dateTime()` is not mistaken for a call.
 */
function codeWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * Where an instant's time is formatted, entered or converted.
 *
 * ⚠️ THE WHOLE FAMILY, and it was not. The pattern named `dateTime`, `isoDateTime`, `dateTimeTooltip` and
 * `timezone`, and Filament formats an instant's time five more ways — `time()`, `isoTime()` and three tooltips —
 * each falling back to the application's zone, UTC. It also matched a picker built by `::make()` alone, not by `new`
 * or under an alias.
 */
const SITE_TIME_CONSTRUCTS = '/->\s*(?:dateTime|isoDateTime|time|isoTime|dateTimeTooltip|isoDateTimeTooltip|timeTooltip|isoTimeTooltip|timezone)\s*\('
    .'|\b(?:DateTimePicker|TimePicker)\s*::\s*make\s*\(|\bnew\s+[\w\\\\]*\b(?:DateTimePicker|TimePicker)\b|\b(?:DateTimePicker|TimePicker)\s+as\b/i';

it('recognises every way to format an instant\'s time or build its picker, and none that shows a date', function (): void {
    $caught = [
        "TextColumn::make('updated_at')->dateTime()", '->isoDateTime()', '->since()->dateTimeTooltip()',
        "TextColumn::make('updated_at')->time()", '->isoTime()', '->since()->timeTooltip()',
        '->since()->isoTimeTooltip()', '->since()->isoDateTimeTooltip()', "->timezone('UTC')",
        "DateTimePicker::make('at')", "\\Filament\\Forms\\Components\\DateTimePicker::make('at')", "TimePicker::make('at')",
        "new DateTimePicker('at')", "new \\Filament\\Forms\\Components\\TimePicker('at')",
        'use Filament\\Forms\\Components\\DateTimePicker as Picker;',
    ];
    $allowed = [
        "TextColumn::make('runs_on')->date()", '->isoDate()', '->since()', '->dateTooltip()', '->isoDateTooltip()',
        "DatePicker::make('runs_on')", '$component instanceof DateTimePicker', '->timeout(5)',
        'use Filament\\Forms\\Components\\DateTimePicker;',
    ];

    foreach ($caught as $code) {
        expect(preg_match(SITE_TIME_CONSTRUCTS, $code))->toBe(1, "missed: {$code}");
    }

    foreach ($allowed as $code) {
        expect(preg_match(SITE_TIME_CONSTRUCTS, $code))->toBe(0, "flagged: {$code}");
    }
});

it('builds every date-time column and picker in the admin through SiteTime', function (): void {
    $constructs = SITE_TIME_CONSTRUCTS;
    $root = dirname(__DIR__, 3).'/packages/core/src';
    $offenders = [];
    $inSiteTime = 0;

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $found = preg_match_all($constructs, codeWithoutComments($file->getPathname()));

        if ($file->getFilename() === 'SiteTime.php') {
            $inSiteTime = $found;

            continue;
        }

        if ($found > 0) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    // ⚠️ Non-vacuity: the pattern has to match the code it exists to allow, or it could match nothing anywhere.
    expect($inSiteTime)->toBeGreaterThanOrEqual(4, 'the pattern no longer recognises SiteTime\'s own constructs')
        ->and($offenders)->toBe([], 'an instant is built outside SiteTime: '.implode(', ', $offenders));
});
