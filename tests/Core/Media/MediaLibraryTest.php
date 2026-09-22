<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\SanitisesSvg;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\SvgSanitizer\EnshrinedSvgSanitiser;

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

/*
 * ────────────────────────────────  SVG, sanitised on write  ────────────────────────────────
 *
 * ⚠️ ASSERTED BY READING THE STORED BYTES, WHICH IS ADR-041'S OWN "ENFORCED BY" LINE: *"an SVG carrying a
 * script element, an event-handler attribute and an external reference is stored with none of the three,
 * asserted by reading the stored bytes rather than the upload response."* An upload that returns 200 proves
 * nothing about what landed on disk, and the bytes are the thing a browser is later handed.
 *
 * The sanitiser bound here is a REAL one — `kitsune/svg-sanitizer` over `enshrined/svg-sanitize` — because a
 * stub would assert that a stub strips scripts. What these tests own is the wiring: that `MediaLibrary` calls
 * it at all, before writing, and that every column describes the sanitised bytes rather than the original.
 */

function withSvgSanitiser(): void
{
    app()->instance(SanitisesSvg::class, new EnshrinedSvgSanitiser);
}

function hostileSvg(): string
{
    $path = tempnam(sys_get_temp_dir(), 'kitsune-lib-');

    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
        .'<script>alert(2)</script>'
        .'<image href="https://evil.test/track.png"/>'
        .'<rect width="1" height="1" fill="#c00"/>'
        .'</svg>');

    return $path;
}

it('refuses svg outright while nothing is bound to sanitise it', function (): void {
    expect(fn () => MediaLibrary::store(hostileSvg(), 'logo.svg', $this->imageType))
        ->toThrow(RuntimeException::class, 'accepted only when a sanitiser is installed');

    expect(DB::table('entries')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('media'))->toBe([]);
});

/** ADR-041's enforcement line, at the disk. */
it('stores an svg with the script, the handler and the remote reference all gone', function (): void {
    withSvgSanitiser();

    $entry = MediaLibrary::store(hostileSvg(), 'logo.svg', $this->imageType);
    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    $stored = Storage::disk($media->disk)->get($media->path);

    expect(strtolower($stored))->not->toContain('<script')
        ->not->toContain('onload')
        ->not->toContain('evil.test')
        /* Still the picture that was uploaded, rather than a refusal dressed as a success. */
        ->and($stored)->toContain('<rect')
        ->and($media->mime)->toBe('image/svg+xml')
        ->and($media->path)->toEndWith('.svg');

    app()->forgetInstance(SanitisesSvg::class);
});

/**
 * ⚠️ THE ROW MUST DESCRIBE THE BYTES THAT WERE STORED, NOT THE ONES THAT ARRIVED. `store()` reads the source
 * four times after the refusals — the stream, the checksum, the size and the dimensions — and sanitising
 * rebinds only what a careful change rebinds. A stale `size_bytes` is the sharpest of them: `headersFor()`
 * sends it verbatim as `Content-Length`, so an original-sized value is a truncated or hanging response for
 * every SVG ever uploaded.
 */
it('describes the sanitised bytes in every column, not the original', function (): void {
    withSvgSanitiser();

    $source = hostileSvg();
    $originalSize = filesize($source);
    $originalChecksum = hash_file('sha256', $source);

    $entry = MediaLibrary::store($source, 'logo.svg', $this->imageType);
    $media = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    $stored = Storage::disk($media->disk)->get($media->path);

    expect($media->size_bytes)->toBe(strlen($stored))
        ->and($media->checksum)->toBe(hash('sha256', $stored))
        /* And it genuinely differs from the input, so the assertions above are not trivially true. */
        ->and($media->size_bytes)->not->toBe($originalSize)
        ->and($media->checksum)->not->toBe($originalChecksum);

    app()->forgetInstance(SanitisesSvg::class);
});

/** ADR-041 departure 2: no original is kept, anywhere. */
it('keeps no copy of the unsanitised original', function (): void {
    withSvgSanitiser();

    $entry = MediaLibrary::store(hostileSvg(), 'logo.svg', $this->imageType);

    foreach (['local', 'public'] as $disk) {
        foreach (Storage::disk($disk)->allFiles() as $file) {
            expect(strtolower(Storage::disk($disk)->get($file)))->not->toContain('<script');
        }
    }

    expect(MediaFile::query()->where('entry_id', $entry->getKey())->count())->toBe(1);

    app()->forgetInstance(SanitisesSvg::class);
});

/** The temporary the sanitiser wrote is the library's residue to clean, and it is cleaned on both paths. */
it('leaves no sanitised temporary behind, on success or on failure', function (): void {
    withSvgSanitiser();

    $before = count(glob(sys_get_temp_dir().'/kitsune-svg-*') ?: []);

    MediaLibrary::store(hostileSvg(), 'logo.svg', $this->imageType);

    Entry::creating(function (): void {
        throw new RuntimeException('row write failed, for the sake of argument');
    });

    expect(fn () => MediaLibrary::store(hostileSvg(), 'doomed.svg', $this->imageType))
        ->toThrow(RuntimeException::class, 'for the sake of argument');

    expect(count(glob(sys_get_temp_dir().'/kitsune-svg-*') ?: []))->toBe($before);

    app()->forgetInstance(SanitisesSvg::class);
});

/**
 * ⚠️ A HOSTILE SVG THAT SANITISES TO NOTHING IS A REFUSAL, AND NOTHING IS WRITTEN. The sanitiser runs before
 * any byte reaches a disk, so its refusal has to leave the same clean slate the allowlist refusals do.
 */
it('writes nothing when the sanitiser refuses the file', function (): void {
    withSvgSanitiser();

    $path = tempnam(sys_get_temp_dir(), 'kitsune-lib-');
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    expect(fn () => MediaLibrary::store($path, 'empty.svg', $this->imageType))
        ->toThrow(RuntimeException::class, 'left an empty document');

    expect(DB::table('entries')->count())->toBe(0)
        ->and(DB::table('media_files')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('media'))->toBe([]);

    app()->forgetInstance(SanitisesSvg::class);
});

/**
 * ⚠️ THE GUARDED CEILING IS 2 MiB, NOT 64, AND THE DIFFERENCE IS MEMORY RATHER THAN DISK. `write()` streams,
 * so `MAX_BYTES` costs 64 MiB of disk and not of RAM. A sanitiser cannot stream — it takes the whole
 * document, builds a DOM and returns a whole string — and peak memory runs about six times the input, so a
 * 64 MiB SVG peaks near 400 MiB. At a `memory_limit` of 128M that is a FATAL rather than a refusal: no
 * `catch` runs, no `finally` runs, and the uploader gets a 500.
 */
it('refuses an svg over the guarded ceiling, well below the general one', function (): void {
    withSvgSanitiser();

    $path = tempnam(sys_get_temp_dir(), 'kitsune-lib-');
    $padding = str_repeat(' ', MediaIntake::GUARDED_MAX_BYTES + 1);
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/>'.$padding.'</svg>');

    expect(filesize($path))->toBeLessThan(MediaIntake::MAX_BYTES)
        ->and(MediaIntake::GUARDED_MAX_BYTES)->toBeLessThan(MediaIntake::MAX_BYTES);

    expect(fn () => MediaLibrary::store($path, 'huge.svg', $this->imageType))
        ->toThrow(RuntimeException::class, 'the ceiling is '.MediaIntake::GUARDED_MAX_BYTES);

    expect(DB::table('entries')->count())->toBe(0);

    app()->forgetInstance(SanitisesSvg::class);
});

/**
 * ⚠️ THE LEAK THE EARLIER TEST COULD NOT SEE, and the reason it could not is worth keeping. "Leaves no
 * sanitised temporary behind" exercised the ROW-WRITE failure, which happens inside the `try` — so it passed
 * while the ceiling re-check, which used to throw BEFORE the `try` opened, leaked a file on every refusal.
 *
 * ⚠️ AND THE FIRST VERSION OF THIS TEST COULD NOT SEE IT EITHER, which is the more useful half. It built a
 * payload that might or might not grow past the ceiling and caught `RuntimeException` with a comment saying
 * "either outcome is fine" — so it passed with the fix reverted. A test that asserts only "nothing exploded"
 * measures nothing.
 *
 * The payload below is built from the MEASURED growth ratio instead: the library re-serialises `<circle r="1"/>`
 * as `<circle r="1"></circle>`, so a document of self-closing elements grows by about half. The input is
 * comfortably under `GUARDED_MAX_BYTES` and the sanitised result is comfortably over it, so the re-check is
 * the thing that refuses — asserted, not hoped for.
 */
it('leaves no temporary behind when the re-check refuses the grown file', function (): void {
    withSvgSanitiser();

    $before = count(glob(sys_get_temp_dir().'/kitsune-svg-*') ?: []);

    $path = tempnam(sys_get_temp_dir(), 'kitsune-lib-');
    $body = str_repeat('<circle r="1"/>', 100_000);
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg">'.$body.'</svg>');

    /* Under the ceiling on the way in — so only the post-sanitisation check can refuse it. */
    expect(filesize($path))->toBeLessThan(MediaIntake::GUARDED_MAX_BYTES);

    expect(fn () => MediaLibrary::store($path, 'grows.svg', $this->imageType))
        ->toThrow(RuntimeException::class, 'the ceiling is '.MediaIntake::GUARDED_MAX_BYTES);

    expect(count(glob(sys_get_temp_dir().'/kitsune-svg-*') ?: []))->toBe($before)
        ->and(DB::table('entries')->count())->toBe(0);

    app()->forgetInstance(SanitisesSvg::class);
});
