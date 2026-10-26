<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\AvailabilityDeclaration;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\DeclaresAvailability;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Availability zero', 'slug' => 'availability-zero']);
    app(Context::class)->setOrg($this->org);
    expect(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

afterEach(function (): void {
    app(Context::class)->forget();
});

function av204ZeroDefinition(): DeclaresAvailability
{
    return new class implements DeclaresAvailability
    {
        public function handle(): string
        {
            return 'availability_zero';
        }

        public function version(): string
        {
            return '1.0.0';
        }

        public function entryTypes(): array
        {
            return [new EntryTypeDeclaration(handle: 'zero_type', name: 'Zero', pluralName: 'Zeros')];
        }

        public function roles(): array
        {
            return [];
        }

        public function availability(): array
        {
            return [new AvailabilityDeclaration('zero_type', 'org', null, false)];
        }
    };
}

/** Committed facts seen through an independent PDO, outside the kernel's transaction. */
function av204ZeroSeen(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);
    $receipt = $pdo->query("select manifest, applied_at from blueprints where handle = 'availability_zero'")->fetch(PDO::FETCH_ASSOC);

    return ['receipt' => $receipt === false ? null : $receipt,
        'types' => (int) $pdo->query("select count(*) from entry_types where handle = 'zero_type'")->fetchColumn(),
        'decisions' => (int) $pdo->query('select count(*) from entry_type_availability')->fetchColumn()];
}

it('commits decisions with their ownership receipt and rolls both back when manifest writing is interrupted', function (): void {
    $seen = null;
    DB::listen(function (QueryExecuted $query) use (&$seen): void {
        if ($seen === null && str_starts_with($query->sql, 'update "blueprints"') && str_contains($query->sql, '"manifest"')) {
            $seen = av204ZeroSeen($this->custodyFile);
            expect($query->connection->transactionLevel())->toBe(1);
            throw new RuntimeException('204 manifest interruption');
        }
    });
    expect(fn () => BlueprintApplier::apply(av204ZeroDefinition()))->toThrow(RuntimeException::class, '204 manifest interruption');
    $empty = ['receipt' => ['manifest' => null, 'applied_at' => null], 'types' => 0, 'decisions' => 0];
    expect($seen)->toBe($empty)->and(av204ZeroSeen($this->custodyFile))->toBe($empty)
        ->and(DB::connection()->transactionLevel())->toBe(0);
    BlueprintApplier::apply(av204ZeroDefinition());
    $committed = av204ZeroSeen($this->custodyFile);
    expect($committed['types'])->toBe(1)->and($committed['decisions'])->toBe(1)
        ->and(json_decode($committed['receipt']['manifest'], true)['availability'][0]['outcome'])->toBe('created');
});

it('finishes an actual committed decision receipt without replaying a subsequently removed decision', function (): void {
    $armed = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$armed): void {
        // finishReceipt uses a conditional bulk update, deliberately bypassing model updating events.
        if ($armed && str_starts_with($query, 'update "blueprints" set "applied_at"')) {
            $armed = false;
            throw new RuntimeException('204 interrupted finish');
        }
    });
    expect(fn () => BlueprintApplier::apply(av204ZeroDefinition()))->toThrow(RuntimeException::class, '204 interrupted finish');
    $committed = av204ZeroSeen($this->custodyFile);
    expect($committed['receipt']['applied_at'])->toBeNull()->and($committed['decisions'])->toBe(1)
        ->and(json_decode($committed['receipt']['manifest'], true)['availability'])->toHaveCount(1)
        ->and(DB::connection()->transactionLevel())->toBe(0);
    EntryTypeAvailability::query()->delete();
    $result = BlueprintApplier::apply(av204ZeroDefinition());
    expect(implode(' ', $result['skipped']))->toContain('availability decision', 'not written again')
        ->and(av204ZeroSeen($this->custodyFile)['decisions'])->toBe(0)
        ->and(av204ZeroSeen($this->custodyFile)['receipt']['applied_at'])->not->toBeNull();
});

it('rolls back the parent cascade and decision deletion when the final receipt delete is vetoed', function (): void {
    BlueprintApplier::apply(av204ZeroDefinition());
    $before = av204ZeroSeen($this->custodyFile);
    Blueprint::deleting(fn (): bool => false);
    expect(fn () => BlueprintApplier::reverse('availability_zero'))->toThrow(RuntimeException::class)
        ->and(av204ZeroSeen($this->custodyFile))->toBe($before)
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});
