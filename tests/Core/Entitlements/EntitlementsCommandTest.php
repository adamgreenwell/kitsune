<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * `kitsune:entitlements` — ADR-040: whether entitlements can work here, in counts that name nothing, and one reader's
 * export and erasure (Adam, 2026-10-06), never echoing the reader.
 */

beforeEach(function (): void {
    Fx::boot();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'));
    Fx::declareReaders();
    $this->org = Fx::org('acme');
    $this->site = Fx::site($this->org, 'main');
    $this->reader = Fx::reader();
    DB::table('test_readers')->where('id', $this->reader->getKey())->update(['id' => 81234]);
    $this->id = 81234;

    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:93177', null);
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:93178', CarbonImmutable::parse('2026-10-07', 'UTC'));
    Fx::writer()->grant($this->id, 'course.other', 'commerce.order:93179', null);
    Fx::writer()->revoke($this->id, 'course.other', 'commerce.order:93179');
    Fx::site($this->org, 'second');
    Fx::writer()->grant($this->id, 'course.advanced-php', 'commerce.order:93180', null);

    app(Context::class)->forget();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
});

afterEach(fn () => Fx::tearDown());

function entitlementsCommand(array $arguments): array
{
    $code = Artisan::call('kitsune:entitlements', $arguments);

    return [$code, Artisan::output()];
}

it('reports a usable guard and counts that name nothing', function (): void {
    [$code, $out] = entitlementsCommand(['action' => 'status']);

    expect($code)->toBe(0)
        ->and($out)->toBe("Reader guard: declared and usable [test_readers].\nEntitlements: 4 grants on 2 sites — 2 live, 1 lapsed, 1 revoked.\n");
});

it('reports no guard declared without failing, and an unusable one by failing', function (): void {
    config([ReaderGuard::CONFIG => null]);
    Fx::forget();
    [$code, $out] = entitlementsCommand(['action' => 'status']);

    expect($code)->toBe(0)
        ->and($out)->toStartWith("Reader guard: none declared (kitsune.readers.guard) — every entitlement check answers no.\n");

    Fx::declareReaders(TestUser::class);
    [$code, $out] = entitlementsCommand(['action' => 'status']);

    expect($code)->toBe(1)
        ->and($out)->toStartWith("Reader guard: declared and NOT usable — the declared reader guard's model [".TestUser::class.'] is scoped through membership, as a panel user is.');
});

it('exports one reader as JSON alone, with sources and no reader id', function (): void {
    [$code, $out] = entitlementsCommand(['action' => 'export', '--org' => 'acme', '--reader' => '81234']);
    $records = json_decode($out, true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($records)->toHaveCount(4)
        ->and(array_column($records, 'source'))->toBe(['commerce.order:93177', 'commerce.order:93178', 'commerce.order:93179', 'commerce.order:93180'])
        ->and(array_keys($records[0]))->toBe(['site', 'entitlement', 'source', 'state', 'expires_at', 'revoked_at', 'changed_at'])
        ->and($out)->not->toContain('81234')
        ->and(app(Context::class)->orgId())->toBeNull();
});

it('erases only with --force, and says how many', function (): void {
    [$code, $out] = entitlementsCommand(['action' => 'forget', '--org' => 'acme', '--reader' => '81234']);

    expect($code)->toBe(1)
        ->and($out)->toBe("Refusing: forget deletes a reader's entitlements, from every source, on every site of the organisation. Run it again with --force.\n")
        ->and(DB::table('entitlements')->count())->toBe(4);

    [$code, $out] = entitlementsCommand(['action' => 'forget', '--org' => 'acme', '--reader' => '81234', '--force' => true]);

    expect($code)->toBe(0)
        ->and($out)->toBe("Erased 4 entitlement grants.\n")
        ->and(DB::table('entitlements')->count())->toBe(0)
        ->and(DB::table('audit_log')->where('action', EntitlementWriter::ERASED)->whereNull('actor_id')->count())->toBe(4);
});

it('exports and erases the readers of a soft-deleted org', function (): void {
    $this->org->delete();

    [$code, $out] = entitlementsCommand(['action' => 'export', '--org' => 'acme', '--reader' => '81234']);

    expect($code)->toBe(0)
        ->and(json_decode($out, true))->toHaveCount(4);

    [$code, $out] = entitlementsCommand(['action' => 'forget', '--org' => 'acme', '--reader' => '81234', '--force' => true]);

    expect($code)->toBe(0)
        ->and($out)->toBe("Erased 4 entitlement grants.\n");
});

it('refuses an unknown org by its slug, a missing option, and an unknown action without echoing it', function (): void {
    expect(entitlementsCommand(['action' => 'export', '--org' => 'nowhere', '--reader' => '81234']))
        ->toBe([1, "Refusing: no organisation has the slug [nowhere]. Nothing was read or written.\n"])
        ->and(entitlementsCommand(['action' => 'export', '--org' => 'acme']))
        ->toBe([1, "Refusing: export needs --org=<slug> and --reader=<id>.\n"])
        ->and(entitlementsCommand(['action' => '81234']))
        ->toBe([1, "Refusing: kitsune:entitlements has three actions: status, export and forget.\n"]);
});

it('never prints the reader, even when it refuses one', function (): void {
    [$code, $out] = entitlementsCommand(['action' => 'export', '--org' => 'acme', '--reader' => '81234 x']);

    expect($code)->toBe(1)
        ->and($out)->toContain('that is not an identifier a reader can have here')
        ->and($out)->not->toContain('81234');
});

it('records nothing when it reads', function (): void {
    app(Context::class)->setOrg($this->org);
    $audited = AuditLog::query()->count();
    app(Context::class)->forget();

    entitlementsCommand(['action' => 'status']);
    entitlementsCommand(['action' => 'export', '--org' => 'acme', '--reader' => '81234']);

    app(Context::class)->setOrg($this->org);

    expect(AuditLog::query()->count())->toBe($audited);
});
