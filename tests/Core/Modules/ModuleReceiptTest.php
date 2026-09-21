<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Module;

/** A receipt for a module that is installed and on. */
function receipt(string $handle = 'kitsune/person', bool $enabled = true): Module
{
    return Module::create([
        'handle' => $handle,
        'version' => '1.0.0',
        'is_enabled' => $enabled,
        'installed_at' => now(),
    ]);
}

it('records an installed module and reads it back', function (): void {
    receipt();

    expect(Module::isEnabled('kitsune/person'))->toBeTrue();
});

/**
 * ⚠️ FAIL-CLOSED, AND THE TWO ANSWERS ARE THE SAME ANSWER. A module nobody installed and a module installed
 * and switched off are both "not running", and `isEnabled()` exists so no caller has to remember which shape
 * the absence takes.
 */
it('treats an absent receipt as disabled', function (): void {
    expect(Module::isEnabled('kitsune/never-installed'))->toBeFalse();

    receipt('kitsune/off', enabled: false);

    expect(Module::isEnabled('kitsune/off'))->toBeFalse();
});

it('defaults a receipt to disabled when the column is not written', function (): void {
    Module::create([
        'handle' => 'kitsune/quiet',
        'version' => '1.0.0',
        'installed_at' => now(),
    ]);

    /* Read past the model, because the default is the database's and an attribute default would hide it. */
    expect(DB::table('modules')->where('handle', 'kitsune/quiet')->value('is_enabled'))
        ->toBeIn([0, false]);

    expect(Module::isEnabled('kitsune/quiet'))->toBeFalse();
});

/**
 * ⚠️ THE ONE THAT MATTERS. The kernel reads this table at boot and registers what it finds, so a row anyone
 * can write in bulk is a service provider anyone can register. `RequiresModelSave` is the mechanism seven
 * other models already use, and this asserts that `Module` is actually inside it rather than merely declaring
 * the method.
 */
it('refuses a bulk update of the switch', function (): void {
    receipt(enabled: false);

    expect(fn () => Module::query()->update(['is_enabled' => true]))
        ->toThrow(RuntimeException::class, 'is the switch that decides whether');

    expect(Module::isEnabled('kitsune/person'))->toBeFalse();
});

it('refuses a bulk insert that would plant a receipt', function (): void {
    expect(fn () => Module::query()->insert([
        'handle' => 'kitsune/planted',
        'version' => '1.0.0',
        'is_enabled' => true,
        'installed_at' => now(),
    ]))->toThrow(RuntimeException::class, 'cannot be created in bulk');

    expect(Module::isEnabled('kitsune/planted'))->toBeFalse();
});

/**
 * ⚠️ REGRESSION ON GHSA-7433-7mg3-722g, INHERITED RATHER THAN REIMPLEMENTED. SQLite, MySQL and MariaDB compare
 * column names without regard to case, so before that fix `IS_ENABLED` reached the real column while the
 * guard on it stood aside. A new guarded model is exactly where that would regress unnoticed.
 */
it('refuses the switch under a spelling the database would still honour', function (): void {
    receipt(enabled: false);

    expect(fn () => Module::query()->update(['IS_ENABLED' => true]))
        ->toThrow(RuntimeException::class);

    expect(Module::isEnabled('kitsune/person'))->toBeFalse();
});

/**
 * ⚠️ The database is what refuses this, so the assertion names the database's exception rather than
 * `Throwable`. Two receipts for one handle would be two answers to "which version did we boot".
 */
it('refuses two receipts for one package', function (): void {
    receipt();

    expect(fn () => receipt())->toThrow(UniqueConstraintViolationException::class);

    expect(Module::query()->where('handle', 'kitsune/person')->count())->toBe(1);
});

/** A model save is the allowed path, and it must actually work or the guard above is just a wall. */
it('allows the switch to be flipped through a model save', function (): void {
    $module = receipt(enabled: false);

    $module->is_enabled = true;
    $module->save();

    expect(Module::isEnabled('kitsune/person'))->toBeTrue();
});
