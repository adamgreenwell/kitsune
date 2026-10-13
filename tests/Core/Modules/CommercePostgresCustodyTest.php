<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\CommerceCustody;

/**
 * DDL belongs to a separate PDO, never RefreshDatabase's transaction. Every schema is unique to this
 * invocation and removed in finally, so this probe touches neither public's fixtures nor another run.
 *
 * @param  callable(PostgresConnection, array{first: string, second: string, other: string}): void  $probe
 */
function withCommercePostgresSchemas(string $prefix, callable $probe): void
{
    $config = DB::connection()->getConfig();
    $config['search_path'] = 'public';
    $config['prefix'] = $prefix;
    $pdo = (new PostgresConnector)->connect($config);
    $connection = new PostgresConnection($pdo, $config['database'], $prefix, $config);
    $schemas = [
        'first' => 'commerce_custody_first_'.bin2hex(random_bytes(6)),
        'second' => 'commerce_custody_second_'.bin2hex(random_bytes(6)),
        'other' => 'commerce_custody_other_'.bin2hex(random_bytes(6)),
    ];
    $grammar = $connection->getQueryGrammar();
    $created = [];

    try {
        foreach ($schemas as $name) {
            $connection->unprepared('create schema '.$grammar->wrap($name));
            $created[] = $name;
        }

        $probe($connection, $schemas);
    } finally {
        foreach (array_reverse($created) as $name) {
            $connection->unprepared('drop schema '.$grammar->wrap($name).' cascade');
        }

        $connection->disconnect();
    }
}

it('uses the effective PostgreSQL write session path rather than configured public', function (): void {
    withCommercePostgresSchemas('', function (PostgresConnection $connection, array $schemas): void {
        $connection->getSchemaBuilder()->create($schemas['first'].'.commerce_future', function (Blueprint $table): void {
            $table->id();
        });
        $connection->table($schemas['first'].'.commerce_future')->insert(['id' => 1]);
        $connection->unprepared('set search_path to '.$connection->getQueryGrammar()->wrap($schemas['first']));

        expect($connection->getSchemaBuilder()->getCurrentSchemaListing())->toBe(['public'])
            ->and($connection->table('commerce_future')->exists())->toBeTrue();

        expect(fn () => CommerceCustody::refuseKeptRows($connection))
            ->toThrow(RuntimeException::class, 'kitsune/commerce still holds data');
    });
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL schema visibility');

it('checks a retained PostgreSQL table behind an empty same-name shadow', function (): void {
    withCommercePostgresSchemas('', function (PostgresConnection $connection, array $schemas): void {
        foreach (['first', 'second'] as $key) {
            $connection->getSchemaBuilder()->create($schemas[$key].'.commerce_future', function (Blueprint $table): void {
                $table->id();
            });
        }

        $connection->table($schemas['second'].'.commerce_future')->insert(['id' => 1]);
        $connection->unprepared('set search_path to '.implode(', ', array_map(
            $connection->getQueryGrammar()->wrap(...),
            [$schemas['first'], $schemas['second']],
        )));

        expect($connection->table('commerce_future')->exists())->toBeFalse();

        expect(fn () => CommerceCustody::refuseKeptRows($connection))
            ->toThrow(RuntimeException::class, 'kitsune/commerce still holds data');
    });
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL schema visibility');

it('does not claim commerce rows in an unrelated PostgreSQL schema', function (): void {
    withCommercePostgresSchemas('', function (PostgresConnection $connection, array $schemas): void {
        foreach (['first', 'other'] as $key) {
            $connection->getSchemaBuilder()->create($schemas[$key].'.commerce_future', function (Blueprint $table): void {
                $table->id();
            });
        }

        $connection->table($schemas['other'].'.commerce_future')->insert(['id' => 1]);
        $connection->unprepared('set search_path to '.$connection->getQueryGrammar()->wrap($schemas['first']));

        CommerceCustody::refuseKeptRows($connection);

        expect($connection->table($schemas['first'].'.commerce_future')->count())->toBe(0)
            ->and($connection->table($schemas['other'].'.commerce_future')->count())->toBe(1);
    });
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL schema visibility');

it('qualifies PostgreSQL schemas and preserves an exact mixed-case connection prefix', function (): void {
    withCommercePostgresSchemas('Host_', function (PostgresConnection $connection, array $schemas): void {
        foreach (['first', 'second'] as $key) {
            $connection->getSchemaBuilder()->create($schemas[$key].'.commerce_future', function (Blueprint $table): void {
                $table->id();
            });
        }

        $connection->table($schemas['second'].'.commerce_future')->insert(['id' => 1]);
        $connection->unprepared('set search_path to '.implode(', ', array_map(
            $connection->getQueryGrammar()->wrap(...),
            [$schemas['first'], $schemas['second']],
        )));

        expect($connection->table('commerce_future')->exists())->toBeFalse();

        $connection->enableQueryLog();

        expect(fn () => CommerceCustody::refuseKeptRows($connection))
            ->toThrow(RuntimeException::class, 'kitsune/commerce still holds data');

        $queries = array_column($connection->getQueryLog(), 'query');

        expect(array_filter($queries, fn (string $query): bool => str_contains(
            $query,
            '"'.$schemas['second'].'"."Host_commerce_future"',
        )))->not->toBeEmpty();
    });
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'PostgreSQL schema visibility');
