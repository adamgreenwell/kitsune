<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\AvailabilityDeclaration;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\DeclaresAvailability;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;

/*
 * Separate native connections at actual level zero. The test framework's usual wrapper stays on its original
 * connection; neither participant borrows its uncommitted fixtures or its testing transaction manager.
 * SQLite's one writer proves neither the existing-decision lock nor the parent's FK insert fence.
 */
beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('Native availability row locks and FK insert fences need a server engine.');
    }
    $this->availabilityOriginal = DB::getDefaultConnection();
    $configuration = config('database.connections.'.$this->availabilityOriginal);
    config(['database.connections.av204-own' => $configuration, 'database.connections.av204-rival' => $configuration]);
    DB::setDefaultConnection('av204-own');
    expect(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    $this->availabilityOrg = Org::create(['name' => 'Availability race', 'slug' => 'av204-race-'.bin2hex(random_bytes(6))]);
    app(Context::class)->setOrg($this->availabilityOrg);
    $this->availabilityDefinition = new class implements DeclaresAvailability
    {
        public function handle(): string
        {
            return 'availability_race';
        }

        public function version(): string
        {
            return '1.0.0';
        }

        public function entryTypes(): array
        {
            return [new EntryTypeDeclaration(handle: 'race_type', name: 'Race', pluralName: 'Races')];
        }

        public function roles(): array
        {
            return [];
        }

        public function availability(): array
        {
            return [new AvailabilityDeclaration('race_type', 'org', null, false)];
        }
    };
    BlueprintApplier::apply($this->availabilityDefinition);
    $this->availabilityTypeId = (int) EntryType::query()->where('org_id', $this->availabilityOrg->id)->where('handle', 'race_type')->sole()->id;
    $this->availabilityRowId = (int) EntryTypeAvailability::query()->where('entry_type_id', $this->availabilityTypeId)->sole()->id;
    foreach (['av204-own', 'av204-rival'] as $name) {
        av204ShortWait(DB::connection($name));
    }
});

afterEach(function (): void {
    if (! isset($this->availabilityOriginal)) {
        return;
    }
    try {
        foreach (['av204-rival', 'av204-own'] as $name) {
            $db = DB::connection($name);
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
        $db = DB::connection('av204-own');
        $db->table('orgs')->where('id', $this->availabilityOrg->id)->delete();
        expect($db->table('orgs')->where('id', $this->availabilityOrg->id)->exists())->toBeFalse()
            ->and($db->table('blueprints')->where('org_id', $this->availabilityOrg->id)->exists())->toBeFalse()
            ->and($db->table('entry_types')->where('id', $this->availabilityTypeId)->exists())->toBeFalse()
            ->and($db->table('entry_type_availability')->where('entry_type_id', $this->availabilityTypeId)->exists())->toBeFalse();
    } finally {
        app(Context::class)->forget();
        DB::setDefaultConnection($this->availabilityOriginal);
        DB::purge('av204-own');
        DB::purge('av204-rival');
    }
});

function av204ShortWait(Connection $db): void
{
    $db->getDriverName() === 'pgsql'
        ? $db->statement("set lock_timeout = '400ms'")
        : $db->statement('set session innodb_lock_wait_timeout = 1');
}

function av204ContenderWrite(Connection $db, string $operation, int $typeId, int $rowId, int $orgId): void
{
    if ($operation === 'edit') {
        $db->table('entry_type_availability')->where('id', $rowId)->update(['is_enabled' => true]);
    } else {
        // Deliberately raw, like an independent writer: only the actual parent FK can fence this insert.
        $db->table('entry_type_availability')->insert(['entry_type_id' => $typeId, 'scope_type' => 'org', 'scope_id' => $orgId + 1000000, 'is_enabled' => false]);
    }
}

function av204LockTimeout(QueryException $error): bool
{
    return in_array($error->errorInfo[0] ?? null, ['55P03', 'HY000'], true)
        && preg_match('/lock timeout|lock wait timeout/i', $error->getMessage()) === 1;
}

it('fences a rival availability edit or insert while reverse holds its parent and decisions', function (string $operation): void {
    $attempted = false;
    $blocked = false;
    DB::listen(function (QueryExecuted $query) use ($operation, &$attempted, &$blocked): void {
        if ($attempted || $query->connectionName !== 'av204-own'
            || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'entry_type_availability')) {
            return;
        }
        $attempted = true;
        $rival = DB::connection('av204-rival');
        expect($rival->transactionLevel())->toBe(0)->and($query->connection->transactionLevel())->toBe(1);
        $rival->beginTransaction();
        try {
            av204ContenderWrite($rival, $operation, $this->availabilityTypeId, $this->availabilityRowId, $this->availabilityOrg->id);
        } catch (QueryException $error) {
            if (! av204LockTimeout($error)) {
                throw $error;
            }
            $blocked = true;
        } finally {
            $rival->rollBack();
        }
    });
    $result = BlueprintApplier::reverse('availability_race');
    expect($attempted)->toBeTrue()->and($blocked)->toBeTrue()->and($result['outcome'])->toBe('reversed')
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
})->with(['edit', 'insert']);

it('waits for a rival decision write and then refuses its operator change in a fresh transaction', function (string $operation): void {
    $rival = DB::connection('av204-rival');
    $rival->beginTransaction();
    av204ContenderWrite($rival, $operation, $this->availabilityTypeId, $this->availabilityRowId, $this->availabilityOrg->id);
    $blocked = false;
    try {
        BlueprintApplier::reverse('availability_race');
    } catch (RuntimeException $error) {
        $previous = $error->getPrevious();
        if (! $previous instanceof QueryException || ! av204LockTimeout($previous)) {
            throw $error;
        }
        $blocked = true;
    } finally {
        $rival->commit();
    }
    expect($blocked)->toBeTrue()->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and(Blueprint::receiptFor('availability_race'))->not->toBeNull();
    $receipt = Blueprint::receiptFor('availability_race')->getAttributes();
    expect(fn () => BlueprintApplier::reverse('availability_race'))->toThrow(RuntimeException::class, 'availability')
        ->and(Blueprint::receiptFor('availability_race')->getAttributes())->toBe($receipt)
        ->and(EntryTypeAvailability::query()->where('entry_type_id', $this->availabilityTypeId)->count())->toBe($operation === 'edit' ? 1 : 2);
})->with(['edit', 'insert']);
