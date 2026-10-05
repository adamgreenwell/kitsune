<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\Types\RelationType;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\RolePermission;
use Kitsune\Core\Schema\DesiredStorage;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Schema\StorageAdoption;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Throwable;

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
 * ~~or undo~~ or reverse. `applied_at` null means an apply started and did not finish.
 *
 * ⚠️ ROWS IN A TRANSACTION, GENERATED COLUMNS AFTER IT. That is the admin's own field flow, scaled up: the
 * row is the source of truth and the schema follows it, so `is_indexed` is written inside and `sync()` runs
 * outside, with `kitsune:schema-sync` as the documented repair for the pair coming apart.
 *
 * ⚠️ THE MANIFEST COMMITS WITH THE ROWS (ADR-039, roles and Blog as built). Written last inside the transaction,
 * so the receipt is always in one of three states, and each says what the next run does:
 * - `manifest` null, `applied_at` null — no row of any version committed: the next run applies afresh, at its own
 *   version;
 * - `manifest` set, `applied_at` null — the rows committed and the finish did not run: the next run finishes the
 *   version the receipt records, whichever version it is itself, rather than refusing its own types;
 * - `applied_at` set — done: the same version is a no-op, and a different one is ~~refused before anything is
 *   written, because ADR-039's additive merge is not built~~ merged (ADR-039, the merge as built): it adds what that
 *   version declares and the manifest does not record, and refuses — before anything is written — any change to or
 *   removal of what the manifest records. Applying over it as a fresh apply stranded the org: the intent record
 *   cleared the manifest, then the default policy refused the types the first version had created.
 *
 * ⚠️ AN APPLY WRITES NO `role_user` ROW, AND NO GRANT ON A ROLE IT DID NOT CREATE. A role a blueprint declares is
 * created with no holders, and one already there is either refused or left exactly as it is.
 */
final class BlueprintApplier
{
    /**
     * An entry type or role handle: the admin's own type-handle shape (`EntryTypeResource`), so a handle never needs
     * escaping where it is shown — and so `*`, which the grant grammar reads as every type, is never a type's handle.
     */
    private const HANDLE = '/^[a-z][a-z0-9_]*$/';

    /**
     * The keys of `typeRecord()`, `fieldRecord()` and `roleRecord()`, which a manifest must hold for a merge to read it.
     */
    private const TYPE_RECORD = ['name', 'plural_name', 'icon', 'description', 'ordering', 'on_collision', 'is_media'];

    /**
     * Type-record keys the format gained after receipts had been written without them, each with the one value every such
     * receipt meant — ADR-039, the DAM as built.
     *
     * ⚠️ A FACT ABOUT THE CORE THAT WROTE THE MANIFEST, NOT A GUESS. A manifest whose entry types hold no `is_media` was
     * written by a core whose `EntryTypeDeclaration` could not declare a media type, so every type it records was declared
     * ordinary. Read as `false`, it compares equal to a later version still declaring the type ordinary and refuses one
     * declaring it media — exactly as the key would have, had it been there. Only these keys are read this way; a row
     * missing any other key is not a manifest any core wrote, and is refused as before. A key joins this list only in the
     * change that adds it to `TYPE_RECORD`, with the value every earlier writer could only have meant.
     *
     * ⚠️ AND ONLY EVER COMPARED. No merge, finish or reverse reads a recorded `is_media` in order to write, so a manifest
     * tampered with here can cause a refusal and never a write.
     */
    private const ADDED_TO_TYPE_RECORD = ['is_media' => false];

    private const FIELD_RECORD = ['type', 'label', 'pii_class', 'cardinality', 'is_indexed', 'settings', 'is_required', 'help_text', 'ordering', 'group'];

    private const ROLE_RECORD = ['name', 'on_collision', 'grants'];

    /** The field keys whose change reshapes storage — what a lock forbids (`FieldStorage::saving()`, ADR-006). */
    private const RESHAPING = ['type', 'cardinality', 'settings'];

    /**
     * @return array{handle: string, version: string, created: list<string>, adopted: list<string>, skipped: list<string>, indexed: int, roles_created: list<string>}
     *
     * @throws InvalidArgumentException for a malformed definition, before anything is read or written
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

        /*
         * What the definition says of itself — its handles, field types, roles and grants — refused before the
         * receipt, so a definition wrong in those ways leaves no receipt and no rows, and asks the database nothing.
         */
        self::refuseMalformed($definition);

        $receipt = Blueprint::receiptFor($definition->handle());

        if ($receipt !== null && $receipt->applied_at !== null) {
            if ($receipt->version === $definition->version()) {
                return self::nothingToDo($definition);
            }

            /*
             * ⚠️ ANOTHER VERSION IS MERGED, ~~REFUSED~~, AND NEVER APPLIED AFRESH. An apply over the old version cleared
             * the manifest and then refused, under the default policy, the very types that version had created; the
             * merge reads the manifest instead, and adds only what this version declares and it does not record.
             */
            return self::merge($definition, $receipt, $orgId);
        }

        /*
         * The rows committed and the finish did not run: finish the version they are, rather than refuse what this
         * blueprint made — at any version, because Blog's moves with core's, and an org whose receipt only an older
         * core could finish would be stranded by the upgrade.
         */
        if ($receipt !== null && $receipt->manifest !== null) {
            return self::finishInterrupted($definition, $receipt, $orgId);
        }

        /* With no manifest, no row of any version committed: this run applies afresh, and the receipt takes its version. */

        /*
         * The intent record, committed on its own. An interrupted apply leaves this row with `applied_at`
         * null, which is what a later run recognises — and what makes "a blueprint half applied into this org"
         * a thing an operator can be told rather than a thing they have to notice.
         *
         * ⚠️ ONE ALREADY HERE ALREADY SAYS THAT, SO IT IS NOT SAVED HERE: it takes this run's version inside the rows'
         * transaction, under its lock, with the manifest. Saved here, a run that had read an intent record while another
         * apply committed over it wrote its version onto the other's receipt — 1.1.0 over a finished 1.0.0 manifest —
         * before its own re-read stopped it (found by review, ADR-039's reverse as built).
         */
        if ($receipt === null) {
            $receipt = new Blueprint(['handle' => $definition->handle()]);
            $receipt->version = $definition->version();
            $receipt->applied_at = null;
            $receipt->manifest = null;

            /* A receipt that appeared since it was read is another apply's, begun at the same moment. */
            try {
                $receipt->save();
            } catch (UniqueConstraintViolationException $e) {
                throw new RuntimeException(self::concurrent($definition), 0, $e);
            }
        }

        $outcome = ['created' => [], 'adopted' => [], 'skipped' => []];
        $indexable = [];
        $rows = ['entry_types' => [], 'roles' => []];

        /*
         * ⚠️ On the MODEL's connection rather than the `DB` facade's, which always resolves the default one.
         * `Site::save()` and `SettingsWriter` were both corrected from that, and `ModuleLifecycle` after them.
         */
        try {
            $receipt->getConnection()->transaction(function () use ($definition, $orgId, $receipt, &$outcome, &$indexable, &$rows): void {
                /*
                 * ⚠️ THE INTENT RECORD AGAIN, FOR UPDATE, BEFORE ANY ROW. It committed on its own, so a reverse could
                 * delete it before this transaction opened — and the rows then committed with no receipt naming them,
                 * printed "Applied", and the next apply refused its own types (ADR-039, the reverse as built). Gone is
                 * a reverse's doing; recording rows, or a version other than the one read, is another apply's.
                 */
                $fresh = Blueprint::query()->whereKey($receipt->getKey())->lockForUpdate()->first();

                if ($fresh === null) {
                    throw new RuntimeException(self::receiptRemoved($definition->handle(), rowsCommitted: false));
                }

                if ($fresh->manifest !== null || $fresh->applied_at !== null || $fresh->version !== $receipt->version) {
                    throw new RuntimeException(self::concurrent($definition));
                }

                /* Written with the manifest, last: an intent record that rolls back keeps the version it had. */
                $receipt->version = $definition->version();

                foreach ($definition->entryTypes() as $declaration) {
                    self::applyEntryType($declaration, $orgId, $outcome, $indexable, $rows);
                }

                /*
                 * After every type, so a role's grants name types that exist — and only types this apply created: one
                 * adopted under Skip is the operator's.
                 */
                $grantable = array_keys(array_filter(
                    $rows['entry_types'],
                    static fn (array $row): bool => $row['outcome'] === 'created',
                ));

                foreach ($definition->roles() as $declaration) {
                    self::applyRole($declaration, $grantable, $outcome, $rows);
                }

                /*
                 * ⚠️ LAST, AND INSIDE. The manifest commits with the rows or not at all, so "manifest set, applied_at
                 * null" means exactly "rows committed, the finish did not run" — the state `finishInterrupted()`
                 * completes, where before a re-run refused its own types.
                 */
                $receipt->manifest = self::manifestOf($definition, $outcome, $rows);
                $receipt->save();
            });
        } catch (Throwable $e) {
            /*
             * ⚠️ A RECEIPT THAT NOW RECORDS ROWS IS NOT THIS RUN'S. This run's manifest rolled back with its rows, so
             * a manifest there now is another apply's, which ran at the same moment — and this run's refusal of
             * "its own" types, or its raw constraint error, would be telling the operator something false.
             */
            try {
                self::discardLeftOpen($receipt->getConnection());
                $fresh = Blueprint::query()->whereKey($receipt->getKey())->first();
            } catch (Throwable) {
                throw $e;
            }

            /*
             * ⚠️ AND ONE THAT IS GONE IS A REVERSE'S: the intent record committed, and nothing but a reverse deletes a
             * receipt. On SQLite in WAL mode a reverse can delete it after this run's re-read, and this run's first write
             * then fails busy on its stale snapshot — a lock error that would otherwise read as itself.
             */
            if ($fresh === null) {
                throw new RuntimeException(self::receiptRemoved($definition->handle(), rowsCommitted: false), 0, $e);
            }

            if ($fresh->manifest !== null || $fresh->applied_at !== null) {
                throw new RuntimeException(self::concurrent($definition), 0, $e);
            }

            throw $e;
        }

        /*
         * ⚠️ AFTER THE TRANSACTION, AND FAILING HERE IS NOT A FAILED APPLY. `is_indexed` is a row that says
         * what the operator wants; the generated column is DDL that cannot join the transaction on two of the
         * four engines. The rows are committed and correct either way, and `kitsune:schema-sync` is the
         * documented repair — which is exactly what the admin does when a field saves but cannot be indexed.
         */
        $indexed = self::syncIndexes($indexable);

        self::finishReceipt($receipt, $definition->handle());

        return [
            'handle' => $definition->handle(),
            'version' => $definition->version(),
            'created' => $outcome['created'],
            'adopted' => $outcome['adopted'],
            'skipped' => $outcome['skipped'],
            'indexed' => $indexed,
            'roles_created' => array_keys(array_filter(
                $rows['roles'],
                static fn (array $row): bool => $row['outcome'] === 'created',
            )),
        ];
    }

    /**
     * Refuse what a definition says of itself that is wrong, before anything is read or written — ADR-039, roles as
     * built.
     *
     * ⚠️ NO QUERY. Whether a type or a role is already in the org is the apply's question, answered under the
     * collision policy; this answers only what the definition says of itself — its type handles, its fields'
     * types, its roles and their grants — so a definition wrong in those ways leaves no receipt claiming an apply
     * began. What the models refuse at save (a field handle, a classification outside the vocabulary the
     * declaration's own type already narrows for PHPStan, a field type's settings) still fails inside the rows
     * transaction, leaving the receipt that says no rows were written — which the next run, at a corrected version
     * or not, applies afresh.
     *
     * ⚠️ PUBLIC, FOR THE COMMAND, which asks it before `FirstOrg` writes the first org: a definition knowable as
     * wrong with no query must not leave an org behind on an empty installation either.
     *
     * @throws InvalidArgumentException
     */
    public static function refuseMalformed(BlueprintDefinition $definition): void
    {
        $handle = $definition->handle();
        $types = [];

        foreach ($definition->entryTypes() as $type) {
            /*
             * ⚠️ THE ADMIN'S SHAPE, NOT THE GRANT GRAMMAR'S. The grammar admits `*` as a type segment, because it is
             * the wildcard — so a type handled `*` passed, and every per-type grant on it in the admin was the
             * wildcard. A handle in this shape is one a grant can name, and names that type alone.
             */
            if (preg_match(self::HANDLE, $type->handle) !== 1 || strlen($type->handle) > 255) {
                throw new InvalidArgumentException(sprintf(
                    'Blueprint [%s] declares entry type [%s]: an entry type handle is lowercase snake_case — a letter, '
                    .'then letters, digits and underscores — at most 255 characters, the shape the admin gives one.',
                    $handle,
                    $type->handle,
                ));
            }

            if (in_array($type->handle, EntryType::RESERVED_HANDLES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Blueprint [%s] declares entry type [%s], a handle the admin\'s own routes use: %s.',
                    $handle,
                    $type->handle,
                    implode(', ', EntryType::RESERVED_HANDLES),
                ));
            }

            if (isset($types[$type->handle])) {
                throw new InvalidArgumentException("Blueprint [{$handle}] declares entry type [{$type->handle}] twice.");
            }

            $types[$type->handle] = true;
            $fields = [];

            foreach ($type->fields as $field) {
                /* A type no registry knows was stored without complaint, and failed closed only where it was shown. */
                if (! app(FieldTypeRegistry::class)->has($field->type)) {
                    throw new InvalidArgumentException(sprintf(
                        'Blueprint [%s] declares field [%s] on [%s] of type [%s], which no field type is registered as.',
                        $handle,
                        $field->handle,
                        $type->handle,
                        $field->type,
                    ));
                }

                /*
                 * ⚠️ BEFORE THE RECEIPT, AND NOT LEFT TO THE ROWS. The second declaration failed only inside the rows
                 * transaction, as one of StorageAdoption's refusals — and a manifest keyed by handle records one of the
                 * two, so a merge could not tell which of them a later version changed.
                 */
                if (isset($fields[$field->handle])) {
                    throw new InvalidArgumentException(
                        "Blueprint [{$handle}] declares field [{$field->handle}] on [{$type->handle}] twice."
                    );
                }

                $fields[$field->handle] = true;
            }
        }

        $declared = array_keys($types);
        $roles = [];

        foreach ($definition->roles() as $role) {
            if (preg_match(self::HANDLE, $role->handle) !== 1 || strlen($role->handle) > 255) {
                throw new InvalidArgumentException(sprintf(
                    'Blueprint [%s] declares role [%s]: a role handle is lowercase snake_case — a letter, then '
                    .'letters, digits and underscores — at most 255 characters.',
                    $handle,
                    $role->handle,
                ));
            }

            if (isset($roles[$role->handle])) {
                throw new InvalidArgumentException("Blueprint [{$handle}] declares role [{$role->handle}] twice.");
            }

            $roles[$role->handle] = true;

            if (trim($role->name) === '' || mb_strlen($role->name) > 255) {
                throw new InvalidArgumentException(
                    "Blueprint [{$handle}] declares role [{$role->handle}] with no name, or one longer than 255 characters."
                );
            }

            if ($role->grants === []) {
                throw new InvalidArgumentException(sprintf(
                    'Blueprint [%s] declares role [%s] with no grants: a role that grants nothing is a name '
                    .'implying authority Kitsune does not gate.',
                    $handle,
                    $role->handle,
                ));
            }

            foreach ($role->grants as $type => $actions) {
                self::refuseGrantKey($handle, $role, $type, $declared);
                self::refuseActions($handle, $role, (string) $type, $actions);
            }

            /* Belt and braces: every string the role would hold is one the registry recognises. */
            foreach (self::permissionsOf($role) as $permission) {
                try {
                    Permissions::validated($permission);
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException(
                        "Blueprint [{$handle}] declares role [{$role->handle}]: {$e->getMessage()}",
                        0,
                        $e,
                    );
                }
            }
        }
    }

    /**
     * @param  list<string>  $declared  the entry type handles this definition declares
     *
     * @throws InvalidArgumentException
     */
    private static function refuseGrantKey(string $handle, RoleDeclaration $role, mixed $type, array $declared): void
    {
        if (! is_string($type)) {
            throw new InvalidArgumentException(sprintf(
                'Blueprint [%s] declares role [%s] with a grant keyed [%s]: grants are keyed by entry type handle.',
                $handle,
                $role->handle,
                (string) $type,
            ));
        }

        if ($type === Permissions::ANY_TYPE) {
            throw new InvalidArgumentException(sprintf(
                'Blueprint [%s] declares role [%s] with a grant on every type: a blueprint never grants the '
                .'wildcard — an owner writes it (ADR-033).',
                $handle,
                $role->handle,
            ));
        }

        /* The same words whether or not such a type exists: the rule is what this definition declares. */
        if (! in_array($type, $declared, true)) {
            throw new InvalidArgumentException(sprintf(
                'Blueprint [%s] declares role [%s] with a grant on [%s], which is not a type this blueprint '
                .'declares. A blueprint grants only on what it declares (ADR-039); authority over somebody '
                .'else\'s type is the operator\'s to give. It declares: %s.',
                $handle,
                $role->handle,
                $type,
                $declared === [] ? 'no entry types' : implode(', ', $declared),
            ));
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function refuseActions(string $handle, RoleDeclaration $role, string $type, mixed $actions): void
    {
        if (! is_array($actions) || $actions === [] || ! array_is_list($actions)) {
            throw new InvalidArgumentException(sprintf(
                'Blueprint [%s] declares role [%s] with no actions, or not a list of them, on [%s]. The actions '
                .'are %s.',
                $handle,
                $role->handle,
                $type,
                implode(', ', Permissions::ACTIONS),
            ));
        }

        foreach ($actions as $action) {
            if (! is_string($action) || ! in_array($action, Permissions::ACTIONS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Blueprint [%s] declares role [%s] with the action [%s] on [%s], which is not one of %s (ADR-033).',
                    $handle,
                    $role->handle,
                    is_string($action) ? $action : get_debug_type($action),
                    $type,
                    implode(', ', Permissions::ACTIONS),
                ));
            }
        }

        if (count(array_unique($actions)) !== count($actions)) {
            throw new InvalidArgumentException(
                "Blueprint [{$handle}] declares role [{$role->handle}] with an action on [{$type}] twice."
            );
        }
    }

    /**
     * The permission strings a role declaration holds, derived rather than written by its author.
     *
     * @return list<string>
     */
    private static function permissionsOf(RoleDeclaration $role): array
    {
        $permissions = [];

        foreach ($role->grants as $type => $actions) {
            foreach ($actions as $action) {
                $permissions[] = Permissions::forEntryType((string) $type, $action);
            }
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }

    private static function concurrent(BlueprintDefinition $definition): string
    {
        return sprintf(
            'The apply of [%s] stopped, and this organisation\'s receipt for it now records rows this run did not '
            .'commit: another apply of it ran at the same moment, or this run\'s own commit landed as it failed. '
            .'Re-run it — it finishes what the receipt records, merges over it, or does nothing if that is done.',
            $definition->handle(),
        );
    }

    /**
     * Merge a later version over a finished apply — ADR-039's additive merge, as built.
     *
     * ⚠️ A NEW VERSION IS THE OLD ONE PLUS ADDITIONS, AND NOTHING ELSE MERGES. The definition is compared with the
     * manifest before anything is written: any change to or removal of what the manifest records is refused, every
     * difference named, so what reaches the transaction can only add — new entry types, new fields on types this
     * blueprint created, new roles. No row an earlier version wrote is written, so every edit the operator made since
     * wins without being looked for, and an upgraded org ends with the rows a fresh apply of the new version writes.
     *
     * ⚠️ VERSION AND MANIFEST MOVE IN THE ROWS' TRANSACTION, WITH `applied_at` NULLED THERE. A merge that commits and
     * does not reach its index sync is then state 2 at the new version — rows and manifest committed, the finish owed
     * — which the next run completes, as it does an interrupted fresh apply.
     *
     * @return array{handle: string, version: string, created: list<string>, adopted: list<string>, skipped: list<string>, indexed: int, roles_created: list<string>}
     *
     * @throws RuntimeException
     */
    private static function merge(BlueprintDefinition $definition, Blueprint $receipt, int $orgId): array
    {
        $from = (string) $receipt->version;
        $to = $definition->version();
        $planned = $receipt->manifest;

        /* The plan: both refusals are thrown before the transaction opens, and neither writes. */
        $recorded = self::recordedOf($definition->handle(), $receipt);
        self::refuseChanges($definition, $recorded, $from, $orgId);

        $outcome = ['created' => [], 'adopted' => [], 'skipped' => []];
        $indexable = [];
        $notes = [];
        $rows = self::seededRows($recorded);
        $createdRoles = [];

        try {
            $receipt->getConnection()->transaction(function () use ($definition, $receipt, $orgId, $from, $to, $planned, $recorded, &$outcome, &$indexable, &$notes, &$rows, &$createdRoles): void {
                /*
                 * ⚠️ 1. THE RECEIPT AGAIN, FOR UPDATE, BEFORE ANYTHING. Two merges both planned from `$from`; without
                 * this the second would write its manifest over the first's — a version-only or grant-free merge
                 * meets no unique index to stop it. PostgreSQL, MySQL and MariaDB make the second wait here and then
                 * read the first's commit; SQLite fails its first write instead, and the catch names either.
                 */
                $fresh = Blueprint::query()->whereKey($receipt->getKey())->lockForUpdate()->first();

                if (self::receiptMoved($fresh, $from, $planned)) {
                    throw new RuntimeException(self::concurrent($definition));
                }

                /* 2. What each row the manifest records as created is now. Recorded types are locked, in id order. */
                $types = $recorded['entry_types'];
                uasort($types, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
                $typeState = [];
                $held = [];

                foreach ($types as $handle => $row) {
                    if ($row['outcome'] === 'created') {
                        [$typeState[$handle], $live] = self::recordedRow('entry_types', $row['id'], $handle, $orgId, lock: true);

                        if ($typeState[$handle] === 'holds' && $live instanceof EntryType) {
                            $held[$handle] = $live;
                        }
                    }
                }

                $roleState = [];
                $roleLive = [];

                foreach ($recorded['roles'] as $handle => $row) {
                    if ($row['outcome'] === 'created') {
                        [$roleState[$handle], $roleLive[$handle]] = self::recordedRow('roles', $row['id'], $handle, $orgId);
                    }
                }

                $foreign = [];

                foreach (['entry type' => [$recorded['entry_types'], $typeState], 'role' => [$recorded['roles'], $roleState]] as $kind => [$records, $states]) {
                    foreach ($records as $handle => $row) {
                        $state = $states[$handle] ?? null;

                        /* A type under another handle is not the admin's doing — it disables the handle on edit. */
                        if ($state === 'foreign' || ($state === 'renamed' && $kind === 'entry type')) {
                            $foreign[] = sprintf('%s id %s is not this organisation\'s %s', $kind, self::idOf($row['id']), $handle);
                        }
                    }
                }

                if ($foreign !== []) {
                    throw new RuntimeException(sprintf(
                        'The receipt for [%1$s] cannot be merged: %2$s. Its manifest is not this organisation\'s record '
                        .'of this blueprint\'s rows, so nothing was written and the receipt still says %3$s. '
                        .'`kitsune:blueprint reverse %1$s` clears it alone.',
                        $definition->handle(),
                        implode('; ', $foreign),
                        $from,
                    ));
                }

                /*
                 * ⚠️ 3. NOTHING LANDS ON WHAT THE OPERATOR REMOVED. A field added to a type they deleted would
                 * re-create it, and a grant on a handle nothing of this blueprint's holds any more reaches whatever
                 * type takes it next — the global one included.
                 */
                $removed = [];

                foreach ($definition->entryTypes() as $declaration) {
                    if (($typeState[$declaration->handle] ?? null) !== 'gone') {
                        continue;
                    }

                    foreach ($declaration->fields as $field) {
                        if (! isset($recorded['entry_types'][$declaration->handle]['fields'][$field->handle])) {
                            $removed[] = sprintf(
                                'it adds field %s to entry type %s, which this blueprint created and this organisation '
                                .'has since removed — a merge never re-creates what was removed',
                                $field->handle,
                                $declaration->handle,
                            );
                        }
                    }
                }

                /* The types an earlier version created that a new role may grant on: still this org's, and no global type's handle. */
                $recordedGrantable = array_keys(array_filter(
                    $held,
                    static fn (EntryType $type): bool => ! EntryType::query()->whereNull('org_id')->where('handle', $type->handle)->exists(),
                ));

                foreach ($definition->roles() as $declaration) {
                    if (isset($recorded['roles'][$declaration->handle]) || self::leavesRoleAsItIs($declaration)) {
                        continue;
                    }

                    foreach (array_keys($declaration->grants) as $type) {
                        $state = $typeState[$type] ?? null;

                        if ($state === 'gone') {
                            $removed[] = sprintf(
                                'role %s, new in %s, grants on %s, which this blueprint created and this organisation has '
                                .'since removed — a grant on that handle would reach whatever type takes it next',
                                $declaration->handle,
                                $to,
                                $type,
                            );
                        } elseif ($state === 'holds' && ! in_array($type, $recordedGrantable, true)) {
                            $removed[] = sprintf(
                                'role %s, new in %s, grants on %s, and a global type with that handle now exists — the '
                                .'grant would reach the global type\'s entries here too',
                                $declaration->handle,
                                $to,
                                $type,
                            );
                        }
                    }
                }

                if ($removed !== []) {
                    throw new RuntimeException(sprintf(
                        'Blueprint [%1$s] cannot be merged from %2$s to %3$s in this organisation: %4$s. A merge writes '
                        .'only onto what this blueprint created and this organisation still has as it was written. '
                        .'Nothing was written, and the receipt still says %2$s. To start afresh at %3$s instead, '
                        .'`kitsune:blueprint reverse %1$s` removes what this blueprint created while nothing holds data '
                        .'or authority for it, and clears the receipt — or refuses, naming what is in the way.',
                        $definition->handle(),
                        $from,
                        $to,
                        implode('; ', $removed),
                    ));
                }

                /* 4. What the operator changed that a merge leaves as it is, said rather than undone. */
                foreach ($recorded['entry_types'] as $handle => $row) {
                    if (($typeState[$handle] ?? null) === 'gone') {
                        $notes[] = "entry type {$handle}: removed since this blueprint wrote it; not written again";
                    }

                    $type = $held[$handle] ?? null;

                    foreach ($type === null ? [] : array_keys($row['fields']) as $field) {
                        $kept = Field::query()
                            ->where('entry_type_id', $type->getKey())
                            ->whereIn('field_storage_id', FieldStorage::query()->select('id')->where('org_id', $orgId)->where('handle', $field))
                            ->exists();

                        if (! $kept) {
                            $notes[] = "field {$field} on {$handle}: removed since this blueprint wrote it; not written again";
                        }
                    }
                }

                foreach (array_keys($recorded['roles']) as $handle) {
                    $state = $roleState[$handle] ?? null;
                    $live = $roleLive[$handle] ?? null;

                    if ($state === 'gone') {
                        $notes[] = "role {$handle}: removed since this blueprint wrote it; not written again";
                    } elseif ($state === 'renamed' && $live !== null) {
                        $notes[] = sprintf(
                            'role %s: renamed %s since this blueprint wrote it; left as it is',
                            $handle,
                            (string) $live->getAttribute('handle'),
                        );
                    }
                }

                /* 5. New types, under the collision policy exactly as a fresh apply meets it. */
                foreach ($definition->entryTypes() as $declaration) {
                    if (! isset($recorded['entry_types'][$declaration->handle])) {
                        self::applyEntryType($declaration, $orgId, $outcome, $indexable, $rows);
                    }
                }

                /* 6. New fields, on the types this blueprint created and this org still has — after 5, so a relation may target a new type. */
                foreach ($definition->entryTypes() as $declaration) {
                    $type = $held[$declaration->handle] ?? null;

                    foreach ($type === null ? [] : $declaration->fields as $field) {
                        if (! isset($recorded['entry_types'][$declaration->handle]['fields'][$field->handle])) {
                            $rows['entry_types'][$declaration->handle]['fields'][$field->handle] = self::applyField($field, $type, $orgId, $outcome, $indexable);
                        }
                    }
                }

                /*
                 * 7. New roles, granting on what this merge created and on what an earlier version created that this
                 * org still has. A recorded role is never written: whoever holds it would gain or lose with nobody
                 * choosing to (ADR-033).
                 */
                $grantable = [...$recordedGrantable, ...array_keys(array_filter(
                    array_diff_key($rows['entry_types'], $recorded['entry_types']),
                    static fn (array $row): bool => $row['outcome'] === 'created',
                ))];

                foreach ($definition->roles() as $declaration) {
                    if (isset($recorded['roles'][$declaration->handle])) {
                        continue;
                    }

                    self::applyRole($declaration, $grantable, $outcome, $rows);

                    if ($rows['roles'][$declaration->handle]['outcome'] === 'created') {
                        $createdRoles[] = $declaration->handle;
                    }
                }

                /*
                 * ⚠️ 8. LAST, AND INSIDE. The manifest is seeded from the recorded ids and outcomes, so it keeps every
                 * earlier row — the ones the operator removed included, which is what stops a later version re-adding
                 * them — and records what this version declares, as a fresh apply of it would.
                 */
                $receipt->version = $to;
                $receipt->manifest = self::manifestOf($definition, $outcome, $rows);
                $receipt->applied_at = null;
                $receipt->save();
            });
        } catch (Throwable $e) {
            /*
             * ⚠️ NAMED BY WHAT THE RECEIPT SAYS NOW, NOT BY WHAT WAS THROWN. A receipt that moved is another apply's;
             * a lock or a unique index lost with the receipt where it was is a write that reached the same rows at the
             * same moment; anything else is this run's own refusal, and reads as itself.
             */
            try {
                self::discardLeftOpen($receipt->getConnection());
                $fresh = Blueprint::query()->whereKey($receipt->getKey())->first();
            } catch (Throwable) {
                throw $e;
            }

            /* ⚠️ GONE IS A REVERSE, NOT AN APPLY: "rows this run did not commit" would be false of a receipt that is gone. */
            if ($fresh === null) {
                throw new RuntimeException(self::receiptRemoved($definition->handle(), rowsCommitted: false), 0, $e);
            }

            if (self::receiptMoved($fresh, $from, $planned)) {
                throw new RuntimeException(self::concurrent($definition), 0, $e);
            }

            if ($e instanceof UniqueConstraintViolationException || self::causedByContention($e)) {
                throw new RuntimeException(self::contended($definition, $from), 0, $e);
            }

            throw $e;
        }

        /* After the commit, as a fresh apply's: DDL cannot join the transaction on two engines of four. */
        $indexed = self::syncIndexes($indexable);

        self::finishReceipt($receipt, $definition->handle());

        return [
            'handle' => $definition->handle(),
            'version' => $to,
            'created' => $outcome['created'],
            'adopted' => $outcome['adopted'],
            'skipped' => [
                sprintf(
                    'version: merged over %1$s, which the receipt recorded — what %2$s adds was written, and nothing '
                    .'%1$s wrote was changed',
                    $from,
                    $to,
                ),
                ...$notes,
                ...$outcome['skipped'],
            ],
            'indexed' => $indexed,
            /* ⚠️ THIS RUN'S, NEVER THE SEEDED ROWS': those would report every role an earlier version created. */
            'roles_created' => $createdRoles,
        ];
    }

    /**
     * The manifest, checked and keyed by handle in manifest order — refused, every problem named, when a merge cannot
     * read it.
     *
     * ⚠️ CHECKED, NOT TRUSTED, AND BEFORE ANYTHING IS WRITTEN. `manifest` is one of the receipt's two columns a bulk
     * write is not refused for, and a merge decides from it which rows are this blueprint's.
     *
     * ⚠️ THE HANDLE, NOT THE DEFINITION: the definition was read for nothing else, and the reverse has none — it reads
     * the manifest alone, so it reaches a receipt whose blueprint is no longer registered (ADR-039, the reverse as built).
     *
     * @return array{
     *     entry_types: array<string, array{id: int|string, outcome: string, record: array<string, mixed>, fields: array<string, array{outcome: string, record: array<string, mixed>}>}>,
     *     roles: array<string, array{id: int|string|null, outcome: string, record: array<string, mixed>}>
     * }
     *
     * @throws RuntimeException
     */
    private static function recordedOf(string $handle, Blueprint $receipt): array
    {
        [$recorded, $problems] = self::readManifest($receipt);

        if ($problems !== []) {
            throw new RuntimeException(sprintf(
                'The receipt for [%1$s] records %2$s, but its manifest is not a record a merge can read: %3$s. Nothing '
                .'was written and the receipt is left as it is. `kitsune:blueprint reverse %1$s` clears a receipt like '
                .'this one, removing it and nothing else, because a manifest that is not this organisation\'s record '
                .'names no row a reverse may remove.',
                $handle,
                (string) $receipt->version,
                implode('; ', $problems),
            ));
        }

        return $recorded;
    }

    /**
     * The manifest checked and keyed by handle, with every problem found — collected, never thrown, so the merge can
     * refuse on them and the reverse can clear the receipt alone.
     *
     * @return array{
     *     0: array{
     *         entry_types: array<string, array{id: int|string, outcome: string, record: array<string, mixed>, fields: array<string, array{outcome: string, record: array<string, mixed>}>}>,
     *         roles: array<string, array{id: int|string|null, outcome: string, record: array<string, mixed>}>
     *     },
     *     1: list<string>
     * }
     */
    private static function readManifest(Blueprint $receipt): array
    {
        $manifest = $receipt->manifest;
        $problems = [];
        $recorded = ['entry_types' => [], 'roles' => []];

        if (! is_array($manifest) || $manifest === []) {
            $problems[] = 'it records nothing';
            $manifest = [];
        } elseif (($manifest['version'] ?? null) !== $receipt->version) {
            $problems[] = 'it records version '.(is_scalar($manifest['version'] ?? null) ? (string) $manifest['version'] : get_debug_type($manifest['version'] ?? null));
        }

        foreach (['entry type' => 'entry_types', 'role' => 'roles'] as $kind => $key) {
            $rows = $manifest[$key] ?? null;

            if ($manifest !== [] && (! is_array($rows) || ! array_is_list($rows))) {
                $problems[] = "its {$key} are not a list";

                continue;
            }

            foreach ((array) $rows as $row) {
                $checked = self::recordedRowOf($kind, '', $row, $problems);

                if ($checked === null) {
                    continue;
                }

                [$handle, $entry] = $checked;

                if (isset($recorded[$key][$handle])) {
                    $problems[] = "it records {$kind} {$handle} twice";

                    continue;
                }

                if ($key === 'entry_types') {
                    $entry['fields'] = [];
                    $fields = $row['fields'] ?? null;

                    if (! is_array($fields) || ! array_is_list($fields)) {
                        $problems[] = "its fields on {$handle} are not a list";
                    } else {
                        foreach ($fields as $field) {
                            $checkedField = self::recordedRowOf('field', " on {$handle}", $field, $problems);

                            if ($checkedField === null) {
                                continue;
                            }

                            if (isset($entry['fields'][$checkedField[0]])) {
                                $problems[] = "it records field {$checkedField[0]} on {$handle} twice";

                                continue;
                            }

                            $entry['fields'][$checkedField[0]] = $checkedField[1];
                        }
                    }
                }

                $recorded[$key][$handle] = $entry;
            }
        }

        /** @var array{entry_types: array<string, array{id: int|string, outcome: string, record: array<string, mixed>, fields: array<string, array{outcome: string, record: array<string, mixed>}>}>, roles: array<string, array{id: int|string|null, outcome: string, record: array<string, mixed>}>} $recorded */
        return [$recorded, $problems];
    }

    /**
     * One row of the manifest, checked: its handle and what a merge reads of it — or null, with the problem added.
     *
     * @param  'entry type'|'role'|'field'  $kind
     * @param  string  $where  ` on {type}` for a field, and empty otherwise
     * @param  list<string>  $problems
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private static function recordedRowOf(string $kind, string $where, mixed $row, array &$problems): ?array
    {
        if (! is_array($row) || ! is_string($row['handle'] ?? null)) {
            $problems[] = sprintf('%s %s it records%s has no handle', $kind === 'entry type' ? 'an' : 'a', $kind, $where);

            return null;
        }

        $handle = $row['handle'];

        /* `$added`: the keys a manifest written before the format had them may lack, and what each then meant. */
        [$keys, $outcomes, $added] = match ($kind) {
            'entry type' => [self::TYPE_RECORD, ['created', 'skipped'], self::ADDED_TO_TYPE_RECORD],
            'role' => [self::ROLE_RECORD, ['created', 'skipped'], []],
            'field' => [self::FIELD_RECORD, ['created', 'adopted'], []],
        };

        $missing = array_values(array_filter(
            [...($kind === 'field' ? [] : ['id']), 'outcome', ...$keys],
            static fn (string $key): bool => ! array_key_exists($key, $row) && ! array_key_exists($key, $added),
        ));

        if ($missing !== []) {
            $problems[] = sprintf('%s %s%s has no %s', $kind, $handle, $where, implode(', ', $missing));

            return null;
        }

        if (! in_array($row['outcome'], $outcomes, true)) {
            $problems[] = sprintf('%s %s%s has the outcome %s', $kind, $handle, $where, (string) json_encode($row['outcome'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return null;
        }

        /* Both a created and an adopted type record the type's id (A: applyEntryType); a skipped role records none. */
        if (($kind === 'entry type' || ($kind === 'role' && $row['outcome'] === 'created')) && ! self::integerLike($row['id'])) {
            $problems[] = "{$kind} {$handle} has no id";

            return null;
        }

        if ($kind === 'role' && (! is_array($row['grants']) || ! array_is_list($row['grants']) || array_filter($row['grants'], is_string(...)) !== $row['grants'])) {
            $problems[] = "role {$handle}'s grants are not a list";

            return null;
        }

        $record = [];

        /* `array_key_exists`, not `??`: a present null is what the manifest says, and compares as the change it is. */
        foreach ($keys as $key) {
            $record[$key] = array_key_exists($key, $row) ? $row[$key] : $added[$key];
        }

        return [$handle, $kind === 'field'
            ? ['outcome' => $row['outcome'], 'record' => $record]
            : ['id' => $row['id'], 'outcome' => $row['outcome'], 'record' => $record]];
    }

    /**
     * Refuse a definition that changes or removes anything the manifest records — every difference named, nothing
     * written.
     *
     * ⚠️ THE ONE RULE, AND WHY IT IS A REFUSAL RATHER THAN A WRITE. A merge that changed a recorded row would overwrite
     * whatever the operator made of it, and one that dropped a recorded grant would take authority from whoever holds
     * the role with nobody choosing to. Every rule here can be loosened later without undoing anything applied; none
     * could be tightened again once a merge had written past it.
     *
     * ⚠️ IT READS TWO THINGS: whether the storage a reshaped field names is locked, for the refusal's wording alone; and
     * whether a new role declared `Skip` meets one the org already has, which it would leave exactly as it is.
     *
     * @param  array{entry_types: array<string, array{id: int|string, outcome: string, record: array<string, mixed>, fields: array<string, array{outcome: string, record: array<string, mixed>}>}>, roles: array<string, array{id: int|string|null, outcome: string, record: array<string, mixed>}>}  $recorded
     *
     * @throws RuntimeException
     */
    private static function refuseChanges(BlueprintDefinition $definition, array $recorded, string $from, int $orgId): void
    {
        $to = $definition->version();
        $types = [];
        $roles = [];
        $items = [];

        foreach ($definition->entryTypes() as $type) {
            $types[$type->handle] = $type;
        }

        foreach ($definition->roles() as $role) {
            $roles[$role->handle] = $role;
        }

        foreach ($recorded['entry_types'] as $handle => $row) {
            $type = $types[$handle] ?? null;

            if ($type === null) {
                $items[] = "entry type {$handle} is no longer declared";

                continue;
            }

            $changed = self::changedKeys(self::typeRecord($type), $row['record']);

            if ($changed !== []) {
                $items[] = sprintf('entry type %s changes its %s', $handle, implode(', ', $changed));
            }

            $fields = [];

            foreach ($type->fields as $field) {
                $fields[$field->handle] = $field;
            }

            foreach ($row['fields'] as $handled => $field) {
                $declared = $fields[$handled] ?? null;

                if ($declared === null) {
                    $items[] = "field {$handled} on {$handle} is no longer declared";

                    continue;
                }

                $changed = self::changedKeys(self::fieldRecord($declared), $field['record']);

                if ($changed === []) {
                    continue;
                }

                $item = sprintf('field %s on %s changes its %s', $handled, $handle, implode(', ', $changed));

                /*
                 * ⚠️ READ FROM THE ROW, NOT THE DEFINITION, AND ONLY FOR THE WORDING. Storage is org-wide, so data
                 * written through another type locks it too; a lock armed after this read changes only the words.
                 */
                $reshapes = array_intersect($changed, self::RESHAPING) !== [];

                if ($reshapes && FieldStorage::query()->where('org_id', $orgId)->where('handle', $handled)->where('is_locked', true)->exists()) {
                    $item .= " — and {$handled} is locked because entries hold data for it: create a new field, migrate "
                        .'the data, verify, then remove the old one (ADR-006)';
                }

                $items[] = $item;
            }

            /* ⚠️ A TYPE ADOPTED UNDER SKIP IS THE OPERATOR'S: a fresh apply adds its fields, and a merge never does. */
            if ($row['outcome'] === 'skipped') {
                foreach ($type->fields as $field) {
                    if (! isset($row['fields'][$field->handle])) {
                        $items[] = sprintf(
                            'field %s is added to entry type %s, which this blueprint adopted (onCollision: skip) '
                            .'rather than created — a merge adds fields only to types this blueprint created',
                            $field->handle,
                            $handle,
                        );
                    }
                }
            }
        }

        foreach ($recorded['roles'] as $handle => $row) {
            $role = $roles[$handle] ?? null;

            if ($role === null) {
                $items[] = "role {$handle} is no longer declared";

                continue;
            }

            $declared = self::roleRecord($role);
            $changed = array_values(array_diff(self::changedKeys($declared, $row['record']), ['grants']));

            if ($changed !== []) {
                $items[] = sprintf('role %s changes its %s', $handle, implode(', ', $changed));
            }

            /** @var list<string> $held */
            $held = $row['record']['grants'];
            $gains = array_values(array_diff($declared['grants'], $held));
            $loses = array_values(array_diff($held, $declared['grants']));
            sort($gains);
            sort($loses);

            if ($gains !== []) {
                $items[] = sprintf(
                    'role %s gains %s — a merge never adds to a role an earlier version created, because whoever holds '
                    .'it would gain them with nobody choosing to (ADR-033); declare them on a new role',
                    $handle,
                    implode(', ', $gains),
                );
            }

            if ($loses !== []) {
                $items[] = sprintf('role %s loses %s — a merge never revokes', $handle, implode(', ', $loses));
            }
        }

        foreach ($roles as $handle => $role) {
            if (isset($recorded['roles'][$handle]) || self::leavesRoleAsItIs($role)) {
                continue;
            }

            foreach (array_keys($role->grants) as $type) {
                if (($recorded['entry_types'][$type]['outcome'] ?? null) === 'skipped') {
                    $items[] = sprintf(
                        'role %s, new in %s, grants on %s, which this blueprint adopted (onCollision: skip) rather than '
                        .'created — authority over that type is the operator\'s to give',
                        $handle,
                        $to,
                        (string) $type,
                    );
                }
            }
        }

        if ($items !== []) {
            throw new RuntimeException(sprintf(
                'Blueprint [%1$s] cannot be merged from %2$s to %3$s: a merge adds what %3$s declares and %2$s did not, '
                .'and never changes or removes what %2$s recorded — %4$s. Nothing was written, and the receipt still '
                .'says %2$s.',
                $definition->handle(),
                $from,
                $to,
                implode('; ', $items),
            ));
        }
    }

    /**
     * The keys of a declaration's record whose values differ from the manifest's, in record order.
     *
     * ⚠️ STRICTLY, `settings` WITH ITS OBJECTS' KEYS SORTED FIRST, AT ANY DEPTH. MySQL hands a JSON object back with its
     * keys re-sorted at every depth, so compared in order a select's options read from the manifest would refuse every
     * merge on one engine of four; the cost is that an options map only reordered is not a change. A list keeps its
     * order on every engine, and is compared in order. ~~Loosely, as `StorageAdoption` compares them~~ — but loosely,
     * `null == 0`, `null == ''` and `'1.1' == '1.10'` at any depth, so a minimum moved from none to 0 merged as no
     * change, left the org's storage as it was, and recorded the new value in the manifest, where no later merge could
     * see the difference again (found by review).
     *
     * ⚠️ A KEY THE RECORD DOES NOT HOLD IS A CHANGE, not a null: the projection and the keys a manifest is read with are
     * written twice, and a key missing from one must refuse every merge rather than compare as equal. The reader fills
     * only `ADDED_TO_TYPE_RECORD`'s keys, with the value their writer could only have meant, before this runs; every other
     * missing key is still a change.
     *
     * @param  array<string, mixed>  $declared
     * @param  array<string, mixed>  $recorded
     * @return list<string>
     */
    private static function changedKeys(array $declared, array $recorded): array
    {
        $changed = [];

        foreach ($declared as $key => $value) {
            $was = $recorded[$key] ?? null;

            if (! array_key_exists($key, $recorded) || ($key === 'settings' ? self::sortedKeys($value) !== self::sortedKeys($was) : $value !== $was)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /** A value with every object in it — every array that is not a list — sorted by key, at any depth. */
    private static function sortedKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortedKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The manifest's ids and outcomes as `$rows`, so the manifest a merge writes keeps every row an earlier version
     * wrote — under its id, removed or not.
     *
     * @param  array{entry_types: array<string, array{id: int|string, outcome: string, record: array<string, mixed>, fields: array<string, array{outcome: string, record: array<string, mixed>}>}>, roles: array<string, array{id: int|string|null, outcome: string, record: array<string, mixed>}>}  $recorded
     * @return array{entry_types: array<string, array<string, mixed>>, roles: array<string, array{id: int|string|null, outcome: string}>}
     */
    private static function seededRows(array $recorded): array
    {
        $rows = ['entry_types' => [], 'roles' => []];

        foreach ($recorded['entry_types'] as $handle => $row) {
            $rows['entry_types'][$handle] = [
                'id' => $row['id'],
                'outcome' => $row['outcome'],
                'fields' => array_map(static fn (array $field): string => $field['outcome'], $row['fields']),
            ];
        }

        foreach ($recorded['roles'] as $handle => $row) {
            $rows['roles'][$handle] = ['id' => $row['id'], 'outcome' => $row['outcome']];
        }

        return $rows;
    }

    /**
     * Whether a new role meets one the org already has under `Skip`, so is left exactly as it is and grants nothing.
     *
     * ⚠️ THEN ITS GRANTS ARE NOT REFUSED, AS A FRESH APPLY DOES NOT REFUSE THEM: `applyRole()` writes none of them. Read
     * through the scope, as `applyRole()` reads it; a role gone by the time it runs meets the grants check there.
     */
    private static function leavesRoleAsItIs(RoleDeclaration $declaration): bool
    {
        return $declaration->onCollision === OnCollision::Skip
            && Role::query()->where('handle', $declaration->handle)->exists();
    }

    /**
     * Whether the receipt is no longer the one a run planned from: gone, finished or not as it was, at another version,
     * or recording something else.
     *
     * ⚠️ THE MANIFEST STRICTLY. Both sides decode what the database stored, so `!==` is exact — and loosely, a
     * manifest at `'1.10'` equals one at `'1.1'`.
     *
     * @param  bool  $finished  whether the receipt planned from had `applied_at` set — always, for a merge; the reverse
     *                          plans from a receipt in any state
     */
    private static function receiptMoved(?Blueprint $fresh, string $from, mixed $planned, bool $finished = true): bool
    {
        return $fresh === null
            || ($fresh->applied_at !== null) !== $finished
            || $fresh->version !== $from
            || $fresh->manifest !== $planned;
    }

    /**
     * Roll back what a failed COMMIT left open on the connection, which Laravel no longer counts.
     *
     * ⚠️ SQLITE KEEPS A TRANSACTION WHOSE COMMIT FAILED BUSY, and Laravel's commit handler drops its count to 0 without
     * rolling it back — so the receipt re-read next saw this run's own uncommitted receipt and named it another apply's,
     * and the connection kept the write lock for whatever it ran next (found by review). Only at level 0: inside a
     * caller's own transaction, the open one is the caller's.
     */
    private static function discardLeftOpen(ConnectionInterface $connection): void
    {
        if ($connection instanceof Connection && $connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction()) {
            $connection->getPdo()->rollBack();
        }
    }

    /** A lock, deadlock or serialisation failure, by Laravel's own detector: the one bound, or its default. */
    private static function causedByContention(Throwable $e): bool
    {
        $detector = app()->bound(ConcurrencyErrorDetectorContract::class)
            ? app(ConcurrencyErrorDetectorContract::class)
            : new ConcurrencyErrorDetector;

        return $detector->causedByConcurrencyError($e);
    }

    private static function contended(BlueprintDefinition $definition, string $from): string
    {
        return sprintf(
            'The merge of [%1$s] from %2$s to %3$s stopped: another write reached the same rows at the same moment, and '
            .'nothing this run wrote was kept. Run it again — it merges over whatever the receipt then records, or does '
            .'nothing if that is done.',
            $definition->handle(),
            $from,
            $definition->version(),
        );
    }

    /**
     * `applied_at`, written only while the receipt is still the one this run committed — ADR-039, the reverse as built.
     *
     * ⚠️ A CONDITIONAL UPDATE, NOT A SAVE. A save to a row deleted underneath it writes nothing and returns true, so an
     * apply whose receipt a reverse removed after its commit printed "Applied" over rows already gone. By version, not
     * by `applied_at` null: two finishes of one owed receipt are harmless, and the second must not claim a removal.
     *
     * ⚠️ ZERO ROWS IS NOT YET "REMOVED". MySQL and MariaDB count rows changed, not rows matched, so a second finish in
     * the same second writes an identical value and reports 0 — the receipt is asked for before it is called gone, and
     * one still here at another version is said to have moved, not to have been reversed (found by review).
     *
     * @throws RuntimeException
     */
    private static function finishReceipt(Blueprint $receipt, string $handle): void
    {
        $at = now();
        $mine = static fn () => Blueprint::query()->whereKey($receipt->getKey())->where('version', (string) $receipt->version);

        if ($mine()->update(['applied_at' => $at]) !== 1 && ! $mine()->exists()) {
            $now = Blueprint::query()->whereKey($receipt->getKey())->value('version');

            throw new RuntimeException($now === null ? self::receiptRemoved($handle, rowsCommitted: true) : sprintf(
                'The apply of [%1$s] committed its rows at %2$s, and before this run could mark them finished another '
                .'run moved this organisation\'s receipt for it to %3$s. Nothing was reversed. `kitsune:blueprint status` '
                .'shows where this organisation stands.',
                $handle,
                (string) $receipt->version,
                is_scalar($now) ? (string) $now : '?',
            ));
        }

        $receipt->applied_at = $at;
        $receipt->syncOriginalAttribute('applied_at');
    }

    /** A receipt a reverse removed while an apply, a merge or a finish ran — before its rows committed, or after. */
    private static function receiptRemoved(string $handle, bool $rowsCommitted): string
    {
        return $rowsCommitted
            ? sprintf(
                'The apply of [%s] committed its rows, and its receipt was removed before this run could mark it '
                .'finished — `kitsune:blueprint reverse` reached it at the same moment and reversed them. '
                .'`kitsune:blueprint status` shows where this organisation stands, and `kitsune:schema-sync --force` '
                .'drops any generated column left for a field that no longer exists.',
                $handle,
            )
            : sprintf(
                'The apply of [%s] stopped and wrote nothing: this organisation\'s receipt for it was removed while it '
                .'ran — `kitsune:blueprint reverse` reached it at the same moment. Run it again to apply afresh.',
                $handle,
            );
    }

    /**
     * Finish an apply whose rows committed and whose finish did not run: index what it declared, and say so.
     *
     * ⚠️ THE VERSION THE RECEIPT RECORDS, WHICHEVER VERSION THIS DEFINITION IS. The finish reads only the manifest —
     * which fields to index, which rows to look for — so it needs nothing of the version that wrote them but the
     * record, and Blog's version moves with core's: an org whose rows committed under an older core is finished by a
     * newer one, at the old version, and the next run then merges the newer version over it ~~is then refused as any
     * other is~~.
     *
     * The merge's own interruption is this state too: it commits the new version and its manifest with its rows and
     * `applied_at` null, so a merge stopped in its index sync is finished here, at the version it merged to.
     *
     * ⚠️ THE MANIFEST IS CHECKED AGAINST THE DATABASE FIRST. It is one of the receipt's two columns a bulk write is
     * not refused for (`applied_at` is the other), so a manifest that is not this org's record of these rows is
     * refused rather than trusted into an `applied_at`:
     * - at the same version, it must record every type and role the definition declares, as created or skipped;
     * - a row it records as created must not be another org's, nor another type under its id. ~~Nor another role's~~:
     *   a role under another handle in this org is its owner's rename — the admin edits a role's handle, never a
     *   type's — so it is reported and the finish goes ahead, as the merge does.
     *
     * A row it records as created that is gone altogether is one the operator removed while the finish was owed —
     * which they may do after a finish at no cost — so it is reported and the finish goes ahead, rather than leaving
     * an org that no command can finish or clear (the reverse now clears one, but only by removing what it can prove
     * is this blueprint's).
     *
     * @return array{handle: string, version: string, created: list<string>, adopted: list<string>, skipped: list<string>, indexed: int, roles_created: list<string>}
     *
     * @throws RuntimeException
     */
    private static function finishInterrupted(BlueprintDefinition $definition, Blueprint $receipt, int $orgId): array
    {
        $manifest = (array) $receipt->manifest;
        $sameVersion = $receipt->version === $definition->version();
        $refused = [];
        $untrusted = false;
        $removed = [];
        $indexedHandles = [];

        foreach (['entry type' => 'entry_types', 'role' => 'roles'] as $kind => $key) {
            $recorded = [];

            foreach ((array) ($manifest[$key] ?? []) as $row) {
                if (is_array($row) && is_string($row['handle'] ?? null)) {
                    $recorded[$row['handle']] = $row;
                }
            }

            $declared = $key === 'entry_types'
                ? array_map(static fn (EntryTypeDeclaration $type): string => $type->handle, $definition->entryTypes())
                : array_map(static fn (RoleDeclaration $role): string => $role->handle, $definition->roles());

            /* Another version's declarations are not this definition's, so its own record is what is checked. */
            foreach ($sameVersion ? $declared : array_keys($recorded) as $handle) {
                $row = $recorded[$handle] ?? null;
                $outcome = $row['outcome'] ?? null;

                if (! in_array($outcome, ['created', 'skipped'], true)) {
                    $refused[] = "{$kind} {$handle} is not recorded";

                    continue;
                }

                if ($outcome === 'created') {
                    [$state, $live] = self::recordedRow($key, $row['id'] ?? null, $handle, $orgId);

                    /*
                     * ⚠️ A ROLE UNDER ANOTHER HANDLE IN THIS ORG IS ITS OWNER'S RENAME, ~~A FORGERY~~. The admin edits
                     * a role's handle and never a type's, and an interrupted merge records roles an earlier version
                     * created long before — so refusing the rename stranded the org at state 2. The finish writes
                     * nothing to roles, so it goes ahead and says so; a type under another handle is still refused.
                     */
                    if ($state === 'renamed' && $key === 'roles' && $live !== null) {
                        $removed[] = sprintf(
                            'role %s: renamed %s since this blueprint wrote it; left as it is',
                            $handle,
                            (string) $live->getAttribute('handle'),
                        );
                    } elseif ($state === 'foreign' || $state === 'renamed') {
                        $untrusted = true;
                        $refused[] = sprintf('%s id %s is not this organisation\'s %s', $kind, self::idOf($row['id'] ?? null), $handle);
                    } elseif ($state === 'gone') {
                        /*
                         * ⚠️ "SINCE THIS BLUEPRINT WROTE IT", ~~"SINCE THE INTERRUPTED APPLY WROTE IT"~~: an interrupted
                         * merge records rows an earlier version wrote, which the operator may have removed before the
                         * merge began — the words the merge's own notes use, true of either.
                         */
                        $removed[] = "{$kind} {$handle}: removed since this blueprint wrote it; not written again";
                    }
                }

                foreach ($key === 'entry_types' ? (array) ($row['fields'] ?? []) : [] as $field) {
                    if (is_array($field) && ($field['is_indexed'] ?? false) === true && is_string($field['handle'] ?? null)) {
                        $indexedHandles[] = $field['handle'];
                    }
                }
            }
        }

        if ($refused !== []) {
            /*
             * ⚠️ THE WAY OUT SAID AS THE REVERSE WILL TAKE IT. A row that is another org's, or a type under another
             * handle, or a manifest it cannot read, makes the reverse clear the receipt alone; a manifest that only
             * fails to record what this definition declares is one it trusts, and reverses in full (found by review).
             */
            $alone = $untrusted || self::readManifest($receipt)[1] !== [];

            throw new RuntimeException(sprintf(
                'The receipt for [%1$s] cannot be finished: %2$s. Its manifest is not this organisation\'s record of this '
                .'blueprint\'s rows, so nothing was written and the receipt is left as it is. %3$s',
                $definition->handle(),
                implode('; ', $refused),
                $alone
                    ? sprintf(
                        '`kitsune:blueprint reverse %s` clears a receipt like this one, removing it and nothing else, '
                        .'because a manifest that is not this organisation\'s record names no row a reverse may remove.',
                        $definition->handle(),
                    )
                    : sprintf(
                        '`kitsune:blueprint reverse %s` removes what this receipt records this blueprint created, while '
                        .'nothing holds data or authority for it, and clears the receipt — or refuses, naming what is in '
                        .'the way; a row it does not record is left as it is.',
                        $definition->handle(),
                    ),
            ));
        }

        $indexable = $indexedHandles === [] ? [] : FieldStorage::query()
            ->where('org_id', $orgId)
            ->whereIn('handle', array_values(array_unique($indexedHandles)))
            ->where('is_indexed', true)
            ->get()
            ->all();

        $indexed = self::syncIndexes(array_values($indexable));

        self::finishReceipt($receipt, $definition->handle());

        $notes = ['rows: written by an earlier run that stopped before it finished; finished now', ...$removed];

        if (! $sameVersion) {
            $notes[] = sprintf(
                'version: finished at %s, which the receipt records; this definition is %s — run it again to merge it '
                .'over %s',
                (string) $receipt->version,
                $definition->version(),
                (string) $receipt->version,
            );
        }

        return [
            'handle' => $definition->handle(),
            'version' => (string) $receipt->version,
            'created' => [],
            'adopted' => [],
            'skipped' => $notes,
            'indexed' => $indexed,
            'roles_created' => [],
        ];
    }

    /**
     * Reverse a blueprint out of the org in context — ADR-039's reverse, as built: a refusal that names what is in the
     * way, not a rollback.
     *
     * ⚠️ THE MANIFEST DECIDES EVERY ROW, AND ONLY WHILE IT IS STILL THIS ORG'S RECORD. A row goes only while it is still
     * the blueprint's — created by it, under the id and handle it recorded, with nothing this organisation added resting
     * on it. Content, a holder, an Owner flag or the operator's own addition refuses the whole reverse, every obstacle
     * named and counted, nothing written; what the organisation took over (a renamed role, a type adopted under Skip,
     * adopted storage) and storage whose lock says data existed are kept. A manifest that records no rows, or that
     * names a row this org does not hold as recorded, removes nothing: only the receipt goes.
     *
     * ⚠️ `ModuleLifecycle::uninstall()`'S SHAPE: refuse before destroying, remove the rows and delete the receipt in one
     * transaction, DDL last and outside — `dropIndex()`, reference-counted, never `sync()`, which re-creates the column
     * of a row it is handed. No option and no prompt, as that command has none.
     *
     * ⚠️ EVERY LOCK BEFORE THE FIRST PLAIN READ: receipt, the org, recorded types, candidate storage, recorded roles,
     * their holders. MySQL's REPEATABLE READ fixes its snapshot at the first plain read, so a holder counted after one
     * could predate a lock it waited on — and `Role::delete()`'s own locking read would then find them, delete the role,
     * and the reverse would have taken a role from somebody (`Role::assignTo()` records the hazard).
     *
     * @return array{
     *     handle: string, version: string, outcome: 'reversed'|'abandoned'|'receipt-only',
     *     removed: list<string>, kept: list<string>, gone: list<string>, notes: list<string>,
     *     problems: list<string>, dropped: int, undropped: list<string>
     * }
     *
     * @throws RuntimeException no org, no receipt, the refusal naming every obstacle, a receipt that moved, a write that
     *                          contended, or a model guard's own refusal wrapped — in every case with nothing written
     */
    public static function reverse(string $handle): array
    {
        $orgId = app(Context::class)->orgId();

        if ($orgId === null) {
            throw new RuntimeException(sprintf(
                'Cannot reverse [%s]: no organisation is in context, and a blueprint is reversed out of one (ADR-039). '
                .'Set it — app(Context::class)->setOrg(...) — first.',
                $handle,
            ));
        }

        $receipt = Blueprint::receiptFor($handle);

        if ($receipt === null) {
            throw new RuntimeException(self::notApplied($handle));
        }

        $locked = null;
        $own = null;

        /* ⚠️ On the MODEL's connection, as the apply's: the facade's is always the default one. */
        try {
            $result = $receipt->getConnection()->transaction(function () use ($handle, $orgId, $receipt, &$locked, &$own): array {
                /* ⚠️ 1. THE RECEIPT, FOR UPDATE, AND EVERY DECISION FROM WHAT THAT READ — not from the read before it. */
                $locked = Blueprint::query()->whereKey($receipt->getKey())->lockForUpdate()->first();

                if ($locked === null || self::receiptMoved($locked, (string) $receipt->version, $receipt->manifest, $receipt->applied_at !== null)) {
                    $own = new RuntimeException(self::reverseMoved($handle));

                    throw $own;
                }

                $version = (string) $locked->version;

                /* 2. No row of any version committed: none can be this blueprint's, so the receipt goes alone. */
                if ($locked->manifest === null && $locked->applied_at === null) {
                    self::refuseVetoed($locked->delete(), "the receipt for {$handle}");

                    return self::reverseOutcome('abandoned', $version);
                }

                /*
                 * 3. A manifest this org cannot trust removes nothing. One forged id means any outcome in it may be
                 * rewritten, so no path removes "the rows that verify" (ADR-039, the merge as built).
                 */
                [$recorded, $problems] = self::readManifest($locked);
                $held = null;

                if ($problems === []) {
                    [$held, $problems] = self::lockRecorded($recorded, $orgId);
                }

                if ($problems !== [] || $held === null) {
                    self::refuseVetoed($locked->delete(), "the receipt for {$handle}");

                    return self::reverseOutcome('receipt-only', $version, problems: $problems);
                }

                /* 4. Under every lock, the plan — plain reads only — and the refusal, before the first write. */
                $plan = self::reversePlan($handle, $recorded, $orgId, $held);

                if ($plan['refusals'] !== []) {
                    $own = new RuntimeException(self::reverseRefusal($handle, $version, $plan['refusals']));

                    throw $own;
                }

                /*
                 * 5. The rows, through the models' own guards — a second, independent check of the same rules — and the
                 * receipt last. A type's fields and availability go with it by cascade; its storage is then unreferenced.
                 */
                foreach ($plan['types'] as $type) {
                    /*
                     * ⚠️ THE NOMINATED SUBJECT FIRST, THROUGH THE MODEL. `subject_field_id` names one of the type's own
                     * fields, which cascade from it while the key nulls back onto the row being deleted — a cycle whose
                     * handling is the engine's, measured on SQLite alone. The nomination goes with the type either way,
                     * so it goes first and the cycle never runs (ADR-039, the DAM as built: the DAM invites one).
                     */
                    if ($type->subject_field_id !== null) {
                        $type->subject_field_id = null;
                        $type->save();
                    }

                    self::refuseVetoed($type->delete(), "entry type {$type->handle}");
                }

                foreach ($plan['storage'] as $storage) {
                    if (FieldStorage::query()->whereKey($storage->getKey())->where('org_id', $orgId)->delete() !== 1) {
                        throw new RuntimeException("field storage {$storage->handle} was not there to delete.");
                    }
                }

                /* ⚠️ EACH GRANT REVOKED, SO EACH IS RECORDED: the apply audited every one as `role.granted` (ADR-033). */
                foreach ($plan['roles'] as [$role, $grants]) {
                    foreach ($grants as $permission) {
                        $role->revoke($permission);
                    }

                    self::refuseVetoed($role->delete(), "role {$role->handle}");
                }

                self::refuseVetoed($locked->delete(), "the receipt for {$handle}");

                return self::reverseOutcome('reversed', $version, $plan);
            });
        } catch (Throwable $e) {
            if ($own !== null && $e === $own) {
                throw $e;
            }

            /*
             * ⚠️ NAMED BY WHAT THE RECEIPT SAYS NOW, as the merge's catch: moved is another run's; a lock or a unique
             * index lost with the receipt where it was is contention; anything else — a model guard's own refusal —
             * reads as itself, inside this command's words.
             */
            try {
                self::discardLeftOpen($receipt->getConnection());
                $now = Blueprint::query()->whereKey($receipt->getKey())->first();
            } catch (Throwable) {
                throw $e;
            }

            $planned = $locked ?? $receipt;

            if (self::receiptMoved($now, (string) $planned->version, $planned->manifest, $planned->applied_at !== null)) {
                throw new RuntimeException(self::reverseMoved($handle), 0, $e);
            }

            if ($e instanceof UniqueConstraintViolationException || self::causedByContention($e)) {
                throw new RuntimeException(self::reverseContended($handle), 0, $e);
            }

            throw new RuntimeException(self::reverseStopped($handle, $e->getMessage(), (string) $planned->version), 0, $e);
        }

        /* ⚠️ AFTER THE COMMIT, AND NEVER INSIDE IT: DDL commits implicitly on MySQL and MariaDB. */
        [$dropped, $undropped] = self::dropColumns($result['indexed']);

        return [
            'handle' => $handle,
            'version' => $result['version'],
            'outcome' => $result['outcome'],
            'removed' => $result['removed'],
            'kept' => $result['kept'],
            'gone' => $result['gone'],
            'notes' => $result['notes'],
            'problems' => $result['problems'],
            'dropped' => $dropped,
            'undropped' => $undropped,
        ];
    }

    /**
     * What a reverse transaction hands back.
     *
     * @param  'reversed'|'abandoned'|'receipt-only'  $outcome
     * @param  array{removed: list<string>, kept: list<string>, gone: list<string>, notes: list<string>, indexed: list<FieldStorage>}|null  $plan
     * @param  list<string>  $problems
     * @return array{outcome: 'reversed'|'abandoned'|'receipt-only', version: string, removed: list<string>, kept: list<string>, gone: list<string>, notes: list<string>, problems: list<string>, indexed: list<FieldStorage>}
     */
    private static function reverseOutcome(string $outcome, string $version, ?array $plan = null, array $problems = []): array
    {
        return [
            'outcome' => $outcome,
            'version' => $version,
            'removed' => $plan['removed'] ?? [],
            'kept' => $plan['kept'] ?? [],
            'gone' => $plan['gone'] ?? [],
            'notes' => $problems === []
                ? ($plan['notes'] ?? [])
                : ['manifest: not this organisation\'s record of what this blueprint wrote — '.implode('; ', $problems)],
            'problems' => $problems,
            'indexed' => $plan['indexed'] ?? [],
        ];
    }

    /**
     * A delete a `deleting` listener vetoed, refused as the reverse's own — so the grants it revoked and every row it
     * deleted roll back with it, and nothing reports a row removed that is still there (found by review: `Role::delete()`
     * documents the veto, and a reverse that ignored it revoked a role's grants, kept the role and printed "removed").
     *
     * @throws RuntimeException
     */
    private static function refuseVetoed(?bool $deleted, string $what): void
    {
        if ($deleted === false) {
            throw new RuntimeException("{$what} was not deleted: a listener on its deleting event refused it.");
        }
    }

    /**
     * Lock every row the manifest names, in the documented order, and say what each is now — or the problems that make
     * the manifest one this org cannot trust.
     *
     * ⚠️ THE ORDER IS RECEIPT → ORG → TYPES (BY ID) → CANDIDATE STORAGE (BY ID) → ROLES (BY ID) → HOLDERS, and it agrees
     * with every other path: the merge (receipt, then types), `Role::delete()` and `save()` (org, then role),
     * `assignTo()` (role), and every insert into an org-scoped table, whose foreign-key checks take the org row's share
     * lock before the type's or the storage row's — an entry's save, a relation's. ~~Org after storage~~: with the org
     * last, an entry saved into a type the reverse had locked held the org's share lock and waited on the type, while
     * the reverse held the type and waited on the org — a deadlock on PostgreSQL, MySQL and MariaDB (found by review).
     * Storage is locked so the admin cannot attach a field to a row the reverse is about to delete; holders by a locking
     * `pluck`, because PostgreSQL refuses `FOR UPDATE` with an aggregate. SQLite compiles every one of these away and
     * serialises writers instead.
     *
     * @param  array{entry_types: array<string, array{id: int|string, outcome: string, record: array<string, mixed>, fields: array<string, array{outcome: string, record: array<string, mixed>}>}>, roles: array<string, array{id: int|string|null, outcome: string, record: array<string, mixed>}>}  $recorded
     * @return array{0: array{types: array<string, array{0: 'holds'|'gone', 1: ?EntryType}>, storage: array<string, FieldStorage>, roles: array<string, array{0: 'holds'|'gone'|'renamed', 1: ?Role, 2: int}>}|null, 1: list<string>}
     */
    private static function lockRecorded(array $recorded, int $orgId): array
    {
        $byId = static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id'];
        $problems = [];

        /* `Role::lockSharedOrgRow()`'s statement, first, as `Role::delete()` and `save()` take it before a role. */
        Org::query()->withoutGlobalScopes()->whereKey($orgId)->lockForUpdate()->value('id');

        $types = $recorded['entry_types'];
        uasort($types, $byId);
        $typeStates = [];

        foreach ($types as $handle => $row) {
            $typeStates[$handle] = self::recordedRow('entry_types', $row['id'], $handle, $orgId, lock: true);
        }

        $held = ['types' => [], 'storage' => [], 'roles' => []];

        /* In manifest order. A type under another handle is not the admin's doing — it disables the handle on edit. */
        foreach ($recorded['entry_types'] as $handle => $row) {
            [$state, $live] = $typeStates[$handle];

            if ($state === 'foreign' || $state === 'renamed') {
                $problems[] = sprintf('entry type id %s is not this organisation\'s %s', self::idOf($row['id']), $handle);
            } else {
                $held['types'][$handle] = [$state, $live instanceof EntryType ? $live : null];
            }
        }

        if ($problems !== []) {
            return [null, $problems];
        }

        [$candidates] = self::storageHandles($recorded);

        if ($candidates !== []) {
            /** @var array<string, FieldStorage> $storage */
            $storage = FieldStorage::query()
                ->where('org_id', $orgId)
                ->whereIn('handle', $candidates)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('handle')
                ->all();

            $held['storage'] = $storage;
        }

        $roles = array_filter($recorded['roles'], static fn (array $row): bool => $row['outcome'] === 'created');
        uasort($roles, $byId);
        $roleStates = [];

        foreach ($roles as $handle => $row) {
            [$state, $live] = self::recordedRow('roles', $row['id'], $handle, $orgId, lock: true);

            $roleStates[$handle] = [$state, $state === 'holds'
                ? Role::query()->whereKey($row['id'])->lockForUpdate()->firstOrFail()
                : ($live instanceof Role ? $live : null)];
        }

        foreach ($recorded['roles'] as $handle => $row) {
            if (! isset($roleStates[$handle])) {
                continue;
            }

            [$state, $role] = $roleStates[$handle];

            if ($state === 'foreign') {
                $problems[] = sprintf('role id %s is not this organisation\'s %s', self::idOf($row['id']), $handle);

                continue;
            }

            /* Counted in PHP: PostgreSQL refuses `FOR UPDATE` with an aggregate. */
            $holders = $state === 'holds' && $role !== null
                ? count(DB::table('role_user')->where('role_id', $role->getKey())->lockForUpdate()->pluck('user_id')->all())
                : 0;

            $held['roles'][$handle] = [$state, $role, $holders];
        }

        return $problems === [] ? [$held, []] : [null, $problems];
    }

    /**
     * The storage handles a manifest names: those any field record says this blueprint created — the candidates a
     * reverse may remove — and those every record says it adopted, each in manifest order.
     *
     * @param  array{entry_types: array<string, array{id: int|string, outcome: string, record: array<string, mixed>, fields: array<string, array{outcome: string, record: array<string, mixed>}>}>, roles: array<string, array{id: int|string|null, outcome: string, record: array<string, mixed>}>}  $recorded
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function storageHandles(array $recorded): array
    {
        $named = [];
        $created = [];

        foreach ($recorded['entry_types'] as $row) {
            foreach ($row['fields'] as $handle => $field) {
                $named[$handle] = true;

                if ($field['outcome'] === 'created') {
                    $created[$handle] = true;
                }
            }
        }

        return [array_keys($created), array_keys(array_diff_key($named, $created))];
    }

    /**
     * Under the locks the reverse took: count every obstacle and decide every row. Plain reads only, and no write.
     *
     * ⚠️ ENTRIES PAST EVERY SCOPE, TRASHED INCLUDED, BY A TYPE ID THIS ORG HOLDS. `withoutGlobalScopes()` counts the trash
     * without being asked and cannot be defeated by a callback that builds its own query, as `withoutScopeBecause()`
     * can (`BlueprintReverseTest` pins the six counts) — and an entry planted in another org's site still blocks:
     * it fails closed.
     *
     * @param  array{entry_types: array<string, array{id: int|string, outcome: string, record: array<string, mixed>, fields: array<string, array{outcome: string, record: array<string, mixed>}>}>, roles: array<string, array{id: int|string|null, outcome: string, record: array<string, mixed>}>}  $recorded
     * @param  array{types: array<string, array{0: 'holds'|'gone', 1: ?EntryType}>, storage: array<string, FieldStorage>, roles: array<string, array{0: 'holds'|'gone'|'renamed', 1: ?Role, 2: int}>}  $held
     * @return array{refusals: list<string>, types: list<EntryType>, storage: list<FieldStorage>, roles: list<array{0: Role, 1: list<string>}>, indexed: list<FieldStorage>, removed: list<string>, kept: list<string>, gone: list<string>, notes: list<string>}
     */
    private static function reversePlan(string $blueprint, array $recorded, int $orgId, array $held): array
    {
        $refusals = ['created' => [], 'skipped' => [], 'roles' => []];
        $plan = ['types' => [], 'storage' => [], 'roles' => [], 'indexed' => []];
        $lines = ['types' => ['removed' => [], 'kept' => [], 'gone' => []], 'storage' => ['removed' => [], 'kept' => [], 'gone' => []], 'roles' => ['removed' => [], 'kept' => [], 'gone' => []]];
        $removingTypes = [];
        /** @var array<string, string> $freed a handle this blueprint created and no type of it holds after this run, and why */
        $freed = [];

        foreach ($recorded['entry_types'] as $handle => $row) {
            [$state, $type] = $held['types'][$handle];

            if ($state === 'gone' || $type === null) {
                if ($row['outcome'] === 'created') {
                    $lines['types']['gone'][] = "entry type {$handle}: removed since this blueprint wrote it";
                    $freed[$handle] = 'this organisation has removed';
                }

                continue;
            }

            /* What is attached to it now: this org's rows under the handles recorded for it, and anything else. */
            [$ours, $theirs] = FieldStorage::query()
                ->whereIn('id', Field::query()->select('field_storage_id')->where('entry_type_id', $type->getKey()))
                ->orderBy('handle')
                ->get(['id', 'org_id', 'handle'])
                ->partition(static fn (FieldStorage $storage): bool => (int) $storage->getAttribute('org_id') === $orgId
                    && isset($row['fields'][$storage->handle]));

            $attached = array_values(array_intersect(array_keys($row['fields']), $ours->pluck('handle')->all()));

            if ($row['outcome'] === 'skipped') {
                if ($attached === []) {
                    $lines['types']['kept'][] = "entry type {$handle}: this organisation's before this blueprint adopted it (onCollision: skip)";
                } else {
                    $refusals['skipped'][] = sprintf(
                        'entry type %1$s was this organisation\'s before this blueprint adopted it (onCollision: skip), and '
                        .'a reverse removes nothing from a type it did not create — remove field%2$s %3$s from it in the '
                        .'admin first',
                        $handle,
                        count($attached) === 1 ? '' : 's',
                        implode(', ', $attached),
                    );
                }

                continue;
            }

            $items = [];
            $entries = Entry::query()->withoutGlobalScopes()->where('entry_type_id', $type->getKey());
            $total = (clone $entries)->count();

            if ($total > 0) {
                $trashed = (clone $entries)->whereNotNull('deleted_at')->count();

                $items[] = sprintf('entry type %1$s still has %2$d entr%3$s', $handle, $total, $total === 1 ? 'y' : 'ies').match (true) {
                    $trashed === 0 => sprintf(' — delete %s, then empty the trash with Delete forever', $total === 1 ? 'it' : 'them'),
                    $trashed < $total => sprintf(', %d of them in the trash — delete the rest, then empty the trash with Delete forever', $trashed),
                    default => ' in the trash — empty the trash with Delete forever',
                };
            }

            /* Revisions Delete forever will not take with their entry: those of entries since moved to another type. */
            $stray = EntryRevision::query()->withoutGlobalScopes()
                ->where('entry_type_id', $type->getKey())
                ->whereNotIn('entry_id', Entry::query()->withoutGlobalScopes()->select('id')->where('entry_type_id', $type->getKey()))
                ->count();

            if ($stray > 0) {
                $items[] = sprintf(
                    'entry type %1$s: %2$d revision%3$s of entries since moved to another type still record%4$s it — '
                    .'nothing in Kitsune deletes a revision (ADR-020), and they go only when those entries are deleted '
                    .'forever',
                    $handle,
                    $stray,
                    $stray === 1 ? '' : 's',
                    $stray === 1 ? 's' : '',
                );
            }

            if ($theirs->isNotEmpty()) {
                $items[] = sprintf(
                    'entry type %1$s carries field%2$s %3$s, which this blueprint did not write — remove %4$s from %1$s '
                    .'in the admin first',
                    $handle,
                    $theirs->count() === 1 ? '' : 's',
                    $theirs->pluck('handle')->implode(', '),
                    $theirs->count() === 1 ? 'it' : 'them',
                );
            }

            if ($items !== []) {
                $refusals['created'] = [...$refusals['created'], ...$items];

                continue;
            }

            $plan['types'][] = $type;
            $removingTypes[] = $type->getKey();
            $freed[$handle] = 'this reverse removes';
            $lines['types']['removed'][] = $attached === []
                ? "entry type {$handle}"
                : sprintf('entry type %s, with field%s %s', $handle, count($attached) === 1 ? '' : 's', implode(', ', $attached));
        }

        /*
         * ⚠️ STORAGE NEVER REFUSES: keeping a row destroys nothing, and the next apply adopts an identical one. It goes
         * only while nothing uses it but the types going now, no relation row names it, and no lock says data existed —
         * the lock is the record ADR-006 keeps, and the row is what erasure finds promoted and relational values by.
         */
        [$candidates, $adopted] = self::storageHandles($recorded);
        $removingStorage = [];

        foreach ($candidates as $handle) {
            $storage = $held['storage'][$handle] ?? null;

            if ($storage === null) {
                $lines['storage']['gone'][] = "field storage {$handle}: removed since this blueprint wrote it";

                continue;
            }

            $users = EntryType::query()
                ->whereIn('id', Field::query()->select('entry_type_id')->where('field_storage_id', $storage->getKey())->whereNotIn('entry_type_id', $removingTypes))
                ->orderBy('handle')
                ->pluck('handle')
                ->all();

            if ($users !== []) {
                $lines['storage']['kept'][] = sprintf(
                    'field storage %1$s: entry type%2$s %3$s use%4$s it',
                    $handle,
                    count($users) === 1 ? '' : 's',
                    implode(', ', $users),
                    count($users) === 1 ? 's' : '',
                );

                continue;
            }

            $relations = EntryRelation::query()->withoutGlobalScopes()->where('field_storage_id', $storage->getKey())->count();

            if ($relations > 0) {
                $lines['storage']['kept'][] = sprintf(
                    'field storage %1$s: %2$d relation row%3$s still point%4$s at it',
                    $handle,
                    $relations,
                    $relations === 1 ? '' : 's',
                    $relations === 1 ? 's' : '',
                );

                continue;
            }

            if ($storage->is_locked) {
                /*
                 * ⚠️ "ADOPTS IT AS IT IS" ONLY WHILE IT IS AS DECLARED. A lock forbids a change of shape, not of indexing,
                 * classification or settings, and `StorageAdoption` refuses a row that differs in any of the three — so the
                 * line names the difference rather than promise an adoption the next apply refuses (found by review).
                 */
                $drift = self::storageDrift($storage, $recorded, $handle);

                $lines['storage']['kept'][] = $drift === []
                    ? "field storage {$handle}: locked, because entries once held data for it (ADR-006) — a later apply adopts it as it is"
                    : sprintf(
                        'field storage %1$s: locked, because entries once held data for it (ADR-006) — but its %2$s no '
                        .'longer match%3$s what this blueprint declared, so a later apply declaring it the same way refuses '
                        .'it, naming the difference',
                        $handle,
                        implode(' and ', $drift),
                        count($drift) === 1 ? 'es' : '',
                    );

                continue;
            }

            $plan['storage'][] = $storage;
            $removingStorage[] = $storage->getKey();
            $lines['storage']['removed'][] = "field storage {$handle}";

            /* ⚠️ THE ROW'S FLAG, NOT THE MANIFEST'S: the operator may have indexed it in the admin since. */
            if ($storage->is_indexed) {
                $plan['indexed'][] = $storage;
            }
        }

        /* Kept, and said only of a row still here: one removed since is nobody's to keep (found by review). */
        foreach ($adopted as $handle) {
            if (! FieldStorage::query()->where('org_id', $orgId)->where('handle', $handle)->exists()) {
                continue;
            }

            $lines['storage']['kept'][] = "field storage {$handle}: adopted, not created, by this blueprint";
        }

        $removingRoles = [];

        foreach ($recorded['roles'] as $handle => $row) {
            if ($row['outcome'] === 'skipped') {
                if (Role::query()->where('handle', $handle)->exists()) {
                    $lines['roles']['kept'][] = "role {$handle}: this organisation's before this blueprint was applied (onCollision: skip)";
                }

                continue;
            }

            [$state, $role, $holders] = $held['roles'][$handle];

            if ($state === 'gone' || $role === null) {
                $lines['roles']['gone'][] = "role {$handle}: removed since this blueprint wrote it";

                continue;
            }

            /* ⚠️ A RENAMED ROLE IS ITS OWNER'S, as the merge and the finish already treat it. */
            if ($state === 'renamed') {
                $lines['roles']['kept'][] = sprintf(
                    'role %1$s: renamed %2$s since this blueprint wrote it, so it is this organisation\'s; left as it is, '
                    .'with its grants',
                    $handle,
                    (string) $role->getAttribute('handle'),
                );

                continue;
            }

            $items = [];

            /* ⚠️ NEVER TAKEN FROM ANYBODY: unassigning is an owner's decision under Roles, audited as theirs. */
            if ($holders > 0) {
                $items[] = sprintf(
                    'role %1$s is held by %2$d account%3$s — an owner unassigns it under Roles first, which is audited '
                    .'(ADR-033)',
                    $handle,
                    $holders,
                    $holders === 1 ? '' : 's',
                );
            }

            if ((bool) $role->getRawOriginal('is_owner')) {
                $items[] = sprintf(
                    'role %s has Owner turned on, and no blueprint creates an owner role — turn it off under Roles first, '
                    .'or rename the role to keep it as your own',
                    $handle,
                );
            }

            /** @var list<string> $live */
            $live = $role->permissions()->orderBy('permission')->pluck('permission')->all();
            /** @var list<string> $granted */
            $granted = $row['record']['grants'];
            $extra = array_values(array_diff($live, $granted));

            if ($extra !== []) {
                $items[] = sprintf(
                    'role %1$s holds %2$s, which this blueprint did not grant — revoke %3$s under Roles first, or rename '
                    .'the role to keep it as your own',
                    $handle,
                    implode(', ', $extra),
                    count($extra) === 1 ? 'it' : 'them',
                );
            }

            if ($items !== []) {
                $refusals['roles'] = [...$refusals['roles'], ...$items];

                continue;
            }

            $plan['roles'][] = [$role, $live];
            $removingRoles[] = $role->getKey();
            $lines['roles']['removed'][] = $live === []
                ? "role {$handle}, which held no grant"
                : sprintf('role %s, revoking its %d grant%s', $handle, count($live), count($live) === 1 ? '' : 's');
        }

        return [
            'refusals' => [...$refusals['created'], ...$refusals['skipped'], ...$refusals['roles']],
            ...$plan,
            'removed' => [...$lines['types']['removed'], ...$lines['storage']['removed'], ...$lines['roles']['removed']],
            'kept' => [...$lines['types']['kept'], ...$lines['storage']['kept'], ...$lines['roles']['kept']],
            'gone' => [...$lines['types']['gone'], ...$lines['storage']['gone'], ...$lines['roles']['gone']],
            'notes' => self::freedNotes($blueprint, $freed, $orgId, $removingTypes, $removingStorage, $removingRoles),
        ];
    }

    /**
     * What of a kept storage row no longer matches the field this manifest records the blueprint creating it with — in
     * `StorageAdoption::refuseDivergentDefinition()`'s words and by its comparisons, settings loosely.
     *
     * @param  array{entry_types: array<string, array{id: int|string, outcome: string, record: array<string, mixed>, fields: array<string, array{outcome: string, record: array<string, mixed>}>}>, roles: array<string, array{id: int|string|null, outcome: string, record: array<string, mixed>}>}  $recorded
     * @return list<string>
     */
    private static function storageDrift(FieldStorage $storage, array $recorded, string $handle): array
    {
        foreach ($recorded['entry_types'] as $row) {
            $field = $row['fields'][$handle] ?? null;

            if ($field === null || $field['outcome'] !== 'created') {
                continue;
            }

            $declared = $field['record'];
            $drift = [];

            if ((string) $storage->pii_class !== $declared['pii_class']) {
                $drift[] = 'privacy classification';
            }

            if ($storage->is_indexed !== (bool) $declared['is_indexed']) {
                $drift[] = 'indexing';
            }

            if (($declared['settings'] ?? []) != ($storage->settings ?? [])) {
                $drift[] = 'settings';
            }

            return $drift;
        }

        return [];
    }

    /**
     * What still names a handle no type will hold after this run — said, never changed.
     *
     * ⚠️ A GRANT ON A ROLE THE REVERSE KEEPS IS THE OPERATOR'S AUTHORITY (Adam, 2026-10-05): it stays, and the note says
     * it reaches whatever type takes the handle next — a re-apply included, which is often what is wanted. A relation's
     * `targetTypes` is configuration, not authority, and is reported the same way. Matched by exact strings, never
     * `LIKE`: `_` in a handle is a `LIKE` wildcard.
     *
     * @param  array<string, string>  $freed
     * @param  list<int|string>  $removingTypes
     * @param  list<int|string>  $removingStorage
     * @param  list<int|string>  $removingRoles
     * @return list<string>
     */
    private static function freedNotes(string $blueprint, array $freed, int $orgId, array $removingTypes, array $removingStorage, array $removingRoles): array
    {
        /* A handle a type in this org or a global type holds after this run is live, and nothing names a freed one. */
        $freed = array_filter($freed, static fn (string $handle): bool => ! EntryType::query()
            ->where('handle', $handle)
            ->where(static fn ($query) => $query->where('org_id', $orgId)->orWhereNull('org_id'))
            ->whereNotIn('id', $removingTypes)
            ->exists(), ARRAY_FILTER_USE_KEY);

        if ($freed === []) {
            return [];
        }

        ksort($freed);
        $strings = [];

        foreach (array_keys($freed) as $handle) {
            foreach (Permissions::ACTIONS as $action) {
                $strings[Permissions::forEntryType($handle, $action)] = $handle;
            }
        }

        $notes = [];
        /* Through the org scope: the org is in context, and these are its roles. */
        $roles = Role::query()->whereNotIn('id', $removingRoles)->orderBy('handle')->pluck('handle', 'id');
        $grants = RolePermission::query()
            ->whereIn('role_id', $roles->keys()->all())
            ->whereIn('permission', array_keys($strings))
            ->orderBy('permission')
            ->get(['role_id', 'permission']);

        foreach ($roles as $id => $role) {
            $held = [];

            foreach ($grants as $grant) {
                if ((int) $grant->getAttribute('role_id') === (int) $id) {
                    $permission = (string) $grant->getAttribute('permission');
                    $held[$strings[$permission]][] = $permission;
                }
            }

            ksort($held);

            foreach ($held as $handle => $permissions) {
                $one = count($permissions) === 1;

                $notes[] = sprintf(
                    'role %1$s holds %2$s on %3$s, which %4$s: %5$s, and %6$s whatever type takes %3$s next — a later '
                    .'apply of %7$s included',
                    $role,
                    implode(', ', $permissions),
                    $handle,
                    $freed[$handle],
                    $one ? 'it stays' : 'they stay',
                    $one ? 'reaches' : 'reach',
                    $blueprint,
                );
            }
        }

        $relations = FieldStorage::query()
            ->where('org_id', $orgId)
            ->where('type', RelationType::handle())
            ->whereNotIn('id', $removingStorage)
            ->orderBy('handle')
            ->get();

        foreach ($relations as $storage) {
            $targets = ($storage->settings ?? [])['targetTypes'] ?? [];

            foreach (is_array($targets) ? array_keys($freed) : [] as $handle) {
                if (in_array($handle, $targets, true)) {
                    $notes[] = sprintf(
                        'field storage %1$s targets %2$s, which %3$s: it stays, and targets whatever type takes %2$s next',
                        $storage->handle,
                        $handle,
                        $freed[$handle],
                    );
                }
            }
        }

        return $notes;
    }

    /**
     * The generated column of each removed storage row that was indexed — after the commit, and each on its own.
     *
     * ⚠️ `dropIndex()`, NEVER `sync()`: sync indexes the instance it is handed when its `is_indexed` is true, so on a
     * deleted row it RE-CREATES the column. `dropIndex()` is reference-counted, so a column another org's row still
     * projects to stays. A type that projects to nothing never had a column, and `dropIndex()` would throw for it.
     *
     * ⚠️ A FAILURE IS COLLECTED, NOT THROWN: the rows and the receipt are gone by now, and an orphan column holds no data.
     * `kitsune:schema-sync --force` drops it, as does the next apply that indexes anything.
     *
     * @param  list<FieldStorage>  $removed
     * @return array{0: int, 1: list<string>}
     */
    private static function dropColumns(array $removed): array
    {
        $dropped = 0;
        $failed = [];

        foreach ($removed as $storage) {
            try {
                if (app(FieldTypeRegistry::class)->get($storage->type)->projection(new FieldConfig($storage)) === null) {
                    continue;
                }

                app(SchemaManager::class)->dropIndex($storage);
                $dropped++;
            } catch (Throwable $e) {
                $failed[] = "{$storage->handle}: {$e->getMessage()}";
            }
        }

        return [$dropped, $failed];
    }

    /** @param list<string> $items */
    private static function reverseRefusal(string $handle, string $version, array $items): string
    {
        return sprintf(
            'Blueprint [%1$s] cannot be reversed in this organisation: %2$s. A reverse removes only what this blueprint '
            .'created, and only while nothing holds data or authority for it and nothing this organisation added rests on '
            .'it — it never deletes content and never takes a role from anybody (ADR-039). Nothing was written, and the '
            .'receipt still says %3$s.',
            $handle,
            implode('; ', $items),
            $version,
        );
    }

    private static function notApplied(string $handle): string
    {
        return sprintf(
            'Blueprint [%s] has not been applied in this organisation, so there is no receipt to reverse. Nothing was '
            .'written. `kitsune:blueprint status` lists every organisation\'s receipts; if an earlier reverse of it stopped '
            .'after its commit, its rows are already gone, and `kitsune:schema-sync --force` drops any generated column it '
            .'left.',
            $handle,
        );
    }

    private static function reverseMoved(string $handle): string
    {
        return sprintf(
            'The reverse of [%s] stopped: this organisation\'s receipt for it changed while it ran — an apply, a merge or '
            .'another reverse reached it at the same moment. Run it again; it reverses whatever the receipt then records.',
            $handle,
        );
    }

    private static function reverseContended(string $handle): string
    {
        return sprintf(
            'The reverse of [%s] stopped: another write reached the same rows at the same moment, and nothing this run '
            .'wrote was kept. Run it again.',
            $handle,
        );
    }

    private static function reverseStopped(string $handle, string $why, string $version): string
    {
        return sprintf(
            'The reverse of [%1$s] stopped: %2$s Nothing was written, and the receipt still says %3$s.',
            $handle,
            $why,
            $version,
        );
    }

    /**
     * What the row a manifest records under an id is now: this org's under that handle, gone, this org's under another
     * handle, or not this org's at all — with the row itself when it is this org's.
     *
     * ⚠️ PAST THE SCOPE FOR A ROLE, AND ONLY TO TELL "ANOTHER ORG'S" FROM "GONE". Through the scope the two read the
     * same, and they mean opposite things: one is a manifest that is not this org's, the other a row its operator
     * removed. A row of another org's is never returned, and nothing read here is written.
     *
     * ⚠️ `$lock` TAKES THE ROW FOR UPDATE, so a type a merge is about to add fields to cannot be deleted under it on
     * PostgreSQL, MySQL and MariaDB. SQLite compiles it away and serialises writers instead, so no SQLite test can see
     * it go (ADR-039, the merge as built).
     *
     * @return array{0: 'holds'|'gone'|'renamed'|'foreign', 1: EntryType|Role|null}
     */
    private static function recordedRow(string $key, mixed $id, string $handle, int $orgId, bool $lock = false): array
    {
        if (! self::integerLike($id)) {
            return ['foreign', null];
        }

        $query = $key === 'entry_types'
            ? EntryType::query()->whereKey($id)
            : Role::query()->withoutGlobalScopes()->whereKey($id)->select(['id', 'org_id', 'handle']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            return ['gone', null];
        }

        if ((int) $row->getAttribute('org_id') !== $orgId) {
            return ['foreign', null];
        }

        return $row->getAttribute('handle') === $handle ? ['holds', $row] : ['renamed', $row];
    }

    private static function integerLike(mixed $id): bool
    {
        return is_int($id) || (is_string($id) && ctype_digit($id));
    }

    /** An id as a message names it: as recorded when it is a scalar, and `?` when there is none. */
    private static function idOf(mixed $id): string
    {
        return is_scalar($id) ? (string) $id : '?';
    }

    /**
     * Create a role this blueprint declares, with exactly the grants it declares — ADR-039's second key.
     *
     * ⚠️ THE LOOKUP IS SCOPED, NEVER PAST IT. A role with the same handle in another org is not a collision, and
     * reading past the scope would refuse — or under Skip, leave alone — another org's role. On MySQL and MariaDB
     * the default collation matches an operator's `Blog_Editor` too, which agrees with the unique index, so the
     * outcome is the named refusal rather than a raw constraint error.
     *
     * @param  list<string>  $grantable  the entry type handles a grant may name: those this apply created — and, for a
     *                                   role a merge creates, those an earlier version created that this org still has
     * @param  array{created: list<string>, adopted: list<string>, skipped: list<string>}  $outcome
     * @param  array{entry_types: array<string, array<string, mixed>>, roles: array<string, array{id: int|string|null, outcome: string}>}  $rows
     */
    private static function applyRole(RoleDeclaration $declaration, array $grantable, array &$outcome, array &$rows): void
    {
        $existing = Role::query()->where('handle', $declaration->handle)->first();

        if ($existing !== null && $declaration->onCollision === OnCollision::Fail) {
            throw new RuntimeException(sprintf(
                'Role [%s] already exists in this organisation, and this blueprint declares it with onCollision: '
                .'fail — so it will not take over a role it did not create. Adding its grants would change what '
                .'everyone holding it may do, an authority change no person made. Rename or remove that role, or '
                .'declare it onCollision: skip to leave it exactly as it is.',
                $declaration->handle,
            ));
        }

        /* ⚠️ NOTHING IS WRITTEN TO A ROLE SOMEBODY ELSE DEFINED — not its grants, not its holders, not its flag. */
        if ($existing !== null) {
            $outcome['skipped'][] = "role {$declaration->handle} (already defined here; left as it is — its grants were not added)";
            $rows['roles'][$declaration->handle] = ['id' => null, 'outcome' => 'skipped'];

            return;
        }

        /*
         * ⚠️ ONLY ON A TYPE THIS APPLY CREATED, OR — FOR A ROLE A MERGE CREATES — ONE AN EARLIER VERSION CREATED THAT
         * THIS ORG STILL HAS. One adopted under Skip is the operator's — its entries theirs, its authority theirs to
         * give — so a grant on it is refused, and the whole apply with it, rather than handed to whoever an owner
         * later assigns this role to, believing it the blueprint's.
         */
        foreach (array_keys($declaration->grants) as $type) {
            if (! in_array($type, $grantable, true)) {
                throw new RuntimeException(sprintf(
                    'Role [%s] grants on [%s], which this apply adopted rather than created (onCollision: skip): that '
                    .'type is the operator\'s, and authority over it is theirs to give (ADR-039). Nothing was written. '
                    .'Drop the grant, or create the type rather than adopting it.',
                    $declaration->handle,
                    (string) $type,
                ));
            }
        }

        /* `EnforcesScope` stamps the org in context; `is_owner` takes its column default. Neither is passed. */
        $role = Role::create(['handle' => $declaration->handle, 'name' => $declaration->name]);
        $permissions = self::permissionsOf($declaration);

        foreach ($permissions as $permission) {
            $role->grant($permission);
        }

        $outcome['created'][] = sprintf('role %s: %s', $declaration->handle, implode(', ', $permissions));
        $rows['roles'][$declaration->handle] = ['id' => $role->id, 'outcome' => 'created'];
    }

    /**
     * @param  array{created: list<string>, adopted: list<string>, skipped: list<string>}  $outcome
     * @param  list<FieldStorage>  $indexable
     * @param  array{entry_types: array<string, array<string, mixed>>, roles: array<string, array{id: int|string|null, outcome: string}>}  $rows
     */
    private static function applyEntryType(
        EntryTypeDeclaration $declaration,
        int $orgId,
        array &$outcome,
        array &$indexable,
        array &$rows,
    ): void {
        /*
         * ⚠️ A GLOBAL TYPE IS NOBODY'S TO SHADOW, WHATEVER THE POLICY SAYS. Every org has it, a blueprint can neither
         * own it (ADR-039) nor add fields to it, and grants are matched on the handle — so a type of the same handle
         * here would take its place at `/c/{handle}`, and every grant this blueprint makes on it would reach the
         * global type's entries in this org too: authority over rows the blueprint never brought.
         */
        if (EntryType::query()->whereNull('org_id')->where('handle', $declaration->handle)->exists()) {
            throw new RuntimeException(sprintf(
                'Entry type [%s] is a global type, which every organisation has, and this blueprint declares one with '
                .'that handle. A blueprint can neither own a global type nor adopt one, whatever its onCollision says: '
                .'its own would stand in the global one\'s place here, and every grant on [%s] would reach the global '
                .'type\'s entries in this organisation too. Nothing was written. Give the type another handle.',
                $declaration->handle,
                $declaration->handle,
            ));
        }

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

        /*
         * ⚠️ ADOPTED ONLY ON THE SAME SIDE OF THE MEDIA LINE — ADR-042 decision 1. The flag is fixed when a type is created,
         * so a type adopted with the other flag stays that way for good: a declared media type with no Upload, or a
         * declared ordinary type the blueprint's fields would describe as files. Neither can be repaired, so it is refused
         * here, before the first field is added — and the merge reaches this for a type a later version adds.
         */
        if ($type !== null && $type->is_media !== $declaration->isMedia) {
            throw new RuntimeException(sprintf(
                'Entry type [%1$s] already exists in this organisation as %2$s, and this blueprint declares it as %3$s, '
                .'with onCollision: skip. Whether a type holds uploaded files is decided when it is created and never '
                .'changed (ADR-042), so it cannot be adopted as declared. Nothing was written. Give the declared type '
                .'another handle, or remove the one that is there.',
                $declaration->handle,
                $type->is_media ? 'a media type' : 'a type that holds no files',
                $declaration->isMedia ? 'a media type' : 'one that holds no files',
            ));
        }

        $created = $type === null;

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
                /* ⚠️ AT CREATION AND ONLY HERE — ADR-042 decision 1. `guardMediaFlag()` refuses it on every later save. */
                'is_media' => $declaration->isMedia,
            ]);

            $outcome['created'][] = "entry type {$declaration->handle}";
        }

        $fields = [];

        foreach ($declaration->fields as $field) {
            $fields[$field->handle] = self::applyField($field, $type, $orgId, $outcome, $indexable);
        }

        $rows['entry_types'][$declaration->handle] = [
            'id' => $type->getKey(),
            'outcome' => $created ? 'created' : 'skipped',
            'fields' => $fields,
        ];
    }

    /**
     * @param  array{created: list<string>, adopted: list<string>, skipped: list<string>}  $outcome
     * @param  list<FieldStorage>  $indexable
     * @return 'created'|'adopted' what became of its storage
     */
    private static function applyField(
        FieldDeclaration $declaration,
        EntryType $type,
        int $orgId,
        array &$outcome,
        array &$indexable,
    ): string {
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

        return $adopting ? 'adopted' : 'created';
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
     * ⚠️ EVERY DECLARATION AS DECLARED, PLUS EACH ROW'S ID AND WHAT BECAME OF IT. Once a version is applied in a
     * real org, this is the only record ADR-039's merge can read: it finds the rows a blueprint owns by id, through
     * scoped queries, adds what a later version declares and this did not, and never touches a role it skipped. It
     * never revokes, never writes a row an earlier version wrote — so the operator's edits win without being looked
     * for — refuses a change to anything recorded here, and writes the next manifest in the rows' own transaction.
     *
     * ⚠️ BUILT ON THE THREE RECORD PROJECTIONS, WHICH THE MERGE COMPARES WITH. One projection for the record and the
     * comparison, so a key added to one cannot be missing from the other.
     *
     * @param  array{created: list<string>, adopted: list<string>, skipped: list<string>}  $outcome
     * @param  array{entry_types: array<string, array<string, mixed>>, roles: array<string, array{id: int|string|null, outcome: string}>}  $rows
     * @return array<string, mixed>
     */
    private static function manifestOf(BlueprintDefinition $definition, array $outcome, array $rows): array
    {
        return [
            'version' => $definition->version(),
            'entry_types' => array_map(
                static fn (EntryTypeDeclaration $type): array => [
                    'handle' => $type->handle,
                    'id' => $rows['entry_types'][$type->handle]['id'] ?? null,
                    'outcome' => $rows['entry_types'][$type->handle]['outcome'] ?? null,
                    ...self::typeRecord($type),
                    'fields' => array_map(
                        static fn (FieldDeclaration $field): array => [
                            'handle' => $field->handle,
                            'outcome' => $rows['entry_types'][$type->handle]['fields'][$field->handle] ?? null,
                            ...self::fieldRecord($field),
                        ],
                        $type->fields,
                    ),
                ],
                $definition->entryTypes(),
            ),
            'roles' => array_map(
                static fn (RoleDeclaration $role): array => [
                    'handle' => $role->handle,
                    'id' => $rows['roles'][$role->handle]['id'] ?? null,
                    'outcome' => $rows['roles'][$role->handle]['outcome'] ?? null,
                    ...self::roleRecord($role),
                ],
                $definition->roles(),
            ),
            'outcome' => $outcome,
        ];
    }

    /**
     * An entry type declaration as the manifest records it, less its handle, id, outcome and fields.
     *
     * ⚠️ `is_media` ALWAYS, NOT ONLY WHEN TRUE: the merge compares the keys a declaration emits, so a key written only for
     * a media type would let a later version drop the flag unseen (ADR-039, the DAM as built).
     *
     * @return array{name: string, plural_name: string, icon: ?string, description: ?string, ordering: int, on_collision: string, is_media: bool}
     */
    private static function typeRecord(EntryTypeDeclaration $type): array
    {
        return [
            'name' => $type->name,
            'plural_name' => $type->pluralName,
            'icon' => $type->icon,
            'description' => $type->description,
            'ordering' => $type->ordering,
            'on_collision' => $type->onCollision->value,
            'is_media' => $type->isMedia,
        ];
    }

    /**
     * A field declaration as the manifest records it, less its handle and outcome.
     *
     * @return array{type: string, label: string, pii_class: string, cardinality: int, is_indexed: bool, settings: array<string, mixed>, is_required: bool, help_text: ?string, ordering: int, group: ?string}
     */
    private static function fieldRecord(FieldDeclaration $field): array
    {
        return [
            'type' => $field->type,
            'label' => $field->label,
            'pii_class' => $field->piiClass,
            'cardinality' => $field->cardinality,
            'is_indexed' => $field->isIndexed,
            'settings' => $field->settings,
            'is_required' => $field->isRequired,
            'help_text' => $field->helpText,
            'ordering' => $field->ordering,
            'group' => $field->group,
        ];
    }

    /**
     * A role declaration as the manifest records it, less its handle, id and outcome — its grants derived.
     *
     * @return array{name: string, on_collision: string, grants: list<string>}
     */
    private static function roleRecord(RoleDeclaration $role): array
    {
        return [
            'name' => $role->name,
            'on_collision' => $role->onCollision->value,
            'grants' => self::permissionsOf($role),
        ];
    }

    /**
     * @return array{handle: string, version: string, created: list<string>, adopted: list<string>, skipped: list<string>, indexed: int, roles_created: list<string>}
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
            'roles_created' => [],
        ];
    }
}
