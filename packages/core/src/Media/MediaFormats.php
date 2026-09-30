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
 * The formats a media type may name as the files it accepts — ADR-042 decision 33.
 *
 * ⚠️ A FORMAT, NOT AN EXTENSION. An editor narrows a type to "JPEG", not to `.jpg` and `.jpeg` one by one, so each
 * format lists the extensions `MediaIntake` stores it under. Every extension `MediaIntake` accepts belongs to exactly
 * one format, and a test holds the two lists together: an extension added there without a format here would be
 * refused by every type that names what it accepts.
 *
 * ⚠️ WITHIN `MediaIntake`, NEVER BEYOND IT. A type narrows what the installation stores; naming a format here does not
 * make it storable — SVG still needs its sanitiser — and `MediaIntake` still refuses a file whose bytes disagree with
 * its name before a type is asked.
 *
 * ⚠️ A TYPE THAT NAMES NONE ACCEPTS EVERY FORMAT, one added later included: a type created before decision 33, and
 * one whose owner ticked every format. A type that names some accepts those alone, and a format added later is not
 * among them until it is named.
 */
final class MediaFormats
{
    /**
     * Each format's name, as an editor reads it, and the extensions `MediaIntake` accepts it under.
     *
     * @var array<string, array{name: string, extensions: list<string>}>
     */
    public const ALL = [
        'jpeg' => ['name' => 'JPEG', 'extensions' => ['jpg', 'jpeg']],
        'png' => ['name' => 'PNG', 'extensions' => ['png']],
        'gif' => ['name' => 'GIF', 'extensions' => ['gif']],
        'webp' => ['name' => 'WebP', 'extensions' => ['webp']],
        'avif' => ['name' => 'AVIF', 'extensions' => ['avif']],
        'svg' => ['name' => 'SVG', 'extensions' => ['svg']],
        'pdf' => ['name' => 'PDF', 'extensions' => ['pdf']],
        'mp4' => ['name' => 'MP4', 'extensions' => ['mp4']],
        'webm' => ['name' => 'WebM', 'extensions' => ['webm']],
        'mp3' => ['name' => 'MP3', 'extensions' => ['mp3']],
        'txt' => ['name' => 'TXT', 'extensions' => ['txt']],
        'csv' => ['name' => 'CSV', 'extensions' => ['csv']],
    ];

    /** The images, which the `image` type accepts alone (Adam, decision 33). */
    public const IMAGES = ['jpeg', 'png', 'gif', 'webp', 'avif', 'svg'];

    /** The key under `entry_types.settings` that names them. */
    public const SETTING = 'accepts';

    /** The format an extension `MediaIntake` accepted belongs to, or null for one it does not. */
    public static function of(string $extension): ?string
    {
        foreach (self::ALL as $format => ['extensions' => $extensions]) {
            if (in_array($extension, $extensions, true)) {
                return $format;
            }
        }

        return null;
    }

    /**
     * The formats a type's stored settings name, or null where it names none and accepts every one.
     *
     * ⚠️ FAILS CLOSED. The type's own save refuses a malformed list (`EntryType::guardAccepts()`), but a row written past
     * the model is read here too, and a list this cannot read is not taken to mean "everything".
     *
     * @return list<string>|null
     *
     * @throws RuntimeException naming the type, for a list that is not one of formats
     */
    public static function namedBy(mixed $settings, string $handle): ?array
    {
        $accepts = is_array($settings) ? ($settings[self::SETTING] ?? null) : null;

        if ($accepts === null) {
            return null;
        }

        $problem = self::problemWith($accepts);

        if ($problem !== null) {
            throw new RuntimeException(sprintf(
                'Entry type [%s] names the files it accepts as %s, so what it accepts cannot be told, and nothing is '
                .'stored in it until its settings are corrected (ADR-042 decision 33).',
                $handle,
                $problem,
            ));
        }

        /** @var list<string> $accepts */
        return $accepts;
    }

    /** What is wrong with a list a type names, or null where it is a list of formats. */
    public static function problemWith(mixed $accepts): ?string
    {
        if (! is_array($accepts) || ! array_is_list($accepts) || $accepts === []) {
            return 'something other than a list of formats with one in it at least';
        }

        foreach ($accepts as $format) {
            if (! is_string($format) || ! array_key_exists($format, self::ALL)) {
                return sprintf('a list holding [%s], which is not a format', is_scalar($format) ? (string) $format : get_debug_type($format));
            }
        }

        if (count(array_unique($accepts)) !== count($accepts)) {
            return 'a list naming a format twice';
        }

        return null;
    }

    /**
     * The formats named, as a sentence: "JPEG, PNG and SVG", in the order `ALL` lists them.
     *
     * @param  list<string>  $formats
     */
    public static function sentence(array $formats): string
    {
        $names = array_values(array_map(
            static fn (string $format): string => self::ALL[$format]['name'],
            array_filter(array_keys(self::ALL), static fn (string $format): bool => in_array($format, $formats, true)),
        ));

        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }

    /**
     * The MIME types the browser is told these formats are, for its own check before anything is staged.
     *
     * @param  list<string>  $formats
     * @return list<string>
     */
    public static function browserTypes(array $formats): array
    {
        $map = self::browserMap();
        $types = [];

        foreach ($formats as $format) {
            foreach (self::ALL[$format]['extensions'] as $extension) {
                $types[] = $map[$extension];
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Each extension's MIME type, as the browser is to read it: from its name, as `MediaIntake` first lists it, so the
     * browser's check never rests on what the operating system happens to call a file — a CSV that Windows reports as
     * a spreadsheet among them.
     *
     * @return array<string, string>
     */
    public static function browserMap(): array
    {
        $map = [];

        foreach ([...MediaIntake::ACCEPTED, ...MediaIntake::GUARDED] as $extension => $mimes) {
            $map[$extension] = $mimes[0];
        }

        // A CSV's name says `text/csv`: `MediaIntake` lists `text/plain` first because that is what its bytes sniff as.
        $map['csv'] = 'text/csv';

        return $map;
    }
}
