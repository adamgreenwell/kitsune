<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Kitsune\Core\Events\Modules\ModuleDisabled;
use Kitsune\Core\Events\Modules\ModuleEnabled;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleDiscovery;

it('reports the saved state and the notification failure separately', function (bool $enabled, string $action, string $event, bool $outerTransaction): void {
    $handle = 'kitsune/fixture-module';
    $receipt = Module::create([
        'handle' => $handle,
        'version' => ModuleDiscovery::version($handle),
        'is_enabled' => ! $enabled,
        'installed_at' => now(),
    ]);
    $calls = 0;
    Event::listen($event, function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('Fixture listener failed.');
    });

    $connection = $receipt->getConnection();
    if ($outerTransaction) {
        $connection->beginTransaction();
    }

    try {
        $arguments = ['action' => $action, 'package' => $handle];
        expect(Artisan::call('kitsune:module', $arguments))->toBe(1)
            ->and(Artisan::output())->toContain(
                'A module notification listener failed.',
                $outerTransaction ? 'The caller still controls the open transaction.' : 'The switch already committed.',
                'Other listeners may have been skipped.',
                'Fixture listener failed.',
            )->and($receipt->fresh()->is_enabled)->toBe($enabled);

        expect(Artisan::call('kitsune:module', $arguments))->toBe(0)
            ->and(Artisan::output())->toContain('already')
            ->and($calls)->toBe(1);
    } finally {
        if ($outerTransaction) {
            $connection->rollBack();
        }
    }

    expect($receipt->fresh()->is_enabled)->toBe($outerTransaction ? ! $enabled : $enabled);
})->with([[true, 'enable', ModuleEnabled::class], [false, 'disable', ModuleDisabled::class]])
    ->with([false, true]);
