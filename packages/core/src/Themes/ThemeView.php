<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

use Closure;
use Illuminate\Support\HtmlString;

/**
 * @internal Candidate for the versioned Blade contract.
 *
 * @phpstan-import-type PublicItem from SiteMenus
 *
 * @phpstan-type MenuLeaf array{label: string, path: string, children: array{}}
 * @phpstan-type MenuItem array{label: string, path: string, children: list<MenuLeaf>}
 */
final readonly class ThemeView
{
    /**
     * @param  array{title: ?string, type: string, path: string}|null  $entry
     * @param  array<string, mixed>  $hostData
     * @param  array<string, list<array{title: ?string, path: string}>>  $related
     * @param  Closure(string): list<PublicItem>  $menus
     * @param  array<string, array{type: string, multiple: bool, value: mixed}>  $fields
     * @param  Closure(string): ?HtmlString  $renderRichText
     */
    public function __construct(
        public string $siteName,
        public string $homePath,
        public string $locale,
        public string $direction,
        public ?array $entry,
        public array $hostData,
        private array $related,
        private Closure $menus,
        private array $fields,
        private Closure $renderRichText,
    ) {}

    /** @return list<array{title: ?string, path: string}> */
    public function related(string $handle): array
    {
        return $this->related[$handle] ?? [];
    }

    /** @return list<MenuItem> */
    public function menu(string $handle): array
    {
        return array_map(static function (array $link): array {
            $link['children'] = array_map(
                static fn (array $child): array => $child + ['children' => []],
                $link['children'],
            );

            return $link;
        }, ($this->menus)($handle));
    }

    /** @return array{type: string, multiple: bool, value: mixed}|null */
    public function field(string $handle): ?array
    {
        return $this->fields[$handle] ?? null;
    }

    public function richText(string $handle): ?HtmlString
    {
        return ($this->renderRichText)($handle);
    }
}
