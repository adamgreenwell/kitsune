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
use Kitsune\Core\Entitlements\EntitlementRefusal;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Entitlements\VerifiedOrderOutcome;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\TestReader;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('order-adjustment');
    $this->site = Fx::site($this->org, 'main');
    Fx::declareReaders();
    $this->reader = Fx::reader();
    $this->owner = Fx::owner();
    $this->end = CarbonImmutable::parse('2027-01-15 12:00:00 UTC');
    $this->shorter = CarbonImmutable::parse('2026-12-15 12:00:00 UTC');
    Fx::writer()->grant($this->reader->getKey(), 'course.purchased', 'commerce.order:12', $this->end);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Fx::tearDown();
});

function adjustOrderSource(object $test, bool $suspend, ?DateTimeInterface $end = null, ?Closure $verify = null): mixed
{
    return DB::transaction(fn () => Fx::writer()->reviseVerifiedOrder($test->reader->getKey(), 'course.purchased',
        'commerce.order:12', $end ?? $test->end, $suspend, $verify ?? static function (): void {}));
}

function restoreOrderSource(object $test, array $witness, ?DateTimeInterface $end = null, ?Closure $verify = null): mixed
{
    return DB::transaction(fn () => Fx::writer()->reinstate($test->reader->getKey(), 'course.purchased',
        'commerce.order:12', $end ?? $test->end, $witness, $verify ?? static function (): void {}));
}

it('revises only this order source to the exact remaining end and leaves other orders and comps untouched', function (): void {
    Fx::writer()->grant($this->reader->getKey(), 'course.purchased', 'commerce.order:13', null);
    Fx::writer()->comp($this->reader->getKey(), 'course.purchased', null);
    $others = DB::table('entitlements')->where('source', '!=', 'commerce.order:12')->orderBy('id')->get()->all();
    $result = adjustOrderSource($this, false, $this->shorter);
    expect($result->outcome)->toBe(VerifiedOrderOutcome::Revised)
        ->and($result->witness['expires_at'])->toBe('2026-12-15 12:00:00')
        ->and($result->witness['revoked_at'])->toBeNull()
        ->and(DB::table('entitlements')->where('source', '!=', 'commerce.order:12')->orderBy('id')->get()->all())->toEqual($others);
    $count = DB::table('audit_log')->count();
    expect(adjustOrderSource($this, false, $this->shorter)->outcome)->toBe(VerifiedOrderOutcome::Unchanged)
        ->and(DB::table('audit_log')->count())->toBe($count);
});

it('suspends and reinstates the exact witnessed source with its projected original end and no duplicate audit', function (bool $permanent): void {
    if ($permanent) {
        Fx::writer()->grant($this->reader->getKey(), 'course.purchased', 'commerce.order:12', null);
    }
    $target = $permanent ? null : $this->shorter;
    $revoke = DB::transaction(fn () => Fx::writer()->reviseVerifiedOrder($this->reader->getKey(), 'course.purchased',
        'commerce.order:12', $target, true, static function (): void {}));
    expect($revoke->outcome)->toBe(VerifiedOrderOutcome::Revoked)->and($revoke->witness['revoked_at'])->not->toBeNull();
    $restored = DB::transaction(fn () => Fx::writer()->reinstate($this->reader->getKey(), 'course.purchased',
        'commerce.order:12', $target, $revoke->witness, static function (): void {}));
    expect($restored->outcome)->toBe(VerifiedOrderOutcome::Reinstated)
        ->and($restored->witness['id'])->toBe($revoke->witness['id'])
        ->and($restored->witness['expires_at'])->toBe($target?->format('Y-m-d H:i:s'))
        ->and($restored->witness['revoked_at'])->toBeNull();
    $count = DB::table('audit_log')->count();
    expect(DB::transaction(fn () => Fx::writer()->reinstate($this->reader->getKey(), 'course.purchased',
        'commerce.order:12', $target, $revoke->witness, static function (): void {}))->outcome)->toBe(VerifiedOrderOutcome::Unchanged)
        ->and(DB::table('audit_log')->count())->toBe($count)
        ->and(DB::table('audit_log')->where('action', EntitlementWriter::REINSTATED)->sole()->actor_id)->toBeNull();
})->with([false, true]);

it('refuses other producers and malformed commerce sources before executing proof', function (string $source): void {
    $witness = adjustOrderSource($this, true)->witness;
    $proof = static function (): never {
        throw new RuntimeException('proof must not run');
    };
    foreach ([
        fn () => DB::transaction(fn () => Fx::writer()->reviseVerifiedOrder($this->reader->getKey(), 'course.purchased', $source, null, true, $proof)),
        fn () => DB::transaction(fn () => Fx::writer()->reinstate($this->reader->getKey(), 'course.purchased', $source, null, $witness, $proof)),
    ] as $door) {
        expect($door)->toThrow(EntitlementRefused::class);
    }
})->with(['core.comp', 'import.legacy:12', 'commerce.order', 'commerce.order:0', 'commerce.order:012', 'commerce.order:12 ', 'commerce.order:12x']);

it('requires the final producer proof even for a missing or unchanged source', function (bool $missing): void {
    if ($missing) {
        Fx::writer()->forget($this->reader->getKey());
    }
    $calls = 0;
    try {
        adjustOrderSource($this, false, verify: static function () use (&$calls): void {
            if (++$calls === 2) {
                throw EntitlementRefused::because(EntitlementRefusal::Cancelled, EntitlementRefused::REVISE);
            }
        });
        $this->fail('the final producer proof was not required');
    } catch (EntitlementRefused) {
    }
    expect($calls)->toBe(2);
})->with([false, true]);

it('refuses untracked raw writes with no valid opaque identity', function (mixed $token): void {
    DB::table('entitlements')->update(['change_token' => $token]);
    expect(fn () => adjustOrderSource($this, true))->toThrow(EntitlementRefused::class);
})->with(['empty' => [''], 'uppercase' => [str_repeat('A', 64)], 'short' => ['abc']]);

it('never clears an existing independent revocation through revision or a stale witness', function (string $field): void {
    $witness = adjustOrderSource($this, true)->witness;
    $wrong = $witness;
    $wrong[$field] = match ($field) {
        'id' => '999999',
        'expires_at' => null,
        'change_token' => str_repeat('0', 64),
        default => '2026-01-01 00:00:00',
    };
    $count = DB::table('audit_log')->count();
    expect(restoreOrderSource($this, $wrong)->outcome)->toBe(VerifiedOrderOutcome::StillRevoked)
        ->and(DB::table('audit_log')->count())->toBe($count)
        ->and(adjustOrderSource($this, false, $this->shorter)->outcome)->toBe(VerifiedOrderOutcome::StillRevoked)
        ->and(DB::table('entitlements')->value('revoked_at'))->toBe($witness['revoked_at']);
})->with(['id', 'expires_at', 'revoked_at', 'changed_at', 'change_token']);

it('distinguishes a same-second manual revoke from a producer suspension even when all old stamps collide', function (): void {
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $witness = adjustOrderSource($this, true)->witness;
    restoreOrderSource($this, $witness);
    Fx::writer()->revoke($this->reader->getKey(), 'course.purchased', 'commerce.order:12');
    $manual = DB::table('entitlements')->sole();
    expect((string) $manual->id)->toBe($witness['id'])->and($manual->expires_at)->toBe($witness['expires_at'])
        ->and($manual->revoked_at)->toBe($witness['revoked_at'])->and($manual->changed_at)->toBe($witness['changed_at'])
        ->and($manual->change_token)->not->toBe($witness['change_token'])
        ->and(restoreOrderSource($this, $witness)->outcome)->toBe(VerifiedOrderOutcome::StillRevoked)
        ->and(DB::table('entitlements')->sole())->toEqual($manual);
});

it('changes write identity on every actual ordinary mutation and retains it on no-ops', function (): void {
    $initial = DB::table('entitlements')->value('change_token');
    Fx::writer()->grant($this->reader->getKey(), 'course.purchased', 'commerce.order:12', $this->end);
    expect(DB::table('entitlements')->value('change_token'))->toBe($initial);
    Fx::writer()->grant($this->reader->getKey(), 'course.purchased', 'commerce.order:12', null);
    $extended = DB::table('entitlements')->value('change_token');
    expect($extended)->not->toBe($initial);
    Fx::writer()->revoke($this->reader->getKey(), 'course.purchased', 'commerce.order:12');
    $revoked = DB::table('entitlements')->value('change_token');
    expect($revoked)->not->toBe($extended);
    Fx::writer()->revoke($this->reader->getKey(), 'course.purchased', 'commerce.order:12');
    expect(DB::table('entitlements')->value('change_token'))->toBe($revoked);
    Fx::writer()->comp($this->reader->getKey(), 'course.purchased', null);
    $comp = DB::table('entitlements')->where('source', 'core.comp')->value('change_token');
    Fx::writer()->revoke($this->reader->getKey(), 'course.purchased', 'core.comp');
    Fx::writer()->comp($this->reader->getKey(), 'course.purchased', null);
    expect(DB::table('entitlements')->where('source', 'core.comp')->value('change_token'))->not->toBe($comp);
});

it('refuses an in-memory forged old token supplied by a host retrieved observer', function (): void {
    $witness = adjustOrderSource($this, true)->witness;
    restoreOrderSource($this, $witness);
    Fx::writer()->revoke($this->reader->getKey(), 'course.purchased', 'commerce.order:12');
    $before = DB::table('entitlements')->sole();
    Entitlement::retrieved(static function ($row) use ($witness): void {
        $row->setRawAttributes([...$row->getAttributes(), ...array_diff_key($witness, ['id' => null])], true);
    });
    try {
        expect(fn () => restoreOrderSource($this, $witness))->toThrow(EntitlementRefused::class);
    } finally {
        Entitlement::flushEventListeners();
        Entitlement::clearBootedModels();
    }
    expect(DB::table('entitlements')->sole())->toEqual($before);
});

it('refuses restoration if an audit observer deletes the canonical reader', function (): void {
    $witness = adjustOrderSource($this, true)->witness;
    $before = DB::table('entitlements')->sole();
    $reader = $this->reader;
    AuditLog::created(static function ($audit) use ($reader): void {
        if ($audit->action === EntitlementWriter::REINSTATED) {
            DB::table($reader->getTable())->where('id', $reader->getKey())->delete();
        }
    });
    try {
        expect(fn () => restoreOrderSource($this, $witness))->toThrow(EntitlementRefused::class);
    } finally {
        AuditLog::flushEventListeners();
        AuditLog::clearBootedModels();
    }
    expect(DB::table('entitlements')->sole())->toEqual($before)
        ->and(DB::table($reader->getTable())->where('id', $reader->getKey())->exists())->toBeTrue();
});

it('does not regenerate a forgotten source through either adjustment door', function (): void {
    $witness = adjustOrderSource($this, true)->witness;
    Fx::writer()->forget($this->reader->getKey());
    $count = DB::table('audit_log')->count();
    expect(adjustOrderSource($this, true)->outcome)->toBe(VerifiedOrderOutcome::Missing)
        ->and(restoreOrderSource($this, $witness)->outcome)->toBe(VerifiedOrderOutcome::Missing)
        ->and(DB::table('entitlements')->count())->toBe(0)->and(DB::table('audit_log')->count())->toBe($count);
});

it('can revoke a deleted host reader but refuses to restore it', function (): void {
    DB::table($this->reader->getTable())->where('id', $this->reader->getKey())->delete();
    expect(adjustOrderSource($this, false, $this->shorter)->outcome)->toBe(VerifiedOrderOutcome::Revised)
        ->and(DB::table('entitlements')->value('expires_at'))->toBe($this->shorter->format('Y-m-d H:i:s'));
    $witness = adjustOrderSource($this, true)->witness;
    expect(fn () => restoreOrderSource($this, $witness))->toThrow(EntitlementRefused::class)
        ->and(DB::table('entitlements')->value('revoked_at'))->toBe($witness['revoked_at']);
});

it('requires a current canonical reader before extending a shortened unrevoked original term', function (bool $deleted, bool $permanent): void {
    CarbonImmutable::setTestNow('2026-10-15 12:00:00 UTC');
    $sevenDays = CarbonImmutable::now('UTC')->addDays(7);
    $thirtyDays = CarbonImmutable::now('UTC')->addDays(30);
    adjustOrderSource($this, false, $sevenDays);
    if ($deleted) {
        DB::table($this->reader->getTable())->where('id', $this->reader->getKey())->delete();
    }
    CarbonImmutable::setTestNow('2026-10-23 12:00:00 UTC');
    $before = DB::table('entitlements')->sole();
    $audits = DB::table('audit_log')->count();
    $extend = fn () => DB::transaction(fn () => Fx::writer()->reviseVerifiedOrder($this->reader->getKey(),
        'course.purchased', 'commerce.order:12', $permanent ? null : $thirtyDays, false, static function (): void {}));
    if ($deleted) {
        expect($extend)->toThrow(EntitlementRefused::class)
            ->and(DB::table('entitlements')->sole())->toEqual($before)
            ->and(DB::table('audit_log')->count())->toBe($audits);
    } else {
        $result = $extend();
        expect($result->outcome)->toBe(VerifiedOrderOutcome::Revised)
            ->and($result->witness['id'])->toBe((string) $before->id)
            ->and($result->witness['expires_at'])->toBe($permanent ? null : '2026-11-14 12:00:00')
            ->and($result->witness['revoked_at'])->toBeNull();
    }
    expect(DB::table('entitlements')->count())->toBe(1);
})->with([false, true])->with([false, true]);

it('rolls an unrevoked end expansion back when its audit callback deletes the reader', function (): void {
    adjustOrderSource($this, false, $this->shorter);
    $before = DB::table('entitlements')->sole();
    $audits = DB::table('audit_log')->count();
    $reader = $this->reader;
    AuditLog::created(static function ($audit) use ($reader): void {
        if ($audit->action === EntitlementWriter::REVISED) {
            DB::table($reader->getTable())->where('id', $reader->getKey())->delete();
        }
    });
    try {
        expect(fn () => adjustOrderSource($this, false))->toThrow(EntitlementRefused::class);
    } finally {
        AuditLog::flushEventListeners();
        AuditLog::clearBootedModels();
    }
    expect(DB::table('entitlements')->sole())->toEqual($before)
        ->and(DB::table('audit_log')->count())->toBe($audits)
        ->and(DB::table($reader->getTable())->where('id', $reader->getKey())->exists())->toBeTrue();
});

it('rechecks raw reader and source custody after fresh reader callbacks during an end expansion', function (bool $deleteReader): void {
    adjustOrderSource($this, false, $this->shorter);
    $before = DB::table('entitlements')->sole();
    $reader = $this->reader;
    TestReader::retrieved(static function () use ($reader, $deleteReader): void {
        if ($deleteReader) {
            DB::table($reader->getTable())->where('id', $reader->getKey())->delete();
        } else {
            DB::table('entitlements')->update(['revoked_at' => '2026-10-15 12:00:00', 'change_token' => bin2hex(random_bytes(32))]);
        }
    });
    try {
        expect(fn () => adjustOrderSource($this, false))->toThrow(EntitlementRefused::class);
    } finally {
        TestReader::flushEventListeners();
        TestReader::clearBootedModels();
    }
    expect(DB::table('entitlements')->sole())->toEqual($before)
        ->and(DB::table($reader->getTable())->where('id', $reader->getKey())->exists())->toBeTrue();
})->with([false, true]);

it('never gives live access when the remaining original term ended during suspension', function (): void {
    $witness = adjustOrderSource($this, true)->witness;
    CarbonImmutable::setTestNow($this->end);
    $result = restoreOrderSource($this, $witness);
    expect($result->outcome)->toBe(VerifiedOrderOutcome::Expired)
        ->and($result->witness['expires_at'])->toBe($witness['expires_at'])
        ->and($result->witness['revoked_at'])->toBe($witness['revoked_at'])
        ->and(Fx::check()->holds('course.purchased'))->toBeFalse();
});

it('rejects a reader or nonowner before executing trusted producer proof', function (string $actor): void {
    $witness = adjustOrderSource($this, true)->witness;
    Fx::nobody();
    $actor === 'reader' ? Fx::signIn($this->reader) : Fx::member();
    $proof = static function (): never {
        throw new RuntimeException('proof must not run');
    };
    foreach ([fn () => adjustOrderSource($this, true, verify: $proof), fn () => restoreOrderSource($this, $witness, verify: $proof)] as $door) {
        expect($door)->toThrow(EntitlementRefused::class);
    }
})->with(['reader', 'member']);

it('admits explicit system recovery while retaining an owner session and a null producer audit actor', function (bool $system): void {
    if ($system) {
        Fx::nobody();
    }
    adjustOrderSource($this, true);
    $audit = DB::table('audit_log')->where('action', EntitlementWriter::REVOKED)->sole();
    expect($audit->actor_id)->toBeNull()->and($audit->actor_type)->toBeNull()
        ->and(Auth::guard('web')->id())->toBe($system ? null : $this->owner->getKey());
})->with([false, true]);

it('cannot revise or reinstate another site or org source', function (bool $foreign): void {
    $witness = adjustOrderSource($this, true)->witness;
    $before = DB::table('entitlements')->sole();
    $org = $foreign ? Fx::org('foreign-adjustment') : $this->org;
    Fx::site($org, 'other');
    if ($foreign) {
        Fx::owner('foreign-owner@example.test');
    }
    expect(adjustOrderSource($this, false)->outcome)->toBe(VerifiedOrderOutcome::Missing)
        ->and(restoreOrderSource($this, $witness)->outcome)->toBe(VerifiedOrderOutcome::Missing)
        ->and(DB::table('entitlements')->sole())->toEqual($before);
})->with([false, true]);

it('refuses malformed witness shapes rather than ignoring their fields', function (string $mutation): void {
    $witness = adjustOrderSource($this, true)->witness;
    match ($mutation) {
        'extra' => $witness['extra'] = 'ignored?',
        'missing' => array_pop($witness),
        'typed' => $witness['id'] = (int) $witness['id'],
        'null-revoke' => $witness['revoked_at'] = null,
        'timestamp' => $witness['changed_at'] .= 'Z',
    };
    expect(fn () => restoreOrderSource($this, $witness))->toThrow(EntitlementRefused::class);
})->with(['extra', 'missing', 'typed', 'null-revoke', 'timestamp']);

it('rolls back adjustment and its audit when host callbacks corrupt persisted custody or authority', function (string $field): void {
    $before = DB::table('entitlements')->sole();
    $count = DB::table('audit_log')->count();
    AuditLog::created(function ($audit) use ($field): void {
        if ($audit->action !== EntitlementWriter::REVOKED) {
            return;
        }
        if ($field === 'actor') {
            DB::table('audit_log')->where('id', $audit->id)->update(['actor_id' => 'forged']);
        } elseif ($field === 'authority') {
            Fx::nobody();
        } elseif ($field === 'context') {
            app(Context::class)->forget();
        } else {
            DB::table('entitlements')->where('id', $audit->target_id)->update([$field => $field === 'expires_at' ? null : 'forged']);
        }
    });
    try {
        expect(fn () => adjustOrderSource($this, true))->toThrow(EntitlementRefused::class);
    } finally {
        AuditLog::flushEventListeners();
        AuditLog::clearBootedModels();
        app(Context::class)->setSite($this->site);
    }
    expect(DB::table('entitlements')->sole())->toEqual($before)->and(DB::table('audit_log')->count())->toBe($count);
})->with(['actor', 'authority', 'context', 'source', 'expires_at']);

it('checks proof twice and catches core row tampering performed by the final proof', function (bool $restore): void {
    $witness = adjustOrderSource($this, true)->witness;
    $before = DB::table('entitlements')->sole();
    $calls = 0;
    $verify = static function () use (&$calls): void {
        if (++$calls === 2) {
            DB::table('entitlements')->update(['expires_at' => null]);
        }
    };
    expect($restore ? fn () => restoreOrderSource($this, $witness, $this->shorter, $verify)
        : fn () => adjustOrderSource($this, false, $this->shorter, $verify))->toThrow(EntitlementRefused::class)
        ->and($calls)->toBe(2)->and(DB::table('entitlements')->sole())->toEqual($before);
})->with([false, true]);

it('fences the current grant after its final proof for writes and both no-op outcomes', function (string $outcome, string $mutation): void {
    $source = $outcome === 'granted' ? 'commerce.order:13' : 'commerce.order:12';
    $end = $outcome === 'extended' ? $this->end->addDays(7) : $this->end;
    if ($outcome === 'still-revoked') {
        Fx::writer()->revoke($this->reader->getKey(), 'course.purchased', $source);
    }
    $before = DB::table('entitlements')->orderBy('id')->get()->all();
    $audits = DB::table('audit_log')->count();
    $calls = 0;
    $verify = static function () use (&$calls, $source, $mutation): void {
        if (++$calls !== 2) {
            return;
        }
        $query = DB::table('entitlements')->where('source', $source);
        if ($mutation === 'delete') {
            $query->delete();
        } else {
            $query->update(['expires_at' => '2028-01-15 12:00:00', 'revoked_at' => null, 'change_token' => bin2hex(random_bytes(32))]);
        }
    };
    expect(fn () => DB::transaction(fn () => Fx::writer()->grantVerifiedOrder($this->reader->getKey(),
        'course.purchased', $source, $end, $verify)))->toThrow(EntitlementRefused::class)
        ->and($calls)->toBe(2)
        ->and(DB::table('entitlements')->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('audit_log')->count())->toBe($audits);
})->with(['granted', 'extended', 'unchanged', 'still-revoked'])->with(['rewrite', 'delete']);

it('fences the newly recorded producer audit after final grant proof callbacks', function (bool $extended, bool $delete): void {
    $source = $extended ? 'commerce.order:12' : 'commerce.order:13';
    $end = $extended ? $this->end->addDays(7) : $this->end;
    $before = DB::table('entitlements')->orderBy('id')->get()->all();
    $audits = DB::table('audit_log')->orderBy('id')->get()->all();
    $calls = 0;
    $verify = static function () use (&$calls, $source, $delete): void {
        if (++$calls !== 2) {
            return;
        }
        $id = DB::table('entitlements')->where('source', $source)->value('id');
        $query = DB::table('audit_log')->where('target_type', Entitlement::class)->where('target_id', $id)->orderByDesc('id');
        $audit = $query->first();
        $delete ? DB::table('audit_log')->where('id', $audit->id)->delete()
            : DB::table('audit_log')->where('id', $audit->id)->update(['actor_id' => 'forged']);
    };
    expect(fn () => DB::transaction(fn () => Fx::writer()->grantVerifiedOrder($this->reader->getKey(),
        'course.purchased', $source, $end, $verify)))->toThrow(EntitlementRefused::class)
        ->and($calls)->toBe(2)
        ->and(DB::table('entitlements')->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('audit_log')->orderBy('id')->get()->all())->toEqual($audits);
})->with([false, true])->with([false, true]);
