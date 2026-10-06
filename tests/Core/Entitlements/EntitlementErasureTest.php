<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementRecords;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\TestReader;

/*
 * A reader's export and erasure — Standing Principle #8 and ADR-020, shipped now (Adam, 2026-10-06): every source, on
 * every site of the org, and nothing of anyone else's.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    Fx::declareReaders();

    $this->org = Fx::org('acme');
    $this->second = Fx::site($this->org, 'second');
    $this->main = Fx::site($this->org, 'main');
    $this->reader = Fx::reader();
    $this->id = (int) $this->reader->getKey();
    $this->other = Fx::reader(email: 'other@example.test');
    Fx::owner();

    $grant = fn (string $name, string $source, ?string $until = null) => Fx::writer()->grant($this->id, $name, $source, $until !== null ? CarbonImmutable::parse($until, 'UTC') : null);

    $grant('course.advanced-php', 'commerce.order:2');
    $grant('course.advanced-php', 'commerce.order:1', '2026-11-01 00:00:00');
    Fx::writer()->comp($this->id, 'course.advanced-php', null);
    Fx::writer()->revoke($this->id, 'course.advanced-php', 'commerce.order:2');
    Fx::writer()->grant((int) $this->other->getKey(), 'course.advanced-php', 'commerce.order:3', null);

    app(Context::class)->setSite($this->second);
    $grant('download.whitepaper-2026', 'import.legacy:batch-7');

    // Another org's row under the same reader id, planted: erasing this org's reader must not reach it.
    $this->rival = Fx::org('rival');
    $this->rivalSite = Fx::site($this->rival, 'main');
    Fx::plant($this->rivalSite, (string) $this->id, 'course.advanced-php');

    app(Context::class)->setSite($this->main);
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC'));
});

afterEach(fn () => Fx::tearDown());

it('exports every row, every source and every site, in a stable order, with nothing to identify the reader', function (): void {
    $records = EntitlementRecords::forReader($this->id);

    expect($records)->toBe([
        ['site' => 'main', 'entitlement' => 'course.advanced-php', 'source' => 'commerce.order:1', 'state' => 'lapsed', 'expires_at' => '2026-11-01T00:00:00Z', 'revoked_at' => null, 'changed_at' => '2026-10-06T12:00:00Z'],
        ['site' => 'main', 'entitlement' => 'course.advanced-php', 'source' => 'commerce.order:2', 'state' => 'revoked', 'expires_at' => null, 'revoked_at' => '2026-10-06T12:00:00Z', 'changed_at' => '2026-10-06T12:00:00Z'],
        ['site' => 'main', 'entitlement' => 'course.advanced-php', 'source' => 'core.comp', 'state' => 'live', 'expires_at' => null, 'revoked_at' => null, 'changed_at' => '2026-10-06T12:00:00Z'],
        ['site' => 'second', 'entitlement' => 'download.whitepaper-2026', 'source' => 'import.legacy:batch-7', 'state' => 'live', 'expires_at' => null, 'revoked_at' => null, 'changed_at' => '2026-10-06T12:00:00Z'],
    ])->and(EntitlementRecords::forReader($this->id))->toBe($records);
});

it('erases every row of the reader in the org, recording each, and nothing else', function (): void {
    $kept = DB::table('entitlements')->where(fn ($q) => $q->where('reader_id', '!=', (string) $this->id)->orWhere('org_id', $this->rival->getKey()))->orderBy('id')->get()->all();
    $erasedIds = DB::table('entitlements')->where('org_id', $this->org->getKey())->where('reader_id', (string) $this->id)->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();

    expect(Fx::writer()->forget($this->id))->toBe(4)
        ->and(DB::table('entitlements')->orderBy('id')->get()->all())->toEqual($kept)
        ->and(AuditLog::query()->where('action', EntitlementWriter::ERASED)->pluck('target_id')->sort()->values()->all())->toBe($erasedIds)
        ->and(EntitlementRecords::forReader($this->id))->toBe([]);
});

it('erases a reader the host has already deleted, and with no guard declared at all', function (bool $undeclare): void {
    TestReader::query()->whereKey($this->id)->delete();

    if ($undeclare) {
        config([ReaderGuard::CONFIG => null]);
        Fx::forget();
    }

    expect(Fx::writer()->forget((string) $this->id))->toBe(4);
})->with(['deleted' => [false], 'and undeclared' => [true]]);

it('writes and records nothing when there is nothing to erase', function (): void {
    $audited = AuditLog::query()->count();

    expect(Fx::writer()->forget(999999))->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audited);
});
