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
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;

/*
 * The blueprint receipt, seen as production commits it — ADR-039's owed "killing an apply between the receipt and
 * the rows", at a real level 0.
 *
 * ⚠️ READ FROM A SECOND CONNECTION, BECAUSE ONLY A COMMIT IS VISIBLE THERE. Under `RefreshDatabase` every write is in
 * one wrapper transaction, so "the receipt commits before the rows" and "the manifest commits with the rows" are both
 * claims nothing in the rest of the suite can see. A second PDO on the same file sees what has committed and nothing
 * else, which is exactly what a process killed at that moment leaves behind.
 */

beforeEach(function (): void {
    FixtureBlueprint::reset();
    FixtureBlueprint::$override = [
        new EntryTypeDeclaration(handle: 'dispatch', name: 'Dispatch', pluralName: 'Dispatches', fields: [
            new FieldDeclaration(handle: 'dispatch_code', type: 'text', label: 'Code', piiClass: 'none', isIndexed: true),
        ]),
    ];
    FixtureBlueprint::$roles = [new RoleDeclaration('dispatcher', 'Dispatcher', ['dispatch' => ['view']])];

    $this->org = Org::create(['slug' => 'receipt-zero', 'name' => 'Receipt zero']);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

/** What a second process sees: the committed state of the file, and nothing in flight. @return array<string, mixed> */
function receiptZeroSeen(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);
    $receipt = $pdo->query("select version, manifest, applied_at from blueprints where handle = 'fixture'")->fetch(PDO::FETCH_ASSOC);

    return [
        'receipt' => $receipt === false ? null : $receipt,
        'entry_types' => (int) $pdo->query("select count(*) from entry_types where handle = 'dispatch'")->fetchColumn(),
        'roles' => (int) $pdo->query("select count(*) from roles where handle = 'dispatcher'")->fetchColumn(),
    ];
}

it('commits the receipt before it writes the rows, so a kill between the two leaves it findable', function (): void {
    $seen = null;

    DB::listen(function (QueryExecuted $query) use (&$seen): void {
        if ($seen === null && str_starts_with($query->sql, 'insert into "entry_types"')) {
            $seen = receiptZeroSeen($this->custodyFile);

            throw new RuntimeException('killed between the receipt and the rows');
        }
    });

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'killed between the receipt and the rows');

    expect($seen)->toBe([
        'receipt' => ['version' => '1.0.0', 'manifest' => null, 'applied_at' => null],
        'entry_types' => 0,
        'roles' => 0,
    ]);

    $after = receiptZeroSeen($this->custodyFile);

    expect($after['entry_types'])->toBe(0)
        ->and($after['roles'])->toBe(0)
        ->and($after['receipt'])->toBe(['version' => '1.0.0', 'manifest' => null, 'applied_at' => null])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

/**
 * ⚠️ THE MANIFEST COMMITS WITH THE ROWS. A kill after the commit and before the finish leaves rows AND their manifest,
 * which is the one state the next run completes rather than redoes — and the second connection proves both landed.
 */
it('commits the manifest with the rows, so a kill after them is finished by the next run', function (): void {
    SchemaManagerStandIn::install()->throwOnce(new RuntimeException('killed after the rows committed'));

    expect(fn () => BlueprintApplier::apply(new FixtureBlueprint))
        ->toThrow(RuntimeException::class, 'killed after the rows committed');

    $seen = receiptZeroSeen($this->custodyFile);

    expect($seen['entry_types'])->toBe(1)
        ->and($seen['roles'])->toBe(1)
        ->and($seen['receipt']['manifest'])->not->toBeNull()
        ->and(json_decode((string) $seen['receipt']['manifest'], true)['roles'][0]['handle'])->toBe('dispatcher')
        ->and($seen['receipt']['applied_at'])->toBeNull()
        ->and(DB::connection()->transactionLevel())->toBe(0);

    app()->forgetInstance(SchemaManager::class);

    expect(BlueprintApplier::apply(new FixtureBlueprint)['indexed'])->toBe(1);

    $finished = receiptZeroSeen($this->custodyFile);

    expect($finished['entry_types'])->toBe(1)
        ->and($finished['roles'])->toBe(1)
        ->and($finished['receipt']['applied_at'])->not->toBeNull()
        ->and(Blueprint::receiptFor('fixture')->applied_at)->not->toBeNull();
});
