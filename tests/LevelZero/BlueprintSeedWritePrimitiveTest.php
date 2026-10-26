<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;

/*
 * Existing write primitives measured before #205's seed declaration/receipt contract.
 * These tests do not implement a seed receipt or claim a seeder exists. An independent
 * PDO observes real commits, rather than RefreshDatabase savepoint releases.
 */
beforeEach(function (): void {
    FixtureBlueprint::reset();
    FixtureBlueprint::$override = [new EntryTypeDeclaration('seed_probe', 'Probe', 'Probes', fields: [
        new FieldDeclaration('probe_body', 'text', 'Body', 'none'),
        new FieldDeclaration('probe_null', 'text', 'Null', 'none'),
        new FieldDeclaration('probe_empty', 'text', 'Empty', 'none'),
        new FieldDeclaration('probe_list', 'text', 'List', 'none', cardinality: -1),
        new FieldDeclaration('probe_zero', 'number', 'Zero', 'none'),
        new FieldDeclaration('probe_false', 'boolean', 'False', 'none'),
        new FieldDeclaration('probe_links', 'relation', 'Links', 'none', cardinality: -1),
    ])];
    $this->org = Org::create(['name' => 'Seed primitives', 'slug' => 'seed-primitives']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'seed-main', 'name' => 'Main', 'locale' => 'en']);
    $this->sibling = Site::create(['org_id' => $this->org->id, 'handle' => 'sibling', 'slug' => 'seed-sibling', 'name' => 'Sibling', 'locale' => 'de']);
    BlueprintApplier::apply(new FixtureBlueprint);
    $this->type = EntryType::query()->where('org_id', $this->org->id)->where('handle', 'seed_probe')->sole();
    app(Context::class)->setSite($this->site);
    $this->links = FieldStorage::query()->where('handle', 'probe_links')->sole();
    expect(DB::connection()->transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

afterEach(function (): void {
    FixtureBlueprint::reset();
    app(Context::class)->forget();
});

/** Only committed counts/lock flags; no private field values leave the fixture. */
function seed205PrimitiveSeen(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);
    $seen = [];
    foreach (['entries', 'entry_relations', 'entry_revisions', 'audit_log'] as $table) {
        $seen[$table] = (int) $pdo->query('select count(*) from '.$table)->fetchColumn();
    }
    $seen['locks'] = array_map(static fn (mixed $value): bool => (bool) $value,
        $pdo->query('select handle, is_locked from field_storage order by handle')->fetchAll(PDO::FETCH_KEY_PAIR));

    return $seen;
}

function seed205PrimitiveEntry(string $title, string $slug, array $values = []): Entry
{
    return Entry::create(['entry_type_id' => test()->type->id, 'title' => $title, 'slug' => $slug, 'values' => $values]);
}

it('commits audited entry revisions and only populated storage locks together at actual level zero', function (): void {
    $before = seed205PrimitiveSeen($this->custodyFile);
    $entry = DB::connection()->transaction(function () use ($before): Entry {
        $entry = seed205PrimitiveEntry('First', 'first', [
            'probe_body' => 'Body', 'probe_null' => null, 'probe_empty' => '', 'probe_list' => [],
            'probe_zero' => 0, 'probe_false' => false,
        ]);
        expect(seed205PrimitiveSeen($this->custodyFile))->toBe($before)
            ->and(DB::connection()->transactionLevel())->toBe(1);

        return $entry;
    });
    $after = seed205PrimitiveSeen($this->custodyFile);
    expect($after['entries'])->toBe(1)->and($after['entry_revisions'])->toBe(1)
        ->and($after['audit_log'])->toBe($before['audit_log'] + 1)
        ->and($after['locks'])->toBe(['probe_body' => true, 'probe_empty' => false, 'probe_false' => true,
            'probe_links' => false, 'probe_list' => false, 'probe_null' => false, 'probe_zero' => true])
        ->and($entry->site_id)->toBe($this->site->id)->and($this->site->fresh()->locale)->toBe('en')
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('rolls entries relations revisions audit and first-value locks back on an outer failure', function (): void {
    $before = seed205PrimitiveSeen($this->custodyFile);
    expect(fn () => DB::connection()->transaction(function (): void {
        $source = seed205PrimitiveEntry('Source', 'source', ['probe_body' => 'Body']);
        $target = seed205PrimitiveEntry('Target', 'target');
        $source->syncFieldRelations($this->links, [$target->id]);
        expect($this->links->fresh()->is_locked)->toBeTrue();
        throw new RuntimeException('205 outer rollback');
    }))->toThrow(RuntimeException::class, '205 outer rollback');
    expect(seed205PrimitiveSeen($this->custodyFile))->toBe($before)
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
});

it('does not leave a first-value lock or entry when its audit fails', function (): void {
    $before = seed205PrimitiveSeen($this->custodyFile);
    AuditLog::creating(static function (): never {
        throw new RuntimeException('205 audit interruption');
    });
    expect(fn () => seed205PrimitiveEntry('Audit failure', 'audit-failure', ['probe_body' => 'Body']))
        ->toThrow(RuntimeException::class, '205 audit interruption');
    expect(seed205PrimitiveSeen($this->custodyFile))->toBe($before)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('permits forward and cyclic links after all entry rows exist and a repeated identical sync adds no history', function (): void {
    [$first, $second] = DB::connection()->transaction(function (): array {
        $first = seed205PrimitiveEntry('First', 'first');
        $second = seed205PrimitiveEntry('Second', 'second');
        $first->syncFieldRelations($this->links, [$second->id]);
        $second->syncFieldRelations($this->links, [$first->id]);

        return [$first, $second];
    });
    $before = seed205PrimitiveSeen($this->custodyFile);
    expect($before['entries'])->toBe(2)->and($before['entry_relations'])->toBe(2)
        ->and($first->linkedIdsForField($this->links))->toBe([$second->id])
        ->and($second->linkedIdsForField($this->links))->toBe([$first->id]);
    DB::connection()->transaction(function () use ($first, $second): void {
        $first->syncFieldRelations($this->links, [$second->id]);
        $second->syncFieldRelations($this->links, [$first->id]);
    });
    expect(seed205PrimitiveSeen($this->custodyFile))->toBe($before);
});

it('keeps committed content and permanent locks after a caller loses post-commit reporting', function (): void {
    $entry = null;
    expect(function () use (&$entry): void {
        $entry = DB::connection()->transaction(fn (): Entry => seed205PrimitiveEntry('Committed', 'committed', ['probe_body' => 'Body']));
        throw new RuntimeException('205 lost report after commit');
    })->toThrow(RuntimeException::class, '205 lost report after commit');
    $seen = seed205PrimitiveSeen($this->custodyFile);
    expect($seen['entries'])->toBe(1)->and($seen['locks']['probe_body'])->toBeTrue()
        ->and($seen['entry_revisions'])->toBe(1);
    $entry->delete();
    expect($entry->fresh()->trashed())->toBeTrue()
        ->and(FieldStorage::query()->where('handle', 'probe_body')->sole()->is_locked)->toBeTrue();
    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class, 'cannot be reversed');
    expect(seed205PrimitiveSeen($this->custodyFile)['entries'])->toBe(1);
});

it('cannot use a foreign org type for a content write in this site', function (): void {
    $foreign = Org::create(['name' => 'Foreign', 'slug' => 'seed-foreign']);
    app(Context::class)->setOrg($foreign);
    $type = EntryType::create(['org_id' => $foreign->id, 'handle' => 'seed_probe', 'name' => 'Foreign', 'plural_name' => 'Foreign']);
    app(Context::class)->setSite($this->site);
    $before = seed205PrimitiveSeen($this->custodyFile);
    expect(fn () => Entry::create(['entry_type_id' => $type->id, 'slug' => 'foreign', 'title' => 'Foreign']))
        ->toThrow(RuntimeException::class);
    expect(seed205PrimitiveSeen($this->custodyFile))->toBe($before);
});

it('retains a slug collision through trash while the sibling site has its own slug namespace', function (): void {
    $entry = seed205PrimitiveEntry('Original', 'same');
    $before = seed205PrimitiveSeen($this->custodyFile);
    expect(fn () => seed205PrimitiveEntry('Collision', 'same'))->toThrow(QueryException::class);
    expect(seed205PrimitiveSeen($this->custodyFile))->toBe($before);
    $entry->delete();
    $trashed = seed205PrimitiveSeen($this->custodyFile);
    expect(fn () => seed205PrimitiveEntry('Trash collision', 'same'))->toThrow(QueryException::class);
    expect(seed205PrimitiveSeen($this->custodyFile))->toBe($trashed);
    app(Context::class)->setSite($this->sibling);
    $sibling = seed205PrimitiveEntry('Sibling', 'same');
    expect($sibling->site_id)->toBe($this->sibling->id)
        ->and($this->sibling->fresh()->locale)->toBe('de')
        ->and(seed205PrimitiveSeen($this->custodyFile)['entries'])->toBe(2);
});

it('refuses an explicit sibling or foreign site rather than borrowing the active site authority', function (): void {
    $foreign = Org::create(['name' => 'Foreign', 'slug' => 'seed-foreign']);
    app(Context::class)->setOrg($foreign);
    $foreignSite = Site::create(['org_id' => $foreign->id, 'handle' => 'main', 'slug' => 'foreign-main', 'name' => 'Foreign']);
    app(Context::class)->setSite($this->site);
    $before = seed205PrimitiveSeen($this->custodyFile);
    foreach ([$this->sibling->id, $foreignSite->id] as $site) {
        expect(fn () => Entry::create(['entry_type_id' => $this->type->id, 'site_id' => $site, 'title' => 'Wrong site', 'slug' => 'wrong']))
            ->toThrow(RuntimeException::class);
        expect(seed205PrimitiveSeen($this->custodyFile))->toBe($before);
    }
});

it('can retry a rolled-back batch once without orphaned revisions audits or locks', function (): void {
    $before = seed205PrimitiveSeen($this->custodyFile);
    expect(fn () => DB::connection()->transaction(function (): void {
        seed205PrimitiveEntry('First', 'first', ['probe_body' => 'Body']);
        seed205PrimitiveEntry('Duplicate', 'first');
    }))->toThrow(QueryException::class);
    expect(seed205PrimitiveSeen($this->custodyFile))->toBe($before);
    $entry = DB::connection()->transaction(fn (): Entry => seed205PrimitiveEntry('First', 'first', ['probe_body' => 'Body']));
    $after = seed205PrimitiveSeen($this->custodyFile);
    expect($after['entries'])->toBe(1)->and($after['entry_revisions'])->toBe(1)
        ->and($after['audit_log'])->toBe($before['audit_log'] + 1)
        ->and($entry->revisions()->sole()->title)->toBe('First')
        ->and($after['locks']['probe_body'])->toBeTrue();
});
