<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Database\EntryWriteReads;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'write-reads', 'name' => 'Write reads']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'write-reads-main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
});
afterEach(fn () => app(Context::class)->forget());
it('restores ordinary reads after successful failed and nested current frames', function (bool $fails): void {
    $reads = app(EntryWriteReads::class);
    $connection = DB::connection();
    $ordinary = Entry::query();
    expect(EntryWriteReads::query($ordinary))->toBe($ordinary)->and($ordinary->getQuery()->lock)->toBeNull();
    $reads->run($connection, function () use ($reads, $connection, $fails): void {
        $current = Entry::query();
        EntryWriteReads::query($current);
        expect(EntryWriteReads::active())->toBeTrue()->and($current->getQuery()->lock)->toBeTrue()
            ->and($current->getQuery()->useWritePdo)->toBeTrue();
        try {
            $reads->run($connection, function () use ($fails): void {
                if ($fails) {
                    throw new RuntimeException('Fixture failure.');
                }
            });
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Fixture failure.');
        }
        expect(EntryWriteReads::active())->toBeTrue();
    });
    expect(EntryWriteReads::active())->toBeFalse()->and(EntryWriteReads::query(Entry::query())->getQuery()->lock)->toBeNull();
})->with([false, true]);
it('rejects a changed connection or scope and clears the frame after refusal', function (string $failure): void {
    $reads = app(EntryWriteReads::class);
    $connection = DB::connection();
    $other = Site::create(['handle' => 'other', 'slug' => 'write-reads-other', 'name' => 'Other', 'locale' => 'en']);
    try {
        expect(fn () => $reads->run($connection, function () use ($failure, $connection, $other): void {
            if ($failure === 'connection') {
                EntryWriteReads::query((clone $connection)->table('entries'));
            } else {
                app(Context::class)->setSite($other);
                EntryWriteReads::query(Entry::query());
            }
        }))->toThrow(RuntimeException::class, 'lost');
    } finally {
        app(Context::class)->setSite($this->site);
    }
    expect(EntryWriteReads::active())->toBeFalse();
})->with(['connection', 'scope']);
it('requires both scope levels and an open transaction before a current frame starts', function (string $failure): void {
    $connection = DB::connection();
    if ($failure === 'transaction') {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
    } else {
        app(Context::class)->forget();
    }
    expect(fn () => app(EntryWriteReads::class)->run($connection, fn () => null))->toThrow(RuntimeException::class, 'require')
        ->and(EntryWriteReads::active())->toBeFalse();
})->with(['transaction', 'scope']);
