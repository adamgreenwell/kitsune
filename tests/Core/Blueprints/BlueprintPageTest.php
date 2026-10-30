<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Filament\Pages\Blueprints;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\FixtureBlueprint;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\PanelUser;
use Kitsune\Core\Tests\Fixtures\Released\MarketingSite100;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config(['auth.providers.users.model' => PanelUser::class, 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    FixtureBlueprint::reset();
    app(BlueprintRegistry::class)->register(new FixtureBlueprint);
    $this->schema = SchemaManagerStandIn::install()->recordOnly();
    $this->org = Org::create(['slug' => 'admin', 'name' => 'Admin Org']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'admin-main', 'name' => 'Main']);
    PanelTenancy::enter($this->site);
    $this->owner = blueprintPageMember($this->site, true, 'owner@admin.test');
});

afterEach(function (): void {
    FixtureBlueprint::reset();
    app()->forgetInstance(SchemaManager::class);
    Auth::guard('web')->logout();
    app(Context::class)->forget();
    Permissions::forget();
});

function blueprintPageMember(Site $site, bool $owner, string $email): PanelUser
{
    /** @var PanelUser $user */
    $user = PanelUser::create(['email' => $email]);
    $user->reachableSiteIds = [(int) $site->id];
    DB::table('org_user')->insert(['org_id' => $site->org_id, 'user_id' => $user->getKey()]);

    if ($owner) {
        $role = Role::query()->where('is_owner', true)->first()
            ?? Role::create(['handle' => 'owner', 'name' => 'Owner', 'is_owner' => true]);
        $role->assignTo($user->getKey());
    }

    Auth::guard('web')->setUser($user);
    Permissions::forget();

    return $user;
}

it('admits the current org owner and refuses a member, anonymous user and another org owner', function (): void {
    expect(Blueprints::canAccess())->toBeTrue();
    blueprintPageMember($this->site, false, 'member@admin.test');
    expect(Blueprints::canAccess())->toBeFalse();
    Auth::guard('web')->logout();
    expect(Blueprints::canAccess())->toBeFalse();

    $other = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    app(Context::class)->setOrg($other);
    $site = Site::create(['handle' => 'main', 'slug' => 'rival-main', 'name' => 'Main']);
    PanelTenancy::moveTo($site);
    blueprintPageMember($site, true, 'owner@rival.test');
    PanelTenancy::moveTo($this->site);
    expect(Blueprints::canAccess())->toBeFalse();
});

it('refuses an owner at a same-org site the panel does not admit and mismatched panel context', function (): void {
    $site = Site::create(['handle' => 'second', 'slug' => 'admin-second', 'name' => 'Second']);
    PanelTenancy::moveTo($site);
    expect(Blueprints::canAccess())->toBeFalse();
    $this->owner->reachableSiteIds[] = (int) $site->id;
    expect(Blueprints::canAccess())->toBeTrue();
    app(Context::class)->setSite($this->site);
    expect(Blueprints::canAccess())->toBeFalse();
});

it('lists registered definitions and only this org receipts without writing', function (): void {
    $before = DB::table('blueprints')->count();
    $lines = Blueprints::lines();
    expect(array_column($lines, 'handle'))->toContain('blog', 'dam', 'marketing-site', 'fixture')
        ->and(DB::table('blueprints')->count())->toBe($before);

    $review = Blueprints::review('fixture', 'apply');
    expect($review['confirmation'])->toBe('fixture @ admin')
        ->and($review['org'])->toBe('Admin Org')
        ->and(DB::table('blueprints')->count())->toBe($before);
});

it('applies and reapplies through the kernel without onboarding or default content', function (): void {
    $review = Blueprints::review('fixture', 'apply');
    $result = Blueprints::perform('fixture', 'apply', $review['confirmation'], $review['token']);
    expect($result['handle'])->toBe('fixture')
        ->and(Blueprint::receiptFor('fixture')?->applied_at)->not->toBeNull()
        ->and(Entry::query()->count())->toBe(0)
        ->and(DB::table('users')->count())->toBe(1)
        ->and(Org::query()->count())->toBe(1);
    $review = Blueprints::review('fixture', 'apply');
    $again = Blueprints::perform('fixture', 'apply', $review['confirmation'], $review['token']);
    expect($again['created'])->toBe([])
        ->and(EntryType::query()->where('handle', 'dispatch')->count())->toBe(1);
});

it('requires exact concrete confirmation and an authentic current review token before writing', function (): void {
    $review = Blueprints::review('fixture', 'apply');
    expect(fn () => Blueprints::perform('fixture', 'apply', 'fixture', $review['token']))->toThrow(ValidationException::class)
        ->and(fn () => Blueprints::perform('fixture', 'apply', $review['confirmation'], 'forged'))->toThrow(ValidationException::class)
        ->and(Blueprint::receiptFor('fixture'))->toBeNull()
        ->and(EntryType::query()->where('handle', 'dispatch')->exists())->toBeFalse();
});

it('refuses review without a signing key and rejects a token after key rotation', function (): void {
    $review = Blueprints::review('fixture', 'apply');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect(fn () => Blueprints::perform('fixture', 'apply', $review['confirmation'], $review['token']))
        ->toThrow(ValidationException::class);
    config(['app.key' => '']);
    expect(fn () => Blueprints::review('fixture', 'apply'))->toThrow(RuntimeException::class, 'signing key')
        ->and(Blueprint::receiptFor('fixture'))->toBeNull();
});

it('does not accept a review for another operation or another same-org site', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $review = Blueprints::review('fixture', 'apply');
    expect(fn () => Blueprints::perform('fixture', 'reverse', $review['confirmation'], $review['token']))->toThrow(ValidationException::class);
    $site = Site::create(['handle' => 'second', 'slug' => 'admin-second', 'name' => 'Second']);
    $this->owner->reachableSiteIds[] = (int) $site->id;
    PanelTenancy::moveTo($site);
    expect(fn () => Blueprints::perform('fixture', 'apply', $review['confirmation'], $review['token']))->toThrow(ValidationException::class)
        ->and(Blueprint::receiptFor('fixture'))->not->toBeNull();
});

it('rejects a stale receipt or changed definition rather than acting on the earlier review', function (): void {
    $review = Blueprints::review('fixture', 'apply');
    BlueprintApplier::apply(new FixtureBlueprint);
    expect(fn () => Blueprints::perform('fixture', 'apply', $review['confirmation'], $review['token']))->toThrow(ValidationException::class);
    $review = Blueprints::review('fixture', 'apply');
    FixtureBlueprint::$version = '1.1.0';
    expect(fn () => Blueprints::perform('fixture', 'apply', $review['confirmation'], $review['token']))->toThrow(ValidationException::class)
        ->and(Blueprint::receiptFor('fixture')?->version)->toBe('1.0.0');
});

it('refuses a receipt changed after the page recheck but before the kernel reads it', function (string $operation): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $review = Blueprints::review('fixture', $operation);
    $staged = false;
    $changed = null;
    $types = DB::table('entry_types')->get()->all();
    $audit = DB::table('audit_log')->get()->all();
    DB::listen(static function (QueryExecuted $query) use (&$staged, &$changed): void {
        if (! $staged && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'blueprints')) {
            $staged = true;
            DB::table('blueprints')->where('handle', 'fixture')->update(['version' => '9.0.0']);
            $changed = (array) DB::table('blueprints')->where('handle', 'fixture')->first();
        }
    });

    expect(fn () => Blueprints::perform('fixture', $operation, $review['confirmation'], $review['token']))
        ->toThrow(RuntimeException::class, 'The blueprint receipt changed after review.')
        ->and($staged)->toBeTrue()
        ->and((array) DB::table('blueprints')->where('handle', 'fixture')->first())->toBe($changed)
        ->and(DB::table('entry_types')->get()->all())->toEqual($types)
        ->and(DB::table('audit_log')->get()->all())->toEqual($audit);
})->with(['apply', 'reverse']);

it('rechecks owner authority on direct submits even when an earlier check was memoized', function (): void {
    $review = Blueprints::review('fixture', 'apply');
    expect(Blueprints::canAccess())->toBeTrue();
    // A hostile stale snapshot: bypass model events to ensure their cache invalidation cannot save the page.
    DB::table('role_user')->where('user_id', $this->owner->getKey())->delete();
    expect(fn () => Blueprints::perform('fixture', 'apply', $review['confirmation'], $review['token']))->toThrow(HttpException::class)
        ->and(Blueprint::receiptFor('fixture'))->toBeNull();
});

it('shows reverse obstacles and preserves content on an actual refused submission', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    $type = EntryType::query()->where('handle', 'dispatch')->firstOrFail();
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Keep this']);
    $review = Blueprints::review('fixture', 'reverse');
    expect(implode('; ', $review['preview']['refusals']))->toContain('dispatch', '1 entry');
    expect(fn () => Blueprints::perform('fixture', 'reverse', $review['confirmation'], $review['token']))->toThrow(RuntimeException::class)
        ->and(Entry::query()->whereKey($entry->id)->exists())->toBeTrue()
        ->and(Blueprint::receiptFor('fixture'))->not->toBeNull();
});

it('refuses invalid operations and unregistered apply handles without writing a receipt', function (): void {
    expect(fn () => Blueprints::review('fixture', 'purge'))->toThrow(ValidationException::class)
        ->and(fn () => Blueprints::review('not-registered', 'apply'))->toThrow(ValidationException::class)
        ->and(DB::table('blueprints')->count())->toBe(0);
});

it('registers the real page and owner sidebar link outside content routes', function (): void {
    $panel = KitsunePanel::apply(Panel::make())->id('admin');
    expect($panel->getPages())->toContain(Blueprints::class);

    $navigation = Closure::bind(static fn (): NavigationBuilder => self::navigation(new NavigationBuilder), null, KitsunePanel::class)();
    $labels = collect($navigation->getNavigation())
        ->flatMap(static fn (NavigationGroup $group): array => $group->getItems())
        ->map(static fn (NavigationItem $item): string => (string) $item->getLabel())->all();
    expect($labels)->toContain('Blueprints');
});

it('upgrades the actual released Marketing Site additively and preserves an operator edit', function (): void {
    BlueprintApplier::apply(new MarketingSite100);
    $type = EntryType::query()->where('handle', 'page')->firstOrFail();
    $type->update(['name' => 'Our page']);
    $review = Blueprints::review('marketing-site', 'apply');
    Blueprints::perform('marketing-site', 'apply', $review['confirmation'], $review['token']);
    expect(Blueprint::receiptFor('marketing-site')?->version)->toBe('1.1.0')
        ->and($type->fresh()?->name)->toBe('Our page')
        ->and(Field::query()->where('entry_type_id', $type->id)->count())->toBe(3);
});

it('resumes a real interrupted apply using the same committed manifest', function (): void {
    FixtureBlueprint::$indexed = true;
    $this->schema->throwOnce(new RuntimeException('fixture sync interrupted'));
    $review = Blueprints::review('fixture', 'apply');
    expect(fn () => Blueprints::perform('fixture', 'apply', $review['confirmation'], $review['token']))
        ->toThrow(RuntimeException::class, 'fixture sync interrupted');
    $receipt = Blueprint::receiptFor('fixture');
    expect($receipt?->applied_at)->toBeNull()->and($receipt?->manifest)->not->toBeNull();
    $manifest = $receipt->manifest;
    $review = Blueprints::review('fixture', 'apply');
    Blueprints::perform('fixture', 'apply', $review['confirmation'], $review['token']);
    expect(Blueprint::receiptFor('fixture')?->applied_at)->not->toBeNull()
        ->and(Blueprint::receiptFor('fixture')?->manifest)->toBe($manifest)
        ->and(EntryType::query()->where('handle', 'dispatch')->count())->toBe(1);
});

it('can reverse this org receipt after its definition is no longer registered', function (): void {
    BlueprintApplier::apply(new FixtureBlueprint);
    app()->instance(BlueprintRegistry::class, new BlueprintRegistry);
    expect(Blueprints::lines()[0])->toMatchArray(['handle' => 'fixture', 'available' => null]);
    $review = Blueprints::review('fixture', 'reverse');
    $result = Blueprints::perform('fixture', 'reverse', $review['confirmation'], $review['token']);
    expect($result['outcome'])->toBe('reversed')
        ->and(Blueprint::receiptFor('fixture'))->toBeNull();
});

it('keeps an action bound to its registered handle despite forged action arguments', function (): void {
    $review = Blueprints::review('fixture', 'apply');
    $action = Blueprints::actionFor('fixture', 'apply')->arguments(['handle' => 'blog', 'operation' => 'reverse', 'org_id' => 999]);
    $action->data(['confirmation' => $review['confirmation'], 'review_token' => $review['token']])->call();
    expect(Blueprint::receiptFor('fixture')?->applied_at)->not->toBeNull()
        ->and(Blueprint::receiptFor('blog'))->toBeNull();
});

it('attaches a refused confirmation to its mounted modal field so the owner sees the reason', function (): void {
    $review = Blueprints::review('fixture', 'apply');
    $action = Blueprints::actionFor('fixture', 'apply')->nestingIndex(2);
    $action->data(['confirmation' => 'wrong', 'review_token' => $review['token']]);

    try {
        $action->call();
        $this->fail('A wrong confirmation was accepted.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe([
            'mountedActions.2.data.confirmation' => [__('kitsune::blueprints.review_changed')],
        ]);
    }

    expect(Blueprint::receiptFor('fixture'))->toBeNull();
});

it('adds no browser-callable public instance handler or public property', function (): void {
    $class = new ReflectionClass(Blueprints::class);
    $properties = array_filter($class->getProperties(ReflectionProperty::IS_PUBLIC),
        static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === Blueprints::class);
    $methods = array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), array_filter(
        $class->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => ! $method->isStatic() && $method->getDeclaringClass()->getName() === Blueprints::class,
    )));
    sort($methods);
    expect($properties)->toBe([])
        ->and($methods)->toBe(['content', 'getTitle', 'shouldRestrictFileUploadsToSchemaComponents']);
});

it('maps storage faults even when wrapped and does not disclose unexpected exception messages', function (): void {
    $failure = new RuntimeException('raw binding should never escape', previous: new PDOException('private database detail'));
    expect(Blueprints::refusal($failure))->toBe(__('kitsune::blueprints.storage_failure'))
        ->and(Blueprints::refusal(new LogicException('private programming detail')))->toBe(__('kitsune::blueprints.unexpected_failure'))
        ->and(Blueprints::refusal(new RuntimeException('Named content obstacle')))->toBe('Named content obstacle');
});
