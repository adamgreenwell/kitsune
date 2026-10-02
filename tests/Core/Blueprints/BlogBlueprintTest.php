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
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Blog — the first first-party blueprint (Phase 5, ADR-039), as it ships.
 *
 * ⚠️ GOLDEN, ON PURPOSE. Once 1.0.0 is applied in a real org, every column below is what that org has, and the
 * receipt says 1.0.0 — so a change here that keeps the version is a change no applied org will ever receive. These
 * tests make that change loud: changing what Blog creates means changing its version, and that is refused until
 * ADR-039's merge exists.
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
