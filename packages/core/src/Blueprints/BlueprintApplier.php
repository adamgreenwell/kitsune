<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\Declarations\FieldDeclaration;
use Kitsune\Core\Blueprints\Declarations\RoleDeclaration;
use Kitsune\Core\Fields\FieldTypeRegistry;
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
 * or undo. `applied_at` null means an apply started and did not finish.
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
 * - `applied_at` set — done: the same version is a no-op, and a different one is refused before anything is
 *   written, because ADR-039's additive merge is not built. Applying over it stranded the org: the intent record
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
            /*
             * ⚠️ ANOTHER VERSION IS REFUSED, AND THE RECEIPT IS LEFT AS IT IS. ADR-039's merge — add what the new
             * version declares and the old one did not — is not built, and an apply over the old version cleared
             * the manifest and then refused, under the default policy, the very types that version had created.
             */
            if ($receipt->version !== $definition->version()) {
                throw new RuntimeException(self::versionRefusal($definition, $receipt));
            }

            return self::nothingToDo($definition);
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
         */
        $receipt ??= new Blueprint(['handle' => $definition->handle()]);
        $receipt->version = $definition->version();
        $receipt->applied_at = null;
        $receipt->manifest = null;

        /* A receipt that appeared since it was read is another apply's, begun at the same moment. */
        try {
            $receipt->save();
        } catch (UniqueConstraintViolationException $e) {
            throw new RuntimeException(self::concurrent($definition), 0, $e);
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
        } catch (Throwable $e) {
            /*
             * ⚠️ A RECEIPT THAT NOW RECORDS ROWS IS NOT THIS RUN'S. This run's manifest rolled back with its rows, so
             * a manifest there now is another apply's, which ran at the same moment — and this run's refusal of
             * "its own" types, or its raw constraint error, would be telling the operator something false.
             */
            try {
                $fresh = Blueprint::query()->whereKey($receipt->getKey())->first();
            } catch (Throwable) {
                throw $e;
            }

            if ($fresh !== null && ($fresh->manifest !== null || $fresh->applied_at !== null)) {
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

    private static function versionRefusal(BlueprintDefinition $definition, Blueprint $receipt): string
    {
        return sprintf(
            'Blueprint [%s] is applied in this organisation at %s; this definition is %s. Applying a different '
            .'version over it, newer or older, waits on ADR-039\'s merge, which is not built yet, and nothing clears '
            .'a receipt yet either. Nothing was written, and the receipt still says %s.',
            $definition->handle(),
            (string) $receipt->version,
            $definition->version(),
            (string) $receipt->version,
        );
    }

    private static function concurrent(BlueprintDefinition $definition): string
    {
        return sprintf(
            'The apply of [%s] stopped, and this organisation\'s receipt for it now records rows this run did not '
            .'commit: another apply of it ran at the same moment, or this run\'s own commit landed as it failed. '
            .'Re-run it — it finishes what the receipt records, or does nothing if that is done.',
            $definition->handle(),
        );
    }

    /**
     * Finish an apply whose rows committed and whose finish did not run: index what it declared, and say so.
     *
     * ⚠️ THE VERSION THE RECEIPT RECORDS, WHICHEVER VERSION THIS DEFINITION IS. The finish reads only the manifest —
     * which fields to index, which rows to look for — so it needs nothing of the version that wrote them but the
     * record, and Blog's version moves with core's: an org whose rows committed under an older core is finished by a
     * newer one, at the old version, and the newer version is then refused as any other is.
     *
     * ⚠️ THE MANIFEST IS CHECKED AGAINST THE DATABASE FIRST. It is one of the receipt's two columns a bulk write is
     * not refused for (`applied_at` is the other), so a manifest that is not this org's record of these rows is
     * refused rather than trusted into an `applied_at`:
     * - at the same version, it must record every type and role the definition declares, as created or skipped;
     * - a row it records as created must not be another org's, nor another type's or role's under its id.
     *
     * A row it records as created that is gone altogether is one the operator removed while the finish was owed —
     * which they may do after a finish at no cost — so it is reported and the finish goes ahead, rather than leaving
     * an org that no command can finish or clear.
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
                    $holds = self::recordedRowHolds($key, $row['id'] ?? null, $handle, $orgId);

                    if ($holds === false) {
                        $refused[] = sprintf('%s id %s is not this organisation\'s %s', $kind, (string) ($row['id'] ?? '?'), $handle);
                    } elseif ($holds === null) {
                        $removed[] = "{$kind} {$handle}: removed since the interrupted apply wrote it; not written again";
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
            throw new RuntimeException(sprintf(
                'The receipt for [%s] cannot be finished: %s. Its manifest is not this organisation\'s record of this '
                .'blueprint\'s rows, so nothing was written and the receipt is left as it is — and no command clears a '
                .'receipt yet, because ADR-039\'s reverse is not built.',
                $definition->handle(),
                implode('; ', $refused),
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

        $notes = ['rows: written by an earlier run that stopped before it finished; finished now', ...$removed];

        if (! $sameVersion) {
            $notes[] = sprintf(
                'version: finished at %s, which the receipt records; this definition is %s, and applying it over %s '
                .'waits on ADR-039\'s merge',
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
     * Whether the row a manifest records under an id is this org's, by that handle: true; another's: false; gone: null.
     *
     * ⚠️ PAST THE SCOPE FOR A ROLE, AND ONLY TO TELL "ANOTHER ORG'S" FROM "GONE". Through the scope the two read the
     * same, and they mean opposite things: one is a manifest that is not this org's, the other a row its operator
     * removed. Nothing read here is written or returned.
     */
    private static function recordedRowHolds(string $key, mixed $id, string $handle, int $orgId): ?bool
    {
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return false;
        }

        $row = $key === 'entry_types'
            ? EntryType::query()->whereKey($id)->first(['id', 'org_id', 'handle'])
            : Role::query()->withoutGlobalScopes()->whereKey($id)->first(['id', 'org_id', 'handle']);

        if ($row === null) {
            return null;
        }

        return (int) $row->getAttribute('org_id') === $orgId && $row->getAttribute('handle') === $handle;
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

        /*
         * ⚠️ ONLY ON A TYPE THIS APPLY CREATED. One adopted under Skip is the operator's — its entries theirs, its
         * authority theirs to give — so a grant on it is refused, and the whole apply with it, rather than handed to
         * whoever an owner later assigns this role to, believing it the blueprint's.
         */
        foreach (array_keys($declaration->grants) as $type) {
            if (($rows['entry_types'][$type]['outcome'] ?? null) !== 'created') {
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
