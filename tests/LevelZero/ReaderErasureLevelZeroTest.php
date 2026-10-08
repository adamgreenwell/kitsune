<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\TestCase;

/*
 * One reader's erasure at transaction level 0, on a file another process can read — ADR-037, as built: the entitlements
 * and the account go together or not at all, so a reader is never half-erased.
 */

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'custody', '--path' => [TestCase::READERS_MIGRATION], '--realpath' => true, '--force' => true]);
    config(AppServiceProvider::readerConfig());
    Fx::boot();

    $this->org = Fx::org('zero');
    $this->site = Fx::site($this->org, 'zero');
    $this->reader = Reader::createReader('zero@kitsune.test', Hash::make('correct-horse-battery-staple'), null);
    Fx::plant($this->site, (string) $this->reader->getKey(), 'course.advanced-php');
    Fx::plant($this->site, (string) $this->reader->getKey(), 'course.linked');
    Fx::nobody();
    app(Context::class)->forget()->setOrg($this->org);
});

afterEach(fn () => Fx::tearDown());

/** What another process sees. @return array{readers: int, entitlements: int} */
function readerZeroSeen(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);

    return [
        'readers' => (int) $pdo->query('select count(*) from readers')->fetchColumn(),
        'entitlements' => (int) $pdo->query('select count(*) from entitlements')->fetchColumn(),
    ];
}

it('commits the entitlements\' erasure and the account\'s together', function (): void {
    expect(readerZeroSeen($this->custodyFile))->toBe(['readers' => 1, 'entitlements' => 2]);

    expect(app(ReaderErasure::class)->erase((string) $this->reader->getKey()))->toBe(['grants' => 2, 'account' => true])
        ->and(readerZeroSeen($this->custodyFile))->toBe(['readers' => 0, 'entitlements' => 0])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('keeps the entitlements when the account cannot be erased', function (): void {
    Reader::deleting(static function (): bool {
        throw new RuntimeException('The host refused to delete the row.');
    });

    expect(fn () => app(ReaderErasure::class)->erase((string) $this->reader->getKey()))
        ->toThrow(RuntimeException::class, 'The host refused to delete the row.');

    expect(readerZeroSeen($this->custodyFile))->toBe(['readers' => 1, 'entitlements' => 2])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});
