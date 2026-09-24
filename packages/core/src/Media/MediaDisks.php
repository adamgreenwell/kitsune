<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Contracts\Config\Repository;

/**
 * The disks core defines for itself — ADR-042 decisions 4 and 4a.
 *
 * @internal
 *
 * ⚠️ NEVER SERVED, AND THAT IS THE WHOLE REASON THIS EXISTS. ADR-041 sent private files to Laravel's `local`,
 * which the framework's shipped configuration marks `serve => true`: it registers signed `GET` and `PUT` routes at
 * `storage/{path}`, so any `temporaryUrl()` minted on it is a bearer URL that skips `EntryPolicy` and outlives the
 * row it was minted for. A disk with `serve` off has no web route at all, signed or not, and the only way to its
 * bytes is the panel route that authorises first. That removes the hole at its root rather than policing every
 * caller that might mint one.
 *
 * ⚠️ OUTSIDE `local`'S ROOT, NOT A FOLDER INSIDE IT. `local` is rooted at `storage/app/private`, and a disk nested
 * under a served one is served by it: `storage.local` would sign a URL for a path in the nested folder as readily
 * as for anything else under its root. The skeleton ignores `storage/app/kitsune` in git, as it ignores
 * `storage/app/private`, so a site kept in version control does not commit its private files.
 *
 * ⚠️ DEFINED UNCONDITIONALLY, OVER ANY DISK A HOST GAVE THE SAME NAME. The name is core's, and the promise made
 * about it — no route serves it — is only true while core decides its definition. A host that wants private media
 * somewhere else points `kitsune.media.disks.private` at a disk of its own, and answers for that disk's serving.
 */
final class MediaDisks
{
    /** Where private media lives unless a host points `kitsune.media.disks.private` elsewhere. */
    public const PRIVATE = 'kitsune-private';

    /**
     * Where Livewire stages an upload before anything has accepted it — ADR-042 decision 4.
     *
     * ⚠️ A SIBLING OF `kitsune/private`, NEVER ITS PARENT. `MediaStaging::sweep()` deletes everything on this disk
     * by age, so a root that contained the private disk — or `storage/app/kitsune` itself, whose `.gitignore` keeps
     * private files out of version control — would put stored media inside the sweep. `MediaDisksTest` holds every
     * local root apart from this one in both directions.
     *
     * ⚠️ AND LOCAL, WHICH IS A SECURITY PROPERTY RATHER THAN A DEFAULT. On a disk whose driver is `s3`, Livewire
     * presigns a PUT straight to the bucket, and the endpoint's middleware and rules never run at all.
     */
    public const INTAKE = 'kitsune-intake';

    /** Written into `filesystems.disks` from `register()`, so `config:cache` captures it and a boot finds it. */
    public static function define(Repository $config): void
    {
        $config->set('filesystems.disks.'.self::PRIVATE, [
            'driver' => 'local',
            'root' => storage_path('app/kitsune/private'),
            'serve' => false,
            /*
             * `false`, as Laravel's own `local` has it: `MediaLibrary::write()` reads a failed write from
             * `writeStream()` returning false, and `MediaDisposal` reports a refused delete rather than throwing.
             */
            'throw' => false,
            'report' => false,
        ]);

        $config->set('filesystems.disks.'.self::INTAKE, [
            'driver' => 'local',
            'root' => storage_path('app/kitsune/intake'),
            'serve' => false,
            /*
             * `false`, as `PRIVATE` has it and for a reason of Livewire's: it reads a staged file's original name
             * from a sidecar, and with `throw` on, a sidecar the sweep has already removed would be an exception
             * where Livewire expects an empty answer.
             */
            'throw' => false,
            'report' => false,
        ]);
    }
}
