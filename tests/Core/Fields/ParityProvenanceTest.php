<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3).'/tools/parity/provenance.php';
require_once dirname(__DIR__, 3).'/tools/property-parity/names.php';

/** Compare synthetic measurement rows through the real CLI without making engine measurements. */
function parityComparison(string $suite, array|object $pcre, array|object $v8): Process
{
    $directory = sys_get_temp_dir().'/kitsune-parity-'.bin2hex(random_bytes(6));
    File::makeDirectory($directory, 0700);
    try {
        $left = $directory.'/pcre.json';
        $right = $directory.'/v8.json';
        File::put($left, json_encode($pcre, JSON_THROW_ON_ERROR));
        File::put($right, json_encode($v8, JSON_THROW_ON_ERROR));
        $command = new Process([PHP_BINARY, dirname(__DIR__, 3)."/tools/{$suite}-parity/compare.php", $left, $right]);
        $command->run();

        return $command;
    } finally {
        File::deleteDirectory($directory);
    }
}

function syntheticParityV8(string $suite): array
{
    $engine = ['name' => 'v8', 'node' => '24.0.0', 'version' => '13.6.233.8', 'unicode' => '16.0'];

    return $suite === 'pattern' ? [...$engine, 'deadline_ms' => 2000] : $engine;
}

it('refuses empty and matching incomplete property dumps', function (bool $envelope): void {
    $left = $right = new stdClass;
    if ($envelope) {
        $corpus = parityPropertyCorpus();
        $names = json_decode($corpus, true, 512, JSON_THROW_ON_ERROR)['names'];
        $rows = array_map(static fn (string $id): array => ['id' => $id, 'error' => 'does not compile'], array_slice($names, 1));
        $left = parityDump('property', $corpus, parityPcreEngine(), $rows);
        $right = parityDump('property', $corpus, syntheticParityV8('property'), $rows);
    }

    $process = parityComparison('property', $left, $right);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain($envelope ? 'complete property corpus' : 'measurement format');
})->with(['two empty objects' => false, 'both omit the same name' => true]);

it('validates membership summary consistency before comparing', function (string $change, string $reason): void {
    $corpus = parityPropertyCorpus();
    $names = json_decode($corpus, true, 512, JSON_THROW_ON_ERROR)['names'];
    $ranges = [[65, 66], [97, 98]];
    $rows = array_map(static fn (string $id): array => ['id' => $id, 'count' => 4,
        'sha' => sha1(json_encode($ranges, JSON_THROW_ON_ERROR)), 'ranges' => $ranges], $names);
    $left = parityDump('property', $corpus, parityPcreEngine(), $rows);
    $right = parityDump('property', $corpus, syntheticParityV8('property'), $rows);
    match ($change) {
        'valid' => null,
        'shape' => $left['rows'][0]['error'] = 'does not compile',
        'count' => $left['rows'][0]['count'] = 3,
        'digest' => $left['rows'][0]['sha'] = str_repeat('a', 40),
        'surrogate' => $left['rows'][0]['ranges'] = [[0xD7FF, 0xE000]],
        'overlap' => $left['rows'][0]['ranges'] = [[65, 66], [66, 68]],
        'duplicate' => $left['rows'][1]['id'] = $left['rows'][0]['id'],
        'stale source' => $left['sources']['tools/property-parity/measure.php'] = str_repeat('a', 64),
    };

    $process = parityComparison('property', $left, $right);

    if ($change === 'valid') {
        expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toContain(count($names).' agree exactly');
    } else {
        expect($process->getExitCode())->toBe(2)->and($process->getErrorOutput())->toContain($reason);
    }
})->with([
    ['valid', ''],
    ['shape', 'Malformed'],
    ['count', 'Inconsistent'],
    ['digest', 'Inconsistent'],
    ['surrogate', 'Invalid'],
    ['overlap', 'Invalid'],
    ['duplicate', 'duplicate measurement id'],
    ['stale source', 'sources do not match'],
]);

it('validates pattern provenance and distinguishes an unanswered match from a disagreement', function (string $change, string $reason): void {
    $corpus = parityRead(dirname(__DIR__, 3).'/tools/pattern-parity/cases.json');
    $cases = json_decode($corpus, true, 512, JSON_THROW_ON_ERROR);
    $rows = array_map(static fn (array $case): array => ['id' => $case['id'], 'compiles' => true, 'matches' => true], $cases);
    $left = parityDump('pattern', $corpus, parityPcreEngine(), $rows);
    $right = parityDump('pattern', $corpus, syntheticParityV8('pattern'), $rows);

    if (in_array($change, ['pattern', 'subject'], true)) {
        $cases[0][$change] .= 'changed';
        $left['corpus_sha256'] = $right['corpus_sha256'] = hash('sha256', json_encode($cases, JSON_THROW_ON_ERROR));
    }
    if ($change === 'impossible') {
        $left['rows'][0]['compiles'] = false;
    }
    if ($change === 'runtime') {
        $left['engine']['backtrack_limit']++;
    }
    if (in_array($change, ['timeout', 'disagreement', 'compile disagreement'], true)) {
        $index = array_search('space-nbsp', array_column($rows, 'id'), true);
        expect($index)->not->toBeFalse();
        $right['rows'][$index] = match ($change) {
            'timeout' => ['id' => $rows[$index]['id'], 'compiles' => null, 'matches' => null, 'timedOut' => true],
            'disagreement' => [...$rows[$index], 'matches' => false],
            'compile disagreement' => [...$rows[$index], 'matches' => null, 'workerError' => true],
        };
        if ($change === 'compile disagreement') {
            $left['rows'][$index] = [...$rows[$index], 'compiles' => false, 'matches' => null];
        }
    }

    $process = parityComparison('pattern', $left, $right);

    if (in_array($change, ['timeout', 'disagreement', 'compile disagreement'], true)) {
        expect($process->getExitCode())->toBe($change === 'timeout' ? 3 : 1)
            ->and($process->getOutput())->toContain($reason);
    } else {
        expect($process->getExitCode())->toBe(2)->and($process->getErrorOutput())->toContain($reason);
    }
})->with([
    ['pattern', 'corpus digest is stale'],
    ['subject', 'corpus digest is stale'],
    ['impossible', 'Malformed'],
    ['runtime', 'incompatible pcre2 runtime'],
    ['timeout', 'no verdict from an engine 1'],
    ['disagreement', 'divergent AND accepted  1'],
    ['compile disagreement', 'divergent AND accepted  1'],
]);
