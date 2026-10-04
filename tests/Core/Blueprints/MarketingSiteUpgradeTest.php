<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\MarketingSiteAtAnotherVersion;
use Kitsune\Core\Tests\Fixtures\MarketingSiteReshaped;
use Kitsune\Core\Tests\Fixtures\Released\MarketingSite100;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * ADR-030's condition 2 — "upgrades cleanly" — for the Marketing Site, checkable by a stranger on a bare clone.
 *
 * ⚠️ FROM THE RELEASE AN ORG ACTUALLY HAS. 1.0.0 is the frozen copy of what #172 shipped, so this upgrades what real
 * orgs hold; the version it upgrades to is a test's 1.1.0 until a released one replaces it (`upgradeNext()`), and only
 * then is the condition met (Adam, 2026-10-04: not "while there is one version").
 *
 * ⚠️ THE OPERATOR'S EDITS ARE MADE THROUGH THE MODELS, AND THE LOCK BY AN EDITOR'S SAVE, because that is how an org that
 * has used its site for a year looks — and every one of those edits must come through an upgrade untouched.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    $this->org = Org::create(['slug' => 'mysite', 'name' => 'My site']);
    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

/** The version the upgrade goes to: a test's 1.1.0, until Marketing Site 1.1.0 is released and replaces it here. */
function upgradeNext(): BlueprintDefinition
{
    return new MarketingSiteAtAnotherVersion;
}

/** Every row an apply writes, in every org, as the database holds it. */
function upgradeRows(): array
{
    $rows = [];

    /* ⚠️ `role_user` is a pivot with no `id`: SQLite reads an unknown quoted name as a string and orders by nothing, the other engines refuse it. */
    foreach (['entry_types', 'field_storage', 'fields', 'roles', 'role_permissions', 'role_user', 'audit_log', 'entries'] as $table) {
        $query = DB::table($table);

        foreach ($table === 'role_user' ? ['role_id', 'user_id'] : ['id'] as $column) {
            $query->orderBy($column);
        }

        $rows[$table] = $query->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

/** What an org has of the blueprint, without ids, timestamps or the org — the shape a fresh apply would give it. */
function upgradeShape(Org $org): array
{
    $types = EntryType::query()->where('org_id', $org->getKey())->orderBy('handle')->get();

    return [
        'types' => $types->map(static fn (EntryType $type): array => [
            ...$type->only(['handle', 'name', 'plural_name', 'icon', 'description', 'ordering']),
            'fields' => Field::query()->where('entry_type_id', $type->getKey())->orderBy('ordering')->get()
                ->map(static fn (Field $field): array => [
                    ...$field->only(['label', 'help_text', 'is_required', 'ordering', 'group']),
                    ...FieldStorage::query()->whereKey($field->field_storage_id)->firstOrFail()
                        ->only(['handle', 'type', 'cardinality', 'pii_class', 'is_indexed', 'settings']),
                ])->all(),
        ])->all(),
        'roles' => Role::query()->withoutGlobalScopes()->where('org_id', $org->getKey())->orderBy('handle')->get()
            ->map(static fn (Role $role): array => [
                ...$role->only(['handle', 'name', 'is_owner']),
                'grants' => DB::table('role_permissions')->where('role_id', $role->getKey())->orderBy('permission')->pluck('permission')->all(),
            ])->all(),
    ];
}

/** A manifest less what only its own org can say — ids and the prose outcome — with keys sorted, for MySQL. */
function upgradeOracle(Org $org): array
{
    $manifest = (array) Blueprint::query()->withoutGlobalScopes()->where('org_id', $org->getKey())->where('handle', 'marketing-site')->value('manifest');
    unset($manifest['outcome']);

    foreach (['entry_types', 'roles'] as $key) {
        foreach ($manifest[$key] as $i => $row) {
            unset($manifest[$key][$i]['id']);
        }
    }

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

/** A page saved as an editor saves one, which arms the lock on the storage it holds data for. */
function upgradeWritePage(Org $org): void
{
    $page = EntryType::query()->where('org_id', $org->getKey())->where('handle', 'page')->firstOrFail();
    $site = Site::create(['org_id' => $org->getKey(), 'handle' => 'main', 'slug' => $org->slug.'-main', 'name' => 'Main']);
    app(Context::class)->setSite($site);
    Entry::create(['entry_type_id' => $page->getKey(), 'title' => 'About', 'values' => ['page_body' => '<p>Hello</p>']]);
    app(Context::class)->setOrg($org);
}

it('upgrades a Marketing Site its operator has edited, adding only what the new version declares and keeping every edit', function (): void {
    BlueprintApplier::apply(new MarketingSite100);
    $recorded = Blueprint::receiptFor('marketing-site')->manifest;
    $orgId = $this->org->getKey();

    /* A year of the operator's own work. */
    $page = EntryType::query()->where('org_id', $orgId)->where('handle', 'page')->firstOrFail();
    $page->update(['name' => 'Web page']);

    $summary = FieldStorage::query()->where('org_id', $orgId)->where('handle', 'page_summary')->firstOrFail();
    Field::query()->where('field_storage_id', $summary->getKey())->firstOrFail()->update(['label' => 'Standfirst']);

    $theirMeta = FieldStorage::create(['org_id' => $orgId, 'handle' => 'meta_description', 'type' => 'text', 'cardinality' => 1, 'pii_class' => 'none']);
    Field::create(['entry_type_id' => $page->getKey(), 'field_storage_id' => $theirMeta->getKey(), 'label' => 'Their meta']);

    Role::query()->where('handle', 'marketing_writer')->firstOrFail()->update(['handle' => 'page_writer']);

    $editor = Role::query()->where('handle', 'marketing_editor')->firstOrFail();
    $editor->revoke('entry.page.delete');
    /** @var TestUser $holder */
    $holder = TestUser::create(['email' => 'editor@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $orgId, 'user_id' => $holder->getKey()]);
    $editor->assignTo($holder->getKey());

    upgradeWritePage($this->org);

    $before = upgradeRows();

    $result = BlueprintApplier::apply(upgradeNext());
    $after = upgradeRows();
    $receipt = Blueprint::receiptFor('marketing-site');

    expect($result['created'])->toBe(['field storage page_meta'])
        ->and($result['skipped'])->toBe([
            'version: merged over 1.0.0, which the receipt recorded — what 1.1.0 adds was written, and nothing 1.0.0 wrote was changed',
            'role marketing_writer: renamed page_writer since this blueprint wrote it; left as it is',
        ])
        ->and($result['roles_created'])->toBe([]);

    /* Exactly what 1.1.0 declares, and nothing else. */
    $meta = FieldStorage::query()->where('org_id', $orgId)->where('handle', 'page_meta')->firstOrFail();
    $metaField = Field::query()->where('field_storage_id', $meta->getKey())->firstOrFail();

    expect($meta->only(['type', 'cardinality', 'pii_class', 'is_indexed', 'is_locked']))
        ->toBe(['type' => 'textarea', 'cardinality' => 1, 'pii_class' => 'none', 'is_indexed' => false, 'is_locked' => false])
        ->and($meta->settings ?? [])->toBe([])
        ->and($metaField->only(['entry_type_id', 'label', 'help_text', 'is_required', 'ordering', 'group']))
        ->toBe(['entry_type_id' => $page->getKey(), 'label' => 'Meta description', 'help_text' => null, 'is_required' => false, 'ordering' => 30, 'group' => null])
        ->and(count($after['field_storage']))->toBe(count($before['field_storage']) + 1)
        ->and(count($after['fields']))->toBe(count($before['fields']) + 1);

    /* Every edit, untouched: no row 1.0.0 wrote was written. */
    expect(array_slice($after['field_storage'], 0, count($before['field_storage'])))->toBe($before['field_storage'])
        ->and(array_slice($after['fields'], 0, count($before['fields'])))->toBe($before['fields'])
        ->and($after['entry_types'])->toBe($before['entry_types'])
        ->and($after['roles'])->toBe($before['roles'])
        ->and($after['role_permissions'])->toBe($before['role_permissions'])
        ->and($after['role_user'])->toBe($before['role_user'])
        ->and($after['audit_log'])->toBe($before['audit_log'])
        ->and($after['entries'])->toBe($before['entries'])
        ->and($page->fresh()->name)->toBe('Web page')
        ->and((bool) FieldStorage::query()->where('org_id', $orgId)->where('handle', 'page_body')->value('is_locked'))->toBeTrue();

    /* The receipt moved, keeping every row 1.0.0 recorded under the id it recorded. */
    expect($receipt->version)->toBe('1.1.0')
        ->and($receipt->applied_at)->not->toBeNull()
        ->and(array_column($receipt->manifest['entry_types'], 'id', 'handle'))->toBe(array_column($recorded['entry_types'], 'id', 'handle'))
        ->and(array_column($receipt->manifest['roles'], 'id', 'handle'))->toBe(array_column($recorded['roles'], 'id', 'handle'));

    /* And run again, it is done. */
    expect(BlueprintApplier::apply(upgradeNext())['skipped'])->toBe(['already applied at this version'])
        ->and(upgradeRows())->toBe($after);
});

it('refuses a version that would reshape a field its operator\'s pages have locked, naming it, and writes nothing', function (): void {
    BlueprintApplier::apply(new MarketingSite100);
    upgradeWritePage($this->org);
    BlueprintApplier::apply(upgradeNext());

    $rows = upgradeRows();
    $receipt = DB::table('blueprints')->get()->map(static fn (object $row): array => (array) $row)->all();

    expect(fn () => BlueprintApplier::apply(new MarketingSiteReshaped))->toThrow(RuntimeException::class,
        'Blueprint [marketing-site] cannot be merged from 1.1.0 to 1.2.0: a merge adds what 1.2.0 declares and 1.1.0 did '
        .'not, and never changes or removes what 1.1.0 recorded — field page_body on page changes its type — and '
        .'page_body is locked because entries hold data for it: create a new field, migrate the data, verify, then '
        .'remove the old one (ADR-006). Nothing was written, and the receipt still says 1.1.0.'
    );

    expect(upgradeRows())->toBe($rows)
        ->and(DB::table('blueprints')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($receipt);
});

it('ends an unedited site exactly where a fresh apply of the new version ends', function (): void {
    BlueprintApplier::apply(new MarketingSite100);
    BlueprintApplier::apply(upgradeNext());

    $fresh = Org::create(['slug' => 'fresh', 'name' => 'Fresh']);
    app(Context::class)->setOrg($fresh);
    BlueprintApplier::apply(upgradeNext());

    expect(upgradeShape($this->org))->toBe(upgradeShape($fresh))
        ->and(upgradeShape($this->org)['types'][0]['fields'])->toHaveCount(3)
        ->and(upgradeOracle($this->org))->toBe(upgradeOracle($fresh));
});
