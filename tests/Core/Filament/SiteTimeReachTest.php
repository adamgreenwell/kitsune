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
 * timezone is applied in one class, and this fails when a date-time column, a date-time picker or a timezone is
 * written anywhere else in `Kitsune\Core\Filament` — so the next screen that lists `published_at` cannot quietly
 * format it in UTC.
 *
 * `since()` is not on the list: it prints a duration ("3 hours ago"), which is the same in every timezone. `date()` and
 * `DatePicker` are not either, and must not be — a date is not an instant (see `Cell::Date`).
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

it('builds every date-time column and picker in the admin through SiteTime', function (): void {
    // Where an instant is formatted, entered or converted.
    $constructs = '/->\s*(?:dateTime|isoDateTime|dateTimeTooltip|timezone)\s*\(|\b(?:DateTimePicker|TimePicker)::make\s*\(/';
    $root = dirname(__DIR__, 3).'/packages/core/src/Filament';
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
