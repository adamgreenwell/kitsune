<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * A document may not promise, in the future tense, a file that is already committed.
 *
 * ⚠️ THIS DEFECT HAS NOW HAPPENED TWICE, WHICH IS WHY IT IS A TEST RATHER THAN A NOTE. `CONTRIBUTING.md`
 * promised the CLA text after `CLA.md` had landed, and it promised `SECURITY.md` after `SECURITY.md` had
 * landed. The first was caught and fixed in one file while the identical sentence survived in `README.md`;
 * the second routed a security reporter away from the disclosure flow that exists, in the section whose
 * only job is to describe it (issue #78).
 *
 * ⚠️ AND IT IS THE THIRD RULE TO DRIFT FOR WANT OF A TEST — after the refusal accounting in
 * `field-types.md` and the licence header in `LicenceHeaderTest`. The shape repeats: prose checked by
 * attention holds until the day attention is elsewhere.
 *
 * ⚠️ A PROMISE OF A FILE THAT DOES NOT EXIST IS FINE, AND THAT IS THE POINT. `README.md` says a trademark
 * policy will land alongside the first release, which is true — there is no `TRADEMARK.md`. This test
 * starts failing on the commit that adds one, which is exactly when that sentence becomes a lie.
 */

/** Future-tense claims about a document. Deliberately literal: a broad match would flag prose about work. */
const FUTURE_TENSE = [
    'will be published',
    'will be written',
    'will land',
    'will ship',
    'is still outstanding',
    'is the one piece still outstanding',
    'does not exist yet',
    'has yet to be published',
    'yet to be written',
];

/**
 * Every Markdown document that speaks for the project — the front door and the reference docs.
 *
 * @return list<string>
 */
function everyMarkdownDocument(): array
{
    $root = dirname(__DIR__, 2);

    $found = array_merge(
        glob($root.'/*.md') ?: [],
        glob($root.'/docs/*.md') ?: [],
    );

    $relative = array_map(
        static fn (string $path): string => mb_substr(str_replace('\\', '/', $path), mb_strlen($root) + 1),
        $found,
    );

    sort($relative);

    return array_values($relative);
}

it('never promises a document that is already committed', function (): void {
    $root = dirname(__DIR__, 2);
    $documents = everyMarkdownDocument();

    /*
     * ⚠️ Not vacuous twice over: the sweep has to find the front door, and it has to find a filename to
     * look for. An empty list of either would make every assertion below pass over nothing.
     */
    expect($documents)->toContain('README.md')
        ->and($documents)->toContain('CONTRIBUTING.md');

    /* The committed root documents are the ones a promise can be wrong about. */
    $committed = array_values(array_filter(
        array_map(static fn (string $path): string => basename($path), $documents),
        static fn (string $name): bool => ! str_contains($name, '/'),
    ));

    expect($committed)->toContain('SECURITY.md')
        ->and($committed)->toContain('CLA.md');

    $broken = [];

    foreach ($documents as $document) {
        $lines = explode("\n", (string) file_get_contents($root.'/'.$document));

        foreach ($lines as $number => $line) {
            foreach (FUTURE_TENSE as $promise) {
                if (! str_contains($line, $promise)) {
                    continue;
                }

                foreach ($committed as $name) {
                    if (str_contains($line, $name)) {
                        $broken[] = $document.':'.($number + 1).' promises '.$name.' ("'.$promise.'")';
                    }
                }
            }
        }
    }

    expect($broken)->toBe([], "these lines promise a file that already exists:\n".implode("\n", $broken));
});
