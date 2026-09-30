<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaFormats;
use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * Each media type names the files it accepts — ADR-042 decision 33.
 *
 * ⚠️ FROM WHAT IS STORED AFTERWARDS. A refusal is asserted with the disks and the tables it leaves, so a file that was
 * refused for another reason, or refused and written anyway, cannot pass for the type's refusal; and every refusal has
 * the control a type naming none of it, which stores the same file.
 */

const ACCEPTS_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

const ACCEPTS_PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);

    $this->org = Org::create(['slug' => 'accepts', 'name' => 'Accepts']);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'accepts-main', 'name' => 'Main', 'locale' => 'en']));

    $this->mediaType = fn (?array $accepts = null, string $handle = 'image'): EntryType => EntryType::create([
        'org_id' => $this->org->id, 'handle' => $handle, 'name' => ucfirst($handle), 'plural_name' => ucfirst($handle).'s', 'is_media' => true,
        'settings' => $accepts === null ? null : [MediaFormats::SETTING => $accepts],
    ]);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach (glob(sys_get_temp_dir().'/kitsune-accepts-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

/** Store bytes under a name, as an upload would. */
function storedAs(string $bytes, string $name, EntryType $type): void
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-accepts-');
    file_put_contents($source, $bytes);

    MediaLibrary::store($source, $name, $type);
}

/** Nothing stored: no row, and no byte on either media disk. */
function nothingStored(): bool
{
    return MediaFile::query()->count() === 0
        && DB::table('entries')->count() === 0
        && Storage::disk(MediaDisks::PRIVATE)->allFiles() === []
        && Storage::disk('public')->allFiles() === [];
}

describe('the formats', function (): void {
    /* Every extension `MediaIntake` stores is one format's, and no format names one it does not: the two lists together. */
    it('give every extension MediaIntake accepts exactly one format, and no other', function (): void {
        $named = array_merge(...array_map(static fn (array $format): array => $format['extensions'], array_values(MediaFormats::ALL)));

        expect($named)->toEqualCanonicalizing(array_keys([...MediaIntake::ACCEPTED, ...MediaIntake::GUARDED]))
            ->and(count($named))->toBe(count(array_unique($named)));

        foreach ($named as $extension) {
            expect(MediaFormats::ALL[MediaFormats::of($extension)]['extensions'])->toContain($extension);
        }

        expect(MediaFormats::of('jpg'))->toBe('jpeg')
            ->and(MediaFormats::of('jpeg'))->toBe('jpeg')
            ->and(MediaFormats::of('php'))->toBeNull();
    });

    it('call images only what MediaIntake accepts as images', function (): void {
        foreach (MediaFormats::IMAGES as $format) {
            foreach (MediaFormats::ALL[$format]['extensions'] as $extension) {
                expect([...MediaIntake::ACCEPTED, ...MediaIntake::GUARDED][$extension][0])->toStartWith('image/');
            }
        }

        // The control: every image MediaIntake accepts is among them.
        foreach ([...MediaIntake::ACCEPTED, ...MediaIntake::GUARDED] as $extension => $mimes) {
            expect(in_array(MediaFormats::of($extension), MediaFormats::IMAGES, true))->toBe(str_starts_with($mimes[0], 'image/'));
        }
    });

    it('are named in a sentence in their own order, and told to the browser by name', function (): void {
        expect(MediaFormats::sentence(['svg', 'jpeg', 'png']))->toBe('JPEG, PNG and SVG')
            ->and(MediaFormats::sentence(['pdf']))->toBe('PDF')
            ->and(MediaFormats::browserTypes(['jpeg', 'csv', 'txt']))->toBe(['image/jpeg', 'text/csv', 'text/plain'])
            ->and(array_keys(MediaFormats::browserMap()))->toEqualCanonicalizing(array_keys([...MediaIntake::ACCEPTED, ...MediaIntake::GUARDED]))
            ->and(MediaFormats::browserMap()['jpeg'])->toBe('image/jpeg')
            ->and(MediaFormats::browserMap()['csv'])->toBe('text/csv');
    });

    /* A list the type could not have saved is not taken to mean every format: it fails closed, naming the type. */
    it('are read from settings as named, none where none are, and refused where they cannot be read', function (mixed $accepts, ?string $problem): void {
        $settings = $accepts === 'absent' ? ['other' => true] : [MediaFormats::SETTING => $accepts];

        $problem === null
            ? expect(MediaFormats::namedBy($settings, 'image'))->toBe($accepts === 'absent' || $accepts === null ? null : $accepts)
            : expect(fn () => MediaFormats::namedBy($settings, 'image'))->toThrow(RuntimeException::class, "Entry type [image] names the files it accepts as {$problem}");
    })->with([
        'absent' => ['absent', null],
        'null' => [null, null],
        'formats' => [['jpeg', 'png'], null],
        'none' => [[], 'something other than a list of formats'],
        'not a list' => ['jpeg', 'something other than a list of formats'],
        'keyed' => [['a' => 'jpeg'], 'something other than a list of formats'],
        'an extension, not a format' => [['jpg'], 'a list holding [jpg], which is not a format'],
        'not a string' => [[7], 'a list holding [7], which is not a format'],
        'twice' => [['png', 'png'], 'a list naming a format twice'],
    ]);

    it('read no settings at all as none named', function (): void {
        expect(MediaFormats::namedBy(null, 'image'))->toBeNull()
            ->and(MediaFormats::namedBy('not settings', 'image'))->toBeNull();
    });
});

describe('a type naming them', function (): void {
    it('saves a media type naming formats, or none', function (): void {
        $images = ($this->mediaType)(MediaFormats::IMAGES);
        $any = ($this->mediaType)(null, 'files');

        expect($images->fresh()->settings)->toBe([MediaFormats::SETTING => MediaFormats::IMAGES])
            ->and($any->fresh()->settings)->toBeNull();
    });

    it('refuses a list the formats cannot read, on create and on update, in the type\'s own words', function (): void {
        expect(fn () => ($this->mediaType)(['jpg']))
            ->toThrow(RuntimeException::class, 'Entry type [image] cannot name the files it accepts as a list holding [jpg], which is not a format. Name one or more of: jpeg, png');

        $type = ($this->mediaType)(['png'], 'files');
        $type->settings = [MediaFormats::SETTING => []];

        expect(fn () => $type->save())->toThrow(RuntimeException::class, 'Entry type [files] cannot name the files it accepts as something other than a list');
        expect($type->fresh()->settings)->toBe([MediaFormats::SETTING => ['png']]);
    });

    it('refuses a list on a type that holds no media, where one naming none saves', function (): void {
        expect(fn () => EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles', 'settings' => [MediaFormats::SETTING => ['png']]]))
            ->toThrow(RuntimeException::class, 'Entry type [article] cannot name the files it accepts: it is not a media type');

        // The control: other settings on such a type save as they did.
        expect(EntryType::create(['org_id' => $this->org->id, 'handle' => 'post', 'name' => 'Post', 'plural_name' => 'Posts', 'settings' => ['revisions' => true]])->fresh()->settings)
            ->toBe(['revisions' => true]);
    });
});

describe('storing into a type naming them', function (): void {
    it('refuses a format the type does not name, naming what it takes, and stores nothing', function (): void {
        $type = ($this->mediaType)(['jpeg', 'png']);

        expect(fn () => storedAs(ACCEPTS_PDF, 'rules.pdf', $type))
            ->toThrow(MediaRefused::class, 'Refusing [rules.pdf]: [Image] takes only JPEG and PNG files, and this file\'s format is PDF. Nothing was stored.');

        expect(nothingStored())->toBeTrue();
    });

    it('stores a format it names', function (): void {
        storedAs(ACCEPTS_PNG, 'photo.png', ($this->mediaType)(['png']));

        expect(MediaFile::query()->value('mime'))->toBe('image/png');
    });

    // The control for the refusal above: the same file into a type that names none is stored, as before decision 33.
    it('stores every format into a type naming none', function (): void {
        storedAs(ACCEPTS_PDF, 'rules.pdf', ($this->mediaType)());

        expect(MediaFile::query()->value('mime'))->toBe('application/pdf');
    });

    // MediaIntake refuses first, so a file whose bytes are not its name is refused for that, never for the type.
    it('leaves MediaIntake\'s refusal first', function (): void {
        expect(fn () => storedAs('just some text', 'photo.pdf', ($this->mediaType)(['png'])))
            ->toThrow(MediaRefused::class, 'it is named .pdf but its contents are [text/plain]');
    });

    /* Asked of the stored type, as the flag is: an instance's settings are an attribute anybody can set. */
    it('asks the stored settings, not the instance handed in', function (): void {
        $narrow = ($this->mediaType)(['png']);
        $narrow->settings = null;

        expect(fn () => storedAs(ACCEPTS_PDF, 'rules.pdf', $narrow))->toThrow(MediaRefused::class, 'takes only PNG files');

        $wide = ($this->mediaType)(null, 'files');
        $wide->settings = [MediaFormats::SETTING => ['png']];
        storedAs(ACCEPTS_PDF, 'rules.pdf', $wide);

        expect(MediaFile::query()->count())->toBe(1);
    });

    /* A list written past the model, which the type could not have saved, fails closed: not an uploader's words. */
    it('refuses every file into a type whose list cannot be read, and stores nothing', function (): void {
        $type = ($this->mediaType)(['png']);
        DB::table('entry_types')->where('id', $type->id)->update(['settings' => json_encode([MediaFormats::SETTING => 'png'])]);

        expect(fn () => storedAs(ACCEPTS_PNG, 'photo.png', $type))->toThrow(function (RuntimeException $failure): void {
            expect($failure)->not->toBeInstanceOf(MediaRefused::class)
                ->and($failure->getMessage())->toContain('Entry type [image] names the files it accepts as something other than a list');
        });

        expect(nothingStored())->toBeTrue();
    });
});

describe('the migration naming the image type\'s', function (): void {
    /** The migration, as a fresh instance: `require` rather than `require_once`, because the file returns one. */
    function acceptsMigration(): object
    {
        return require __DIR__.'/../../../packages/core/database/migrations/0001_01_01_000011_name_the_files_the_image_type_accepts.php';
    }

    function settingsOf(int $id): mixed
    {
        $settings = DB::table('entry_types')->where('id', $id)->value('settings');

        return is_string($settings) ? json_decode($settings, true) : $settings;
    }

    it('names images for the global image media type alone, and only where it names nothing yet', function (): void {
        $global = EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true, 'settings' => ['keep' => 1]]);
        $orgs = ($this->mediaType)();
        $chosen = EntryType::create(['org_id' => null, 'handle' => 'photos', 'name' => 'Photos', 'plural_name' => 'Photos', 'is_media' => true]);
        $notMedia = EntryType::create(['org_id' => null, 'handle' => 'gallery', 'name' => 'Gallery', 'plural_name' => 'Galleries']);

        acceptsMigration()->up();

        expect(settingsOf($global->id))->toBe(['keep' => 1, MediaFormats::SETTING => MediaFormats::IMAGES])
            ->and(settingsOf($orgs->id))->toBeNull()
            ->and(settingsOf($chosen->id))->toBeNull()
            ->and(settingsOf($notMedia->id))->toBeNull();

        // Run again, it changes nothing.
        acceptsMigration()->up();
        expect(settingsOf($global->id))->toBe(['keep' => 1, MediaFormats::SETTING => MediaFormats::IMAGES]);
    });

    it('leaves a global image type that holds no media as it is', function (): void {
        $type = EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images']);

        acceptsMigration()->up();

        expect(settingsOf($type->id))->toBeNull();
    });

    it('leaves a list already chosen as it is, on the way up and the way down', function (): void {
        $global = EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true, 'settings' => [MediaFormats::SETTING => ['png']]]);

        acceptsMigration()->up();
        expect(settingsOf($global->id))->toBe([MediaFormats::SETTING => ['png']]);

        acceptsMigration()->down();
        expect(settingsOf($global->id))->toBe([MediaFormats::SETTING => ['png']]);
    });

    it('writes images to a global image type with no settings', function (): void {
        $global = EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

        acceptsMigration()->up();

        expect(settingsOf($global->id))->toBe([MediaFormats::SETTING => MediaFormats::IMAGES]);
    });

    /*
     * ⚠️ DOWN CHANGES NOTHING — review of #162. The images alone, chosen before the migration ran, read as the list it
     * would have written: taking back "its own" took an operator's choice, and widened their type to every format.
     */
    it('changes nothing on the way down, a list chosen before it ran as the images alone included', function (): void {
        $chosen = EntryType::create(['org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true, 'settings' => [MediaFormats::SETTING => MediaFormats::IMAGES]]);

        acceptsMigration()->up();
        acceptsMigration()->down();

        expect(settingsOf($chosen->id))->toBe([MediaFormats::SETTING => MediaFormats::IMAGES]);
    });
});
