<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Media\SanitisesSvg;

/*
 * What core accepts as an upload, and what it refuses — ADR-041.
 *
 * ⚠️ THESE ARE THE SECURITY TESTS, and they are written first for that reason: a mistake here is a
 * vulnerability rather than a bug. Nothing in this repository covered upload safety before ADR-041 —
 * `field-types.md` §6 names `rich_text` and `json` as "the two types that need security review", and an
 * uploaded file is neither.
 */

/** A real file on disk, because every check reads bytes rather than trusting a name. */
function fileHolding(string $bytes, string $suffix = ''): string
{
    $path = tempnam(sys_get_temp_dir(), 'kitsune-intake-').$suffix;
    file_put_contents($path, $bytes);

    return $path;
}

/** The smallest valid PNG: an 8-byte signature is enough for finfo to call it image/png. */
function pngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kitsune-intake-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

it('accepts a genuine png named as one', function (): void {
    $path = fileHolding(pngBytes());

    expect(MediaIntake::accept('photo.png', $path, filesize($path)))
        ->toBe(['extension' => 'png', 'mime' => 'image/png']);
});

/**
 * ⚠️ THE CLASSIC HOLE, IN BOTH DIRECTIONS. An extension allowlist alone accepts a script named `.jpg`; a MIME
 * check alone accepts a genuine image named `.php`, which some server configurations will execute. The pair
 * must agree, so both halves are asserted rather than one.
 */
it('refuses a php script wearing an image extension', function (): void {
    $path = fileHolding("<?php echo 'pwned'; ?>\n");

    expect(fn () => MediaIntake::accept('innocent.png', $path, filesize($path)))
        ->toThrow(RuntimeException::class, 'its contents are');
});

it('refuses a genuine png wearing a php extension', function (): void {
    $path = fileHolding(pngBytes());

    expect(fn () => MediaIntake::accept('payload.php', $path, filesize($path)))
        ->toThrow(RuntimeException::class, 'not an accepted file type');
});

it('refuses a file with no extension at all', function (): void {
    $path = fileHolding(pngBytes());

    expect(fn () => MediaIntake::accept('README', $path, filesize($path)))
        ->toThrow(RuntimeException::class, 'no extension');
});

/**
 * ⚠️ THE SUCCESSOR TO `refuses svg until the sanitiser ships with it`, REPLACED RATHER THAN DELETED. That
 * test was the only assertion in the suite that a script-bearing SVG is handled at all; removing it when SVG
 * became acceptable would have retired the question along with the answer.
 *
 * What changed is the REASON for the refusal, not the refusal. ADR-041 accepts SVG sanitised, and the
 * sanitiser lives in `kitsune/svg-sanitizer` because the only library with the population ADR-041 was buying
 * is GPL-2.0-or-later (Standing Principle #11). With nothing bound, core refuses — and says so in terms an
 * operator can act on, which is what this asserts.
 */
it('refuses svg while nothing can make it safe, and names the fix', function (): void {
    expect(app()->bound(SanitisesSvg::class))->toBeFalse('the core suite must not bind a sanitiser by default');

    $path = fileHolding('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    expect(fn () => MediaIntake::accept('logo.svg', $path, filesize($path)))
        ->toThrow(RuntimeException::class, 'accepted only when a sanitiser is installed');

    /* Not the generic message — an operator with an ordinary logo needs an install step, not a different file. */
    expect(fn () => MediaIntake::accept('logo.svg', $path, filesize($path)))
        ->not->toThrow(RuntimeException::class, 'is not an accepted file type');
});

/** And it is genuinely absent from the list, rather than present and refused later. */
it('leaves svg out of the accepted types while nothing is bound', function (): void {
    expect(MediaIntake::acceptedTypes())->not->toHaveKey('svg')
        ->and(MediaIntake::isGuarded('svg'))->toBeTrue()
        ->and(MediaIntake::isGuarded('png'))->toBeFalse();
});

/**
 * ⚠️ BOUND, AND NOW IT IS ACCEPTED — the other half, which a refusal-only test cannot see. A gate that
 * refuses everything passes every refusal test ever written.
 */
it('accepts svg once something implements the sanitiser', function (): void {
    app()->instance(SanitisesSvg::class, new class implements SanitisesSvg
    {
        public function sanitise(string $svg): string
        {
            return $svg;
        }
    });

    $path = fileHolding('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');

    expect(MediaIntake::accept('logo.svg', $path, filesize($path)))
        ->toBe(['extension' => 'svg', 'mime' => 'image/svg+xml'])
        ->and(MediaIntake::acceptedTypes())->toHaveKey('svg');

    app()->forgetInstance(SanitisesSvg::class);
});

/**
 * ⚠️ THE SNIFF STILL DECIDES, AND IT IS THE ONLY THING STANDING BETWEEN `.svg` AND AN ARBITRARY FILE. Binding
 * a sanitiser opens the extension, not the gate: HTML and PHP named `.svg` are what `GUARDED` listing exactly
 * one MIME buys, measured with libmagic rather than assumed.
 */
it('still refuses html and php wearing an svg extension, even with a sanitiser bound', function (): void {
    app()->instance(SanitisesSvg::class, new class implements SanitisesSvg
    {
        public function sanitise(string $svg): string
        {
            return $svg;
        }
    });

    $html = fileHolding('<html><body><script>alert(1)</script></body></html>');
    $php = fileHolding("<?php echo 'pwned';");

    expect(fn () => MediaIntake::accept('logo.svg', $html, filesize($html)))
        ->toThrow(RuntimeException::class, 'its contents are')
        ->and(fn () => MediaIntake::accept('logo.svg', $php, filesize($php)))
        ->toThrow(RuntimeException::class, 'its contents are');

    app()->forgetInstance(SanitisesSvg::class);
});

it('refuses a file over the ceiling before anything is written', function (): void {
    $path = fileHolding(pngBytes());

    expect(fn () => MediaIntake::accept('huge.png', $path, MediaIntake::MAX_BYTES + 1))
        ->toThrow(RuntimeException::class, 'the ceiling is');
});

/*
 * ⚠️ A CALLER'S FILENAME NEVER BECOMES A PATH.
 *
 * Traversal, a leading slash, a Windows separator, a null byte and a name that is nothing but dots are the
 * same problem wearing different clothes: the uploader choosing where bytes land or what they overwrite. The
 * extension is read from the basename, and the stored name is generated by core.
 */
it('takes the extension from the basename, whatever the path pretends to be', function (string $name): void {
    expect(MediaIntake::extensionOf($name))->toBe('png');
})->with([
    'traversal' => '../../../etc/passwd.png',
    'absolute' => '/etc/cron.d/evil.png',
    'windows separator' => 'C:\\windows\\system32\\evil.png',
    'nested' => 'a/b/c/photo.PNG',
    'uppercase' => 'PHOTO.PNG',
]);

it('generates a stored name that carries nothing from the caller', function (): void {
    $stored = MediaIntake::storedName('png');

    expect($stored)->toEndWith('.png')
        ->and($stored)->toMatch('/^[0-9a-f]{32}\.png$/')
        /* Nothing a caller could have supplied survives into the name bytes are written under. */
        ->and($stored)->not->toContain('/')
        ->and($stored)->not->toContain('..');
});

it('never generates the same stored name twice', function (): void {
    $names = array_map(fn (): string => MediaIntake::storedName('png'), range(1, 50));

    expect(array_unique($names))->toHaveCount(50);
});

/** An allowlist, never a denylist — the rule `rich_text` already follows, asserted so it cannot quietly invert. */
it('publishes an allowlist whose every entry pins its accepted content types', function (): void {
    expect(MediaIntake::ACCEPTED)->not->toBeEmpty();

    foreach (MediaIntake::ACCEPTED as $extension => $mimes) {
        expect($extension)->toMatch('/^[a-z0-9]+$/')
            ->and($mimes)->not->toBeEmpty();

        foreach ($mimes as $mime) {
            expect($mime)->toMatch('#^[a-z]+/[a-z0-9.+-]+$#');
        }
    }

    /*
     * The executable extensions that must never appear, asserted by name rather than by reading the list.
     *
     * ⚠️ `svg` IS STILL HERE, AND IT IS STILL IN THIS LIST FOR THE SAME REASON IT ALWAYS WAS. It did not stop
     * being executable when ADR-041's sanitiser arrived — a sanitiser now stands in front of it, which is a
     * different claim. `ACCEPTED` is the unconditional list and SVG is conditional, so this assertion keeps
     * asking exactly the question it was written to ask; `acceptedTypes()` is where the conditional answer
     * lives, and its own tests are above.
     */
    foreach (['php', 'phtml', 'phar', 'svg', 'html', 'htm', 'js', 'sh', 'exe'] as $forbidden) {
        expect(MediaIntake::ACCEPTED)->not->toHaveKey($forbidden);
    }
});
