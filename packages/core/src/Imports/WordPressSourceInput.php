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
use Kitsune\Core\Fields\Types\RichTextType;

/**
 * @internal
 *
 * @phpstan-import-type SourceRow from WordPressWxrExport
 */
final readonly class WordPressSourceInput
{
    public function __construct(private WordPressDraftInput $drafts) {}

    /**
     * @param  array{sha256: string, blog: string, rows: list<SourceRow>}  $source
     * @param  array<string, int>  $destinationTypes
     * @param  list<ImportMediaAsset>  $media
     * @param  array<string, string>  $bodyFields
     */
    public function preview(
        string $mode, array $source, string $sourceKey, string $acfSchema, array $destinationTypes,
        bool $complete, array $media, ?string $mediaRoot, array $bodyFields = [],
    ): ImportPlan {
        $fields = self::fields($acfSchema);
        $descriptors = array_column($media, null, 'sourceId');
        foreach ($bodyFields as $type => $field) {
            if (isset($fields[$type][$field])) {
                throw new InvalidArgumentException('A body field cannot also be mapped from ACF.');
            }
        }
        $findings = [];
        $bodyNotices = $bodyFields === [] ? [] : [[
            'position' => 0, 'code' => 'wordpress-body-source-rendering-not-reproduced',
        ]];
        $bodyBytes = 0;
        $metadataOnly = $bodyFields === [];
        $records = [];
        $attachments = [];
        $approved = [];
        foreach ($source['rows'] as $row) {
            $id = $row['id'];
            if ($row['type'] === 'attachment') {
                $asset = $descriptors[$id] ?? null;
                if ($asset === null || $asset->sourceType !== 'attachment' || ($row['meta']['_wp_attached_file'] ?? []) !== [$asset->path]) {
                    $findings[] = ['code' => 'attachment-path-not-approved', 'source_id' => $id];

                    continue;
                }
                $attachments[] = ['source_id' => $id, 'source_size' => $asset->size, 'source_sha256' => $asset->sha256];
                $approved[] = $asset;

                continue;
            }
            if (! isset($destinationTypes[$row['type']]) || ! in_array($row['status'], ['publish', 'draft'], true)) {
                $findings[] = ['code' => 'post-kind-or-status-needs-review', 'source_id' => $id];

                continue;
            }

            [$values, $links, $issues] = self::recordFields($fields[$row['type']] ?? [], $row['meta']);
            foreach ($issues as $field => $code) {
                $findings[] = ['code' => $code, 'source_id' => $id, 'field' => $field];
            }
            $bodyField = $bodyFields[$row['type']] ?? null;
            if ($bodyField === null) {
                $metadataOnly = true;
            } else {
                [$normalized, $bodyFinding] = self::body($row);
                if ($bodyFinding !== null) {
                    $findings[] = ['code' => $bodyFinding, 'source_id' => $id, 'field' => $bodyField];
                } elseif (strlen($normalized) > WordPressBodyRead::MAX_TOTAL_BYTES - $bodyBytes) {
                    $findings[] = ['code' => 'body-output-exceeds-byte-limit', 'source_id' => $id, 'field' => $bodyField];
                } else {
                    $bodyBytes += strlen($normalized);
                    $values[$bodyField] = $normalized;
                    $notice = match (true) {
                        $normalized !== $row['body'] => 'wordpress-body-markup-normalized',
                        ! str_contains($row['body'], '<!-- wp:')
                            && preg_match('/\R[ \t]*\R/u', $row['body']) === 1 => 'wordpress-body-classic-layout-needs-review',
                        default => null,
                    };
                    if ($notice !== null) {
                        $bodyNotices[] = [
                            'position' => count($records) + 1,
                            'code' => $notice,
                            'source_id' => $id,
                            'field' => $bodyField,
                        ];
                    }
                }
            }
            $records[] = [
                'source' => ['type' => $row['type'], 'id' => $id],
                'parent_source_id' => $row['parent'],
                'source_status' => $row['status'],
                'source_utc_date' => $row['utc'],
                'content_sha256' => $row['content_sha256'],
                'title' => $row['title'], 'slug' => $row['slug'],
                'values' => (object) $values, 'media_references' => (object) $links,
            ];
        }
        if (count($approved) !== count($media)) {
            $findings[] = ['code' => 'attachment-descriptors-not-used'];
        }

        $projection = json_encode([
            'format' => 1,
            'blocked' => $findings !== [],
            'records' => $records,
            'media' => $attachments,
            'findings' => $findings,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $snapshot = hash('sha256', "kitsune.wordpress.1\0".$mode."\0".$source['sha256'].hash('sha256', $acfSchema).hash('sha256', $projection)
            .($bodyFields === [] ? '' : hash('sha256', json_encode($bodyFields, JSON_THROW_ON_ERROR))));

        return $this->drafts->preview(
            $mode, $sourceKey, $projection, $destinationTypes, $complete, $approved, $mediaRoot, $snapshot,
            $source['blog'], $bodyNotices, $metadataOnly, $bodyFields,
        );
    }

    /**
     * @param  SourceRow  $row
     * @return array{string, null}|array{null, string}
     */
    private static function body(array $row): array
    {
        if (isset($row['body_finding'])) {
            return [null, $row['body_finding']];
        }
        $raw = $row['body'] ?? null;
        if ($raw === null || preg_match('//u', $raw) !== 1 || hash('sha256', $raw) !== $row['content_sha256']) {
            return [null, 'body-source-bytes-unverified'];
        }

        if (self::containsEmbeddedMedia($raw)) {
            return [null, 'body-inline-media-needs-rehosting'];
        }

        $richText = new RichTextType;
        $normalized = $richText->sanitize(self::plainTextParagraphs($raw));
        if (preg_match('~<img(?:\s|>)~', $normalized) === 1) {
            return [null, 'body-inline-media-needs-rehosting'];
        }
        if (trim($raw) !== '' && trim($normalized) === '') {
            return [null, 'body-has-no-convertible-content'];
        }
        if ($richText->sanitize($normalized) !== $normalized) {
            return [null, 'body-normalization-is-unstable'];
        }

        return [$normalized, null];
    }

    private static function containsEmbeddedMedia(string $raw): bool
    {
        // Leave recognized references in source custody until a rehost plan covers them.
        return preg_match(<<<'REGEX'
            ~
              <(?:img|picture|audio|video|source|iframe|object|embed|svg)\b
            | \[(?:gallery|audio|video|embed|caption|playlist)\b
            | <!--\s*wp:(?:image|gallery|embed|cover|file|media-text|audio|video|core-embed/[^\s]+)\b
            | <[^>]+\bstyle\s*=\s*["'][^>]*?\burl\s*\(
            | <[^>]+\b(?:href|src)\s*=\s*["']?[^"'>\s]*?/?wp-content/uploads/
            ~ix
            REGEX, $raw) === 1;
    }

    private static function plainTextParagraphs(string $body): string
    {
        if (trim($body) === '' || preg_match('/<[\/a-z!]/i', $body) === 1 || strpbrk($body, "\r\n") === false) {
            return $body;
        }

        $text = str_replace(["\r\n", "\r"], "\n", trim($body));
        $paragraphs = preg_split('/\n(?:[ \t]*\n)+/', $text) ?: [$text];

        return implode('', array_map(static fn (string $paragraph): string => '<p>'
            .str_replace("\n", '<br>', htmlspecialchars($paragraph, ENT_QUOTES | ENT_HTML5, 'UTF-8', double_encode: false))
            .'</p>', $paragraphs));
    }

    /**
     * @return array<string, array<string, array{key: string, kind: string, required: bool}>>
     */
    private static function fields(string $bytes): array
    {
        try {
            $schema = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new InvalidArgumentException('The reviewed ACF report is not valid JSON.', previous: $failure);
        }
        if (! is_array($schema) || ($schema['blocked'] ?? null) !== false || ! is_array($schema['groups'] ?? null)) {
            throw new InvalidArgumentException('Supply an unblocked reviewed ACF field report.');
        }

        $definitions = [];
        foreach ($schema['groups'] as $group) {
            if (! is_array($group) || ! is_array($group['post_types'] ?? null) || ! is_array($group['fields'] ?? null)) {
                throw new InvalidArgumentException('The reviewed ACF field group is invalid.');
            }
            foreach ($group['post_types'] as $type) {
                if (! is_string($type) || $type === '') {
                    throw new InvalidArgumentException('The reviewed ACF field group needs a post type.');
                }
                foreach ($group['fields'] as $field) {
                    if (! is_array($field) || ! is_string($field['name'] ?? null) || $field['name'] === ''
                        || ! is_string($field['source_key'] ?? null) || ! is_bool($field['required'] ?? null)
                        || ! is_string($field['proposed_kind'] ?? null) || isset($definitions[$type][$field['name']])) {
                        throw new InvalidArgumentException('The reviewed ACF report contains an invalid or repeated field.');
                    }
                    $definitions[$type][$field['name']] = ['key' => $field['source_key'], 'kind' => $field['proposed_kind'], 'required' => $field['required']];
                }
            }
        }

        return $definitions;
    }

    /**
     * @param  array<string, array{key: string, kind: string, required: bool}>  $fields
     * @param  array<string, list<?string>>  $meta
     * @return array{array<string, scalar>, array<string, string>, array<string, string>}
     */
    private static function recordFields(array $fields, array $meta): array
    {
        $values = $links = $issues = [];
        foreach ($fields as $handle => $field) {
            $valuesForField = $meta[$handle] ?? [];
            $refs = $meta['_'.$handle] ?? [];
            if ($valuesForField === [] && $refs === [] && ! $field['required']) {
                continue;
            }
            if ($refs !== [$field['key']] || count($valuesForField) !== 1 || ! is_string($valuesForField[0])) {
                $issues[$handle] = 'ambiguous-acf-value';

                continue;
            }

            $raw = $valuesForField[0];
            if ($field['kind'] === 'media-reference' && preg_match('/^[1-9][0-9]{0,63}$/D', $raw) === 1) {
                $links[$handle] = $raw;

                continue;
            }
            $value = match ($field['kind']) {
                'text' => $raw,
                'boolean' => match ($raw) {
                    '0' => false,
                    '1' => true,
                    default => null,
                },
                'number' => preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $raw) === 1 ? $raw : null,
                default => null,
            };
            if ($value === null || ($field['required'] && $value === '')) {
                $issues[$handle] = 'unsupported-acf-value';
            } else {
                $values[$handle] = $value;
            }
        }

        return [$values, $links, $issues];
    }
}
