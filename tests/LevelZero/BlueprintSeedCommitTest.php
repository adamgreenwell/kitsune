<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintSeeder;
use Kitsune\Core\Blueprints\Declarations\SeedEntryDeclaration;
use Kitsune\Core\Models\BlueprintSeed;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\SeedBlueprint;

beforeEach(function (): void {
    $org = Org::create(['name' => 'Seed commits', 'slug' => 'seed-commits']);
    app(Context::class)->setOrg($org);
    $this->site = Site::create(['org_id' => $org->id, 'name' => 'Main', 'slug' => 'seed-commits', 'handle' => 'main', 'locale' => 'en']);
    $this->definition = new SeedBlueprint([
        new SeedEntryDeclaration('first', 'seed_article', 'First', 'first', ['seed_body' => 'First'], ['seed_related' => ['second']]),
        new SeedEntryDeclaration('second', 'seed_article', 'Second', 'second', relations: ['seed_related' => ['first']]),
    ]);
    BlueprintApplier::apply($this->definition);
    app(Context::class)->setSite($this->site);
    expect(DB::connection()->transactionLevel())->toBe(0);
});

afterEach(fn () => app(Context::class)->forget());

/** Independent committed counts, without importing content. @return array<string, int> */
function seed205Committed(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);
    $counts = [];
    foreach (['blueprint_seeds', 'entries', 'entry_relations', 'entry_revisions', 'audit_log'] as $table) {
        $counts[$table] = (int) $pdo->query('select count(*) from '.$table)->fetchColumn();
    }
    $counts['locks'] = (int) $pdo->query('select count(*) from field_storage where is_locked = 1')->fetchColumn();

    return $counts;
}

it('commits seed facts entries ordered relations revisions audit and locks as one actual outer transaction', function (): void {
    $before = seed205Committed($this->custodyFile);
    $seen = null;
    BlueprintSeed::saved(function () use (&$seen): void {
        $seen = seed205Committed($this->custodyFile);
    });
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $after = seed205Committed($this->custodyFile);
    expect($seen)->toBe($before)->and($after['blueprint_seeds'])->toBe(1)->and($after['entries'])->toBe(2)
        ->and($after['entry_relations'])->toBe(2)->and($after['entry_revisions'])->toBeGreaterThan(1)
        ->and($after['audit_log'])->toBeGreaterThan($before['audit_log'])->and($after['locks'])->toBe(2)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('leaves no seed receipt content history or locks when interrupted after its receipt save but before commit', function (): void {
    $before = seed205Committed($this->custodyFile);
    BlueprintSeed::saved(static function (): never {
        throw new RuntimeException('205 before outer commit');
    });
    expect(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class, '205 before outer commit');
    expect(seed205Committed($this->custodyFile))->toBe($before)->and(DB::connection()->transactionLevel())->toBe(0);
});

it('recovers a committed but unreported seed result without another entry relation revision or audit', function (): void {
    expect(function (): void {
        BlueprintSeeder::seed($this->definition, $this->site, 'en');
        throw new RuntimeException('205 result lost after commit');
    })->toThrow(RuntimeException::class, '205 result lost after commit');
    $committed = seed205Committed($this->custodyFile);
    $result = BlueprintSeeder::seed($this->definition, $this->site, 'en');
    expect(seed205Committed($this->custodyFile))->toBe($committed)
        ->and($result)->toBe(['created' => [], 'skipped' => [], 'retained' => ['first', 'second']]);
});

it('reads committed facts on retry after an actual afterCommit callback interrupts result delivery', function (): void {
    $armed = true;
    BlueprintSeed::saved(function () use (&$armed): void {
        if ($armed) {
            $armed = false;
            DB::afterCommit(static function (): never {
                throw new RuntimeException('205 committed callback interrupted');
            });
        }
    });
    expect(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class, '205 committed callback interrupted');
    $committed = seed205Committed($this->custodyFile);
    expect($committed['blueprint_seeds'])->toBe(1)->and($committed['entries'])->toBe(2)
        ->and(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    expect(seed205Committed($this->custodyFile))->toBe($committed);
});
