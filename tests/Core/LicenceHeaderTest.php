<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * AGENTS.md §7: every PHP file carries the MPL Exhibit A header, never Exhibit B.
 *
 * ⚠️ THIS TEST EXISTS BECAUSE THE RULE WAS PUBLISHED AND UNENFORCED, and review caught three files
 * without the header — `tools/property-parity/{names,measure,compare}.php`, added in the round before
 * this one. A licence notice is not a style preference: MPL-2.0 §3.4 is what puts the terms on each
 * file, and a file without it is the one thing in this repository that cannot be fixed after
 * distribution.
 *
 * ⚠️ AND IT IS THE SECOND RULE THIS SESSION THAT DRIFTED FOR WANT OF A TEST. The refusal accounting in
 * `field-types.md` was the first. The pattern is the lesson: a rule stated in prose and checked by
 * attention is a rule that holds until the day it is busy.
 */

/** Every directory whose PHP is generated, vendored or cached — none of it ours to licence. */
const NOT_OURS = [
    '/vendor/',
    '/node_modules/',
    '/build/',
    '/bootstrap/cache/',
    '/storage/framework/',
];

/**
 * @return list<string>
 */
function everyPhpFileWeShip(): array
{
    $root = dirname(__DIR__, 2);
    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        foreach (NOT_OURS as $excluded) {
            if (str_contains($path, $excluded)) {
                continue 2;
            }
        }

        $found[] = mb_substr($path, mb_strlen($root) + 1);
    }

    sort($found);

    return $found;
}

it('carries the Exhibit A header on every PHP file', function (): void {
    $root = dirname(__DIR__, 2);
    $files = everyPhpFileWeShip();

    /*
     * ⚠️ Not vacuous: the walk has to find the file it is written in, or an exclusion typo would make
     * this pass over an empty list. The count is asserted loosely — a floor, not a pin — because a test
     * that fails when someone adds a file teaches them to delete the test.
     */
    expect($files)->toContain('tests/Core/LicenceHeaderTest.php')
        ->and(count($files))->toBeGreaterThan(150);

    $missing = [];

    foreach ($files as $file) {
        $head = (string) file_get_contents($root.'/'.$file, false, null, 0, 400);

        // The whole notice, not a keyword: a file with the words in a comment somewhere else is not
        // licensed by them, and the URL is the part MPL-2.0 §3.4 asks for.
        $carries = str_contains($head, 'This Source Code Form is subject to the terms of the Mozilla Public')
            && str_contains($head, 'License, v. 2.0. If a copy of the MPL was not distributed with this')
            && str_contains($head, 'file, You can obtain one at https://mozilla.org/MPL/2.0/.');

        if (! $carries) {
            $missing[] = $file;
        }
    }

    expect($missing)->toBe([], 'these PHP files carry no MPL Exhibit A header: '.implode(', ', $missing));
});

it('carries Exhibit B nowhere', function (): void {
    /*
     * ⚠️ EXHIBIT B IS THE OPPOSITE OF THE LICENCE THIS PROJECT CHOSE. It marks a file as incompatible
     * with secondary licences — GPL among them — and MPL-2.0 is deliberately compatible. One file
     * carrying it would change what downstream may do with the whole work, and it is a single line that
     * a copy-and-paste from another MPL project brings with it.
     */
    $root = dirname(__DIR__, 2);
    $carrying = [];

    /*
     * ⚠️ ASSEMBLED RATHER THAN WRITTEN, and this file is skipped as well. A test that searches for a
     * string cannot avoid containing it — the first version of this reported ITSELF as carrying Exhibit
     * B, which is a true statement about the bytes and a false one about the licence. Both guards are
     * here because either alone reads as a trick; together they say what is meant.
     */
    $exhibitB = 'Incompatible With'.' Secondary Licenses';
    $itself = 'tests/Core/'.basename(__FILE__);

    foreach (everyPhpFileWeShip() as $file) {
        if ($file === $itself) {
            continue;
        }

        if (str_contains((string) file_get_contents($root.'/'.$file), $exhibitB)) {
            $carrying[] = $file;
        }
    }

    expect($carrying)->toBe([], 'these files carry Exhibit B: '.implode(', ', $carrying));
});
