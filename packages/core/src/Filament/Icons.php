<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use BladeUI\Icons\Exceptions\SvgNotFound;
use BladeUI\Icons\Factory;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Icon names, and the reason they cannot be trusted.
 *
 * ⚠️ `entry_types.icon` was a free-text column rendered into the navigation on
 * EVERY admin page. Blade Icons throws `SvgNotFound` for a name it cannot
 * resolve, so one typo returned 500 from every page in that org's admin —
 * including `/entry-types`, the only page that could have corrected it. An
 * author could permanently brick their own admin with a misspelling, and there
 * was no way back through the interface.
 *
 * Three layers, and they are not redundant:
 *
 * 1. `orFallback()` at the RENDER boundary, which is what actually prevents the
 *    outage. It holds however the value got there — a seed, an import, a direct
 *    SQL write, or a row written before the guard existed. A renderer must not
 *    trust its data.
 * 2. `options()` in the form, so the common path cannot produce a bad name.
 * 3. A model guard, so a bad write is REPORTED rather than silently falling
 *    back to a different icon than the author asked for.
 */
final class Icons
{
    /** The navigation icon for an entry type that names none. */
    public const DEFAULT_ENTRY_TYPE = 'heroicon-o-rectangle-stack';

    /**
     * Judgements per name, for this request.
     *
     * ⚠️ A plain static keyed on the NAME, not `once()`. The key is the value
     * the body uses, which is what invariant 13 asks for — and the answer
     * depends on the installed icon sets rather than on any model, so there is
     * no object identity to get wrong.
     *
     * @var array<string, bool|null>
     */
    private static array $judged = [];

    /**
     * Whether the name resolves — or NULL when that cannot be determined here.
     *
     * ⚠️ Three-valued on purpose, and two-valued was a bug waiting to happen.
     *
     * The icon factory is Blade Icons', registered by a service provider that a
     * headless console context, an artisan command in a bare install, or a
     * package test harness may never boot. Resolving it then throws — so a
     * boolean answer would have made `EntryType::save()` explode wherever the UI
     * layer is absent, refusing a write for a reason that has nothing to do with
     * the data. Found by the test suite, which is exactly such a context.
     *
     * So each caller states its own tolerance rather than inheriting one:
     * `resolves()` is strict, the model guard refuses only a definite NO, and
     * `orFallback()` substitutes only for a definite NO.
     */
    public static function judge(string $name): ?bool
    {
        if ($name === '') {
            return false;
        }

        if (array_key_exists($name, self::$judged)) {
            return self::$judged[$name];
        }

        try {
            $factory = app(Factory::class);
        } catch (Throwable) {
            // Unjudgeable, not invalid. Deliberately not memoised: the provider
            // may boot later in the same process, and caching "cannot tell"
            // would outlive the reason for it.
            return null;
        }

        try {
            $factory->svg($name);

            return self::$judged[$name] = true;
        } catch (SvgNotFound) {
            // Only this exception. Catching Throwable here would swallow a
            // misconfigured icon set and report it as a bad name, sending the
            // next reader to look in the wrong place entirely.
            return self::$judged[$name] = false;
        }
    }

    /** Strictly: does this name resolve, here, now? */
    public static function resolves(string $name): bool
    {
        return self::judge($name) === true;
    }

    /**
     * The name unless it is KNOWN not to resolve.
     *
     * Not `resolves()`: where the factory is unavailable there is nothing to
     * render anyway, and swapping a name we could not judge would hide the
     * author's choice for no gain.
     */
    public static function orFallback(?string $name, string $fallback = self::DEFAULT_ENTRY_TYPE): string
    {
        if ($name === null || $name === '') {
            return $fallback;
        }

        return self::judge($name) === false ? $fallback : $name;
    }

    /**
     * Every Heroicon Filament ships, grouped by style for a searchable select.
     *
     * ⚠️ A solid case is NOT `heroicon-{value}`, and assuming so put 324 broken
     * names in the list.
     *
     * Filament's enum encodes outlined icons with an `o-` prefix and solid ones
     * with none, then `getIconForSize()` resolves a bare case to `heroicon-s-`,
     * `heroicon-m-` or `heroicon-c-` depending on how large it is being drawn.
     * The blade-icons set has all four. So an enum case is not a name on its
     * own — and this column holds a name, because `NavigationItem::icon()` takes
     * a string and nothing downstream knows a size. Outlined and solid are the
     * two that make sense at navigation size; mini and micro are for inline
     * glyphs, and the micro set is incomplete besides.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        $grouped = ['Outlined' => [], 'Solid' => []];

        foreach (Heroicon::cases() as $case) {
            $outlined = str_starts_with($case->value, 'o-');
            $bare = $outlined ? substr($case->value, 2) : $case->value;

            $grouped[$outlined ? 'Outlined' : 'Solid'][($outlined ? 'heroicon-o-' : 'heroicon-s-').$bare] = Str::headline($bare);
        }

        return $grouped;
    }
}
