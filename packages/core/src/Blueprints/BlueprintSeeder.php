<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Illuminate\Support\Facades\Validator;
use Kitsune\Core\Blueprints\Declarations\SeedEntryDeclaration;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\BlueprintSeed;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Schema\FieldStorageWrites;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/** Row-only optional content, committed once and subsequently operator-owned — ADR-039. @internal */
final class BlueprintSeeder
{
    /** @return array{created: list<string>, skipped: list<string>, retained: list<string>} */
    public static function seed(BlueprintDefinition $definition, Site $site, string $locale): array
    {
        $context = app(Context::class);
        $org = $context->org();
        $previousSite = $context->site();
        if ($org === null || ! $definition instanceof DeclaresSeedContent) {
            throw new RuntimeException('Seeding requires an existing organisation and a blueprint declaring seed content.');
        }
        // Freeze declarations before opening the transaction: a callback must not supply a different list per pass.
        $declarations = self::declarations($definition);
        $connection = (new Blueprint)->getConnection();
        foreach ([new BlueprintSeed, new Site, new EntryType, new Field, new FieldStorage, new Entry, new EntryRelation, new EntryRevision, new AuditLog] as $model) {
            if ($model->getConnection()->getPdo() !== $connection->getPdo()) {
                throw new RuntimeException('Seed content and its receipts require the same model writer connection.');
            }
        }

        try {
            return $connection->transaction(function () use ($definition, $site, $locale, $org, $context, $declarations, $connection): array {
                // Same first lock as schema merge/reverse. No seed intent is committed separately.
                $schema = Blueprint::query()->useWritePdo()->where('org_id', $org->id)
                    ->where('handle', $definition->handle())->lockForUpdate()->first();
                if ($schema === null) {
                    throw new RuntimeException('Apply the schema before requesting seed content.');
                }
                $recordedTypes = BlueprintApplier::schemaForSeed($definition, $schema);
                $selected = Site::query()->useWritePdo()->where('org_id', $org->id)->whereKey($site->getKey())->lockForUpdate()->first();
                if ($selected === null || $selected->handle !== $site->handle || $locale === '' || $selected->locale !== $locale) {
                    throw new RuntimeException('Seed content needs an existing current-org site and its exact current locale.');
                }
                $context->setSite($selected);
                $receipt = BlueprintSeed::query()->useWritePdo()->where('blueprint_id', $schema->id)
                    ->where('original_site_id', $selected->id)->lockForUpdate()->first();
                if ($receipt !== null && ($receipt->locale !== $locale || (int) $receipt->site_id !== (int) $selected->id)) {
                    throw new RuntimeException('The seed receipt records a different site or locale; it cannot seed another copy.');
                }
                $facts = $receipt === null ? [] : self::facts($receipt);
                foreach ($facts as $key => $fact) {
                    if (! isset($declarations[$key]) || $declarations[$key]['fingerprint'] !== $fact['fingerprint']) {
                        throw new RuntimeException("Seed declaration [{$key}] changed or was removed. Only new keys may be added.");
                    }
                }
                $new = array_diff_key($declarations, $facts);
                $outcome = ['created' => [], 'skipped' => [], 'retained' => array_keys($facts)];
                if ($new === []) {
                    return $outcome;
                }

                // Resolve only schema-receipted type identities, in stable lock order. Display scopes grant no authority.
                $types = EntryType::query()->useWritePdo()->where('org_id', $org->id)
                    ->whereIn('id', array_column($recordedTypes, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $fieldsByType = FieldStorageWrites::lockTypes($connection, $types->modelKeys());
                $plans = [];
                foreach ($new as $key => $item) {
                    $declaration = $item['declaration'];
                    $recorded = $recordedTypes[$declaration->entryType] ?? null;
                    $type = $recorded === null ? null : $types->get($recorded['id']);
                    if ($type === null || $type->handle !== $declaration->entryType || $type->is_media) {
                        throw new RuntimeException("Seed [{$key}] needs its recorded ordinary entry type; media requires file custody.");
                    }
                    $fields = [];
                    foreach ($fieldsByType[(int) $type->id] ?? [] as $field) {
                        $storage = $field->fieldStorage;
                        if ($storage !== null && isset($recorded['fields'][$storage->handle])) {
                            $fields[$storage->handle] = $field;
                        } elseif ($field->is_required) {
                            throw new RuntimeException("Seed [{$key}] cannot satisfy an operator-added required field it does not declare.");
                        }
                    }
                    if (count($fields) !== count($recorded['fields'])) {
                        throw new RuntimeException("Seed [{$key}] cannot resolve every recorded field attachment.");
                    }
                    $payload = self::payload($declaration, $type, $fields);
                    $identities = [];
                    foreach ($fields as $handle => $field) {
                        $identities[$handle] = ['field_id' => (int) $field->id, 'storage_id' => (int) $field->field_storage_id];
                    }
                    $collision = $payload['slug'] === null ? null : Entry::query()->withTrashed()->useWritePdo()
                        ->where('org_id', $org->id)->where('site_id', $selected->id)->where('entry_type_id', $type->id)
                        ->where('slug', $payload['slug'])->lockForUpdate()->first();
                    if ($collision !== null && $declaration->onCollision === OnCollision::Fail) {
                        throw new RuntimeException("Seed [{$key}] collides with an existing entry, including trash. Nothing was adopted.");
                    }
                    $facts[$key] = ['key' => $key, 'fingerprint' => $item['fingerprint'], 'type_handle' => $declaration->entryType,
                        'type_id' => (int) $type->id, 'fields' => $identities, 'entry_id' => null,
                        'outcome' => $collision === null ? 'created' : 'skipped'];
                    $plans[$key] = ['payload' => $payload, 'fields' => $fields];
                }

                // All rows first. Forward references and cycles are resolved only after every new ID exists.
                $entries = [];
                foreach ($new as $key => $item) {
                    if ($facts[$key]['outcome'] === 'skipped') {
                        $outcome['skipped'][] = $key;

                        continue;
                    }
                    $payload = $plans[$key]['payload'];
                    if ($payload['slug'] !== null && Entry::query()->withTrashed()->useWritePdo()
                        ->where('org_id', $org->id)->where('site_id', $selected->id)
                        ->where('entry_type_id', $payload['entry_type_id'])->where('slug', $payload['slug'])->lockForUpdate()->exists()) {
                        if ($item['declaration']->onCollision === OnCollision::Fail) {
                            throw new RuntimeException("Seed [{$key}] collides with a preceding entry in this batch.");
                        }
                        $facts[$key]['outcome'] = 'skipped';
                        $outcome['skipped'][] = $key;

                        continue;
                    }
                    $entry = new Entry($plans[$key]['payload']);
                    if (! $entry->save()) {
                        throw new RuntimeException("Seed [{$key}] entry creation was vetoed.");
                    }
                    $entries[$key] = $entry;
                    $facts[$key]['entry_id'] = (int) $entry->id;
                    $outcome['created'][] = $key;
                }
                foreach ($entries as $key => $entry) {
                    $declaration = $new[$key]['declaration'];
                    foreach ($declaration->relations as $handle => $targets) {
                        $ids = [];
                        foreach ($targets as $target) {
                            $fact = $facts[$target] ?? null;
                            if ($fact === null || $fact['outcome'] !== 'created') {
                                throw new RuntimeException("Seed [{$key}] cannot relate to skipped or unknown key [{$target}].");
                            }
                            $related = $entries[$target] ?? Entry::query()->useWritePdo()->whereKey($fact['entry_id'])
                                ->where('org_id', $org->id)->where('site_id', $selected->id)
                                ->where('entry_type_id', $fact['type_id'])->where('type_handle', $fact['type_handle'])
                                ->lockForUpdate()->first();
                            if ($related === null) {
                                throw new RuntimeException("Seed target [{$target}] was removed, moved, retyped or trashed; it will not be recreated.");
                            }
                            $ids[] = (int) $related->id;
                        }
                        $field = $plans[$key]['fields'][$handle];
                        $storage = $field->fieldStorage;
                        if ($storage === null) {
                            throw new RuntimeException('A seed relation lost its storage.');
                        }
                        self::validateField($field, $entry, $ids);
                        $entry->syncFieldRelations($storage, $ids);
                    }
                }
                $receipt ??= new BlueprintSeed(['blueprint_id' => $schema->id, 'site_id' => $selected->id,
                    'original_site_id' => $selected->id, 'locale' => $locale]);
                $receipt->version = $definition->version();
                $receipt->manifest = ['format' => 1, 'entries' => array_values($facts)];
                $receipt->seeded_at = now();
                if (! $receipt->save()) {
                    throw new RuntimeException('The seed receipt save was vetoed; the content batch was rolled back.');
                }

                return $outcome;
            });
        } finally {
            $context->forget();
            if ($previousSite !== null) {
                $context->setSite($previousSite);
            } else {
                $context->setOrg($org);
            }
        }
    }

    /** @return array<string, array{declaration: SeedEntryDeclaration, fingerprint: string}> */
    private static function declarations(DeclaresSeedContent $definition): array
    {
        return self::checkDeclarations($definition->seedContent());
    }

    /** Runtime PHP input is checked even when an author's PHPDoc promises the correct shape.
     * @param  array<array-key, mixed>  $rows
     * @return array<string, array{declaration: SeedEntryDeclaration, fingerprint: string}>
     */
    private static function checkDeclarations(array $rows): array
    {
        if (! array_is_list($rows)) {
            throw new RuntimeException('Seed content must be a list of declarations.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (! $row instanceof SeedEntryDeclaration || ! self::handle($row->key) || ! self::handle($row->entryType)
                || isset($result[$row->key]) || ! self::validStatus($row->status)
                || mb_strlen($row->title) > 255 || ($row->slug !== null && mb_strlen($row->slug) > 255)) {
                throw new RuntimeException('Seed declarations need unique stable keys, valid types, status, title and slug.');
            }
            self::checkHandles($row->values + $row->relations);
            if (array_intersect_key($row->values, $row->relations) !== []) {
                throw new RuntimeException('A seed field cannot hold both scalar values and relations.');
            }
            foreach ($row->relations as $targets) {
                self::checkTargets($targets);
            }
            $result[$row->key] = ['declaration' => $row, 'fingerprint' => hash('sha256', 'seed-v1:'.self::canonical([
                $row->key, $row->entryType, $row->title, $row->slug, $row->values, $row->relations, $row->status, $row->onCollision->value,
            ]))];
        }

        return $result;
    }

    /** @param array<array-key, mixed> $fields */
    private static function checkHandles(array $fields): void
    {
        foreach (array_keys($fields) as $handle) {
            if (! is_string($handle) || ! self::handle($handle)) {
                throw new RuntimeException('Seed fields must be declared handles.');
            }
        }
    }

    private static function checkTargets(mixed $targets): void
    {
        if (! is_array($targets) || ! array_is_list($targets)) {
            throw new RuntimeException('Seed relation targets must be ordered lists of seed keys.');
        }
        $seen = [];
        foreach ($targets as $key) {
            if (! is_string($key) || ! self::handle($key) || isset($seen[$key])) {
                throw new RuntimeException('Seed relation keys must be valid and unique.');
            }
            $seen[$key] = true;
        }
    }

    private static function validStatus(string $status): bool
    {
        return in_array($status, Entry::STATUSES, true);
    }

    /**
     * Called under the schema-receipt lock, before reverse makes any write. Original
     * IDs matter even after an operator moves/retypes/trashes the content. No content
     * is deleted here. The returned data-free receipts may go only with a successful
     * ordinary schema reverse. @internal
     *
     * @return list<BlueprintSeed>
     */
    public static function lockForReverse(Blueprint $schema): array
    {
        $receipts = BlueprintSeed::query()->withoutGlobalScopes()->useWritePdo()
            ->where('blueprint_id', $schema->id)->orderBy('id')->lockForUpdate()->get();
        $obstacles = [];
        foreach ($receipts as $receipt) {
            if ((int) $receipt->org_id !== (int) $schema->org_id) {
                throw new RuntimeException('Seed receipt ownership is malformed; reverse wrote nothing.');
            }
            foreach (self::facts($receipt) as $key => $fact) {
                if ($fact['outcome'] !== 'created') {
                    continue;
                }
                $id = $fact['entry_id'];
                if (Entry::query()->withoutGlobalScopes()->useWritePdo()->whereKey($id)->lockForUpdate()->first() !== null
                    || EntryRevision::query()->withoutGlobalScopes()->useWritePdo()->where('entry_id', $id)->exists()
                    || EntryRelation::query()->withoutGlobalScopes()->useWritePdo()->where(fn ($q) => $q->where('source_entry_id', $id)->orWhere('target_entry_id', $id))->exists()) {
                    $obstacles[] = "seed [{$key}] entry {$id} still has content, relations or revisions (including moved, retyped or trashed content)";
                }
            }
        }
        if ($obstacles !== []) {
            throw new RuntimeException('Cannot reverse: '.implode('; ', $obstacles).'. Nothing was written.');
        }

        return $receipts->all();
    }

    /** Deterministic, type-preserving encoding; objects, resources, invalid UTF-8 and recursion refuse. */
    private static function canonical(mixed $value, int $depth = 0): string
    {
        if ($depth > 64 || (! is_array($value) && ! is_scalar($value) && $value !== null)
            || (is_float($value) && ! is_finite($value))) {
            throw new RuntimeException('Seed values must be finite JSON-compatible PHP values with bounded depth.');
        }
        if (is_array($value)) {
            $list = array_is_list($value);
            if (! $list) {
                ksort($value, SORT_STRING);
            }
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = ($list ? '' : self::canonical($key, $depth + 1).':').self::canonical($item, $depth + 1);
            }

            return ($list ? '[' : '{').implode(',', $parts).($list ? ']' : '}');
        }

        return get_debug_type($value).':'.json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, array<string, mixed>> */
    public static function facts(BlueprintSeed $receipt): array
    {
        $manifest = $receipt->manifest;
        // Empty input never writes a receipt. An existing empty manifest is missing
        // committed facts, and cannot authorize replay of a row with no unique slug.
        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['entries'] ?? null)
            || $manifest['entries'] === [] || ! array_is_list($manifest['entries'])) {
            throw new RuntimeException('Malformed seed receipt; no content may be replayed or adopted.');
        }
        $facts = [];
        $ids = [];
        foreach ($manifest['entries'] as $row) {
            if (! is_array($row) || ! is_string($row['key'] ?? null) || ! self::handle($row['key']) || isset($facts[$row['key']])
                || ! is_string($row['fingerprint'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $row['fingerprint']) !== 1
                || ! is_string($row['type_handle'] ?? null) || ! self::handle($row['type_handle'])
                || ! is_int($row['type_id'] ?? null) || $row['type_id'] < 1
                || ! in_array($row['outcome'] ?? null, ['created', 'skipped'], true) || ! is_array($row['fields'] ?? null)
                || ! array_key_exists('entry_id', $row)) {
                throw new RuntimeException('Malformed seed declaration facts.');
            }
            if ($row['outcome'] === 'created') {
                if (! is_int($row['entry_id']) || $row['entry_id'] < 1 || isset($ids[$row['entry_id']])) {
                    throw new RuntimeException('Malformed seed entry identity.');
                }
                $ids[$row['entry_id']] = true;
            } elseif ($row['entry_id'] !== null) {
                throw new RuntimeException('A skipped seed cannot claim an operator entry.');
            }
            foreach ($row['fields'] as $handle => $field) {
                if (! is_string($handle) || ! self::handle($handle) || ! is_array($field)
                    || ! is_int($field['field_id'] ?? null) || $field['field_id'] < 1
                    || ! is_int($field['storage_id'] ?? null) || $field['storage_id'] < 1) {
                    throw new RuntimeException('Malformed seed field identity.');
                }
            }
            $facts[$row['key']] = $row;
        }

        return $facts;
    }

    /** @param array<string, Field> $fields
     * @return array<string, mixed>
     */
    private static function payload(SeedEntryDeclaration $declaration, EntryType $type, array $fields): array
    {
        $payload = ['entry_type_id' => $type->id, 'title' => $declaration->title, 'slug' => $declaration->slug,
            'status' => $declaration->status, 'values' => []];
        $entry = new Entry($payload);
        $registry = app(FieldTypeRegistry::class);
        foreach ($declaration->values + $declaration->relations as $handle => $value) {
            $field = $fields[$handle] ?? null;
            if ($field === null || $field->fieldStorage === null) {
                throw new RuntimeException("Seed field [{$handle}] is not attached through the schema receipt.");
            }
            $fieldType = $registry->get($field->fieldStorage->type);
            if (($fieldType->strategy() === StorageStrategy::Relational) !== array_key_exists($handle, $declaration->relations)) {
                throw new RuntimeException("Seed field [{$handle}] has the wrong scalar/relation declaration.");
            }
        }
        foreach ($fields as $handle => $field) {
            $storage = $field->fieldStorage;
            if ($storage === null) {
                throw new RuntimeException('Seed field storage is missing.');
            }
            $fieldType = $registry->get($storage->type);
            if ($fieldType->strategy() === StorageStrategy::Relational) {
                if ($field->is_required && ($declaration->relations[$handle] ?? []) === []) {
                    throw new RuntimeException("Required seed relation [{$handle}] is empty.");
                }

                continue;
            }
            $column = $fieldType->promotedColumn();
            $value = $declaration->values[$handle] ?? ($column === null ? null : ($payload[$column] ?? null));
            self::validateField($field, $entry, $value);
            if (array_key_exists($handle, $declaration->values)) {
                if ($column === null) {
                    $payload['values'][$handle] = $value;
                } else {
                    if ($column === 'slug' && $declaration->slug !== null && $value !== $declaration->slug) {
                        throw new RuntimeException('Seed slug and its promoted field disagree.');
                    }
                    $payload[$column] = $value;
                }
            }
        }

        return $payload;
    }

    private static function validateField(Field $field, Entry $entry, mixed $value): void
    {
        $storage = $field->fieldStorage;
        if ($storage === null) {
            throw new RuntimeException('Seed field storage is missing.');
        }
        $type = app(FieldTypeRegistry::class)->get($storage->type);
        $config = new FieldConfig($storage, $field, $entry);
        $rules = ['value' => $type->validationRules($config)];
        $elements = $type->elementValidationRules($config);
        if ($elements !== []) {
            $rules['value.*'] = $elements;
        }
        $validator = Validator::make(['value' => $value], $rules);
        if ($validator->fails()) {
            throw new RuntimeException("Seed field [{$storage->handle}] is invalid: ".implode('; ', $validator->errors()->all()));
        }
    }

    private static function handle(string $value): bool
    {
        return strlen($value) <= 255 && preg_match('/^[a-z][a-z0-9_]*$/D', $value) === 1;
    }
}
