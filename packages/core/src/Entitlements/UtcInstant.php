<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * An instant on `entitlements` or `reader_tokens`, stored as UTC wall clock in whole seconds and read back as UTC — ADR-040.
 *
 * ⚠️ NOT Eloquent's own `datetime` cast, which formats a Carbon in ITS OWN zone with no offset: a host that changes
 * `app.timezone` would then move every stored end by the offset, and a caller's Paris-zoned end would be stored as
 * Paris wall clock. `config/kitsune.php` promises "storage is UTC regardless"; this is where that holds for the first
 * core column comparing a stored instant against the clock.
 *
 * ⚠️ ONLY AN INSTANT IS WRITTEN. A string is refused, so an ISO `T` spelling or a fraction can never reach SQLite, where
 * `datetime` is text and such a value misorders against `Y-m-d H:i:s`. Below Eloquent nothing stands, as ADR-020 says of
 * the audit log; `GuardedEntitlementBuilder` refuses every Eloquent write but the writer's.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 *
 * @implements CastsAttributes<CarbonImmutable|null, mixed>  whatever is assigned arrives here, and only an instant is kept
 */
final class UtcInstant implements CastsAttributes
{
    public const FORMAT = 'Y-m-d H:i:s';

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        $read = CarbonImmutable::createFromFormat(self::FORMAT, substr((string) $value, 0, 19), 'UTC');

        return $read instanceof CarbonImmutable ? $read : null;
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException(sprintf(
                'Refusing to write [%s] on %s from a %s: only an instant is written, and it is stored as UTC in '
                .'whole seconds, so a string spelled another way can never be compared against the clock (ADR-040).',
                $key,
                $model->getTable(),
                get_debug_type($value),
            ));
        }

        return self::stored($value);
    }

    /** The stored spelling of an instant: UTC, whole seconds, `Y-m-d H:i:s`. */
    public static function stored(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->startOfSecond()->format(self::FORMAT);
    }
}
