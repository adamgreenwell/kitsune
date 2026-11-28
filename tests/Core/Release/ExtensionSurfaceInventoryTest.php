<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('surveys declared symbols, command signatures, configuration and host imports', function (): void {
    $script = dirname(__DIR__, 3).'/bin/inventory-extension-surface.php';
    $process = new Process([PHP_BINARY, $script]);
    $process->mustRun();
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    $fieldContract = $report['symbols']['Kitsune\Core\Http\Api\EntryFieldContract'];
    $rules = $report['symbols']['Kitsune\Core\Validation\Rule'];
    $scopedUnique = array_column($rules['members'], null, 'name')['scopedUnique'];
    expect($fieldContract['classification'])->toBe('internal')
        ->and($fieldContract['members'][0]['classification'])->toBe('internal')
        ->and($rules['classification'])->toBe('unclassified')
        ->and($scopedUnique['visibility'])->toBe('public')
        ->and($scopedUnique['parameters'])->toContain('model', 'column')
        ->and($report['configuration'])->toContain('kitsune.readers.guard')
        ->and(array_column($report['commands'], 'owner'))->toContain('Kitsune\Core\Console\SiteThemeCommand');

    $imports = array_values(array_filter($report['core_imports'], fn (array $row): bool => $row['path'] === 'skeleton/app/Models/Reader.php'));
    expect(array_column($imports, 'symbol'))->toContain(
        'Kitsune\Core\Readers\Contracts\ReaderAccount',
        'Kitsune\Core\Readers\Contracts\AnchorsReaderErasure',
    );
});
