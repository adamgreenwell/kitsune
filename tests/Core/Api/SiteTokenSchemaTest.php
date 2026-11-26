<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Http\Api\SiteTokenSchema;

class SiteTokenIntegerStaff extends Model
{
    protected $connection = 'token-membership-fixture';

    protected $table = 'staff';
}

class SiteTokenUlidStaff extends SiteTokenIntegerStaff
{
    use HasUlids;
}

function siteTokenMembershipFixture(string $staffClass, bool $cascade = true): void
{
    config(['database.connections.token-membership-fixture' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true, 'prefix' => 'probe_',
    ]]);

    $schema = DB::connection('token-membership-fixture')->getSchemaBuilder();
    $schema->create('staff', function (Blueprint $table) use ($staffClass): void {
        if ($staffClass === SiteTokenUlidStaff::class) {
            $table->ulid('id')->primary();
        } else {
            $table->id();
        }
    });

    foreach (['site_user' => 'site_id', 'org_user' => 'org_id'] as $membership => $scope) {
        $schema->create($membership, function (Blueprint $table) use ($scope, $staffClass, $cascade): void {
            $table->foreignId($scope);
            $staff = $table->foreignIdFor($staffClass, 'user_id')->constrained('staff', 'id');
            if ($cascade) {
                $staff->cascadeOnDelete();
            }
            $table->primary([$scope, 'user_id']);
        });
    }
}

afterEach(fn () => DB::purge('token-membership-fixture'));

it('keeps the token migration outside ordinary application installs', function (): void {
    expect(Schema::hasTable(SiteTokenSchema::TABLE))->toBeFalse();
});

it('revokes credentials permanently when either membership ends and when the staff account is removed', function (string $staffClass, int|string $staffId): void {
    siteTokenMembershipFixture($staffClass);
    SiteTokenSchema::create($staffClass);
    $db = DB::connection('token-membership-fixture');
    $db->table('staff')->insert(['id' => $staffId]);

    foreach (['site_user' => 'site_id', 'org_user' => 'org_id'] as $membership => $scope) {
        $db->table($membership)->insert([$scope => 1, 'user_id' => $staffId]);
    }

    $issue = static fn () => $db->table(SiteTokenSchema::TABLE)->insert([
        'site_id' => 1,
        'org_id' => 1,
        'actor_id' => $staffId,
        'actor_model' => $staffClass,
        'name' => 'Test token',
        'token' => hash('sha256', 'fixture'),
        'abilities' => json_encode(['entry.article.view'], JSON_THROW_ON_ERROR),
        'expires_at' => now()->addHour(),
    ]);

    foreach (['site_user' => 'site_id', 'org_user' => 'org_id'] as $membership => $scope) {
        $issue();
        $db->table($membership)->where($scope, 1)->where('user_id', $staffId)->delete();
        $db->table($membership)->insert([$scope => 1, 'user_id' => $staffId]);
        expect($db->table(SiteTokenSchema::TABLE)->count())->toBe(0);
    }

    $issue();
    $db->table('staff')->where('id', $staffId)->delete();
    expect($db->table(SiteTokenSchema::TABLE)->count())->toBe(0);
})->with([
    'integer host' => [SiteTokenIntegerStaff::class, 91],
    'ULID host' => [SiteTokenUlidStaff::class, '01jh0000000000000000000000'],
]);

it('refuses a membership table without staff deletion cleanup before creating the token table', function (): void {
    siteTokenMembershipFixture(SiteTokenIntegerStaff::class, cascade: false);

    expect(fn () => SiteTokenSchema::create(SiteTokenIntegerStaff::class))
        ->toThrow(RuntimeException::class, 'cascade deletion')
        ->and(Schema::connection('token-membership-fixture')->hasTable(SiteTokenSchema::TABLE))->toBeFalse();
});

it('drops storage on the same staff connection chosen by up', function (): void {
    siteTokenMembershipFixture(SiteTokenIntegerStaff::class);
    SiteTokenSchema::create(SiteTokenIntegerStaff::class);
    $schema = Schema::connection('token-membership-fixture');

    expect($schema->hasTable(SiteTokenSchema::TABLE))->toBeTrue();
    SiteTokenSchema::drop(SiteTokenIntegerStaff::class);

    expect($schema->hasTable(SiteTokenSchema::TABLE))->toBeFalse()
        ->and($schema->hasTable('staff'))->toBeTrue();
});
