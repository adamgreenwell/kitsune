<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use RuntimeException;

/**
 * What core will accept as an uploaded file, and what it refuses — ADR-041.
 *
 * @internal
 *
 * ⚠️ THIS CLASS TRUSTS NOTHING THE CLIENT SENDS. The three things a caller controls — the filename, the
 * declared content type, and the bytes — are each handled on the assumption that they are hostile, because
 * `field-types.md` §6 names the two field types that need security review and an uploaded file is neither.
 * Nothing in this repository covered upload safety before ADR-041.
 *
 * ⚠️ AN ALLOWLIST, NEVER A DENYLIST, which is the rule `rich_text` already follows: *"Allowlist tags and
 * attributes; never a denylist."* A denylist is a list of the attacks somebody thought of.
 *
 * ⚠️ SVG IS ACCEPTED ONLY WHEN SOMETHING CAN MAKE IT SAFE FIRST, which is why there are two lists rather
 * than one. ADR-041 requires SVG sanitised on upload by a maintained library, and the library that qualifies
 * is GPL-2.0-or-later — so it ships in `kitsune/svg-sanitizer` rather than in core's own `require` block
 * (Standing Principle #11). With nothing bound to `SanitisesSvg`, `svg` is not an accepted extension at all,
 * and a fresh install therefore refuses it. That is the same posture as before the module existed, reached
 * deliberately rather than by omission.
 */
final class MediaIntake
{
    /**
     * Extension → the MIME types the file's own bytes are allowed to say it is.
     *
     * ⚠️ BOTH SIDES ARE CHECKED, and checking only one is the classic hole. An extension allowlist alone
     * accepts a PHP script named `.jpg`; a MIME check alone accepts a genuine JPEG named `.php`, which some
     * server configurations will happily execute. The pair must agree.
     */
    public const ACCEPTED = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'pdf' => ['application/pdf'],
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'mp3' => ['audio/mpeg'],
        'txt' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv'],
    ];

    /**
     * Extension → MIME, for types that need something else to vouch for them before they are accepted.
     *
     * ⚠️ ONLY `image/svg+xml`, AND THAT NARROWNESS WAS MEASURED RATHER THAN CHOSEN FOR TIDINESS. libmagic
     * reports `image/svg+xml` for the shapes real exporters produce — Illustrator's XML declaration plus
     * generator comment, Inkscape's DOCTYPE, a UTF-8 BOM, a bare `<svg>` — and reports `text/html` for HTML
     * named `.svg` and `text/x-php` for a PHP script named `.svg`, which are exactly the two refusals that
     * matter. It reports `text/plain` for an SVG preceded by blank lines, so that file is refused with a
     * message naming the mismatch. Adding `text/plain` here to rescue it would admit ANY text file named
     * `.svg` through the sniff, which is the gate rather than a formality.
     *
     * @var array<string, list<string>>
     */
    public const GUARDED = [
        'svg' => ['image/svg+xml'],
    ];

    /** 64 MiB. A ceiling core owns, checked before anything is written rather than after. */
    public const MAX_BYTES = 67_108_864;

    /**
     * 2 MiB, for types that must be read whole before they can be stored.
     *
     * ⚠️ A SECOND, MUCH LOWER CEILING, AND IT EXISTS BECAUSE THE GUARDED PATH CANNOT STREAM. `MediaLibrary`
     * writes bytes with `writeStream()` precisely so that `MAX_BYTES` costs 64 MiB of disk and not 64 MiB of
     * RAM — its own comment says so. A sanitiser cannot work that way: it takes the whole document, builds a
     * DOM from it, and returns a whole string. Measured on this path, peak memory runs about six times the
     * input at scale, so a 64 MiB SVG peaks near 400 MiB in one request. At a `memory_limit` of 128M or 256M
     * that is a FATAL rather than a catchable refusal — no `catch` runs, no `finally` runs, and the uploader
     * gets a 500 instead of a message.
     *
     * 2 MiB is far above any real SVG — vector artwork is kilobytes — and holds peak memory to roughly
     * twelve, which the ADR-027 floor can afford.
     */
    public const GUARDED_MAX_BYTES = 2_097_152;

    /**
     * The extensions this installation will store, which is `ACCEPTED` plus whatever is currently vouched for.
     *
     * ⚠️ A METHOD RATHER THAN A CONSTANT, because the answer depends on what the installation has installed.
     * `MediaIntake::ACCEPTED` stays a constant and stays the unconditional list, so a test asserting that
     * `php` is not in it keeps asking the question it was written to ask.
     *
     * @return array<string, list<string>>
     */
    public static function acceptedTypes(): array
    {
        return self::vouchedFor()
            ? [...self::ACCEPTED, ...self::GUARDED]
            : self::ACCEPTED;
    }

    /** Does this extension need a sanitiser to stand behind it before its bytes may be stored? */
    public static function isGuarded(string $extension): bool
    {
        return array_key_exists($extension, self::GUARDED);
    }

    /**
     * ⚠️ `bound()` RATHER THAN `make()`, so asking the question never constructs the sanitiser. `accept()` is
     * called for every upload of every type, and resolving a library's parser to decide that a PNG is a PNG
     * would be work done for nothing on the common path.
     */
    private static function vouchedFor(): bool
    {
        return app()->bound(SanitisesSvg::class);
    }

    /**
     * Refuse the upload, or return the extension and sniffed MIME type it may be stored as.
     *
     * @return array{extension: string, mime: string}
     *
     * @throws RuntimeException naming which rule refused it
     */
    public static function accept(string $originalName, string $absolutePath, int $sizeBytes): array
    {
        self::refuseIfTooLarge($sizeBytes, $originalName);

        $extension = self::extensionOf($originalName);

        /*
         * ⚠️ THE GUARDED CEILING IS CHECKED HERE, BEFORE THE FILE IS EVER READ WHOLE. Refusing after the
         * sanitiser had already loaded it would be refusing after paying the cost the ceiling exists to
         * avoid.
         */
        if (self::isGuarded($extension)) {
            self::refuseIfTooLarge($sizeBytes, $originalName, self::GUARDED_MAX_BYTES);
        }

        $accepted = self::acceptedTypes();

        if (! array_key_exists($extension, $accepted)) {
            /*
             * ⚠️ A GUARDED TYPE GETS ITS OWN MESSAGE, because "not an accepted file type" is true and useless
             * here: the operator uploaded a perfectly ordinary logo and the fix is an install step, not a
             * different file. Saying so is the difference between a refusal and a dead end.
             */
            if (self::isGuarded($extension)) {
                throw new RuntimeException(sprintf(
                    'Refusing [%s]: .%s is accepted only when a sanitiser is installed to make it safe first '
                    .'(ADR-041), and nothing in this installation implements [%s]. The first-party module '
                    .'`kitsune/svg-sanitizer` provides one. Installing is not enabling, so it takes both: '
                    .'`php artisan kitsune:module install kitsune/svg-sanitizer` then `php artisan '
                    .'kitsune:module enable kitsune/svg-sanitizer`. Or convert the file to PNG. Storing it '
                    .'unsanitised is the one outcome that decision was taken to avoid.',
                    $originalName,
                    $extension,
                    SanitisesSvg::class,
                ));
            }

            throw new RuntimeException(sprintf(
                'Refusing [%s]: [%s] is not an accepted file type. The list is an allowlist core owns — a '
                .'denylist is a list of the attacks somebody thought of — and an org cannot widen it.',
                $originalName,
                $extension === '' ? 'no extension' : $extension,
            ));
        }

        $sniffed = self::sniff($absolutePath);

        if (! in_array($sniffed, $accepted[$extension], true)) {
            throw new RuntimeException(sprintf(
                'Refusing [%s]: it is named .%s but its contents are [%s]. The type is read from the file\'s '
                .'own bytes and never from what the upload claimed, because a claimed type is a claim by '
                .'whoever is uploading — and this value decides the Content-Type a browser is later handed.',
                $originalName,
                $extension,
                $sniffed,
            ));
        }

        return ['extension' => $extension, 'mime' => $sniffed];
    }

    /**
     * Refuse anything over the ceiling, before it is written.
     *
     * ⚠️ CALLED TWICE FOR A GUARDED TYPE, and that is the reason it is a method rather than four lines inside
     * `accept()`. Sanitising can GROW a file, so the size that arrived and the size that would be stored are
     * two different numbers, and `MAX_BYTES` is a promise about the second one.
     *
     * @throws RuntimeException
     */
    public static function refuseIfTooLarge(int $sizeBytes, string $originalName, ?int $ceiling = null): void
    {
        $ceiling ??= self::MAX_BYTES;

        if ($sizeBytes <= $ceiling) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing [%s]: it is %d bytes and the ceiling is %d. The limit is checked before anything is '
            .'written, so nothing was stored.',
            $originalName,
            $sizeBytes,
            $ceiling,
        ));
    }

    /**
     * The lowercased extension, taken from the name and nothing else.
     *
     * ⚠️ `pathinfo()` on the BASENAME, so a name carrying directories cannot reach past it. This function
     * never returns a path and the caller never uses the original name for storage — see `storedName()`.
     */
    public static function extensionOf(string $originalName): string
    {
        $base = basename(str_replace('\\', '/', $originalName));

        return mb_strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    }

    /**
     * The name bytes are stored under — generated by core, never derived from what the caller sent.
     *
     * ⚠️ A CALLER'S FILENAME NEVER BECOMES A PATH. `../../.env`, a name with a null byte, a 4,000-character
     * name and a name that differs only by case on a case-insensitive disk are all the same problem: the
     * caller choosing where bytes land or what they overwrite. Core chooses instead, and the original name
     * belongs in a field on the entry where it is data rather than a location.
     */
    public static function storedName(string $extension): string
    {
        return bin2hex(random_bytes(16)).'.'.$extension;
    }

    /**
     * The file's type according to its own bytes.
     *
     * ⚠️ `finfo` is `ext-fileinfo` — bundled with PHP but able to be compiled out, so it is not guaranteed the
     * way `ext/standard` is. It also holds transitively at the ADR-027 floor: `laravel/framework` requires
     * `league/flysystem-local`, which requires `ext-fileinfo`.
     *
     * ⚠️ AND CORE DECLARES IT ANYWAY, WHICH REVERSES WHAT THIS COMMENT USED TO ARGUE. It used to offer the
     * transitive guarantee as a reason core need not name the extension itself — and then the SVG work added
     * `ext-dom` and `ext-libxml`, which are guaranteed by exactly the same shape (`laravel/framework` →
     * `tijsverkoyen/css-to-inline-styles`), leaving core stating one rule and following another. Review
     * caught the contradiction. A package that calls a function declares the extension that provides it: the
     * transitive guarantee is somebody else's dependency graph, and it can change without anyone here
     * noticing. If it is somehow absent this still refuses rather than falling back to the client's claim,
     * because the fallback is the vulnerability.
     */
    private static function sniff(string $absolutePath): string
    {
        if (! class_exists(\finfo::class)) {
            throw new RuntimeException(
                'Refusing this upload: ext-fileinfo is not available, so the file type cannot be read from its '
                .'own bytes. Uploads are refused rather than trusting the type the client declared.'
            );
        }

        $info = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $info->file($absolutePath);

        if (! is_string($mime) || $mime === '') {
            throw new RuntimeException('Refusing this upload: its content type could not be determined.');
        }

        return $mime;
    }
}
