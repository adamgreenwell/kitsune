<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * @internal
 *
 * @phpstan-import-type SourceRow from WordPressWxrExport
 */
final class WordPressDatabaseExport
{
    /** @return array{sha256: string, blog: string, rows: list<SourceRow>} */
    public function read(PDO $source, string $prefix, string $blog): array
    {
        if ($source->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $source->inTransaction()
            || preg_match('/^[a-zA-Z0-9_]{1,54}$/D', $prefix) !== 1 || $blog === '') {
            throw new InvalidArgumentException('Use a separate MySQL source connection and a valid WordPress table prefix.');
        }

        try {
            if ($source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false
                || $source->exec('START TRANSACTION READ ONLY') === false) {
                throw new InvalidArgumentException('Unable to start a read-only WordPress snapshot.');
            }

            try {
                $rows = $this->rows($source, $prefix, $blog);
            } finally {
                if ($source->exec('ROLLBACK') === false) {
                    throw new InvalidArgumentException('Unable to close the read-only WordPress snapshot.');
                }
            }
        } catch (PDOException) {
            throw new InvalidArgumentException('The selected offline WordPress database could not be read.');
        }

        return [
            'sha256' => hash('sha256', json_encode([$prefix, $blog, $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'blog' => $blog,
            'rows' => $rows,
        ];
    }

    /** @return list<SourceRow> */
    private function rows(PDO $source, string $prefix, string $blog): array
    {
        $address = $source->query("SELECT option_value FROM `{$prefix}options` WHERE option_name = 'home'");
        if ($address === false || $address->fetchAll(PDO::FETCH_COLUMN) !== [$blog]) {
            throw new InvalidArgumentException('The confirmed blog address does not match the selected WordPress prefix.');
        }

        $types = $source->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?)');
        if ($types === false || ! $types->execute([$prefix.'options', $prefix.'posts', $prefix.'postmeta'])
            || $types->fetchAll(PDO::FETCH_COLUMN) !== ['InnoDB', 'InnoDB', 'InnoDB']) {
            throw new InvalidArgumentException('The selected WordPress source tables must support a consistent InnoDB snapshot.');
        }

        $posts = $source->query("SELECT CAST(ID AS CHAR) AS id, post_type AS type, post_status AS status,
            CAST(post_parent AS CHAR) AS parent, post_name AS slug, post_title AS title, post_date_gmt AS utc,
            SHA2(CONVERT(post_content USING utf8mb4), 256) AS content_sha256
            FROM `{$prefix}posts` ORDER BY ID");
        if ($posts === false) {
            throw new InvalidArgumentException('Unable to read WordPress posts.');
        }

        $rows = [];
        while (($row = $posts->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row['meta'] = [];
            $rows[$row['id']] = $row;
        }
        $metadata = $source->query("SELECT CAST(post_id AS CHAR) AS post_id, meta_key, meta_value
            FROM `{$prefix}postmeta` ORDER BY meta_id");
        if ($metadata === false) {
            throw new InvalidArgumentException('Unable to read WordPress metadata.');
        }
        while (($row = $metadata->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (isset($rows[$row['post_id']]) && is_string($row['meta_key'])) {
                $rows[$row['post_id']]['meta'][$row['meta_key']][] = $row['meta_value'];
            }
        }

        return array_values($rows);
    }
}
