<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * Storing a file — ADR-016's entry-plus-bytes, with ADR-041's visibility.
 *
 * ⚠️ BYTES FIRST, ROWS SECOND. Neither order is free of failure, so the question is which residue is
 * recoverable: rows first leaves an entry pointing at a file that does not exist, which an operator sees and
 * cannot explain; bytes first leaves an unreferenced file, which costs disk and which a sweep can find.
 */

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');

    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);

    /* ADR-016's system media type. Org-owned here, because ADR-039 forbids a blueprint creating global rows. */
    $this->imageType = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images',
    ]);
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach (glob(sys_get_temp_dir().'/kitsune-lib-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

function aPng(): string
{
    $path = tempnam(sys_get_temp_dir(), 'kitsune-lib-');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    return $path;
}

it('stores the bytes and creates the entry and its row', function (): void {
    $source = aPng();

    $entry = MediaLibrary::store($source, 'Company Logo.png', $this->imageType);

    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    expect($entry->title)->toBe('Company Logo')
        ->and($media->mime)->toBe('image/png')
        ->and($media->size_bytes)->toBe(filesize($source))
        ->and($media->checksum)->toBe(hash_file('sha256', $source));

    Storage::disk($media->disk)->assertExists($media->path);
});

/** ADR-041: private is the default, and public is an explicit act. */
it('stores privately by default, on the disk the web server does not serve', function (): void {
    $entry = MediaLibrary::store(aPng(), 'secret.png', $this->imageType);

    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    expect($media->visibility)->toBe('private')
        ->and($media->isPublic())->toBeFalse()
        ->and($media->disk)->toBe('local');

    Storage::disk('public')->assertDirectoryEmpty('media');
});

it('stores publicly when asked, on the linked disk', function (): void {
    $entry = MediaLibrary::store(aPng(), 'logo.png', $this->imageType, 'public');

    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    expect($media->visibility)->toBe('public')->and($media->disk)->toBe('public');

    Storage::disk('public')->assertExists($media->path);
});

it('refuses a visibility it does not recognise, rather than resolving it', function (): void {
    expect(fn () => MediaLibrary::store(aPng(), 'logo.png', $this->imageType, 'unlisted'))
        ->toThrow(RuntimeException::class, 'Refusing to store media with visibility');

    expect(DB::table('media_files')->count())->toBe(0);
});

/** An entry write with no org is refused rather than merely unaudited (ADR-020). */
it('refuses to store with no organisation in context', function (): void {
    app(Context::class)->forget();

    expect(fn () => MediaLibrary::store(aPng(), 'logo.png', $this->imageType))
        ->toThrow(RuntimeException::class, 'no organisation in context');

    expect(DB::table('entries')->count())->toBe(0);
});

it('reads dimensions from an image and leaves duration null', function (): void {
    $entry = MediaLibrary::store(aPng(), 'tiny.png', $this->imageType);

    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    expect($media->width)->toBe(1)->and($media->height)->toBe(1)
        /* ADR-041: null in v1.0, so a later change to populate it is a visible decision rather than drift. */
        ->and($media->duration_ms)->toBeNull();
});

/**
 * ⚠️ THE CALLER'S NAME REACHES THE TITLE AND NOTHING ELSE. It never becomes a path, which is the whole point
 * of `MediaIntake::storedName()` — asserted here from the storage side rather than the guard's.
 */
it('never lets the original name reach the stored path', function (): void {
    $entry = MediaLibrary::store(aPng(), '../../../etc/passwd.png', $this->imageType);

    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    expect($media->path)->toStartWith('media/'.$this->org->getKey().'/')
        ->and($media->path)->not->toContain('..')
        ->and($media->path)->not->toContain('passwd');
});

it('fans paths out by org and date, so no directory holds everything', function (): void {
    $entry = MediaLibrary::store(aPng(), 'a.png', $this->imageType);

    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    expect($media->path)->toMatch('#^media/'.$this->org->getKey().'/\d{4}/\d{2}/[0-9a-f]{32}\.png$#');
});

/** Two uploads of one filename must not collide on `UNIQUE (site_id, entry_type_id, slug)`. */
it('stores the same filename twice without colliding', function (): void {
    $first = MediaLibrary::store(aPng(), 'logo.png', $this->imageType);
    $second = MediaLibrary::store(aPng(), 'logo.png', $this->imageType);

    expect($first->slug)->not->toBe($second->slug)
        ->and(MediaFile::query()->count())->toBe(2);

    $paths = MediaFile::query()->pluck('path')->all();

    expect(array_unique($paths))->toHaveCount(2);
});

/**
 * ⚠️ A FAILED STORE LEAVES NO BYTES, which is the whole reason bytes are written before rows rather than
 * after. The failure is injected because no input can reach it: the refusals run before anything is written,
 * so the only way to fail *after* the write is for the row write to fail.
 */
it('removes the bytes it wrote when the rows cannot be committed', function (): void {
    Entry::creating(function (): void {
        throw new RuntimeException('row write failed, for the sake of argument');
    });

    expect(fn () => MediaLibrary::store(aPng(), 'doomed.png', $this->imageType))
        ->toThrow(RuntimeException::class, 'for the sake of argument');

    expect(DB::table('entries')->count())->toBe(0)
        ->and(DB::table('media_files')->count())->toBe(0);

    /* Nothing left on either disk — the bytes this call wrote are gone. */
    expect(Storage::disk('local')->allFiles('media'))->toBe([])
        ->and(Storage::disk('public')->allFiles('media'))->toBe([]);
});

/** The refusals run before any byte is written, so a refused upload cannot leave a file at all. */
it('writes nothing at all when the file is refused', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'kitsune-lib-');
    file_put_contents($script, "<?php echo 'pwned';");

    expect(fn () => MediaLibrary::store($script, 'innocent.png', $this->imageType))
        ->toThrow(RuntimeException::class, 'its contents are');

    expect(Storage::disk('local')->allFiles('media'))->toBe([])
        ->and(DB::table('entries')->count())->toBe(0);
});
