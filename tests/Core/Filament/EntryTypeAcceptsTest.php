<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Media\MediaFormats;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;

/*
 * The entry type form's *Accepts* — ADR-042 decision 33: the formats a media type names, between `settings.accepts` and
 * the form. The page itself, its list shown only for a media type, is `entity-type-builder.spec.js`'s.
 */

beforeEach(function (): void {
    $this->org = Org::create(['slug' => 'accepts-form', 'name' => 'Accepts form']);
    app(Context::class)->setOrg($this->org);

    $this->type = fn (?array $settings, bool $media = true, string $handle = 'image'): EntryType => EntryType::create([
        'org_id' => $this->org->id, 'handle' => $handle, 'name' => ucfirst($handle), 'plural_name' => ucfirst($handle).'s', 'is_media' => $media, 'settings' => $settings,
    ]);
});

afterEach(fn () => app(Context::class)->forget());

describe('filled from the type', function (): void {
    it('ticks the formats the type names, and every one where it names none', function (?array $settings, array $ticked): void {
        expect(EntryTypeResource::acceptsFromSettings(['name' => 'Image'], ($this->type)($settings)))
            ->toBe(['name' => 'Image', 'media_accepts' => $ticked]);
    })->with([
        'naming some' => [[MediaFormats::SETTING => ['png', 'pdf']], ['png', 'pdf']],
        'naming none' => [null, array_keys(MediaFormats::ALL)],
        'with other settings, naming none' => [['revisions' => true], array_keys(MediaFormats::ALL)],
    ]);

    /* A list written past the model shows nothing ticked, so a save asks for a choice rather than keeping it. */
    it('ticks none where the list cannot be read', function (): void {
        $type = ($this->type)(null);
        DB::table('entry_types')->where('id', $type->id)->update(['settings' => json_encode([MediaFormats::SETTING => ['jpg']])]);

        expect(EntryTypeResource::acceptsFromSettings([], $type->fresh()))->toBe(['media_accepts' => []]);
    });

    it('adds nothing for a type that holds no media', function (): void {
        expect(EntryTypeResource::acceptsFromSettings(['name' => 'Article'], ($this->type)(null, media: false)))->toBe(['name' => 'Article']);
    });
});

describe('saved into the type', function (): void {
    it('names the formats ticked, in their own order, and keeps the rest of the settings', function (): void {
        $data = EntryTypeResource::acceptsIntoSettings(['name' => 'Image', 'media_accepts' => ['pdf', 'png']], ($this->type)(['revisions' => true]));

        expect($data)->toBe(['name' => 'Image', 'settings' => ['revisions' => true, MediaFormats::SETTING => ['png', 'pdf']]]);
    });

    /* Every format ticked is the key left out, which accepts every format, one added later included. */
    it('leaves the key out where every format is ticked, and the settings null where nothing else is in them', function (): void {
        $all = array_keys(MediaFormats::ALL);

        expect(EntryTypeResource::acceptsIntoSettings(['media_accepts' => $all], ($this->type)([MediaFormats::SETTING => ['png']])))
            ->toBe(['settings' => null])
            ->and(EntryTypeResource::acceptsIntoSettings(['media_accepts' => array_reverse($all)], ($this->type)(['revisions' => true, MediaFormats::SETTING => ['png']], handle: 'photo')))
            ->toBe(['settings' => ['revisions' => true]]);
    });

    it('names the formats ticked on a type being created', function (): void {
        expect(EntryTypeResource::acceptsIntoSettings(['handle' => 'docs', 'media_accepts' => ['pdf']], null))
            ->toBe(['handle' => 'docs', 'settings' => [MediaFormats::SETTING => ['pdf']]]);
    });

    /* No list shown — a type that holds no media — and nothing about settings is sent, so nothing changes. */
    it('changes nothing where the form showed no list', function (): void {
        expect(EntryTypeResource::acceptsIntoSettings(['name' => 'Article'], ($this->type)(['revisions' => true], media: false)))
            ->toBe(['name' => 'Article']);
    });

    /* Something that is not a format is dropped, not stored for the model to refuse. */
    it('keeps only formats', function (): void {
        expect(EntryTypeResource::acceptsIntoSettings(['media_accepts' => ['png', 'php']], null))
            ->toBe(['settings' => [MediaFormats::SETTING => ['png']]]);
    });
});
