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
use Kitsune\Core\Blueprints\Declarations\AvailabilityDeclaration;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\DeclaresAvailability;
use Kitsune\Core\Blueprints\OnCollision;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;

/* ADR-039's third key: declarations own only the decisions they actually created. */
beforeEach(function (): void {
    FixtureBlueprint::reset();
    SchemaManagerStandIn::install()->recordOnly();
    $this->org = Org::create(['slug' => 'availability-a', 'name' => 'A']);
    $this->rival = Org::create(['slug' => 'availability-b', 'name' => 'B']);
    app(Context::class)->setOrg($this->org);
    $this->group = SiteGroup::create(['handle' => 'editions', 'name' => 'A editions']);
    $this->alpha = Site::create(['handle' => 'alpha', 'slug' => 'availability-a-alpha', 'name' => 'A alpha', 'site_group_id' => $this->group->id]);
    $this->beta = Site::create(['handle' => 'beta', 'slug' => 'availability-a-beta', 'name' => 'A beta', 'site_group_id' => $this->group->id]);
    app(Context::class)->setOrg($this->rival);
    $this->rivalGroup = SiteGroup::create(['handle' => 'editions', 'name' => 'B editions']);
    $this->rivalOnlyGroup = SiteGroup::create(['handle' => 'rival-only', 'name' => 'B only']);
    $this->rivalSite = Site::create(['handle' => 'alpha', 'slug' => 'availability-b-alpha', 'name' => 'B alpha', 'site_group_id' => $this->rivalGroup->id]);
    $this->rivalOnlySite = Site::create(['handle' => 'rival-only', 'slug' => 'availability-b-only', 'name' => 'B only', 'site_group_id' => $this->rivalOnlyGroup->id]);
    $this->rivalType = EntryType::create(['handle' => 'rival_type', 'name' => 'Rival', 'plural_name' => 'Rivals']);
    $this->rivalDecision = EntryTypeAvailability::create(['entry_type_id' => $this->rivalType->id, 'scope_type' => 'site', 'scope_id' => $this->rivalSite->id, 'is_enabled' => false]);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    FixtureBlueprint::reset();
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

/** @param list<AvailabilityDeclaration> $availability */
function av204Definition(array $availability = [], string $version = '1.0.0', ?array $types = null): BlueprintDefinition
{
    return new class($availability, $version, $types) implements DeclaresAvailability
    {
        public function __construct(private array $decisions, private string $number, private ?array $types) {}

        public function handle(): string
        {
            return 'availability_fixture';
        }

        public function version(): string
        {
            return $this->number;
        }

        public function entryTypes(): array
        {
            return $this->types ?? (new FixtureBlueprint)->entryTypes();
        }

        public function roles(): array
        {
            return [];
        }

        public function availability(): array
        {
            return $this->decisions;
        }
    };
}

/** Exact stored state, including the receipt and both orgs' bystanders, around a refused operation. */
function av204State(): array
{
    $state = [];
    foreach (['blueprints', 'entry_types', 'field_storage', 'fields', 'entry_type_availability', 'audit_log'] as $table) {
        $state[$table] = DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    return $state;
}

it('applies all three sparse levels to exact org-local targets and receipts the created decisions', function (): void {
    BlueprintApplier::apply(av204Definition([
        new AvailabilityDeclaration('dispatch', 'org', null, false),
        new AvailabilityDeclaration('dispatch', 'site_group', 'editions', true),
        new AvailabilityDeclaration('dispatch', 'site', 'alpha', false),
    ]));
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    $manifest = Blueprint::query()->where('handle', 'availability_fixture')->sole()->manifest;

    expect(EntryTypeAvailability::isEnabledFor($type, $this->alpha))->toBeFalse()
        ->and(EntryTypeAvailability::isEnabledFor($type, $this->beta))->toBeTrue()
        ->and($this->rivalDecision->fresh()->is_enabled)->toBeFalse()
        ->and($manifest['availability'])->toHaveCount(3)
        ->and(array_column($manifest['availability'], 'outcome'))->toBe(['created', 'created', 'created'])
        ->and(array_column($manifest['availability'], 'scope_id'))->toEqual([$this->org->id, $this->group->id, $this->alpha->id])
        ->and(EntryTypeAvailability::query()->where('entry_type_id', $type->id)->count())->toBe(3)
        ->and(EntryTypeAvailability::query()->where('entry_type_id', $type->id)->where('scope_type', 'site')->where('scope_id', $this->rivalSite->id)->exists())->toBeFalse();
});

it('refuses a foreign-only target before even an intent receipt is written', function (string $scope): void {
    $before = av204State();
    $definition = av204Definition([new AvailabilityDeclaration('dispatch', $scope, 'rival-only', false)]);

    expect(fn () => BlueprintApplier::apply($definition))->toThrow(RuntimeException::class)
        ->and(av204State())->toBe($before)
        ->and(Blueprint::query()->where('handle', 'availability_fixture')->exists())->toBeFalse();
})->with(['site', 'site_group']);

it('refuses an existing decision even when its value agrees, without moving the receipt', function (bool $declared): void {
    BlueprintApplier::apply(av204Definition());
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    EntryTypeAvailability::create(['entry_type_id' => $type->id, 'scope_type' => 'site', 'scope_id' => $this->alpha->id, 'is_enabled' => false]);
    $before = av204State();

    expect(fn () => BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'site', 'alpha', $declared)], '2.0.0')))
        ->toThrow(RuntimeException::class)
        ->and(av204State())->toBe($before);
})->with([false, true]);

it('records Skip without adopting or overwriting the operator decision and refuses its parent cascade', function (): void {
    BlueprintApplier::apply(av204Definition());
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    $row = EntryTypeAvailability::create(['entry_type_id' => $type->id, 'scope_type' => 'site', 'scope_id' => $this->alpha->id, 'is_enabled' => false]);
    BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'site', 'alpha', true, OnCollision::Skip)], '2.0.0'));
    $receipt = Blueprint::query()->where('handle', 'availability_fixture')->sole();

    expect($row->fresh()->is_enabled)->toBeFalse()
        ->and($receipt->manifest['availability'][0]['outcome'])->toBe('skipped');
    $before = av204State();
    expect(fn () => BlueprintApplier::reverse('availability_fixture'))->toThrow(RuntimeException::class, 'availability')
        ->and(av204State())->toBe($before);
});

it('adds only new tuples while preserving edited and deleted decisions across reapply and merge', function (): void {
    $first = new AvailabilityDeclaration('dispatch', 'site', 'alpha', false);
    BlueprintApplier::apply(av204Definition([$first]));
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    $row = EntryTypeAvailability::query()->where('entry_type_id', $type->id)->sole();
    $row->update(['is_enabled' => true]);
    $before = av204State();
    BlueprintApplier::apply(av204Definition([$first]));
    expect(av204State())->toBe($before);

    $second = new AvailabilityDeclaration('dispatch', 'site', 'beta', false);
    BlueprintApplier::apply(av204Definition([$first, $second], '2.0.0'));
    expect($row->fresh()->is_enabled)->toBeTrue()
        ->and(EntryTypeAvailability::isEnabledFor($type, $this->beta))->toBeFalse();
    $row->delete();
    BlueprintApplier::apply(av204Definition([$first, $second, new AvailabilityDeclaration('dispatch', 'org', null, false)], '3.0.0'));
    expect(EntryTypeAvailability::query()->whereKey($row->id)->exists())->toBeFalse()
        ->and(EntryTypeAvailability::query()->where('entry_type_id', $type->id)->where('scope_type', 'site')->where('scope_id', $this->alpha->id)->exists())->toBeFalse();
});

it('refuses changed or removed declarations while leaving every stored row intact', function (bool $remove): void {
    BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'site', 'alpha', false)]));
    $before = av204State();
    $next = $remove ? [] : [new AvailabilityDeclaration('dispatch', 'site', 'alpha', true)];

    expect(fn () => BlueprintApplier::apply(av204Definition($next, '2.0.0')))->toThrow(RuntimeException::class)
        ->and(av204State())->toBe($before);
})->with([false, true]);

it('refuses an edited created decision and removes an unchanged one only with its owned parent', function (): void {
    BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'site', 'alpha', false)]));
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    $row = EntryTypeAvailability::query()->where('entry_type_id', $type->id)->sole();
    $row->update(['is_enabled' => true]);
    $before = av204State();
    expect(fn () => BlueprintApplier::reverse('availability_fixture'))->toThrow(RuntimeException::class, 'availability')
        ->and(av204State())->toBe($before);

    $row->update(['is_enabled' => false]);
    BlueprintApplier::reverse('availability_fixture');
    expect(EntryType::query()->whereKey($type->id)->exists())->toBeFalse()
        ->and(EntryTypeAvailability::query()->whereKey($row->id)->exists())->toBeFalse()
        ->and($this->rivalDecision->fresh()->is_enabled)->toBeFalse();
});

it('protects operator availability on a legacy receipt through the parent cascade', function (): void {
    BlueprintApplier::apply(av204Definition());
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    $receipt = Blueprint::query()->where('handle', 'availability_fixture')->sole();
    $legacy = $receipt->manifest;
    unset($legacy['availability']);
    DB::table('blueprints')->where('org_id', $this->org->id)->where('id', $receipt->id)->update(['manifest' => json_encode($legacy, JSON_THROW_ON_ERROR)]);
    $row = EntryTypeAvailability::create(['entry_type_id' => $type->id, 'scope_type' => 'site', 'scope_id' => $this->alpha->id, 'is_enabled' => false]);
    $before = av204State();

    expect(fn () => BlueprintApplier::reverse('availability_fixture'))->toThrow(RuntimeException::class, 'availability')
        ->and(av204State())->toBe($before);
    $row->delete();
    expect(BlueprintApplier::reverse('availability_fixture')['outcome'])->toBe('reversed')
        ->and($this->rivalDecision->fresh()->is_enabled)->toBeFalse();
});

it('refuses malformed declaration shapes before an intent or any data write', function (array $rows): void {
    $before = av204State();
    expect(fn () => BlueprintApplier::apply(av204Definition($rows)))->toThrow(InvalidArgumentException::class)
        ->and(av204State())->toBe($before);
})->with([
    'unknown level' => [[new AvailabilityDeclaration('dispatch', 'customer', null, false)]],
    'undeclared type' => [[new AvailabilityDeclaration('other', 'org', null, false)]],
    'org selector' => [[new AvailabilityDeclaration('dispatch', 'org', 'alpha', false)]],
    'missing site' => [[new AvailabilityDeclaration('dispatch', 'site', null, false)]],
    'empty group' => [[new AvailabilityDeclaration('dispatch', 'site_group', '', false)]],
    'space substitution' => [[new AvailabilityDeclaration('dispatch', 'site', ' alpha', false)]],
    'wrong object' => [[new stdClass]],
    'not a list' => [['one' => new AvailabilityDeclaration('dispatch', 'org', null, false)]],
    'duplicate tuple' => [[new AvailabilityDeclaration('dispatch', 'site', 'alpha', false), new AvailabilityDeclaration('dispatch', 'site', 'alpha', true)]],
]);

it('treats a numeric target as a handle and rejects a wrong spelling under case folding too', function (): void {
    $numeric = Site::create(['handle' => '123', 'slug' => 'availability-numeric', 'name' => 'Numeric']);
    BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'site', '123', false)]));
    $receipt = Blueprint::query()->where('handle', 'availability_fixture')->sole();
    expect($receipt->manifest['availability'][0]['scope_id'])->toEqual($numeric->id);
    $before = av204State();
    expect(fn () => BlueprintApplier::apply(av204Definition([
        new AvailabilityDeclaration('dispatch', 'site', '123', false),
        new AvailabilityDeclaration('dispatch', 'site', 'Alpha', false),
    ], '2.0.0')))->toThrow(RuntimeException::class)
        ->and(av204State())->toBe($before);
});

it('refuses a database id shortcut and an unknown target before intent', function (string $name): void {
    $before = av204State();
    $target = $name === 'id' ? (string) $this->alpha->id : 'not-a-site';
    expect(fn () => BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'site', $target, false)])))
        ->toThrow(RuntimeException::class)->and(av204State())->toBe($before);
})->with(['id', 'unknown']);

it('never writes availability to an adopted or global entry type', function (bool $global): void {
    $type = $global
        ? EntryType::withoutScopeBecause('a global type is a bystander to this blueprint', fn ($query) => $query->create(['org_id' => null, 'handle' => 'adopted', 'name' => 'Existing', 'plural_name' => 'Existing']))
        : EntryType::create(['handle' => 'adopted', 'name' => 'Existing', 'plural_name' => 'Existing']);
    $types = [new EntryTypeDeclaration(handle: 'adopted', name: 'Existing', pluralName: 'Existing', onCollision: OnCollision::Skip)];
    expect(fn () => BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('adopted', 'org', null, false)], types: $types)))
        ->toThrow(RuntimeException::class);
    expect(EntryTypeAvailability::query()->where('entry_type_id', $type->id)->exists())->toBeFalse()
        ->and($type->fresh()->name)->toBe('Existing');
})->with([false, true]);

it('refuses a new decision on a parent removed after apply without rebinding its reused handle', function (): void {
    BlueprintApplier::apply(av204Definition());
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    $type->delete();
    $replacement = EntryType::create(['handle' => 'dispatch', 'name' => 'Operator', 'plural_name' => 'Operators']);
    $before = av204State();
    expect(fn () => BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'org', null, false)], '2.0.0')))
        ->toThrow(RuntimeException::class)->and(av204State())->toBe($before)
        ->and(EntryTypeAvailability::query()->where('entry_type_id', $replacement->id)->exists())->toBeFalse();
});

it('does not rebind a recorded decision to a replacement target during merge or reverse', function (): void {
    $declaration = new AvailabilityDeclaration('dispatch', 'site', 'alpha', false);
    BlueprintApplier::apply(av204Definition([$declaration]));
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    $oldId = $this->alpha->id;
    DB::table('sites')->where('id', $oldId)->update(['handle' => 'renamed']);
    $replacement = Site::create(['handle' => 'alpha', 'slug' => 'replacement-alpha', 'name' => 'Replacement']);
    BlueprintApplier::apply(av204Definition([$declaration, new AvailabilityDeclaration('dispatch', 'site', 'beta', false)], '2.0.0'));
    expect(EntryTypeAvailability::query()->where('entry_type_id', $type->id)->where('scope_type', 'site')->where('scope_id', $replacement->id)->exists())->toBeFalse();
    $before = av204State();
    expect(fn () => BlueprintApplier::reverse('availability_fixture'))->toThrow(RuntimeException::class, 'availability')
        ->and(av204State())->toBe($before);
});

it('refuses a target that moves between preflight and the rows transaction without writing any decisions', function (): void {
    Blueprint::created(function (Blueprint $receipt): void {
        if ($receipt->handle === 'availability_fixture') {
            DB::table('sites')->where('id', $this->alpha->id)->update(['org_id' => $this->rival->id, 'handle' => 'moved-alpha']);
        }
    });
    expect(fn () => BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'site', 'alpha', false)])))
        ->toThrow(RuntimeException::class, 'captured target');
    expect(EntryType::query()->where('handle', 'dispatch')->exists())->toBeFalse()
        ->and(Blueprint::query()->where('handle', 'availability_fixture')->sole()->manifest)->toBeNull();
});

it('finishes an actual post-commit interruption from recorded facts without replaying deleted or edited decisions', function (): void {
    FixtureBlueprint::$indexed = true;
    app(SchemaManager::class)->throwOnce(new RuntimeException('204 deliberate post-commit interruption'));
    $declarations = [new AvailabilityDeclaration('dispatch', 'site', 'alpha', false), new AvailabilityDeclaration('dispatch', 'site', 'beta', false)];
    expect(fn () => BlueprintApplier::apply(av204Definition($declarations)))->toThrow(RuntimeException::class, '204 deliberate');
    $receipt = Blueprint::query()->where('handle', 'availability_fixture')->sole();
    expect($receipt->applied_at)->toBeNull()->and($receipt->manifest['availability'])->toHaveCount(2);
    $first = $receipt->manifest['availability'][0]['id'];
    $second = $receipt->manifest['availability'][1]['id'];
    EntryTypeAvailability::query()->whereKey($first)->delete();
    EntryTypeAvailability::query()->whereKey($second)->update(['is_enabled' => true]);
    $outcome = BlueprintApplier::apply(av204Definition([], '2.0.0'));
    expect($outcome['version'])->toBe('1.0.0')
        ->and(implode(' ', $outcome['skipped']))->toContain('availability decision', 'not written again')
        ->and(EntryTypeAvailability::query()->whereKey($first)->exists())->toBeFalse()
        ->and(EntryTypeAvailability::query()->whereKey($second)->sole()->is_enabled)->toBeTrue()
        ->and($receipt->fresh()->applied_at)->not->toBeNull();
});

it('refuses a malformed present availability manifest for merge and finish and reverses only its receipt', function (mixed $bad): void {
    BlueprintApplier::apply(av204Definition());
    $receipt = Blueprint::query()->where('handle', 'availability_fixture')->sole();
    $manifest = $receipt->manifest;
    $manifest['availability'] = $bad;
    DB::table('blueprints')->where('id', $receipt->id)->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
    $before = av204State();
    expect(fn () => BlueprintApplier::apply(av204Definition([], '2.0.0')))->toThrow(RuntimeException::class)
        ->and(av204State())->toBe($before);
    DB::table('blueprints')->where('id', $receipt->id)->update(['applied_at' => null]);
    $beforeFinish = av204State();
    expect(fn () => BlueprintApplier::apply(av204Definition()))->toThrow(RuntimeException::class)
        ->and(av204State())->toBe($beforeFinish);
    expect(BlueprintApplier::reverse('availability_fixture')['outcome'])->toBe('receipt-only')
        ->and(EntryType::query()->where('handle', 'dispatch')->exists())->toBeTrue();
})->with(['null' => [null], 'scalar' => ['broken'], 'not-list' => [['bad' => []]], 'bad-record' => [[['scope' => 'site']]]]);

it('rolls back new tuples and added parents when a later decision collides', function (): void {
    BlueprintApplier::apply(av204Definition());
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    EntryTypeAvailability::create(['entry_type_id' => $type->id, 'scope_type' => 'site', 'scope_id' => $this->alpha->id, 'is_enabled' => false]);
    $before = av204State();
    $types = [...(new FixtureBlueprint)->entryTypes(), new EntryTypeDeclaration(handle: 'new_type', name: 'New', pluralName: 'New')];
    expect(fn () => BlueprintApplier::apply(av204Definition([
        new AvailabilityDeclaration('new_type', 'org', null, false), new AvailabilityDeclaration('dispatch', 'site', 'alpha', false),
    ], '2.0.0', $types)))->toThrow(RuntimeException::class)
        ->and(av204State())->toBe($before);
});

it('fails closed without org context', function (): void {
    $before = av204State();
    app(Context::class)->forget();
    expect(fn () => BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'org', null, false)])))
        ->toThrow(RuntimeException::class, 'no organisation');
    app(Context::class)->setOrg($this->org);
    expect(av204State())->toBe($before);
});

it('keeps equal handles in two organisations independently owned and resolves their own hierarchy', function (): void {
    $definition = av204Definition([new AvailabilityDeclaration('dispatch', 'site_group', 'editions', false), new AvailabilityDeclaration('dispatch', 'site', 'alpha', true)]);
    BlueprintApplier::apply($definition);
    $own = EntryType::query()->where('handle', 'dispatch')->sole();
    app(Context::class)->setOrg($this->rival);
    BlueprintApplier::apply($definition);
    $rival = EntryType::query()->where('org_id', $this->rival->id)->where('handle', 'dispatch')->sole();
    expect($rival->id)->not->toBe($own->id)
        ->and(EntryTypeAvailability::isEnabledFor($rival, $this->rivalSite))->toBeTrue();
    BlueprintApplier::reverse('availability_fixture');
    app(Context::class)->setOrg($this->org);
    expect(EntryTypeAvailability::isEnabledFor($own, $this->alpha))->toBeTrue()
        ->and(EntryTypeAvailability::isEnabledFor($own, $this->beta))->toBeFalse()
        ->and($this->rivalDecision->fresh()->is_enabled)->toBeFalse();
});

it('never removes an operator replacement even with the original tuple and value', function (): void {
    BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'org', null, false)]));
    $type = EntryType::query()->where('handle', 'dispatch')->sole();
    $old = EntryTypeAvailability::query()->where('entry_type_id', $type->id)->sole();
    $old->delete();
    $replacement = EntryTypeAvailability::create(['entry_type_id' => $type->id, 'scope_type' => 'org', 'scope_id' => $this->org->id, 'is_enabled' => false]);
    expect($replacement->id)->not->toBe($old->id);
    $before = av204State();
    expect(fn () => BlueprintApplier::reverse('availability_fixture'))->toThrow(RuntimeException::class, 'availability')
        ->and(av204State())->toBe($before);
});

it('protects the parent when a recorded target is deleted, renamed or moved to another org', function (string $scope, string $change): void {
    $target = $scope === 'site' ? $this->alpha : $this->group;
    $handle = $scope === 'site' ? 'alpha' : 'editions';
    $table = $scope === 'site' ? 'sites' : 'site_groups';
    BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', $scope, $handle, false)]));
    if ($change === 'delete') {
        DB::table($table)->where('id', $target->id)->delete();
    } else {
        DB::table($table)->where('id', $target->id)->update($change === 'rename' ? ['handle' => 'renamed'] : ['org_id' => $this->rival->id, 'handle' => 'moved']);
    }
    $before = av204State();
    expect(fn () => BlueprintApplier::reverse('availability_fixture'))->toThrow(RuntimeException::class, 'availability')
        ->and(av204State())->toBe($before);
})->with(['site', 'site_group'])->with(['delete', 'rename', 'move']);

it('gives malformed ownership records no destructive authority', function (string $corruption): void {
    BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'org', null, false)]));
    $receipt = Blueprint::query()->where('handle', 'availability_fixture')->sole();
    $manifest = $receipt->manifest;
    if ($corruption === 'duplicate') {
        $manifest['availability'][] = $manifest['availability'][0];
    } else {
        [$key, $value] = match ($corruption) {
            'bool' => ['is_enabled', 0],
            'parent' => ['entry_type_id', $this->rivalType->id],
            'org' => ['scope_id', $this->rival->id],
            'skip' => ['outcome', 'skipped'],
            'id' => ['id', null],
        };
        $manifest['availability'][0][$key] = $value;
    }
    DB::table('blueprints')->where('id', $receipt->id)->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
    $before = av204State();
    expect(fn () => BlueprintApplier::apply(av204Definition([new AvailabilityDeclaration('dispatch', 'org', null, false)], '2.0.0')))
        ->toThrow(RuntimeException::class)->and(av204State())->toBe($before);
    expect(BlueprintApplier::reverse('availability_fixture')['outcome'])->toBe('receipt-only')
        ->and(EntryType::query()->where('handle', 'dispatch')->exists())->toBeTrue();
})->with(['bool', 'parent', 'org', 'skip', 'id', 'duplicate']);
