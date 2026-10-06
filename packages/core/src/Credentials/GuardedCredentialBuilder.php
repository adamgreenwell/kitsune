<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kitsune\Core\Tenancy\ScopedBuilder;
use RuntimeException;

/**
 * `credentials` and `org_credential_modes` have one door in: `CredentialWriter` — ADR-040.
 *
 * ⚠️ THE WINDOW IS THE DISCRIMINATOR, as on `role_permissions` (`GuardedGrantBuilder`): a private static the writer arms
 * around each save and clears in a `finally`, with a reader exposed and no opener. Every write that is not the
 * writer's is refused — the scoped reads stay as they are.
 *
 * ⚠️ AND NOT STOOD DOWN BY THE ESCAPE HATCH. `withoutScopeBecause()` suspends `ScopeWrites`, and inside it
 * `ScopedBuilder::upsert()` writes: measured on `role_permissions`, an upsert there added a grant. Here that would
 * plant another org's row, or a value unencrypted. So the check below asks nothing of `ScopeWrites`; and `upsert()`,
 * `delete()` and `forceDelete()` are refused even inside the window — the writer tombstones a removed value, and nothing
 * deletes a row but the org's own hard delete. `truncate()`, `updateOrInsert()` and `updateFrom()` are the parent's to
 * refuse, for every model.
 *
 * ⚠️ WHAT STAYS EXPOSED, stated: a listener a host registers on these models runs inside the window, as one on
 * `RolePermission` does today — core registers none, and `CredentialWriteDoorsTest` pins the count. Below Eloquent
 * (`DB::table`, `toBase()`, raw SQL) nothing stands, as ADR-020 says of the audit log; the sealed envelope makes a
 * ciphertext moved there refuse to open rather than mean something else.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 *
 * @template TModel of Model
 *
 * @extends ScopedBuilder<TModel>
 */
class GuardedCredentialBuilder extends ScopedBuilder
{
    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        $this->refuseUnlessWriting('update()');

        return parent::update($values);
    }

    /** @param  array<string, mixed>  $values */
    public function insert(array $values): bool
    {
        $this->refuseUnlessWriting('insert()');

        return parent::insert($values);
    }

    /**
     * `performInsert()`'s path for an incrementing model, so it is the one the writer's save takes.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->refuseUnlessWriting('insertGetId()');

        return parent::insertGetId($values, $sequence);
    }

    /** @param  array<string, mixed>  $values */
    public function insertOrIgnore(array $values): int
    {
        $this->refuseUnlessWriting('insertOrIgnore()');

        return parent::insertOrIgnore($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $returning
     * @param  array<int, string>|string|null  $uniqueBy
     * @return Collection<int, mixed>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): Collection
    {
        $this->refuseUnlessWriting('insertOrIgnoreReturning()');

        return parent::insertOrIgnoreReturning($values, $returning, $uniqueBy);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $this->refuseUnlessWriting('insertUsing()');

        return parent::insertUsing($columns, $query);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->refuseUnlessWriting('insertOrIgnoreUsing()');

        return parent::insertOrIgnoreUsing($columns, $query);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->refuse('upsert()');
    }

    /** @return int */
    public function delete()
    {
        $this->refuse('delete()');
    }

    /** Eloquent sends a force delete to the query builder rather than through `delete()`. */
    public function forceDelete()
    {
        $this->refuse('forceDelete()');
    }

    /**
     * `increment()`, `decrement()` and their `Each` forms, which reach the query builder without passing `update()`.
     *
     * @param  array<string, mixed>  $values
     */
    protected function guardArithmetic(array $values): void
    {
        $this->refuseUnlessWriting('an arithmetic write');

        parent::guardArithmetic($values);
    }

    private function refuseUnlessWriting(string $method): void
    {
        if (! CredentialWriter::isWriting()) {
            $this->refuse($method);
        }
    }

    private function refuse(string $method): never
    {
        throw new RuntimeException(sprintf(
            'Refusing %s on credentials: a stored credential changes only through %s, which checks the value, '
            .'encrypts it, files it under the current organisation and records the change (ADR-040). Anywhere else, a '
            .'write could store a value unencrypted, file it under another organisation, or change it unrecorded.',
            $method,
            CredentialWriter::class,
        ));
    }
}
