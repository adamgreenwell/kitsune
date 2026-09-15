<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Console\Concerns\LeavesNothingBehind;

/*
 * A benchmark's run lock stays out of the host application's repository.
 *
 * ⚠️ FOUND AS AN UNTRACKED FILE after a benchmark run. `LeavesNothingBehind` serializes runs through a lock on a
 * file in `storage/framework`, and the file outlives the run by design: the lock belongs to the process, and the
 * file is only its handle. The skeleton shipped no `storage/framework/.gitignore` at all, so the lock showed up
 * in the host's working tree, and so would Laravel's own `down`, `schedule-*` and `services.json` beside it.
 *
 * ⚠️ ASKED OF THE COMMANDS, NOT OF A FILENAME. Every command using the trait is found by scanning for it, and
 * each lock path comes from `runLockPath()`. A new benchmark, a renamed command or a moved lock therefore fails
 * this test until the ignore file covers it.
 */

it('ignores the run lock of every command that takes one', function (): void {
    $patterns = collect(file(__DIR__.'/../../../skeleton/storage/framework/.gitignore', FILE_IGNORE_NEW_LINES) ?: [])
        ->map(static fn (string $line): string => trim($line))
        ->reject(static fn (string $line): bool => $line === '' || str_starts_with($line, '#'))
        ->values();

    $commands = collect(glob(__DIR__.'/../../../packages/core/src/Console/*.php') ?: [])
        ->map(static fn (string $file): string => 'Kitsune\\Core\\Console\\'.basename($file, '.php'))
        ->filter(static fn (string $class): bool => in_array(LeavesNothingBehind::class, class_uses($class), true))
        ->values();

    // ⚠️ Not vacuous: an empty scan would make the loop below assert nothing. The three benchmarks use the trait.
    expect($commands->count())->toBeGreaterThanOrEqual(3);

    foreach ($commands as $class) {
        $path = $class::runLockPath((string) app($class)->getName());

        // The ignore file sits in `storage/framework`, so it only reaches a lock written directly inside it.
        expect(dirname($path))->toBe(storage_path('framework'));

        $lock = basename($path);

        expect($patterns->contains(static fn (string $pattern): bool => fnmatch($pattern, $lock)))
            ->toBeTrue("skeleton/storage/framework/.gitignore has no pattern matching {$lock}, which {$class} leaves in the host's storage/framework");
    }
});
