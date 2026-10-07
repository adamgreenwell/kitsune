<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementSource;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Filament\Pages\Entitlements;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Settings\SiteTimezone;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\TestReader;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * The owner's entitlements page — ADR-040, entitlements' second half: who may reach it, what each row says, the filters
 * and what they bind, Comp, Revoke and History, and what it never lets out. What a browser receives is
 * `e2e/entitlements.spec.js`'s.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('acme');
    // Not UTC, so an instant shown anywhere but in the site's timezone is seen to be.
    $this->site = Site::create(['handle' => 'main', 'slug' => 'acme-main', 'name' => 'Main', 'locale' => 'en', 'settings' => ['timezone' => 'America/New_York']]);
    app(Context::class)->setSite($this->site);
    Fx::declareReaders();
    $this->reader = Fx::reader();
    $this->id = (string) $this->reader->getKey();
    $this->owner = Fx::owner('owner@acme.test');
    $this->owner->forceFill(['name' => 'Olive Owner'])->save();
    PanelTenancy::enter($this->site);

    Route::get('/test-entitlements/{tenant:slug}', static fn (): string => '')->name('filament.admin.pages.entitlements');
    app('router')->getRoutes()->refreshNameLookups();

    session()->forget('filament.notifications');
    // 12:05 UTC is 08:05 in New York on this day.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:05:00', 'UTC'));
});

afterEach(fn () => Fx::tearDown());

/**
 * The page as a request builds it: mounted, then booted — the order that pages at the configured 25 (booted first, a
 * table pages at Filament's default of 15).
 *
 * @param  array<string, mixed>  $filters
 */
function entitlementsPage(array $filters = []): Entitlements
{
    $page = app(Entitlements::class);
    $page->mountInteractsWithTable();
    $page->bootedInteractsWithTable();

    // Over the form's own state, as a request applies them: a filter the request leaves out keeps its default.
    if ($filters !== []) {
        $page->tableFilters = array_replace_recursive((array) $page->tableFilters, $filters);
    }

    return $page;
}

/** @return list<Entitlement> */
function pageEntitlementRows(Entitlements $page): array
{
    return array_values($page->getTableRecords()->items());
}

/** One row, found by its entitlement and source. */
function pageEntitlementRow(Entitlements $page, string $entitlement, string $source): Entitlement
{
    foreach (pageEntitlementRows($page) as $row) {
        if ($row->entitlement === $entitlement && $row->source === $source) {
            return $row;
        }
    }

    throw new RuntimeException("no row for {$entitlement} from {$source}");
}

/** A column's text for one row, as the cell shows it. */
function pageEntitlementCell(Entitlements $page, string $column, Entitlement $row): string
{
    $cell = $page->getTable()->getColumn($column)->record($row);

    return (string) $cell->formatState($cell->getState());
}

/** A row action, bound to its row as the table binds it. */
function pageEntitlementRowAction(Entitlements $page, string $name, Entitlement $row): Action
{
    return $page->getTable()->getAction($name)->record($row);
}

/** @return list<array<string, mixed>> */
function pageEntitlementNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

/** Nobody signed in, or a member who is not an owner — the two the page refuses. */
function pageEntitlementActAs(string $who): void
{
    $who === 'a member' ? Fx::member('member@acme.test') : Fx::nobody();
}

/** An instant as UTC wall clock, as it is stored. */
function pageEntitlementUtc(?CarbonImmutable $instant): ?string
{
    return $instant?->utc()->format('Y-m-d H:i:s');
}

/** @return list<Action> */
function pageEntitlementHeaderActions(Entitlements $page): array
{
    return (fn (): array => $this->getHeaderActions())->call($page);
}

/** The Comp modal, opened on the page and given what the owner typed; the page afterwards. */
function pageEntitlementComp(Entitlements $page, array $data): Entitlements
{
    $page->mountAction('comp');
    $page->mountedActions[0]['data'] = $data;
    $page->callMountedAction();

    return $page;
}

/** The labels of the panel's own sidebar, as `KitsunePanel` builds it for the signed-in user. @return list<string> */
function pageEntitlementSidebar(): array
{
    $builder = Closure::bind(static fn (): NavigationBuilder => self::navigation(new NavigationBuilder), null, KitsunePanel::class)();

    return collect($builder->getNavigation())
        ->flatMap(static fn (NavigationGroup $group): array => $group->getItems())
        ->map(static fn (NavigationItem $item): string => (string) $item->getLabel())
        ->values()
        ->all();
}

/** The queries a read runs. @return list<array{query: string, bindings: array<int, mixed>}> */
function pageEntitlementQueries(Closure $read): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $read();
    } finally {
        $log = DB::getQueryLog();
        DB::disableQueryLog();
    }

    return array_values($log);
}

/**
 * A row's history as its modal renders it — a table of change, when and by whom, oldest first, read from the HTML.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function pageEntitlementHistory(Entitlement $row): array
{
    $html = (string) Schema::make(app(Entitlements::class))->components(Entitlements::historyOf($row))->toHtml();

    if (! str_contains($html, '<tbody')) {
        return [];
    }

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $rows = [];

    foreach ((new DOMXPath($document))->query('//tbody/tr') as $tr) {
        $cells = [];

        // The cell's content; its term repeats the column header for a screen reader alone.
        foreach ((new DOMXPath($document))->query('./td', $tr) as $td) {
            $content = (new DOMXPath($document))->query('.//*[@role="definition"]', $td)->item(0) ?? $td;
            $cells[] = trim((string) preg_replace('/\s+/', ' ', $content->textContent));
        }

        $rows[] = $cells;
    }

    return $rows;
}

/** A class an audit row can name, which says whether anything built it. */
final class EntitlementPageForgedActor
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }
}

/* 1. Who may. */
it('opens to an owner of the org in context and to nobody else', function (): void {
    expect(Entitlements::canAccess())->toBeTrue();

    Fx::member('member@acme.test');
    expect(Entitlements::canAccess())->toBeFalse();

    Fx::nobody();
    expect(Entitlements::canAccess())->toBeFalse();

    // A reader's own session rides along on any route; it opens nothing here.
    Fx::signIn($this->reader);
    expect(Entitlements::canAccess())->toBeFalse();
});

/* 2. The link — asked of the sidebar the panel builds, not of the helper alone. */
it('is linked from the sidebar only where the URL opens and a usable reader guard is declared', function (): void {
    $sidebar = pageEntitlementSidebar();

    expect(Entitlements::belongsInNavigation())->toBeTrue()
        ->and($sidebar)->toContain('Roles', 'Entitlements')
        // After the owner's other screens, never ahead of them.
        ->and(array_search('Entitlements', $sidebar, true))->toBeGreaterThan(array_search('Roles', $sidebar, true));

    // Once warm, the five sidebar builds of a request cost nothing more.
    expect(pageEntitlementQueries(static fn () => Entitlements::belongsInNavigation()))->toBe([]);

    foreach ([
        'no guard declared' => static fn () => config([ReaderGuard::CONFIG => null]),
        'the panel\'s own guard' => static fn () => config([ReaderGuard::CONFIG => 'web']),
        'a model scoped by nothing' => static fn () => Fx::declareReaders(TestUser::class),
    ] as $case => $declare) {
        $declare();
        Fx::forget();

        // Declaring a guard forgets the built ones, `web`'s user with them.
        Auth::guard('web')->setUser($this->owner);
        Fx::forget();

        expect(Entitlements::belongsInNavigation())->toBeFalse($case)
            ->and(pageEntitlementSidebar())->not->toContain('Entitlements')
            ->and(pageEntitlementSidebar())->toContain('Roles')
            ->and(Entitlements::canAccess())->toBeTrue($case);

        Fx::declareReaders();
        Auth::guard('web')->setUser($this->owner);
        Fx::forget();
    }

    expect(Entitlements::belongsInNavigation())->toBeTrue();

    Fx::member('member@acme.test');
    expect(Entitlements::belongsInNavigation())->toBeFalse()
        ->and(pageEntitlementSidebar())->not->toContain('Entitlements');
});

it('is a page of the panel at its own slug, with no navigation of Filament\'s', function (): void {
    $panel = KitsunePanel::apply(Panel::make())->id('admin');

    expect($panel->getPages())->toContain(Entitlements::class)
        ->and(Entitlements::getSlug())->toBe('entitlements')
        ->and(Entitlements::shouldRegisterNavigation())->toBeFalse();
});

/* 3. With no usable reader guard. */
it('says why, and lists and offers nothing, without a usable reader guard', function (): void {
    Fx::writer()->comp($this->id, 'course.advanced-php', null);
    config([ReaderGuard::CONFIG => null]);
    Fx::forget();

    $components = null;
    $queries = pageEntitlementQueries(static function () use (&$components): void {
        $page = entitlementsPage();
        $components = $page->content(Schema::make($page))->getComponents();
        expect(pageEntitlementHeaderActions($page))->toBe([]);
    });

    expect($components)->toHaveCount(1)
        ->and($components[0])->toBeInstanceOf(EmptyState::class)
        ->and((string) $components[0]->getDescription())->toBe('Nothing can be listed or given here, because this installation declares no reader guard (kitsune.readers.guard). Until whoever runs this installation fixes it, every check answers no.')
        ->and(array_filter($components, static fn ($component): bool => $component instanceof EmbeddedTable))->toBe([])
        ->and(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'entitlements')))->toBe([]);

    // And a read a browser calls by name lists nothing either: Livewire hands its value back.
    $page = entitlementsPage();
    $id = (string) Entitlement::query()->value('id');

    expect(pageEntitlementRows($page))->toBe([])
        ->and($page->getAllTableRecordsCount())->toBe(0)
        ->and($page->getTableRecord($id))->toBeNull();

    // With a guard: the table, and Comp.
    Fx::declareReaders();
    $page = entitlementsPage();
    $components = $page->content(Schema::make($page))->getComponents();

    expect($components[2])->toBeInstanceOf(EmbeddedTable::class)
        ->and((string) $components[1]->getContent())->toBe('A reader holds an entitlement while any of its sources is live.')
        ->and(array_map(static fn (Action $action): string => $action->getName(), pageEntitlementHeaderActions($page)))->toBe(['comp']);
});

/* 4. The rows. */
it('lists one row per source, as the check reads it, in the site\'s timezone', function (): void {
    Fx::writer()->grant($this->id, 'course.a', 'test.order:1', null);
    Fx::writer()->comp($this->id, 'course.a', CarbonImmutable::parse('2026-12-01 17:00:00', 'UTC'));
    Fx::plant($this->site, $this->id, 'course.b', 'test.order:2', expires: CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'));
    Fx::plant($this->site, $this->id, 'course.c', 'test.order:3', revoked: CarbonImmutable::parse('2026-10-07 11:00:00', 'UTC'));

    $page = entitlementsPage();
    $producer = pageEntitlementRow($page, 'course.a', 'test.order:1');
    $comp = pageEntitlementRow($page, 'course.a', EntitlementSource::COMP);
    $lapsed = pageEntitlementRow($page, 'course.b', 'test.order:2');
    $revoked = pageEntitlementRow($page, 'course.c', 'test.order:3');
    $state = $page->getTable()->getColumn('state');

    expect(pageEntitlementRows($page))->toHaveCount(4)
        ->and(pageEntitlementCell($page, 'reader_id', $producer))->toBe($this->id)
        ->and(pageEntitlementCell($page, 'source', $producer))->toBe('test.order:1')
        ->and(pageEntitlementCell($page, 'source', $comp))->toBe('Comp')
        ->and(array_map(static fn (Entitlement $row): string => pageEntitlementCell($page, 'state', $row), [$producer, $comp, $lapsed, $revoked]))
        ->toBe(['Live', 'Live', 'Lapsed', 'Revoked'])
        ->and(array_map(static fn (Entitlement $row): ?string => $state->record($row)->getColor($state->record($row)->getState()), [$producer, $lapsed, $revoked]))
        ->toBe(['success', 'gray', 'danger'])
        // In ordinary text, not Filament's placeholder, which is grey below WCAG AA's contrast.
        ->and(pageEntitlementCell($page, 'expires_at', $producer))->toBe('No end')
        ->and($page->getTable()->getColumn('expires_at')->getPlaceholder())->toBeNull()
        ->and(pageEntitlementCell($page, 'expires_at', $comp))->toBe('Dec 1, 2026 12:00:00')
        ->and(pageEntitlementCell($page, 'changed_at', $producer))->toBe('Oct 7, 2026 08:05:00')
        // The newest change first: every row here changed at one instant, so the key breaks the tie.
        ->and(pageEntitlementRows($page)[0]->getKey())->toBe($revoked->getKey());
});

/* 5. Isolation, from the attacker's side. */
it('shows this site\'s rows and no other, whatever key a client names', function (): void {
    Fx::writer()->comp($this->id, 'course.a', null);
    $own = (int) Entitlement::query()->value('id');

    $sibling = Site::create(['handle' => 'other', 'slug' => 'acme-other', 'name' => 'Other', 'locale' => 'en']);
    $siblingRow = Fx::plant($sibling, $this->id, 'course.a', 'test.order:9');

    // Filed under another org with this site's id — a write below Eloquent; `SiteScope`'s site branch compares no org.
    $rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    $misfiled = Fx::plant($this->site, $this->id, 'course.z', 'test.order:8', orgId: (int) $rival->getKey());

    $page = entitlementsPage();

    expect(array_map(static fn (Entitlement $row): int => (int) $row->getKey(), pageEntitlementRows($page)))->toBe([$own])
        ->and($page->getTableRecord((string) $siblingRow))->toBeNull()
        ->and($page->getTableRecord((string) $misfiled))->toBeNull()
        ->and($page->getTableRecord((string) $own)?->getKey())->toBe($own);

    // Another org's own site, its owner signed in: none of this one's rows, the misfiled one included.
    app(Context::class)->setOrg($rival);
    $rivalSite = Site::create(['handle' => 'main', 'slug' => 'rival-main', 'name' => 'Main', 'locale' => 'en']);
    PanelTenancy::moveTo($rivalSite);
    Fx::owner('owner@rival.test');

    expect(pageEntitlementRows(entitlementsPage()))->toBe([]);
});

/* 6. Filters. */
it('filters exactly, binding only what the one encoding accepts', function (): void {
    Fx::plant($this->site, '7', 'course.a', 'test.order:1');
    Fx::plant($this->site, '7', 'course.b', EntitlementSource::COMP);
    Fx::plant($this->site, '8', 'course.a', 'test.order:1');

    $found = static fn (array $filters): array => array_map(
        static fn (Entitlement $row): string => $row->reader_id.' '.$row->entitlement.' '.$row->source,
        pageEntitlementRows(entitlementsPage($filters)),
    );

    expect($found(['reader' => ['value' => '7']]))->toEqualCanonicalizing(['7 course.a test.order:1', '7 course.b core.comp'])
        ->and($found(['entitlement' => ['value' => 'course.a']]))->toEqualCanonicalizing(['7 course.a test.order:1', '8 course.a test.order:1'])
        ->and($found(['source' => ['value' => 'test.order:1']]))->toEqualCanonicalizing(['7 course.a test.order:1', '8 course.a test.order:1'])
        ->and($found(['comps' => ['isActive' => true]]))->toBe(['7 course.b core.comp'])
        ->and($found(['reader' => ['value' => '7'], 'entitlement' => ['value' => 'course.a']]))->toBe(['7 course.a test.order:1']);

    // What no encoding accepts finds nothing, and binds nothing: one count, of this site and org alone.
    foreach ([
        'a reader the integer key would read as 7' => ['reader' => ['value' => '007']],
        'a reader with a space' => ['reader' => ['value' => '7 ']],
        'a malformed name' => ['entitlement' => ['value' => 'Course A']],
        'a malformed source' => ['source' => ['value' => 'test order 1']],
    ] as $case => $filters) {
        $page = entitlementsPage($filters);
        $queries = pageEntitlementQueries(static fn () => expect(pageEntitlementRows($page))->toBe([], $case));
        $bindings = array_merge(...array_map(static fn (array $query): array => $query['bindings'], $queries));

        expect($queries)->toHaveCount(1, $case)
            ->and($queries[0]['query'])->toContain('0 = 1')
            ->and(array_diff(array_map('strval', $bindings), [(string) $this->site->getKey(), (string) $this->org->getKey()]))->toBe([], $case);
    }
});

it('agrees with the badge about which rows are live, lapsed and revoked, at an end exactly', function (): void {
    $end = CarbonImmutable::parse('2026-10-07 12:05:00', 'UTC');

    foreach ([
        ['test.r:1', null, null],
        ['test.r:2', $end, null],
        ['test.r:3', $end->subSecond(), null],
        ['test.r:4', $end->addSecond(), null],
        ['test.r:5', null, $end->subDay()],
        ['test.r:6', $end->addYear(), $end->subDay()],
        ['test.r:7', $end->subYear(), $end->subDay()],
    ] as [$source, $expires, $revoked]) {
        Fx::plant($this->site, $this->id, 'course.a', $source, expires: $expires, revoked: $revoked);
    }

    foreach ([$end->subSecond(), $end, $end->addSecond()] as $instant) {
        $this->travelTo($instant);
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $all = Entitlement::query()->get();

        foreach (['live', 'lapsed', 'revoked'] as $state) {
            $expected = $all->filter(static fn (Entitlement $row): bool => Entitlements::stateOf($row, $now) === $state)
                ->map(static fn (Entitlement $row): string => $row->source)->sort()->values()->all();
            $filtered = collect(pageEntitlementRows(entitlementsPage(['state' => ['value' => $state]])))
                ->map(static fn (Entitlement $row): string => $row->source)->sort()->values()->all();

            expect($filtered)->toBe($expected, "{$state} at {$instant}");
        }
    }

    // Not vacuous: at the end exactly, the row ending then has lapsed and the one a second later is live.
    $this->travelTo($end);
    $now = CarbonImmutable::now('UTC');

    expect(Entitlements::stateOf(Entitlement::query()->where('source', 'test.r:2')->sole(), $now))->toBe('lapsed')
        ->and(Entitlements::stateOf(Entitlement::query()->where('source', 'test.r:4')->sole(), $now))->toBe('live');
});

/* 7. The reader's id stays out of URLs and the session. */
it('keeps the reader\'s id out of every address and out of the session', function (): void {
    Fx::plant($this->site, '81234', 'course.a');

    $class = new ReflectionClass(Entitlements::class);
    $urlBound = array_values(array_map(static fn (ReflectionProperty $property): string => $property->getName(), array_filter(
        $class->getProperties(),
        static fn (ReflectionProperty $property): bool => $property->getAttributes(Url::class) !== [],
    )));
    sort($urlBound);

    // Built under a host-wide default that would persist every table's filters, then filtered as a browser applies it.
    $page = Table::configureUsing(static fn (Table $table) => $table->persistFiltersInSession(), during: static function (): Entitlements {
        $page = entitlementsPage();
        $page->tableDeferredFilters = array_replace_recursive((array) $page->tableDeferredFilters, ['reader' => ['value' => '81234']]);
        $page->applyTableFilters();

        return $page;
    });

    // Filament's own, which mount an action from a link and carry no filter; nothing of the table's.
    expect($urlBound)->toBe(['defaultAction', 'defaultActionArguments', 'defaultActionContext', 'defaultTableAction', 'defaultTableActionArguments', 'defaultTableActionRecord'])
        ->and($page->getTable()->persistsFiltersInSession())->toBeFalse()
        ->and(pageEntitlementRows($page))->toHaveCount(1)
        ->and(json_encode(session()->all()))->not->toContain('81234');
});

/* 8. Comp's form. */
it('builds Comp with the writer as its only validation, and an end that is always said', function (): void {
    $page = entitlementsPage();
    $page->mountAction('comp');
    $schema = (fn (): ?Schema => $this->getMountedActionSchema())->call($page);
    $fields = [];

    foreach ($schema->getFlatComponents(withHidden: true) as $component) {
        if (method_exists($component, 'getName')) {
            $fields[$component->getName()] = $component;
        }
    }

    expect(array_keys($fields))->toBe(['reader', 'entitlement', 'ends', 'until'])
        // A browser silently truncates a paste to a `maxlength`, where the writer refuses instead.
        ->and($fields['reader']->getMaxLength())->toBeNull()
        // Adam, answer 2: "no end" is chosen, never the reading of a forgotten date.
        ->and($fields['ends']->getDefaultState())->toBeNull()
        ->and($page->mountedActions[0]['data']['ends'])->toBeNull()
        ->and($fields['until']->isHidden())->toBeTrue();

    foreach ($fields as $name => $field) {
        foreach ($field->getValidationRules() as $rule) {
            expect($rule)->not->toBeInstanceOf(Exists::class, $name)
                ->and($rule)->not->toBeInstanceOf(Unique::class, $name);

            if (is_string($rule)) {
                expect($rule)->not->toStartWith('exists')->not->toStartWith('unique')->not->toStartWith('regex')->not->toStartWith('max');
            }
        }
    }

    $page->mountedActions[0]['data']['ends'] = 'on';
    expect((fn (): ?Schema => $this->getMountedActionSchema())->call($page)->getComponent('until', withHidden: true)?->isHidden())->toBeFalse();

    $page->mountedActions[0]['data']['ends'] = 'none';
    expect((fn (): ?Schema => $this->getMountedActionSchema())->call($page)->getComponent('until', withHidden: true)?->isHidden())->toBeTrue();

    // Built with every action made transactional, as a host's `databaseTransactions()` panel makes them.
    $comp = Action::configureUsing(static fn (Action $action) => $action->databaseTransaction(), during: static fn (): Action => Entitlements::compAction());

    expect($comp->hasDatabaseTransactions())->toBeFalse()
        ->and($comp->isAuthorized())->toBeTrue();

    Fx::member('member@acme.test');
    expect(Entitlements::compAction()->isAuthorized())->toBeFalse();
});

it('refuses to give a comp with no end chosen, and writes nothing', function (): void {
    try {
        pageEntitlementComp(entitlementsPage(), ['reader' => $this->id, 'entitlement' => 'course.a', 'ends' => null]);
        $this->fail('no end was accepted');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toHaveKey('mountedActions.0.data.ends');
    }

    // "On a date" with no date is not "no end": the form refuses it, rather than give the comp forever.
    try {
        pageEntitlementComp(entitlementsPage(), ['reader' => $this->id, 'entitlement' => 'course.a', 'ends' => 'on', 'until' => null]);
        $this->fail('a blank date was accepted');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toHaveKey('mountedActions.0.data.until');
    }

    expect(Entitlement::query()->count())->toBe(0);
});

/* 9. Comp's outcomes. */
it('comps as the owner, in its own source, and says what happened', function (): void {
    $titles = function (): array {
        return array_map(static fn (array $notice): string => (string) $notice['title'], pageEntitlementNotices());
    };

    expect(Entitlements::giveComp($this->id, 'course.a', '2026-11-01 08:00:00'))->toBeTrue()
        ->and(Entitlements::giveComp($this->id, 'course.a', '2026-12-01 08:00:00'))->toBeTrue()
        ->and(Entitlements::giveComp($this->id, 'course.a', '2026-11-15 08:00:00'))->toBeTrue();

    Fx::writer()->revoke($this->id, 'course.a', EntitlementSource::COMP);

    expect(Entitlements::giveComp($this->id, 'course.a', null))->toBeTrue()
        ->and($titles())->toBe([
            'Given.',
            'Extended.',
            'Already comped for as long or longer — nothing changed.',
            'Given again — this comp had been revoked.',
        ])
        ->and(Entitlement::query()->pluck('source')->all())->toBe([EntitlementSource::COMP]);

    $actors = AuditLog::query()->where('action', 'like', 'entitlement.%')->get(['actor_type', 'actor_id']);

    // Granted, extended, revoked and reinstated: a comp that changes nothing records nothing.
    expect($actors)->toHaveCount(4)
        ->and($actors->every(fn (AuditLog $record): bool => $record->actor_type === $this->owner->getMorphClass() && $record->actor_id === (string) $this->owner->getKey()))->toBeTrue();
});

it('reads the picker\'s end in the site\'s timezone, through the application\'s, never as UTC', function (): void {
    config(['app.timezone' => 'America/Chicago']);

    // 09:00 entered in New York is 13:00 UTC on 1 November and 14:00 UTC on 1 December.
    $page = pageEntitlementComp(entitlementsPage(), ['reader' => $this->id, 'entitlement' => 'course.a', 'ends' => 'on', 'until' => '2026-12-01 09:00:00']);

    expect($page->mountedActions)->toBe([])
        ->and(pageEntitlementUtc(Entitlement::query()->sole()->expires_at))->toBe('2026-12-01 14:00:00')
        ->and(pageEntitlementNotices()[0]['title'])->toBe('Given.');

    // And the handler alone reads the application's wall clock.
    Entitlements::giveComp($this->id, 'course.b', '2026-12-01 08:00:00');

    expect(pageEntitlementUtc(Entitlement::query()->where('entitlement', 'course.b')->sole()->expires_at))->toBe('2026-12-01 14:00:00');
});

/* 10. A refusal. */
it('shows a refusal in the writer\'s own words, escaped, and keeps the form with what was typed', function (): void {
    $typed = ['reader' => $this->id, 'entitlement' => 'Course A', 'ends' => 'none'];
    $page = pageEntitlementComp(entitlementsPage(), $typed);
    $notices = pageEntitlementNotices();

    // Adam, answer 3: the writer's refusal is this form's validation, and validation keeps the form.
    expect($page->mountedActions)->toHaveCount(1)
        ->and($page->mountedActions[0]['name'])->toBe('comp')
        ->and($page->mountedActions[0]['data'])->toMatchArray($typed)
        ->and($notices)->toHaveCount(1)
        ->and($notices[0]['status'])->toBe('danger')
        ->and($notices[0]['duration'])->toBe('persistent')
        ->and($notices[0]['title'])->toBe('Not given')
        ->and($notices[0]['body'])->toBe(e('Refusing to give an entitlement by hand: that is not an entitlement\'s name. A name is two lower-case words joined by a dot — course.advanced-php, download.whitepaper-2026 — of letters, digits and single hyphens, the first word starting with a letter, at most 100 characters (ADR-040). It is not repeated here. Nothing was written.'))
        ->and($notices[0]['body'])->toContain('&#039;')
        ->and(Entitlement::query()->count())->toBe(0);

    // Another org's reader, with an id worth looking for.
    $rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    TestReader::withoutScopeBecause('a reader of another org, for the refusal', static fn ($query) => $query->create(['id' => 81234, 'org_id' => $rival->getKey(), 'email' => 'r@rival.test']));
    session()->forget('filament.notifications');

    expect(Entitlements::giveComp('81234', 'course.a', null))->toBeFalse()
        ->and(pageEntitlementNotices()[0]['body'])->toContain('no reader with that identifier belongs to this organisation')
        ->and(json_encode(pageEntitlementNotices()))->not->toContain('81234')
        ->and(Entitlement::query()->count())->toBe(0);
});

/* 11. Nobody, and a member. */
it('refuses a comp from nobody and from a member with a 403, writing nothing', function (string $who): void {
    pageEntitlementActAs($who);

    try {
        Entitlements::giveComp($this->id, 'course.a', null);
        $this->fail('no 403');
    } catch (HttpException $refused) {
        expect($refused->getStatusCode())->toBe(403);
    }

    expect(Entitlement::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'entitlement.%')->count())->toBe(0)
        ->and(pageEntitlementNotices())->toBe([]);
})->with(['nobody signed in', 'a member']);

/* 12. Revoke's offer. */
it('offers Revoke on live and lapsed sources only, in words that differ for a comp', function (): void {
    Fx::writer()->grant($this->id, 'course.a', 'test.order:1', null);
    Fx::writer()->comp($this->id, 'course.a', null);
    Fx::plant($this->site, $this->id, 'course.b', 'test.order:2', expires: CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'));
    Fx::plant($this->site, $this->id, 'course.c', 'test.order:3', revoked: CarbonImmutable::parse('2026-10-07 11:00:00', 'UTC'));

    $page = entitlementsPage();
    $revoke = static fn (string $entitlement, string $source): Action => pageEntitlementRowAction($page, 'revoke', pageEntitlementRow($page, $entitlement, $source));

    expect($revoke('course.a', 'test.order:1')->isVisible())->toBeTrue()
        ->and($revoke('course.a', EntitlementSource::COMP)->isVisible())->toBeTrue()
        // Lapsed too: revoked, a later grant of the same source cannot extend it.
        ->and($revoke('course.b', 'test.order:2')->isVisible())->toBeTrue()
        ->and($revoke('course.c', 'test.order:3')->isVisible())->toBeFalse()
        ->and($revoke('course.a', EntitlementSource::COMP)->getModalHeading())->toBe('Revoke this comp?')
        ->and((string) $revoke('course.a', EntitlementSource::COMP)->getModalDescription())->toBe('The reader keeps any access another source gives. You can comp again later.')
        ->and($revoke('course.a', 'test.order:1')->getModalHeading())->toBe('Revoke test.order:1?')
        ->and((string) $revoke('course.a', 'test.order:1')->getModalDescription())->toStartWith('It stays revoked: if it is granted again')
        ->and($revoke('course.a', 'test.order:1')->isConfirmationRequired())->toBeTrue()
        ->and($revoke('course.a', 'test.order:1')->isAuthorized())->toBeTrue();

    $transactional = Action::configureUsing(static fn (Action $action) => $action->databaseTransaction(), during: static fn (): Action => Entitlements::revokeAction());
    expect($transactional->hasDatabaseTransactions())->toBeFalse();

    Fx::member('member@acme.test');
    expect(Entitlements::revokeAction()->isAuthorized())->toBeFalse()
        ->and(Entitlements::historyAction()->isAuthorized())->toBeFalse();
});

/* 13. Revoke's effect. */
it('revokes the row\'s own source and nothing else, and says so', function (): void {
    foreach (['course.a', 'course.b'] as $entitlement) {
        Fx::writer()->grant($this->id, $entitlement, 'test.order:1', null);
        Fx::writer()->comp($this->id, $entitlement, null);
    }

    $page = entitlementsPage();
    Entitlements::revokeRow(pageEntitlementRow($page, 'course.a', 'test.order:1'));
    Entitlements::revokeRow(pageEntitlementRow($page, 'course.b', EntitlementSource::COMP));

    $state = static fn (bool $revoked): array => Entitlement::query()->when($revoked, static fn ($query) => $query->whereNotNull('revoked_at'), static fn ($query) => $query->whereNull('revoked_at'))
        ->get()->map(static fn (Entitlement $row): string => $row->entitlement.' '.$row->source)->sort()->values()->all();

    // The other source of each still gives access.
    expect($state(true))->toBe(['course.a test.order:1', 'course.b core.comp'])
        ->and($state(false))->toBe(['course.a core.comp', 'course.b test.order:1'])
        ->and(array_column(pageEntitlementNotices(), 'title'))->toBe(['Revoked.', 'Revoked.']);

    // A second revoke of the same, from a page built before the first: nothing to say but that.
    $audits = AuditLog::query()->count();
    session()->forget('filament.notifications');
    Entitlements::revokeRow(Entitlement::query()->where('entitlement', 'course.a')->where('source', 'test.order:1')->sole());

    expect(pageEntitlementNotices()[0]['title'])->toBe('Nothing was revoked: it had already been revoked.')
        ->and(pageEntitlementNotices()[0]['status'])->toBe('info')
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('refuses a revoke from nobody and from a member with a 403, the row still live', function (string $who): void {
    Fx::writer()->comp($this->id, 'course.a', null);
    $row = pageEntitlementRow(entitlementsPage(), 'course.a', EntitlementSource::COMP);

    // ⚠️ THE LOAD-BEARING CASE: with nobody signed in the writer would trust this call as the system's.
    pageEntitlementActAs($who);

    try {
        Entitlements::revokeRow($row);
        $this->fail('no 403');
    } catch (HttpException $refused) {
        expect($refused->getStatusCode())->toBe(403);
    }

    expect(Entitlement::query()->sole()->revoked_at)->toBeNull();
})->with(['nobody signed in', 'a member']);

it('shows the writer\'s refusal of a revoke, escaped, rather than an error', function (): void {
    Fx::writer()->comp($this->id, 'course.a', null);
    $row = pageEntitlementRow(entitlementsPage(), 'course.a', EntitlementSource::COMP);

    // The guard removed between the render and the click.
    config([ReaderGuard::CONFIG => null]);
    Fx::forget();
    Entitlements::revokeRow($row);

    expect(pageEntitlementNotices()[0])->toMatchArray(['status' => 'danger', 'duration' => 'persistent', 'title' => 'Not revoked'])
        ->and(pageEntitlementNotices()[0]['body'])->toBe(e('Refusing to revoke an entitlement: this installation declares no reader guard (kitsune.readers.guard). Without a usable reader guard core cannot tell one reader from another, and it never guesses (ADR-037). Nothing was written.'))
        ->and(Entitlement::query()->sole()->revoked_at)->toBeNull();

    // A row whose reader no longer fits the integer key — planted below Eloquent, as a migration might leave one.
    Fx::declareReaders();
    Auth::guard('web')->setUser($this->owner);
    Fx::forget();
    session()->forget('filament.notifications');
    Fx::plant($this->site, 'x"y', 'course.b', 'test.order:1');
    Entitlements::revokeRow(pageEntitlementRow(entitlementsPage(), 'course.b', 'test.order:1'));

    expect(pageEntitlementNotices()[0]['title'])->toBe('Not revoked')
        ->and(pageEntitlementNotices()[0]['body'])->toContain('that is not an identifier a reader can have here')
        ->and(json_encode(pageEntitlementNotices()))->not->toContain('x\"y')->not->toContain('x&quot;y');
});

/* 14. History. */
it('tells a row\'s history, naming people only through the org\'s own members', function (): void {
    Fx::writer()->comp($this->id, 'course.a', null);
    Fx::writer()->revoke($this->id, 'course.a', EntitlementSource::COMP);
    Fx::writer()->comp($this->id, 'course.a', null);

    // A producer's grant, with nobody signed in.
    Fx::nobody();
    Fx::writer()->grant($this->id, 'course.a', 'test.order:1', null);
    Auth::guard('web')->setUser($this->owner);
    Fx::forget();

    $page = entitlementsPage();
    $comp = pageEntitlementRow($page, 'course.a', EntitlementSource::COMP);
    $producer = pageEntitlementRow($page, 'course.a', 'test.order:1');

    // When, in the site's timezone: 12:05 UTC is 08:05 in New York.
    expect(pageEntitlementHistory($comp))->toBe([
        ['Granted', 'Oct 7, 2026 08:05:00', 'Olive Owner'],
        ['Revoked', 'Oct 7, 2026 08:05:00', 'Olive Owner'],
        ['Given again', 'Oct 7, 2026 08:05:00', 'Olive Owner'],
    ])
        ->and(pageEntitlementHistory($producer))->toBe([['Granted', 'Oct 7, 2026 08:05:00', 'the system']])
        ->and(pageEntitlementRowAction($page, 'history', $comp)->getModalHeading())->toBe('course.a from Comp')
        ->and(pageEntitlementRowAction($page, 'history', $producer)->getModalHeading())->toBe('course.a from test.order:1');

    // One query for the rows, one for the people — or none for people when only the system acted.
    Entitlements::canAccess();
    expect(pageEntitlementQueries(static fn () => Entitlements::historyOf($comp)))->toHaveCount(2)
        ->and(pageEntitlementQueries(static fn () => Entitlements::historyOf($producer)))->toHaveCount(1);

    // A second owner comps, then leaves the org: not found, so not named.
    $second = Fx::owner('second@acme.test');
    $second->forceFill(['name' => 'Sam Second'])->save();
    Fx::writer()->comp($this->id, 'course.b', null);
    DB::table('org_user')->where('user_id', $second->getKey())->delete();
    Auth::guard('web')->setUser($this->owner);
    Fx::forget();

    $left = pageEntitlementRow(entitlementsPage(), 'course.b', EntitlementSource::COMP);
    expect(pageEntitlementHistory($left))->toBe([['Granted', 'Oct 7, 2026 08:05:00', 'someone no longer in this organisation']]);

    // A row naming a class of its own: named as one, and the class never built.
    $forged = 'Kitsune\\Nowhere\\ForgedActor';
    // `created_at` set to itself: MariaDB before 10.10 gives a table's first TIMESTAMP column ON UPDATE CURRENT_TIMESTAMP,
    // which would move the record to the real clock (CI's 10.6).
    AuditLog::query()->for($left)->toBase()->update(['actor_type' => $forged, 'created_at' => DB::raw('created_at')]);

    expect(pageEntitlementHistory($left))->toBe([['Granted', 'Oct 7, 2026 08:05:00', 'an account of another kind']])
        ->and(class_exists($forged, false))->toBeFalse();

    // And one naming a class that exists, which counts its constructions: named the same way, and never built.
    AuditLog::query()->for($left)->toBase()->update(['actor_type' => EntitlementPageForgedActor::class, 'actor_id' => '1', 'created_at' => DB::raw('created_at')]);

    expect(pageEntitlementHistory($left))->toBe([['Granted', 'Oct 7, 2026 08:05:00', 'an account of another kind']])
        ->and(EntitlementPageForgedActor::$built)->toBe(0);
});

it('tells each change by its own hand and at its own time, an extension included', function (): void {
    // The system grants, an hour later the owner extends it by hand, and a day later revokes it through the page.
    Fx::nobody();
    Fx::writer()->grant($this->id, 'course.a', 'test.order:1', CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'));
    Auth::guard('web')->setUser($this->owner);
    Fx::forget();

    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:05:00', 'UTC'));
    Fx::writer()->grant($this->id, 'course.a', 'test.order:1', CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC'));

    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:05:00', 'UTC'));
    Entitlements::revokeRow(pageEntitlementRow(entitlementsPage(), 'course.a', 'test.order:1'));

    expect(pageEntitlementHistory(Entitlement::query()->sole()))->toBe([
        ['Granted', 'Oct 7, 2026 08:05:00', 'the system'],
        ['Extended', 'Oct 7, 2026 09:05:00', 'Olive Owner'],
        ['Revoked', 'Oct 8, 2026 08:05:00', 'Olive Owner'],
    ]);
});

it('says when nothing is recorded for a source, and names an action it does not know as stored', function (): void {
    Fx::plant($this->site, $this->id, 'course.a', 'test.order:1');
    $row = pageEntitlementRow(entitlementsPage(), 'course.a', 'test.order:1');
    $components = Entitlements::historyOf($row);

    expect($components)->toHaveCount(1)
        ->and($components[0])->toBeInstanceOf(Text::class)
        ->and((string) $components[0]->getContent())->toBe('Nothing is recorded for this source.')
        ->and(Entitlements::actionLabel(EntitlementWriter::ERASED))->toBe('Erased')
        ->and(Entitlements::actionLabel('entitlement.<b>'))->toBe('entitlement.<b>');
});

it('refuses History to nobody and to a member with a 403', function (string $who): void {
    Fx::writer()->comp($this->id, 'course.a', null);
    $row = Entitlement::query()->sole();
    pageEntitlementActAs($who);

    try {
        Entitlements::historyOf($row);
        $this->fail('no 403');
    } catch (HttpException $refused) {
        expect($refused->getStatusCode())->toBe(403);
    }
})->with(['nobody signed in', 'a member']);

/* 15. Cost. */
it('costs a fixed count of queries, whatever is stored', function (): void {
    for ($n = 1; $n <= 30; $n++) {
        Fx::plant(
            $this->site,
            (string) $n,
            'course.c'.($n % 3),
            ['test.order:'.$n, EntitlementSource::COMP, 'import.legacy:'.$n][$n % 3],
            expires: $n % 5 === 0 ? CarbonImmutable::parse('2026-01-01', 'UTC') : null,
            revoked: $n % 7 === 0 ? CarbonImmutable::parse('2026-02-01', 'UTC') : null,
        );
    }

    Entitlements::canAccess();
    SiteTimezone::current();
    $page = entitlementsPage();
    $columns = ['reader_id', 'entitlement', 'source', 'state', 'expires_at', 'changed_at'];
    $shown = 0;

    $queries = pageEntitlementQueries(static function () use ($page, $columns, &$shown): void {
        foreach (pageEntitlementRows($page) as $row) {
            $shown++;

            foreach ($columns as $column) {
                pageEntitlementCell($page, $column, $row);
            }
        }
    });

    // The count and the page, and nothing per row — each leading with the site and the org as plain equalities, the
    // index prefix every engine plans from (without them SQLite scans the table on `SiteScope`'s OR, ADR-040 M3).
    expect($shown)->toBe(25)
        ->and($queries)->toHaveCount(2);

    foreach ($queries as $query) {
        expect(str_replace(['"', '`'], '', $query['query']))->toContain('where entitlements.site_id = ? and entitlements.org_id = ?');
    }
});

/* 16. A failed read. */
it('never lets a failed read carry the reader\'s id or the name it asked about', function (): void {
    Fx::plant($this->site, '81234', 'course.addiction-recovery');
    $page = entitlementsPage(['reader' => ['value' => '81234'], 'entitlement' => ['value' => 'course.addiction-recovery']]);

    // A failure as the database reports one: its message interpolates the bindings. And inside a transaction, as a host's
    // `databaseTransactions()` panel runs an action, Laravel's deadlock — a PDOException carrying that message.
    $failing = 'refused';
    DB::listen(static function (QueryExecuted $query) use (&$failing): void {
        if (str_contains($query->sql, 'entitlements')) {
            $driver = new PDOException('SQLSTATE[HY000]: General error: 1 disk I/O error');
            $driver->errorInfo = ['HY000', 1, 'disk I/O error'];
            $refused = new QueryException($query->connectionName, $query->sql, $query->bindings, $driver);

            throw $failing === 'refused' ? $refused : new DeadlockException('SQLSTATE[40001]: '.$refused->getMessage(), 40001, $refused);
        }
    });

    $previous = [ini_set('zend.exception_ignore_args', '0'), ini_set('zend.exception_string_param_max_len', '15')];

    try {
        foreach (['refused' => 'HY000', 'deadlocked' => '40001'] as $failing => $state) {
            foreach ([
                'the page' => static fn () => $page->getTableRecords(),
                'a row' => static fn () => $page->getTableRecord('1'),
                // Nothing on the page calls these four; a browser can, by name.
                'the count' => static fn () => $page->getAllTableRecordsCount(),
                'every key' => static fn () => $page->getAllSelectableTableRecordKeys(),
                'the selectable count' => static fn () => $page->getAllSelectableTableRecordsCount(),
                'the selection' => static fn () => $page->getSelectedTableRecords(),
            ] as $read => $fn) {
                try {
                    $fn();
                    $this->fail("{$read} did not fail");
                } catch (RuntimeException $refused) {
                    expect($refused)->not->toBeInstanceOf(QueryException::class, $read)
                        ->and($refused)->not->toBeInstanceOf(PDOException::class, $read)
                        ->and($refused->getPrevious())->toBeNull()
                        ->and($refused->getMessage())->toBe("The entitlements could not be read: the database refused (SQLSTATE {$state}). Its message is not repeated, because it can carry a reader's identifier.")
                        ->and($refused->getTraceAsString())->not->toContain('81234')->not->toContain('addiction');
                }
            }
        }
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous[0]);
        ini_set('zend.exception_string_param_max_len', (string) $previous[1]);
    }
});

it('says nothing has been given only when no filter narrows the rows', function (): void {
    $heading = static fn (Entitlements $page): string => (string) $page->getTable()->getEmptyStateHeading();
    $description = static fn (Entitlements $page): ?string => $page->getTable()->getEmptyStateDescription();

    $unfiltered = entitlementsPage();
    expect($heading($unfiltered))->toBe('Nothing has been given on this site')
        ->and($description($unfiltered))->toBe('A comp, or a producer such as a payment, adds a row here.');

    foreach ([
        ['reader' => ['value' => '7']],
        ['entitlement' => ['value' => 'course.a']],
        ['source' => ['value' => 'test.order:1']],
        ['state' => ['value' => 'live']],
        ['comps' => ['isActive' => true]],
    ] as $filters) {
        $page = entitlementsPage($filters);

        expect($heading($page))->toBe('No row matches these filters', json_encode($filters))
            ->and($description($page))->toBeNull();
    }

    // The source filter's help is true of it: a comp is shown as "Comp", which is no source.
    $help = static function (string $name): string {
        $field = collect(entitlementsPage()->getTable()->getFilter($name)->getSchemaComponents())->first();

        foreach ($field->getChildSchema(Field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [] as $component) {
            if ($component instanceof Text) {
                return (string) $component->getContent();
            }
        }

        return '';
    };

    expect($help('reader'))->toBe('Exactly as shown in the table.')
        ->and($help('source'))->toBe('Exactly as stored, such as commerce.order:4821. For comps, use Comps only.');
});

it('never titles a refusal "not" over a commit the database refused, whose change cannot be told', function (): void {
    Fx::writer()->comp($this->id, 'course.a', null);
    $row = pageEntitlementRow(entitlementsPage(), 'course.a', EntitlementSource::COMP);

    // The writer's own refusal for a COMMIT that may have landed (`EntitlementAuthority::mapped()`), at either door.
    app()->instance(EntitlementWriter::class, new class
    {
        public function comp(): never
        {
            throw EntitlementRefused::because(EntitlementRefusal::Database, EntitlementRefused::COMP, 'course.a', ['state' => 'HY000', 'commit' => true]);
        }

        public function revoke(): never
        {
            throw EntitlementRefused::because(EntitlementRefusal::Database, EntitlementRefused::REVOKE, 'course.a', ['state' => 'HY000', 'commit' => true]);
        }
    });

    expect(Entitlements::giveComp($this->id, 'course.a', null))->toBeFalse();
    Entitlements::revokeRow($row);

    $notices = pageEntitlementNotices();

    expect(array_column($notices, 'title'))->toBe(['Perhaps given, perhaps not', 'Perhaps revoked, perhaps not'])
        ->and($notices[0]['body'])->toContain('may not have been given')
        ->and($notices[1]['body'])->toContain('may not have been revoked');

    // And a refusal that wrote nothing still says so.
    expect(EntitlementRefused::because(EntitlementRefusal::Database, EntitlementRefused::COMP, 'course.a', ['state' => 'HY000'])->mayHaveApplied)->toBeFalse()
        ->and(EntitlementRefused::because(EntitlementRefusal::Race, EntitlementRefused::COMP, 'course.a', ['commit' => true])->mayHaveApplied)->toBeFalse();
});

/* 17. No browser endpoint. */
it('adds no public property and no public instance method a browser could call', function (): void {
    $class = new ReflectionClass(Entitlements::class);
    $file = $class->getFileName();

    $instance = array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), array_filter(
        $class->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => ! $method->isStatic() && $method->getFileName() === $file,
    )));
    sort($instance);

    $traits = array_map(static fn (string $trait): ReflectionClass => new ReflectionClass($trait), array_values(class_uses_recursive(Entitlements::class)));
    $properties = array_values(array_map(static fn (ReflectionProperty $property): string => $property->getName(), array_filter(
        $class->getProperties(ReflectionProperty::IS_PUBLIC),
        static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === Entitlements::class
            && array_filter($traits, static fn (ReflectionClass $trait): bool => $trait->hasProperty($property->getName())) === [],
    )));

    // Filament's own, each overriding a method the page must have: three to build it, and six reads, each mapped.
    expect($instance)->toBe([
        'content', 'getAllSelectableTableRecordKeys', 'getAllSelectableTableRecordsCount', 'getAllTableRecordsCount',
        'getSelectedTableRecords', 'getTableRecord', 'getTableRecords', 'getTitle', 'table',
    ])
        ->and($properties)->toBe([]);
});

/* 18. Stored values stay text. */
it('keeps every stored value out of attributes and markup', function (): void {
    // Printable ASCII with no space — a reader id the guard's grammar admits, for a model keyed by strings.
    $hostile = 'x"onfocus=alert(1)//<b>&';
    Fx::plant($this->site, $hostile, 'course.hostile', 'test.plant:1');
    $page = entitlementsPage();
    $row = pageEntitlementRow($page, 'course.hostile', 'test.plant:1');

    foreach (['revoke', 'history'] as $name) {
        $action = pageEntitlementRowAction($page, $name, $row);

        expect($action->getExtraAttributes())->toBe([], $name)
            ->and((string) $action->getModalHeading())->not->toContain('onfocus')
            ->and((string) $action->getModalDescription())->not->toContain('onfocus');
    }

    $source = (string) file_get_contents((new ReflectionClass(Entitlements::class))->getFileName());

    foreach (['HtmlString', '->html(', '->markdown(', '->tooltip(', '{!!'] as $sink) {
        expect(str_contains($source, $sink))->toBeFalse("the page reaches {$sink}");
    }

    // Every one of Filament's attribute bags: extraAttributes, extraCellAttributes, extraModalWindowAttributes …
    expect(preg_match('/extra\w*Attributes\s*\(/i', $source))->toBe(0);
});
