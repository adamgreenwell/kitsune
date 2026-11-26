<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\RegistersOrgAwareProvider;
use Kitsune\Core\Http\Api\SiteAccessToken;
use Kitsune\Core\Http\Api\SiteApiRoutes;
use Kitsune\Core\Http\Api\SiteTokens;
use Kitsune\Core\Http\Api\SiteTokenSchema;
use Kitsune\Core\Http\Middleware\ResolveSiteFromRequest;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaFormats;
use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryTranslationGroup;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

#[OrgScopedThroughPivot(table: 'org_user', foreignKey: 'user_id')]
class SiteTokenGuardStaff extends TestUser implements HasTenants
{
    public bool $implicitAdmission = false;

    public function getTenants(Panel $panel): array|Collection
    {
        return [];
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->implicitAdmission
            || DB::table('site_user')->where('site_id', $tenant->getKey())->where('user_id', $this->getKey())->exists();
    }
}

beforeEach(function (): void {
    // The runtime suite creates the opt-in migration within the fixture transaction. Non-transactional
    // DDL engines are qualified separately, so their default application schema stays unchanged here.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Runs an opt-in migration inside the SQLite fixture transaction.');
    }

    config([
        'kitsune.api.staff_provider' => 'api-fixture-staff',
        'auth.providers.api-fixture-staff' => ['driver' => 'eloquent', 'model' => SiteTokenGuardStaff::class],
    ]);
    RegistersOrgAwareProvider::on(app(), 'api-fixture-staff');
    SiteTokenSchema::create(SiteTokenGuardStaff::class);
    SiteTokens::register();
    SiteApiRoutes::register();

    $this->org = Org::create(['slug' => 'api', 'name' => 'API']);
    $this->otherOrg = Org::create(['slug' => 'other-api', 'name' => 'Other API']);
    $makeSite = static function (Org $org, string $slug): Site {
        app(Context::class)->setOrg($org);

        return Site::create([
            'handle' => $slug, 'slug' => $slug, 'name' => $slug,
            'url_strategy' => 'domain', 'base_url' => "https://{$slug}.example.test",
        ]);
    };
    $this->site = $makeSite($this->org, 'api');
    $sibling = $this->sibling = $makeSite($this->org, 'sibling');
    $foreign = $this->foreign = $makeSite($this->otherOrg, 'foreign');
    app(Context::class)->setSite($this->site);

    $this->actor = SiteTokenGuardStaff::create(['email' => 'actor@example.test']);
    foreach ([$this->org, $this->otherOrg] as $org) {
        DB::table('org_user')->insert(['org_id' => $org->getKey(), 'user_id' => $this->actor->getKey()]);
    }
    foreach ([$this->site, $sibling, $foreign] as $site) {
        DB::table('site_user')->insert(['site_id' => $site->getKey(), 'user_id' => $this->actor->getKey()]);
    }

    $this->entryType = EntryType::create(['handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
    $this->role = Role::create(['handle' => 'api-editor', 'name' => 'API editor']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $this->actor->getKey()]);
    $this->role->grant('entry.article.view');
    $this->role->grant('entry.article.update');

    $handler = static function (Request $request, string $action) {
        abort_unless(SiteTokens::allows($request, 'article', $action), 403);

        return response()->json(['site' => app(Context::class)->site()?->slug]);
    };
    Route::get('/api-test/{action}', $handler)->middleware(['auth:'.SiteTokens::GUARD, ResolveSiteFromRequest::class]);
    Route::get('/lazy-api/{action}', $handler)->middleware(ResolveSiteFromRequest::class);
    Route::get('/missing-context/{action}', $handler)->middleware('auth:'.SiteTokens::GUARD);

    $this->read = function (string $credential, string $host = 'api', string $path = '/api-test/view') {
        app()->forgetScopedInstances();
        Permissions::forget();
        Auth::forgetGuards();
        Auth::shouldUse('web');

        return $this->getJson("https://{$host}.example.test{$path}", ['Authorization' => 'Bearer '.$credential]);
    };
    $this->write = function (string $method, string $credential, string $path, array $data, string $host = 'api') {
        app()->forgetScopedInstances();
        Permissions::forget();
        Auth::forgetGuards();
        Auth::shouldUse('web');

        return $this->json($method, "https://{$host}.example.test{$path}", $data, ['Authorization' => 'Bearer '.$credential]);
    };

});

it('keeps a token bound to its Site and current actor permissions', function (): void {
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    $token = SiteAccessToken::query()->sole();
    expect($token->token)->toBe(hash('sha256', explode('|', $credential)[1]))
        ->and($token->toArray())->not->toHaveKey('token');

    ($this->read)($credential)->assertOk()->assertExactJson(['site' => 'api']);
    ($this->read)($credential, path: '/api-test/update')->assertForbidden();
    ($this->read)($credential, 'sibling')->assertUnauthorized();
    ($this->read)($credential, 'foreign')->assertUnauthorized();
    ($this->read)($credential, 'unmatched')->assertUnauthorized();
    ($this->read)($credential, path: '/missing-context/view')->assertUnauthorized();

    app(Context::class)->setSite($this->site);
    $this->role->revoke('entry.article.view');
    ($this->read)($credential)->assertForbidden();
});

it('refuses session authentication and rechecks expiry and revocation', function (): void {
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    $token = SiteAccessToken::query()->sole();
    $this->actingAs($this->actor, 'web');
    app(Context::class)->forget();
    $this->getJson('https://api.example.test/api-test/view')->assertUnauthorized();

    app(Context::class)->setSite($this->site);
    $token->forceFill(['expires_at' => now()->subHour()])->save();
    ($this->read)($credential)->assertUnauthorized();
    $token->forceFill(['expires_at' => now()->addHour()])->save();

    ($this->read)($credential)->assertOk();
    expect(SiteTokens::revoke($token->id))->toBeTrue();
    ($this->read)($credential)->assertUnauthorized();
});

it('refuses to issue missing grants and wildcard permissions', function (): void {
    expect(fn () => SiteTokens::issue($this->actor, 'test client', ['entry.article.delete'], now()->addHour()))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => SiteTokens::issue($this->actor, 'test client', ['entry.*.view'], now()->addHour()))
        ->toThrow(InvalidArgumentException::class)
        ->and(SiteAccessToken::query()->count())->toBe(0);
});

it('refuses a previously issued ability while its entry type is disabled on the Site', function (): void {
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    ($this->read)($credential)->assertOk();

    EntryTypeAvailability::create([
        'entry_type_id' => $this->entryType->getKey(),
        'scope_type' => 'site',
        'scope_id' => $this->site->getKey(),
        'is_enabled' => false,
    ]);
    ($this->read)($credential)->assertForbidden();
});

it('issues a credential once through Artisan and revokes only inside the selected Site', function (): void {
    $status = Artisan::call('kitsune:api-token', [
        'action' => 'issue',
        '--org' => 'api',
        '--site' => 'api',
        '--actor' => (string) $this->actor->getKey(),
        '--name' => 'test client',
        '--ability' => ['entry.article.view'],
    ]);
    expect($status)->toBe(0);
    $credential = trim(Artisan::output());
    if (preg_match('/\A[1-9][0-9]*\|[a-f0-9]{64}\z/', $credential) !== 1) {
        $this->fail('The command did not emit exactly one token.');
    }
    $token = SiteAccessToken::query()->sole();
    ($this->read)($credential)->assertOk();

    $command = ['action' => 'revoke', '--org' => 'api', '--site' => 'sibling', '--id' => (string) $token->id];
    expect(Artisan::call('kitsune:api-token', $command))->toBe(1);
    ($this->read)($credential)->assertOk();

    $command['--site'] = 'api';
    expect(Artisan::call('kitsune:api-token', $command))->toBe(0);
    ($this->read)($credential)->assertUnauthorized();
});

it('resolves the token before checking authorization through the request guard', function (): void {
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    ($this->read)($credential, path: '/lazy-api/view')->assertOk();
});

it('refuses issuance without a persisted Site membership even when host admission says yes', function (): void {
    $this->actor->implicitAdmission = true;
    DB::table('site_user')->where('site_id', $this->site->getKey())->where('user_id', $this->actor->getKey())->delete();

    expect(fn () => SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour()))
        ->toThrow(InvalidArgumentException::class, 'selected Site and Org')
        ->and(SiteAccessToken::query()->count())->toBe(0);
});

it('restores the prior context after a command succeeds or refuses', function (): void {
    $context = app(Context::class)->setSite($this->sibling);
    $issue = [
        'action' => 'issue',
        '--org' => 'api',
        '--site' => 'api',
        '--actor' => (string) $this->actor->getKey(),
        '--name' => 'test client',
        '--ability' => ['entry.article.view'],
    ];

    expect(Artisan::call('kitsune:api-token', $issue))->toBe(0)
        ->and($context->siteId())->toBe($this->sibling->getKey());
    $issue['--site'] = 'missing';
    expect(Artisan::call('kitsune:api-token', $issue))->toBe(1)
        ->and($context->siteId())->toBe($this->sibling->getKey());
});

it('discovers authorized Site schemas outside the session-backed routes', function (): void {
    EntryType::create(['handle' => 'aaa-hidden', 'name' => 'Other', 'plural_name' => 'Other']);
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());

    ($this->read)($credential, path: '/api/v1/schema')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.handle', 'article')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeaderMissing('Set-Cookie');
    ($this->read)($credential, 'sibling', '/api/v1/schema')->assertUnauthorized();
    ($this->read)($credential, 'foreign', '/api/v1/schema')->assertUnauthorized();
    ($this->read)($credential, 'api', '/not-the-site/api/v1/schema')->assertNotFound();
});

it('requires a prefixed Site schema to use that Site’s complete canonical path', function (): void {
    $site = Site::create([
        'handle' => 'brand-edition', 'slug' => 'brand-edition', 'name' => 'Brand edition',
        'url_strategy' => 'path', 'base_url' => 'https://api.example.test/brand/edition',
    ]);
    DB::table('site_user')->insert(['site_id' => $site->getKey(), 'user_id' => $this->actor->getKey()]);
    app(Context::class)->setSite($site);
    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    $entry = Entry::create(['entry_type_id' => $this->entryType->getKey(), 'title' => 'Nested Site article']);

    ($this->read)($credential, path: '/brand/edition/api/v1/schema')->assertOk()->assertJsonPath('data.0.handle', 'article');
    ($this->read)($credential, path: '/brand/edition/api/v1/entries/article/'.$entry->getKey())->assertOk()
        ->assertJsonPath('data.title', 'Nested Site article');
    ($this->read)($credential, path: '/api/v1/schema')->assertUnauthorized();
});

it('paginates scoped entry reads by ID and returns only the selected type’s declared fields', function (): void {
    $storage = FieldStorage::create([
        'handle' => 'synopsis', 'type' => 'text', 'cardinality' => 1, 'pii_class' => 'none',
    ]);
    $this->entryType->fields()->create(['field_storage_id' => $storage->getKey(), 'label' => 'Synopsis']);
    $first = Entry::create(['entry_type_id' => $this->entryType->getKey(), 'title' => 'First', 'values' => ['synopsis' => 'Visible']]);
    $second = Entry::create(['entry_type_id' => $this->entryType->getKey(), 'title' => 'Second']);
    app(Context::class)->setSite($this->sibling);
    $sibling = Entry::create(['entry_type_id' => $this->entryType->getKey(), 'title' => 'Sibling']);
    app(Context::class)->setSite($this->foreign);
    $foreign = Entry::create(['entry_type_id' => $this->entryType->getKey(), 'title' => 'Foreign']);
    app(Context::class)->setSite($this->site);

    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    ($this->read)($credential, path: '/api/v1/entries/article?limit=1')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $first->getKey())
        ->assertJsonPath('data.0.values.synopsis', 'Visible')
        ->assertJsonMissingPath('data.0.org_id')
        ->assertJsonPath('pagination.next_after', $first->getKey());
    ($this->read)($credential, path: '/api/v1/entries/article?limit=1&after='.$first->getKey())->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $second->getKey())
        ->assertJsonPath('pagination.next_after', null);
    ($this->read)($credential, path: '/api/v1/entries/article/'.$first->getKey())->assertOk()
        ->assertJsonPath('data.values.synopsis', 'Visible');
    ($this->read)($credential, path: '/api/v1/entries/article/'.$sibling->getKey())->assertNotFound();
    ($this->read)($credential, path: '/api/v1/entries/article/'.$foreign->getKey())->assertNotFound();
    ($this->read)($credential, path: '/api/v1/entries/credentials')->assertNotFound();
    ($this->read)($credential, path: '/api/v1/entries/entitlements')->assertNotFound();
    ($this->read)($credential, path: '/api/v1/entries/article?limit=101')->assertUnprocessable();
});

it('keeps pinned relation order and omits targets outside the token’s readable entry types', function (): void {
    $storage = FieldStorage::create([
        'handle' => 'related_items', 'type' => 'relation', 'cardinality' => 4, 'pii_class' => 'none',
    ]);
    $this->entryType->fields()->create(['field_storage_id' => $storage->getKey(), 'label' => 'Related items']);
    $unreadableType = EntryType::create(['handle' => 'private-notes', 'name' => 'Note', 'plural_name' => 'Notes']);
    $article = fn (string $name): Entry => Entry::create(['entry_type_id' => $this->entryType->getKey(), 'title' => $name]);
    $source = $article('Source');
    $first = $article('First');
    $last = $article('Last');
    $note = Entry::create(['entry_type_id' => $unreadableType->getKey(), 'title' => 'Unshared note']);
    $source->related()->attach([
        $last->getKey() => ['field_storage_id' => $storage->getKey(), 'ordering' => 20],
        $note->getKey() => ['field_storage_id' => $storage->getKey(), 'ordering' => 10],
        $first->getKey() => ['field_storage_id' => $storage->getKey(), 'ordering' => 0],
    ]);

    $credential = SiteTokens::issue($this->actor, 'test client', ['entry.article.view'], now()->addHour());
    ($this->read)($credential, path: '/api/v1/entries/article/'.$source->getKey())->assertOk()
        ->assertJsonPath('data.values.related_items', [$first->getKey(), $last->getKey()]);
    ($this->read)($credential, path: '/api/v1/entries/article')->assertOk()
        ->assertJsonPath('data.0.values.related_items', [$first->getKey(), $last->getKey()]);
});

it('serves a tagged group link alongside a legacy pin and resolves the group on the token’s Site', function (): void {
    $storage = FieldStorage::create([
        'handle' => 'related_items', 'type' => 'relation', 'cardinality' => 4, 'pii_class' => 'none',
    ]);
    $this->entryType->fields()->create(['field_storage_id' => $storage->id, 'label' => 'Related items']);

    $uuid = 'a5773ed2-5df9-4ffe-92d8-cf2f51de66f2';
    $source = Entry::create(['entry_type_id' => $this->entryType->id, 'site_id' => null, 'title' => 'Source']);
    $first = Entry::create(['entry_type_id' => $this->entryType->id, 'translation_group' => $uuid, 'title' => 'First']);
    $group = EntryTranslationGroup::query()->sole();
    $source->syncFieldRelations($storage, [$first->id]);
    EntryRelation::create([
        'org_id' => $this->org->id, 'source_entry_id' => $source->id, 'field_storage_id' => $storage->id,
        'target_group_id' => $group->id, 'ordering' => 1,
    ]);

    $firstToken = SiteTokens::issue($this->actor, 'first site client', ['entry.article.view'], now()->addHour());
    app(Context::class)->setSite($this->sibling);
    $sibling = Entry::create(['entry_type_id' => $this->entryType->id, 'translation_group' => $uuid, 'title' => 'Sibling']);
    $siblingToken = SiteTokens::issue($this->actor, 'second site client', ['entry.article.view'], now()->addHour());

    ($this->read)($firstToken, path: '/api/v1/entries/article')->assertOk()
        ->assertJsonPath('data.0.values.related_items', [$first->id, ['group' => $uuid, 'entry' => $first->id]]);
    ($this->read)($siblingToken, 'sibling', '/api/v1/entries/article/'.$source->id)->assertOk()
        ->assertJsonPath('data.values.related_items', [['group' => $uuid, 'entry' => $sibling->id]]);

    ($this->read)($firstToken, path: '/api/v1/schema')->assertOk()
        ->assertJsonPath('data.0.fields.properties.related_items.maxItems', 4)
        ->assertJsonPath('data.0.fields.properties.related_items.items.oneOf.0.type', 'integer')
        ->assertJsonPath('data.0.fields.properties.related_items.items.oneOf.1.properties.group.format', 'uuid');
});

it('validates generated create and update writes and reconciles relations in one revision', function (): void {
    $this->role->grant('entry.article.create');
    $body = FieldStorage::create(['handle' => 'blurb', 'type' => 'text', 'cardinality' => 1, 'pii_class' => 'none',
        'settings' => ['maxLength' => 12],
    ]);
    $related = FieldStorage::create(['handle' => 'related_items', 'type' => 'relation', 'cardinality' => 2, 'pii_class' => 'none']);
    $this->entryType->fields()->create(['field_storage_id' => $body->id, 'label' => 'Blurb', 'is_required' => true]);
    $this->entryType->fields()->create(['field_storage_id' => $related->id, 'label' => 'Related']);

    $uuid = 'a5773ed2-5df9-4ffe-92d8-cf2f51de66f2';
    $target = Entry::create(['entry_type_id' => $this->entryType->id, 'translation_group' => $uuid, 'title' => 'Translation member']);
    $credential = SiteTokens::issue($this->actor, 'write client', ['entry.article.create', 'entry.article.update', 'entry.article.view'], now()->addHour());
    $path = '/api/v1/entries/article';
    $send = $this->write;

    $send('POST', $credential, $path, ['title' => 'No fields'])->assertUnprocessable()->assertJsonValidationErrors('values.blurb');
    $send('POST', $credential, $path, ['title' => 'Too long', 'values' => ['blurb' => str_repeat('x', 13)]])
        ->assertUnprocessable()->assertJsonValidationErrors('values.blurb');
    $send('POST', $credential, $path, ['title' => 'Cannot publish', 'status' => 'published', 'values' => ['blurb' => 'short']])
        ->assertForbidden();
    $send('POST', $credential, $path, ['title' => 'Invalid key', 'values' => ['blurb' => 'short', 'extra' => 1]])
        ->assertUnprocessable()->assertJsonValidationErrors('values');

    $created = $send('POST', $credential, $path, [
        'title' => 'New story', 'slug' => 'new-story', 'values' => [
            'blurb' => 'short', 'related_items' => [['entry' => $target->id], $target->id],
        ],
    ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    $id = $created->json('data.id');

    $record = Entry::query()->findOrFail($id);
    expect($record->revisions()->count())->toBe(1)
        ->and($record->revisionHistory()->first()->relation_state)->toBe([$related->id => [['group' => $uuid], $target->id]]);
    ($this->read)($credential, path: $path.'/'.$id)->assertOk()
        ->assertJsonPath('data.values.related_items', [['group' => $uuid, 'entry' => $target->id], $target->id]);

    $send('PATCH', $credential, $path.'/'.$id, ['title' => 'Kept group', 'values' => [
        'related_items' => [['group' => $uuid, 'entry' => $target->id], $target->id],
    ]])->assertOk();
    $send('PATCH', $credential, $path.'/'.$id, ['values' => ['related_items' => [' ']]])
        ->assertUnprocessable()->assertJsonValidationErrors('values.related_items.0');
    $send('PATCH', $credential, $path.'/'.$id, ['title' => 'Rejected title', 'values' => ['related_items' => [['entry' => 99999999]]]])
        ->assertUnprocessable()->assertJsonValidationErrors('values.related_items');
    expect($record->fresh()->title)->toBe('Kept group')
        ->and($record->revisions()->count())->toBe(2)
        ->and($record->fresh()->values)->toBe(['blurb' => 'short']);

    $this->role->grant('entry.article.publish');
    $publisher = SiteTokens::issue($this->actor, 'publisher', ['entry.article.update', 'entry.article.publish'], now()->addHour());
    $send('PATCH', $publisher, $path.'/'.$id, ['status' => 'published'])->assertOk();
    ($this->read)($credential, path: $path.'/'.$id)->assertOk()->assertJsonPath('data.status', 'published');

    ($this->read)($credential, path: '/api/v1/schema')->assertOk()
        ->assertJsonPath('data.0.create.required', ['title', 'values'])
        ->assertJsonPath('data.0.create.properties.values.required', ['blurb'])
        ->assertJsonMissingPath('data.0.update.required')
        ->assertJsonMissingPath('data.0.update.properties.values.required')
        ->assertJsonPath('data.0.update.properties.values.properties.related_items.anyOf.0.items.oneOf.2.properties.mode.enum', ['follow', 'pin']);
});

it('refuses writes outside the token Site, unavailable types and missing action grants', function (): void {
    $this->role->grant('entry.article.create');
    $entry = Entry::create(['entry_type_id' => $this->entryType->id, 'title' => 'Current Site']);
    $credential = SiteTokens::issue($this->actor, 'write client', ['entry.article.create', 'entry.article.update'], now()->addHour());
    $viewOnly = SiteTokens::issue($this->actor, 'reader client', ['entry.article.view'], now()->addHour());
    $send = $this->write;

    app(Context::class)->setSite($this->sibling);
    $sibling = Entry::create(['entry_type_id' => $this->entryType->id, 'title' => 'Sibling Site']);

    $send('PATCH', $viewOnly, '/api/v1/entries/article/'.$entry->id, ['title' => 'No permission'])->assertNotFound();
    $send('PATCH', $credential, '/api/v1/entries/article/'.$sibling->id, ['title' => 'Wrong record'])->assertNotFound();
    $send('POST', $credential, '/api/v1/entries/article', ['title' => 'Wrong host'], 'sibling')->assertUnauthorized();
    $send('POST', $credential, '/api/v1/entries/article', ['title' => 'Wrong organisation'], 'foreign')->assertUnauthorized();
    app(Context::class)->setSite($this->site);
    expect(Entry::query()->findOrFail($entry->id)->title)->toBe('Current Site');
});

it('uses media intake and restricts uploaded bytes to private storage on the token Site', function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);
    $type = EntryType::create([
        'handle' => 'api_documents', 'name' => 'Document', 'plural_name' => 'Documents', 'is_media' => true,
        'settings' => [MediaFormats::SETTING => ['txt']],
    ]);
    $this->role->grant('entry.api_documents.create');
    $this->role->grant('entry.api_documents.publish');
    $limited = SiteTokens::issue($this->actor, 'create only', ['entry.api_documents.create'], now()->addHour());
    $credential = SiteTokens::issue($this->actor, 'media client', ['entry.api_documents.create', 'entry.api_documents.publish'], now()->addHour());
    $send = function (string $token, array $fields, string $host = 'api') {
        app()->forgetScopedInstances();
        Permissions::forget();
        Auth::forgetGuards();
        Auth::shouldUse('web');

        return $this->post("https://{$host}.example.test/api/v1/media/api_documents", $fields, [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'multipart/form-data; boundary=fixture',
        ]);
    };

    $file = fn (string $name = 'notes.txt'): UploadedFile => UploadedFile::fake()->createWithContent($name, "Harmless manuscript notes.\n");
    $send($limited, ['file' => $file()])->assertForbidden();
    $send($credential, ['file' => $file()], 'sibling')->assertUnauthorized();
    $send($credential, ['file' => $file()], 'foreign')->assertUnauthorized();
    $send($credential, ['file' => $file(), 'visibility' => 'public'])->assertUnprocessable()->assertJsonValidationErrors('media');
    $send($credential, ['file' => $file('notes.png')])->assertUnprocessable()->assertJsonValidationErrors('file');
    $send($credential, ['file' => $file('notes.csv')])->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(DB::table('media_files')->count())->toBe(0)
        ->and(Storage::disk(MediaDisks::PRIVATE)->allFiles())->toBe([]);

    $response = $send($credential, ['file' => $file(), 'title' => 'Private notes'])->assertCreated();
    $id = $response->json('data.id');
    $entry = Entry::query()->findOrFail($id);
    $media = MediaFile::query()->sole();

    expect($entry->entry_type_id)->toBe($type->id)
        ->and($entry->site_id)->toBe($this->site->id)
        ->and($entry->title)->toBe('Private notes')
        ->and($media->visibility)->toBe('private')
        ->and($media->disk)->toBe(MediaDisks::PRIVATE)
        ->and(Storage::disk($media->disk)->get($media->path))->toBe("Harmless manuscript notes.\n")
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    ($this->read)($credential, path: '/api/v1/schema')->assertOk()
        ->assertJsonPath('data.0.media_upload.extensions', ['txt'])
        ->assertJsonPath('data.0.media_upload.max_bytes', MediaIntake::MAX_BYTES);
});
