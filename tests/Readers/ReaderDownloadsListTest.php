<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Once;
use Kitsune\Core\Entitlements\EntitlementRead;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Readers\DownloadOffers;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    $this->site = $this->world['sites']['golfdom'];
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'downloads@kitsune.test');
    app(Context::class)->setOrg($this->world['golfdom'])->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->world['golfdom']->id, 'handle' => 'files', 'name' => 'Files', 'plural_name' => 'Files', 'is_media' => true]);
    ReaderFixture::signIn($this->reader);
    EntitlementFixture::plant($this->site, (string) $this->reader->id, 'course.held');
});

afterEach(fn () => ReaderFixture::forget());

/** Synthetic persisted rows, with no storage operation: this lists offers rather than proving byte availability. */
function listOffer(string $title, ?string $gate = 'course.held', array $entry = [], array $file = []): int
{
    $id = DB::table('entries')->insertGetId($entry + [
        'site_id' => test()->site->id, 'org_id' => test()->world['golfdom']->id,
        'entry_type_id' => test()->type->id, 'type_handle' => 'files', 'title' => $title,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('media_files')->insert($file + [
        'entry_id' => $id, 'disk' => MediaDisks::PRIVATE, 'path' => 'never-read/'.$id.'.pdf',
        'mime' => 'application/pdf', 'size_bytes' => 100, 'checksum' => str_repeat('a', 64),
        'visibility' => 'private', 'reader_entitlement' => $gate, 'created_at' => now(),
    ]);

    return (int) $id;
}

/** The list's body has no CSRF form or secret; identical body includes identical navigation. */
function listBody(string $page = ''): string
{
    return (string) test()->readerGet('/golfdom/account/downloads'.$page)->assertOk()->getContent();
}

it('shows only current authorized offers and never probes disk or links directly to bytes', function (): void {
    $id = listOffer('My <private> report');
    listOffer('Unheld confidential title', 'secret.unheld');
    listOffer('Public title', file: ['visibility' => 'public']);
    listOffer('Shared title', entry: ['site_id' => null]);
    listOffer('Other Site title', entry: ['site_id' => $this->world['sites']['golfdom-fr']->id]);
    listOffer('Other Org title', entry: ['site_id' => $this->world['sites']['rival']->id, 'org_id' => $this->world['rival']->id]);
    listOffer('Trashed title', entry: ['deleted_at' => now()]);
    listOffer('Unrelated malformed title', 'COURSE.held');
    Storage::shouldReceive('disk')->never();

    $body = listBody();
    expect($body)->toContain('My &lt;private&gt; report', '/golfdom/account/downloads/'.$id, '<bdi>')
        ->not->toContain('Unheld confidential', 'Public title', 'Shared title', 'Other Site title', 'Other Org title',
            'Trashed title', 'Unrelated malformed', 'course.held', 'never-read', '/file', 'application/pdf');
});

it('keeps hidden candidates from changing the body or advertising pages', function (): void {
    for ($i = 0; $i < 24; $i++) {
        listOffer('Owned '.$i);
    }
    $before = listBody();
    $disabled = EntryType::create(['org_id' => $this->world['golfdom']->id, 'handle' => 'disabled', 'name' => 'Disabled', 'plural_name' => 'Disabled', 'is_media' => true]);
    EntryTypeAvailability::create(['entry_type_id' => $disabled->id, 'scope_type' => 'site', 'scope_id' => $this->site->id, 'is_enabled' => false]);

    for ($i = 0; $i < 150; $i++) {
        listOffer('Hidden '.$i, 'secret.unheld');
        listOffer('Disabled '.$i, entry: ['entry_type_id' => $disabled->id]);
        listOffer('Foreign '.$i, entry: ['site_id' => $this->world['sites']['golfdom-fr']->id]);
    }
    foreach (['COURSE.held', 'cóurse.held', 'course.held ', 'no-dot', ''] as $gate) {
        listOffer('Malformed secret '.$gate, $gate);
    }

    expect(listBody())->toBe($before)->not->toContain('More downloads');
});

it('paginates eligible offers only with a finite lookahead and no total', function (): void {
    for ($i = 1; $i <= 49; $i++) {
        listOffer(sprintf('Owned item %02d', $i));
        listOffer('Hidden '.$i, 'secret.unheld');
    }
    $first = listBody();
    $second = listBody('/page/2');
    $third = listBody('/page/3');
    expect($first)->toContain('Owned item 01', 'Owned item 24', 'rel="next"', '/page/2')->not->toContain('Owned item 25', 'rel="prev"')
        ->and($second)->toContain('Owned item 25', 'Owned item 48', 'rel="prev"', '/page/3')->not->toContain('Owned item 49')
        ->and($third)->toContain('Owned item 49', 'rel="prev"')->not->toContain('rel="next"', 'Hidden');
    $this->readerGet('/golfdom/account/downloads/page/4')->assertNotFound();
    $this->readerGet('/golfdom/account/downloads/page/1')->assertRedirect('/golfdom/account/downloads')->assertStatus(303);
});

it('uses a constant number of reads for one and twenty-four rows', function (): void {
    listOffer('First');
    app(Context::class)->setOrg($this->world['golfdom'])->setSite($this->site);
    app(EntitlementRead::class)->current();
    $counts = [];

    foreach ([1, 24] as $amount) {
        if ($amount === 24) {
            for ($i = 1; $i < 24; $i++) {
                listOffer('More '.$i);
            }
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $found = app(DownloadOffers::class)->page(1);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $counts[] = count($queries);
        expect($found['offers'])->toHaveCount($amount)
            ->and($queries)->toHaveCount(3);
        expect(collect($queries)->filter(fn (array $q) => str_contains($q['query'], 'select distinct'))->count())->toBe(1);
    }

    expect($counts)->toBe([3, 3]);
});

it('returns an empty list for expired revoked foreign-reader and foreign-org authority', function (): void {
    DB::table('entitlements')->delete();
    listOffer('Expired', 'course.expired');
    listOffer('Revoked', 'course.revoked');
    listOffer('Other reader', 'course.other');
    listOffer('Wrong Org', 'course.foreign');
    EntitlementFixture::plant($this->site, (string) $this->reader->id, 'course.expired', expires: CarbonImmutable::now('UTC')->startOfSecond());
    EntitlementFixture::plant($this->site, (string) $this->reader->id, 'course.revoked', revoked: CarbonImmutable::now());
    EntitlementFixture::plant($this->site, '999999', 'course.other');
    EntitlementFixture::plant($this->site, (string) $this->reader->id, 'course.foreign', orgId: $this->world['rival']->id);

    expect(listBody())->toContain('There are no downloads')->not->toContain('Expired', 'Revoked', 'Wrong Org', 'Other reader', 'More downloads');
});

it('refuses matching malformed persisted authority before any batch or partial metadata', function (): void {
    listOffer('Never show this', 'BROKEN');
    EntitlementFixture::plant($this->site, (string) $this->reader->id, 'BROKEN');
    $response = $this->readerGet('/golfdom/account/downloads')->assertStatus(503);
    expect((string) $response->getContent())->toContain('Downloads can’t be checked')
        ->not->toContain('Never show this', 'BROKEN', 'Download pages', 'More downloads');
});

it('rechecks lookahead access and availability before advertising another page', function (string $change): void {
    for ($i = 0; $i < 24; $i++) {
        listOffer('Current '.$i);
    }
    $edge = EntryType::create(['org_id' => $this->world['golfdom']->id, 'handle' => 'edge', 'name' => 'Edge', 'plural_name' => 'Edges', 'is_media' => true]);
    listOffer('Withdrawn lookahead', 'course.edge', ['entry_type_id' => $edge->id]);
    EntitlementFixture::plant($this->site, (string) $this->reader->id, 'course.edge');
    $changed = false;
    DB::listen(function (QueryExecuted $query) use (&$changed, $change, $edge): void {
        if ($changed || ! str_contains($query->sql, 'kitsune_reader_gate')) {
            return;
        }
        $changed = true;
        if ($change === 'grant') {
            DB::table('entitlements')->where('entitlement', 'course.edge')->update(['revoked_at' => now()]);
        } else {
            EntryTypeAvailability::create(['entry_type_id' => $edge->id, 'scope_type' => 'site', 'scope_id' => $this->site->id, 'is_enabled' => false]);
        }
    });

    expect(listBody())->not->toContain('More downloads', 'Withdrawn lookahead');
    expect($changed)->toBeTrue();
})->with(['grant', 'type']);

it('sends guests to sign-in and preserves an exact prefixed page return', function (): void {
    $this->readerPost('/golfdom/account/sign-out')->assertRedirect();
    $this->readerGet('/golfdom/account/downloads/page/2')->assertStatus(303)->assertRedirect('/golfdom/account/sign-in');
    $this->readerPost('/golfdom/account/sign-in', ['email' => $this->reader->email, 'password' => ReaderFixture::PASSWORD])
        ->assertRedirect('/golfdom/account/downloads/page/2');
});

it('keeps account navigation prefix-aware and refuses a disabled Reader Area', function (): void {
    $this->readerGet('/golfdom/account')->assertOk()->assertSee('href="/golfdom/account/downloads"', false);
    ReaderFixture::mode($this->world['golfdom'], 'off');
    $this->readerGet('/golfdom/account/downloads')->assertNotFound();
});

it('refuses noncanonical unbounded and malformed page parameters', function (string $page): void {
    $this->readerGet('/golfdom/account/downloads/page/'.$page)->assertNotFound();
})->with(['0', '00', '01', '-1', 'abc', '1e2', '10001', '999999999999999999999999']);

it('uses fallback copy direction while isolating a mixed-direction file title', function (): void {
    $this->site->locale = 'ar';
    $this->site->save();
    listOffer('تقرير report <script>');
    $body = listBody();
    expect($body)->toContain('lang="ar"', 'dir="rtl"', '<main', 'lang="en"', 'dir="ltr"', '<bdi>تقرير report &lt;script&gt;</bdi>');
});

it('refuses database failures with one safe notice and no partial file metadata', function (): void {
    listOffer('Secret outage title');
    $active = true;
    DB::connection()->beforeExecuting(static function (string $query, array $bindings, Connection $connection) use (&$active): void {
        if ($active && str_contains($query, 'media_files')) {
            $driver = new PDOException('sensitive-reader@kitsune.test secret binding');
            $driver->errorInfo = ['HY000', 1, 'private'];
            throw new QueryException((string) $connection->getName(), $query, $bindings, $driver);
        }
    });
    try {
        $body = (string) $this->readerGet('/golfdom/account/downloads')->assertStatus(503)->getContent();
        expect($body)->toContain('Downloads can’t be checked')
            ->not->toContain('Secret outage title', 'sensitive-reader', 'course.held', 'Download pages');
    } finally {
        $active = false;
    }
});

it('fails closed before candidate SQL without a current Site Org or reader', function (): void {
    foreach (['site', 'org', 'reader'] as $missing) {
        ReaderFixture::forget();
        if ($missing === 'site') {
            app(Context::class)->setOrg($this->world['golfdom']);
        } elseif ($missing === 'reader') {
            $this->readerPost('/golfdom/account/sign-out');
            app(Context::class)->setOrg($this->world['golfdom'])->setSite($this->site);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read = app(EntitlementRead::class)->current();
        $found = app(DownloadOffers::class)->page(1);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        expect($read)->toBeNull()->and($found)->toBe(['offers' => [], 'next' => false]);
        expect(collect($queries)->filter(fn (array $q) => str_contains($q['query'], 'media_files'))->count())->toBe(0);
    }
});

it('uses only owned or global media types and never a planted foreign or non-media type', function (): void {
    $global = EntryType::create(['org_id' => null, 'handle' => 'global-file', 'name' => 'Global', 'plural_name' => 'Global', 'is_media' => true]);
    $article = EntryType::create(['org_id' => $this->world['golfdom']->id, 'handle' => 'article-list', 'name' => 'Article', 'plural_name' => 'Articles']);
    $foreign = EntryType::create(['org_id' => $this->world['rival']->id, 'handle' => 'foreign-file', 'name' => 'Foreign', 'plural_name' => 'Foreign', 'is_media' => true]);
    listOffer('Global available file', entry: ['entry_type_id' => $global->id]);
    listOffer('Non-media secret', entry: ['entry_type_id' => $article->id]);
    listOffer('Foreign type secret', entry: ['entry_type_id' => $foreign->id]);
    expect(listBody())->toContain('Global available file')->not->toContain('Non-media secret', 'Foreign type secret');
});

it('wraps fixed candidate columns with configured prefixes including native byte equality', function (): void {
    app(Context::class)->setOrg($this->world['golfdom'])->setSite($this->site);
    app(EntitlementRead::class)->current(); // Warm the host reader while its real table still has its real name.
    $connection = DB::connection();
    $prefix = $connection->getTablePrefix();
    $sql = '';
    $capture = true;
    $connection->beforeExecuting(static function (string $query) use (&$sql, &$capture): void {
        if ($capture && str_contains($query, 'probe_entries')) {
            $sql = $query;
            throw new RuntimeException('captured before execution');
        }
    });
    try {
        $connection->setTablePrefix('probe_');
        try {
            app(DownloadOffers::class)->page(1);
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('captured before execution');
        }
    } finally {
        $capture = false;
        $connection->setTablePrefix($prefix);
    }
    $plain = str_replace(['"', '`'], '', $sql);
    expect($plain)->toContain('probe_entries', 'probe_media_files', 'probe_entitlements.entitlement = probe_media_files.reader_entitlement');
    if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        expect($plain)->toContain('CAST(probe_entitlements.entitlement AS BINARY) = CAST(probe_media_files.reader_entitlement AS BINARY)');
    }
});

it('never renders captured offers after a reader Site or Org switch during a read', function (string $phase, string $scope): void {
    listOffer('Captured confidential title');
    $other = ReaderFixture::reader($this->world['golfdom'], 'other-list-reader@kitsune.test');
    EntitlementFixture::plant($this->site, (string) $other->id, 'course.held');
    EntitlementFixture::plant($this->world['sites']['golfdom-fr'], (string) $this->reader->id, 'course.held');
    ReaderFixture::signIn($this->reader);
    $switched = false;
    DB::listen(function (QueryExecuted $query) use ($phase, $scope, $other, &$switched): void {
        $candidate = str_contains($query->sql, 'kitsune_reader_gate');
        $batch = str_contains($query->sql, 'select distinct');
        $availability = ! $candidate && str_contains($query->sql, 'entry_type_availability');
        if ($switched || ! match ($phase) {
            'candidate' => $candidate, 'batch' => $batch, default => $availability
        }) {
            return;
        }
        $switched = true;
        $context = app(Context::class);
        if ($scope === 'reader') {
            ReaderFixture::signIn($other);
            $context->setSite($this->site);
        } elseif ($scope === 'site') {
            $context->forget()->setOrg($this->world['golfdom'])->setSite($this->world['sites']['golfdom-fr']);
        } else {
            $context->forget()->setOrg($this->world['rival'])->setSite($this->world['sites']['rival']);
        }
    });
    $body = (string) $this->readerGet('/golfdom/account/downloads')->assertStatus(503)->getContent();
    expect($switched)->toBeTrue()->and($body)->not->toContain('Captured confidential title', 'Download pages', 'More downloads');
})->with([
    ['candidate', 'reader'], ['candidate', 'site'], ['candidate', 'org'],
    ['batch', 'reader'], ['batch', 'site'], ['batch', 'org'],
    ['availability', 'reader'], ['availability', 'site'], ['availability', 'org'],
]);

it('keeps actual GET rendering and middleware reads flat for one and twenty-four offers', function (): void {
    listOffer('First endpoint offer');
    $counts = [];
    $families = [];
    foreach ([1, 24] as $amount) {
        if ($amount === 24) {
            for ($i = 1; $i < 24; $i++) {
                listOffer('More endpoint offers '.$i);
            }
        }
        Once::flush();
        SettingsResolver::forgetEverywhere();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->readerGet('/golfdom/account/downloads')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $counts[] = count($queries);
        $families[] = [
            'candidates' => collect($queries)->filter(fn (array $q) => str_contains($q['query'], 'kitsune_reader_gate'))->count(),
            'batch' => collect($queries)->filter(fn (array $q) => str_contains($q['query'], 'select distinct'))->count(),
            'availability' => collect($queries)->filter(fn (array $q) => str_contains($q['query'], 'entry_type_availability') && ! str_contains($q['query'], 'kitsune_reader_gate'))->count(),
        ];
    }
    expect($counts)->toBe([8, 8])
        ->and($families)->toBe([['candidates' => 1, 'batch' => 1, 'availability' => 1], ['candidates' => 1, 'batch' => 1, 'availability' => 1]]);
});

it('bounds a page before batch checking more than one hundred distinct live offer names', function (): void {
    for ($i = 1; $i <= 101; $i++) {
        $gate = 'bounded.item'.$i;
        listOffer(sprintf('Bounded offer %03d', $i), $gate);
        EntitlementFixture::plant($this->site, (string) $this->reader->id, $gate);
    }
    $body = listBody();
    expect(substr_count($body, '<li>'))->toBe(24)
        ->and($body)->toContain('Bounded offer 001', 'Bounded offer 024', 'More downloads')
        ->not->toContain('Bounded offer 025', 'Bounded offer 101');
});

it('validates the twenty-fifth lookahead before rendering any partial file metadata', function (): void {
    for ($i = 1; $i <= 24; $i++) {
        listOffer('Partial confidential title '.$i);
    }
    listOffer('Malformed lookahead confidential title', 'BROKEN');
    EntitlementFixture::plant($this->site, (string) $this->reader->id, 'BROKEN');
    $body = (string) $this->readerGet('/golfdom/account/downloads')->assertStatus(503)->getContent();
    expect($body)->toContain('Downloads can’t be checked')
        ->not->toContain('Partial confidential title', 'Malformed lookahead confidential title', 'BROKEN', 'More downloads', 'Download pages');
});
