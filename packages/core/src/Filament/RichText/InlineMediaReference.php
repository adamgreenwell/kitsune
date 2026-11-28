<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\RichText;

use stdClass;
use Tiptap\Core\Node;

/** @internal Keeps an existing media reference through editor hydration and saving. */
final class InlineMediaReference extends Node
{
    public const ASSET = 'kitsune-rich-editor-inline-media';

    /** @var string */
    public static $name = 'kitsuneInlineMedia';

    /** @return list<array{tag: string}> */
    public function parseHTML(): array
    {
        return [['tag' => 'img[data-kitsune-media]']];
    }

    /** @return array<string, array<string, mixed>> */
    public function addAttributes(): array
    {
        return [
            'mediaId' => [
                'parseHTML' => fn ($element) => $element->getAttribute('data-kitsune-media'),
                'renderHTML' => fn ($attributes): array => ['data-kitsune-media' => $attributes->mediaId ?? null],
            ],
            'alt' => [],
        ];
    }

    /**
     * @param  stdClass  $node
     * @param  array<string, mixed>  $HTMLAttributes
     * @return array{string, array<string, mixed>, int}
     */
    public function renderHTML($node, $HTMLAttributes = []): array
    {
        return ['img', $HTMLAttributes, 0];
    }
}
