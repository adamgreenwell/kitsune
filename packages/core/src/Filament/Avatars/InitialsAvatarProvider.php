<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Avatars;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Models\Contracts\HasName;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Database\Eloquent\Model;

/**
 * An avatar drawn from a name's initials, on the server, as an inline SVG.
 *
 * ⚠️ FILAMENT'S DEFAULT ASKS ANOTHER HOST FOR IT. `UiAvatarsProvider` returns a ui-avatars.com URL built from the
 * signed-in user's initials and the site's, so every admin page sent both to a third party (ADR-020), and a host with
 * no outbound network — ADR-027's floor — rendered two broken images. A data: URI never leaves the page. Found by the
 * alpha's local smoke test, in the network log.
 *
 * The look is Filament's: up to two white initials on the panel's gray 950, with leading punctuation skipped, so
 * "[SYSTEM] Admin" reads "SA".
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $background = Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.$background.'"/>'
            .'<text x="32" y="32" dominant-baseline="central" text-anchor="middle" fill="#FFFFFF"'
            .' font-family="ui-sans-serif, system-ui, sans-serif" font-size="26" font-weight="500">'
            .htmlspecialchars(self::initials($record), ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The first letter of the first two words, uppercased where the script has case.
     *
     * ⚠️ THE NAME IS READ AS FILAMENT READS IT, WITHOUT ASKING WHICH PANEL IS CURRENT. Filament's
     * `getNameForDefaultAvatar()` checks the record against the current panel's tenant model only to choose between
     * `getTenantName()` and `getUserName()`, and both answer `HasName` first and the `name` attribute otherwise.
     */
    private static function initials(Model $record): string
    {
        $name = $record instanceof HasName ? $record->getFilamentName() : (string) $record->getAttributeValue('name');

        $letters = [];

        // `?: []` because `preg_split()` returns false on a name that is not valid UTF-8, which then draws no initials.
        foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $word = (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word);

            if ($word !== '') {
                $letters[] = mb_strtoupper(mb_substr($word, 0, 1));
            }

            if (count($letters) === 2) {
                break;
            }
        }

        return implode('', $letters);
    }
}
