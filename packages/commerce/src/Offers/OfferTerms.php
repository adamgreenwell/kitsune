<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Offers;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Kitsune\Core\Entitlements\EntitlementName;
use RuntimeException;
use Throwable;

/** Version one: one purchase, permanent or whole UTC days from its first durable payment confirmation. @internal */
final class OfferTerms
{
    public const MAX_DAYS = 36500;

    /** Null remains unknown, never an alias for permanent access.
     * @return array{version:1,kind:'permanent'}|array{version:1,kind:'fixed',days:int}|null
     */
    public static function normalize(#[\SensitiveParameter] mixed $terms): ?array
    {
        if ($terms === null) {
            return null;
        }
        if (! is_array($terms) || array_is_list($terms) || ($terms['version'] ?? null) !== 1) {
            throw new RuntimeException('The purchased access terms are incompatible.');
        }
        $kind = $terms['kind'] ?? null;
        $keys = array_keys($terms);
        sort($keys, SORT_STRING);
        if ($kind === 'permanent' && $keys === ['kind', 'version']) {
            return ['version' => 1, 'kind' => 'permanent'];
        }
        if ($kind === 'fixed' && $keys === ['days', 'kind', 'version'] && is_int($terms['days'])
            && $terms['days'] >= 1 && $terms['days'] <= self::MAX_DAYS) {
            return ['version' => 1, 'kind' => 'fixed', 'days' => $terms['days']];
        }

        throw new RuntimeException('The purchased access terms are incompatible.');
    }

    /** Decode only SQL's JSON spelling; JSON null, arrays and coerced scalar values are not configured offers.
     * @return array{version:1,kind:'permanent'}|array{version:1,kind:'fixed',days:int}|null
     */
    public static function stored(#[\SensitiveParameter] mixed $terms): ?array
    {
        if ($terms === null) {
            return null;
        }
        if (! is_string($terms) || strlen($terms) > 512) {
            throw new RuntimeException('The retained purchased access terms are incompatible.');
        }
        try {
            $decoded = json_decode($terms, true, 8, JSON_THROW_ON_ERROR);
            if ($decoded === null) {
                throw new RuntimeException;
            }

            return self::normalize($decoded);
        } catch (Throwable) {
            throw new RuntimeException('The retained purchased access terms are incompatible.');
        }
    }

    /** A named null offer is a retained unknown; a configured offer always names its access.
     * @return array{version:1,kind:'permanent'}|array{version:1,kind:'fixed',days:int}|null
     */
    public static function forMapping(#[\SensitiveParameter] mixed $name, #[\SensitiveParameter] mixed $terms): ?array
    {
        if ($name !== null && (! is_string($name) || ! EntitlementName::isName($name))) {
            throw new RuntimeException('The purchased access mapping is incompatible.');
        }
        $terms = self::normalize($terms);
        if ($name === null && $terms !== null) {
            throw new RuntimeException('Purchased access terms require an entitlement name.');
        }

        return $terms;
    }

    /** A new buyer must never be charged for mapped access whose duration was not explicitly configured.
     * @return array{version:1,kind:'permanent'}|array{version:1,kind:'fixed',days:int}|null
     */
    public static function purchasable(#[\SensitiveParameter] mixed $name, #[\SensitiveParameter] mixed $terms): ?array
    {
        $terms = self::forMapping($name, $terms);
        if ($name !== null && $terms === null) {
            throw new RuntimeException('Configure the purchased access terms before preparing this order.');
        }

        return $terms;
    }

    /** Resolve once, before granting. Days are 24 UTC hours, and recovery never starts this clock again.
     * @param  array<string,mixed>  $terms
     */
    public static function resolve(#[\SensitiveParameter] array $terms, DateTimeInterface $confirmedAt): ?CarbonImmutable
    {
        $terms = self::normalize($terms) ?? throw new RuntimeException('Purchased access terms are required.');
        $start = CarbonImmutable::instance($confirmedAt)->utc()->startOfSecond();
        if ($start->year < 1 || $start->year > 9999) {
            throw new RuntimeException('The purchased access start is outside the supported range.');
        }
        if ($terms['kind'] === 'permanent') {
            return null;
        }
        $end = $start->addDays($terms['days']);
        if ($end->year > 9999) {
            throw new RuntimeException('The purchased access end is outside the supported range.');
        }

        return $end;
    }

    /** A safe human projection; the entitlement name itself need never appear on a public page.
     * @param  array<string,mixed>|null  $terms
     */
    public static function label(#[\SensitiveParameter] ?array $terms, bool $mapped = false): ?string
    {
        $terms = self::normalize($terms);
        if ($terms === null) {
            return $mapped ? __('commerce::catalogue.offer_unknown') : null;
        }

        return $terms['kind'] === 'permanent' ? __('commerce::catalogue.offer_permanent')
            : __('commerce::catalogue.offer_fixed', ['days' => $terms['days']]);
    }
}
