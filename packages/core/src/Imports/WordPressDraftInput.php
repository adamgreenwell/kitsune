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
 * Convert a reviewed reader projection into the same draft plan used by CSV and JSON.
 *
 * @internal
 *
 * @phpstan-import-type Finding from ImportPlan
 */
final readonly class WordPressDraftInput
{
    private const WXR_LIMITS = [
        'export-filters-and-omissions-are-not-recoverable-from-wxr',
        'site-options-and-attachment-bytes-are-not-included',
        'author-identities-comments-taxonomy-and-url-rules-need-separate-review',
    ];

    public function __construct(private ImportJournal $journal) {}

    /**
     * @param  array<string, int>  $destinationTypes
     * @param  list<ImportMediaAsset>  $media
     */
    public function preview(
        string $mode, string $sourceKey, string $projection, array $destinationTypes, bool $complete,
        array $media, ?string $mediaRoot, ?string $snapshot = null, ?string $confirmedBlog = null,
    ): ImportPlan {
        if (! in_array($mode, ['direct', 'wxr'], true)) {
            throw new InvalidArgumentException('Use a direct or WXR WordPress projection.');
        }

        try {
            $report = json_decode($projection, depth: 64, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('The WordPress projection is not valid JSON.', previous: $failure);
        }

        if (! $report instanceof stdClass || ($report->format ?? null) !== 1 || ! is_bool($report->blocked ?? null)
            || ! self::knownKeys($report, ['format', 'source', 'blocked', 'records', 'media', 'findings', 'limits'])
            || (property_exists($report, 'source') && ! is_string($report->source))) {
            throw new InvalidArgumentException('The WordPress projection envelope is invalid.');
        }

        $media = self::approvedMedia($report->media ?? null, $media);
        $attachments = array_fill_keys(array_column($media, 'sourceId'), true);
        $drafts = [];
        $positions = [];
        $unavailableLinks = [];
        foreach (self::items($report->records ?? null) as $row) {
            if (! $row instanceof stdClass || ! ($row->source ?? null) instanceof stdClass
                || ! self::knownKeys($row, ['source', 'parent_source_id', 'slug', 'title', 'source_status',
                    'source_utc_date', 'content_sha256', 'values', 'media_references'])
                || ! self::knownKeys($row->source, ['type', 'id'])
                || ! is_string($row->source->type ?? null) || ! self::validId($row->source->id ?? null)) {
                throw new InvalidArgumentException('The WordPress projection contains an invalid source record.');
            }
            $id = $row->source->id;
            if (isset($positions[$id])) {
                throw new InvalidArgumentException('The WordPress projection repeats a source post ID.');
            }
            $positions[$id] = count($drafts) + 1;

            $values = self::properties($row->values ?? null);
            $links = [];
            foreach (self::properties($row->media_references ?? null) as $field => $attachment) {
                if (! self::validId($attachment)) {
                    throw new InvalidArgumentException('The WordPress projection contains an invalid attachment link.');
                }
                if (! isset($attachments[$attachment])) {
                    $unavailableLinks[$id] = true;
                }
                $links[$field] = [(object) ['source_type' => 'attachment', 'source_id' => $attachment]];
            }
            $drafts[] = [
                'source_type' => $row->source->type,
                'source_id' => $id,
                'entry_type_id' => $destinationTypes[$row->source->type] ?? null,
                'title' => $row->title ?? null,
                'slug' => $row->slug ?? null,
                'values' => (object) $values,
                'links' => (object) $links,
            ];
        }

        $blockers = self::sourceFindings($report->findings ?? null, $positions);
        foreach (array_keys($unavailableLinks) as $id) {
            $blockers[] = ['position' => $positions[$id], 'code' => 'wordpress-unapproved-attachment-link', 'source_id' => (string) $id];
        }
        if ($drafts === [] && $media === [] && $blockers === []) {
            $blockers[] = ['position' => 0, 'code' => 'empty-wordpress-projection'];
        }
        if ($report->blocked && $blockers === []) {
            $blockers[] = ['position' => 0, 'code' => 'wordpress-source-blocked'];
        }
        $notices = ['wordpress-body-conversion-not-performed' => ['position' => 0, 'code' => 'wordpress-body-conversion-not-performed']];
        $limits = self::items($report->limits ?? []);
        array_push($limits, ...($mode === 'wxr' ? self::WXR_LIMITS : []));
        foreach ($limits as $code) {
            if (! is_string($code)) {
                throw new InvalidArgumentException('The WordPress projection contains an invalid limit.');
            }
            $diagnostic = 'wordpress-limit-'.$code;
            $notices[$diagnostic] = ['position' => 0, 'code' => $diagnostic];
        }

        return $this->journal->preview(
            $sourceKey, 'wordpress:'.$mode.':v1', $snapshot ?? hash('sha256', $projection), $drafts, $complete,
            $media, $mediaRoot,
            ['blockers' => $blockers, 'notices' => array_values($notices)], $confirmedBlog,
        );
    }

    /**
     * @param  array<array-key, int>  $positions
     * @return list<Finding>
     */
    private static function sourceFindings(mixed $value, array $positions): array
    {
        $findings = [];
        foreach (self::items($value) as $finding) {
            if (! $finding instanceof stdClass || ! is_string($finding->code ?? null)
                || ! self::knownKeys($finding, ['code', 'source_id', 'field'])
                || (isset($finding->source_id) && ! self::validId($finding->source_id))) {
                throw new InvalidArgumentException('The WordPress projection contains an invalid finding.');
            }
            $id = $finding->source_id ?? null;
            $findings[] = [
                'position' => $id === null ? 0 : ($positions[$id] ?? 0),
                'code' => 'wordpress-finding-'.$finding->code,
                ...($id === null ? [] : ['source_id' => $id]),
            ];
        }

        return $findings;
    }

    /**
     * @param  list<ImportMediaAsset>  $media
     * @return list<ImportMediaAsset>
     */
    private static function approvedMedia(mixed $value, array $media): array
    {
        $approved = [];
        foreach (self::items($value) as $item) {
            if (! $item instanceof stdClass || ! self::validId($item->source_id ?? null)
                || ! self::knownKeys($item, ['source_id', 'parent_source_id', 'source_size', 'source_sha256'])
                || ! is_int($item->source_size ?? null) || ! is_string($item->source_sha256 ?? null)
                || isset($approved[$item->source_id])) {
                throw new InvalidArgumentException('The WordPress projection contains invalid or repeated media.');
            }
            $approved[$item->source_id] = $item;
        }

        $matched = [];
        foreach ($media as $asset) {
            $record = $approved[$asset->sourceId] ?? null;
            if ($asset->sourceType !== 'attachment' || $asset->entryTypeId === null
                || $record === null || isset($matched[$asset->sourceId])
                || $asset->size !== $record->source_size || $asset->sha256 !== $record->source_sha256) {
                throw new InvalidArgumentException('An attachment descriptor differs from the reviewed WordPress projection.');
            }
            $matched[$asset->sourceId] = $asset;
        }
        if (count($matched) !== count($approved)) {
            throw new InvalidArgumentException('Provide every attachment descriptor named in the WordPress projection.');
        }

        return array_values($matched);
    }

    /** @param list<string> $keys */
    private static function knownKeys(stdClass $object, array $keys): bool
    {
        return array_diff(array_keys(get_object_vars($object)), $keys) === [];
    }

    /** @phpstan-assert-if-true string $value */
    private static function validId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,63}$/D', $value) === 1;
    }

    /** @return list<mixed> */
    private static function items(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('The WordPress projection requires lists for its records, media and diagnostics.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function properties(mixed $value): array
    {
        if ($value === []) {
            return [];
        }
        if (! $value instanceof stdClass) {
            throw new InvalidArgumentException('WordPress values and attachment links must use field handles.');
        }

        return get_object_vars($value);
    }
}
