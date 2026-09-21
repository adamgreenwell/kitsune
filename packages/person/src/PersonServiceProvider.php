<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Person;

use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Modules\ModuleServiceProvider;
use RuntimeException;

/**
 * People as content — the roadmap's *"one hardcoded entity type end to end as a normal module"* (ADR-038).
 *
 * ⚠️ IT SHIPS NO MODELS AND NO MIGRATIONS, AND THAT IS THE POINT RATHER THAN A SHORTCUT. A person is an
 * ENTRY — `Permissions::ACTIONS` has exactly one subject, `entry`, so a module cannot ask for a permission of
 * its own before v1.2 and anything it adds must be authorised as an entry type. Entries already have a table,
 * so this module's entire footprint is three rows: an `entry_types` row, and a `field_storage` + `fields` pair
 * per field. A module that invented a table to look substantial would be inventing schema to make a proof
 * prettier, and `scoping: []` is then a claim the verifier can actually check — it sweeps the package and
 * confirms there is nothing to scope.
 *
 * ⚠️ THE TYPE IS GLOBAL (`org_id` NULL), which is what makes it a SYSTEM type: `EntryType::visibleFor()`
 * includes `whereNull('org_id')`, so every org sees it, and `EntryTypeResource::ownsRecord()` returns false for
 * a null org, so no org can edit or delete it from the admin. Protection comes from that, not from `is_system`
 * — which `architecture.md` publishes as "undeletable" and nothing enforces (recorded as an open question by
 * ADR-038). The flag is set because it is true, and nothing here relies on it.
 *
 * ⚠️ AND THE FIELD HANDLES ARE PREFIXED. `field_storage` carries `unique(['org_id', 'handle'])` and NULLs
 * compare distinct on every engine, so a GLOBAL field's handle is claimed install-wide. Taking `name` and
 * `email` unprefixed would block every later module and every org that wanted the obvious word for the obvious
 * thing.
 */
final class PersonServiceProvider extends ModuleServiceProvider
{
    public const TYPE = 'person';

    /** @var array<string, array{type: string, label: string, pii_class: string}> */
    private const FIELDS = [
        'person_name' => ['type' => 'text', 'label' => 'Full name', 'pii_class' => 'personal'],
        'person_email' => ['type' => 'text', 'label' => 'Email', 'pii_class' => 'personal'],
    ];

    public function install(): void
    {
        if (EntryType::query()->where('handle', self::TYPE)->whereNull('org_id')->exists()) {
            throw new RuntimeException('A global `person` entry type already exists.');
        }

        /*
         * No scope hatch here, and that is worth saying rather than leaving to be noticed: `EntryType`,
         * `FieldStorage` and `Field` are all `#[Unscoped]`, so there is no scope to stand down and `org_id`
         * is never filled from context. A hatch would suggest a constraint is being bypassed when none
         * applies.
         */
        $type = EntryType::create([
            'org_id' => null,
            'handle' => self::TYPE,
            'name' => 'Person',
            'plural_name' => 'People',
            'icon' => 'heroicon-o-user',
            'is_system' => true,
        ]);

        foreach (self::FIELDS as $handle => $field) {
            $storage = FieldStorage::create([
                'org_id' => null,
                'handle' => $handle,
                'type' => $field['type'],
                'pii_class' => $field['pii_class'],
                'cardinality' => 1,
            ]);

            Field::create([
                'entry_type_id' => $type->id,
                'field_storage_id' => $storage->id,
                'label' => $field['label'],
            ]);
        }
    }

    /**
     * ⚠️ REFUSED WHILE ANY PERSON EXISTS, AND COUNTED PAST THE SCOPE RATHER THAN THROUGH IT. `Entry` is
     * `#[SiteScoped]`, so an ordinary count from the console — where no site is in context — returns zero
     * whatever is in the table, and the module would cheerfully delete the type out from under every org's
     * people. The count is the thing this refusal rests on, so it has to be the honest one.
     *
     * ⚠️ AND `withoutScopeBecause()` IS THE WRONG TOOL FOR IT, which the first version of this method used and
     * a test caught. That hatch suspends WRITE scoping (`ScopeWrites::suspend`); it leaves the global read
     * scope in place. Measured with a person sitting in a site no context named: plain count 0,
     * `withoutScopeBecause` count 0, `withoutGlobalScopes()` count 1, raw row count 1. A read past a scope is
     * `withoutGlobalScopes()`, and the two are not interchangeable however similar the names read.
     */
    public function uninstall(): void
    {
        $type = EntryType::query()->where('handle', self::TYPE)->whereNull('org_id')->first();

        if (! $type instanceof EntryType) {
            return;
        }

        $people = Entry::query()
            ->withoutGlobalScopes()
            ->where('entry_type_id', $type->id)
            ->count();

        if ($people > 0) {
            throw new RuntimeException(sprintf(
                'kitsune/person still holds %d %s. Delete them before uninstalling the module — core cannot '
                .'know whether they matter, so it asks.',
                $people,
                $people === 1 ? 'person' : 'people',
            ));
        }

        $storageIds = Field::query()->where('entry_type_id', $type->id)->pluck('field_storage_id');

        Field::query()->where('entry_type_id', $type->id)->delete();

        foreach ($storageIds as $storageId) {
            FieldStorage::query()->whereKey($storageId)->whereNull('org_id')->delete();
        }

        $type->delete();
    }
}
