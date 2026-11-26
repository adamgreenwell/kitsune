<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Http\Middleware\RequireReader;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

// ADR-041, canonical prefix amendment: claims and emitted addresses are canonical; old spellings remain aliases.
beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    $this->mixed = ReaderFixture::site($this->world['golfdom'], 'mixed-prefix', 'Mixed prefix', '/NeWs/Fr');
    ReaderFixture::mode($this->mixed, 'sign-in');
});

it('keeps the configured spelling while claiming and emitting one lower-case prefix', function (): void {
    $row = DB::table('sites')->where('id', $this->mixed->id)->first();

    expect($row->base_url)->toBe('/NeWs/Fr')
        ->and($row->path_prefix)->toBe('/news/fr')
        ->and(ReaderLinks::path($this->mixed, ReaderLinks::SIGN_IN))->toBe('/news/fr/account/sign-in');

    $this->mixed->name = 'Renamed without moving the address';
    $this->mixed->save();

    expect($this->mixed->fresh()->base_url)->toBe('/NeWs/Fr')
        ->and($this->mixed->fresh()->path_prefix)->toBe('/news/fr');
});

it('canonicalizes only a return path prefix and keeps the stored-return fence strict', function (string $path, string $expected): void {
    expect(ReaderLinks::canonicalPath($this->mixed, $path))->toBe($expected)
        ->and(ReaderLinks::isOwn($this->mixed, $expected))->toBeTrue();
})->with([
    ['/NeWs/Fr/account', '/news/fr/account'],
    ['/NEWS/FR/account/downloads', '/news/fr/account/downloads'],
    ['/news/fr/account/downloads/page/2', '/news/fr/account/downloads/page/2'],
    ['/News/Fr/account/downloads/12/file', '/news/fr/account/downloads/12/file'],
]);

it('refuses unsafe or foreign return paths rather than normalizing them into this site', function (string $path): void {
    expect(ReaderLinks::canonicalPath($this->mixed, $path))->toBeNull()
        ->and(ReaderLinks::isOwn($this->mixed, $path))->toBeFalse();
})->with([
    'other org' => ['/shop/account'],
    'sibling segment' => ['/news/fringe/account'],
    'nested site' => ['/news/fr/child/account'],
    'missing segment' => ['/news/account'],
    'absolute URL' => ['https://elsewhere.test/news/fr/account'],
    'protocol relative' => ['//elsewhere.test/news/fr/account'],
    'query' => ['/NEWS/FR/account?next=elsewhere'],
    'fragment' => ['/NEWS/FR/account#elsewhere'],
    'dot traversal' => ['/NEWS/FR/../account'],
    'encoded separator' => ['/NEWS%2FFR/account'],
    'encoded traversal' => ['/NEWS/FR/%2e%2e/account'],
    'backslash' => ['/NEWS/FR\\account'],
    'extra slash' => ['/NEWS//FR/account'],
    'changed account segment' => ['/NEWS/FR/ACCOUNT'],
    'changed leaf segment' => ['/NEWS/FR/account/DOWNLOADS'],
    'control' => ["/NEWS/FR/account\n"],
]);

it('keeps root reader paths exact while a prefixed site cannot claim them', function (): void {
    $root = new Site(['path_prefix' => '']);

    expect(ReaderLinks::canonicalPath($root, '/account/downloads'))->toBe('/account/downloads')
        ->and(ReaderLinks::canonicalPath($root, '/ACCOUNT/downloads'))->toBeNull()
        ->and(ReaderLinks::canonicalPath($root, '//account/downloads'))->toBeNull()
        ->and(ReaderLinks::canonicalPath($this->mixed, '/account/downloads'))->toBeNull()
        ->and(ReaderLinks::isOwn($this->mixed, '/NEWS/FR/account'))->toBeFalse();
});

it('returns a real mixed-case guest sign-in to its canonical downloads page without retaining the query', function (): void {
    ReaderFixture::reader($this->world['golfdom'], 'prefix-reader@kitsune.test');

    $this->readerGet('/NeWs/Fr/account/downloads?ignored=value')->assertRedirect('/news/fr/account/sign-in');

    expect(app('session.store')->get(RequireReader::INTENDED))->toBe('/news/fr/account/downloads');

    $this->readerPost('/news/fr/account/sign-in', ['email' => 'prefix-reader@kitsune.test', 'password' => ReaderFixture::PASSWORD])
        ->assertStatus(303)->assertRedirect('/news/fr/account/downloads');

    $this->readerGet('/news/fr/account/downloads')->assertOk();
});

it('preserves nested and sibling sites while refusing a rival orgs case-folded exact and overlapping claims', function (): void {
    $nested = ReaderFixture::site($this->world['golfdom'], 'nested-prefix', 'Nested', '/NEWS/FR/Child');
    $sibling = ReaderFixture::site($this->world['rival'], 'sibling-prefix', 'Sibling', '/news/fringe');
    $before = DB::table('sites')->count();

    foreach (['/NEWS/FR', '/News', '/nEwS/fR/deeper'] as $i => $path) {
        expect(fn () => ReaderFixture::site($this->world['rival'], 'refused-prefix-'.$i, 'Refused', $path))
            ->toThrow(RuntimeException::class, 'overlap');
        expect(DB::table('sites')->count())->toBe($before);
    }

    $this->readerGet('/NeWs/Fr/account/sign-in')->assertOk();
    expect(app(Context::class)->siteId())->toBe($this->mixed->id);
    ReaderFixture::mode($nested, 'sign-in');
    ReaderFixture::mode($sibling, 'sign-in');
    $this->readerGet('/news/fr/CHILD/account/sign-in')->assertOk();
    expect(app(Context::class)->siteId())->toBe($nested->id);
    $this->readerGet('/News/Fringe/account/sign-in')->assertOk();
    expect(app(Context::class)->siteId())->toBe($sibling->id)
        ->and(app(Context::class)->orgId())->toBe($this->world['rival']->id);
});
