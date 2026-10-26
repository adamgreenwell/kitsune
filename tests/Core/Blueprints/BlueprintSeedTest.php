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
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\SeedEntryDeclaration;
use Kitsune\Core\Blueprints\DeclaresSeedContent;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\BlueprintSeed;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\SeedBlueprint;

/** @param list<SeedEntryDeclaration> $seeds */
function seed205Definition(array $seeds, string $version = '1.0.0'): DeclaresSeedContent
{
    return new class($seeds, $version) implements DeclaresSeedContent
    {
        public function __construct(private array $seeds, private string $revision) {}

        public function handle(): string
        {
            return 'seed_test';
        }

        public function version(): string
        {
            return $this->revision;
        }

        public function entryTypes(): array
        {
            return [new EntryTypeDeclaration('seed_article', 'Article', 'Articles', fields: [
                new FieldDeclaration('seed_body', 'text', 'Body', 'none'),
                new FieldDeclaration('seed_related', 'relation', 'Related', 'none', cardinality: -1),
            ])];
        }

        public function roles(): array
        {
            return [];
        }

        public function seedContent(): array
        {
            return $this->seeds;
        }
    };
}

function seed205Welcome(): SeedEntryDeclaration
{
    return new SeedEntryDeclaration('welcome', 'seed_article', 'Welcome', 'welcome', ['seed_body' => 'Original']);
}

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Seed test', 'slug' => 'seed-test']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'seed-main', 'name' => 'Main', 'locale' => 'en']);
    $this->sibling = Site::create(['org_id' => $this->org->id, 'handle' => 'sibling', 'slug' => 'seed-sibling', 'name' => 'Sibling', 'locale' => 'de']);
    $this->definition = seed205Definition([seed205Welcome()]);
    BlueprintApplier::apply($this->definition);
    app(Context::class)->setSite($this->site);
});

afterEach(fn () => app(Context::class)->forget());

it('does not seed content during ordinary schema apply', function (): void {
    expect(Entry::query()->withoutGlobalScopes()->count())->toBe(0);
    $type = DB::table('entry_types')->where('org_id', $this->org->id)->where('handle', 'seed_article')->sole();
    expect($type)->not->toBeNull()
        ->and(DB::table('field_storage')->where('org_id', $this->org->id)->where('is_locked', true)->count())->toBe(0);
});

it('creates a separate scoped receipt with content and does not duplicate history on retry', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $first = Entry::query()->sole();
    $before = [Entry::count(), $first->revisions()->count(), AuditLog::count()];
    expect($first->title)->toBe('Welcome')->and($first->site_id)->toBe($this->site->id)
        ->and(BlueprintSeed::query()->count())->toBe(1)
        ->and(DB::table('field_storage')->where('handle', 'seed_body')->value('is_locked'))->toBeTruthy();
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    expect([Entry::count(), $first->revisions()->count(), AuditLog::count()])->toBe($before)
        ->and(BlueprintSeed::query()->count())->toBe(1);
});

it('preserves operator edits trash and committed declaration facts on reapply', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $entry = Entry::query()->sole();
    $entry->update(['title' => 'Operator title', 'values' => ['seed_body' => 'Operator body']]);
    $entry->delete();
    $receipt = BlueprintSeed::query()->sole()->manifest;
    $audits = AuditLog::count();
    $revisions = $entry->revisions()->count();
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    expect($entry->fresh()->title)->toBe('Operator title')->and($entry->fresh()->trashed())->toBeTrue()
        ->and($entry->fresh()->values['seed_body'])->toBe('Operator body')
        ->and(BlueprintSeed::query()->sole()->manifest)->toBe($receipt)
        ->and(AuditLog::count())->toBe($audits)->and($entry->revisions()->count())->toBe($revisions);
});

it('refuses a foreign site or wrong locale before creating any seed receipt or entry', function (): void {
    $foreign = Org::create(['name' => 'Other', 'slug' => 'seed-other']);
    app(Context::class)->setOrg($foreign);
    $site = Site::create(['org_id' => $foreign->id, 'handle' => 'main', 'slug' => 'seed-other-main', 'name' => 'Other']);
    app(Context::class)->setSite($this->site);
    foreach ([[$site, 'en'], [$this->site, 'de']] as [$target, $locale]) {
        expect(fn () => BlueprintSeeder::seed($this->definition, $target, $locale))->toThrow(RuntimeException::class);
        expect(BlueprintSeed::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and(Entry::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and(app(Context::class)->siteId())->toBe($this->site->id);
    }
});

it('selects a sibling explicitly and restores the original site context', function (): void {
    BlueprintSeeder::seed($this->definition, $this->sibling, 'de');
    expect(app(Context::class)->siteId())->toBe($this->site->id)->and(Entry::count())->toBe(0);
    app(Context::class)->setSite($this->sibling);
    expect(Entry::query()->sole()->title)->toBe('Welcome')->and(BlueprintSeed::query()->count())->toBe(1);
});

it('rolls its receipt entries revisions audit and first-value locks back when an audit is refused', function (): void {
    $before = AuditLog::count();
    AuditLog::creating(static function (): never {
        throw new RuntimeException('205 audit failure');
    });
    expect(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class, '205 audit failure');
    expect(BlueprintSeed::query()->count())->toBe(0)->and(Entry::count())->toBe(0)
        ->and(DB::table('entry_revisions')->count())->toBe(0)->and(AuditLog::count())->toBe($before)
        ->and(DB::table('field_storage')->where('is_locked', true)->count())->toBe(0);
});

it('refuses changed or removed recorded declarations and only adds new keys at an applied schema version', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $changed = seed205Definition([new SeedEntryDeclaration('welcome', 'seed_article', 'Changed', 'welcome')], '1.1.0');
    BlueprintApplier::apply($changed);
    expect(fn () => BlueprintSeeder::seed($changed, $this->site, 'en'))->toThrow(RuntimeException::class);
    expect(fn () => BlueprintSeeder::seed(seed205Definition([], '1.1.0'), $this->site, 'en'))->toThrow(RuntimeException::class);
    $added = seed205Definition([seed205Welcome(), new SeedEntryDeclaration('second', 'seed_article', 'Second', 'second')], '1.1.0');
    BlueprintSeeder::seed($added, $this->site, 'en');
    expect(Entry::query()->orderBy('id')->pluck('title')->all())->toBe(['Welcome', 'Second'])
        ->and(BlueprintSeed::query()->count())->toBe(1);
});

it('creates forward cyclic relations once without duplicating ordered links or revisions', function (): void {
    $definition = seed205Definition([
        new SeedEntryDeclaration('first', 'seed_article', 'First', 'first', relations: ['seed_related' => ['second']]),
        new SeedEntryDeclaration('second', 'seed_article', 'Second', 'second', relations: ['seed_related' => ['first']]),
    ]);
    BlueprintSeeder::seed($definition, $this->site, 'en');
    $before = [Entry::count(), DB::table('entry_relations')->count(), DB::table('entry_revisions')->count(), AuditLog::count()];
    expect($before[0])->toBe(2)->and($before[1])->toBe(2);
    BlueprintSeeder::seed($definition, $this->site, 'en');
    expect([Entry::count(), DB::table('entry_relations')->count(), DB::table('entry_revisions')->count(), AuditLog::count()])->toBe($before);
});

it('records skip collisions without adopting an operator entry or using it as relation authority', function (): void {
    $type = DB::table('entry_types')->where('org_id', $this->org->id)->where('handle', 'seed_article')->sole();
    $operator = Entry::create(['entry_type_id' => $type->id, 'title' => 'Operator', 'slug' => 'welcome']);
    $definition = seed205Definition([new SeedEntryDeclaration('welcome', 'seed_article', 'Welcome', 'welcome', onCollision: OnCollision::Skip)]);
    $before = AuditLog::count();
    BlueprintSeeder::seed($definition, $this->site, 'en');
    expect(Entry::count())->toBe(1)->and($operator->fresh()->title)->toBe('Operator')->and(AuditLog::count())->toBe($before);
    $next = seed205Definition([$definition->seedContent()[0], new SeedEntryDeclaration('second', 'seed_article', 'Second', 'second', relations: ['seed_related' => ['welcome']])], '1.1.0');
    BlueprintApplier::apply($next);
    expect(fn () => BlueprintSeeder::seed($next, $this->site, 'en'))->toThrow(RuntimeException::class);
    expect(Entry::count())->toBe(1)->and($operator->fresh()->title)->toBe('Operator');
});

it('names moved and retyped seed content as a reverse obstacle', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $entry = Entry::query()->sole();
    $other = EntryType::create(['org_id' => $this->org->id, 'handle' => 'other', 'name' => 'Other', 'plural_name' => 'Others']);
    $entry->update(['entry_type_id' => $other->id]);
    expect(fn () => BlueprintApplier::reverse('seed_test'))->toThrow(RuntimeException::class, 'welcome');
    expect(Blueprint::count())->toBe(1)->and(BlueprintSeed::count())->toBe(1)->and($entry->fresh()->entry_type_id)->toBe($other->id);
});

it('removes only data-free seed bookkeeping on a successful ordinary schema reverse', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    Entry::query()->sole()->forceDelete();
    BlueprintApplier::reverse('seed_test');
    expect(Blueprint::count())->toBe(0)->and(BlueprintSeed::count())->toBe(0)
        ->and(DB::table('field_storage')->where('handle', 'seed_body')->value('is_locked'))->toBeTruthy();
});

it('refuses malformed seed facts on both retry and reverse without changing content', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $receipt = BlueprintSeed::query()->sole();
    $receipt->update(['manifest' => ['format' => 1, 'entries' => [['key' => 'welcome']]]]);
    $before = [Entry::count(), AuditLog::count(), Blueprint::count(), BlueprintSeed::count()];
    expect(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class);
    expect(fn () => BlueprintApplier::reverse('seed_test'))->toThrow(RuntimeException::class);
    expect([Entry::count(), AuditLog::count(), Blueprint::count(), BlueprintSeed::count()])->toBe($before);
});

it('refuses a new relation to a trashed original without recreating it or retaining part of the batch', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $entry = Entry::query()->sole();
    $entry->delete();
    $next = seed205Definition([seed205Welcome(), new SeedEntryDeclaration('next', 'seed_article', 'Next', 'next', relations: ['seed_related' => ['welcome']])], '1.1.0');
    BlueprintApplier::apply($next);
    $before = [Entry::withTrashed()->count(), AuditLog::count(), DB::table('entry_revisions')->count(), BlueprintSeed::query()->sole()->manifest];
    expect(fn () => BlueprintSeeder::seed($next, $this->site, 'en'))->toThrow(RuntimeException::class, 'trashed');
    expect([Entry::withTrashed()->count(), AuditLog::count(), DB::table('entry_revisions')->count(), BlueprintSeed::query()->sole()->manifest])->toBe($before);
});

it('records a same-batch skip without adopting the preceding seed under another key', function (): void {
    $definition = seed205Definition([seed205Welcome(), new SeedEntryDeclaration('alias', 'seed_article', 'Alias', 'welcome', onCollision: OnCollision::Skip)]);
    $result = BlueprintSeeder::seed($definition, $this->site, 'en');
    expect(Entry::count())->toBe(1)->and($result['skipped'])->toBe(['alias'])
        ->and(BlueprintSeed::query()->sole()->manifest['entries'][1]['entry_id'])->toBeNull();
});

it('rejects invalid scalar values and unsupported PHP payloads before any batch rows survive', function (): void {
    foreach ([['seed_body' => ['wrong-shape']], ['seed_body' => new stdClass], ['not_declared' => 'no']] as $values) {
        expect(fn () => BlueprintSeeder::seed(seed205Definition([new SeedEntryDeclaration('bad', 'seed_article', 'Bad', 'bad', $values)]), $this->site, 'en'))
            ->toThrow(RuntimeException::class);
        expect(Entry::count())->toBe(0)->and(BlueprintSeed::count())->toBe(0);
    }
});

it('does not retain plaintext field values or titles in declaration facts', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $json = json_encode(BlueprintSeed::query()->sole()->manifest, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain('Original')->not->toContain('Welcome')->toContain('fingerprint');
});

it('fails closed without org context and hides seed receipts across the org boundary', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    app(Context::class)->forget();
    expect(BlueprintSeed::count())->toBe(0)
        ->and(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class);
    $foreign = Org::create(['name' => 'Foreign', 'slug' => 'foreign-seed-reader']);
    app(Context::class)->setOrg($foreign);
    expect(BlueprintSeed::count())->toBe(0)
        ->and(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class)
        ->and(app(Context::class)->orgId())->toBe($foreign->id)->and(app(Context::class)->siteId())->toBeNull();
    app(Context::class)->setSite($this->site);
    expect(Entry::count())->toBe(1)->and(BlueprintSeed::count())->toBe(1);
});

it('refuses locale drift on a seeded site even when the new explicit locale matches that site', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $this->site->update(['locale' => 'de']);
    expect(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'de'))->toThrow(RuntimeException::class, 'different site or locale');
    expect(Entry::count())->toBe(1)->and(BlueprintSeed::count())->toBe(1);
});

it('preserves original site facts after deletion and never rebinds them to a reused handle', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $originalId = $this->site->id;
    $originalManifest = BlueprintSeed::query()->sole()->manifest;
    Entry::query()->sole()->forceDelete();
    app(Context::class)->forget();
    app(Context::class)->setOrg($this->org);
    $this->site->delete();
    $replacement = Site::create(['org_id' => $this->org->id, 'name' => 'Replacement', 'slug' => 'seed-main', 'handle' => 'main', 'locale' => 'en']);
    BlueprintSeeder::seed($this->definition, $replacement, 'en');
    $old = BlueprintSeed::query()->where('original_site_id', $originalId)->sole();
    expect($replacement->id)->not->toBe($originalId)->and(BlueprintSeed::count())->toBe(2)
        ->and($old->site_id)->toBeNull()->and($old->manifest)->toBe($originalManifest)
        ->and(Entry::query()->withoutGlobalScopes()->sole()->site_id)->toBe($replacement->id);
});

it('does not replay a permanently removed original under a reused slug', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    $entry = Entry::query()->sole();
    $type = $entry->entry_type_id;
    $entry->forceDelete();
    $replacement = Entry::create(['entry_type_id' => $type, 'title' => 'Replacement', 'slug' => 'welcome']);
    $before = [AuditLog::count(), BlueprintSeed::query()->sole()->manifest];
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    expect(Entry::count())->toBe(1)->and($replacement->fresh()->title)->toBe('Replacement')
        ->and([AuditLog::count(), BlueprintSeed::query()->sole()->manifest])->toBe($before);
});

it('refuses incomplete schema versions and removed recorded field attachments before content', function (): void {
    $receipt = Blueprint::query()->sole();
    $receipt->update(['applied_at' => null]);
    expect(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class, 'completed');
    $receipt->update(['applied_at' => now()]);
    Field::query()->where('entry_type_id', DB::table('entry_types')->where('handle', 'seed_article')->value('id'))->firstOrFail()->delete();
    expect(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class, 'field attachment');
    expect(Entry::count())->toBe(0)->and(BlueprintSeed::count())->toBe(0);
});

it('refuses phantom media entries without manufacturing file custody', function (): void {
    BlueprintApplier::reverse('seed_test');
    $definition = new SeedBlueprint([new SeedEntryDeclaration('asset', 'seed_asset', 'Asset', 'asset')], types: [
        new EntryTypeDeclaration('seed_asset', 'Asset', 'Assets', isMedia: true),
    ]);
    BlueprintApplier::apply($definition);
    expect(fn () => BlueprintSeeder::seed($definition, $this->site, 'en'))->toThrow(RuntimeException::class, 'file custody');
    expect(Entry::count())->toBe(0)->and(BlueprintSeed::count())->toBe(0)->and(DB::table('media_files')->count())->toBe(0);
});

it('refuses a type with an operator-added required field it cannot populate through its receipt', function (): void {
    $type = EntryType::query()->where('handle', 'seed_article')->sole();
    $storage = FieldStorage::create(['org_id' => $this->org->id, 'handle' => 'operator_required', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1]);
    Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Required', 'is_required' => true]);
    expect(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class, 'operator-added required field');
    expect(Entry::count())->toBe(0)->and(BlueprintSeed::count())->toBe(0)->and($storage->fresh()->is_locked)->toBeFalse();
});

it('rolls the batch back when the seed receipt model vetoes its creation', function (): void {
    $before = AuditLog::count();
    BlueprintSeed::creating(static fn (): bool => false);
    expect(fn () => BlueprintSeeder::seed($this->definition, $this->site, 'en'))->toThrow(RuntimeException::class, 'vetoed');
    expect(Entry::count())->toBe(0)->and(BlueprintSeed::count())->toBe(0)->and(AuditLog::count())->toBe($before)
        ->and(DB::table('entry_revisions')->count())->toBe(0)->and(DB::table('field_storage')->where('is_locked', true)->count())->toBe(0);
});

it('keeps receipt restrictions compatible with permanently deleting its org', function (): void {
    BlueprintSeeder::seed($this->definition, $this->site, 'en');
    app(Context::class)->forget();
    $this->org->forceDelete();
    expect(Blueprint::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(BlueprintSeed::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Entry::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an empty committed manifest rather than replaying entries without slugs', function (): void {
    $definition = seed205Definition([new SeedEntryDeclaration('no_slug', 'seed_article', 'No slug')]);
    BlueprintSeeder::seed($definition, $this->site, 'en');
    BlueprintSeed::query()->sole()->update(['manifest' => ['format' => 1, 'entries' => []]]);
    $before = [Entry::count(), AuditLog::count(), DB::table('entry_revisions')->count()];
    expect(fn () => BlueprintSeeder::seed($definition, $this->site, 'en'))->toThrow(RuntimeException::class, 'Malformed');
    expect([Entry::count(), AuditLog::count(), DB::table('entry_revisions')->count()])->toBe($before);
});
