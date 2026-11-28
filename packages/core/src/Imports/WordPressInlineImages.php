<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use DomainException;
use InvalidArgumentException;
use stdClass;

/**
 * @internal
 *
 * @phpstan-type Image array{url: string, attachment_id: string, descriptor_sha256: string}
 * @phpstan-type InventoryRow array{source_type: string, body_sha256: string, relation: string, images: list<Image>}
 * @phpstan-type VerifiedImages array{urls: list<string>, attachments: list<string>, positions: list<int>}
 */
final class WordPressInlineImages
{
    /** @return array<array-key, InventoryRow> */
    public static function read(string $bytes): array
    {
        $rows = json_decode($bytes, depth: 16, flags: JSON_THROW_ON_ERROR);
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            throw new InvalidArgumentException('Provide a nonempty WordPress inline image inventory.');
        }

        $inventory = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass
                || ! self::hasFields($row, ['source_type', 'source_id', 'body_sha256', 'relation', 'images'])
                || ! is_string($row->source_type) || ! is_string($row->source_id)
                || preg_match('/^[1-9][0-9]{0,63}$/D', $row->source_id) !== 1
                || ! self::digest($row->body_sha256) || ! is_string($row->relation)
                || preg_match('/^[a-z][a-z0-9_]{0,95}$/D', $row->relation) !== 1
                || ! is_array($row->images) || ! array_is_list($row->images) || $row->images === []
                || isset($inventory[$row->source_id])) {
                throw new InvalidArgumentException('An inline image inventory must identify each WordPress body once.');
            }

            $images = [];
            foreach ($row->images as $image) {
                if (! $image instanceof stdClass || ! self::hasFields($image, ['url', 'attachment_id', 'descriptor_sha256'])
                    || ! is_string($image->url) || strlen($image->url) > 4096
                    || preg_match('~^(?:https?://[^/\s]+/|/[^/\s])[^\x00-\x20]*$~iD', $image->url) !== 1
                    || ! is_string($image->attachment_id) || preg_match('/^[1-9][0-9]{0,63}$/D', $image->attachment_id) !== 1
                    || ! self::digest($image->descriptor_sha256)) {
                    throw new InvalidArgumentException('Each inline image needs a source URL and approved attachment digest.');
                }
                $images[] = ['url' => $image->url, 'attachment_id' => $image->attachment_id, 'descriptor_sha256' => $image->descriptor_sha256];
            }
            $inventory[$row->source_id] = [
                'source_type' => $row->source_type, 'body_sha256' => $row->body_sha256, 'relation' => $row->relation, 'images' => $images,
            ];
        }

        return $inventory;
    }

    /**
     * @param  InventoryRow  $reviewed
     * @param  array<array-key, ImportMediaAsset>  $assets
     * @return VerifiedImages
     */
    public static function verify(array $reviewed, string $type, string $bodyDigest, array $assets): array
    {
        if ($reviewed['source_type'] !== $type || ! hash_equals($reviewed['body_sha256'], $bodyDigest)) {
            throw new DomainException('The reviewed WordPress inline image body changed.');
        }

        $urls = $attachments = $positions = $indices = [];
        foreach ($reviewed['images'] as $image) {
            $id = $image['attachment_id'];
            $asset = $assets[$id] ?? null;
            if ($asset === null || $asset->sourceType !== 'attachment' || ! hash_equals($asset->descriptorHash(), $image['descriptor_sha256'])) {
                throw new DomainException('The reviewed inline attachment descriptor changed.');
            }
            if (! isset($indices[$id])) {
                $indices[$id] = count($attachments);
                $attachments[] = $id;
            }
            $urls[] = $image['url'];
            $positions[] = $indices[$id];
        }

        return ['urls' => $urls, 'attachments' => $attachments, 'positions' => $positions];
    }

    /** @param list<string> $fields */
    private static function hasFields(stdClass $value, array $fields): bool
    {
        $keys = array_keys(get_object_vars($value));

        return count($keys) === count($fields) && array_diff($keys, $fields) === [];
    }

    /** @phpstan-assert-if-true string $value */
    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }
}
