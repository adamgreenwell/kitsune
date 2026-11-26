<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

use Illuminate\Support\Str;

/**
 * @internal
 *
 * @phpstan-type MenuLeaf array{label: string, target: string}
 * @phpstan-type MenuItem array{label: string, target: string, children: list<MenuLeaf>}
 */
final class SiteMenuItems
{
    public const LIMIT = 50;

    /** @return list<MenuItem>|null */
    public static function parse(mixed $items): ?array
    {
        if (! is_array($items) || ! array_is_list($items) || count($items) > self::LIMIT) {
            return null;
        }

        $menu = [];
        $total = count($items);
        foreach ($items as $raw) {
            $leaf = self::leaf($raw);
            if ($leaf === null) {
                return null;
            }

            $children = $raw['children'] ?? [];
            if (! is_array($children) || ! array_is_list($children) || ($total += count($children)) > self::LIMIT) {
                return null;
            }

            $links = [];
            foreach ($children as $child) {
                $link = self::leaf($child);
                if ($link === null || ! empty($child['children'])) {
                    return null;
                }

                $links[] = $link;
            }
            $menu[] = $leaf + ['children' => $links];
        }

        return $menu;
    }

    /**
     * @param  list<MenuItem>  $items
     * @return list<string>
     */
    public static function targets(array $items): array
    {
        $targets = [];
        foreach ($items as $item) {
            $targets[] = $item['target'];
            foreach ($item['children'] as $child) {
                $targets[] = $child['target'];
            }
        }

        return $targets;
    }

    /** @return MenuLeaf|null */
    private static function leaf(mixed $item): ?array
    {
        if (! is_array($item) || ! is_string($item['label'] ?? null) || ! is_string($item['target'] ?? null)) {
            return null;
        }

        $label = trim($item['label']);
        $target = $item['target'];
        if ($label === '' || mb_strlen($label) > 120) {
            return null;
        }

        if (str_starts_with($target, 'group:') && Str::isUuid(substr($target, 6))) {
            $target = strtolower($target);
        } elseif ($target !== 'home' && ! self::validEntryTarget($target)) {
            return null;
        }

        return ['label' => $label, 'target' => $target];
    }

    private static function validEntryTarget(string $target): bool
    {
        if (preg_match('/^entry:([1-9][0-9]*)$/D', $target, $parts) !== 1) {
            return false;
        }

        return (string) (int) $parts[1] === $parts[1];
    }
}
