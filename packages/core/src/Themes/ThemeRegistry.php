<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

use JsonException;

/** Finds trusted, host-installed Blade themes without scanning on a request for a known selection. */
final class ThemeRegistry
{
    private readonly string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? resource_path('views/themes');
    }

    public function find(string $handle): ?SiteTheme
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,47}$/D', $handle)) {
            return null;
        }

        $directory = $this->directory.'/'.$handle;
        $manifest = $directory.'/theme.json';
        if (! is_file($manifest) || ! is_readable($manifest)) {
            return null;
        }

        $contents = file_get_contents($manifest);
        if ($contents === false) {
            return null;
        }

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $types = is_array($data) ? ($data['entry_types'] ?? null) : null;
        if (! is_array($types) || ! array_is_list($types)) {
            return null;
        }

        foreach ($types as $type) {
            if (! is_string($type) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $type)) {
                return null;
            }
        }

        $theme = new SiteTheme($handle, $directory, array_values(array_unique($types)));

        return $theme->home() === null ? null : $theme;
    }

    /** @return list<SiteTheme> */
    public function all(): array
    {
        $themes = [];
        foreach (glob($this->directory.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $theme = $this->find(basename($directory));
            if ($theme !== null) {
                $themes[] = $theme;
            }
        }

        return $themes;
    }
}
