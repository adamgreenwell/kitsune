<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Tests\Fixtures\CommittedModuleReceipt;

it('preserves an existing receipt when fixture setup stops before or during creation', function (bool $attemptInsert): void {
    $connection = DB::connection();
    $receipts = $connection->table('modules');
    $attributes = [
        'handle' => 'kitsune/already-installed', 'version' => '1.0.0',
        'is_enabled' => false, 'installed_at' => '2026-09-01 12:34:56',
    ];
    $id = $receipts->insertGetId($attributes);
    $before = (array) $connection->table('modules')->where('id', $id)->sole();
    $fixture = new CommittedModuleReceipt($connection);

    if ($attemptInsert) {
        expect(fn () => $connection->transaction(fn () => $fixture->create($attributes)))
            ->toThrow(UniqueConstraintViolationException::class);
    }

    $fixture->remove();

    expect((array) $connection->table('modules')->where('id', $id)->sole())->toBe($before);
})->with(['before creation' => false, 'duplicate handle at creation' => true]);

it('removes the owned receipt after a state change and preserves unrelated receipts', function (): void {
    $connection = DB::connection();
    $fixture = new CommittedModuleReceipt($connection);
    $module = $fixture->create([
        'handle' => 'kitsune/run-owned', 'version' => '1.0.0',
        'is_enabled' => true, 'installed_at' => '2026-09-02 12:34:56',
    ]);
    $sentinelId = $connection->table('modules')->insertGetId([
        'handle' => 'kitsune/unrelated', 'version' => '2.0.0',
        'is_enabled' => true, 'installed_at' => '2026-09-03 12:34:56',
    ]);
    $sentinel = (array) $connection->table('modules')->where('id', $sentinelId)->sole();

    $module->update(['is_enabled' => false]);
    $fixture->remove();

    expect($connection->table('modules')->where('id', $module->id)->exists())->toBeFalse()
        ->and((array) $connection->table('modules')->where('id', $sentinelId)->sole())->toBe($sentinel);
});

it('refuses to delete an identity that changed after fixture creation', function (): void {
    $connection = DB::connection();
    $fixture = new CommittedModuleReceipt($connection);
    $module = $fixture->create([
        'handle' => 'kitsune/run-owned', 'version' => '1.0.0',
        'is_enabled' => true, 'installed_at' => '2026-09-02 12:34:56',
    ]);
    $module->update(['version' => '2.0.0']);
    $before = (array) $connection->table('modules')->where('id', $module->id)->sole();

    expect(fn () => $fixture->remove())->toThrow(RuntimeException::class, 'changed identity')
        ->and((array) $connection->table('modules')->where('id', $module->id)->sole())->toBe($before);
});
