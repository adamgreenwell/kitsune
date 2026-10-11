<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Carbon\CarbonImmutable;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Filament\Pages\Entitlements;
use Kitsune\Core\Filament\ReaderNames;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Settings\SettingsWriter;
use Kitsune\Core\Settings\SiteTimezone;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ElsewhereReader;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\FoldingAccount;
use Kitsune\Core\Tests\Fixtures\KeyNotIdentifierReader;
use Kitsune\Core\Tests\Fixtures\MissingTableAccount;
use Kitsune\Core\Tests\Fixtures\OrgBlindReader;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\ScopeShedReader;
use Kitsune\Core\Tests\Fixtures\ShoutingReader;
use Kitsune\Core\Tests\Fixtures\StringKeyedReaderAccount;
use Kitsune\Core\Tests\Fixtures\TestReader;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Kitsune\Core\Tests\Fixtures\ThrowingAddressReader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Readers named on the owner's entitlements page — ADR-037 decisions 1–3 (Adam, 2026-10-10): the operator's switch,
 * the organisation's alone, the Email address column beside the identifier, one fenced read per page held at 25 rows,
 * and kept nowhere. Written from the attacker's side across both boundaries. What a browser receives is
 * `e2e/entitlements.spec.js`'s.
 */

const ENTITLEMENT_NAMES_NONE = 'No account in this organisation';

const ENTITLEMENT_NAMES_UNSHOWN = 'An account in this organisation, whose stored address cannot be shown';

/** With the SQLSTATE in place of `%s`. */
const ENTITLEMENT_NAMES_UNREAD = 'Could not be read (SQLSTATE %s) — tell whoever runs this installation';

const ENTITLEMENT_NAMES_HOSTILE = 'x"onfocus=alert(1)//<b>&';

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('golfdom');
    $this->site = Site::create(['handle' => 'main', 'slug' => 'golfdom-main', 'name' => 'Main', 'locale' => 'en']);
    $this->rival = Org::create(['slug' => 'rival', 'name' => 'Rival']);
    app(Context::class)->setSite($this->site);
    Fx::declareReaders(Reader::class);

    // Explicit keys throughout: the tests name readers by key, and `4` is Rival's.
    entitlementNamesReader($this->org, 1, 'subscriber@kitsune.test');
    entitlementNamesReader($this->rival, 4, 'rival-subscriber@kitsune.test');

    $this->owner = Fx::owner('owner@golfdom.test');
    PanelTenancy::enter($this->site);

    Route::get('/test-entitlements/{tenant:slug}', static fn (): string => '')->name('filament.admin.pages.entitlements');
    app('router')->getRoutes()->refreshNameLookups();

    session()->forget('filament.notifications');
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:05:00', 'UTC'));
});

afterEach(function (): void {
    ThrowingAddressReader::$failure = null;
    ThrowingAddressReader::$queryFailure = null;
    Fx::tearDown();
});

/** The page as a request builds it: mounted, then booted (`entitlementsPage()`'s order). */
function entitlementNamesPage(): Entitlements
{
    $page = app(Entitlements::class);
    $page->mountInteractsWithTable();
    $page->bootedInteractsWithTable();

    return $page;
}

/**
 * A reader of this org, written through the model as a host's own write would be — its mutator included — with the key
 * given, and the context put back as it was.
 *
 * @param  class-string<Model>  $model
 */
function entitlementNamesReader(Org $org, int|string $id, string $email, string $model = Reader::class): Model
{
    $context = app(Context::class);
    [$site, $was] = [$context->site(), $context->org()];
    $context->setOrg($org);

    try {
        return $model::query()->forceCreate(['id' => $id, 'email' => $email]);
    } finally {
        $site !== null ? $context->setSite($site) : $context->setOrg($was);
    }
}

/**
 * `kitsune:readers addresses …` as the operator runs it, then back on the page's site, as the next request would be.
 *
 * @param  array<string, string>  $options
 */
function entitlementNamesOperator(string $value, Site $back, array $options = []): string
{
    $input = new ArrayInput(['command' => 'kitsune:readers', 'action' => 'addresses', 'value' => $value, ...$options]);
    $input->setStream(fopen('php://memory', 'r+'));

    $status = app(ConsoleKernel::class)->handle($input, $output = new BufferedOutput);
    PanelTenancy::moveTo($back);

    expect($status)->toBe(0);

    return trim($output->fetch());
}

/**
 * A site's own `reader_addresses`, written below Eloquent as only a hand-written query could — `SettingsGuard` refuses
 * it on every save — or none, and the resolver told, since it sees no write it did not make.
 */
function entitlementNamesSiteValue(Site $site, ?string $value): void
{
    DB::table('sites')->where('id', $site->getKey())->update(['settings' => $value === null ? null : json_encode([SettingsGuard::READER_ADDRESSES => $value])]);
    SettingsResolver::forgetEverywhere($site);
}

/**
 * Declare another reader model, as a host's configuration does, with the owner still signed in.
 *
 * @param  class-string  $model
 */
function entitlementNamesDeclare(string $model, TestUser $owner): void
{
    Fx::declareReaders($model);
    Auth::guard('web')->setUser($owner);
    Fx::forget();
}

/**
 * Each row as the table shows it: the Reader cell, and the Email address cell — null when the column is not shown.
 *
 * @return list<array{0: string, 1: ?string}>
 */
function entitlementNamesTable(Entitlements $page): array
{
    $rows = [];

    foreach ($page->getTableRecords()->items() as $row) {
        $reader = $page->getTable()->getColumn('reader_id')->record($row);
        $address = $page->getTable()->getColumn('reader_address')->record($row);

        $rows[] = [
            (string) $reader->formatState($reader->getState()),
            $address->isHidden() ? null : (string) $address->formatState($address->getState()),
        ];
    }

    return $rows;
}

/** The address cells alone, keyed by the Reader cell. @return array<string, ?string> */
function entitlementNamesCells(Entitlements $page): array
{
    $cells = [];

    foreach (entitlementNamesTable($page) as [$reader, $address]) {
        $cells[$reader] = $address;
    }

    ksort($cells, SORT_STRING);

    return $cells;
}

/** The intro, one whole sentence per state. */
function entitlementNamesIntro(Entitlements $page): string
{
    return (string) $page->content(Schema::make($page))->getComponents()[0]->getContent();
}

/**
 * Every statement a read runs, on every connection: `DB::listen` registers on the dispatcher they share.
 *
 * @return list<array{connection: string, sql: string, bindings: array<int, mixed>}>
 */
function entitlementNamesQueries(Closure $read): array
{
    $seen = [];
    $listening = true;

    DB::listen(static function (QueryExecuted $query) use (&$seen, &$listening): void {
        if ($listening) {
            $seen[] = [
                'connection' => (string) $query->connectionName,
                'sql' => str_replace(['"', '`'], '', $query->sql),
                'bindings' => $query->bindings,
            ];
        }
    });

    try {
        $read();
    } finally {
        $listening = false;
    }

    return $seen;
}

/**
 * The statements that read a readers table — the skeleton's or a fixture's.
 *
 * @param  list<array{connection: string, sql: string, bindings: array<int, mixed>}>  $queries
 * @return list<array{connection: string, sql: string, bindings: array<int, mixed>}>
 */
function entitlementNamesOnReaders(array $queries): array
{
    return array_values(array_filter($queries, static fn (array $query): bool => preg_match('/\bfrom (readers|test_readers|test_ulid_readers|no_such_readers)\b/', $query['sql']) === 1));
}

/** The integer keys an `in (…)` on a readers table names, as the SQL carries them, in order. @return list<int> */
function entitlementNamesInList(string $sql): array
{
    expect(preg_match('/\breaders\.id in \(([^)]*)\)/', $sql, $match))->toBe(1, $sql);

    $keys = array_map('intval', explode(', ', $match[1]));
    sort($keys);

    return $keys;
}

/**
 * A read inside a savepoint that is then rolled back: on PostgreSQL a failed statement aborts the transaction it is in,
 * and the test's own is the one every later statement needs.
 */
function entitlementNamesInSavepoint(Closure $read): mixed
{
    $result = null;

    try {
        DB::transaction(static function () use ($read, &$result): never {
            $result = $read();

            throw new LogicException('rolled back to the savepoint');
        });
    } catch (LogicException $rolledBack) {
        expect($rolledBack->getMessage())->toBe('rolled back to the savepoint');
    }

    return $result;
}

/** Nothing written to any log channel, at any level. */
function entitlementNamesNothingLogged(): void
{
    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write'] as $method) {
        Log::shouldNotHaveReceived($method);
    }
}

/** Settings and permissions read once, as the request's earlier work reads them, so what follows is the page's own. */
function entitlementNamesWarm(): void
{
    Entitlements::canAccess();
    SiteTimezone::current();
}

/* 1. Hidden until the operator says otherwise. */
it('shows readers by their identifier alone until the operator shows their addresses', function (): void {
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesWarm();

    $page = entitlementNamesPage();
    $rows = null;
    $queries = entitlementNamesQueries(static function () use ($page, &$rows): void {
        $rows = entitlementNamesTable($page);
    });

    expect(config('kitsune.settings.reader_addresses'))->toBe('hidden')
        ->and(ReaderNames::state($this->site))->toBe(ReaderNames::HIDDEN)
        ->and($rows)->toBe([['1', null]])
        ->and(array_keys($page->getTable()->getVisibleColumns()))->not->toContain('reader_address')
        ->and(entitlementNamesIntro($page))->toBe('Who may reach what on Main, from which source, and until when. Readers are shown by their identifier: their addresses appear here once whoever runs this installation turns them on.')
        // The count and the page, and nothing on the readers' table.
        ->and($queries)->toHaveCount(2)
        ->and(entitlementNamesOnReaders($queries))->toBe([]);
});

/* 2. Shown. */
it('names each reader by their address beside the identifier once the operator shows them', function (): void {
    Fx::plant($this->site, '1', 'course.a');

    expect(entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']))->toBe('Readers\' addresses on [golfdom]: shown.');

    entitlementNamesWarm();
    $page = entitlementNamesPage();
    $rows = null;
    $queries = entitlementNamesQueries(static function () use ($page, &$rows): void {
        $rows = entitlementNamesTable($page);
    });
    $column = $page->getTable()->getColumn('reader_address');

    expect($rows)->toBe([['1', 'subscriber@kitsune.test']])
        ->and(array_keys($page->getTable()->getVisibleColumns()))->toBe(['reader_id', 'reader_address', 'entitlement', 'source', 'state', 'expires_at', 'changed_at'])
        ->and($column->getLabel())->toBe('Email address')
        ->and($column->getExtraAttributes())->toBe(['dir' => 'auto'])
        ->and(entitlementNamesIntro($page))->toBe('Who may reach what on Main, from which source, and until when.')
        // The count, the page, and one read of the readers it lists — fenced by this org in its own words.
        ->and($queries)->toHaveCount(3)
        ->and($queries[2]['sql'])->toContain('from readers where readers.id in (1)')
        ->and($queries[2]['sql'])->toContain('readers.org_id = ?')
        ->and($queries[2]['bindings'])->each->toBe($this->org->getKey());

    foreach ($queries as $query) {
        foreach ($query['bindings'] as $binding) {
            expect((string) $binding)->not->toContain('@');
        }
    }
});

/* 3. Cross-site, from the attacker's side. */
it('shows or hides readers\' addresses for the whole organisation alone, and reads each page\'s readers by its own rows', function (): void {
    $fr = Fx::site($this->org, 'fr');
    PanelTenancy::moveTo($this->site);
    entitlementNamesReader($this->org, 2, 'second@kitsune.test');

    Fx::plant($this->site, '1', 'course.a');
    // Reader 1 holds something on both sites; reader 2 on the sibling alone.
    Fx::plant($fr, '1', 'course.b');
    Fx::plant($fr, '2', 'course.c');

    // ⚠️ NO SITE HOLDS THE SWITCH. A site hidden beside a shown sibling would hide nothing from an owner of both, since
    // the identifiers are the same on both pages; so the write is refused, whichever way it points, and nothing is kept.
    foreach (['shown', 'hidden'] as $value) {
        expect(fn () => app(SettingsWriter::class)->set($fr, SettingsGuard::READER_ADDRESSES, $value))->toThrow(new RuntimeException(
            "Refusing [reader_addresses] on Site {$fr->getKey()}: it is set for a whole organisation, whose readers they are — never for a site group or a site, where a sibling would name the same readers. Set it on the organisation instead.",
        ));
    }

    expect(DB::table('sites')->where('id', $fr->getKey())->value('settings'))->toBeNull()
        ->and(DB::table('audit_log')->whereIn('action', [SettingsWriter::SET, SettingsWriter::REVERTED])->count())->toBe(0);

    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    // Reader 2 is never asked about here: the page's own rows are the only keys it reads.
    $queries = entitlementNamesQueries(fn () => expect(entitlementNamesCells(entitlementNamesPage()))->toBe(['1' => 'subscriber@kitsune.test']));
    expect(entitlementNamesInList(entitlementNamesOnReaders($queries)[0]['sql']))->toBe([1]);

    // ⚠️ THE RESIDUAL, AND WHY THE SWITCH IS PER ORG: the sibling's page names the same reader by the same identifier.
    PanelTenancy::moveTo($fr);
    $queries = entitlementNamesQueries(fn () => expect(entitlementNamesCells(entitlementNamesPage()))->toBe(['1' => 'subscriber@kitsune.test', '2' => 'second@kitsune.test']));
    expect(entitlementNamesInList(entitlementNamesOnReaders($queries)[0]['sql']))->toBe([1, 2]);

    // A site's own value, written below Eloquent as only a hand-written query could: either way, the page fails closed
    // to identifiers, with no read of the readers at all — while this site's page, under the same org, still names.
    foreach (['shown', 'hidden'] as $value) {
        entitlementNamesSiteValue($fr, $value);

        $page = entitlementNamesPage();
        $queries = entitlementNamesQueries(fn () => expect(entitlementNamesCells($page))->toBe(['1' => null, '2' => null]));

        // Read, and from the site — and still not counted.
        expect(app(SettingsResolver::class)->resolve($fr, SettingsGuard::READER_ADDRESSES))->toMatchObject(['value' => $value, 'origin' => 'site'])
            ->and(ReaderNames::state($fr))->toBe(ReaderNames::HIDDEN)
            ->and(entitlementNamesOnReaders($queries))->toBe([])
            ->and(entitlementNamesIntro($page))->toBe('Who may reach what on Fr, from which source, and until when. Readers are shown by their identifier: their addresses appear here once whoever runs this installation turns them on.');

        PanelTenancy::moveTo($this->site);
        expect(entitlementNamesCells(entitlementNamesPage()))->toBe(['1' => 'subscriber@kitsune.test']);
        PanelTenancy::moveTo($fr);
    }

    entitlementNamesSiteValue($fr, null);

    // The org hidden: neither site names anyone, and neither reads a reader.
    entitlementNamesOperator('hidden', $this->site, ['--org' => 'golfdom']);

    foreach ([[$this->site, ['1' => null]], [$fr, ['1' => null, '2' => null]]] as [$site, $cells]) {
        PanelTenancy::moveTo($site);
        $queries = entitlementNamesQueries(fn () => expect(entitlementNamesCells(entitlementNamesPage()))->toBe($cells));

        expect(ReaderNames::state($site))->toBe(ReaderNames::HIDDEN)
            ->and(entitlementNamesOnReaders($queries))->toBe([]);
    }

    // And a site's planted `shown` beneath it names nobody either.
    entitlementNamesSiteValue($fr, 'shown');
    $queries = entitlementNamesQueries(fn () => expect(entitlementNamesCells(entitlementNamesPage()))->toBe(['1' => null, '2' => null]));

    expect(entitlementNamesOnReaders($queries))->toBe([]);
});

/* 4. Cross-org, from the attacker's side — the boundary with no framework safety net. */
it('never names another organisation\'s reader, whatever the host\'s query drops', function (string $model): void {
    // Rival's reader 4, named on a row of this site — what an owner who comps `4` here would see.
    Fx::plant($this->site, '1', 'course.a');
    Fx::plant($this->site, '4', 'course.b');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    entitlementNamesDeclare($model, $this->owner);

    $page = entitlementNamesPage();
    $cells = null;
    $queries = entitlementNamesQueries(static function () use ($page, &$cells): void {
        $cells = entitlementNamesCells($page);
    });
    $read = entitlementNamesOnReaders($queries);

    expect($cells)->toBe(['1' => 'subscriber@kitsune.test', '4' => ENTITLEMENT_NAMES_NONE])
        ->and(json_encode($page->getTableRecords()))->not->toContain('rival-subscriber')
        ->and(json_encode(session()->all()))->not->toContain('rival-subscriber')
        ->and($read)->toHaveCount(1)
        ->and(entitlementNamesInList($read[0]['sql']))->toBe([1, 4]);

    // What each host does to the query, so the fence that holds is the one the case is about.
    match ($model) {
        // The skeleton's scope and the page's own fence, both in the SQL.
        Reader::class => expect(substr_count($read[0]['sql'], 'readers.org_id = ?'))->toBe(2),
        // ⚠️ THE SCOPE IS SHED — the model's own query finds Rival's row by its key — so the one org predicate left is
        // the page's own, bound to this org.
        ScopeShedReader::class => expect(substr_count($read[0]['sql'], 'readers.org_id = ?'))->toBe(1)
            ->and($read[0]['bindings'])->toBe([$this->org->getKey()])
            ->and(ScopeShedReader::query()->whereKey(4)->first()?->getAttributes()['email'])->toBe('rival-subscriber@kitsune.test'),
        // ⚠️ NO ORG PREDICATE SURVIVES: the read fetches Rival's row, and the row's written org is the fence that holds.
        OrgBlindReader::class => expect($read[0]['sql'])->not->toContain('org_id')
            ->and(OrgBlindReader::query()->whereKey(4)->first()?->getAttributes()['email'])->toBe('rival-subscriber@kitsune.test'),
    };
})->with([
    'the skeleton\'s reader' => [Reader::class],
    'a host that sheds the org scope' => [ScopeShedReader::class],
    'a host whose builder drops every org predicate' => [OrgBlindReader::class],
]);

it('keeps Rival\'s page to identifiers while Golfdom\'s names its readers, and Golfdom\'s readers off Rival\'s', function (): void {
    $rivalSite = Fx::site($this->rival, 'main', 'rival-main');
    Fx::plant($rivalSite, '4', 'course.r');
    // Golfdom's reader 1, named on Rival's site — what a Rival owner who comps `1` would see.
    Fx::plant($rivalSite, '1', 'course.s');

    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    PanelTenancy::moveTo($rivalSite);
    Fx::owner('owner@rival.test');

    $page = entitlementNamesPage();
    $queries = entitlementNamesQueries(fn () => expect(entitlementNamesCells($page))->toBe(['1' => null, '4' => null]));

    expect(ReaderNames::state($rivalSite))->toBe(ReaderNames::HIDDEN)
        ->and(entitlementNamesOnReaders($queries))->toBe([])
        ->and(entitlementNamesIntro($page))->toContain('their addresses appear here once whoever runs this installation turns them on');

    // Rival's operator shows them too: Rival's own reader is named, and Golfdom's reader 1 is no account of Rival's.
    entitlementNamesOperator('shown', $rivalSite, ['--org' => 'rival']);
    $page = entitlementNamesPage();

    expect(entitlementNamesCells($page))->toBe(['1' => ENTITLEMENT_NAMES_NONE, '4' => 'rival-subscriber@kitsune.test'])
        ->and(json_encode($page->getTableRecords()))->not->toContain('@')
        ->and(json_encode(session()->all()))->not->toContain('@');
});

/* 5. Identifiers no reader can have. */
it('reads no reader for an identifier that can never be a key, and names none by its integer reading', function (): void {
    foreach ([5 => 'five@kitsune.test', 7 => 'seven@kitsune.test'] as $id => $email) {
        entitlementNamesReader($this->org, $id, $email);
    }

    $neverKeys = ['007', '5abc', '01', ' 1', '-1', '99999999999999999999', ENTITLEMENT_NAMES_HOSTILE];

    foreach ($neverKeys as $n => $id) {
        Fx::plant($this->site, $id, 'course.n'.$n);
    }

    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    entitlementNamesWarm();

    $page = entitlementNamesPage();
    $cells = null;
    $queries = entitlementNamesQueries(static function () use ($page, &$cells): void {
        $cells = entitlementNamesCells($page);
    });

    $expected = array_fill_keys($neverKeys, ENTITLEMENT_NAMES_NONE);
    ksort($expected, SORT_STRING);

    // `007` is not reader 7, `5abc` not reader 5, and `01` and ` 1` not reader 1 — and none of them is even asked about.
    expect($cells)->toBe($expected)
        ->and($queries)->toHaveCount(2)
        ->and(entitlementNamesOnReaders($queries))->toBe([]);

    // Beside a real key, only the real key is read.
    Fx::plant($this->site, '7', 'course.seven');
    $page = entitlementNamesPage();
    $queries = entitlementNamesQueries(static function () use ($page, &$cells): void {
        $cells = entitlementNamesCells($page);
    });

    expect($cells['7'])->toBe('seven@kitsune.test')
        ->and(array_diff_key($cells, ['7' => true]))->toBe($expected)
        ->and(entitlementNamesInList(entitlementNamesOnReaders($queries)[0]['sql']))->toBe([7]);
});

/* 6. A host's bare delete. */
it('says no account once the host has deleted the reader underneath', function (): void {
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    expect(entitlementNamesCells(entitlementNamesPage()))->toBe(['1' => 'subscriber@kitsune.test']);

    // Below core, as a host's own cleanup would: the entitlement row stays, naming nobody.
    DB::table('readers')->where('id', 1)->delete();

    expect(entitlementNamesCells(entitlementNamesPage()))->toBe(['1' => ENTITLEMENT_NAMES_NONE])
        ->and(Entitlement::query()->count())->toBe(1);
});

/* 7. Spelling. */
it('shows an address only in its one stored spelling, and says so of one that is no address', function (): void {
    $hostWritten = [
        11 => "reader\u{202E}moc@kitsune.test",
        12 => 'a"b<c@kitsune.test',
        13 => 'Ü@kitsune.test',
    ];

    foreach ($hostWritten as $id => $email) {
        entitlementNamesReader($this->org, $id, $email);
    }

    // The skeleton's mutator lower-cases ASCII and keeps each of these as written.
    expect(DB::table('readers')->whereIn('id', array_keys($hostWritten))->orderBy('id')->pluck('email')->all())->toBe(array_values($hostWritten));

    Fx::plant($this->site, '1', 'course.a');

    foreach (array_keys($hostWritten) as $id) {
        Fx::plant($this->site, (string) $id, 'course.h'.$id);
    }

    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    $page = entitlementNamesPage();

    expect(entitlementNamesCells($page))->toBe([
        '1' => 'subscriber@kitsune.test',
        '11' => ENTITLEMENT_NAMES_UNSHOWN,
        '12' => ENTITLEMENT_NAMES_UNSHOWN,
        '13' => ENTITLEMENT_NAMES_UNSHOWN,
    ]);

    // A host whose model hands the address back in capitals: shown as it is stored and looked up, lower-case.
    entitlementNamesDeclare(ShoutingReader::class, $this->owner);
    $page = entitlementNamesPage();
    $cells = entitlementNamesCells($page);

    expect(ShoutingReader::query()->whereKey(1)->first()?->readerEmail())->toBe('SUBSCRIBER@KITSUNE.TEST')
        ->and($cells['1'])->toBe('subscriber@kitsune.test');

    // U+202E, which would reverse what follows it on screen, reaches no cell, no record and no session.
    foreach ([json_encode($cells, JSON_UNESCAPED_UNICODE), json_encode($page->getTableRecords(), JSON_UNESCAPED_UNICODE), json_encode(session()->all(), JSON_UNESCAPED_UNICODE)] as $text) {
        expect($text)->not->toContain("\xE2\x80\xAE")->not->toContain('Ü')->not->toContain('a\"b<c');
    }
});

/* 8. Failures. */
it('says a reader could not be read, with the SQLSTATE alone, when the readers table is missing', function (): void {
    Fx::plant($this->site, '1', 'course.a');
    Fx::plant($this->site, '7', 'course.b');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    entitlementNamesDeclare(MissingTableAccount::class, $this->owner);

    // The engine's own SQLSTATE for a table that is not there, measured rather than assumed.
    $state = entitlementNamesInSavepoint(static function (): string {
        try {
            DB::table('no_such_readers')->first();
        } catch (QueryException $missing) {
            return (string) $missing->errorInfo[0];
        }

        throw new RuntimeException('the missing table was found');
    });

    Log::spy();
    Exceptions::fake();

    [$cells, $revokeOffered, $queries] = entitlementNamesInSavepoint(static function (): array {
        $page = entitlementNamesPage();
        $cells = $revokeOffered = null;

        $queries = entitlementNamesQueries(static function () use ($page, &$cells, &$revokeOffered): void {
            $cells = entitlementNamesCells($page);
            $revokeOffered = array_map(
                static fn (Entitlement $row): bool => $page->getTable()->getAction('revoke')->record($row)->isVisible(),
                array_values($page->getTableRecords()->items()),
            );
        });

        return [$cells, $revokeOffered, $queries];
    });

    expect(DB::connection()->getDriverName() === 'sqlite' ? $state : 'HY000')->toBe('HY000')
        // Every row is still listed, and each says why it has no address — never "no account".
        ->and($cells)->toBe(['1' => sprintf(ENTITLEMENT_NAMES_UNREAD, $state), '7' => sprintf(ENTITLEMENT_NAMES_UNREAD, $state)])
        ->and($revokeOffered)->toBe([true, true])
        // ⚠️ NOTHING READS AFTER THE FAILED ONE — the cells, the second ask for the rows and Revoke's offer included — so
        // a transaction PostgreSQL aborted on it is asked for nothing more in that request.
        ->and(end($queries)['sql'])->toContain('from entitlements')->toContain('limit 25');

    entitlementNamesNothingLogged();
    Exceptions::assertNothingReported();
});

it('reads a reader model on its own connection, and says so when that connection has no readers table', function (): void {
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    // The same database through a connection of its own, so the read is seen to run where the model lives.
    config(['database.connections.'.ElsewhereReader::CONNECTION => config('database.connections.'.config('database.default'))]);
    DB::connection(ElsewhereReader::CONNECTION)->setPdo(DB::connection()->getPdo());
    entitlementNamesDeclare(ElsewhereReader::class, $this->owner);

    $queries = entitlementNamesQueries(fn () => expect(entitlementNamesCells(entitlementNamesPage()))->toBe(['1' => 'subscriber@kitsune.test']));
    $read = entitlementNamesOnReaders($queries);

    expect($read)->toHaveCount(1)
        ->and($read[0]['connection'])->toBe(ElsewhereReader::CONNECTION)
        ->and(entitlementNamesInList($read[0]['sql']))->toBe([1]);

    // That connection now an in-memory SQLite database whatever the default engine, with no `readers` table in it.
    DB::purge(ElsewhereReader::CONNECTION);
    config(['database.connections.'.ElsewhereReader::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    Log::spy();
    Exceptions::fake();

    expect(entitlementNamesTable(entitlementNamesPage()))->toBe([['1', sprintf(ENTITLEMENT_NAMES_UNREAD, 'HY000')]]);

    entitlementNamesNothingLogged();
    Exceptions::assertNothingReported();
});

it('leaves the page working when the names read is refused, and Revoke still revokes', function (): void {
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    // A refusal as the database reports one, its message carrying the bindings — once, on the names read alone.
    $refuse = true;
    DB::listen(static function (QueryExecuted $query) use (&$refuse): void {
        if ($refuse && preg_match('/from .readers. where .readers.\..id. in \(/', $query->sql) === 1) {
            $refuse = false;
            $driver = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
            $driver->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

            throw new QueryException($query->connectionName, $query->sql, $query->bindings, $driver);
        }
    });

    Log::spy();
    Exceptions::fake();

    $page = entitlementNamesPage();
    $row = $page->getTableRecords()->items()[0];

    expect($refuse)->toBeFalse()
        ->and(entitlementNamesTable($page))->toBe([['1', sprintf(ENTITLEMENT_NAMES_UNREAD, '40001')]]);

    Entitlements::revokeRow($row);

    expect($row->fresh()?->revoked_at)->not->toBeNull()
        ->and(array_column((array) session('filament.notifications', []), 'status'))->toBe(['success']);

    entitlementNamesNothingLogged();
    Exceptions::assertNothingReported();
});

it('says why a host\'s address accessor failed in the database, and lets any other failure of its own through', function (): void {
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    entitlementNamesDeclare(ThrowingAddressReader::class, $this->owner);

    // A lazy accessor whose own query is refused: its SQLSTATE, as any other read of the readers.
    $refused = new PDOException('SQLSTATE[08006]: connection failure reading subscriber@kitsune.test');
    $refused->errorInfo = ['08006', 7, 'connection failure'];
    ThrowingAddressReader::$failure = $refused;

    expect(entitlementNamesTable(entitlementNamesPage()))->toBe([['1', sprintf(ENTITLEMENT_NAMES_UNREAD, '08006')]]);

    // ⚠️ A HOST'S BUG IS NOT A DATABASE'S REFUSAL: it propagates as itself, never as words that would hide it — from the
    // address accessor, or from the model's query before any SQL.
    foreach (['failure' => 'A host bug in readerEmail().', 'queryFailure' => 'A host bug in newQuery().'] as $where => $message) {
        $bug = new RuntimeException($message);
        ThrowingAddressReader::$failure = null;
        ThrowingAddressReader::${$where} = $bug;

        try {
            entitlementNamesPage()->getTableRecords();
            $this->fail("a host's RuntimeException from {$where} was swallowed");
        } catch (RuntimeException $thrown) {
            expect($thrown)->toBe($bug);
        } finally {
            ThrowingAddressReader::${$where} = null;
        }
    }
});

/* 9. Gates: a reader model that cannot name readers here. */
it('shows identifiers and reads no reader where the reader model cannot name them', function (string $case): void {
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    if ($case === 'a token guard over the skeleton\'s reader') {
        config([
            'auth.guards.'.Fx::GUARD => ['driver' => 'token', 'provider' => Fx::GUARD],
            'auth.providers.'.Fx::GUARD => ['driver' => 'eloquent', 'model' => Reader::class],
            ReaderGuard::CONFIG => Fx::GUARD,
        ]);
        Auth::forgetGuards();
        Auth::guard('web')->setUser($this->owner);
        Fx::forget();

        // ⚠️ THE GUARD'S MODEL IS THE SKELETON'S, and usable; the accounts' model is none, since it is no session's.
        expect(Fx::guard()->fault())->toBeNull()
            ->and(Fx::guard()->model())->toBe(Reader::class);
    } else {
        entitlementNamesDeclare($case === 'a reader model that is no account' ? TestReader::class : KeyNotIdentifierReader::class, $this->owner);
    }

    entitlementNamesWarm();
    $page = entitlementNamesPage();
    $rows = null;
    $queries = entitlementNamesQueries(static function () use ($page, &$rows): void {
        $rows = entitlementNamesTable($page);
    });

    expect(ReaderNames::state($this->site))->toBe(ReaderNames::UNAVAILABLE)
        ->and($rows)->toBe([['1', null]])
        ->and(array_keys($page->getTable()->getVisibleColumns()))->not->toContain('reader_address')
        ->and(entitlementNamesIntro($page))->toBe('Who may reach what on Main, from which source, and until when. Readers are shown by their identifier: this installation\'s reader accounts cannot name them here.')
        ->and($queries)->toHaveCount(2)
        ->and(entitlementNamesOnReaders($queries))->toBe([]);
})->with([
    'a reader model that is no account',
    'a token guard over the skeleton\'s reader',
    'a model whose identifier is not its key',
]);

it('names no row whose key is not its identifier, even when asked directly', function (): void {
    entitlementNamesDeclare(KeyNotIdentifierReader::class, $this->owner);

    // Found by key 1, identified by its address: the row stands for something else than what was asked.
    expect(KeyNotIdentifierReader::query()->whereKey(1)->first()?->getAuthIdentifier())->toBe('subscriber@kitsune.test')
        ->and(ReaderNames::of(['1']))->toBe(['1' => [ReaderNames::NONE, null]]);
});

/* 10. String keys. */
it('binds only the keys and the org for string keys, and names a row only by the key it is read back as', function (): void {
    entitlementNamesDeclare(StringKeyedReaderAccount::class, $this->owner);
    entitlementNamesReader($this->org, 'auth0|abc123', 'sso@kitsune.test', StringKeyedReaderAccount::class);
    entitlementNamesReader($this->org, ENTITLEMENT_NAMES_HOSTILE, 'hostile@kitsune.test', StringKeyedReaderAccount::class);
    entitlementNamesReader($this->rival, 'rival|1', 'rival-subscriber@kitsune.test', StringKeyedReaderAccount::class);

    foreach (['auth0|abc123', ENTITLEMENT_NAMES_HOSTILE, 'nobody', 'rival|1'] as $n => $id) {
        Fx::plant($this->site, $id, 'course.s'.$n);
    }

    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    $page = entitlementNamesPage();
    $cells = null;
    $queries = entitlementNamesQueries(static function () use ($page, &$cells): void {
        $cells = entitlementNamesCells($page);
    });
    $read = entitlementNamesOnReaders($queries);

    expect($cells)->toBe([
        'auth0|abc123' => 'sso@kitsune.test',
        'nobody' => ENTITLEMENT_NAMES_NONE,
        'rival|1' => ENTITLEMENT_NAMES_NONE,
        ENTITLEMENT_NAMES_HOSTILE => 'hostile@kitsune.test',
    ])
        ->and($read)->toHaveCount(1)
        // Bound, never written into the SQL: the hostile key with them.
        ->and($read[0]['sql'])->not->toContain('onfocus');

    [$keys, $orgs] = [[], []];

    foreach ($read[0]['bindings'] as $binding) {
        $binding === $this->org->getKey() ? $orgs[] = $binding : $keys[] = $binding;
    }

    sort($keys, SORT_STRING);

    expect($keys)->toBe(['auth0|abc123', 'nobody', 'rival|1', ENTITLEMENT_NAMES_HOSTILE])
        ->and($orgs)->not->toBe([]);
});

it('reads no reader for a string identifier that could never be stored as a key, and names none by a neighbour', function (): void {
    entitlementNamesDeclare(StringKeyedReaderAccount::class, $this->owner);

    // What a trimming, space-dropping or accent-folding read would take each one for.
    foreach (['1' => 'one@kitsune.test', 'ab' => 'ab@kitsune.test', 'cafe' => 'cafe@kitsune.test'] as $id => $email) {
        entitlementNamesReader($this->org, $id, $email, StringKeyedReaderAccount::class);
    }

    // ⚠️ EACH IS A KEY `userKey()` ACCEPTS for a string-keyed model; only the way back refuses them. Written below
    // Eloquent, as nothing in core can write one — and on MySQL and MariaDB as bytes, which `varbinary` takes as given.
    $neverKeys = [' 1', 'a b', 'café'];

    foreach ($neverKeys as $n => $id) {
        Fx::plant($this->site, $id, 'course.n'.$n);
    }

    expect(DB::table('entitlements')->orderBy('id')->pluck('reader_id')->all())->toBe($neverKeys);

    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    entitlementNamesWarm();

    $page = entitlementNamesPage();
    $cells = null;
    $queries = entitlementNamesQueries(static function () use ($page, &$cells): void {
        $cells = entitlementNamesCells($page);
    });

    $expected = array_fill_keys($neverKeys, ENTITLEMENT_NAMES_NONE);
    ksort($expected, SORT_STRING);

    expect($cells)->toBe($expected)
        ->and($queries)->toHaveCount(2)
        ->and(entitlementNamesOnReaders($queries))->toBe([]);

    // Beside a real key, only the real key is bound.
    Fx::plant($this->site, '1', 'course.one');
    $page = entitlementNamesPage();
    $queries = entitlementNamesQueries(static function () use ($page, &$cells): void {
        $cells = entitlementNamesCells($page);
    });
    $read = entitlementNamesOnReaders($queries);

    expect($cells['1'])->toBe('one@kitsune.test')
        ->and(array_diff_key($cells, ['1' => true]))->toBe($expected)
        ->and($read)->toHaveCount(1)
        ->and(array_values(array_filter($read[0]['bindings'], 'is_string')))->toBe(['1']);
});

it('reads many string keys in chunks of at most a hundred, each once', function (): void {
    entitlementNamesDeclare(StringKeyedReaderAccount::class, $this->owner);
    $ids = array_map(static fn (int $n): string => 'k'.$n, range(1, 250));

    $names = null;
    // Each identifier twice, as two sources of one reader's rows would list it.
    $queries = entitlementNamesQueries(static function () use ($ids, &$names): void {
        $names = ReaderNames::of([...$ids, ...array_reverse($ids)]);
    });
    $bound = [];

    foreach ($queries as $query) {
        expect(count($query['bindings']))->toBeLessThanOrEqual(102);

        foreach ($query['bindings'] as $binding) {
            if (is_string($binding)) {
                $bound[] = $binding;
            }
        }
    }

    sort($bound, SORT_STRING);
    $sorted = $ids;
    sort($sorted, SORT_STRING);

    expect($queries)->toHaveCount(3)
        ->and($bound)->toBe($sorted)
        ->and($names)->toHaveCount(250)
        ->and(array_values(array_unique(array_column($names, 0))))->toBe([ReaderNames::NONE]);
});

it('never names a reader by a key the table folds into theirs', function (): void {
    entitlementNamesDeclare(FoldingAccount::class, $this->owner);
    entitlementNamesReader($this->org, 'abc', 'abc@kitsune.test', FoldingAccount::class);
    Fx::plant($this->site, 'ABC', 'course.a');

    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    $queries = entitlementNamesQueries(fn () => expect(entitlementNamesCells(entitlementNamesPage()))->toBe(['ABC' => ENTITLEMENT_NAMES_NONE]));

    // The table did find `abc` for `ABC`; the row is still not the one asked about.
    expect(entitlementNamesOnReaders($queries)[0]['bindings'])->toContain('abc')
        ->and(FoldingAccount::query()->whereKey(['ABC'])->first()?->getKey())->toBe('abc');

    Fx::plant($this->site, 'abc', 'course.b');

    expect(entitlementNamesCells(entitlementNamesPage()))->toBe(['ABC' => ENTITLEMENT_NAMES_NONE, 'abc' => 'abc@kitsune.test']);
});

/* 11. The clamp. */
it('holds the page at 25 rows whatever page size a browser sets', function (int|string $perPage): void {
    for ($n = 1; $n <= 30; $n++) {
        if ($n > 1) {
            entitlementNamesReader($this->org, $n + 100, "r{$n}@kitsune.test");
        }

        Fx::plant($this->site, (string) ($n > 1 ? $n + 100 : 1), 'course.a');
    }

    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    $page = entitlementNamesPage();
    // As `$wire.set('tableRecordsPerPage', …)` arrives: the property, then Filament's own hook for it.
    $page->tableRecordsPerPage = $perPage;
    $page->updatedTableRecordsPerPage();

    $rows = null;
    $queries = entitlementNamesQueries(static function () use ($page, &$rows): void {
        $rows = entitlementNamesTable($page);
    });
    $read = entitlementNamesOnReaders($queries);

    expect($rows)->toHaveCount(25)
        ->and($page->getTableRecords()->perPage())->toBe(Entitlements::PER_PAGE)
        ->and($read)->toHaveCount(1)
        ->and(count(entitlementNamesInList($read[0]['sql'])))->toBe(25);
})->with([10000, 'all']);

/* 12. The memo. */
it('reads the readers once for the page, however often the page asks for its rows', function (): void {
    Fx::plant($this->site, '1', 'course.a');
    Fx::plant($this->site, '4', 'course.b');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    $page = entitlementNamesPage();
    $queries = entitlementNamesQueries(static function () use ($page): void {
        $page->getTableRecords();
        entitlementNamesTable($page);
        $page->getTableRecords();
    });

    expect(entitlementNamesOnReaders($queries))->toHaveCount(1)
        ->and(entitlementNamesCells($page))->toBe(['1' => 'subscriber@kitsune.test', '4' => ENTITLEMENT_NAMES_NONE]);
});

it('keys the memo by the page\'s identifiers, so a row that arrives is named on the same page', function (): void {
    entitlementNamesReader($this->org, 2, 'second@kitsune.test');
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    $page = entitlementNamesPage();
    expect(entitlementNamesCells($page))->toBe(['1' => 'subscriber@kitsune.test']);

    // Another reader of the org gains a row, and the same instance is reset: `resetTable()`, what Filament has an action
    // that changes the rows call, and the one thing that drops the rows it holds.
    Fx::plant($this->site, '2', 'course.b');
    $page->resetTable();

    $queries = entitlementNamesQueries(fn () => expect(entitlementNamesCells($page))->toBe(['1' => 'subscriber@kitsune.test', '2' => 'second@kitsune.test']));

    expect(entitlementNamesOnReaders($queries))->toHaveCount(1)
        ->and(entitlementNamesInList(entitlementNamesOnReaders($queries)[0]['sql']))->toBe([1, 2]);
});

/* 13. Kept nowhere. */
it('says nothing of a row whose reader the page has not read — never that it has no account', function (): void {
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    $page = entitlementNamesPage();
    // A row resolved for an action, as `getTableRecord()` resolves one, and never through the page's read: the memo holds
    // nothing for it, and a false "No account in this organisation" would be worse than an empty cell.
    $row = Entitlement::query()->where('reader_id', '1')->firstOrFail();

    expect($page->getTable()->getColumn('reader_address')->record($row)->getState())->toBeNull()
        // The control: on a page that reads its rows, the same row is named — a new page, since Filament keeps a cell's
        // state once it has been asked for.
        ->and(entitlementNamesCells(entitlementNamesPage()))->toBe(['1' => 'subscriber@kitsune.test']);
});

it('keeps a reader\'s address in the page\'s private memo for the request, and nowhere else', function (): void {
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);

    $page = entitlementNamesPage();
    expect(entitlementNamesCells($page))->toBe(['1' => 'subscriber@kitsune.test']);

    $records = $page->getTableRecords();

    expect(json_encode($records))->not->toContain('@')
        ->and(json_encode(session()->all()))->not->toContain('@');

    foreach ($records->items() as $record) {
        expect(json_encode($record->getAttributes()))->not->toContain('@')
            ->and($record->getRelations())->toBe([]);
    }

    // Every property of the page, static or not, at any visibility: the one holding the address is the private memo.
    $holding = [];

    foreach ((new ReflectionClass($page))->getProperties() as $property) {
        if ($property->isStatic() ? ! $property->isInitialized() : ! $property->isInitialized($page)) {
            continue;
        }

        $value = $property->isStatic() ? $property->getValue() : $property->getValue($page);

        if (str_contains((string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR, 64), 'subscriber@kitsune.test')) {
            $holding[$property->getName()] = ($property->isPrivate() ? 'private' : 'not private').($property->isStatic() ? ', static' : '');
        }
    }

    expect($holding)->toBe(['names' => 'private'])
        // And the read itself keeps nothing between calls.
        ->and((new ReflectionClass(ReaderNames::class))->getProperties())->toBe([]);
});

/* 14. Nobody, and a member. */
it('refuses its rows to nobody and to a member with a 403, before reading any', function (string $who): void {
    Fx::plant($this->site, '1', 'course.a');
    entitlementNamesOperator('shown', $this->site, ['--org' => 'golfdom']);
    $page = entitlementNamesPage();

    // The page mounted for an owner, then called by name from a session that is not one.
    $who === 'a member' ? Fx::member('member@golfdom.test') : Fx::nobody();

    $queries = entitlementNamesQueries(static function () use ($page): void {
        try {
            $page->getTableRecords();
            test()->fail('the rows were returned');
        } catch (HttpException $refused) {
            expect($refused->getStatusCode())->toBe(403);
        }
    });

    expect(array_filter($queries, static fn (array $query): bool => preg_match('/\bfrom (entitlements|readers)\b/', $query['sql']) === 1))->toBe([]);
})->with(['nobody signed in', 'a member']);
