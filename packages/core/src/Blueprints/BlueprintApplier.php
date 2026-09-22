<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Schema\DesiredStorage;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Schema\StorageAdoption;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * Apply a blueprint into the org in context — ADR-039.
 *
 * @internal
 *
 * Public surface here is `BlueprintDefinition` and the declaration types it returns; this class and the
 * adoption rule it calls are `@internal`, on ADR-038's precedent — the extension API is unstable until the
 * v1.2 freeze, and everything still standing at v1.2 is a permanent obligation.
 *
 * ⚠️ THE RECEIPT IS WRITTEN FIRST AND OUTSIDE THE TRANSACTION, WHICH IS THE OPPOSITE OF `ModuleLifecycle`.
 *
 * A module's receipt is written last, because an install that fails should leave no claim that it succeeded.
 * A blueprint's is written first, because the failure that matters is a different one: apply cannot be a
 * single transaction — a blueprint that indexes a field issues DDL through `SchemaManager::sync()`, which
 * commits implicitly on MySQL and MariaDB, the stated reason that class is not an observer — so a crash
 * partway is reachable, and a half-applied blueprint with no receipt is one that nothing can find to finish
 * or undo. `applied_at` null means an apply started and did not finish.
 *
 * ⚠️ ROWS IN A TRANSACTION, GENERATED COLUMNS AFTER IT. That is the admin's own field flow, scaled up: the
 * row is the source of truth and the schema follows it, so `is_indexed` is written inside and `sync()` runs
 * outside, with `kitsune:schema-sync` as the documented repair for the pair coming apart.
 */
final class BlueprintApplier
{
    /**
     * @return array{handle: string, version: string, created: list<string>, adopted: list<string>, skipped: list<string>, indexed: int}
     *
     * @throws RuntimeException
     */
    public static function apply(BlueprintDefinition $definition): array
    {
        $orgId = app(Context::class)->orgId();

        /*
         * ⚠️ REFUSED RATHER THAN DEFAULTED. ADR-039 settles that a blueprint is applied INTO an org; with none
         * in context the rows would be written global, which is the one thing a blueprint may never do. The
         * message names the fix, as `Auditor::recordOrFail()`'s does for the same situation one layer down.
         */
        if ($orgId === null) {
            throw new RuntimeException(
                "Cannot apply [{$definition->handle()}]: no organisation is in context, and a blueprint is "
                .'applied into one (ADR-039). Set it — app(Context::class)->setOrg(...) — before applying from '
                .'a command, a migration or a seeder.'
            );
        }

        $receipt = Blueprint::receiptFor($definition->handle());

        if ($receipt !== null && $receipt->applied_at !== null && $receipt->version === $definition->version()) {
            return self::nothingToDo($definition);
        }

        /*
         * The intent record, committed on its own. An interrupted apply leaves this row with `applied_at`
         * null, which is what a later run recognises — and what makes "a blueprint half applied into this org"
         * a thing an operator can be told rather than a thing they have to notice.
         */
        $receipt ??= new Blueprint(['handle' => $definition->handle()]);
        $receipt->version = $definition->version();
        $receipt->applied_at = null;
        $receipt->manifest = null;
        $receipt->save();

        $outcome = ['created' => [], 'adopted' => [], 'skipped' => []];
        $indexable = [];

        /*
         * ⚠️ On the MODEL's connection rather than the `DB` facade's, which always resolves the default one.
         * `Site::save()` and `SettingsWriter` were both corrected from that, and `ModuleLifecycle` after them.
         */
        $receipt->getConnection()->transaction(function () use ($definition, $orgId, &$outcome, &$indexable): void {
            foreach ($definition->entryTypes() as $declaration) {
                self::applyEntryType($declaration, $orgId, $outcome, $indexable);
            }
        });

        /*
         * ⚠️ AFTER THE TRANSACTION, AND FAILING HERE IS NOT A FAILED APPLY. `is_indexed` is a row that says
         * what the operator wants; the generated column is DDL that cannot join the transaction on two of the
         * four engines. The rows are committed and correct either way, and `kitsune:schema-sync` is the
         * documented repair — which is exactly what the admin does when a field saves but cannot be indexed.
         */
        $indexed = self::syncIndexes($indexable);

        $receipt->manifest = self::manifestOf($definition, $outcome);
        $receipt->applied_at = now();
        $receipt->save();

        return [
            'handle' => $definition->handle(),
            'version' => $definition->version(),
            'created' => $outcome['created'],
            'adopted' => $outcome['adopted'],
            'skipped' => $outcome['skipped'],
            'indexed' => $indexed,
        ];
    }

    /**
     * @param  array{created: list<string>, adopted: list<string>, skipped: list<string>}  $outcome
     * @param  list<FieldStorage>  $indexable
     */
    private static function applyEntryType(
        EntryTypeDeclaration $declaration,
        int $orgId,
        array &$outcome,
        array &$indexable,
    ): void {
        /* `EntryType` is `#[Unscoped]`, so the org is named rather than inherited from context. */
        $type = EntryType::query()
            ->where('org_id', $orgId)
            ->where('handle', $declaration->handle)
            ->first();

        if ($type !== null && $declaration->onCollision === OnCollision::Fail) {
            throw new RuntimeException(sprintf(
                'Entry type [%s] already exists in this organisation, and this blueprint declares it with '
                .'onCollision: fail — so it will not adopt a type it did not create. A type belongs to one '
                .'thing: putting this blueprint\'s fields on it would change something the operator or another '
                .'blueprint owns, and record in the receipt that this apply created it. Remove the type, or '
                .'declare it with onCollision: skip if adopting it is what you meant.',
                $declaration->handle,
            ));
        }

        if ($type !== null) {
            $outcome['skipped'][] = "entry type {$declaration->handle}";
        } else {
            $type = EntryType::create([
                'org_id' => $orgId,
                'handle' => $declaration->handle,
                'name' => $declaration->name,
                'plural_name' => $declaration->pluralName,
                'icon' => $declaration->icon,
                'description' => $declaration->description,
                'ordering' => $declaration->ordering,
            ]);

            $outcome['created'][] = "entry type {$declaration->handle}";
        }

        foreach ($declaration->fields as $field) {
            self::applyField($field, $type, $orgId, $outcome, $indexable);
        }
    }

    /**
     * @param  array{created: list<string>, adopted: list<string>, skipped: list<string>}  $outcome
     * @param  list<FieldStorage>  $indexable
     */
    private static function applyField(
        FieldDeclaration $declaration,
        EntryType $type,
        int $orgId,
        array &$outcome,
        array &$indexable,
    ): void {
        $adopting = StorageAdoption::exists($orgId, $declaration->handle);

        $storage = StorageAdoption::resolve($orgId, new DesiredStorage(
            handle: $declaration->handle,
            type: $declaration->type,
            piiClass: $declaration->piiClass,
            cardinality: $declaration->cardinality,
            isIndexed: $declaration->isIndexed,
            settings: $declaration->settings,
        ), $type);

        $outcome[$adopting ? 'adopted' : 'created'][] = "field storage {$declaration->handle}";

        Field::create([
            'entry_type_id' => $type->getKey(),
            'field_storage_id' => $storage->getKey(),
            'label' => $declaration->label,
            'help_text' => $declaration->helpText,
            'is_required' => $declaration->isRequired,
            'ordering' => $declaration->ordering,
            'group' => $declaration->group,
        ]);

        if ($storage->is_indexed) {
            $indexable[] = $storage;
        }
    }

    /**
     * @param  list<FieldStorage>  $indexable
     */
    private static function syncIndexes(array $indexable): int
    {
        if ($indexable === []) {
            return 0;
        }

        $manager = app(SchemaManager::class);
        $synced = 0;

        foreach ($indexable as $storage) {
            $manager->sync($storage);
            $synced++;
        }

        return $synced;
    }

    /**
     * What was applied, as applied — the second of the three inputs a later merge needs.
     *
     * @param  array{created: list<string>, adopted: list<string>, skipped: list<string>}  $outcome
     * @return array<string, mixed>
     */
    private static function manifestOf(BlueprintDefinition $definition, array $outcome): array
    {
        return [
            'version' => $definition->version(),
            'entry_types' => array_map(
                static fn (EntryTypeDeclaration $type): array => [
                    'handle' => $type->handle,
                    'name' => $type->name,
                    'plural_name' => $type->pluralName,
                    'fields' => array_map(
                        static fn (FieldDeclaration $field): array => [
                            'handle' => $field->handle,
                            'type' => $field->type,
                            'label' => $field->label,
                            'pii_class' => $field->piiClass,
                            'cardinality' => $field->cardinality,
                            'is_indexed' => $field->isIndexed,
                            'settings' => $field->settings,
                        ],
                        $type->fields,
                    ),
                ],
                $definition->entryTypes(),
            ),
            'outcome' => $outcome,
        ];
    }

    /**
     * @return array{handle: string, version: string, created: list<string>, adopted: list<string>, skipped: list<string>, indexed: int}
     */
    private static function nothingToDo(BlueprintDefinition $definition): array
    {
        return [
            'handle' => $definition->handle(),
            'version' => $definition->version(),
            'created' => [],
            'adopted' => [],
            'skipped' => ['already applied at this version'],
            'indexed' => 0,
        ];
    }
}
