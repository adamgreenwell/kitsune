<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/**
 * Read identifier witnesses on an explicitly disposable MySQL/MariaDB database. This creates and drops
 * only randomly named probe tables, never a Kitsune installation or module receipt. See README.md.
 */
if (PHP_SAPI !== 'cli' || getenv('KITSUNE_CUSTODY_PROBE') !== 'disposable') {
    throw new RuntimeException('Explicit disposable-database admission is required.');
}

$database = getenv('KITSUNE_PROBE_DATABASE');
if (! is_string($database) || preg_match('/\Akitsune_custody_probe_[a-z0-9_]+\z/', $database) !== 1) {
    throw new RuntimeException('Use a dedicated kitsune_custody_probe_ database.');
}
$pdo = new PDO(
    'mysql:host='.(getenv('KITSUNE_PROBE_HOST') ?: '127.0.0.1').';port='.(getenv('KITSUNE_PROBE_PORT') ?: '3306')
        .';dbname='.$database.';charset=utf8mb4',
    getenv('KITSUNE_PROBE_USERNAME') ?: '',
    getenv('KITSUNE_PROBE_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$report = $pdo->query('select version() as version, @@lower_case_table_names as folding, @@sql_quote_show_create as quoted_ddl, @@character_set_connection as connection_charset')->fetch(PDO::FETCH_ASSOC);
$report['cases'] = [];
$suffix = '_kc263_'.bin2hex(random_bytes(6)).'_commerce_future';
$quote = static fn (string $name): string => '`'.str_replace('`', '``', $name).'`';

foreach (['İ', 'ΟΣ', 'ẞ', 'K', 'Ä', 'A`B', 'é', "e\u{0301}", 'İ_commerce_'] as $prefix) {
    $requested = $prefix.$suffix;
    $table = $quote($database).'.'.$quote($requested);
    $created = false;
    try {
        $pdo->exec('create table '.$table.' (id int not null)');
        $created = true;
        $pdo->exec('insert into '.$table.' values (1)');
        $ddl = $pdo->query('show create table '.$table)->fetch(PDO::FETCH_ASSOC);
        $catalogue = array_values(array_filter($pdo->query('show tables')->fetchAll(PDO::FETCH_COLUMN),
            static fn (string $name): bool => str_ends_with($name, $suffix)));
        $physical = null;
        if (preg_match('/\ACREATE TABLE `((?:[^`]|``)+)` \(/', $ddl['Create Table'] ?? '', $match) === 1) {
            $physical = str_replace('``', '`', $match[1]);
        }
        $rows = (int) $pdo->query('select count(*) from '.$table)->fetchColumn();
        $physicalRows = $physical === null ? null : (int) $pdo->query('select count(*) from '.$quote($database).'.'.$quote($physical))->fetchColumn();
        $report['cases'][] = [
            'requested' => $requested, 'catalogue' => $catalogue, 'result_table_field' => $ddl['Table'] ?? null,
            'ddl_identifier' => $physical, 'requested_bytes' => strlen($requested), 'physical_bytes' => $physical === null ? null : strlen($physical),
            'requested_rows' => $rows, 'physical_rows' => $physicalRows,
        ];
        if ($physical === null || $catalogue !== [$physical] || $rows !== 1 || $physicalRows !== 1) {
            throw new RuntimeException('Physical identity witness failed; do not admit this combination.');
        }
    } finally {
        if ($created) {
            $pdo->exec('drop table '.$table);
        }
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
