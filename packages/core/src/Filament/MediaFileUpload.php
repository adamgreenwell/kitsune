<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Filament\Forms\Components\FileUpload;
use Kitsune\Core\Media\MediaFormats;

/**
 * The Upload field, told which formats its type accepts — for the browser alone (ADR-042 decision 33).
 *
 * ⚠️ NOT `acceptedFileTypes()`, WHICH ADDS A SERVER RULE. Filament's method hands FilePond the types and adds a
 * `mimetypes:` rule the submit validates, which asks the intake disk for each staged file — and throws for one that is
 * not there, before the handler can refuse it, as a `max:` rule did (decision 4). So the browser is told the types, and
 * checks them before anything is staged — its picker offers those alone — and `MediaLibrary::store()` refuses what
 * the type does not name, in words naming what it does; no rule stands between the two.
 *
 * ⚠️ AND THE BROWSER READS A FILE'S TYPE FROM ITS NAME, by `MediaFormats::browserMap()`, so its check is the one the
 * name implies, whatever the operating system calls the file. The bytes are `MediaIntake`'s to judge, at staging.
 */
final class MediaFileUpload extends FileUpload
{
    /** @var list<string>|null */
    private ?array $formats = null;

    /**
     * The formats the type names, or null where it names none and the browser checks nothing.
     *
     * @param  list<string>|null  $formats
     */
    public function formats(?array $formats): static
    {
        $this->formats = $formats;

        return $this;
    }

    /** @return list<string>|null */
    public function getAcceptedFileTypes(): ?array
    {
        return $this->formats === null ? null : MediaFormats::browserTypes($this->formats);
    }

    /** @return array<string, string> */
    public function getMimeTypeMap(): array
    {
        return $this->formats === null ? parent::getMimeTypeMap() : MediaFormats::browserMap();
    }
}
