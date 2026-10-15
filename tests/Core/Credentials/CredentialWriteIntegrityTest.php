<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialCipher;
use Kitsune\Core\Credentials\CredentialConsoleAuthority;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\OrgCredentialMode;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('integrity-credentials');
    $this->otherOrg = Org::create(['slug' => 'integrity-other', 'name' => 'Other']);
    Auth::guard('web')->logout();
    Permissions::forget();
});

afterEach(fn () => Fx::tearDown());

function credentialIntegrityPrepare(string $operation): void
{
    CredentialConsoleAuthority::run(app(Context::class), 'set', Fx::SHARED, null, null,
        fn ($console) => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40), console: $console));
    if ($operation === 'mode') {
        CredentialConsoleAuthority::run(app(Context::class), 'switch', null, CredentialMode::Live, null,
            fn ($console) => Fx::writer()->switchTo(CredentialMode::Live, console: $console));
    }
    if ($operation === 'rotation') {
        config(['app.previous_keys' => [config('app.key')], 'app.key' => Fx::appKey()]);
        Fx::forget();
    }
}

function credentialIntegrityWrite(string $operation, ?CredentialConsoleAuthority &$lease = null): void
{
    $action = match ($operation) {
        'replace', 'create' => 'set', 'remove' => 'remove', 'mode' => 'switch', 'rotation' => 'reencrypt',
    };
    $slot = in_array($operation, ['replace', 'create', 'remove'], true) ? Fx::SHARED : null;
    $mode = $operation === 'mode' ? CredentialMode::Test : null;
    $id = $operation === 'rotation' ? (int) Credential::query()->where('slot', Fx::SHARED)->value('id') : null;
    CredentialConsoleAuthority::run(app(Context::class), $action, $slot, $mode, $id,
        function (CredentialConsoleAuthority $console) use ($operation, $id, &$lease): void {
            $lease = $console;
            match ($operation) {
                'replace', 'create' => Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40), console: $console),
                'remove' => Fx::writer()->remove(Fx::SHARED, null, console: $console),
                'mode' => Fx::writer()->switchTo(CredentialMode::Test, console: $console),
                'rotation' => Fx::writer()->reencrypt($id, $console),
            };
        });
}

/** A listener changes the actual Eloquent seam, then its original listeners are put back exactly. */
function credentialIntegrityListen(string $model, string $event, Closure $listener, Closure $work): void
{
    $events = Model::getEventDispatcher();
    $name = 'eloquent.'.$event.': '.$model;
    $before = $events->getRawListeners()[$name] ?? [];
    $model::{$event}($listener);
    try {
        $work();
    } finally {
        $events->forget($name);
        foreach ($before as $old) {
            $events->listen($name, $old);
        }
    }
}

/** The intended values must survive observers, rather than accepting their new post-save originals as intent. */
function credentialIntegrityMutate(Credential|OrgCredentialMode $row, string $field, int $otherOrg): void
{
    match ($field) {
        'slot' => $row->slot = 'fx.redirected-secret',
        'mode' => $row->mode = $row instanceof OrgCredentialMode ? 'live' : 'test',
        'org_id' => $row->org_id = $otherOrg,
        'id' => $row->id += 10000,
        'changed_at' => $row->changed_at = CarbonImmutable::parse('2001-01-01 00:00:00', 'UTC'),
        'ciphertext' => (function () use ($row): void {
            $sealed = app(CredentialCipher::class)->seal((int) $row->org_id, $row->slot, $row->mode, Fx::value('', 40));
            $row->forceFill(['ciphertext' => $sealed->ciphertext, 'key_id' => $sealed->keyId]);
        })(),
    };
}

/** @return array<string, array> */
function credentialIntegritySnapshot(): array
{
    $result = [];
    foreach (['credentials', 'org_credential_modes', 'audit_log'] as $table) {
        $result[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
    }

    return $result;
}

function credentialIntegrityRefuses(string $operation, array $before): void
{
    $level = DB::transactionLevel();
    expect(fn () => credentialIntegrityWrite($operation))->toThrow(CredentialRefused::class)
        ->and(credentialIntegritySnapshot())->toBe($before)
        ->and(DB::transactionLevel())->toBe($level);
}

it('refuses pre-save changes to the intended fields on every credential write', function (string $operation, string $field): void {
    credentialIntegrityPrepare($operation);
    $before = credentialIntegritySnapshot();
    $model = $operation === 'mode' ? OrgCredentialMode::class : Credential::class;
    credentialIntegrityListen($model, 'saving', fn ($row) => credentialIntegrityMutate($row, $field, $this->otherOrg->getKey()),
        fn () => credentialIntegrityRefuses($operation, $before));
})->with([
    'replacement must keep requested value' => ['replace', 'ciphertext'],
    'removal cannot restore a coherent encrypted value' => ['remove', 'ciphertext'],
    'mode cannot report test while persisting live' => ['mode', 'mode'],
    'rotation cannot rewrite the original change time' => ['rotation', 'changed_at'],
    'slot transplant' => ['replace', 'slot'],
    'mode transplant' => ['replace', 'mode'],
    'org transplant' => ['replace', 'org_id'],
    'existing target identity' => ['replace', 'id'],
    'replacement change time' => ['replace', 'changed_at'],
]);

it('refuses saved-observer data changes even if refresh makes the model agree with the database', function (string $operation): void {
    credentialIntegrityPrepare($operation);
    $before = credentialIntegritySnapshot();
    $model = $operation === 'mode' ? OrgCredentialMode::class : Credential::class;
    credentialIntegrityListen($model, 'saved', static function (Credential|OrgCredentialMode $row): void {
        $values = $row instanceof OrgCredentialMode ? ['mode' => 'live'] : ['changed_at' => '2001-01-01 00:00:00'];
        DB::table($row->getTable())->where('id', $row->getKey())->update($values);
        $row->refresh();
    }, fn () => credentialIntegrityRefuses($operation, $before));
})->with(['replace', 'remove', 'mode', 'rotation']);

it('refuses cancelled misfiled and rewritten audit records', function (string $operation, string $event, string $attack): void {
    credentialIntegrityPrepare($operation);
    $before = credentialIntegritySnapshot();
    credentialIntegrityListen(AuditLog::class, $event, static function (AuditLog $audit) use ($attack): ?bool {
        if ($attack === 'cancel') {
            return false;
        }
        if ($attack === 'misfile') {
            $audit->target_id = '999999';
        } else {
            DB::table('audit_log')->where('id', $audit->getKey())->update(['action' => 'credential.unrelated']);
            $audit->refresh();
        }

        return null;
    }, fn () => credentialIntegrityRefuses($operation, $before));
})->with([
    'cancelled' => ['replace', 'creating', 'cancel'],
    'wrong target' => ['remove', 'creating', 'misfile'],
    'rewritten after create' => ['mode', 'created', 'rewrite'],
]);

it('refuses an audit observer that changes and refreshes the rotated target', function (): void {
    credentialIntegrityPrepare('rotation');
    $before = credentialIntegritySnapshot();
    $target = null;
    credentialIntegrityListen(Credential::class, 'saving', static function (Credential $row) use (&$target): void {
        $target = $row;
    }, function () use (&$target, $before): void {
        credentialIntegrityListen(AuditLog::class, 'creating', static function () use (&$target): void {
            DB::table('credentials')->where('id', $target->getKey())->update(['changed_at' => '2001-01-01 00:00:00']);
            $target->refresh();
        }, fn () => credentialIntegrityRefuses('rotation', $before));
    });
});

it('refuses a context switch made after the audit row was created', function (): void {
    credentialIntegrityPrepare('replace');
    $before = credentialIntegritySnapshot();
    credentialIntegrityListen(AuditLog::class, 'created', fn () => app(Context::class)->setOrg($this->otherOrg),
        fn () => credentialIntegrityRefuses('replace', $before));
});

it('refuses a console lease invalidated by an audit observer', function (): void {
    credentialIntegrityPrepare('remove');
    $before = credentialIntegritySnapshot();
    $lease = null;
    credentialIntegrityListen(AuditLog::class, 'created', static function () use (&$lease): void {
        // The observer swallows the replay refusal. The writer must still see the lease was invalidated.
        try {
            $lease->consume(app(Context::class), 'remove', Fx::SHARED);
        } catch (LogicException) {
        }
    }, function () use (&$lease, $before): void {
        $level = DB::transactionLevel();
        expect(fn () => credentialIntegrityWrite('remove', $lease))->toThrow(CredentialRefused::class)
            ->and(credentialIntegritySnapshot())->toBe($before)->and(DB::transactionLevel())->toBe($level);
    });
});

it('refuses a signed-in actor introduced after the console audit', function (): void {
    credentialIntegrityPrepare('mode');
    $actor = Fx::member();
    Auth::guard('web')->logout();
    Permissions::forget();
    $before = credentialIntegritySnapshot();
    credentialIntegrityListen(AuditLog::class, 'created', static function () use ($actor): void {
        Auth::guard('web')->setUser($actor);
        Permissions::forget();
    }, fn () => credentialIntegrityRefuses('mode', $before));
});

it('accepts normal insertion with the intended org and a newly allocated identity', function (): void {
    credentialIntegrityWrite('create');
    $row = Credential::query()->first();
    expect($row)->not->toBeNull()->and($row->getKey())->toBeGreaterThan(0)
        ->and($row->org_id)->toBe($this->org->getKey())->and($row->slot)->toBe(Fx::SHARED)
        ->and($row->mode)->toBe('none')->and(AuditLog::query()->where('action', CredentialWriter::SET)->count())->toBe(1);
});
