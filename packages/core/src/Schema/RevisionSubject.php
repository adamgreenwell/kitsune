<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Site;

/** @internal The field and scope that identified a subject when an entry revision was created. */
final class RevisionSubject
{
    /**
     * Capture only schema and scope. The subject value already lives in the existing revision representations.
     *
     * @return array{org: int, site: ?int, type: int, storage: int, strategy: string, key: string, fields: array<string, array{storage: int, strategy: string, key: string}>}|null
     */
    public static function capture(Entry $entry): ?array
    {
        $registry = app(FieldTypeRegistry::class);
        $fields = FieldStorage::query()->useWritePdo()
            ->select('field_storage.*', 'fields.id as revision_field_id', 'entry_types.subject_field_id as revision_subject_field_id')
            ->join('fields', 'fields.field_storage_id', '=', 'field_storage.id')
            ->join('entry_types', 'entry_types.id', '=', 'fields.entry_type_id')
            ->where('entry_types.id', $entry->entry_type_id)->whereNotNull('entry_types.subject_field_id')
            ->where(fn ($query) => $query->whereNull('entry_types.org_id')->orWhere('entry_types.org_id', $entry->org_id))
            ->where(fn ($query) => $query->whereNull('field_storage.org_id')->orWhereColumn('field_storage.org_id', 'entry_types.org_id'))
            ->where(fn ($query) => $query->whereColumn('fields.id', 'entry_types.subject_field_id')
                ->orWhereIn('field_storage.pii_class', ['personal', 'sensitive']))
            ->orderBy('field_storage.id')->get();

        $subject = null;
        $projection = [];
        foreach ($fields as $field) {
            if (! $registry->has($field->type)) {
                continue;
            }

            $type = $registry->get($field->type);
            $strategy = $type->strategy();
            $key = match ($strategy) {
                StorageStrategy::Inline => $field->handle,
                StorageStrategy::Promoted => $type->promotedColumn(),
                StorageStrategy::Relational => (string) $field->id,
            };
            if ($key === null || ($strategy === StorageStrategy::Promoted && ! in_array($key, EntryRevision::REDACTABLE_COLUMNS, true))) {
                continue;
            }

            $spec = ['storage' => (int) $field->id, 'strategy' => $strategy->value, 'key' => $key];
            if ((int) $field->getAttribute('revision_field_id') === (int) $field->getAttribute('revision_subject_field_id')) {
                $subject = $spec;
            }
            if (in_array($field->pii_class, ['personal', 'sensitive'], true)) {
                $projection[$field->handle] = $spec;
            }
        }

        if ($subject === null) {
            return null;
        }

        return [
            'org' => (int) $entry->org_id,
            'site' => $entry->site_id === null ? null : (int) $entry->site_id,
            'type' => (int) $entry->entry_type_id,
            ...$subject,
            'fields' => $projection,
        ];
    }

    /**
     * Compare one safely loaded revision. The caller must restrict the query to authorized parent entries first.
     * Null means the historical subject cannot be established from the retained revision.
     */
    public static function matches(EntryRevision $revision, Site $site, int $storageId, bool|int|float|string $identifier): ?bool
    {
        $schema = $revision->subject_schema;
        if ($schema === null || ($schema['type'] ?? null) !== (int) $revision->entry_type_id
            || ! is_string($schema['key'] ?? null) || ! is_int($schema['storage'] ?? null)
            || ! array_key_exists('site', $schema) || ! is_int($schema['org'] ?? null)) {
            return null;
        }

        if ($schema['org'] !== (int) $site->org_id) {
            return false;
        }

        if (($schema['site'] !== null && $schema['site'] !== (int) $site->id) || $schema['storage'] !== $storageId) {
            return null;
        }

        $key = $schema['key'];

        if (($schema['strategy'] ?? null) === StorageStrategy::Relational->value) {
            return self::matchesRelation($revision, $key, $identifier);
        }

        $value = match ($schema['strategy'] ?? null) {
            StorageStrategy::Inline->value => $revision->values[$key] ?? null,
            StorageStrategy::Promoted->value => in_array($key, EntryRevision::REDACTABLE_COLUMNS, true) ? $revision->getAttribute($key) : null,
            default => null,
        };

        return is_scalar($value) ? $value === $identifier : null;
    }

    private static function matchesRelation(EntryRevision $revision, string $key, bool|int|float|string $identifier): ?bool
    {
        $state = $revision->getAttribute('relation_state');
        if (! is_array($state)) {
            return null;
        }

        // An explicit null may be a redaction. The revision can still hold personal data in other fields.
        $targets = array_key_exists($key, $state) ? $state[$key] : [];

        if (! is_array($targets) || ! array_is_list($targets) || count($targets) > 1) {
            return null;
        }

        return is_int($identifier) && ($targets[0] ?? null) === $identifier;
    }
}
