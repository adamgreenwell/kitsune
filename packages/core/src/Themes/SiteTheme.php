<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

/** @internal The Blade theme contract remains provisional through v1.1. */
final readonly class SiteTheme
{
    /** @param list<string> $entryTypes */
    public function __construct(public string $handle, private string $directory, public array $entryTypes, public ?int $contract = null) {}

    public function home(): ?string
    {
        return $this->template('home.blade.php');
    }

    public function entry(string $type): ?string
    {
        if (! in_array($type, $this->entryTypes, true)) {
            return null;
        }

        return $this->template('entries/'.$type.'.blade.php') ?? $this->template('entry.blade.php');
    }

    private function template(string $relative): ?string
    {
        $file = $this->directory.'/'.$relative;

        return is_file($file) && is_readable($file) ? $file : null;
    }
}
