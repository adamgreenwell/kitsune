<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Tests\Fixtures\TestImpostor;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Role assignments on a host whose database connection carries a table prefix — ADR-033.
 *
 * ⚠️ EVERY PREFIXED HOST RESOLVED NO ROLES, found while designing #91's fixture rather than by a host. `Permissions`
 * decides whether `role_user` is about a user model by comparing the table its foreign key references with
 * `Model::getTable()`. `Schema::getForeignKeys()` reports that table as the database holds it, prefix included, and
 * the model names it without — measured on SQLite, PostgreSQL, MySQL and MariaDB alike. So on any connection with a
 * `prefix`, the right model was refused: no role resolved, the holder picker was disabled, and the last-owner guard
 * refused every change. Fail-closed, and unusable.
 *
 * ⚠️ ASKED ON A SECOND CONNECTION TO THE SAME DATABASE, because a prefix on the default one would rename every table
 * this suite migrated. Its DDL runs in its own session, so even on MySQL it cannot commit `RefreshDatabase`'s
 * transaction on the default connection. Its foreign key is named explicitly, because MySQL and MariaDB keep
 * constraint names unique per database and Laravel generates them without the prefix: `role_user_user_id_foreign`
 * already exists there, on this suite's own fixture table.
 *
 * ⚠️ ON THE PREDICATE, for the reason `PermissionsTest` gives for the identity-connection case: resolving `held()` end
 * to end would need the whole schema on the second connection, and none of this test's rows.
 */

it('recognises the assignment identity on a connection with a table prefix', function (): void {
    $default = DB::getDefaultConnection();

    config(['database.connections.prefixed' => [
        ...config("database.connections.{$default}"),
        'prefix' => 'kitsune_prefixed_',
    ]]);

    $schema = Schema::connection('prefixed');
    $schema->dropIfExists('role_user');
    $schema->dropIfExists('users');

    try {
        $schema->create('users', fn (Blueprint $table) => $table->id());
        $schema->create('role_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id', 'kitsune_prefixed_role_user_user_id_fk')->references('id')->on('users');
        });

        DB::setDefaultConnection('prefixed');
        Permissions::forget();

        // The prefix is real, and it is what the schema reports — not a name this test made up.
        expect(DB::connection()->getTablePrefix())->toBe('kitsune_prefixed_')
            ->and(collect(Schema::getForeignKeys('role_user'))->pluck('foreign_table')->all())->toBe(['kitsune_prefixed_users']);

        expect(Permissions::assignmentsAreAbout(TestUser::class))->toBeTrue()
            // ⚠️ Not vacuous: a model on another table is still refused through the same comparison.
            ->and(Permissions::assignmentsAreAbout(TestImpostor::class))->toBeFalse();
    } finally {
        DB::setDefaultConnection($default);
        Permissions::forget();

        $schema->dropIfExists('role_user');
        $schema->dropIfExists('users');
        DB::purge('prefixed');
    }
});
