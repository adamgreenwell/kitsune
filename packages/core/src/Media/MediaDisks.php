<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

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

    /**
     * Refuse a configuration in which another disk or a public link reaches into core's, or core's reaches into it.
     *
     * ⚠️ THE NAMES ARE CORE'S, AND THE ROOTS CAN STILL COLLIDE — Codex, #152. `define()` decides what the two names mean,
     * but not where the host's own disks are. Two overlaps break a promise made here, and both are refused:
     *
     * - core's root inside a disk that is served, or inside a directory a public link exposes — the "never served" disk
     *   is then served through the other one, signed or not;
     * - another disk's root, or a link's, inside core's — the intake sweep deletes everything on its disk by age, and a
     *   directory the web serves inside the private disk exposes whatever lands there.
     *
     * Core's root inside a disk that is neither served nor linked is allowed: Laravel 10 and earlier rooted `local` at
     * `storage/app`, and a disk nothing serves exposes nothing. That host's own code can still reach core's files
     * through it, which is the host's to govern.
     *
     * ⚠️ ONCE EVERY PROVIDER HAS BOOTED, AND IT THROWS. The configuration is final then; `config:cache` boots the
     * application before it exports, so `deploy/release.sh` stops on an overlap before a release goes live, as Laravel
     * stops on two served disks claiming one URL.
     *
     * @throws RuntimeException naming both sides and the fix
     */
    public static function refuseOverlaps(Repository $config): void
    {
        $reaches = [];

        foreach ((array) $config->get('filesystems.disks', []) as $name => $disk) {
            if (is_array($disk) && ($disk['driver'] ?? null) === 'local' && is_string($disk['root'] ?? null) && $disk['root'] !== '') {
                $reaches[] = [
                    'what' => "the [{$name}] disk",
                    'name' => (string) $name,
                    'root' => self::normalised($disk['root']),
                    'exposed' => (bool) ($disk['serve'] ?? false),
                ];
            }
        }

        foreach ((array) $config->get('filesystems.links', []) as $link => $target) {
            if (is_string($target) && $target !== '') {
                $reaches[] = ['what' => "the public link at [{$link}]", 'name' => null, 'root' => self::normalised($target), 'exposed' => true];
            }
        }

        foreach ([self::PRIVATE, self::INTAKE] as $core) {
            $own = array_values(array_filter($reaches, static fn (array $reach): bool => $reach['name'] === $core))[0] ?? null;

            if ($own === null) {
                continue;
            }

            foreach ($reaches as $other) {
                if ($other['name'] === $core) {
                    continue;
                }

                $inside = str_starts_with($own['root'], $other['root']);
                $holds = str_starts_with($other['root'], $own['root']);

                if (($inside && $other['exposed']) || $holds) {
                    throw new RuntimeException(sprintf(
                        'Refusing to boot: core\'s [%s] disk, rooted at [%s], %s %s at [%s]. Core\'s disks are never '
                        .'served, and the intake disk is swept by age (ADR-042 decision 4); give %s a root outside '
                        .'[%s], or move it so that it does not contain core\'s. If the configuration is cached, clear '
                        .'bootstrap/cache/config.php first.',
                        $core,
                        rtrim($own['root'], '/'),
                        $holds ? 'contains' : 'sits inside',
                        $other['what'],
                        rtrim($other['root'], '/'),
                        $other['what'],
                        rtrim($own['root'], '/'),
                    ));
                }
            }
        }
    }

    /**
     * A root as the filesystem resolves it, ending in a slash so a prefix is a directory rather than a name.
     *
     * ⚠️ THROUGH THE NEAREST PART THAT EXISTS, because symlinks are how deployments share storage: a release's
     * `storage` is commonly a link to a shared directory, and one disk named through the link and another through its
     * target are the same place. A root that does not exist yet — the intake disk before its first upload — is
     * resolved through its nearest existing parent, so the two still compare.
     */
    private static function normalised(string $root): string
    {
        $path = rtrim(str_replace('\\', '/', $root), '/');
        $missing = '';

        while ($path !== '' && realpath($path) === false) {
            $parent = dirname($path);

            if ($parent === $path) {
                break;
            }

            $missing = '/'.basename($path).$missing;
            $path = $parent;
        }

        $resolved = realpath($path);

        return rtrim(str_replace('\\', '/', $resolved !== false ? $resolved : $path), '/').$missing.'/';
    }
}
