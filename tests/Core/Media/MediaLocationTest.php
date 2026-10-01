<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Media\JpegBytes;
use Kitsune\Core\Media\JpegLocation;
use Kitsune\Core\Media\LocationUnremovable;
use Kitsune\Core\Media\MediaFormats;
use Kitsune\Core\Media\MediaLocation;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Media\MediaWithdrawalRefused;
use Kitsune\Core\Tests\Fixtures\LocatedJpeg as J;

/*
 * Where a file was made, removed as it is made public — the seam, ADR-042 decision 30. `MediaLibrary::store()` calls
 * it for a file stored public, and decision 32 will call it for a file made public.
 */

afterEach(function (): void {
    // This process's own: a parallel run's other processes have theirs in flight.
    foreach ([...glob(sys_get_temp_dir().'/kitsune-loc-'.getmypid().'-*') ?: [], ...glob(sys_get_temp_dir().'/'.JpegLocation::TEMPORARY_PREFIX.getmypid().'-*') ?: []] as $leftover) {
        @unlink($leftover);
    }
});

function locationTemporaries(): int
{
    return count(glob(sys_get_temp_dir().'/'.JpegLocation::TEMPORARY_PREFIX.getmypid().'-*') ?: []);
}

it('strips JPEG alone', function (): void {
    expect(MediaLocation::STRIPPED)->toBe(['jpeg']);

    foreach (array_keys(MediaFormats::ALL) as $format) {
        expect(MediaLocation::strips($format))->toBe($format === 'jpeg');
    }

    expect(MediaLocation::strips(null))->toBeFalse();
});

it('answers null for a format it does not strip, and makes no temporary', function (): void {
    $before = locationTemporaries();

    // A JPEG's bytes named as another format: the format decides, and nothing is opened.
    expect(MediaLocation::strippedCopy(J::file(J::photo(true)), 'png', 'photo.png'))->toBeNull()
        ->and(MediaLocation::strippedCopy(J::file(J::photo(true)), null, 'photo'))->toBeNull()
        ->and(locationTemporaries())->toBe($before);
});

it('answers a copy without the location, and null for that copy', function (): void {
    $copy = MediaLocation::strippedCopy(J::file(J::photo(true)), 'jpeg', 'photo.jpg');

    expect($copy)->not->toBeNull()
        ->and(J::sentinels((string) file_get_contents((string) $copy)))->toBe([])
        ->and(MediaLocation::strippedCopy((string) $copy, 'jpeg', 'photo.jpg'))->toBeNull();
});

it('refuses in words an uploader is shown, saying why and offering private', function (string $reason, string $words): void {
    $refusal = MediaLocation::refusal('photo.jpg', $reason);

    expect($refusal)->toBeInstanceOf(MediaRefused::class)
        ->and($refusal->getMessage())->toStartWith('Refusing [photo.jpg] as public: '.$words.', so the GPS data it may carry cannot be removed with certainty.')
        ->toContain('It can be stored private')
        ->not->toContain($reason)
        ->not->toContain('Nothing was stored');
})->with([
    [LocationUnremovable::EXIF_DAMAGED, 'its EXIF data is damaged'],
    [LocationUnremovable::GPS_SHARED, 'its GPS data shares bytes with other EXIF data'],
    [LocationUnremovable::XMP_UNREADABLE, 'its XMP data is not text this can read'],
    [LocationUnremovable::XMP_MALFORMED, 'its XMP data mentions GPS and is not well-formed'],
    [LocationUnremovable::XMP_MIXED, 'its XMP data would change beyond its GPS properties'],
    [LocationUnremovable::BLOCKS_OVERLAP, 'its metadata blocks overlap one another or the picture\'s own segments'],
    [LocationUnremovable::OUT_OF_PLACE, 'its GPS data sits inside a part of the file that is not its metadata'],
    [LocationUnremovable::TOO_MANY, 'it holds more metadata than this reads'],
    [LocationUnremovable::NOT_JPEG, 'it does not begin as a JPEG does'],
]);

it('has words for every reason there is', function (): void {
    foreach ((new ReflectionClass(LocationUnremovable::class))->getConstants() as $reason) {
        expect(MediaLocation::refusal('photo.jpg', $reason)->getMessage())->not->toContain($reason);
    }
});

it('refuses a JPEG it cannot strip, and leaves no temporary behind', function (): void {
    $before = locationTemporaries();

    expect(fn () => MediaLocation::strippedCopy(J::file(J::unremovable()), 'jpeg', 'photo.jpg'))
        ->toThrow(MediaRefused::class, 'Refusing [photo.jpg] as public: its GPS data shares bytes with other EXIF data')
        ->and(locationTemporaries())->toBe($before);
});

/*
 * ⚠️ PURE PHP OVER BYTES. Core requires neither ext-exif nor GD nor Imagick (AGENTS.md; `packages/core/composer.json`),
 * and CI does not install them, so a call to one would work here and fail on a host without it.
 */
it('calls no EXIF, GD or Imagick function', function (): void {
    foreach (['MediaLocation', 'JpegLocation', 'JpegBytes', 'ExifLocation', 'XmpLocation', 'LocationBudget', 'LocationUnremovable'] as $class) {
        $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 3).'/packages/core/src/Media/'.$class.'.php'));

        foreach ($tokens as $i => $token) {
            $next = $i + 1;

            while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }

            // A name called as a function, or constructed as a class.
            $called = ($tokens[$next] ?? null) === '(' || (is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][0] === T_NEW);

            if ($called && is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                expect(preg_match('/^\\\\?(?:exif_\w+|image(?:create\w*|jpeg|png|gif|webp|avif|copy\w*|destroy|sx|sy|colorat)|getimagesize\w*|Imagick\w*)$/i', $token[1]))
                    ->toBe(0, "{$class} names {$token[1]}");
            }
        }
    }
});

/*
 * ────────────────────────────────  The windowed reader  ────────────────────────────────
 */

it('finds a needle that straddles two windows, and reads across them', function (): void {
    $bytes = str_repeat('a', JpegBytes::WINDOW - 1).'XY'.str_repeat('b', 10);
    $file = JpegBytes::ofFile(J::file($bytes));

    expect($file->find('XY', 0, strlen($bytes)))->toBe(JpegBytes::WINDOW - 1)
        ->and($file->find('XY', 0, JpegBytes::WINDOW))->toBeNull()
        ->and($file->read(JpegBytes::WINDOW - 2, 4))->toBe('aXYb')
        ->and($file->byte(JpegBytes::WINDOW))->toBe(ord('Y'))
        ->and($file->byte(strlen($bytes)))->toBe(-1)
        ->and($file->read(strlen($bytes) - 3, 10))->toBe('bbb');

    $file->close();
});

it('says so when a file shrinks while it is read', function (): void {
    $path = J::file(str_repeat('a', JpegBytes::WINDOW + 100));
    $file = JpegBytes::ofFile($path);
    file_put_contents($path, 'short');

    expect(fn () => $file->read(JpegBytes::WINDOW + 10, 10))->toThrow(RuntimeException::class, 'changed while it was read');
});

it('finds the marker that ends scan data past stuffing, restarts and fill, and across two windows', function (): void {
    $scan = str_repeat("\xFF\x00\x12\xFF\xD3", 10);
    $bytes = JpegBytes::ofString($scan."\xFF\xFF\xFF\xD9");

    expect($bytes->nextMarker(0, strlen($scan) + 4))->toBe(strlen($scan) + 2)
        ->and($bytes->nextMarker(0, strlen($scan) + 3))->toBeNull();

    // The window's last byte an `FF`, its marker the next window's first.
    $file = JpegBytes::ofFile(J::file(str_repeat("\x12", JpegBytes::WINDOW - 1)."\xFF\xD9"));

    expect($file->nextMarker(0, JpegBytes::WINDOW + 1))->toBe(JpegBytes::WINDOW - 1);
    $file->close();
});

/*
 * ADR-042 decision 32: a stored file made public is read as the format its row says — failing closed for a row written
 * past the model — and refused in words for a file already stored.
 */
it('reads a stored row as JPEG by its extension in any case, or by its type whatever its name', function (string $path, ?string $mime, ?string $format): void {
    expect(MediaLocation::formatOf($path, $mime))->toBe($format);
})->with([
    'a JPEG' => ['media/1/photo.jpg', 'image/jpeg', 'jpeg'],
    'a capitalised extension' => ['media/1/PHOTO.JPG', 'image/jpeg', 'jpeg'],
    'a capitalised extension with no type' => ['media/1/PHOTO.JPEG', null, 'jpeg'],
    'a JPEG under a PNG\'s name' => ['media/1/photo.png', 'image/jpeg', 'jpeg'],
    'a JPEG\'s type in capitals' => ['media/1/photo.bin', ' IMAGE/JPEG ', 'jpeg'],
    'a PNG' => ['media/1/photo.png', 'image/png', 'png'],
    'a capitalised PNG' => ['media/1/photo.PNG', 'image/png', 'png'],
    'something else' => ['media/1/photo.xyz', 'application/octet-stream', null],
    // A JPEG's other names (review of decision 32).
    'a browser\'s .jfif' => ['media/1/photo.jfif', 'image/pjpeg', 'jpeg'],
    'a .jpe' => ['media/1/photo.JPE', null, 'jpeg'],
    'a .pjp' => ['media/1/photo.pjp', null, 'jpeg'],
    'image/pjpeg' => ['media/1/photo', 'image/pjpeg', 'jpeg'],
    'image/jpg' => ['media/1/photo', 'image/jpg', 'jpeg'],
    'a type with parameters' => ['media/1/photo', 'image/jpeg; charset=binary', 'jpeg'],
    'a PNG\'s type with parameters' => ['media/1/photo.png', 'image/png; charset=binary', 'png'],
]);

it('refuses a stored file in words that say it stays private', function (): void {
    $refusal = MediaLocation::refusal('Holiday', LocationUnremovable::GPS_SHARED, stored: true);

    expect($refusal->getMessage())->toBe('Refusing to make [Holiday] public: its GPS data shares bytes with other EXIF data, '
        .'so the GPS data it may carry cannot be removed with certainty. It stays private, where it is served only to those '
        .'who may view it; saved again without its location and uploaded, it can be public.')
        ->and(MediaLocation::refusal('photo.jpg', LocationUnremovable::GPS_SHARED)->getMessage())
        ->toStartWith('Refusing [photo.jpg] as public: ')->toContain('It can be stored private');

    $file = J::file(J::unremovable());

    try {
        expect(fn () => MediaLocation::strippedCopy($file, 'jpeg', 'Holiday', stored: true))
            ->toThrow(MediaRefused::class, 'Refusing to make [Holiday] public: ');
    } finally {
        unlink($file);
    }
});

it('names the act a withdrawal refused: a trash, an erasure, or a file made private', function (): void {
    $refusal = static fn (string $operation, string $reason): string => (new MediaWithdrawalRefused(7, $reason, 'public', $operation))->getMessage();

    expect($refusal('make private', MediaWithdrawalRefused::DELETE_FAILED))->toStartWith('Refusing to make entry 7 private: its file could not be withdrawn')
        ->and($refusal('make private', MediaWithdrawalRefused::UNSAFE_DISKS))->toStartWith('Refusing to make entry 7 private: the configured media disks')
        ->and($refusal('trash', MediaWithdrawalRefused::DELETE_FAILED))->toStartWith('Refusing to trash entry 7: its file could not be withdrawn')
        ->and($refusal('erase', MediaWithdrawalRefused::UNSAFE_DISKS))->toStartWith('Refusing to erase entry 7: the configured media disks');
});
