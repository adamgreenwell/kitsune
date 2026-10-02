<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use InvalidArgumentException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Role;
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
 *
 * ⚠️ THE MANIFEST COMMITS WITH THE ROWS (ADR-039, roles and Blog as built). Written last inside the transaction,
 * so a receipt whose manifest is set and whose `applied_at` is null means exactly "the rows committed and the
 * finish did not run" — which a re-run at the same version completes rather than refusing its own types. A
 * receipt at another version is refused before anything is written: ADR-039's additive merge is not built, and
 * re-applying over it stranded the org (the intent record cleared the manifest, then the default policy refused
 * the types the first version had created).
 *
 * ⚠️ AN APPLY WRITES NO `role_user` ROW, AND NO GRANT ON A ROLE IT DID NOT CREATE. A role a blueprint declares is
 * created with no holders, and one already there is either refused or left exactly as it is.
 */
final class BlueprintApplier
{
    /** A role handle: the admin's type-handle shape, so a handle never needs escaping where it is shown. */
    private const ROLE_HANDLE = '/^[a-z][a-z0-9_]*$/';

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

        /* An author's bug, refused before the receipt: it leaves no receipt and no rows, and asks the database nothing. */
        self::refuseMalformed($definition);

        $receipt = Blueprint::receiptFor($definition->handle());

        /*
         * ⚠️ ANOTHER VERSION IS REFUSED, AND THE RECEIPT IS LEFT AS IT IS. ADR-039's merge — add what the new
         * version declares and the old one did not — is not built, and an apply over the old version cleared the
         * manifest and then refused, under the default policy, the very types that version had created.
         */
        if ($receipt !== null && $receipt->version !== $definition->version()) {
            throw new RuntimeException(self::versionRefusal($definition, $receipt));
        }

        if ($receipt !== null && $receipt->applied_at !== null) {
            return self::nothingToDo($definition);
        }

        /* The rows committed and the finish did not run: finish it, rather than refuse what this blueprint made. */
        if ($receipt !== null && $receipt->manifest !== null) {
            return self::finishInterrupted($definition, $receipt, $orgId);
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
        $rows = ['entry_types' => [], 'roles' => []];

        /*
         * ⚠️ On the MODEL's connection rather than the `DB` facade's, which always resolves the default one.
         * `Site::save()` and `SettingsWriter` were both corrected from that, and `ModuleLifecycle` after them.
         */
        $receipt->getConnection()->transaction(function () use ($definition, $orgId, $receipt, &$outcome, &$indexable, &$rows): void {
            foreach ($definition->entryTypes() as $declaration) {
                self::applyEntryType($declaration, $orgId, $outcome, $indexable, $rows);
            }

            /* After every type, so a role's grants name types that exist — this apply's own among them. */
            foreach ($definition->roles() as $declaration) {
                self::applyRole($declaration, $outcome, $rows);
            }

            /*
             * ⚠️ LAST, AND INSIDE. The manifest commits with the rows or not at all, so "manifest set, applied_at
             * null" means exactly "rows committed, the finish did not run" — the state `finishInterrupted()`
             * completes, where before a re-run refused its own types.
             */
            $receipt->manifest = self::manifestOf($definition, $outcome, $rows);
            $receipt->save();
        });

        /*
         * ⚠️ AFTER THE TRANSACTION, AND FAILING HERE IS NOT A FAILED APPLY. `is_indexed` is a row that says
         * what the operator wants; the generated column is DDL that cannot join the transaction on two of the
         * four engines. The rows are committed and correct either way, and `kitsune:schema-sync` is the
         * documented repair — which is exactly what the admin does when a field saves but cannot be indexed.
         */
        $indexed = self::syncIndexes($indexable);

        $receipt->applied_at = now();
        $receipt->save();

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
     * Refuse a definition its author got wrong, before anything is read or written — ADR-039, roles as built.
     *
     * ⚠️ NO QUERY. Whether a type or a role is already in the org is the apply's question, answered under the
     * collision policy; this answers only what the definition says of itself, so a malformed one leaves no receipt
     * claiming an apply began.
     *
     * @throws InvalidArgumentException
     */
    private static function refuseMalformed(BlueprintDefinition $definition): void
    {
        $handle = $definition->handle();
        $types = [];

        foreach ($definition->entryTypes() as $type) {
            /* One encoding of the grammar: a type a grant can never name is refused as the grant would be. */
            try {
                Permissions::validated(Permissions::forEntryType($type->handle, 'view'));
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(sprintf(
                    'Blueprint [%s] declares entry type [%s], which cannot carry a grant: %s',
                    $handle,
                    $type->handle,
                    $e->getMessage(),
                ), 0, $e);
            }

            if (isset($types[$type->handle])) {
                throw new InvalidArgumentException("Blueprint [{$handle}] declares entry type [{$type->handle}] twice.");
            }

            $types[$type->handle] = true;
        }

        $declared = array_keys($types);
        $roles = [];

        foreach ($definition->roles() as $role) {
            if (preg_match(self::ROLE_HANDLE, $role->handle) !== 1 || strlen($role->handle) > 255) {
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

    private static function versionRefusal(BlueprintDefinition $definition, Blueprint $receipt): string
    {
        return sprintf(
            'Blueprint [%s] is recorded in this organisation at %s (%s); this definition is %s. Applying one '
            .'version over another is ADR-039\'s additive merge, which is not built yet. Nothing was written, and '
            .'the receipt still says %s.',
            $definition->handle(),
            (string) $receipt->version,
            $receipt->applied_at !== null ? 'applied' : 'started and not finished',
            $definition->version(),
            (string) $receipt->version,
        );
    }

    /**
     * Finish an apply whose rows committed and whose finish did not run: index what it declared, and say so.
     *
     * ⚠️ THE MANIFEST IS CHECKED AGAINST THE DATABASE FIRST, through scoped queries. It is the one column of the
     * receipt a bulk write cannot be refused for, so a manifest naming rows this org does not have is refused
     * rather than trusted into an `applied_at`.
     *
     * @return array{handle: string, version: string, created: list<string>, adopted: list<string>, skipped: list<string>, indexed: int, roles_created: list<string>}
     *
     * @throws RuntimeException
     */
    private static function finishInterrupted(BlueprintDefinition $definition, Blueprint $receipt, int $orgId): array
    {
        /** @var array{entry_types?: list<array<string, mixed>>, roles?: list<array<string, mixed>>} $manifest */
        $manifest = (array) $receipt->manifest;
        $missing = [];
        $indexedHandles = [];

        foreach ($manifest['entry_types'] ?? [] as $type) {
            if (($type['outcome'] ?? null) === 'created'
                && ! EntryType::query()->where('org_id', $orgId)->whereKey($type['id'] ?? 0)->exists()) {
                $missing[] = sprintf('entry type id %s', (string) ($type['id'] ?? '?'));
            }

            foreach ((array) ($type['fields'] ?? []) as $field) {
                if (is_array($field) && ($field['is_indexed'] ?? false) === true && is_string($field['handle'] ?? null)) {
                    $indexedHandles[] = $field['handle'];
                }
            }
        }

        foreach ($manifest['roles'] ?? [] as $role) {
            /* ⚠️ Scoped, never past it: a role id from another org is a role this org does not have. */
            if (($role['outcome'] ?? null) === 'created' && ! Role::query()->whereKey($role['id'] ?? 0)->exists()) {
                $missing[] = sprintf('role id %s', (string) ($role['id'] ?? '?'));
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The receipt for [%s] records rows this organisation does not have (%s). It was not finished, and '
                .'nothing was written. `kitsune:blueprint status` shows it.',
                $definition->handle(),
                implode(', ', $missing),
            ));
        }

        $indexable = $indexedHandles === [] ? [] : FieldStorage::query()
            ->where('org_id', $orgId)
            ->whereIn('handle', array_values(array_unique($indexedHandles)))
            ->where('is_indexed', true)
            ->get()
            ->all();

        $indexed = self::syncIndexes(array_values($indexable));

        $receipt->applied_at = now();
        $receipt->save();

        return [
            'handle' => $definition->handle(),
            'version' => $definition->version(),
            'created' => [],
            'adopted' => [],
            'skipped' => ['rows: written by an earlier run that stopped before it finished; finished now'],
            'indexed' => $indexed,
            'roles_created' => [],
        ];
    }

    /**
     * Create a role this blueprint declares, with exactly the grants it declares — ADR-039's second key.
     *
     * ⚠️ THE LOOKUP IS SCOPED, NEVER PAST IT. A role with the same handle in another org is not a collision, and
     * reading past the scope would refuse — or under Skip, leave alone — another org's role. On MySQL and MariaDB
     * the default collation matches an operator's `Blog_Editor` too, which agrees with the unique index, so the
     * outcome is the named refusal rather than a raw constraint error.
     *
     * @param  array{created: list<string>, adopted: list<string>, skipped: list<string>}  $outcome
     * @param  array{entry_types: array<string, array<string, mixed>>, roles: array<string, array{id: int|string|null, outcome: string}>}  $rows
     */
    private static function applyRole(RoleDeclaration $declaration, array &$outcome, array &$rows): void
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
     * scoped queries, adds what a later version declares and this did not, and never touches a role it skipped.
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
                    'name' => $type->name,
                    'plural_name' => $type->pluralName,
                    'icon' => $type->icon,
                    'description' => $type->description,
                    'ordering' => $type->ordering,
                    'on_collision' => $type->onCollision->value,
                    'fields' => array_map(
                        static fn (FieldDeclaration $field): array => [
                            'handle' => $field->handle,
                            'outcome' => $rows['entry_types'][$type->handle]['fields'][$field->handle] ?? null,
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
                    'name' => $role->name,
                    'on_collision' => $role->onCollision->value,
                    'grants' => self::permissionsOf($role),
                ],
                $definition->roles(),
            ),
            'outcome' => $outcome,
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
