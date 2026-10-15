<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\VerifiedOrderOutcome;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;

beforeEach(function (): void {
    Fx::boot();
    $this->org = Fx::org('adjustment-zero');
    $this->site = Fx::site($this->org, 'main');
    Fx::declareReaders();
    $this->reader = Fx::reader();
    Fx::owner();
    Fx::writer()->grant($this->reader->getKey(), 'course.purchased', 'commerce.order:12', null);
});

afterEach(fn () => Fx::tearDown());

it('refuses both verified adjustments without an already-open real transaction', function (): void {
    $before = DB::table('entitlements')->sole();
    $proof = static function (): never {
        throw new RuntimeException('proof must not run');
    };
    expect(fn () => Fx::writer()->reviseVerifiedOrder($this->reader->getKey(), 'course.purchased', 'commerce.order:12', null, true, $proof))
        ->toThrow(EntitlementRefused::class)
        ->and(fn () => Fx::writer()->reinstate($this->reader->getKey(), 'course.purchased', 'commerce.order:12', null, [], $proof))
        ->toThrow(EntitlementRefused::class)
        ->and(DB::table('entitlements')->sole())->toEqual($before);
});

it('commits source mutation and system audit together, visible through an independent connection', function (): void {
    $result = DB::transaction(fn () => Fx::writer()->reviseVerifiedOrder($this->reader->getKey(), 'course.purchased',
        'commerce.order:12', CarbonImmutable::parse('2027-01-15 12:00:00 UTC'), true, static function (): void {}));
    $pdo = new PDO('sqlite:'.$this->custodyFile);
    $row = $pdo->query('select * from entitlements')->fetch(PDO::FETCH_ASSOC);
    expect($result->outcome)->toBe(VerifiedOrderOutcome::Revoked)
        ->and($row['change_token'])->toBe($result->witness['change_token'])
        ->and($row['revoked_at'])->toBe($result->witness['revoked_at'])
        ->and((int) $pdo->query("select count(*) from audit_log where action='entitlement.revoked' and actor_id is null and actor_type is null")->fetchColumn())->toBe(1);
});

it('leaves the committed suspension intact when the database refuses its restoration audit', function (): void {
    $witness = DB::transaction(fn () => Fx::writer()->reviseVerifiedOrder($this->reader->getKey(), 'course.purchased',
        'commerce.order:12', null, true, static function (): void {}))->witness;
    $before = DB::table('entitlements')->sole();
    DB::statement("create trigger refuse_restore_audit before insert on audit_log when new.action='entitlement.reinstated' begin select raise(abort, 'audit refused'); end");
    expect(fn () => DB::transaction(fn () => Fx::writer()->reinstate($this->reader->getKey(), 'course.purchased',
        'commerce.order:12', null, $witness, static function (): void {})))->toThrow(EntitlementRefused::class);
    $pdo = new PDO('sqlite:'.$this->custodyFile);
    expect((object) $pdo->query('select * from entitlements')->fetch(PDO::FETCH_ASSOC))->toEqual($before)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('backfills distinct identities for retained grants and refuses to remove witnesses while access remains', function (): void {
    $migration = require dirname(__DIR__, 2).'/packages/core/database/migrations/0001_01_01_000017_add_entitlement_change_token.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    $legacy = (array) DB::table('entitlements')->sole();
    unset($legacy['id'], $legacy['change_token']);
    DB::table('entitlements')->delete();
    $migration->down();
    DB::table('entitlements')->insert([$legacy, [...$legacy, 'source' => 'commerce.order:13']]);
    $migration->up();
    $tokens = DB::table('entitlements')->orderBy('id')->pluck('change_token')->all();
    expect($tokens)->toHaveCount(2)->and($tokens[0])->toMatch('/\A[a-f0-9]{64}\z/D')
        ->and($tokens[1])->toMatch('/\A[a-f0-9]{64}\z/D')->and($tokens[0])->not->toBe($tokens[1]);
});
