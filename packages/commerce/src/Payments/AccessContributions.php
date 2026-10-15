<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Kitsune\Commerce\Offers\OfferTerms;

/** Recompute only surviving original offers; quantities never buy extra time. @internal */
final class AccessContributions
{
    /** The caller first proves the immutable access manifest and financial unit attribution under the same locks.
     * Unknown historical terms remain held even if a financial adjustment removes their supporting units.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<int|string,int>  $removedUnits  Original line ID to suspended/refunded awarded unit count.
     * @return list<array{entitlement:string,source:string,expires_at:?string,known:bool,remaining:bool,suspended:bool}>
     */
    public static function project(#[\SensitiveParameter] array $manifest, array $removedUnits, bool $blocked): array
    {
        if (($manifest['version'] ?? null) !== 1 || ! is_string($manifest['confirmed_at'] ?? null)
            || ! is_array($manifest['definitions'] ?? null) || ! is_array($manifest['lines'] ?? null)
            || ! is_array($manifest['allocations'] ?? null) || count($manifest['definitions']) > 20
            || count($manifest['lines']) > 20 || count($manifest['allocations']) > 20 || count($removedUnits) > 20) {
            throw new CheckoutRefused('changed');
        }
        $start = CheckoutAttempts::instant($manifest['confirmed_at']);
        $definitions = [];
        foreach ($manifest['definitions'] as $definition) {
            if (! is_array($definition) || ! is_string($definition['entitlement'] ?? null)
                || ! is_string($definition['source'] ?? null) || ! is_bool($definition['known'] ?? null)
                || ! array_key_exists('expires_at', $definition)
                || ($definition['expires_at'] !== null && ! is_string($definition['expires_at']))
                || isset($definitions[$definition['entitlement']])) {
                throw new CheckoutRefused('changed');
            }
            $definitions[$definition['entitlement']] = ['entitlement' => $definition['entitlement'],
                'source' => $definition['source'], 'expires_at' => $definition['expires_at'], 'known' => $definition['known'],
                'remaining' => false, 'suspended' => true];
        }
        $awarded = [];
        foreach ($manifest['allocations'] as $allocation) {
            if (! is_array($allocation)) {
                throw new CheckoutRefused('changed');
            }
            $id = self::id($allocation['line_id'] ?? null);
            if (array_key_exists($id, $awarded)) {
                throw new CheckoutRefused('changed');
            }
            $awarded[$id] = self::quantity($allocation['awarded'] ?? null);
        }
        foreach ($removedUnits as $id => $quantity) {
            $id = self::id($id);
            $quantity = self::removedQuantity($quantity);
            if (! array_key_exists($id, $awarded) || $quantity > $awarded[$id]) {
                throw new CheckoutRefused('changed');
            }
        }
        $seen = [];
        foreach ($manifest['lines'] as $line) {
            if (! is_array($line)) {
                throw new CheckoutRefused('changed');
            }
            $id = self::id($line['id'] ?? null);
            if (isset($seen[$id]) || ! array_key_exists($id, $awarded)) {
                throw new CheckoutRefused('changed');
            }
            $seen[$id] = true;
            $remaining = $awarded[$id] - ($removedUnits[$id] ?? 0);
            $name = $line['entitlement_name'] ?? null;
            if ($awarded[$id] === 0 || $name === null) {
                continue;
            }
            if (! is_string($name) || ! isset($definitions[$name])) {
                throw new CheckoutRefused('changed');
            }
            $terms = OfferTerms::forMapping($name, OfferTerms::stored($line['offer_terms'] ?? null));
            if ($remaining === 0) {
                continue;
            }
            $end = $terms === null ? null : OfferTerms::resolve($terms, $start)?->format('Y-m-d H:i:s');
            $first = ! $definitions[$name]['remaining'];
            $old = $definitions[$name]['expires_at'];
            $definitions[$name]['expires_at'] = $first ? $end : ($old === null || $end === null ? null : max($old, $end));
            $definitions[$name]['known'] = $definitions[$name]['known'] && $terms !== null;
            $definitions[$name]['remaining'] = true;
        }
        if (count($seen) !== count($awarded)) {
            throw new CheckoutRefused('changed');
        }
        foreach ($definitions as &$definition) {
            $definition['suspended'] = $blocked || ! $definition['remaining'] || ! $definition['known'];
        }
        unset($definition);

        return array_values($definitions);
    }

    private static function id(mixed $value): string
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $value) !== 1) {
            throw new CheckoutRefused('changed');
        }

        return (string) $value;
    }

    private static function quantity(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A(?:0|[1-9][0-9]?)\z/D', (string) $value) !== 1) {
            throw new CheckoutRefused('changed');
        }

        return (int) $value;
    }

    private static function removedQuantity(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 99) {
            throw new CheckoutRefused('changed');
        }

        return $value;
    }
}
