<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Exact bytes of a versioned dry-run report for the internal draft writer.
 *
 * @internal
 *
 * @phpstan-type Finding array{position: int, code: string}
 * @phpstan-type DraftAction array{source_type: string, source_id: string, entry_type_id: int, title: string, slug: string, expected_revision_id: ?int}
 */
final readonly class ImportPlan
{
    /**
     * @param  list<DraftAction>  $records
     * @param  list<Finding>  $findings
     */
    private function __construct(
        private string $bytes,
        public int $orgId,
        public int $siteId,
        public string $sourceKey,
        public bool $complete,
        public array $records,
        public array $findings,
    ) {}

    /**
     * @param  list<DraftAction>  $records
     * @param  list<Finding>  $findings
     */
    public static function prepare(
        int $orgId, int $siteId, string $sourceKey, string $adapter, string $snapshot, array $records, array $findings,
    ): self {
        return self::restore(json_encode([
            'version' => 1,
            'org_id' => $orgId,
            'site_id' => $siteId,
            'source_key' => $sourceKey,
            'adapter' => $adapter,
            'snapshot_sha256' => $snapshot,
            'complete' => $findings === [],
            'records' => $records,
            'findings' => $findings,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function report(): string
    {
        return $this->bytes;
    }

    public static function restore(string $bytes): self
    {
        try {
            $envelope = json_decode($bytes, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('The import report is not valid JSON.', previous: $error);
        }

        if (! $envelope instanceof stdClass) {
            throw new InvalidArgumentException('The import report must be a JSON object.');
        }

        $plan = get_object_vars($envelope);
        if (array_keys($plan) !== ['version', 'org_id', 'site_id', 'source_key', 'adapter', 'snapshot_sha256', 'complete', 'records', 'findings']
            || $plan['version'] !== 1
            || ! is_int($plan['org_id']) || $plan['org_id'] < 1
            || ! is_int($plan['site_id']) || $plan['site_id'] < 1
            || ! is_string($plan['source_key']) || ! self::validKey($plan['source_key'])
            || ! is_string($plan['adapter']) || ! self::validKey($plan['adapter'])
            || ! is_string($plan['snapshot_sha256']) || preg_match('/^[0-9a-f]{64}$/D', $plan['snapshot_sha256']) !== 1
            || ! is_bool($plan['complete'])
            || ! is_array($plan['records']) || ! array_is_list($plan['records'])
            || ! is_array($plan['findings']) || ! array_is_list($plan['findings'])
            || $plan['complete'] !== ($plan['findings'] === [])) {
            throw new InvalidArgumentException('The import report envelope is invalid.');
        }

        // Canonical JSON excludes duplicate object keys, alternate byte forms and partial decoding.
        if (json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== $bytes) {
            throw new InvalidArgumentException('The import report is outside its canonical byte format.');
        }

        $records = [];
        $seen = [];

        foreach ($plan['records'] as $record) {
            $action = self::draft($record);
            $key = $action['source_type'].':'.hash('sha256', $action['source_id']);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('The import report repeats a source record.');
            }

            $seen[$key] = true;
            $records[] = $action;
        }

        $findings = [];
        foreach ($plan['findings'] as $item) {
            if (! $item instanceof stdClass || array_keys(get_object_vars($item)) !== ['position', 'code']
                || ! is_int($item->position) || $item->position < 0
                || ! is_string($item->code) || ! self::validKey($item->code)) {
                throw new InvalidArgumentException('The import report contains an invalid finding.');
            }

            $findings[] = ['position' => $item->position, 'code' => $item->code];
        }

        return new self($bytes, $plan['org_id'], $plan['site_id'], $plan['source_key'], $plan['complete'], $records, $findings);
    }

    public function digest(): string
    {
        return hash('sha256', "kitsune.draft-import.v1\0".$this->bytes);
    }

    /** @return DraftAction */
    public static function draft(mixed $record): array
    {
        if (! $record instanceof stdClass) {
            throw new InvalidArgumentException('Each import action must be a JSON object.');
        }

        $action = get_object_vars($record);
        if (array_keys($action) !== ['source_type', 'source_id', 'entry_type_id', 'title', 'slug', 'expected_revision_id']
            || ! is_string($action['source_type']) || ! self::validKey($action['source_type'])
            || ! is_string($action['source_id']) || $action['source_id'] === '' || strlen($action['source_id']) > 4096
            || ! is_int($action['entry_type_id']) || $action['entry_type_id'] < 1
            || ! is_string($action['title']) || trim($action['title']) === '' || mb_strlen($action['title']) > 255
            || ! is_string($action['slug']) || strlen($action['slug']) > 255
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $action['slug']) !== 1
            || ($action['expected_revision_id'] !== null && (! is_int($action['expected_revision_id']) || $action['expected_revision_id'] < 1))) {
            throw new InvalidArgumentException('The import report contains an invalid draft action.');
        }

        return [
            'source_type' => $action['source_type'],
            'source_id' => $action['source_id'],
            'entry_type_id' => $action['entry_type_id'],
            'title' => $action['title'],
            'slug' => $action['slug'],
            'expected_revision_id' => $action['expected_revision_id'],
        ];
    }

    private static function validKey(string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D', $key) === 1;
    }
}
