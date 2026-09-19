<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Concerns;

/**
 * Which row a written key names, answered once for every guard that compares one.
 *
 * ⚠️ PHP AND THE DATABASE READ A KEY DIFFERENTLY, AND THE GUARDS ASKED PHP. `(int) '13.9'` is 13, and MySQL and
 * MariaDB round `'13.9'` to 14 when they store it in an integer column — `'13.9e0'` and the float `13.9` too. So a
 * guard asking `(int) $value === $current` passed a key the database then wrote into the next org: measured on both,
 * from org 13, through a mass update, a save, a create, a hand-rolled insert and an audit append, each of which
 * stored 14. A relation's storage key went the same way by another road: looked up as `'5.4'` it found nothing, so
 * every check that needed the storage stood aside, and the engine stored 5. SQLite keeps the fraction and PostgreSQL
 * refuses it as a bigint, which is luck rather than a guard.
 *
 * So a key is an int, or exactly the decimal string of one — the two shapes a model and a form hand over — and
 * anything else names no row a guard can vouch for. That is the same rule `ResolvesWrittenColumns` applies to a
 * column's NAME, applied to its value: what counts is what the database writes.
 *
 * @internal Kitsune's own guards use this. It is not an extension point, and it may change or move before v1.2
 *           without notice (CONTRIBUTING.md: no new public API surface before then).
 */
trait ReadsWrittenKeys
{
    /**
     * The id a written key names, or null when it is not exactly a whole id and so names no row for certain.
     */
    private static function writtenKey(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value === (string) (int) $value ? (int) $value : null;
    }
}
