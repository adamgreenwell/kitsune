<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Benchmarks\SchemaScaleOwnedReadConnection;

require_once __DIR__.'/SchemaScaleOwnedReadConnection.php';

/** @param list<int|string> $bindings */
function one(PDO $pdo, string $sql, array $bindings = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($bindings);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (! is_array($row)) {
        throw new RuntimeException('A benchmark state row is missing.');
    }

    return $row;
}

try {
    if (count($argv) < 4 || count($argv) > 5 || ($application = realpath($argv[1])) === false || ($fixture = realpath($argv[2])) !== $argv[2]
        || ! in_array($argv[3], ['1', '3', '11'], true) || (isset($argv[4]) && ! ctype_digit($argv[4]))) {
        throw new InvalidArgumentException('Use: php schema-scale-write-state.php APPLICATION FIXTURE SELECTED_ENTRY [AFTER_AUDIT_ID]');
    }

    $connection = SchemaScaleOwnedReadConnection::open(dirname(__DIR__, 2), $application, $fixture);
    $pdo = $connection->pdo;
    $id = (int) $argv[3];
    $values = in_array($connection->driver, ['mysql', 'mariadb'], true)
        ? '`values`'
        : '"values"';
    $entry = one($pdo, 'SELECT id,org_id,site_id,deleted_at,title,slug,'.$values.' AS data FROM entries WHERE id = ?', [$id]);
    $latest = one($pdo, 'SELECT id,'.$values.' AS data,relation_state FROM entry_revisions WHERE entry_id = ? ORDER BY id DESC LIMIT 1', [$id]);
    $actor = one($pdo, 'SELECT id FROM users WHERE email = ?', ['scale-owner-1@example.test']);
    if ((int) $entry['org_id'] !== 1 || (int) $entry['site_id'] !== 1 || $entry['deleted_at'] !== null) {
        throw new RuntimeException('The expected live benchmark article is missing.');
    }

    $entry['data'] = json_decode($entry['data'], true, flags: JSON_THROW_ON_ERROR);
    $latest['data'] = json_decode($latest['data'], true, flags: JSON_THROW_ON_ERROR);
    $latest['relation_state'] = json_decode($latest['relation_state'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
    $links = $pdo->prepare('SELECT org_id,target_entry_id FROM entry_relations WHERE source_entry_id = ? ORDER BY ordering');
    $links->execute([$id]);

    $result = [
        'complete' => true,
        'engine' => $connection->driver,
        'entry' => $entry,
        'latest' => $latest,
        'links' => $links->fetchAll(PDO::FETCH_ASSOC),
        'history' => (int) one($pdo, 'SELECT count(*) AS total FROM entry_revisions WHERE entry_id = ?', [$id])['total'],
        'actor_id' => (int) $actor['id'],
        'audit' => one($pdo, 'SELECT coalesce(max(id),0) AS latest,count(*) AS count FROM audit_log'),
    ];
    if (isset($argv[4])) {
        $after = $pdo->prepare('SELECT org_id,site_id,actor_type,actor_id,action,target_type,target_id FROM audit_log WHERE id > ? ORDER BY id LIMIT 601');
        $after->execute([(int) $argv[4]]);
        $result['after_audit'] = $after->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // The caller can log the category without printing private connection or record data.
    fwrite(STDERR, 'Benchmark write state refused: '.$error::class."\n");
    exit(1);
}
