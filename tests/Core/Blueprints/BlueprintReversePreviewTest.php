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
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;
use Kitsune\Core\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    FixtureBlueprint::reset();
    config(['auth.providers.users.model' => TestUser::class]);
    $this->schema = SchemaManagerStandIn::install()->recordOnly();
    $this->org = Org::create(['slug' => 'preview', 'name' => 'Preview']);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
    FixtureBlueprint::reset();
});

/** Full row values, including audit and receipt, rather than counts that could hide updates. */
function blueprintPreviewRows(): array
{
    $result = [];

    foreach (['blueprints', 'entry_types', 'fields', 'field_storage', 'entry_type_availability', 'roles', 'role_permissions', 'role_user', 'entries', 'entry_relations', 'entry_revisions', 'audit_log'] as $table) {
        $result[$table] = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    return $result;
}

it('previews removal without changing any rows, receipt, audit or schema', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $before = blueprintPreviewRows();
    $schemaCalls = [$this->schema->syncedHandles, $this->schema->droppedHandles];
    $writes = [];
    $watch = true;
    DB::listen(static function (QueryExecuted $query) use (&$writes, &$watch): void {
        if ($watch && preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $preview = BlueprintApplier::previewReverse('fixture');
    $watch = false;

    expect($preview['handle'])->toBe('fixture')
        ->and($preview['version'])->toBe('1.0.0')
        ->and($preview['outcome'])->toBe('reversed')
        ->and($preview['refusals'])->toBe([])
        ->and($preview['removed'])->not->toBeEmpty()
        ->and($writes)->toBe([])
        ->and(blueprintPreviewRows())->toBe($before)
        ->and([$this->schema->syncedHandles, $this->schema->droppedHandles])->toBe($schemaCalls);

    $actual = BlueprintApplier::reverse('fixture');
    expect($actual['removed'])->toBe($preview['removed'])
        ->and($actual['kept'])->toBe($preview['kept']);
});

it('names role holders, the owner flag and operator grants while preserving all authority', function (): void {
    FixtureBlueprint::$roles = [new RoleDeclaration('writer', 'Writer', ['dispatch' => ['view']])];
    BlueprintApplier::apply(new FixtureBlueprint);
    $role = Role::query()->where('handle', 'writer')->firstOrFail();
    $user = TestUser::create(['email' => 'preview-holder@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->id, 'user_id' => $user->getKey()]);
    $role->assignTo($user->getKey());
    $role->update(['is_owner' => true]);
    $role->grant('entry.dispatch.update');
    $before = blueprintPreviewRows();

    $preview = BlueprintApplier::previewReverse('fixture');

    expect(implode('; ', $preview['refusals']))->toContain('held by 1 account', 'Owner turned on', 'entry.dispatch.update')
        ->and(blueprintPreviewRows())->toBe($before);
});

it('names operator field additions without treating them as blueprint property', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $type = EntryType::query()->where('handle', 'dispatch')->firstOrFail();
    $storage = FieldStorage::create(['handle' => 'dispatch_extra', 'type' => 'text', 'pii_class' => 'none', 'cardinality' => 1]);
    Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $storage->id, 'label' => 'Operator addition']);
    $before = blueprintPreviewRows();

    expect(implode('; ', BlueprintApplier::previewReverse('fixture')['refusals']))->toContain('dispatch_extra', 'did not write')
        ->and(blueprintPreviewRows())->toBe($before);
});

it('reports content in another site including trash and never removes it', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $site = Site::create(['handle' => 'other', 'slug' => 'preview-other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($site);
    $type = EntryType::query()->where('handle', 'dispatch')->firstOrFail();
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Kept', 'status' => 'draft']);
    $entry->delete();
    app(Context::class)->setOrg($this->org);
    $before = blueprintPreviewRows();

    $preview = BlueprintApplier::previewReverse('fixture');

    expect(implode('; ', $preview['refusals']))->toContain('dispatch', '1')
        ->and(blueprintPreviewRows())->toBe($before);
});

it('cannot preview another org receipt or operate with no org', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $other = Org::create(['slug' => 'other', 'name' => 'Other']);
    app(Context::class)->setOrg($other);
    $before = blueprintPreviewRows();

    expect(fn () => BlueprintApplier::previewReverse('fixture'))->toThrow(RuntimeException::class)
        ->and(blueprintPreviewRows())->toBe($before);

    app(Context::class)->forget();
    expect(fn () => BlueprintApplier::previewReverse('fixture'))->toThrow(RuntimeException::class);
});

it('shows an uncommitted receipt as abandonment without deleting it', function (): void {
    Blueprint::create(['handle' => 'fixture', 'version' => '1.0.0', 'manifest' => null, 'applied_at' => null]);
    $before = blueprintPreviewRows();

    expect(BlueprintApplier::previewReverse('fixture'))
        ->toMatchArray(['outcome' => 'abandoned', 'removed' => [], 'refusals' => []])
        ->and(blueprintPreviewRows())->toBe($before);
});

it('reports malformed receipt-only cleanup without writing it', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    DB::table('blueprints')->where('org_id', $this->org->id)->update(['manifest' => '{"broken":true}']);
    $before = blueprintPreviewRows();

    $preview = BlueprintApplier::previewReverse('fixture');

    expect($preview['outcome'])->toBe('receipt-only')
        ->and($preview['problems'])->not->toBeEmpty()
        ->and($preview['removed'])->toBe([])
        ->and(blueprintPreviewRows())->toBe($before);
});

it('does not turn an earlier clear preview into permission to reverse later content', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    expect(BlueprintApplier::previewReverse('fixture')['refusals'])->toBe([]);
    $type = EntryType::query()->where('handle', 'dispatch')->firstOrFail();
    Entry::create(['entry_type_id' => $type->id, 'title' => 'Arrived later', 'status' => 'draft']);
    $before = blueprintPreviewRows();

    expect(fn () => BlueprintApplier::reverse('fixture'))->toThrow(RuntimeException::class)
        ->and(blueprintPreviewRows())->toBe($before);
});
