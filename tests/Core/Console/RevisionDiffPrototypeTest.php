<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Benchmarks\RevisionDiffPrototype;

beforeAll(function (): void {
    require_once dirname(__DIR__, 3).'/bin/benchmarks/RevisionDiffPrototype.php';
});

function revisionGrowthState(int $version): array
{
    return [
        'entry_type_id' => 7,
        'title' => 'Synthetic personal marker',
        'slug' => 'version-'.$version,
        'status' => 'draft',
        'published_at' => null,
        'values' => ['body' => str_repeat('content ', 128), 'personal' => 'Synthetic personal marker', 'counter' => $version],
        'relation_state' => ['12' => $version % 2 ? [4, 9] : [9, 4]],
        'unsanitized_values' => ['personal' => '<p>Synthetic personal marker</p>'],
    ];
}

it('reconstructs every retained oracle state while pruning a thousand edits', function (int $interval): void {
    $history = new RevisionDiffPrototype('entry:42', $interval);

    for ($i = 1; $i <= 1000; $i++) {
        $history->append(revisionGrowthState($i));

        if (in_array($i, [1, 10, 49, 50, 51, 100, 1000], true)) {
            expect($history->rows())->toHaveCount(min(50, $i));

            foreach ($history->rows() as $row) {
                expect($history->read($row['sequence']))->toEqual(revisionGrowthState($row['sequence']));
            }

            expect($history->rows()[0]['kind'])->toBe('checkpoint');
        }
    }

    expect(fn () => $history->read(950))->toThrow(RuntimeException::class, 'not retained');
})->with([1, 5, 10, 25, 50]);

it('erases inline, promoted, relation and retained-original copies without resurrection', function (): void {
    $history = new RevisionDiffPrototype('entry:42', 10);
    for ($i = 1; $i <= 75; $i++) {
        $history->append(revisionGrowthState($i));
    }

    $history->redact(['personal'], ['title' => null], ['12']);

    expect(RevisionDiffPrototype::json($history->rows()))->not->toContain('Synthetic personal marker');
    foreach ($history->rows() as $row) {
        $state = $history->read($row['sequence']);
        expect($state['values']['personal'])->toBeNull()
            ->and($state['title'])->toBeNull()
            ->and($state['unsanitized_values'])->toBeNull()
            // Unknown after erasure: an empty list would clear replacement links during restore (ADR-020).
            ->and($state['relation_state'][12])->toBeNull()
            ->and($state['entry_type_id'])->toBe(7)
            ->and($state['values']['body'])->toBe(str_repeat('content ', 128));
    }

    expect(fn () => $history->redact([], ['entry_type_id' => null]))->toThrow(RuntimeException::class, 'discriminator');
});

it('detects corrupt or missing operations and recovers only at an intact independent checkpoint', function (): void {
    $history = new RevisionDiffPrototype('entry:42', 10);
    for ($i = 1; $i <= 50; $i++) {
        $history->append(revisionGrowthState($i));
    }

    $rows = $history->rows();
    $rows[12]['payload'][0]['value'] = 'corrupted';
    $corrupt = RevisionDiffPrototype::fromRows('entry:42', 10, $rows);
    expect(fn () => $corrupt->read(15))->toThrow(RuntimeException::class, 'checksum')
        ->and($corrupt->read(21))->toEqual(revisionGrowthState(21));

    $rows = $history->rows();
    array_splice($rows, 10, 1); // Missing checkpoint 11, rather than a silently shorter history.
    $missing = RevisionDiffPrototype::fromRows('entry:42', 10, $rows);
    expect(fn () => $missing->read(15))->toThrow(RuntimeException::class, 'sequence')
        ->and($missing->read(21))->toEqual(revisionGrowthState(21));
});

it('refuses foreign identities and malformed ordering without applying their data', function (): void {
    $history = new RevisionDiffPrototype('entry:42', 5);
    $history->append(revisionGrowthState(1));
    $history->append(revisionGrowthState(2));

    expect(fn () => RevisionDiffPrototype::fromRows('entry:99', 5, $history->rows())->read(2))
        ->toThrow(RuntimeException::class, 'identity');
    expect(fn () => RevisionDiffPrototype::fromRows('entry:42', 5, array_reverse($history->rows())))
        ->toThrow(RuntimeException::class, 'order');
    expect(fn () => new RevisionDiffPrototype('entry:42', 51))->toThrow(RuntimeException::class, 'interval');
});
