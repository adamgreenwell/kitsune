<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Commerce\Models\RetentionPolicy;
use Kitsune\Commerce\Models\RetentionPolicyActivation;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Commerce\Privacy\RetentionRefused;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as RetentionFx;

beforeEach(function (): void {
    RetentionFx::boot();
    $this->org = RetentionFx::org('retention-policy');
    $this->owner = RetentionFx::member();
    $this->policies = app(RetentionPolicies::class);
});

afterEach(fn () => RetentionFx::tearDown());

function retentionPolicy(RetentionPolicies $policies, string $clock = RetentionPolicySnapshot::CONFIRMATION_DAYS, int $duration = 30, ?string $zone = null): RetentionPolicySnapshot
{
    return $policies->createAsOwner(['address.country', 'name'], 'Operator-stated invoice obligation', 'Owner selected this profile', $clock, $duration, $zone);
}

it('has no default policy and requires explicit activation of immutable future versions', function (): void {
    expect($this->policies->current(DB::connection(), $this->org->id))->toBeNull();
    $first = retentionPolicy($this->policies);
    expect($first->version)->toBe(1)->and($first->billingFields)->toBe(['name', 'address.country'])
        ->and($this->policies->current(DB::connection(), $this->org->id))->toBeNull();
    $this->policies->activateAsOwner(1);
    $frozen = $first->snapshot();
    $second = retentionPolicy($this->policies, duration: 90);
    expect($second->version)->toBe(2)->and($this->policies->current(DB::connection(), $this->org->id)->snapshot())->toBe($frozen);
    $this->policies->activateAsOwner(2);
    $this->policies->activateAsOwner(2);
    expect($this->policies->current(DB::connection(), $this->org->id)->version)->toBe(2)
        ->and($this->policies->version(DB::connection(), $this->org->id, 1)->snapshot())->toBe($frozen)
        ->and(RetentionPolicySnapshot::fromSnapshot($frozen)->duration)->toBe(30)
        ->and(array_map(fn (RetentionPolicySnapshot $row): int => $row->version, $this->policies->versionsAsOwner()))->toBe([2, 1])
        ->and(DB::table('commerce_orders')->count())->toBe(0)
        ->and(DB::table(AuditLog::TABLE)->where('action', 'commerce.retention_policy_activated')->count())->toBe(2)
        ->and(json_encode(DB::table(AuditLog::TABLE)->get()))->not->toContain('Owner selected this profile');
});

it('counts UTC whole days from the first confirmation rather than local days or a later clock', function (): void {
    $policy = retentionPolicy($this->policies, duration: 1);
    $start = CarbonImmutable::parse('2026-03-08 01:30:00.999999', 'America/New_York');
    config(['app.timezone' => 'Pacific/Auckland']);
    expect($policy->deadline($start)->format('Y-m-d H:i:s P'))->toBe('2026-03-09 06:30:00 +00:00')
        ->and($start->format('Y-m-d H:i:s.u'))->toBe('2026-03-08 01:30:00.999999');
});

it('uses the frozen policy calendar year and an exclusive end after whole years', function (string $instant, string $zone, string $expected): void {
    $policy = retentionPolicy($this->policies, RetentionPolicySnapshot::PAYMENT_YEAR_END, 2, $zone);
    config(['app.timezone' => 'Europe/Paris']);
    expect($policy->deadline(CarbonImmutable::parse($instant, 'UTC'))->format('Y-m-d H:i:s P'))->toBe($expected);
})->with([
    ['2027-01-01 00:30:00', 'America/Los_Angeles', '2029-01-01 08:00:00 +00:00'],
    ['2026-12-31 10:30:00', 'Pacific/Kiritimati', '2029-12-31 10:00:00 +00:00'],
    ['2024-02-29 12:00:00', 'UTC', '2027-01-01 00:00:00 +00:00'],
]);

it('allows explicitly zero duration and refuses unrepresentable deadlines', function (): void {
    $days = retentionPolicy($this->policies, duration: 0);
    expect($days->deadline(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'))->format('Y-m-d H:i:s'))->toBe('2026-10-15 12:00:00');
    $years = retentionPolicy($this->policies, RetentionPolicySnapshot::PAYMENT_YEAR_END, 0, 'UTC');
    expect($years->deadline(CarbonImmutable::parse('2026-10-15', 'UTC'))->format('Y-m-d H:i:s'))->toBe('2027-01-01 00:00:00');
    $long = retentionPolicy($this->policies, duration: 36500);
    expect(fn () => $long->deadline(CarbonImmutable::parse('9999-12-31', 'UTC')))->toThrow(RetentionRefused::class)
        ->and(fn () => $years->deadline(CarbonImmutable::parse('9999-12-31', 'UTC')))->toThrow(RetentionRefused::class);
});

it('allows an explicitly empty billing profile without silently retaining other personal fields', function (): void {
    $policy = $this->policies->createAsOwner([], 'No invoice personal field obligation', 'Operator explicitly selected none', RetentionPolicySnapshot::CONFIRMATION_DAYS, 0);
    expect($policy->billingFields)->toBe([])->and($policy->duration)->toBe(0);
});

it('refuses nonbilling fields, implicit clocks, scalar coercion and invalid frozen zones', function (array $overrides): void {
    $values = array_replace(['fields' => ['name'], 'basis' => 'Explicit obligation', 'reason' => 'Chosen profile', 'clock' => RetentionPolicySnapshot::CONFIRMATION_DAYS, 'duration' => 30, 'timezone' => null], $overrides);
    expect(fn () => $this->policies->createAsOwner($values['fields'], $values['basis'], $values['reason'], $values['clock'], $values['duration'], $values['timezone']))
        ->toThrow(is_string($values['duration']) ? TypeError::class : RetentionRefused::class);
    expect(DB::table(RetentionPolicies::TABLE)->count())->toBe(0);
})->with([
    [['fields' => ['email']]], [['fields' => ['delivery.address.line1']]], [['fields' => ['name', 'name']]],
    [['fields' => ['country']]], [['basis' => ' ']], [['reason' => '']], [['clock' => 'default']],
    [['duration' => -1]], [['duration' => 36501]], [['duration' => '30']], [['timezone' => 'UTC']],
    [['clock' => RetentionPolicySnapshot::PAYMENT_YEAR_END]],
    [['clock' => RetentionPolicySnapshot::PAYMENT_YEAR_END, 'duration' => 101, 'timezone' => 'UTC']],
    [['clock' => RetentionPolicySnapshot::PAYMENT_YEAR_END, 'timezone' => '+00:00']],
    [['clock' => RetentionPolicySnapshot::PAYMENT_YEAR_END, 'timezone' => 'america/new_york']],
]);

it('refuses malformed frozen configuration and accepts database object key reordering', function (): void {
    $snapshot = retentionPolicy($this->policies)->snapshot();
    $reordered = $snapshot;
    $reordered['configuration'] = array_reverse($snapshot['configuration'], true);
    expect(RetentionPolicySnapshot::fromSnapshot($reordered)->snapshot())->toBe($snapshot);
    foreach ([['configuration', 'duration', '30'], ['configuration', 'billing_fields', ['email']], ['configuration', 'extra', true], ['root', 'config_hash', str_repeat('f', 64)]] as [$where, $key, $value]) {
        $corrupt = $snapshot;
        if ($where === 'root') {
            $corrupt[$key] = $value;
        } else {
            $corrupt['configuration'][$key] = $value;
        }
        expect(fn () => RetentionPolicySnapshot::fromSnapshot($corrupt))->toThrow(RetentionRefused::class);
    }
});

it('requires the current owner for writes and private history even with a known version', function (bool $member): void {
    retentionPolicy($this->policies);
    $member ? RetentionFx::member(owner: false, email: 'editor@retention.test') : Auth::guard('web')->logout();
    foreach ([fn () => retentionPolicy($this->policies), fn () => $this->policies->activateAsOwner(1), fn () => $this->policies->versionsAsOwner()] as $action) {
        expect($action)->toThrow(RetentionRefused::class);
    }
    expect(DB::table(RetentionPolicies::TABLE)->count())->toBe(1)->and(DB::table(RetentionPolicies::ACTIVATIONS)->count())->toBe(0);
})->with([false, true]);

it('shares a policy across sibling sites while refusing another Org and planted foreign activation', function (): void {
    $policy = retentionPolicy($this->policies);
    $this->policies->activateAsOwner(1);
    $site = Site::create(['handle' => 'sibling', 'slug' => 'retention-sibling', 'name' => 'Sibling', 'locale' => 'en']);
    app(Context::class)->setSite($site);
    expect($this->policies->current(DB::connection(), $this->org->id)->id)->toBe($policy->id);
    $foreign = RetentionFx::org('foreign-retention');
    RetentionFx::member(email: 'foreign@retention.test');
    $other = retentionPolicy($this->policies);
    expect(fn () => $this->policies->version(DB::connection(), $this->org->id, 1))->toThrow(RetentionRefused::class)
        ->and(RetentionPolicy::query()->count())->toBe(1)->and(RetentionPolicyActivation::query()->count())->toBe(0);
    DB::table(RetentionPolicies::ACTIVATIONS)->insert(['org_id' => $foreign->id, 'policy_id' => $policy->id, 'policy_version' => 1, 'config_hash' => $policy->configHash, 'activated_at' => '2026-10-15 00:00:00']);
    expect(fn () => $this->policies->current(DB::connection(), $foreign->id))->toThrow(RetentionRefused::class)
        ->and($other->id)->not->toBe($policy->id);
});

it('declares Org scopes and refuses ORM mutation or accidental private serialization', function (): void {
    retentionPolicy($this->policies);
    $this->policies->activateAsOwner(1);
    foreach ([RetentionPolicy::class, RetentionPolicyActivation::class] as $class) {
        expect((new ReflectionClass($class))->getAttributes(OrgScoped::class))->toHaveCount(1);
        $model = $class::query()->sole();
        expect($model->toArray())->toBe([])->and(fn () => $model->save())->toThrow(RetentionRefused::class)
            ->and(fn () => $model->delete())->toThrow(RetentionRefused::class)
            ->and(fn () => $class::query()->update(['org_id' => $this->org->id]))->toThrow(RuntimeException::class);
    }
    app(Context::class)->forget();
    expect(RetentionPolicy::query()->count())->toBe(0)->and(RetentionPolicyActivation::query()->count())->toBe(0);
});

it('checks installed policy data independently of enabled or current module runtime', function (): void {
    $policy = retentionPolicy($this->policies);
    $this->policies->activateAsOwner(1);
    DB::table('modules')->updateOrInsert(['handle' => 'kitsune/commerce'], ['version' => 'stale', 'is_enabled' => false, 'installed_at' => now()]);
    expect($this->policies->current(DB::connection(), $this->org->id)->snapshot())->toBe($policy->snapshot());
    DB::table(RetentionPolicies::TABLE)->where('id', $policy->id)->update(['duration' => 31]);
    expect(fn () => $this->policies->current(DB::connection(), $this->org->id))->toThrow(RetentionRefused::class);
});

it('rolls back policy payload, activation and audit when audit callbacks corrupt the write', function (string $action, string $attack): void {
    $first = retentionPolicy($this->policies);
    $this->policies->activateAsOwner(1);
    $otherOwner = RetentionFx::member(email: 'other-owner@retention.test');
    Auth::guard('web')->login($this->owner);
    if ($action === 'activated') {
        retentionPolicy($this->policies, duration: 90);
    }
    $events = AuditLog::getEventDispatcher();
    $name = 'eloquent.created: '.AuditLog::class;
    $old = $events->getRawListeners()[$name] ?? [];
    $before = [DB::table(RetentionPolicies::TABLE)->get()->toArray(), DB::table(RetentionPolicies::ACTIVATIONS)->get()->toArray(), DB::table(AuditLog::TABLE)->get()->toArray()];
    AuditLog::created(static function (AuditLog $audit) use ($action, $attack, $otherOwner): void {
        if ($audit->action !== 'commerce.retention_policy_'.$action) {
            return;
        }
        match ($attack) {
            'payload' => DB::table(RetentionPolicies::TABLE)->where('id', $audit->target_id)->update(['reason' => 'Rewritten after save']),
            'pointer' => DB::table(RetentionPolicies::ACTIVATIONS)->delete(),
            'audit' => DB::table(AuditLog::TABLE)->where('id', $audit->getKey())->update(['action' => 'commerce.misfiled']),
            'deleted audit' => DB::table(AuditLog::TABLE)->where('id', $audit->getKey())->delete(),
            'owner swap' => Auth::guard('web')->login($otherOwner),
        };
    });
    try {
        expect(fn () => $action === 'created' ? retentionPolicy($this->policies, duration: 90) : $this->policies->activateAsOwner(2))->toThrow(RetentionRefused::class);
        expect([DB::table(RetentionPolicies::TABLE)->get()->toArray(), DB::table(RetentionPolicies::ACTIVATIONS)->get()->toArray(), DB::table(AuditLog::TABLE)->get()->toArray()])->toEqual($before)
            ->and($this->policies->current(DB::connection(), $this->org->id)->id)->toBe($first->id);
    } finally {
        $events->forget($name);
        foreach ($old as $listener) {
            $events->listen($name, $listener);
        }
    }
})->with(['created', 'activated'])->with(['payload', 'pointer', 'audit', 'deleted audit', 'owner swap']);

it('refuses a canceled or misfiled creating policy audit', function (bool $cancel): void {
    $events = AuditLog::getEventDispatcher();
    $name = 'eloquent.creating: '.AuditLog::class;
    $old = $events->getRawListeners()[$name] ?? [];
    AuditLog::creating(static function (AuditLog $audit) use ($cancel): bool {
        if ($audit->action === 'commerce.retention_policy_created') {
            $audit->target_type = Site::class;

            return ! $cancel;
        }

        return true;
    });
    try {
        expect(fn () => retentionPolicy($this->policies))->toThrow(RetentionRefused::class)
            ->and(DB::table(RetentionPolicies::TABLE)->count())->toBe(0)
            ->and(DB::table(AuditLog::TABLE)->where('action', 'commerce.retention_policy_created')->count())->toBe(0);
    } finally {
        $events->forget($name);
        foreach ($old as $listener) {
            $events->listen($name, $listener);
        }
    }
})->with([false, true]);

it('refuses a partial schema or missing recorded policy custody', function (): void {
    Schema::rename(RetentionPolicies::TABLE, RetentionPolicies::TABLE.'_saved');
    try {
        expect(fn () => $this->policies->schemaAvailable(DB::connection()))->toThrow(RetentionRefused::class);
        Schema::rename(RetentionPolicies::ACTIVATIONS, RetentionPolicies::ACTIVATIONS.'_saved');
        try {
            expect(fn () => $this->policies->schemaAvailable(DB::connection()))->toThrow(RetentionRefused::class);
            DB::table('migrations')->where('migration', '0001_01_01_000217_create_commerce_retention_policies')->delete();
            expect($this->policies->schemaAvailable(DB::connection()))->toBeFalse();
        } finally {
            Schema::rename(RetentionPolicies::ACTIVATIONS.'_saved', RetentionPolicies::ACTIVATIONS);
        }
    } finally {
        Schema::rename(RetentionPolicies::TABLE.'_saved', RetentionPolicies::TABLE);
    }
});
