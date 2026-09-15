<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Colors\ColorManager;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Filament\Avatars\InitialsAvatarProvider;
use Kitsune\Core\Filament\Panels\KitsunePanel;

/*
 * Avatars are drawn by the install, not fetched from another host.
 *
 * ⚠️ BOUND THE WAY FILAMENT BINDS IT, AND NO FURTHER. The provider reads the panel's gray from the color registry,
 * which `SupportServiceProvider` binds and Testbench does not boot.
 */
beforeEach(function (): void {
    app()->scoped(ColorManager::class, fn (): ColorManager => new ColorManager);
});

/** The SVG inside the data: URI, so the assertions read what a browser would draw. */
function drawnAvatarSvg(Model $record): string
{
    $uri = (new InitialsAvatarProvider)->get($record);
    $prefix = 'data:image/svg+xml;base64,';

    expect($uri)->toStartWith($prefix);

    return (string) base64_decode(substr($uri, strlen($prefix)), true);
}

function avatarRecordNamed(string $name): Model
{
    return new class(['name' => $name]) extends Model
    {
        protected $guarded = [];
    };
}

it('is the Kitsune panel\'s avatar provider, not Filament\'s ui-avatars.com default', function (): void {
    expect(KitsunePanel::apply(Panel::make())->getDefaultAvatarProvider())
        ->toBe(InitialsAvatarProvider::class)
        ->not->toBe(UiAvatarsProvider::class);
});

it('draws two initials on the panel\'s gray, as Filament\'s default looked', function (): void {
    // ⚠️ #09090b, NOT `Color::Gray[950]` (#030712). The registry's gray is not the constant named Gray, and the
    // ui-avatars.com URL the smoke test caught carried `background=%2309090b`.
    expect(drawnAvatarSvg(avatarRecordNamed('Alpha User')))
        ->toContain('>AU</text>')
        ->toContain('fill="#09090b"');
});

it('follows a gray the host registers, rather than a fixed one', function (): void {
    FilamentColor::register(['gray' => Color::Slate]);

    expect(drawnAvatarSvg(avatarRecordNamed('Alpha User')))
        ->toContain('fill="'.Color::convertToHex(Color::Slate[950]).'"')
        ->not->toContain('fill="#09090b"');
});

it('skips leading punctuation and stops at two initials', function (): void {
    expect(drawnAvatarSvg(avatarRecordNamed('[SYSTEM] Admin Account')))->toContain('>SA</text>');
});

it('keeps the letters of a script that has no case', function (): void {
    expect(drawnAvatarSvg(avatarRecordNamed('غولفدوم العربية')))->toContain('>غا</text>');
});

it('keeps a non-Latin initial whatever the host\'s internal encoding', function (): void {
    // ⚠️ `mb_*` read their input in `mb_internal_encoding()` unless told. Under ISO-8859-1 the Arabic initial was the
    // byte `d8`, which `htmlspecialchars(..., 'UTF-8')` then dropped — measured before the fix, found in review.
    $previous = mb_internal_encoding();
    mb_internal_encoding('ISO-8859-1');

    try {
        expect(drawnAvatarSvg(avatarRecordNamed('غولفدوم العربية')))->toContain('>غا</text>');
    } finally {
        mb_internal_encoding($previous);
    }
});

it('reads the name a HasName model gives Filament', function (): void {
    $site = new class extends Model implements HasName
    {
        public function getFilamentName(): string
        {
            return 'golfdom arabic';
        }
    };

    expect(drawnAvatarSvg($site))->toContain('>GA</text>');
});

it('draws well-formed SVG whatever the name holds', function (): void {
    // ⚠️ PARSED, NOT SEARCHED. A name the escaping missed would still contain the expected text and pass a
    // `toContain`; only a parser says whether a browser can draw the result.
    libxml_use_internal_errors(true);

    foreach (['Alpha User', '<script>', '& Co', '1" onload="x', '', '   ', "\xff\xfe"] as $name) {
        $document = new DOMDocument;

        expect($document->loadXML(drawnAvatarSvg(avatarRecordNamed($name))))
            ->toBeTrue('['.bin2hex($name).'] did not parse as XML');
    }
});
