<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Roles\Concerns;

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Roles\RoleResource;
use Kitsune\Core\Models\Role;

/**
 * The form carries grants; the role's own row does not — issue #84.
 *
 * ⚠️ A GRANT IS A ROW IN ANOTHER TABLE, so it cannot ride along in the model's attributes. That is ADR-015's
 * shape applied to authorization, and it brings the same trap `SyncsFieldRelations` records: `dehydrated(false)`
 * suppresses the leaf value and leaves the nested CONTAINER key in the form data, which the save then tries
 * to write as a column. Any form key that is not a model attribute has to be removed here.
 *
 * ⚠️ AND IT WRITES THROUGH `grant()` / `revoke()` RATHER THAN THE RELATION. Those are the audited path — they
 * record `role.granted` / `role.revoked`, refuse a permission the registry does not know, refuse a role from
 * another org, and drop the memoised permission sets. A page that wrote `role_permissions` directly would
 * skip all four, and the audit would be missing exactly where somebody changed who may do what.
 */
trait SyncsRolePermissions
{
    /**
     * Fill the checkboxes from what the role actually holds.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Role) {
            return $data;
        }

        $data[RoleResource::PERMISSION_STATE] = [];
        $data[RoleResource::ANY_TYPE_STATE] = [];
        $data[RoleResource::HOLDER_STATE] = DB::table('role_user')
            ->where('role_id', $record->getKey())
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($record->permissions()->pluck('permission') as $permission) {
            $parts = explode('.', (string) $permission);

            if (count($parts) !== 3) {
                continue;
            }

            [, $type, $action] = $parts;

            if ($type === Permissions::ANY_TYPE) {
                $data[RoleResource::ANY_TYPE_STATE][] = $action;

                continue;
            }

            $data[RoleResource::PERMISSION_STATE][$type][] = $action;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->withoutGrantState($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->withoutGrantState($data);
    }

    protected function afterCreate(): void
    {
        $this->syncGrants();
        $this->syncHolders();
    }

    protected function afterSave(): void
    {
        $this->syncGrants();
        $this->syncHolders();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutGrantState(array $data): array
    {
        unset(
            $data[RoleResource::PERMISSION_STATE],
            $data[RoleResource::ANY_TYPE_STATE],
            $data[RoleResource::HOLDER_STATE],
        );

        return $data;
    }

    /**
     * Bring the role's grants in line with the form, one grant at a time.
     *
     * ⚠️ A DIFF RATHER THAN A REPLACE, and the difference is the audit trail. Deleting every grant and
     * re-inserting the chosen ones would record a revoke and a grant for every permission on every save —
     * a log that says an editor's authority changed nine times when it changed once. `grant()` and
     * `revoke()` are already silent about a no-op, so the diff falls out of calling them only for a real
     * change.
     */
    /**
     * Bring the role's holders in line with the form.
     *
     * ⚠️ THROUGH `assignTo()` / `removeFrom()`, never the pivot, for the reason the class docblock gives:
     * those are the audited path, and ADR-033 names assignment as the audited security event. They also
     * refuse a role from another org and drop the memoised permission sets — three guarantees a direct
     * `DB::table('role_user')` write would skip in one line.
     *
     * ⚠️ AND A DIFF, so the log records the change rather than the save. Both helpers are silent about a
     * no-op, so calling them only for a real difference is what keeps `role.assigned` meaning somebody was
     * assigned.
     */
    private function syncHolders(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Role) {
            return;
        }

        $desired = array_map(
            intval(...),
            array_filter((array) ($this->form->getRawState()[RoleResource::HOLDER_STATE] ?? []), is_numeric(...)),
        );

        $held = DB::table('role_user')
            ->where('role_id', $record->getKey())
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach (array_diff($desired, $held) as $userId) {
            $record->assignTo($userId);
        }

        foreach (array_diff($held, $desired) as $userId) {
            $record->removeFrom($userId);
        }
    }

    private function syncGrants(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof Role) {
            return;
        }

        $state = $this->form->getRawState();

        $desired = [];

        foreach ((array) ($state[RoleResource::ANY_TYPE_STATE] ?? []) as $action) {
            if (is_string($action)) {
                $desired[] = Permissions::forEntryType(Permissions::ANY_TYPE, $action);
            }
        }

        foreach ((array) ($state[RoleResource::PERMISSION_STATE] ?? []) as $type => $actions) {
            if (! is_string($type)) {
                continue;
            }

            foreach ((array) $actions as $action) {
                if (is_string($action)) {
                    $desired[] = Permissions::forEntryType($type, $action);
                }
            }
        }

        $held = $record->permissions()->pluck('permission')->map(strval(...))->all();

        foreach (array_diff($desired, $held) as $permission) {
            $record->grant($permission);
        }

        foreach (array_diff($held, $desired) as $permission) {
            $record->revoke($permission);
        }
    }
}
