<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use InvalidArgumentException;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Site;

/** @internal Subject discovery in one authorized Site, including revisions of retyped entries. */
final class SubjectDiscovery
{
    public function __construct(
        private readonly EntitlementAuthority $authority,
        private readonly FieldTypeRegistry $fields,
    ) {}

    /**
     * Return only matching record identities. Unknown coverage is a count and contains no payload.
     *
     * @return array{entries: list<int>, revisions: list<array{id: int, entry: int}>, history_indeterminate: int, live_covered: bool, records?: list<array<string, mixed>>}
     */
    public function forSite(int $typeId, int $storageId, #[\SensitiveParameter] bool|int|float|string $identifier, bool $includeRecords = false): array
    {
        return EntitlementAuthority::mapped(EntitlementRefused::EXPORT, null, function () use ($typeId, $storageId, $identifier, $includeRecords): array {
            $siteId = $this->authority->site(EntitlementRefused::EXPORT, null);
            $orgId = $this->authority->org(EntitlementRefused::EXPORT);
            $this->authority->authorise(EntitlementRefused::EXPORT, null);

            $site = Site::query()->where('org_id', $orgId)->find($siteId);
            $type = EntryType::query()->where(fn ($query) => $query->whereNull('org_id')->orWhere('org_id', $orgId))->find($typeId);
            if ($site === null || $type === null) {
                throw new InvalidArgumentException('The selected Site or entry type is unavailable.');
            }

            $parents = Entry::query()->withTrashed()->where('entries.org_id', $orgId);
            $nomination = $type->subjectField;
            $storage = $nomination?->fieldStorage;
            $entries = [];
            $records = [];
            $projection = $includeRecords ? $type->fields()->with('fieldStorage')->get()
                ->pluck('fieldStorage')->filter(fn ($field): bool => $field instanceof FieldStorage
                    && ($field->org_id === null || $field->org_id === $type->org_id)
                    && in_array($field->pii_class, ['personal', 'sensitive'], true)
                    && $this->fields->has($field->type))->unique('id')->values()->all() : [];
            $liveCovered = $nomination !== null && $nomination->entry_type_id === $type->id
                && $storage !== null && $storage->id === $storageId
                && ($storage->org_id === null || $storage->org_id === $type->org_id)
                && $this->fields->has($storage->type);

            if ($liveCovered) {
                $relation = $storage->strategy() === StorageStrategy::Relational;
                if ($relation && (! is_int($identifier) || $identifier < 1)) {
                    throw new InvalidArgumentException('A relational subject requires a positive integer entry identity.');
                }
                foreach ((clone $parents)->whereSubjectIs($type, $identifier)->lazyById(200, 'entries.id', 'id') as $entry) {
                    $retained = $entry->subjectValue();
                    if (($relation && $retained === [$identifier]) || (! $relation && $retained === $identifier)) {
                        $entries[] = (int) $entry->id;
                        if ($includeRecords) {
                            $records[] = ['kind' => 'entry', 'id' => (int) $entry->id, 'site' => $entry->site_id,
                                'data' => $this->project($entry, $projection, $storageId, $identifier)];
                        }
                    }
                }
            }

            $revisions = [];
            $unknown = 0;
            $relationTargetVisible = null;
            foreach (EntryRevision::query()->where('entry_type_id', $typeId)
                ->whereIn('entry_id', (clone $parents)->select('entries.id'))
                ->lazyById(200) as $revision) {
                $match = RevisionSubject::matches($revision, $site, $storageId, $identifier);
                if ($match && ($revision->subject_schema['strategy'] ?? null) === StorageStrategy::Relational->value) {
                    $relationTargetVisible ??= is_int($identifier) && (clone $parents)->whereKey($identifier)->exists();
                    $match = $relationTargetVisible ? true : null;
                }

                if ($match === null) {
                    $unknown++;
                } elseif ($match) {
                    $revisions[] = ['id' => (int) $revision->id, 'entry' => (int) $revision->entry_id];
                    if ($includeRecords) {
                        $records[] = ['kind' => 'revision', 'id' => (int) $revision->id, 'entry' => (int) $revision->entry_id,
                            'site' => $revision->subject_schema['site'], 'data' => $this->projectRevision($revision, $storageId, $identifier)];
                    }
                }
            }

            $result = ['entries' => $entries, 'revisions' => $revisions, 'history_indeterminate' => $unknown, 'live_covered' => $liveCovered];
            if ($includeRecords) {
                $result['records'] = $records;
            }

            return $result;
        });
    }

    /**
     * The requested subject relation can be exported as a reference. Other relations may contain information about
     * someone else. Live data comes only from fields currently declared personal or sensitive.
     *
     * @param  list<FieldStorage>  $projection
     * @return array<string, mixed>
     */
    private function project(Entry $record, array $projection, int $storageId, #[\SensitiveParameter] bool|int|float|string $identifier): array
    {
        $data = [];
        foreach ($projection as $field) {
            $value = match ($field->strategy()) {
                StorageStrategy::Inline => $record->values[$field->handle] ?? null,
                StorageStrategy::Promoted => ($column = $field->promotedColumn()) !== null
                    && in_array($column, EntryRevision::REDACTABLE_COLUMNS, true) ? $record->getAttribute($column) : null,
                StorageStrategy::Relational => $field->id === $storageId && is_int($identifier) ? ['entry' => $identifier] : null,
            };
            if ($value !== null) {
                $data[$field->handle] = $value;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function projectRevision(EntryRevision $revision, int $storageId, #[\SensitiveParameter] bool|int|float|string $identifier): array
    {
        $fields = $revision->subject_schema['fields'] ?? null;
        $data = [];
        if (! is_array($fields)) {
            return $data;
        }

        foreach ($fields as $name => $spec) {
            if (! is_string($name) || ! is_array($spec) || ! is_string($spec['key'] ?? null)) {
                continue;
            }

            $key = $spec['key'];
            $value = match ($spec['strategy'] ?? null) {
                StorageStrategy::Inline->value => $revision->values[$key] ?? null,
                StorageStrategy::Promoted->value => in_array($key, EntryRevision::REDACTABLE_COLUMNS, true) ? $revision->getAttribute($key) : null,
                StorageStrategy::Relational->value => ($spec['storage'] ?? null) === $storageId && is_int($identifier) ? ['entry' => $identifier] : null,
                default => null,
            };
            if ($value !== null) {
                $data[$name] = $value;
            }
        }

        return $data;
    }
}
