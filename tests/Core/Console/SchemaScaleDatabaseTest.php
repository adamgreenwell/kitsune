<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\File;
use Kitsune\Benchmarks\SchemaScaleDatabase;

require_once dirname(__DIR__, 3).'/bin/benchmarks/SchemaScaleDatabase.php';

it('refuses native endpoint indirection before opening a PDO connection', function (array $overrides): void {
    $requested = sys_get_temp_dir().'/kitsune-scale-native-guard-'.bin2hex(random_bytes(8));
    mkdir($requested, 0700);
    $out = realpath($requested);
    mkdir($out.'/storage', 0700);
    $root = dirname(__DIR__, 3);
    $nonce = str_repeat('a', 24);
    $native = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 15432, 'database' => 'kitsune_scale_'.$nonce, 'nonce' => $nonce, 'container_id' => str_repeat('b', 64)];
    file_put_contents($out.'/owned-experiment.json', json_encode(['purpose' => 'kitsune-schema-scale', 'root' => $root, 'rows' => 10_000, 'native' => $native]));
    $config = array_replace(['driver' => 'pgsql', 'host' => $native['host'], 'port' => $native['port'], 'database' => $native['database'], 'prefix' => '', 'search_path' => 'public'], $overrides);
    $connections = 0;
    $db = new PostgresConnection(function () use (&$connections): PDO {
        $connections++;
        throw new RuntimeException('An invalid benchmark must never open PDO.');
    }, $config['database'], '', $config);
    $storage = storage_path();
    app()->useStoragePath($out.'/storage');
    try {
        expect(fn () => (new SchemaScaleDatabase($db, $root, $out, 10_000))->assertOwnedEmpty())
            ->toThrow(RuntimeException::class, 'Refusing an ambient or indirect native database connection.');
        expect($connections)->toBe(0);
    } finally {
        app()->useStoragePath($storage);
        File::deleteDirectory($out);
    }
})->with([
    'remote host' => [['host' => '198.51.100.42']],
    'ordinary database' => [['database' => 'kitsune']],
    'alternate port' => [['port' => 5432]],
    'read host' => [['read' => ['host' => '198.51.100.42']]],
    'write host' => [['write' => ['host' => '198.51.100.42']]],
    'socket' => [['unix_socket' => '/tmp/ambient.sock']],
    'connection URL' => [['url' => 'pgsql://example.invalid/ordinary']],
    'pooled direct host' => [['pooled' => true, 'direct' => ['host' => '198.51.100.42']]],
    'different schema' => [['search_path' => 'public,ordinary']],
]);

it('admits only the explicit native configuration and returns no credentials as ownership metadata', function (string $driver): void {
    $root = dirname(__DIR__, 3);
    $nonce = str_repeat('c', 24);
    $native = ['driver' => $driver, 'host' => '127.0.0.1', 'port' => 15432, 'database' => 'kitsune_scale_'.$nonce, 'nonce' => $nonce, 'container_id' => str_repeat('d', 64)];
    $marker = ['purpose' => 'kitsune-schema-scale', 'root' => $root, 'rows' => 10_000, 'native' => $native];
    $config = $native + ['prefix' => '', 'username' => 'synthetic', 'password' => 'unexported-canary'];
    $selected = SchemaScaleDatabase::nativeConfiguration($marker, $config, $root, 10_000);
    expect($selected)->toBe($native)->not->toHaveKey('password')->not->toHaveKey('username');
})->with(['pgsql', 'mysql', 'mariadb']);

it('refuses measurement before ownership even with a valid-looking connection', function (): void {
    $db = new PostgresConnection(fn () => throw new RuntimeException('PDO must remain unopened.'), 'kitsune_scale_'.str_repeat('e', 24), '', ['driver' => 'pgsql']);
    $database = new SchemaScaleDatabase($db, dirname(__DIR__, 3), '/not-owned', 10_000);
    expect(fn () => $database->plan('select 1', []))
        ->toThrow(RuntimeException::class, 'An empty owned database must be verified before measurement.');
});
