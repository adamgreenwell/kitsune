<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Config\Repository as ConfigRepository;
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
     * Refuse a boot in which either of core's disk names no longer holds core's definition — Codex, #152.
     *
     * ⚠️ `define()` WRITES THE NAMES, AND A LATER PROVIDER CAN WRITE THEM AGAIN. Core registers before the host's own
     * providers, so a host's `register()` or `boot()` can replace either definition after core has written it — and
     * every promise made about these disks is about the definition. Redefined as `s3`, Livewire presigns a PUT to the
     * bucket past the endpoint's gate and rule; redefined as a served local disk, Laravel routes it at `storage/{path}`.
     * So once every provider has booted, each name must still be local, unserved and rooted where core put it.
     *
     * ⚠️ AND EVERY OTHER KEY AS CORE WROTE IT — Codex, #152. The first version checked those three, so `throw` switched on
     * passed as intact, and Livewire's read of a sidecar the sweep removed, `MediaLibrary::write()` and `MediaDisposal`
     * all expect a failure answered as `false`. `links`, `lock` and `permissions` change behaviour as much. Naming the
     * keys that matter would leave the next one to be found, so the definition is compared whole: every key core wrote,
     * and none added, the root as the filesystem resolves it.
     *
     * @throws RuntimeException naming the disk and the keys that changed — never their values, which on an object store
     *                          would be its credentials
     */
    public static function refuseRedefinition(Repository $config): void
    {
        $core = new ConfigRepository;
        self::define($core);

        foreach ([self::PRIVATE, self::INTAKE] as $name) {
            /** @var array<string, mixed> $written */
            $written = $core->get("filesystems.disks.{$name}");
            $changed = self::changedKeys($written, $config->get("filesystems.disks.{$name}"));

            if ($changed !== []) {
                throw new RuntimeException(sprintf(
                    'Refusing to boot: the [%s] disk is core\'s — local, never served, rooted at [%s], answering a failure '
                    .'with false (ADR-042 decisions 4 and 4a) — and something after core\'s provider changed its [%s]. '
                    .'Leave the name to core: private media goes elsewhere by pointing `kitsune.media.disks.private` at a '
                    .'disk of your own, and the intake has no alternative.',
                    $name,
                    (string) $written['root'],
                    implode(', ', $changed),
                ));
            }
        }
    }

    /**
     * The keys in which a disk's definition differs from the one core wrote: each changed, missing or added — all of
     * them, when it is no definition at all. A key set to null is one left out, as Laravel reads both; the root is
     * compared as the filesystem resolves it.
     *
     * @param  array<string, mixed>  $written
     * @return list<string>
     */
    private static function changedKeys(array $written, mixed $disk): array
    {
        if (! is_array($disk)) {
            return array_keys($written);
        }

        $changed = [];

        foreach (array_keys($written + $disk) as $key) {
            $same = $key === 'root'
                ? is_string($disk['root'] ?? null) && self::normalised($disk['root']) === self::normalised((string) $written['root'])
                : ($disk[$key] ?? null) === ($written[$key] ?? null);

            if (! $same) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
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
     * ⚠️ ONE COMPONENT AT A TIME, AS THE FILESYSTEM WALKS IT. Symlinks are how deployments share storage — a release's
     * `storage` is commonly a link to a shared directory — so each part that exists is resolved through `realpath()`.
     * A part that does not exist yet is kept as written: the intake disk before its first upload, whose directory
     * `mkdir` will create.
     *
     * ⚠️ AND `..` STEPS BACK FROM WHAT HAS BEEN RESOLVED SO FAR — Codex, #152. The first version resolved the nearest
     * existing parent and kept the rest literally, so `<base>/missing/../public/intake` stayed as written while `mkdir`
     * creates `missing` and writes into `<base>/public/intake`, and a served disk at `<base>/public` held the intake
     * unnoticed. Collapsing `..` as text instead would be wrong through a symlink, whose `..` is its target's parent;
     * stepping back from the resolved path is right in both cases.
     */
    private static function normalised(string $root): string
    {
        $path = str_replace('\\', '/', $root);

        if (! str_starts_with($path, '/')) {
            $path = str_replace('\\', '/', (string) getcwd()).'/'.$path;
        }

        $resolved = '';

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                $resolved = dirname($resolved === '' ? '/' : $resolved);
                $resolved = $resolved === '/' ? '' : $resolved;

                continue;
            }

            $next = $resolved.'/'.$part;
            $real = realpath($next);
            $resolved = $real !== false ? rtrim(str_replace('\\', '/', $real), '/') : $next;
        }

        return $resolved.'/';
    }
}
