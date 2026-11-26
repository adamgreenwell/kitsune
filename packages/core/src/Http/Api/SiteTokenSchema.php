<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use RuntimeException;
use Throwable;

/** @internal The first-party product API migration uses the host's existing staff key. */
final class SiteTokenSchema
{
    public const TABLE = 'site_api_tokens';

    /**
     * The host runs this after creating its staff and membership tables.
     * Both memberships are required for issuance, and removing either permanently revokes the credential.
     *
     * @param  class-string<Model>  $staffModel
     */
    public static function create(string $staffModel): void
    {
        $staff = new $staffModel;
        $connection = $staff->getConnection();
        $schema = $connection->getSchemaBuilder();

        foreach (['site_user' => 'site_id', 'org_user' => 'org_id'] as $pivot => $scope) {
            self::requireMembership($schema, $pivot, $scope, $connection->getTablePrefix().$staff->getTable(), $staff->getKeyName());
        }

        $columns = static function (Blueprint $table) use ($staff): void {
            $table->id();
            $table->foreignId('site_id');
            $table->foreignId('org_id');
            $table->foreignIdFor($staff, 'actor_id');
            $table->string('actor_model');
            $table->string('name');
            $table->char('token', 64);
            $table->json('abilities');
            $table->timestamp('expires_at');
            $table->timestamps();
        };

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $schema->create(self::TABLE, static function (Blueprint $table) use ($columns): void {
                $columns($table);
                self::addKeys($table);
            });

            return;
        }

        // MySQL and MariaDB add these keys using separate, non-transactional DDL.
        // Only clean up a table after this migration has successfully created it.
        $schema->create(self::TABLE, $columns);
        try {
            $schema->table(self::TABLE, self::addKeys(...));
        } catch (Throwable $exception) {
            $schema->drop(self::TABLE);

            throw $exception;
        }
    }

    /** @param class-string<Model> $staffModel */
    public static function drop(string $staffModel): void
    {
        (new $staffModel)->getConnection()->getSchemaBuilder()->dropIfExists(self::TABLE);
    }

    private static function addKeys(Blueprint $table): void
    {
        $table->foreign(['site_id', 'actor_id'], 'site_api_tokens_site_member_fk')
            ->references(['site_id', 'user_id'])->on('site_user')->cascadeOnDelete();
        $table->foreign(['org_id', 'actor_id'], 'site_api_tokens_org_member_fk')
            ->references(['org_id', 'user_id'])->on('org_user')->cascadeOnDelete();

        $table->index(['site_id', 'actor_id']);
        // This index serves cascading revocation of that actor's credentials across the whole Org.
        $table->index(['org_id', 'actor_id']);
        $table->index(['site_id', 'id']);
        $table->unique(['site_id', 'token']);
    }

    private static function requireMembership(Builder $schema, string $pivot, string $scope, string $actorTable, string $actorKey): void
    {
        if ($schema->hasIndex($pivot, [$scope, 'user_id'], 'unique')) {
            foreach ($schema->getForeignKeys($pivot) as $key) {
                if ($key['columns'] === ['user_id'] && $key['foreign_table'] === $actorTable
                    && $key['foreign_columns'] === [$actorKey] && $key['on_delete'] === 'cascade') {
                    return;
                }
            }
        }

        throw new RuntimeException("API token storage requires [{$pivot}] to uniquely identify each membership and cascade deletion from the staff table.");
    }
}
