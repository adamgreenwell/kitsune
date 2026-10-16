<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

beforeEach(function (): void {
    Fx::boot();
    Fx::declareReaders();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $this->org = Fx::org('retirement');
    $this->site = Fx::site($this->org, 'retirement');
    $this->reader = Fx::reader($this->org);
    $this->otherSite = Fx::site($this->org, 'other');
    $this->foreignOrg = Fx::org('foreign-retirement');
    $this->foreignSite = Fx::site($this->foreignOrg, 'foreign');
    app(Context::class)->setSite($this->site);
    $this->writer = Fx::writer();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

it('retires only inactive exact order sources and leaves every other grant untouched', function (): void {
    $expired = Fx::plant($this->site, (string) $this->reader->id, 'course.expired', 'commerce.order:73', now()->subSecond());
    $revoked = Fx::plant($this->site, (string) $this->reader->id, 'course.revoked', 'commerce.order:73', revoked: now());
    $other = Fx::plant($this->site, (string) $this->reader->id, 'course.comp', 'core.comp');
    $sibling = Fx::plant($this->otherSite, (string) $this->reader->id, 'course.sibling', 'commerce.order:73');
    $foreign = Fx::plant($this->foreignSite, 'foreign-reader', 'course.foreign', 'commerce.order:73');
    $calls = 0;
    $verify = function () use (&$calls): void {
        $calls++;
    };
    expect(DB::transaction(fn () => $this->writer->retireVerifiedOrderSource('commerce.order:73', $verify)))->toBe(2)
        ->and($calls)->toBe(2)->and(DB::table('entitlements')->whereIn('id', [$expired, $revoked])->count())->toBe(0)
        ->and(DB::table('entitlements')->whereIn('id', [$other, $sibling, $foreign])->count())->toBe(3);
    expect(DB::transaction(fn () => $this->writer->retireVerifiedOrderSource('commerce.order:73', $verify)))->toBe(0)
        ->and(DB::table('audit_log')->where('action', 'entitlement.erased')->count())->toBe(2);
});

it('refuses any surviving active source instead of erasing the rest', function (?string $end): void {
    $expired = Fx::plant($this->site, (string) $this->reader->id, 'course.expired', 'commerce.order:73', now()->subSecond());
    $active = Fx::plant($this->site, (string) $this->reader->id, 'course.active', 'commerce.order:73', $end === null ? null : now()->addDay());
    expect(fn () => DB::transaction(fn () => $this->writer->retireVerifiedOrderSource('commerce.order:73', static fn () => null)))
        ->toThrow(EntitlementRefused::class);
    expect(DB::table('entitlements')->whereIn('id', [$expired, $active])->count())->toBe(2)->and(DB::table('audit_log')->count())->toBe(0);
})->with([null, 'future']);

it('requires an existing transaction, an exact reserved source and ordinary owner or system authority', function (string $denial): void {
    Fx::plant($this->site, (string) $this->reader->id, 'course.expired', 'commerce.order:73', now()->subSecond());
    if ($denial === 'member') {
        Fx::member();
    }
    if ($denial === 'reader') {
        Fx::signIn($this->reader);
    }
    $run = fn () => $this->writer->retireVerifiedOrderSource($denial === 'source' ? 'core.comp' : 'commerce.order:73', static fn () => null);
    expect(fn () => $denial === 'transaction' ? $run() : DB::transaction($run))->toThrow(EntitlementRefused::class);
    expect(DB::table('entitlements')->count())->toBe(1)->and(DB::table('audit_log')->count())->toBe(0);
})->with(['source', 'member', 'reader']);

it('rolls the whole retirement back when audit callbacks corrupt its proof or recreate any erased source', function (string $poison): void {
    $id = Fx::plant($this->site, (string) $this->reader->id, 'course.expired', 'commerce.order:73', now()->subSecond());
    $original = (array) DB::table('entitlements')->where('id', $id)->sole();
    $oldAudit = in_array($poison, ['audit_pointer', 'foreign_audit_pointer'], true)
        ? app(Auditor::class)->recordForProducerOrFail('entitlement.erased', (new Entitlement)->newFromBuilder($original))->getKey() : null;
    if ($poison === 'foreign_audit_pointer') {
        DB::table('audit_log')->where('id', $oldAudit)->update(['org_id' => $this->foreignOrg->id, 'site_id' => $this->foreignSite->id]);
    }
    $beforeAudits = DB::table('audit_log')->count();
    AuditLog::created(function ($audit) use ($poison, $original, $oldAudit): void {
        if ($audit->action !== 'entitlement.erased') {
            return;
        }
        match ($poison) {
            'audit' => DB::table('audit_log')->where('id', $audit->id)->update(['action' => 'misfiled']),
            'audit_pointer' => $audit->setAttribute('id', $oldAudit),
            'foreign_audit_pointer' => (function () use ($audit, $oldAudit): void {
                DB::table('audit_log')->where('id', $oldAudit)->update(['org_id' => $this->org->id, 'site_id' => $this->site->id]);
                $audit->setAttribute('id', $oldAudit);
            })(),
            'source' => DB::table('entitlements')->insert($original),
            'changed_source' => DB::table('entitlements')->insert([...$original, 'source' => 'core.comp']),
            'sibling_source' => DB::table('entitlements')->insert([...$original, 'site_id' => $this->otherSite->id]),
            'foreign_source' => DB::table('entitlements')->insert([...$original, 'org_id' => $this->foreignOrg->id, 'site_id' => $this->foreignSite->id]),
            'authority' => Fx::member('changed-retirement@example.test'),
            'scope' => app(Context::class)->setSite($this->otherSite),
        };
    });
    try {
        expect(fn () => DB::transaction(fn () => $this->writer->retireVerifiedOrderSource('commerce.order:73', static fn () => null)))
            ->toThrow(EntitlementRefused::class);
    } finally {
        AuditLog::flushEventListeners();
    }
    expect((array) DB::table('entitlements')->where('id', $id)->sole())->toBe($original)->and(DB::table('audit_log')->count())->toBe($beforeAudits);
})->with(['audit', 'audit_pointer', 'foreign_audit_pointer', 'source', 'changed_source', 'sibling_source', 'foreign_source', 'authority', 'scope']);

it('rejects a final producer proof that changes the original authorized owner', function (): void {
    Fx::owner();
    Fx::plant($this->site, (string) $this->reader->id, 'course.expired', 'commerce.order:73', now()->subSecond());
    $beforeAudits = DB::table('audit_log')->count();
    $calls = 0;
    $verify = function () use (&$calls): void {
        if (++$calls === 2) {
            Fx::owner('another-retirement-owner@example.test');
        }
    };
    expect(fn () => DB::transaction(fn () => $this->writer->retireVerifiedOrderSource('commerce.order:73', $verify)))
        ->toThrow(EntitlementRefused::class);
    expect(DB::table('entitlements')->count())->toBe(1)->and(DB::table('audit_log')->count())->toBe($beforeAudits);
});

it('refuses a final proof that removes the same owner membership without changing the actor key', function (): void {
    $owner = Fx::owner();
    Fx::plant($this->site, (string) $this->reader->id, 'course.expired', 'commerce.order:73', now()->subSecond());
    $beforeAudits = DB::table('audit_log')->count();
    $calls = 0;
    $verify = function () use (&$calls, $owner): void {
        if (++$calls === 2) {
            DB::table('org_user')->where('org_id', $this->org->id)->where('user_id', $owner->id)->delete();
        }
    };
    expect(fn () => DB::transaction(fn () => $this->writer->retireVerifiedOrderSource('commerce.order:73', $verify)))
        ->toThrow(EntitlementRefused::class);
    expect(DB::table('entitlements')->count())->toBe(1)->and(DB::table('audit_log')->count())->toBe($beforeAudits)
        ->and(DB::table('org_user')->where('org_id', $this->org->id)->where('user_id', $owner->id)->exists())->toBeTrue();
});
