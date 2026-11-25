<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Benchmarks;

use Illuminate\Database\Connection;
use RuntimeException;

/** Owned, opt-in benchmark databases only. This is not an application database configurator. @internal */
final class SchemaScaleDatabase
{
    private bool $owned = false;

    /** @var array<string, int|string> */
    private array $native = [];

    public function __construct(
        private readonly Connection $db,
        private readonly string $root,
        private readonly string $out,
        private readonly int $rows,
    ) {}

    public function assertOwnedEmpty(): void
    {
        if (storage_path() !== $this->out.'/storage' || realpath($this->out.'/storage') !== $this->out.'/storage') {
            throw new RuntimeException('The running host must use only the owned database and storage.');
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->out.'/storage', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('The owned storage must not traverse a link.');
            }
        }
        $path = $this->out.'/owned-experiment.json';
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('A regular owned experiment marker is required.');
        }
        $marker = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($marker)) {
            throw new RuntimeException('The ownership marker must be an object.');
        }
        if ($this->driver() === 'sqlite') {
            $file = $this->out.'/scale.sqlite';
            if ($this->db->getDatabaseName() !== $file
                || $marker !== ['purpose' => 'kitsune-schema-scale', 'root' => $this->root, 'rows' => $this->rows]
                || ! is_file($file) || is_link($file) || filesize($file) !== 0) {
                throw new RuntimeException('The experiment must start from a new owned DB.');
            }
        } else {
            $this->native = self::nativeConfiguration($marker, $this->db->getConfig(), $this->root, $this->rows);
            $identity = $this->db->selectOne($this->driver() === 'pgsql'
                ? 'select current_database() as name' : 'select database() as name');
            if ($identity->name !== $this->native['database']) {
                throw new RuntimeException('The actual native database differs from its owned marker.');
            }
            $schema = $this->db->getSchemaBuilder();
            $tables = $schema->getTables();
            if (count($tables) !== 1 || $tables[0]['name'] !== 'kitsune_scale_ownership' || $schema->getViews() !== []) {
                throw new RuntimeException('A native experiment must contain only its ownership record before migrations.');
            }
            $owners = $this->db->table('kitsune_scale_ownership')->get();
            if ($owners->count() !== 1 || $owners[0]->nonce !== $this->native['nonce']
                || $owners[0]->root_sha256 !== hash('sha256', $this->root)
                || (int) $owners[0]->rows !== $this->rows || $owners[0]->driver !== $this->driver()) {
                throw new RuntimeException('The server-side ownership record differs from the local marker.');
            }
            $version = $this->rawVersion();
            if (in_array($this->driver(), ['mysql', 'mariadb'], true)
                && str_contains($version, 'MariaDB') !== ($this->driver() === 'mariadb')) {
                throw new RuntimeException('The native server brand differs from the requested driver.');
            }
        }
        $this->assertEnforcement();
        $this->owned = true;
    }

    /**
     * Refuse endpoint indirection before a PDO connection, including read/write hosts and Unix sockets.
     *
     * @param  array<string, mixed>  $marker
     * @param  array<string, mixed>  $config
     * @return array{driver: 'pgsql'|'mysql'|'mariadb', host: '127.0.0.1', port: int, database: string, nonce: string, container_id: string}
     */
    public static function nativeConfiguration(array $marker, array $config, string $root, int $rows): array
    {
        $native = $marker['native'] ?? [];
        if (! is_array($native)) {
            throw new RuntimeException('Refusing an ambient or indirect native database connection.');
        }
        $keys = array_keys($native);
        sort($keys);
        if (($marker['purpose'] ?? null) !== 'kitsune-schema-scale' || ($marker['root'] ?? null) !== $root
            || ($marker['rows'] ?? null) !== $rows || count($marker) !== 4
            || $keys !== ['container_id', 'database', 'driver', 'host', 'nonce', 'port']
            || ! in_array($native['driver'] ?? null, ['pgsql', 'mysql', 'mariadb'], true)
            || ($native['host'] ?? null) !== '127.0.0.1' || ! is_int($native['port'] ?? null)
            || $native['port'] < 1024 || $native['port'] > 65535
            || ! is_string($native['nonce'] ?? null) || ! preg_match('/^[0-9a-f]{24}$/D', $native['nonce'])
            || ($native['database'] ?? null) !== 'kitsune_scale_'.$native['nonce']
            || ! is_string($native['container_id'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $native['container_id'])
            || ($config['driver'] ?? null) !== $native['driver'] || ($config['host'] ?? null) !== $native['host']
            || (string) ($config['port'] ?? '') !== (string) $native['port']
            || ($config['database'] ?? null) !== $native['database'] || ($config['prefix'] ?? '') !== ''
            || ! empty($config['url']) || ! empty($config['unix_socket']) || ! empty($config['read']) || ! empty($config['write'])
            || ! empty($config['direct']) || ! empty($config['pooled'])
            || ($native['driver'] === 'pgsql' && ($config['search_path'] ?? 'public') !== 'public')) {
            throw new RuntimeException('Refusing an ambient or indirect native database connection.');
        }

        return $native;
    }

    public function driver(): string
    {
        return $this->db->getDriverName();
    }

    public function assertActiveConnection(Connection $db): void
    {
        $this->requireOwned();
        if ($db !== $this->db || $this->db->transactionLevel() !== 0) {
            throw new RuntimeException('The owned database and outer commit must remain active.');
        }
    }

    /**
     * Explicit fixture IDs do not advance a PostgreSQL sequence; real model writes must not collide.
     *
     * @return array{explicit_fixture_max_id: int, postgres_sequence_advanced: bool}
     */
    public function advanceEntryIdentity(): array
    {
        $this->requireOwned();
        $max = (int) $this->db->table('entries')->max('id');
        if ($this->driver() === 'pgsql') {
            $this->db->selectOne('select setval(pg_get_serial_sequence(?, ?), ?, true) as value', ['entries', 'id', $max]);
        }

        return ['explicit_fixture_max_id' => $max, 'postgres_sequence_advanced' => $this->driver() === 'pgsql'];
    }

    /**
     * Native enforcement plus independent orphan probes; nullable composite keys retain MATCH SIMPLE semantics.
     *
     * @return array{enabled: true, orphans: 0, method: string, constraints_checked?: int}
     */
    public function foreignKeys(): array
    {
        $this->requireOwned();
        $this->assertEnforcement();
        if ($this->db->transactionLevel() !== 0) {
            throw new RuntimeException('Foreign-key controls require an outer commit.');
        }
        if ($this->driver() === 'sqlite') {
            if ($this->db->select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('The owned SQLite database contains a foreign-key violation.');
            }

            return ['enabled' => true, 'orphans' => 0, 'method' => 'PRAGMA foreign_key_check'];
        }
        $schema = $this->db->getSchemaBuilder();
        $grammar = $this->db->getQueryGrammar();
        $checked = 0;
        foreach ($schema->getTables() as $table) {
            foreach ($schema->getForeignKeys($table['schema_qualified_name']) as $key) {
                if ($key['foreign_schema'] !== $table['schema'] || count($key['columns']) !== count($key['foreign_columns'])) {
                    throw new RuntimeException('A benchmark foreign key escaped the owned schema.');
                }
                $on = $notNull = [];
                foreach ($key['columns'] as $i => $column) {
                    $on[] = $grammar->wrap('c.'.$column).' = '.$grammar->wrap('p.'.$key['foreign_columns'][$i]);
                    $notNull[] = $grammar->wrap('c.'.$column).' is not null';
                }
                $sql = 'select 1 as orphan from '.$grammar->wrapTable($table['schema_qualified_name']).' as c left join '
                    .$grammar->wrapTable($key['foreign_schema'].'.'.$key['foreign_table']).' as p on '.implode(' and ', $on)
                    .' where '.implode(' and ', $notNull).' and '.$grammar->wrap('p.'.$key['foreign_columns'][0]).' is null limit 1';
                if ($this->db->selectOne($sql) !== null) {
                    throw new RuntimeException('The owned native database contains a foreign-key violation.');
                }
                $checked++;
            }
        }
        if ($checked === 0) {
            throw new RuntimeException('The native migrations created no foreign-key controls.');
        }

        return ['enabled' => true, 'orphans' => 0, 'constraints_checked' => $checked, 'method' => 'native enforcement and nullable-aware anti-joins'];
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array<int, mixed>|null
     */
    public function plan(string $sql, array $bindings): ?array
    {
        $this->requireOwned();
        if (! preg_match('/^\s*select\b/i', $sql)) {
            return null;
        }
        $prefix = match ($this->driver()) {
            'sqlite' => 'EXPLAIN QUERY PLAN ',
            'pgsql' => 'EXPLAIN (FORMAT JSON) ',
            'mysql', 'mariadb' => 'EXPLAIN FORMAT=JSON ',
            default => throw new RuntimeException('Unsupported benchmark database driver.'),
        };

        return $this->db->select($prefix.$sql, $bindings);
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        $this->requireOwned();
        $facts = ['driver' => $this->driver(), 'version' => $this->rawVersion(), 'plan_semantics' => 'Estimated SELECT plans; no ANALYZE re-execution. Query durations measured separately.'];
        if ($this->driver() === 'sqlite') {
            foreach (['foreign_keys', 'journal_mode', 'synchronous', 'page_size', 'page_count', 'freelist_count'] as $name) {
                $facts['sqlite_pragmas'][$name] = $this->db->selectOne('PRAGMA '.$name);
            }
        } elseif ($this->driver() === 'pgsql') {
            foreach (['server_encoding', 'block_size', 'shared_buffers', 'work_mem', 'synchronous_commit', 'full_page_writes', 'wal_compression', 'default_toast_compression'] as $name) {
                $facts['settings'][$name] = $this->db->selectOne('select current_setting(?, true) as value', [$name])->value;
            }
            $facts['database_locale'] = $this->db->selectOne('select datcollate, datctype from pg_database where datname=current_database()');
        } else {
            $facts['settings'] = $this->db->selectOne('select @@character_set_database as character_set_database, @@collation_database as collation_database, @@foreign_key_checks as foreign_key_checks, @@innodb_buffer_pool_size as innodb_buffer_pool_size, @@innodb_flush_log_at_trx_commit as innodb_flush_log_at_trx_commit, @@innodb_file_per_table as innodb_file_per_table');
        }

        return $facts;
    }

    /** @return array{semantics: string, objects: array<int, mixed>} */
    public function storage(): array
    {
        $this->requireOwned();

        return match ($this->driver()) {
            'sqlite' => ['semantics' => 'SQLite dbstat allocated B-tree bytes; file-level facts recorded separately.', 'objects' => $this->db->select('select name, sum(pgsize) as bytes from dbstat group by name order by name')],
            // pg_table_size includes TOAST/FSM/VM; total adds indexes. These are not interchangeable with InnoDB estimates.
            // https://www.postgresql.org/docs/17/functions-admin.html#FUNCTIONS-ADMIN-DBSIZE
            'pgsql' => ['semantics' => 'PostgreSQL relation bytes; table_bytes includes TOAST/FSM/VM, total_bytes includes indexes.', 'objects' => $this->db->select("select c.relname as name, pg_relation_size(c.oid) as main_fork_bytes, pg_table_size(c.oid) as table_bytes, pg_indexes_size(c.oid) as index_bytes, pg_total_relation_size(c.oid) as total_bytes from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname=current_schema() and c.relkind in ('r','p') order by c.relname")],
            // InnoDB TABLE_ROWS and DATA/INDEX_LENGTH are estimates/allocated-page measures, not exact logical contents.
            // https://dev.mysql.com/doc/refman/8.4/en/information-schema-tables-table.html
            'mysql', 'mariadb' => ['semantics' => 'InnoDB information_schema estimated rows and approximate allocated clustered/nonclustered index bytes; no exact logical-size claim.', 'objects' => $this->db->select('select table_name as name, engine, row_format, table_rows as estimated_rows, data_length as approximate_clustered_bytes, index_length as approximate_secondary_index_bytes, data_free as free_extent_bytes from information_schema.tables where table_schema=database() order by table_name')],
            default => throw new RuntimeException('Unsupported benchmark database driver.'),
        };
    }

    private function assertEnforcement(): void
    {
        $enabled = match ($this->driver()) {
            'sqlite' => (int) $this->db->selectOne('PRAGMA foreign_keys')->foreign_keys === 1,
            'pgsql' => $this->db->selectOne("select current_setting('session_replication_role') as value")->value === 'origin',
            'mysql', 'mariadb' => (int) $this->db->selectOne('select @@foreign_key_checks as enabled')->enabled === 1,
            default => false,
        };
        if (! $enabled) {
            throw new RuntimeException('Native foreign-key enforcement must remain enabled.');
        }
    }

    private function rawVersion(): string
    {
        return (string) $this->db->selectOne($this->driver() === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v')->v;
    }

    private function requireOwned(): void
    {
        if (! $this->owned) {
            throw new RuntimeException('An empty owned database must be verified before measurement.');
        }
    }
}
