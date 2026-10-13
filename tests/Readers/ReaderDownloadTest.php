<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Http\Middleware\ReaderArea;
use Kitsune\Core\Http\Middleware\RequireReader;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;
use League\Flysystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * The reader's download route — ADR-041 decisions 1–4 (Adam, 2026-10-11): `{prefix}/account/downloads/{id}`, a page that
 * names a file offered to readers, and `…/{id}/file`, its bytes — to a reader who holds the file's name on this site now,
 * and to nobody else, with one answer for everybody else.
 *
 * ⚠️ FROM THE ATTACKER'S SIDE (AGENTS.md §9). Every refusal is compared with every other — status, body, how many queries
 * and on which tables — so a refusal that does less work than its neighbours says which files exist, and fails here.
 * Each request starts as a new one does: nothing in context, no guard built, no memo (`dlCall()`).
 */

const DL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

const DL_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

/** The name the signed-in reader holds at Golfdom, which most files here are offered for. */
const DL_HELD = 'course.annual';

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    /*
     * ⚠️ A FAKE'S ROOT, WATCHED: every operation on the private disk is logged, so a refusal can be seen to read no byte.
     * Not `RefusingDisk::install()`, which points the configured root at its own — and `HoldMediaStaging` refuses every
     * request while core's disk is redefined.
     */
    Storage::fake(MediaDisks::PRIVATE);
    Storage::fake('public');
    $root = rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/');
    $this->disk = new RefusingDisk($root, MediaDisks::PRIVATE);
    Storage::set(MediaDisks::PRIVATE, new LocalFilesystemAdapter(new Filesystem($this->disk), $this->disk, ['driver' => 'local', 'root' => $root]));

    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');
    ReaderFixture::mode($this->world['rival'], 'sign-in');
    $this->sites = $this->world['sites'];

    $context = app(Context::class);
    $this->types = [];
    $this->owners = [];

    foreach (['golfdom' => $this->world['golfdom'], 'rival' => $this->world['rival']] as $slug => $org) {
        $context->forget()->setOrg($org);
        $this->types[$slug] = EntryType::create(['org_id' => $org->getKey(), 'handle' => 'document', 'name' => 'Document', 'plural_name' => 'Documents', 'is_media' => true]);
        $this->owners[$slug] = CredentialFixture::member(owner: true, email: "owner@{$slug}.test");
    }

    $context->forget()->setOrg($this->world['golfdom']);
    $this->types['report'] = EntryType::create(['org_id' => $this->world['golfdom']->getKey(), 'handle' => 'report', 'name' => 'Report', 'plural_name' => 'Reports', 'is_media' => true]);
    $this->types['article'] = EntryType::create(['org_id' => $this->world['golfdom']->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);

    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    $this->key = (string) $this->reader->getKey();

    ReaderFixture::forget();
    RefusingDisk::forgetLog();
});

afterEach(function (): void {
    app(Context::class)->forget();
    RefusingDisk::forgetLog();

    // This process's own (#185's lesson): another run sharing the temporary directory has its own in flight.
    foreach (glob(sys_get_temp_dir().'/kitsune-dl-src-'.getmypid().'-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

/** A file stored at a site as an upload stores it — kept to that site unless `$siteOnly` is false. */
function dlStore(string $site, string $title = 'Annual report 2026', string $kind = 'pdf', bool $siteOnly = true, string $visibility = 'private', ?string $type = null): Entry
{
    $test = test();
    /** @var Site $at */
    $at = $test->sites[$site];
    $org = Org::query()->findOrFail($at->org_id);
    $source = tempnam(sys_get_temp_dir(), 'kitsune-dl-src-'.getmypid().'-');
    file_put_contents($source, $kind === 'pdf' ? DL_PDF : base64_decode(DL_PNG));

    try {
        app(Context::class)->forget()->setOrg($org)->setSite($at);

        return MediaLibrary::store($source, 'upload.'.$kind, $test->types[$type ?? ($org->slug === 'rival' ? 'rival' : 'golfdom')], $visibility, title: $title, siteOnly: $siteOnly);
    } finally {
        @unlink($source);
        ReaderFixture::forget();
        RefusingDisk::forgetLog();
    }
}

/** Offer an entry's file to readers, as its org's owner does with *Offer to readers*. */
function dlOffer(Entry $entry, string $name = DL_HELD): Entry
{
    $test = test();
    $site = Site::query()->withoutGlobalScopes()->findOrFail($entry->site_id);
    $org = Org::query()->findOrFail($site->org_id);

    // From the default guard, as the admin asks: whatever the last request left `shouldUse()` at is forgotten first.
    ReaderFixture::forget();
    app(Context::class)->setOrg($org)->setSite($site);
    Auth::guard('web')->setUser($test->owners[$org->slug === 'rival' ? 'rival' : 'golfdom']);
    Permissions::forget();

    try {
        expect(MediaVisibility::offer($entry, $name))->toBe(MediaVisibility::SWITCHED);
    } finally {
        ReaderFixture::forget();
        RefusingDisk::forgetLog();
    }

    return $entry;
}

/** The reader holds a name at a site, from an order. */
function dlGrant(string $site, string $name = DL_HELD, ?DateTimeInterface $expires = null, ?DateTimeInterface $revoked = null): void
{
    EntitlementFixture::plant(test()->sites[$site], test()->key, $name, expires: $expires, revoked: $revoked);
}

/** The signed-in reader, through the reader guard's own `login()`. */
function dlSignIn(?Reader $reader = null): void
{
    ReaderFixture::signIn($reader ?? test()->reader);
}

/**
 * One request, as a new one starts — and what it cost: its status, its body, and each query's tables.
 *
 * @param  array<string, string>  $headers
 * @return array{response: TestResponse<Response>, status: int, body: string, queries: int, tables: list<string>}
 */
function dlCall(string $method, string $path, array $headers = []): array
{
    ReaderFixture::forget();
    Once::flush();
    SettingsResolver::forgetEverywhere();

    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $response = test()->call($method, str_starts_with($path, 'http') ? $path : 'http://localhost'.$path, [], [], [], $server);
        $body = $response->baseResponse instanceof StreamedResponse ? (string) $response->streamedContent() : (string) $response->getContent();
    } finally {
        $log = DB::getQueryLog();
        DB::disableQueryLog();
    }

    $tables = array_map(static function (array $query): string {
        preg_match_all('/\b(?:from|join|into|update)\s+["`]?(\w+)["`]?/i', $query['query'], $match);

        return implode('+', $match[1]);
    }, $log);

    return ['response' => $response, 'status' => $response->getStatusCode(), 'body' => $body, 'queries' => count($log), 'tables' => $tables];
}

/** The landing page's path at Golfdom. */
function dlPage(Entry|int|string $id, string $prefix = '/golfdom'): string
{
    return $prefix.'/account/downloads/'.($id instanceof Entry ? $id->getKey() : $id);
}

/** The file's path at Golfdom. */
function dlFile(Entry|int|string $id, string $prefix = '/golfdom'): string
{
    return dlPage($id, $prefix).'/file';
}

/**
 * Every table a call's queries named.
 *
 * @param  array{tables: list<string>}  $call
 * @return list<string>
 */
function dlTables(array $call): array
{
    return array_values(array_unique(array_merge(...array_map(static fn (string $tables): array => explode('+', $tables), $call['tables'] ?: ['']))));
}

/** A `Cache-Control` value's directives, in order — Symfony sorts them, so the order is not the value's. @return list<string> */
function dlDirectives(?string $value): array
{
    $directives = array_map('trim', explode(',', strtolower((string) $value)));
    sort($directives);

    return $directives;
}

/** @param  TestResponse<Response>  $response */
function dlHasFileHeaders(TestResponse $response): void
{
    foreach (ReaderArea::FILE_HEADERS as $name => $value) {
        expect($name === 'Cache-Control' ? dlDirectives($response->headers->get($name)) : $response->headers->get($name))
            ->toBe($name === 'Cache-Control' ? dlDirectives($value) : $value, $name);
    }
}

/** The row the file is stored under. */
function dlRow(Entry $entry): MediaFile
{
    return MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();
}

/** Run a request inside a savepoint of its own: on PostgreSQL a failed statement aborts the test's transaction. */
function dlInSavepoint(Closure $request): mixed
{
    DB::beginTransaction();

    try {
        return $request();
    } finally {
        DB::rollBack();
    }
}

/** A request's log lines: level, message and context keys. @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function dlLogged(Closure $request): array
{
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = [$message->level, $message->message, $message->context];
    });

    try {
        $request();
    } finally {
        Event::forget(MessageLogged::class);
    }

    return $logged;
}

const DL_ELSEWHERE = '/^A reader\'s download could not be answered: the database refused \(SQLSTATE [0-9A-Z]{5}\)\. The reader, the file and the entitlement are not logged\.$/D';

// ---- A guest ----------------------------------------------------------------------------------------------------------

it('sends a guest to sign in from every id, the same way, before anything is looked up — and keeps the page', function (): void {
    $pdf = dlOffer(dlStore('golfdom'));
    $answers = [];

    foreach ([(string) $pdf->getKey(), '999999', 'abc', '09', '99999999999999999999999'] as $id) {
        foreach ([dlPage($id), dlFile($id)] as $path) {
            foreach (['GET', 'HEAD'] as $method) {
                app('session.store')->forget(RequireReader::INTENDED);
                $call = dlCall($method, $path);

                expect($call['status'])->toBe(303, "{$method} {$path}")
                    ->and($call['response']->headers->get('Location'))->toBe('/golfdom/account/sign-in')
                    // The site and its mode, and nothing about readers, entries, files or what anybody holds.
                    ->and(array_diff(dlTables($call), ['sites', 'orgs']))->toBe([], "{$method} {$path}")
                    ->and(app('session.store')->get(RequireReader::INTENDED))->toBe($path);

                $answers[$method][] = [$call['status'], $call['body'], $call['queries'], $call['tables']];
            }
        }
    }

    expect(array_unique(array_map('serialize', $answers['GET'])))->toHaveCount(1)
        ->and(array_unique(array_map('serialize', $answers['HEAD'])))->toHaveCount(1)
        ->and($answers['HEAD'][0][1])->toBe('');
});

it('brings a reader back to the page after signing in — under a prefix of four segments and at a root host too', function (string $baseUrl, string $prefix, string $origin): void {
    $handle = 'golfdom';

    if ($baseUrl !== '/golfdom') {
        $handle = 'elsewhere';
        $this->sites[$handle] = ReaderFixture::site($this->world['golfdom'], 'elsewhere', 'Elsewhere', $baseUrl);
    }

    $entry = dlOffer(dlStore($handle));
    dlGrant($handle);
    $page = $prefix.'/account/downloads/'.$entry->getKey();

    $guest = dlCall('GET', $origin.$page);

    expect($guest['status'])->toBe(303)
        ->and($guest['response']->headers->get('Location'))->toBe($prefix.'/account/sign-in');

    $this->readerPost($origin.$prefix.'/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(303)
        ->assertHeader('Location', $page);

    $back = dlCall('GET', $origin.$page);

    expect($back['status'])->toBe(200)
        ->and($back['body'])->toContain('<a href="'.$page.'/file">Download</a>')
        ->and(dlCall('GET', $origin.$page.'/file')['body'])->toBe(DL_PDF);
})->with([
    'a prefix of one segment' => ['/golfdom', '/golfdom', 'http://localhost'],
    'a prefix of four segments' => ['/news/fr/x/y', '/news/fr/x/y', 'http://localhost'],
    'a site at the root of its host' => ['https://downloads.test', '', 'https://downloads.test'],
]);

// ---- A reader who holds the file ------------------------------------------------------------------------------------

it('shows a holder the page: the title escaped, isolated and in the site\'s language, its format and size, and one link', function (): void {
    $pdf = dlOffer(dlStore('golfdom', '<b>Annual & "report"</b> 2026'));
    $png = dlOffer(dlStore('golfdom', 'Course map', 'png'));
    dlGrant('golfdom');
    dlSignIn();

    $page = dlCall('GET', dlPage($pdf));

    expect($page['status'])->toBe(200)
        // The owner's words, so the site's language — not the copy's, which `<main>` carries.
        ->and($page['body'])->toContain('<h1 lang="en" dir="auto">'."\u{2068}".'&lt;b&gt;Annual &amp; &quot;report&quot;&lt;/b&gt; 2026'."\u{2069}".'</h1>')
        ->and($page['body'])->not->toContain('<b>Annual')
        // The tab and the history say "Download", not what was bought.
        ->and($page['body'])->toContain('<title>Download — Golfdom</title>')
        ->and($page['body'])->toContain('<p>PDF file, '.strlen(DL_PDF).' B</p>')
        ->and($page['body'])->toContain('<a href="'.dlFile($pdf).'">Download</a>')
        ->and(substr_count($page['body'], '/file"'))->toBe(1)
        ->and($page['queries'])->toBe(9);

    foreach (ReaderArea::HEADERS as $name => $value) {
        $page['response']->assertHeader($name, $value);
    }

    expect($page['response']->headers->has('Content-Disposition'))->toBeFalse()
        ->and($page['response']->headers->has('Cross-Origin-Resource-Policy'))->toBeFalse();

    // An image the browser opens says so.
    $image = dlCall('GET', dlPage($png));

    expect($image['body'])->toContain('<p>PNG file, '.strlen((string) base64_decode(DL_PNG)).' B</p>')
        ->and($image['body'])->toContain('<a href="'.dlFile($png).'">Open</a>');
});

it('sends a holder the bytes as stored, with exactly the file headers', function (): void {
    $pdf = dlOffer(dlStore('golfdom'));
    $png = dlOffer(dlStore('golfdom', 'Course map', 'png'));
    dlGrant('golfdom');
    dlSignIn();

    $document = dlCall('GET', dlFile($pdf));

    expect($document['status'])->toBe(200)
        ->and($document['body'])->toBe(DL_PDF)
        ->and($document['response']->headers->get('Content-Length'))->toBe((string) dlRow($pdf)->size_bytes)
        ->and($document['response']->headers->get('Content-Length'))->toBe((string) strlen(DL_PDF))
        ->and($document['response']->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($document['response']->headers->get('Content-Disposition'))->toBe("attachment; filename=annual-report-2026.pdf; filename*=utf-8''Annual%20report%202026.pdf")
        ->and($document['queries'])->toBe(9);

    dlHasFileHeaders($document['response']);

    $image = dlCall('GET', dlFile($png));

    expect($image['body'])->toBe(base64_decode(DL_PNG))
        ->and($image['response']->headers->get('Content-Type'))->toBe('image/png')
        ->and($image['response']->headers->get('Content-Disposition'))->toStartWith('inline; ');

    dlHasFileHeaders($image['response']);
});

it('offers a title in any script by its own name, with the slug as the ASCII fallback', function (string $title, string $fallback): void {
    $pdf = dlOffer(dlStore('golfdom', $title));
    dlGrant('golfdom');
    dlSignIn();

    expect(dlCall('GET', dlFile($pdf))['response']->headers->get('Content-Disposition'))
        ->toBe("attachment; filename={$fallback}; filename*=utf-8''".rawurlencode($title).'.pdf');
})->with([
    'Hebrew' => ['דוח שנתי 2026', '2026.pdf'],
    'Japanese' => ['年次報告書', 'file.pdf'],
]);

it('gives the title the site\'s language on an Arabic site, while the copy keeps its own', function (): void {
    $this->sites['golfdom-ar'] = ReaderFixture::site($this->world['golfdom'], 'golfdom-ar', 'Golfdom AR', '/golfdom-ar', 'ar');
    $pdf = dlOffer(dlStore('golfdom-ar', 'التقرير السنوي 2026'));
    dlGrant('golfdom-ar');
    dlSignIn();

    $page = dlCall('GET', dlPage($pdf, '/golfdom-ar'));

    // The document and the owner's title are Arabic; core's words, found only in English, say so on `<main>`.
    expect($page['status'])->toBe(200)
        ->and($page['body'])->toContain('<html lang="ar" dir="rtl">')
        ->and($page['body'])->toContain('<main lang="en" dir="ltr">')
        ->and($page['body'])->toContain('<h1 lang="ar" dir="auto">'."\u{2068}".'التقرير السنوي 2026'."\u{2069}".'</h1>')
        ->and($page['body'])->toContain('<p>PDF file, '.strlen(DL_PDF).' B</p>');
});

it('heads a file with no title, or only space, "Untitled file" in the copy\'s language — never two invisible isolates', function (?string $title): void {
    $pdf = dlOffer(dlStore('golfdom'));
    DB::table('entries')->where('id', $pdf->getKey())->update(['title' => $title]);
    dlGrant('golfdom');
    dlSignIn();

    $page = dlCall('GET', dlPage($pdf));

    expect($page['status'])->toBe(200)
        ->and($page['body'])->toContain('<h1>Untitled file</h1>')
        ->and(substr_count($page['body'], '<h1'))->toBe(1)
        ->and($page['body'])->not->toContain("\u{2068}")
        ->and($page['body'])->not->toContain("\u{2069}")
        ->and($page['body'])->toContain('<a href="'.dlFile($pdf).'">Download</a>');
})->with([
    'none' => [null],
    'empty' => [''],
    'spaces' => ['   '],
    'a tab and a newline' => ["\t\n"],
    'a no-break and an ideographic space' => ["\u{00A0}\u{3000}"],
]);

// ---- Everybody else: one answer ---------------------------------------------------------------------------------------

it('refuses every other case with one answer — the same status, body and queries, on the same tables — reading no byte', function (): void {
    $golfdom = $this->world['golfdom'];
    dlGrant('golfdom');
    dlGrant('golfdom-fr');

    $held = dlOffer(dlStore('golfdom', 'Held'));

    $trashed = dlOffer(dlStore('golfdom', 'Trashed'));
    ReaderFixture::forget();
    app(Context::class)->setOrg($golfdom)->setSite($this->sites['golfdom']);
    Auth::guard('web')->setUser($this->owners['golfdom']);
    $trashed->delete();
    ReaderFixture::forget();

    $switchedOff = dlOffer(dlStore('golfdom', 'Report', type: 'report'));
    EntryTypeAvailability::create(['entry_type_id' => $this->types['report']->getKey(), 'scope_type' => 'site', 'scope_id' => $this->sites['golfdom']->getKey(), 'is_enabled' => false]);

    app(Context::class)->forget()->setOrg($golfdom)->setSite($this->sites['golfdom']);
    $article = Entry::create(['entry_type_id' => $this->types['article']->getKey(), 'title' => 'Plain', 'slug' => 'plain']);
    ReaderFixture::forget();

    // Below the model, as no door writes them: the writer refuses a public file, a shared one and a name that is none.
    $public = dlStore('golfdom', 'Public', visibility: 'public');
    $shared = dlStore('golfdom', 'Shared', siteOnly: false);
    $malformed = dlStore('golfdom', 'Malformed');
    DB::table('media_files')->whereIn('entry_id', [$public->getKey(), $shared->getKey()])->update(['reader_entitlement' => DL_HELD]);
    DB::table('media_files')->where('entry_id', $malformed->getKey())->update(['reader_entitlement' => 'Course.Annual']);

    $lapsed = dlOffer(dlStore('golfdom', 'Lapsed'), 'course.lapsed');
    dlGrant('golfdom', 'course.lapsed', expires: CarbonImmutable::now()->subDay());
    $revoked = dlOffer(dlStore('golfdom', 'Revoked'), 'course.revoked');
    dlGrant('golfdom', 'course.revoked', revoked: CarbonImmutable::now()->subDay());
    $sibling = dlOffer(dlStore('golfdom', 'Sibling'), 'course.sibling');
    dlGrant('golfdom-fr', 'course.sibling');

    $gone = dlOffer(dlStore('golfdom', 'Gone'), 'course.other');
    Storage::disk(MediaDisks::PRIVATE)->delete(dlRow($gone)->path);

    $cases = [
        'an id nothing has' => '999999',
        'the largest id the column holds' => '9223372036854775807',
        'a file offered to nobody' => dlStore('golfdom', 'Ungated'),
        'a file offered for a name the reader does not hold' => dlOffer(dlStore('golfdom', 'Other'), 'course.other'),
        'a lapsed grant' => $lapsed,
        'a revoked grant' => $revoked,
        'a trashed entry' => $trashed,
        'a type this site has switched off' => $switchedOff,
        'an entry with no file' => $article,
        'a public file offered below the model' => $public,
        'a shared file offered below the model' => $shared,
        'a stored name that is not a name' => $malformed,
        "Golfdom FR's file, held there, asked at Golfdom" => dlOffer(dlStore('golfdom-fr')),
        "Rival's file" => dlOffer(dlStore('rival')),
        'a name held only on the sibling site' => $sibling,
        'a file whose bytes are gone, not held' => $gone,
    ];

    dlSignIn();

    // The control: the same reader, the same door, a file they hold.
    expect(dlCall('GET', dlFile($held))['body'])->toBe(DL_PDF);

    RefusingDisk::forgetLog();
    $answers = [];

    foreach ($cases as $name => $id) {
        foreach ([dlPage($id), dlFile($id)] as $path) {
            $call = dlCall('GET', $path);
            $answers["{$name}: {$path}"] = [$call['status'], $call['body'], $call['queries'], $call['tables']];
        }
    }

    // And every grant forgotten, by the owner's erasure: the file the reader held a moment ago.
    ReaderFixture::forget();
    app(Context::class)->setOrg($golfdom);
    Auth::guard('web')->setUser($this->owners['golfdom']);
    Permissions::forget();
    expect(EntitlementFixture::writer()->forget($this->key))->toBeGreaterThan(0);
    ReaderFixture::forget();

    foreach ([dlPage($held), dlFile($held)] as $path) {
        $call = dlCall('GET', $path);
        $answers["every grant forgotten: {$path}"] = [$call['status'], $call['body'], $call['queries'], $call['tables']];
    }

    [$status, $body, $queries, $tables] = reset($answers);

    foreach ($answers as $case => $answer) {
        expect($answer)->toBe([$status, $body, $queries, $tables], $case);
    }

    expect($status)->toBe(404)
        ->and($body)->toContain('This download isn&#039;t available to this account.')
        ->and($body)->toContain('<a href="/golfdom/account">Your account</a>')
        ->and($tables)->toBe(['sites+orgs', 'orgs', 'sites', 'orgs', 'readers', 'entries+media_files', 'entry_type_availability', 'entitlements'])
        // No byte read, nor asked after: a file's presence says nothing to somebody who does not hold it.
        ->and(RefusingDisk::$log)->toBe([])
        /*
         * ⚠️ PINNED ON EVERY ENGINE, as the holder's 9 is: measured equal on SQLite, PostgreSQL 17 and MariaDB 10.11, and
         * MySQL by CI. The equality above is the rule; the count says when a refusal's work changes at all.
         */
        ->and($queries)->toBe(8);
});

/*
 * ⚠️ THE ROW IS ASKED AGAIN WHEN IT IS READ. Between the lookup that found the offer and the read of the file's row, an
 * owner can stop offering it or — once it is stopped — make it public. The race is staged here: the row changes the
 * moment the reader's entitlement has been read, as another request's write would land.
 */
it('refuses a file whose offer changed between the lookup and the read of its row', function (array $change): void {
    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');
    dlSignIn();
    $changed = false;

    DB::listen(static function (QueryExecuted $query) use ($pdf, $change, &$changed): void {
        if (! $changed && preg_match('/\bfrom\s+[`"]?entitlements[`"]?/i', $query->sql) === 1) {
            $changed = true;
            DB::table('media_files')->where('entry_id', $pdf->getKey())->update($change);
        }
    });

    foreach ([dlPage($pdf), dlFile($pdf)] as $path) {
        $changed = false;
        DB::table('media_files')->where('entry_id', $pdf->getKey())->update(['visibility' => 'private', 'reader_entitlement' => DL_HELD]);

        expect(dlCall('GET', $path)['status'])->toBe(404, $path)
            ->and($changed)->toBeTrue();
    }
})->with([
    'offered no more' => [['reader_entitlement' => null]],
    'offered for another name' => [['reader_entitlement' => 'course.other']],
    'made public' => [['visibility' => 'public']],
]);

it('answers a malformed id with the same notice, before anything about the file is read', function (string $id): void {
    dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');
    dlSignIn();

    $unknown = dlCall('GET', dlPage(999999));

    foreach ([dlPage($id), dlFile($id)] as $path) {
        $call = dlCall('GET', $path);

        expect($call['status'])->toBe(404)
            ->and($call['body'])->toBe($unknown['body'])
            ->and(array_intersect(dlTables($call), ['entries', 'media_files', 'entitlements']))->toBe([]);
    }
})->with([
    'letters' => ['abc'],
    'digits then letters' => ['1abc'],
    'a padded id' => ['01'],
    'an id past the column' => ['9223372036854775808'],
    'a negative id' => ['-1'],
    'an encoded space' => ['1%20'],
]);

// ---- From the attacker's side ---------------------------------------------------------------------------------------

it('sends a reader of another organisation to sign in, as a guest is sent, whatever a row says', function (): void {
    $pdf = dlOffer(dlStore('golfdom'));
    $rival = ReaderFixture::reader($this->world['rival'], 'rival@kitsune.test');
    // A row filed at Golfdom under the Rival reader's key: nothing a Rival session may use.
    EntitlementFixture::plant($this->sites['golfdom'], (string) $rival->getKey(), DL_HELD);
    dlSignIn($rival);

    foreach ([dlPage($pdf), dlFile($pdf)] as $path) {
        $call = dlCall('GET', $path);

        expect($call['status'])->toBe(303)
            ->and($call['response']->headers->get('Location'))->toBe('/golfdom/account/sign-in')
            ->and(array_intersect(dlTables($call), ['entries', 'media_files', 'entitlements']))->toBe([]);
    }

    // At Rival's own site the reader is a reader, and holds nothing there.
    expect(dlCall('GET', dlPage($pdf, '/rival'))['status'])->toBe(404);
});

it('sends an owner signed in to the admin to sign in, even where a grant names their key', function (): void {
    $pdf = dlOffer(dlStore('golfdom'));
    $owner = $this->owners['golfdom'];
    EntitlementFixture::plant($this->sites['golfdom'], (string) $owner->getKey(), DL_HELD);

    app(Context::class)->forget()->setOrg($this->world['golfdom']);
    Auth::guard('web')->login($owner);
    ReaderFixture::forget();

    foreach ([dlPage($pdf), dlFile($pdf)] as $path) {
        $call = dlCall('GET', $path);

        expect($call['status'])->toBe(303)
            ->and($call['response']->headers->get('Location'))->toBe('/golfdom/account/sign-in')
            ->and(array_intersect(dlTables($call), ['entries', 'media_files', 'entitlements']))->toBe([]);
    }
});

it('answers 404 where reader accounts are off — to a reader whose session began on a sibling site too', function (): void {
    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');

    $this->readerPost('http://localhost/golfdom-fr/account/sign-in', ['email' => 'subscriber@kitsune.test', 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(303);

    expect(dlCall('GET', dlFile($pdf))['body'])->toBe(DL_PDF);

    ReaderFixture::mode($this->sites['golfdom'], 'off');

    foreach ([dlPage($pdf), dlFile($pdf)] as $path) {
        $call = dlCall('GET', $path);

        expect($call['status'])->toBe(404)
            ->and(array_intersect(dlTables($call), ['readers', 'entries', 'media_files', 'entitlements']))->toBe([]);
    }

    expect(dlCall('GET', '/golfdom-fr/account')['status'])->toBe(200);
});

it('answers 404 while reader accounts cannot work here, and inside a panel\'s URL space', function (): void {
    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');
    dlSignIn();

    config(['kitsune.readers.guard' => null]);

    expect(dlCall('GET', dlPage($pdf))['status'])->toBe(404)
        ->and(dlCall('GET', dlFile($pdf))['status'])->toBe(404);

    config(['kitsune.readers.guard' => ReaderFixture::GUARD]);
    PanelTenancy::enter($this->sites['golfdom'])->path('admin');
    ReaderFixture::site($this->world['golfdom'], 'admin-site', 'Admin Site', '/admin');

    expect(dlCall('GET', dlPage($pdf, '/admin'))['status'])->toBe(404)
        ->and(dlCall('GET', dlFile($pdf, '/admin'))['status'])->toBe(404)
        ->and(dlCall('GET', dlFile($pdf))['body'])->toBe(DL_PDF);
});

it('serves an upper-case prefix as ReaderArea does, and keeps no page there to return to', function (): void {
    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');

    $guest = dlCall('GET', dlPage($pdf, '/GOLFDOM'));

    expect($guest['status'])->toBe(303)
        ->and(app('session.store')->get(RequireReader::INTENDED))->toBeNull();

    dlSignIn();

    expect(dlCall('GET', dlPage($pdf, '/GOLFDOM'))['status'])->toBe(200)
        ->and(dlCall('GET', dlFile($pdf, '/GOLFDOM'))['body'])->toBe(DL_PDF);
});

// ---- Requests from elsewhere (Fetch Metadata) ---------------------------------------------------------------------

it('answers an embed or a script from elsewhere with 404, before the reader or the file is read', function (string $from, string $mode, string $dest): void {
    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');
    dlSignIn();

    foreach ([dlPage($pdf), dlFile($pdf), dlPage(999999), dlFile(999999)] as $path) {
        $call = dlCall('GET', $path, ['Sec-Fetch-Site' => $from, 'Sec-Fetch-Mode' => $mode, 'Sec-Fetch-Dest' => $dest]);

        expect($call['status'])->toBe(404, $path)
            ->and(array_diff(dlTables($call), ['sites', 'orgs']))->toBe([], $path)
            ->and($call['response']->headers->has('Content-Disposition'))->toBeFalse();
    }
})->with([
    'another site\'s image' => ['cross-site', 'no-cors', 'image'],
    'another site\'s frame' => ['cross-site', 'navigate', 'iframe'],
    'another site\'s fetch' => ['cross-site', 'cors', 'empty'],
    'another site\'s video' => ['cross-site', 'no-cors', 'video'],
    'a sibling subdomain\'s image' => ['same-site', 'no-cors', 'image'],
    'a sibling subdomain\'s frame' => ['same-site', 'navigate', 'iframe'],
    'a sibling subdomain\'s fetch' => ['same-site', 'cors', 'empty'],
    'nowhere, but not a navigation' => ['none', 'no-cors', 'image'],
]);

it('sends a link from elsewhere straight to the bytes to the page instead, before anything is read', function (string $from): void {
    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');
    $navigation = ['Sec-Fetch-Site' => $from, 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Sec-Fetch-User' => '?1'];

    // A guest: to the page, then from the page to sign in, which keeps the page.
    $file = dlCall('GET', dlFile($pdf), $navigation);

    expect($file['status'])->toBe(303)
        ->and($file['response']->headers->get('Location'))->toBe(dlPage($pdf))
        ->and(array_diff(dlTables($file), ['sites', 'orgs']))->toBe([]);

    $page = dlCall('GET', dlPage($pdf), $navigation);

    expect($page['status'])->toBe(303)
        ->and($page['response']->headers->get('Location'))->toBe('/golfdom/account/sign-in')
        ->and(app('session.store')->get(RequireReader::INTENDED))->toBe(dlPage($pdf));

    // A holder: the same 303 from the bytes, and the page itself.
    dlSignIn();

    expect(dlCall('GET', dlFile($pdf), $navigation)['response']->headers->get('Location'))->toBe(dlPage($pdf))
        ->and(dlCall('GET', dlFile(999999), $navigation)['response']->headers->get('Location'))->toBe(dlPage(999999))
        ->and(dlCall('GET', dlPage($pdf), $navigation)['status'])->toBe(200)
        // An id the column cannot hold is put in no `Location`.
        ->and(dlCall('GET', dlFile('abc'), $navigation)['status'])->toBe(404);
})->with(['another site' => ['cross-site'], 'a sibling subdomain' => ['same-site'], 'a bookmark or a typed address' => ['none']]);

it('answers a Sec-Fetch-Site it does not know with 404', function (string $value): void {
    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');
    dlSignIn();

    foreach ([dlPage($pdf), dlFile($pdf)] as $path) {
        $call = dlCall('GET', $path, ['Sec-Fetch-Site' => $value, 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document']);

        expect($call['status'])->toBe(404)
            ->and(array_diff(dlTables($call), ['sites', 'orgs']))->toBe([]);
    }
})->with([
    'a value of no browser' => ['cross-origin'],
    'another spelling' => ['Cross-Site'],
    'nothing' => [''],
    'two values in one' => ['same-origin, cross-site'],
]);

it('lets through what the browser marks as this site\'s own, and what it does not mark at all', function (): void {
    $png = dlOffer(dlStore('golfdom', 'Course map', 'png'));
    dlGrant('golfdom');
    dlSignIn();

    // This site's own page showing the image, and a browser that sends no Fetch Metadata.
    expect(dlCall('GET', dlFile($png), ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'image'])['body'])->toBe(base64_decode(DL_PNG))
        ->and(dlCall('GET', dlFile($png), ['Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'image'])['body'])->toBe(base64_decode(DL_PNG))
        ->and(dlCall('GET', dlPage($png), ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'])['status'])->toBe(200);
});

// ---- Faults -----------------------------------------------------------------------------------------------------------

it('answers 503 when what a reader holds or the files cannot be read — the same for every id, one SQLSTATE line, nothing reported', function (string $table): void {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('DDL commits implicitly on MySQL and MariaDB; SQLite and PostgreSQL measure this.');
    }

    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');
    dlSignIn();
    Exceptions::fake();

    DB::statement("alter table {$table} rename to {$table}_elsewhere");
    $answers = [];

    try {
        foreach ([dlPage($pdf), dlFile($pdf), dlPage(999999), dlFile(999999)] as $path) {
            $logged = dlLogged(function () use ($path, &$answers): void {
                $answers[$path] = dlInSavepoint(static fn (): array => dlCall('GET', $path));
            });

            expect($answers[$path]['status'])->toBe(503, $path)
                ->and($logged)->toHaveCount(1)
                ->and($logged[0][0])->toBe('warning')
                ->and($logged[0][1])->toMatch(DL_ELSEWHERE)
                ->and($logged[0][2])->toBe([]);
        }
    } finally {
        DB::statement("alter table {$table}_elsewhere rename to {$table}");
    }

    expect(array_unique(array_column($answers, 'body')))->toHaveCount(1)
        ->and(reset($answers)['body'])->toContain('Downloads can&#039;t be checked right now')
        ->and(reset($answers)['body'])->not->toContain((string) $pdf->getKey().'/file')
        ->and(reset($answers)['response']->headers->has('Retry-After'))->toBeFalse();

    Exceptions::assertNothingReported();

    // And once the table is back, the file is.
    expect(dlCall('GET', dlFile($pdf))['body'])->toBe(DL_PDF);
})->with(['the entitlements' => ['entitlements'], 'the files' => ['media_files']]);

/*
 * ⚠️ THE SAME FAULT WITHOUT DDL, SO EVERY ENGINE MEASURES IT. The test above renames a table, which MySQL and MariaDB
 * commit implicitly; here the connection refuses each statement on the table before it is sent, with the error a driver
 * raises — a `QueryException` over a `PDOException` carrying a SQLSTATE — as `EntitlementPageTest` and
 * `MediaDisposalTest` inject theirs. Nothing reaches the database, so no transaction is left aborted on PostgreSQL.
 */
it('answers 503 on every engine when the database refuses what a reader holds or the files — the same for every id, one SQLSTATE line, nothing reported', function (string $table): void {
    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');
    dlSignIn();
    Exceptions::fake();

    $failing = true;
    $refusals = 0;
    DB::connection()->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use ($table, &$failing, &$refusals): void {
        if ($failing && preg_match('/\b(?:from|join)\s+["`]?'.$table.'["`]?(?:\s|$)/i', $query) === 1) {
            $refusals++;
            $driver = new PDOException('SQLSTATE[HY000]: General error: 1 disk I/O error');
            $driver->errorInfo = ['HY000', 1, 'disk I/O error'];

            throw new QueryException((string) $connection->getName(), $query, $bindings, $driver);
        }
    });

    $answers = [];

    foreach ([dlPage($pdf), dlFile($pdf), dlPage(999999), dlFile(999999)] as $path) {
        $before = $refusals;
        $logged = dlLogged(function () use ($path, &$answers): void {
            $answers[$path] = dlCall('GET', $path);
        });

        expect($refusals)->toBe($before + 1, $path)
            ->and($answers[$path]['status'])->toBe(503, $path)
            ->and($logged)->toHaveCount(1)
            ->and($logged[0][0])->toBe('warning')
            ->and($logged[0][1])->toMatch(DL_ELSEWHERE)
            ->and($logged[0][1])->toContain('(SQLSTATE HY000)')
            ->and($logged[0][2])->toBe([]);
    }

    expect(array_unique(array_column($answers, 'body')))->toHaveCount(1)
        ->and(reset($answers)['body'])->toContain('Downloads can&#039;t be checked right now')
        ->and(reset($answers)['body'])->not->toContain((string) $pdf->getKey().'/file')
        ->and(reset($answers)['response']->headers->has('Retry-After'))->toBeFalse();

    Exceptions::assertNothingReported();

    // And once the database answers again, the file is there.
    $failing = false;

    expect(dlCall('GET', dlFile($pdf))['body'])->toBe(DL_PDF);
})->with(['the entitlements' => ['entitlements'], 'the files' => ['media_files']]);

it('leaves a failure to load the reader to the host: a 500 that names no reader', function (): void {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('DDL commits implicitly on MySQL and MariaDB; SQLite and PostgreSQL measure this.');
    }

    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');
    dlSignIn();

    DB::statement('alter table readers rename to readers_elsewhere');

    try {
        $call = null;
        $logged = dlLogged(function () use ($pdf, &$call): void {
            $call = dlInSavepoint(static fn (): array => dlCall('GET', dlFile($pdf)));
        });
    } finally {
        DB::statement('alter table readers_elsewhere rename to readers');
    }

    // The host's report of `ReaderAccounts`' own refusal: its SQLSTATE, no query, no bindings, and no `userId` — the
    // handler's attempt to add one asks the same table, and fails.
    expect($call['status'])->toBe(500)
        ->and($call['body'])->not->toContain('subscriber@kitsune.test')
        ->and($logged)->toHaveCount(1)
        ->and($logged[0][0])->toBe('error')
        ->and($logged[0][1])->toMatch('/^The readers table could not be read or written \(SQLSTATE [0-9A-Z]{5}\)\.$/D')
        ->and(array_keys($logged[0][2]))->toBe(['exception'])
        ->and($logged[0][2]['exception']->getPrevious())->toBeNull();
});

// ---- A file whose bytes are gone ------------------------------------------------------------------------------------

it('answers a holder whose file has lost its bytes with the 404 and one line, and anybody else with no line and no read', function (): void {
    $held = dlOffer(dlStore('golfdom', 'Held'));
    $other = dlOffer(dlStore('golfdom', 'Other'), 'course.other');
    dlGrant('golfdom');
    dlSignIn();
    $refused = dlCall('GET', dlPage(999999))['body'];

    foreach ([$held, $other] as $entry) {
        Storage::disk(MediaDisks::PRIVATE)->delete(dlRow($entry)->path);
    }

    foreach ([dlPage($held), dlFile($held)] as $path) {
        RefusingDisk::forgetLog();
        $call = null;
        $logged = dlLogged(function () use ($path, &$call): void {
            $call = dlCall('GET', $path);
        });

        expect($call['status'])->toBe(404)
            ->and($call['body'])->toBe($refused)
            ->and($logged)->toHaveCount(1)
            ->and($logged[0][1])->toStartWith('Kitsune has a media_files row whose bytes are missing: ['.MediaDisks::PRIVATE.':'.dlRow($held)->path.'] for entry '.$held->getKey().'.')
            ->and($logged[0][1])->not->toContain('subscriber@kitsune.test')
            ->and(RefusingDisk::$log)->not->toBe([]);
    }

    foreach ([dlPage($other), dlFile($other)] as $path) {
        RefusingDisk::forgetLog();
        $call = null;
        $logged = dlLogged(function () use ($path, &$call): void {
            $call = dlCall('GET', $path);
        });

        expect($call['status'])->toBe(404)
            ->and($call['body'])->toBe($refused)
            ->and($logged)->toBe([])
            ->and(RefusingDisk::$log)->toBe([]);
    }
});

it('reads a disk that throws as one without the bytes: the 404 and one line, never a 500', function (): void {
    $held = dlOffer(dlStore('golfdom', 'Held'));
    dlGrant('golfdom');
    dlSignIn();
    $path = dlRow($held)->path;

    // A disk configured to throw: what it raises is Flysystem's own, which Laravel passes on.
    $root = rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/');
    Storage::set(MediaDisks::PRIVATE, new LocalFilesystemAdapter(new Filesystem($this->disk), $this->disk, ['driver' => 'local', 'root' => $root, 'throw' => true]));
    $this->disk->unknown[] = $path;
    $this->disk->unreadable[] = $path;

    foreach ([dlPage($held), dlFile($held)] as $request) {
        $call = null;
        $logged = dlLogged(function () use ($request, &$call): void {
            $call = dlCall('GET', $request);
        });

        expect($call['status'])->toBe(404)
            ->and($logged)->toHaveCount(1)
            ->and($logged[0][1])->toStartWith('Kitsune has a media_files row whose bytes are missing: ');
    }
});

// ---- HEAD -------------------------------------------------------------------------------------------------------------

it('answers HEAD as it answers GET, everywhere, with no body', function (): void {
    $pdf = dlOffer(dlStore('golfdom'));
    $other = dlOffer(dlStore('golfdom', 'Other'), 'course.other');
    dlGrant('golfdom');
    $elsewhere = ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'image'];
    $followed = ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'];

    $requests = static fn (): array => [
        [dlPage($pdf), []], [dlFile($pdf), []], [dlPage($other), []], [dlFile($other), []], [dlFile(999999), []], [dlFile('abc'), []],
        [dlFile($pdf), $elsewhere], [dlFile($pdf), $followed],
    ];

    foreach (['a guest' => false, 'a holder' => true] as $who => $signedIn) {
        if ($signedIn) {
            dlSignIn();
        }

        foreach ($requests() as [$path, $headers]) {
            $get = dlCall('GET', $path, $headers);
            $head = dlCall('HEAD', $path, $headers);

            expect($head['status'])->toBe($get['status'], "{$who}: {$path}")
                ->and($head['response']->headers->get('Location'))->toBe($get['response']->headers->get('Location'))
                ->and($head['body'])->toBe('');
        }
    }

    $get = dlCall('GET', dlFile($pdf));
    $head = dlCall('HEAD', dlFile($pdf));

    expect($head['status'])->toBe(200)
        ->and($head['body'])->toBe('')
        ->and($head['queries'])->toBe($get['queries'])
        ->and($head['response']->headers->get('Content-Length'))->toBe((string) strlen(DL_PDF))
        ->and($head['response']->headers->get('Content-Disposition'))->toBe($get['response']->headers->get('Content-Disposition'));

    dlHasFileHeaders($head['response']);
});

// ---- A link's query -------------------------------------------------------------------------------------------------

it('keeps a query\'s token out of the page kept for after sign-in, and out of the session\'s last page', function (): void {
    $pdf = dlOffer(dlStore('golfdom'));
    dlGrant('golfdom');

    $guest = dlCall('GET', dlPage($pdf).'?token=abc');

    expect($guest['status'])->toBe(303)
        ->and(app('session.store')->get(RequireReader::INTENDED))->toBe(dlPage($pdf))
        ->and(app('session.store')->previousUrl())->toBe('http://localhost'.dlPage($pdf));

    dlSignIn();

    foreach ([dlPage($pdf), dlFile($pdf)] as $path) {
        expect(dlCall('GET', $path.'?token=abc')['status'])->toBe(200)
            ->and(app('session.store')->previousUrl())->toBe('http://localhost'.$path);
    }
});
