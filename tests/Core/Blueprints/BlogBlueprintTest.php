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
use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\FirstParty\BlogBlueprint;
use Kitsune\Core\Blueprints\FirstParty\MarketingSiteBlueprint;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
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
use Kitsune\Core\Tests\Fixtures\BlogAtAnotherVersion;
use Kitsune\Core\Tests\Fixtures\Released\Blog100;
use Kitsune\Core\Tests\Fixtures\Released\MarketingSite100;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Blog — the first first-party blueprint (Phase 5, ADR-039), as it ships.
 *
 * ⚠️ GOLDEN, ON PURPOSE. Once 1.0.0 is applied in a real org, every column below is what that org has, and the
 * receipt says 1.0.0 — so a change here that keeps the version is a change no applied org will ever receive. These
 * tests make that change loud: changing what Blog creates means changing its version, and ~~that is refused until
 * ADR-039's merge exists~~ a new version may only add — the merge refuses any change to what 1.0.0 recorded, and
 * `ReleasedBlueprintsTest` merges the shipped class over the frozen 1.0.0 to prove it does not.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'myblog', 'name' => 'My blog']);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(EntryType::class);
    app(Context::class)->forget();
});

/** A member of the org holding one of Blog's roles — assigned here, because the blueprint assigns nobody. */
function blogHolder(Org $org, string $roleHandle): TestUser
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

/** Core's own provider registers it: no test, module or host does. */
it('is registered by core at 1.0.0', function (): void {
    $blog = app(BlueprintRegistry::class)->get('blog');

    expect($blog)->toBeInstanceOf(BlogBlueprint::class)
        ->and($blog->version())->toBe('1.0.0');
});

it('creates the post and tag types exactly', function (string $handle, array $expected): void {
    BlueprintApplier::apply(new BlogBlueprint);

    $type = EntryType::query()->where('org_id', $this->org->getKey())->where('handle', $handle)->firstOrFail();

    expect([...$type->only(['name', 'plural_name', 'icon', 'description']), 'ordering' => (int) $type->ordering, 'is_media' => $type->is_media])
        ->toBe($expected)
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->count())->toBe(2);
})->with([
    'post' => ['post', ['name' => 'Post', 'plural_name' => 'Posts', 'icon' => 'heroicon-o-pencil-square', 'description' => 'Posts published on the blog.', 'ordering' => 10, 'is_media' => false]],
    'tag' => ['tag', ['name' => 'Tag', 'plural_name' => 'Tags', 'icon' => 'heroicon-o-tag', 'description' => 'Topics a post can carry.', 'ordering' => 11, 'is_media' => false]],
]);

it('creates each field exactly', function (string $type, string $handle, array $storage, array $field): void {
    BlueprintApplier::apply(new BlogBlueprint);

    $row = FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', $handle)->firstOrFail();

    expect([
        'type' => $row->type,
        'pii_class' => $row->pii_class,
        'cardinality' => (int) $row->cardinality,
        'settings' => (array) $row->settings,
        'is_indexed' => (bool) $row->is_indexed,
    ])->toBe($storage);

    $attached = Field::query()
        ->where('entry_type_id', EntryType::query()->where('org_id', $this->org->getKey())->where('handle', $type)->value('id'))
        ->where('field_storage_id', $row->getKey())
        ->firstOrFail();

    expect([
        'label' => $attached->label,
        'is_required' => (bool) $attached->is_required,
        'ordering' => (int) $attached->ordering,
        'help_text' => $attached->help_text,
    ])->toBe($field);
})->with([
    'post_body' => ['post', 'post_body',
        ['type' => 'rich_text', 'pii_class' => 'none', 'cardinality' => 1, 'settings' => [], 'is_indexed' => false],
        ['label' => 'Body', 'is_required' => false, 'ordering' => 10, 'help_text' => null]],
    'post_excerpt' => ['post', 'post_excerpt',
        ['type' => 'textarea', 'pii_class' => 'none', 'cardinality' => 1, 'settings' => [], 'is_indexed' => false],
        ['label' => 'Excerpt', 'is_required' => false, 'ordering' => 20, 'help_text' => 'A sentence or two summing the post up, for wherever it is listed.']],
    'post_tags' => ['post', 'post_tags',
        ['type' => 'relation', 'pii_class' => 'none', 'cardinality' => -1, 'settings' => ['targetTypes' => ['tag']], 'is_indexed' => false],
        ['label' => 'Tags', 'is_required' => false, 'ordering' => 30, 'help_text' => 'Choose from the tags under Tags.']],
    'tag_description' => ['tag', 'tag_description',
        ['type' => 'textarea', 'pii_class' => 'none', 'cardinality' => 1, 'settings' => [], 'is_indexed' => false],
        ['label' => 'Description', 'is_required' => false, 'ordering' => 10, 'help_text' => 'What the posts carrying this tag are about.']],
]);

it('attaches no field it does not declare', function (): void {
    BlueprintApplier::apply(new BlogBlueprint);

    expect(FieldStorage::query()->where('org_id', $this->org->getKey())->orderBy('handle')->pluck('handle')->all())
        ->toBe(['post_body', 'post_excerpt', 'post_tags', 'tag_description'])
        ->and(Field::query()->count())->toBe(4);
});

/** ⚠️ Exactly these grants, and nobody an owner: a sixth action or a wildcard here is authority nobody reviewed. */
it('creates the editor and writer roles with exactly their grants', function (): void {
    $result = BlueprintApplier::apply(new BlogBlueprint);

    $grants = fn (string $handle): array => RolePermission::query()->withoutGlobalScopes()
        ->where('role_id', Role::query()->where('handle', $handle)->value('id'))
        ->orderBy('permission')->pluck('permission')->all();

    expect(Role::query()->orderBy('handle')->pluck('handle')->all())->toBe(['blog_editor', 'blog_writer'])
        ->and(Role::query()->where('is_owner', true)->exists())->toBeFalse()
        ->and(Role::query()->where('handle', 'blog_editor')->value('name'))->toBe('Blog editor')
        ->and(Role::query()->where('handle', 'blog_writer')->value('name'))->toBe('Blog writer')
        ->and($grants('blog_editor'))->toBe([
            'entry.post.create', 'entry.post.delete', 'entry.post.publish', 'entry.post.update', 'entry.post.view',
            'entry.tag.create', 'entry.tag.delete', 'entry.tag.publish', 'entry.tag.update', 'entry.tag.view',
        ])
        ->and($grants('blog_writer'))->toBe(['entry.post.create', 'entry.post.update', 'entry.post.view', 'entry.tag.view'])
        ->and($result['roles_created'])->toBe(['blog_editor', 'blog_writer'])
        ->and(DB::table('role_user')->count())->toBe(0);
});

/**
 * ⚠️ NO DDL, AND NO TYPE ON THE SUBJECT-IDENTIFIER REPORT. Blog indexes nothing, so its apply is rows alone; and every
 * field is `none`, so no Blog type is a hole a subject-access request falls through. If Blog's classification
 * changes to `personal`, this flips on purpose.
 */
it('issues no DDL and adds nothing to the subject-identifier report', function (): void {
    $result = BlueprintApplier::apply(new BlogBlueprint);

    expect($result['indexed'])->toBe(0)
        ->and(EntryType::withoutSubjectIdentifier()->whereIn('handle', ['post', 'tag'])->exists())->toBeFalse();
});

/**
 * Blog applies into an org that already has content, adopting none of it — its storage handles are prefixed so a
 * seeded org's `body` and `summary` are not taken over, and its role handles so an org's own `editor` is not.
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

    $result = BlueprintApplier::apply(new BlogBlueprint);

    expect($result['adopted'])->toBe([])
        ->and($result['skipped'])->toBe([])
        ->and($result['roles_created'])->toBe(['blog_editor', 'blog_writer'])
        ->and(EntryType::query()->where('org_id', $other->getKey())->orderBy('handle')->pluck('handle')->all())->toBe(['article', 'post', 'tag']);
})->with(['seeded', 'floor-benchmark']);

it('lets a writer draft and edit posts, and publish nothing', function (): void {
    BlueprintApplier::apply(new BlogBlueprint);
    $writer = blogHolder($this->org, 'blog_writer');

    foreach (['view', 'create', 'update'] as $action) {
        expect(Permissions::allows($writer, Permissions::forEntryType('post', $action)))->toBeTrue("post {$action}");
    }

    foreach (['post' => ['delete', 'publish'], 'tag' => ['create', 'update', 'delete', 'publish'], 'article' => Permissions::ACTIONS, '*' => Permissions::ACTIONS] as $type => $actions) {
        foreach ($actions as $action) {
            expect(Permissions::allows($writer, Permissions::forEntryType($type, $action)))->toBeFalse("{$type} {$action}");
        }
    }

    expect(Permissions::allows($writer, Permissions::forEntryType('tag', 'view')))->toBeTrue()
        ->and(Permissions::isOwner($writer))->toBeFalse();

    app()->instance(EntryType::class, EntryType::query()->where('handle', 'post')->firstOrFail());

    expect(array_keys(EntryResource::statusOptions($writer)))->toBe(['draft', 'archived'])
        /* A typo fix on a published post keeps it published — the concession ADR-033's review found owed. */
        ->and(array_keys(EntryResource::statusOptions($writer, 'published')))->toBe(['draft', 'published', 'archived']);
});

it('lets an editor do everything on posts and tags, and nothing else', function (): void {
    BlueprintApplier::apply(new BlogBlueprint);
    $editor = blogHolder($this->org, 'blog_editor');

    foreach (['post', 'tag'] as $type) {
        foreach (Permissions::ACTIONS as $action) {
            expect(Permissions::allows($editor, Permissions::forEntryType($type, $action)))->toBeTrue("{$type} {$action}");
        }
    }

    expect(Permissions::allows($editor, Permissions::forEntryType('article', 'view')))->toBeFalse()
        ->and(Permissions::isOwner($editor))->toBeFalse();

    app()->instance(EntryType::class, EntryType::query()->where('handle', 'post')->firstOrFail());

    expect(array_keys(EntryResource::statusOptions($editor)))->toBe(['draft', 'published', 'archived']);
});

/** A post's tags are chosen from tags, and from nothing else. */
it('points the tags relation at tags alone', function (): void {
    BlueprintApplier::apply(new BlogBlueprint);

    expect(FieldStorage::query()->where('org_id', $this->org->getKey())->where('handle', 'post_tags')->firstOrFail()->settings)
        ->toBe(['targetTypes' => ['tag']]);
});

/** A post carrying a tag, related as the admin relates one — which locks `post_tags`. */
function blogTaggedPost(Org $org): void
{
    $type = static fn (string $handle): int => (int) EntryType::query()->where('org_id', $org->getKey())->where('handle', $handle)->value('id');
    $site = Site::create(['org_id' => $org->getKey(), 'handle' => 'main', 'slug' => $org->slug.'-main', 'name' => 'Main']);
    app(Context::class)->setSite($site);

    $tag = Entry::create(['entry_type_id' => $type('tag'), 'title' => 'News', 'values' => []]);
    $post = Entry::create(['entry_type_id' => $type('post'), 'title' => 'Hello', 'values' => []]);
    $post->related()->attach($tag->getKey(), [
        'field_storage_id' => FieldStorage::query()->where('org_id', $org->getKey())->where('handle', 'post_tags')->value('id'),
    ]);

    app(Context::class)->setOrg($org);
}

/** What one blueprint wrote into the org: its receipt, as stored, and its roles with their grants and holders. */
function blogWrittenBy(string $handle, array $roles): array
{
    $ids = Role::query()->whereIn('handle', $roles)->pluck('id')->all();

    return [
        DB::table('blueprints')->where('handle', $handle)->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('roles')->whereIn('id', $ids)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('role_permissions')->whereIn('role_id', $ids)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        DB::table('role_user')->whereIn('role_id', $ids)->orderBy('user_id')->get()->map(static fn (object $row): array => (array) $row)->all(),
    ];
}

/**
 * ⚠️ EVERY KIND OF ADDITION, ON A REAL BLUEPRINT, PAST DATA. A test's 1.1.0 adds a type, a relation on `post` that
 * targets it, and a role granting on both — over an org whose posts already carry tags, so `post_tags` is locked and
 * both 1.0.0 roles have holders. None of that is touched.
 */
it('merges a test\'s 1.1.0 over 1.0.0, past a tagged post', function (): void {
    BlueprintApplier::apply(new Blog100);
    blogHolder($this->org, 'blog_editor');
    blogHolder($this->org, 'blog_writer');
    blogTaggedPost($this->org);
    $before = blogWrittenBy('blog', ['blog_editor', 'blog_writer']);

    $result = BlueprintApplier::apply(new BlogAtAnotherVersion);
    $orgId = $this->org->getKey();
    $series = EntryType::query()->where('org_id', $orgId)->where('handle', 'series')->firstOrFail();
    $post = EntryType::query()->where('org_id', $orgId)->where('handle', 'post')->firstOrFail();
    $postSeries = FieldStorage::query()->where('org_id', $orgId)->where('handle', 'post_series')->firstOrFail();
    $seriesEditor = Role::query()->where('handle', 'blog_series_editor')->firstOrFail();

    expect($result['roles_created'])->toBe(['blog_series_editor'])
        ->and($result['created'])->toBe([
            'entry type series',
            'field storage series_description',
            'field storage post_series',
            'role blog_series_editor: entry.post.view, entry.series.create, entry.series.delete, entry.series.publish, entry.series.update, entry.series.view',
        ])
        ->and($series->only(['name', 'plural_name']))->toBe(['name' => 'Series', 'plural_name' => 'Series'])
        ->and($postSeries->only(['type', 'cardinality', 'settings']))->toBe(['type' => 'relation', 'cardinality' => 1, 'settings' => ['targetTypes' => ['series']]])
        ->and(Field::query()->where('entry_type_id', $post->getKey())->where('field_storage_id', $postSeries->getKey())->value('label'))->toBe('Series')
        ->and(RolePermission::query()->withoutGlobalScopes()->where('role_id', $seriesEditor->getKey())->count())->toBe(6)
        ->and(DB::table('role_user')->where('role_id', $seriesEditor->getKey())->exists())->toBeFalse()
        ->and((bool) FieldStorage::query()->where('org_id', $orgId)->where('handle', 'post_tags')->value('is_locked'))->toBeTrue()
        ->and(array_slice(blogWrittenBy('blog', ['blog_editor', 'blog_writer']), 1))->toBe(array_slice($before, 1))
        ->and(Blueprint::receiptFor('blog')->version)->toBe('1.1.0');
});

/** Two blueprints in one org keep separate receipts, and a merge reads and writes only its own. */
it('merges one blueprint without touching the other\'s rows, receipt or roles', function (): void {
    BlueprintApplier::apply(new Blog100);
    BlueprintApplier::apply(new MarketingSite100);
    blogHolder($this->org, 'blog_writer');
    $blog = blogWrittenBy('blog', ['blog_editor', 'blog_writer']);
    $blogFields = Field::query()->whereIn('entry_type_id', EntryType::query()->where('org_id', $this->org->getKey())->whereIn('handle', ['post', 'tag'])->pluck('id'))->orderBy('id')->get()->toArray();

    BlueprintApplier::apply(new MarketingSiteBlueprint);

    expect(blogWrittenBy('blog', ['blog_editor', 'blog_writer']))->toBe($blog)
        ->and(Field::query()->whereIn('entry_type_id', EntryType::query()->where('org_id', $this->org->getKey())->whereIn('handle', ['post', 'tag'])->pluck('id'))->orderBy('id')->get()->toArray())->toBe($blogFields);

    $marketing = blogWrittenBy('marketing-site', ['marketing_editor', 'marketing_writer']);

    BlueprintApplier::apply(new BlogAtAnotherVersion);

    expect(blogWrittenBy('marketing-site', ['marketing_editor', 'marketing_writer']))->toBe($marketing)
        ->and(Blueprint::receiptFor('blog')->version)->toBe('1.1.0')
        ->and(Blueprint::receiptFor('marketing-site')->version)->toBe('1.1.0');
});

/** ⚠️ A RELATION LOCKS TOO: tag rows hold data for `post_tags`, so narrowing it to one tag is named as the lock it is. */
it('names the lock relation rows arm when a version reshapes the field', function (): void {
    BlueprintApplier::apply(new Blog100);
    blogTaggedPost($this->org);

    $narrowed = new class implements BlueprintDefinition
    {
        public function handle(): string
        {
            return 'blog';
        }

        public function version(): string
        {
            return '1.1.0';
        }

        public function entryTypes(): array
        {
            return array_map(static fn (EntryTypeDeclaration $type): EntryTypeDeclaration => new EntryTypeDeclaration(
                handle: $type->handle,
                name: $type->name,
                pluralName: $type->pluralName,
                fields: array_map(static fn (FieldDeclaration $field): FieldDeclaration => $field->handle !== 'post_tags' ? $field : new FieldDeclaration(
                    handle: $field->handle,
                    type: $field->type,
                    label: $field->label,
                    piiClass: $field->piiClass,
                    cardinality: 1,
                    settings: $field->settings,
                    helpText: $field->helpText,
                    ordering: $field->ordering,
                ), $type->fields),
                icon: $type->icon,
                description: $type->description,
                ordering: $type->ordering,
            ), (new Blog100)->entryTypes());
        }

        public function roles(): array
        {
            return (new Blog100)->roles();
        }
    };

    expect(fn () => BlueprintApplier::apply($narrowed))->toThrow(RuntimeException::class,
        'field post_tags on post changes its cardinality — and post_tags is locked because entries hold data for it: '
        .'create a new field, migrate the data, verify, then remove the old one (ADR-006). Nothing was written, and the '
        .'receipt still says 1.0.0.'
    );
});
