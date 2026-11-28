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
 * @phpstan-type Finding array{position: int, code: string, source_id?: string, field?: string}
 * @phpstan-type LinkTargets array<string, list<array{source_type: string, source_id: string}>>
 * @phpstan-type DraftAction array{source_type: string, source_id: string, entry_type_id: int, title: string, slug: string, expected_revision_id: ?int, values?: array<string, mixed>, links?: LinkTargets, reviewed_rich_text?: list<string>}
 */
final readonly class ImportPlan
{
    /**
     * @param  list<DraftAction>  $records
     * @param  list<Finding>  $findings
     * @param  list<ImportMediaAsset>  $media
     * @param  list<Finding>  $sourceNotices
     */
    private function __construct(
        private string $bytes,
        public int $orgId,
        public int $siteId,
        public string $sourceKey,
        public string $schemaDigest,
        public bool $complete,
        public array $records,
        public array $findings,
        public array $media,
        public array $sourceNotices,
        public ?string $sourceAddress,
    ) {}

    /**
     * @param  list<DraftAction>  $records
     * @param  list<Finding>  $findings
     * @param  list<ImportMediaAsset>  $media
     * @param  list<Finding>  $sourceNotices
     */
    public static function prepare(
        int $orgId, int $siteId, string $sourceKey, string $adapter, string $snapshot, string $schemaDigest, array $records, array $findings, array $media = [], array $sourceNotices = [], ?string $sourceAddress = null,
    ): self {
        return self::restore(json_encode([
            'version' => 1,
            'org_id' => $orgId,
            'site_id' => $siteId,
            'source_key' => $sourceKey,
            'adapter' => $adapter,
            'snapshot_sha256' => $snapshot,
            'schema_sha256' => $schemaDigest,
            'complete' => $findings === [],
            'records' => array_map(static function (array $record): array {
                foreach (['values', 'links'] as $object) {
                    if (array_key_exists($object, $record)) {
                        $record[$object] = (object) $record[$object];
                    }
                }

                return $record;
            }, $records),
            'findings' => $findings,
            ...($media === [] ? [] : ['media' => array_map(static fn (ImportMediaAsset $asset): array => $asset->descriptor(), $media)]),
            ...($sourceNotices === [] ? [] : ['source_notices' => $sourceNotices]),
            ...($sourceAddress === null ? [] : ['source_address' => $sourceAddress]),
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
        $keys = ['version', 'org_id', 'site_id', 'source_key', 'adapter', 'snapshot_sha256', 'schema_sha256', 'complete', 'records', 'findings'];
        $expected = [
            ...$keys,
            ...(array_key_exists('media', $plan) ? ['media'] : []),
            ...(array_key_exists('source_notices', $plan) ? ['source_notices'] : []),
            ...(array_key_exists('source_address', $plan) ? ['source_address'] : []),
        ];
        if (array_keys($plan) !== $expected
            || $plan['version'] !== 1
            || ! is_int($plan['org_id']) || $plan['org_id'] < 1
            || ! is_int($plan['site_id']) || $plan['site_id'] < 1
            || ! is_string($plan['source_key']) || ! self::validKey($plan['source_key'])
            || ! is_string($plan['adapter']) || ! self::validKey($plan['adapter'])
            || ! is_string($plan['snapshot_sha256']) || preg_match('/^[0-9a-f]{64}$/D', $plan['snapshot_sha256']) !== 1
            || ! is_string($plan['schema_sha256']) || preg_match('/^[0-9a-f]{64}$/D', $plan['schema_sha256']) !== 1
            || ! is_bool($plan['complete'])
            || ! is_array($plan['records']) || ! array_is_list($plan['records'])
            || ! is_array($plan['findings']) || ! array_is_list($plan['findings'])
            || $plan['complete'] !== ($plan['findings'] === [])
            || (array_key_exists('media', $plan) && (! is_array($plan['media']) || ! array_is_list($plan['media'])))
            || (array_key_exists('source_address', $plan) && ! self::validSourceAddress($plan['source_address']))
            || (array_key_exists('source_notices', $plan) && (! is_array($plan['source_notices']) || $plan['source_notices'] === [] || ! array_is_list($plan['source_notices'])))) {
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

        $findings = self::diagnostics($plan['findings']);
        $sourceNotices = self::diagnostics($plan['source_notices'] ?? []);

        $media = [];
        foreach ($plan['media'] ?? [] as $item) {
            $asset = ImportMediaAsset::fromDescriptor($item);
            $key = DraftRelations::identity($asset->sourceType, $asset->sourceId);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('The import report repeats a source record.');
            }
            $seen[$key] = true;
            $media[] = $asset;
        }

        return new self($bytes, $plan['org_id'], $plan['site_id'], $plan['source_key'], $plan['schema_sha256'], $plan['complete'], $records, $findings, $media, $sourceNotices, $plan['source_address'] ?? null);
    }

    /**
     * @param  list<mixed>  $items
     * @return list<Finding>
     */
    private static function diagnostics(array $items): array
    {
        $findings = [];
        foreach ($items as $item) {
            if (! $item instanceof stdClass
                || ! in_array(array_keys(get_object_vars($item)), [
                    ['position', 'code'],
                    ['position', 'code', 'source_id'],
                    ['position', 'code', 'field'],
                    ['position', 'code', 'source_id', 'field'],
                ], true)
                || ! is_int($item->position) || $item->position < 0
                || ! is_string($item->code) || ! self::validKey($item->code)
                || (property_exists($item, 'source_id') && (! is_string($item->source_id)
                    || $item->source_id === '' || strlen($item->source_id) > 4096))
                || (property_exists($item, 'field') && (! is_string($item->field) || ! self::validKey($item->field)))) {
                throw new InvalidArgumentException('The import report contains an invalid diagnostic.');
            }

            $findings[] = [
                'position' => $item->position,
                'code' => $item->code,
                ...(isset($item->source_id) ? ['source_id' => $item->source_id] : []),
                ...(isset($item->field) ? ['field' => $item->field] : []),
            ];
        }

        return $findings;
    }

    private static function validSourceAddress(mixed $value): bool
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($value);

        return $parts !== false && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']);
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
        $required = ['source_type', 'source_id', 'entry_type_id', 'title', 'slug', 'expected_revision_id'];
        if (array_diff($required, array_keys($action)) !== [] || array_diff(array_keys($action), [...$required, 'values', 'links', 'reviewed_rich_text']) !== []
            || (array_key_exists('values', $action) && ! $action['values'] instanceof stdClass)
            || ! is_string($action['source_type']) || ! self::validKey($action['source_type'])
            || ! is_string($action['source_id']) || $action['source_id'] === '' || strlen($action['source_id']) > 4096
            || ! is_int($action['entry_type_id']) || $action['entry_type_id'] < 1
            || ! is_string($action['title']) || trim($action['title']) === '' || mb_strlen($action['title']) > 255
            || ! is_string($action['slug']) || strlen($action['slug']) > 255
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $action['slug']) !== 1
            || ($action['expected_revision_id'] !== null && (! is_int($action['expected_revision_id']) || $action['expected_revision_id'] < 1))) {
            throw new InvalidArgumentException('The import report contains an invalid draft action.');
        }

        $reviewedRichText = $action['reviewed_rich_text'] ?? [];
        if ((array_key_exists('reviewed_rich_text', $action) && $reviewedRichText === [])
            || ! is_array($reviewedRichText) || ! array_is_list($reviewedRichText)) {
            throw new InvalidArgumentException('Reviewed rich text needs a nonempty list of field handles.');
        }
        $values = isset($action['values']) ? get_object_vars($action['values']) : [];
        $seenRichText = [];
        foreach ($reviewedRichText as $handle) {
            if (! is_string($handle) || ! self::validKey($handle) || ! is_string($values[$handle] ?? null)
                || isset($seenRichText[$handle])) {
                throw new InvalidArgumentException('A reviewed rich-text field is invalid, absent, or repeated.');
            }
            $seenRichText[$handle] = true;
        }

        return [
            'source_type' => $action['source_type'],
            'source_id' => $action['source_id'],
            'entry_type_id' => $action['entry_type_id'],
            'title' => $action['title'],
            'slug' => $action['slug'],
            'expected_revision_id' => $action['expected_revision_id'],
            ...(! array_key_exists('values', $action) ? [] : [
                'values' => json_decode(json_encode($action['values'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            ]),
            ...(! array_key_exists('links', $action) ? [] : ['links' => self::links($action['links'])]),
            ...($reviewedRichText === [] ? [] : ['reviewed_rich_text' => $reviewedRichText]),
        ];
    }

    /** @return LinkTargets */
    private static function links(mixed $input): array
    {
        if (! $input instanceof stdClass) {
            throw new InvalidArgumentException('Relation fields must be provided as an object.');
        }

        $normalized = [];
        foreach (get_object_vars($input) as $field => $targets) {
            if (! is_string($field) || ! self::validKey($field) || ! is_array($targets) || ! array_is_list($targets)) {
                throw new InvalidArgumentException('A relation field must contain an ordered list of targets.');
            }

            $seen = [];
            $links = [];
            foreach ($targets as $target) {
                if (! $target instanceof stdClass || count(get_object_vars($target)) !== 2
                    || ! isset($target->source_type, $target->source_id) || ! is_string($target->source_type) || ! self::validKey($target->source_type)
                    || ! is_string($target->source_id) || $target->source_id === '' || strlen($target->source_id) > 4096) {
                    throw new InvalidArgumentException('A relation target must identify a typed source record.');
                }

                $key = DraftRelations::identity($target->source_type, $target->source_id);
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException('A relation field repeats a source target.');
                }

                $seen[$key] = true;
                $links[] = ['source_type' => $target->source_type, 'source_id' => $target->source_id];
            }
            $normalized[$field] = $links;
        }

        return $normalized;
    }

    private static function validKey(string $key): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D', $key) === 1;
    }
}
