<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Closure;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Tenancy\Context;
use WeakMap;

/**
 * Batches the stored identity reads for the controls of one rendered media page.
 *
 * @internal
 */
final class EntryListAuthorization
{
    private const MAX_VISIBLE_ROWS = 50;

    /** @var WeakMap<Entry, Entry>|null */
    private ?WeakMap $rows = null;

    private ?Entry $activeRecord = null;

    private ?string $activeAbility = null;

    /**
     * @param  iterable<mixed>  $records  The paginator's visible items, after the lookahead row is removed.
     */
    public function render(iterable $records, Closure $html): string
    {
        $visible = [];
        $keys = [];
        foreach ($records as $record) {
            if (! $record instanceof Entry || ! $record->exists || count($keys) === self::MAX_VISIBLE_ROWS) {
                return $html();
            }
            $key = $record->getKeyForAuthorization();
            if (! is_int($key) && ! is_string($key)) {
                return $html();
            }
            $visible[] = $record;
            $keys[] = $key;
        }

        $context = app(Context::class);
        if ($keys === [] || ! $context->hasSite() || ! $context->hasOrg()) {
            return $html();
        }

        // The display read keeps the current scope. Missing rows take the ordinary policy path;
        // both paths compare the stored identity with the model’s loaded identity in EntryPolicy.
        $stored = Entry::withTrashed()->whereKey($keys)
            ->get(['id', 'site_id', 'org_id', 'type_handle', 'entry_type_id'])
            ->keyBy('id');
        /** @var WeakMap<Entry, Entry> $rows */
        $rows = new WeakMap;
        foreach ($visible as $entry) {
            $row = $stored->get($entry->getKeyForAuthorization());
            if ($row !== null) {
                $rows[$entry] = $row;
            }
        }

        $previous = $this->rows;
        $this->rows = $rows;

        try {
            return $html();
        } finally {
            $this->rows = $previous;
        }
    }

    /** Applies the batch only while Filament resolves this built-in control's presentation. */
    public function forControl(Entry $record, string $ability, Closure $authorization): mixed
    {
        if (! isset($this->rows[$record])) {
            return $authorization();
        }

        $previousRecord = $this->activeRecord;
        $previousAbility = $this->activeAbility;
        $this->activeRecord = $record;
        $this->activeAbility = $ability;

        try {
            return $authorization();
        } finally {
            $this->activeRecord = $previousRecord;
            $this->activeAbility = $previousAbility;
        }
    }

    public function storedRecord(Entry $record, string $ability): ?Entry
    {
        return $this->activeRecord === $record && $this->activeAbility === $ability
            ? ($this->rows[$record] ?? null)
            : null;
    }
}
