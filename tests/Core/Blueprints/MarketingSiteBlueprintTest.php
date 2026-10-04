<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Blueprints\FirstParty\BlogBlueprint;
use Kitsune\Core\Blueprints\FirstParty\MarketingSiteBlueprint;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\MarketingSiteAtAnotherVersion;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Marketing Site — the second first-party blueprint (Phase 5, ADR-039), as it ships, and the one ADR-030 moves
 * kitsunecms.org onto.
 *
 * ⚠️ GOLDEN, ON PURPOSE, for Blog's reason: once 1.0.0 is applied in a real org, every column below is what that org
 * has, and the receipt says 1.0.0 — so a change here that keeps the version is a change no applied org will ever
 * receive, and a new version ~~is refused until ADR-039's merge exists~~ may only add to it: the merge refuses any
 * change to what 1.0.0 recorded (`MarketingSiteUpgradeTest`).
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'mysite', 'name' => 'My site']);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(EntryType::class);
    app(Context::class)->forget();
});

/** A member of the org holding one of the Marketing Site's roles — assigned here, because a blueprint assigns nobody. */
function marketingHolder(Org $org, string $roleHandle): TestUser
{
    /** @var TestUser $user */
    $user = TestUser::create(['email' => $roleHandle.'@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $user->getKey()]);
    DB::table('role_user')->insert([
        'role_id' => Role::query()->where('handle', $roleHandle)->value('id'),
        'user_id' => $user->getKey(),
    ]);

    return $user;
}

/** @return array<string, int> the rows an apply writes, counted in every org */
function marketingCounts(): array
{
    $counts = [];

    foreach (['entry_types', 'field_storage', 'fields', 'roles', 'role_permissions', 'role_user', 'audit_log', 'blueprints'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

/** The receipt's manifest as stored, keys sorted — MySQL re-sorts a JSON object's keys, so the order says nothing. */
function marketingManifest(Org $org): array
{
    $manifest = (array) Blueprint::query()->withoutGlobalScopes()
        ->where('org_id', $org->getKey())->where('handle', 'marketing-site')->value('manifest');

    $sort = static function (array $value) use (&$sort): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $sort($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    };

    return $sort($manifest);
}

/** Core's own provider registers it beside Blog: no test, module or host does. */
it('is registered by core at 1.0.0, beside Blog', function (): void {
    $registry = app(BlueprintRegistry::class);
    $site = $registry->get('marketing-site');

    expect($site)->toBeInstanceOf(MarketingSiteBlueprint::class)
        ->and($site->version())->toBe('1.0.0')
        ->and(array_keys($registry->all()))->toContain('blog', 'marketing-site');
});

it('creates the page type exactly', function (): void {
    BlueprintApplier::apply(new MarketingSiteBlueprint);

    $type = EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'page')->firstOrFail();

    expect([...$type->only(['name', 'plural_name', 'icon', 'description']), 'ordering' => (int) $type->ordering, 'is_media' => (bool) $type->is_media])
        ->toBe([
            'name' => 'Page',
            'plural_name' => 'Pages',
            'icon' => 'heroicon-o-document',
            'description' => 'The site\'s own pages, such as its home page and its about page.',
            'ordering' => 5,
            'is_media' => false,
        ])
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->count())->toBe(1);
});

it('creates each field exactly', function (string $handle, array $storage, array $field): void {
    BlueprintApplier::apply(new MarketingSiteBlueprint);

    $row = FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', $handle)->firstOrFail();

    expect([
        'type' => $row->type,
        'pii_class' => $row->pii_class,
        'cardinality' => (int) $row->cardinality,
        'settings' => (array) $row->settings,
        'is_indexed' => (bool) $row->is_indexed,
        'is_locked' => (bool) $row->is_locked,
    ])->toBe($storage);

    $attached = Field::query()
        ->where('entry_type_id', EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'page')->value('id'))
        ->where('field_storage_id', $row->getKey())
        ->firstOrFail();

    expect([
        'label' => $attached->label,
        'help_text' => $attached->help_text,
        'is_required' => (bool) $attached->is_required,
        'ordering' => (int) $attached->ordering,
        'group' => $attached->group,
    ])->toBe($field);
})->with([
    'page_body' => ['page_body',
        ['type' => 'rich_text', 'pii_class' => 'none', 'cardinality' => 1, 'settings' => [], 'is_indexed' => false, 'is_locked' => false],
        ['label' => 'Body', 'help_text' => null, 'is_required' => false, 'ordering' => 10, 'group' => null]],
    'page_summary' => ['page_summary',
        ['type' => 'textarea', 'pii_class' => 'none', 'cardinality' => 1, 'settings' => [], 'is_indexed' => false, 'is_locked' => false],
        ['label' => 'Summary', 'help_text' => 'A sentence or two summing the page up, for wherever it is listed or linked.', 'is_required' => false, 'ordering' => 20, 'group' => null]],
]);

it('attaches no field it does not declare', function (): void {
    BlueprintApplier::apply(new MarketingSiteBlueprint);

    expect(FieldStorage::query()->where('org_id', $this->org->getKey())->orderBy('handle')->pluck('handle')->all())
        ->toBe(['page_body', 'page_summary'])
        ->and(Field::query()->count())->toBe(2);
});

/** ⚠️ Exactly these grants, and nobody an owner: a sixth action or a wildcard here is authority nobody reviewed. */
it('creates the editor and writer roles with exactly their grants', function (): void {
    $result = BlueprintApplier::apply(new MarketingSiteBlueprint);

    $grants = fn (string $handle): array => RolePermission::query()->withoutGlobalScopes()
        ->where('role_id', Role::query()->where('handle', $handle)->value('id'))
        ->orderBy('permission')->pluck('permission')->all();

    $granted = DB::table('audit_log')->where('action', 'role.granted')->get(['org_id', 'site_id']);

    expect(Role::query()->orderBy('handle')->pluck('handle')->all())->toBe(['marketing_editor', 'marketing_writer'])
        ->and(Role::query()->where('is_owner', true)->exists())->toBeFalse()
        ->and(Role::query()->where('handle', 'marketing_editor')->value('name'))->toBe('Marketing editor')
        ->and(Role::query()->where('handle', 'marketing_writer')->value('name'))->toBe('Marketing writer')
        ->and($grants('marketing_editor'))->toBe([
            'entry.page.create', 'entry.page.delete', 'entry.page.publish', 'entry.page.update', 'entry.page.view',
        ])
        ->and($grants('marketing_writer'))->toBe(['entry.page.create', 'entry.page.update', 'entry.page.view'])
        ->and($result['roles_created'])->toBe(['marketing_editor', 'marketing_writer'])
        ->and(DB::table('role_user')->count())->toBe(0)
        ->and($granted)->toHaveCount(8)
        ->and($granted->every(fn (object $row): bool => (int) $row->org_id === $this->org->getKey() && $row->site_id === null))->toBeTrue();
});

/** ⚠️ NO DDL, AND NO TYPE ON THE SUBJECT-IDENTIFIER REPORT, for Blog's reasons: rows alone, and every field `none`. */
it('issues no DDL and adds nothing to the subject-identifier report', function (): void {
    $result = BlueprintApplier::apply(new MarketingSiteBlueprint);

    expect($result['indexed'])->toBe(0)
        ->and(EntryType::withoutSubjectIdentifier()->where('handle', 'page')->exists())->toBeFalse();
});

/** Every row is the org's: a global row would be every organisation's page. */
it('writes nothing global', function (): void {
    $global = fn (): array => [
        DB::table('entry_types')->whereNull('org_id')->count(),
        DB::table('field_storage')->whereNull('org_id')->count(),
        DB::table('roles')->whereNull('org_id')->count(),
    ];

    $before = $global();

    BlueprintApplier::apply(new MarketingSiteBlueprint);

    expect($global())->toBe($before);
});

/**
 * The Marketing Site applies into an org that already has content, adopting none of it — its storage handles are
 * prefixed so a seeded org's `body` and `summary` are not taken over, and its role handles so an org's own are not.
 */
it('composes with an org that already has content', function (string $shape): void {
    $other = Org::create(['slug' => $shape, 'name' => ucfirst($shape)]);
    app(Context::class)->setOrg($other);

    $article = EntryType::create(['org_id' => $other->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    if ($shape === 'seeded') {
        foreach ([['body', 'rich_text'], ['summary', 'textarea']] as [$handle, $type]) {
            $storage = FieldStorage::create(['org_id' => $other->getKey(), 'handle' => $handle, 'type' => $type, 'cardinality' => 1, 'pii_class' => 'none']);
            Field::create(['entry_type_id' => $article->getKey(), 'field_storage_id' => $storage->getKey(), 'label' => ucfirst($handle)]);
        }

        Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
        Role::create(['handle' => 'copy-editor', 'name' => 'Copy editor'])->grant('entry.article.update');
        Role::create(['handle' => 'viewer', 'name' => 'Viewer'])->grant('entry.article.view');
    }

    $result = BlueprintApplier::apply(new MarketingSiteBlueprint);

    expect($result['adopted'])->toBe([])
        ->and($result['skipped'])->toBe([])
        ->and($result['roles_created'])->toBe(['marketing_editor', 'marketing_writer'])
        ->and(EntryType::query()->where('org_id', $other->getKey())->orderBy('handle')->pluck('handle')->all())->toBe(['article', 'page']);
})->with(['seeded', 'floor-benchmark']);

/**
 * ⚠️ BLOG AND THE MARKETING SITE IN ONE ORG, IN EITHER ORDER — kitsunecms.org's likely shape. Nothing collides, each
 * blueprint keeps its own receipt, and each blueprint's roles reach only its own types.
 */
it('composes with Blog in one org, in either order', function (string $order): void {
    $definitions = $order === 'blog-first'
        ? [new BlogBlueprint, new MarketingSiteBlueprint]
        : [new MarketingSiteBlueprint, new BlogBlueprint];

    foreach ($definitions as $definition) {
        $result = BlueprintApplier::apply($definition);

        expect($result['adopted'])->toBe([]);
    }

    expect(EntryType::query()->where('org_id', $this->org->getKey())->orderBy('handle')->pluck('handle')->all())->toBe(['page', 'post', 'tag'])
        ->and(FieldStorage::query()->where('org_id', $this->org->getKey())->count())->toBe(6)
        ->and(Role::query()->orderBy('handle')->pluck('handle')->all())->toBe(['blog_editor', 'blog_writer', 'marketing_editor', 'marketing_writer'])
        ->and(Blueprint::query()->withoutGlobalScopes()->where('org_id', $this->org->getKey())->whereNotNull('applied_at')->orderBy('handle')->pluck('handle')->all())
        ->toBe(['blog', 'marketing-site']);

    foreach (['marketing_editor' => ['post', 'tag'], 'marketing_writer' => ['post', 'tag'], 'blog_editor' => ['page'], 'blog_writer' => ['page']] as $role => $types) {
        $holder = marketingHolder($this->org, $role);

        foreach ($types as $type) {
            foreach (Permissions::ACTIONS as $action) {
                expect(Permissions::allows($holder, Permissions::forEntryType($type, $action)))->toBeFalse("{$role} {$type} {$action}");
            }
        }
    }

    foreach ($definitions as $definition) {
        expect(BlueprintApplier::apply($definition)['skipped'])->toBe(['already applied at this version']);
    }

    expect(RolePermission::query()->withoutGlobalScopes()->count())->toBe(22);
})->with(['blog-first', 'marketing-first']);

it('lets a writer draft and edit pages, and publish nothing', function (): void {
    BlueprintApplier::apply(new MarketingSiteBlueprint);
    $writer = marketingHolder($this->org, 'marketing_writer');

    foreach (['view', 'create', 'update'] as $action) {
        expect(Permissions::allows($writer, Permissions::forEntryType('page', $action)))->toBeTrue("page {$action}");
    }

    foreach (['page' => ['delete', 'publish'], 'post' => Permissions::ACTIONS, 'article' => Permissions::ACTIONS, '*' => Permissions::ACTIONS] as $type => $actions) {
        foreach ($actions as $action) {
            expect(Permissions::allows($writer, Permissions::forEntryType($type, $action)))->toBeFalse("{$type} {$action}");
        }
    }

    expect(Permissions::isOwner($writer))->toBeFalse();

    app()->instance(EntryType::class, EntryType::query()->where('handle', 'page')->firstOrFail());

    expect(array_keys(EntryResource::statusOptions($writer)))->toBe(['draft', 'archived'])
        /* A typo fixed on a published page keeps it published, as on a post. */
        ->and(array_keys(EntryResource::statusOptions($writer, 'published')))->toBe(['draft', 'published', 'archived']);
});

it('lets an editor do everything on pages, and nothing else', function (): void {
    BlueprintApplier::apply(new MarketingSiteBlueprint);
    $editor = marketingHolder($this->org, 'marketing_editor');

    foreach (Permissions::ACTIONS as $action) {
        expect(Permissions::allows($editor, Permissions::forEntryType('page', $action)))->toBeTrue("page {$action}");
    }

    expect(Permissions::allows($editor, Permissions::forEntryType('article', 'view')))->toBeFalse()
        ->and(Permissions::isOwner($editor))->toBeFalse();

    app()->instance(EntryType::class, EntryType::query()->where('handle', 'page')->firstOrFail());

    expect(array_keys(EntryResource::statusOptions($editor)))->toBe(['draft', 'published', 'archived']);
});

it('changes nothing when applied again at the same version', function (): void {
    BlueprintApplier::apply(new MarketingSiteBlueprint);

    $counts = marketingCounts();
    $manifest = marketingManifest($this->org);

    $again = BlueprintApplier::apply(new MarketingSiteBlueprint);

    expect($again['skipped'])->toBe(['already applied at this version'])
        ->and(marketingCounts())->toBe($counts)
        ->and(marketingManifest($this->org))->toBe($manifest);
});

/**
 * The operator's edits, and the lock their first page arms, survive a re-apply. ⚠️ AT THE SAME VERSION, which returns
 * before reading anything — so this pins that path; that edits survive an UPGRADE is `MarketingSiteUpgradeTest`'s.
 */
it('keeps the operator\'s edits and the lock across a re-apply', function (): void {
    BlueprintApplier::apply(new MarketingSiteBlueprint);

    $page = EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'page')->firstOrFail();
    $page->update(['name' => 'Web page']);

    $summary = FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', 'page_summary')->firstOrFail();
    Field::query()->where('field_storage_id', $summary->getKey())->firstOrFail()->update(['label' => 'Standfirst']);

    $meta = FieldStorage::create(['org_id' => $this->org->getKey(), 'handle' => 'meta_description', 'type' => 'text', 'cardinality' => 1, 'pii_class' => 'none']);
    Field::create(['entry_type_id' => $page->getKey(), 'field_storage_id' => $meta->getKey(), 'label' => 'Meta description']);

    Role::query()->where('handle', 'marketing_writer')->firstOrFail()->update(['name' => 'Page writer']);

    $site = Site::create(['org_id' => $this->org->getKey(), 'handle' => 'main', 'slug' => 'mysite-main', 'name' => 'Main']);
    app(Context::class)->setSite($site);
    Entry::create(['entry_type_id' => $page->getKey(), 'title' => 'About', 'values' => ['page_body' => '<p>Hello</p>']]);
    app(Context::class)->setOrg($this->org);

    BlueprintApplier::apply(new MarketingSiteBlueprint);

    $storage = fn (string $handle): FieldStorage => FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', $handle)->firstOrFail();

    expect($page->fresh()->name)->toBe('Web page')
        ->and(Field::query()->where('field_storage_id', $summary->getKey())->value('label'))->toBe('Standfirst')
        ->and(Role::query()->where('handle', 'marketing_writer')->value('name'))->toBe('Page writer')
        ->and((bool) $storage('page_body')->is_locked)->toBeTrue()
        ->and((bool) $storage('page_summary')->is_locked)->toBeFalse()
        ->and(FieldStorage::query()->where('org_id', $this->org->getKey())->count())->toBe(3)
        ->and(Field::query()->where('entry_type_id', $page->getKey())->count())->toBe(3);
});

/**
 * A test's 1.1.0 over 1.0.0 merges ~~is refused~~: its meta description is written, and nothing else. The upgrade's
 * own proof — the operator's edits, a locked field, the oracle — is `MarketingSiteUpgradeTest`.
 */
it('merges a test\'s 1.1.0, adding only its meta description', function (): void {
    BlueprintApplier::apply(new MarketingSiteBlueprint);

    $counts = marketingCounts();

    $result = BlueprintApplier::apply(new MarketingSiteAtAnotherVersion);

    expect($result['created'])->toBe(['field storage page_meta'])
        ->and($result['roles_created'])->toBe([])
        ->and(marketingCounts())->toBe(array_replace($counts, ['field_storage' => $counts['field_storage'] + 1, 'fields' => $counts['fields'] + 1]))
        ->and(Blueprint::query()->withoutGlobalScopes()->where('org_id', $this->org->getKey())->value('version'))->toBe('1.1.0');
});

/**
 * ⚠️ AN ORG THAT ALREADY HAS A `page`, OR ONE OF THESE ROLES, IS REFUSED BY NAME, with nothing written. `Fail`, because
 * Skip could never succeed: both roles grant on `page`, and a grant on an adopted type is refused.
 */
it('refuses what the org already has, naming it, and writes nothing', function (string $what, string $refusal): void {
    if ($what === 'type') {
        EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'page', 'name' => 'Theirs', 'plural_name' => 'Theirs']);
    } else {
        Role::create(['handle' => 'marketing_editor', 'name' => 'Theirs']);
    }

    $before = marketingCounts();

    expect(fn () => BlueprintApplier::apply(new MarketingSiteBlueprint))->toThrow(RuntimeException::class, $refusal);

    $after = marketingCounts();

    expect(array_diff_key($after, ['blueprints' => 0]))->toBe(array_diff_key($before, ['blueprints' => 0]))
        ->and(Blueprint::query()->withoutGlobalScopes()->where('org_id', $this->org->getKey())->value('manifest'))->toBeNull();
})->with([
    'a page type' => ['type', 'Entry type [page] already exists in this organisation, and this blueprint declares it with onCollision: fail'],
    'a marketing_editor role' => ['role', 'Role [marketing_editor] already exists in this organisation, and this blueprint declares it with onCollision: fail'],
]);

/** A global `page` would stand in this blueprint's place in every org: refused whatever the declared policy says. */
it('refuses a global page type, and writes nothing', function (): void {
    EntryType::create(['org_id' => null, 'handle' => 'page', 'name' => 'Global page', 'plural_name' => 'Global pages']);

    $before = marketingCounts();

    expect(fn () => BlueprintApplier::apply(new MarketingSiteBlueprint))
        ->toThrow(RuntimeException::class, 'Entry type [page] is a global type');

    expect(array_diff_key(marketingCounts(), ['blueprints' => 0]))->toBe(array_diff_key($before, ['blueprints' => 0]));
});

/** What 1.0.0 leaves out reaches an org through the admin, whose schema is its owner's — this org's, and no other's. */
it('leaves the page type to its org\'s owner to extend', function (): void {
    BlueprintApplier::apply(new MarketingSiteBlueprint);

    $page = EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'page')->firstOrFail();

    expect(EntryTypeResource::ownsRecord($page))->toBeTrue();

    app(Context::class)->setOrg(Org::create(['slug' => 'elsewhere', 'name' => 'Elsewhere']));

    expect(EntryTypeResource::ownsRecord($page))->toBeFalse();
});
