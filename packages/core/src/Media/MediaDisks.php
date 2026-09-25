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
use Illuminate\Support\Facades\Storage;
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

    /** The disk a visibility is stored on, read from this configuration as `MediaLibrary::diskFor()` reads it. */
    public static function configured(Repository $config, string $visibility): string
    {
        $key = $visibility === 'public' ? 'public' : 'private';
        $disk = $config->get("kitsune.media.disks.{$key}");

        return is_string($disk) && $disk !== '' ? $disk : ($key === 'public' ? 'public' : self::PRIVATE);
    }

    /**
     * A disk's configuration as the framework builds it — through every `scoped` layer — ADR-042 decision 5.
     *
     * ⚠️ AS `FilesystemManager::createScopedDriver()` DOES IT: prefixes join from the base disk inward, the outermost
     * visibility set wins, and the url is the base disk's, since a scoped entry's own is never read. A local disk's root
     * is its root with the joined prefix under it; an object store's `prefix` is the key prefix its objects sit under —
     * its own `root`, then the joined prefix.
     *
     * @return array{driver: string, root: ?string, url: ?string, bucket: ?string, endpoint: ?string, prefix: string, visibility: ?string, serve: bool}
     */
    public static function resolved(Repository $config, string $disk): array
    {
        $entry = $config->get("filesystems.disks.{$disk}");
        $prefix = '';
        $visibility = null;
        $seen = [];

        while (is_array($entry) && ($entry['driver'] ?? null) === 'scoped') {
            $parent = $entry['disk'] ?? null;

            if (is_string($parent) && (isset($seen[$parent]) || count($seen) >= 8)) {
                throw new RuntimeException(sprintf('Refusing to read the [%s] disk: its scoped disks form a cycle.', $disk));
            }

            $own = trim((string) ($entry['prefix'] ?? ''), '/');
            $prefix = $own === '' ? $prefix : ($prefix === '' ? $own : $own.'/'.$prefix);
            $visibility ??= is_string($entry['visibility'] ?? null) ? $entry['visibility'] : null;

            if (is_string($parent)) {
                $seen[$parent] = true;
            }

            $entry = is_string($parent) ? $config->get("filesystems.disks.{$parent}") : $parent;
        }

        if (! is_array($entry) || ! is_string($entry['driver'] ?? null)) {
            throw new RuntimeException(sprintf('Refusing to read the [%s] disk: it, or a disk it is scoped over, is not configured.', $disk));
        }

        $base = trim((string) ($entry['prefix'] ?? ''), '/');
        $prefix = trim($base === '' ? $prefix : ($prefix === '' ? $base : $base.'/'.$prefix), '/');
        $local = $entry['driver'] === 'local';

        /*
         * ⚠️ AN OBJECT STORE'S OWN `root` IS A KEY PREFIX INSIDE THE STORE, innermost — Laravel hands it to the adapter,
         * and wraps the disk's `prefix` and every scoped layer around that. Review found `root` left out: an S3 disk at
         * `root` 'site' and another at `prefix` 'site' in the same bucket are one place, and compared as two, so a
         * withdrawal took the file itself for its private copy and deleted it; while two disks with sibling roots
         * compared as one, and refused every trash.
         */
        $storeRoot = $local ? '' : trim((string) ($entry['root'] ?? ''), '/');
        $prefix = $storeRoot === '' ? $prefix : trim($storeRoot.($prefix === '' ? '' : '/'.$prefix), '/');

        return [
            'driver' => $entry['driver'],
            'root' => $local && is_string($entry['root'] ?? null) ? self::normalised(rtrim($entry['root'], '/').($prefix === '' ? '' : '/'.$prefix)) : null,
            'url' => is_string($entry['url'] ?? null) && $entry['url'] !== '' ? $entry['url'] : null,
            'bucket' => is_string($entry['bucket'] ?? $entry['container'] ?? null) ? ($entry['bucket'] ?? $entry['container']) : null,
            'endpoint' => is_string($entry['endpoint'] ?? null) ? $entry['endpoint'] : null,
            'prefix' => $prefix,
            'visibility' => $visibility ?? (is_string($entry['visibility'] ?? null) ? $entry['visibility'] : null),
            'serve' => (bool) ($entry['serve'] ?? false),
        ];
    }

    /**
     * Every disk the web may serve a file from without Kitsune in the way — ADR-042 decision 5.
     *
     * ⚠️ AS THE FRAMEWORK SERVES, NOT AS A NAME SUGGESTS. A disk is served when it has a url (a scoped disk inherits its
     * base disk's); when it is a local disk with `serve` on and public visibility, which Laravel's route answers with no
     * signature; or when its media directory meets the document root, a directory a public link exposes, or such a
     * disk's root. A local disk
     * served only to signatures is not, because Kitsune mints none (finding 4). An object store with no url that is
     * public by its own policy cannot be seen from here — a recorded limit. Core's private and intake disks, and the
     * configured private disk, are never listed: `refuseUnsafeMediaDisks()` refuses a private disk that is served.
     *
     * @return list<string>
     */
    public static function servedDisks(Repository $config): array
    {
        $public = self::configured($config, 'public');
        $private = self::configured($config, 'private');
        $served = [$public];

        foreach (array_keys((array) $config->get('filesystems.disks', [])) as $name) {
            $name = (string) $name;

            if (in_array($name, [$public, $private, self::PRIVATE, self::INTAKE], true)) {
                continue;
            }

            if (self::servedBy($config, $name)) {
                $served[] = $name;
            }
        }

        return $served;
    }

    /** Whether the web may serve this disk's files without Kitsune in the way — the rule `servedDisks()` states. */
    public static function servedBy(Repository $config, string $disk): bool
    {
        $entry = $config->get("filesystems.disks.{$disk}");

        if (! is_array($entry)) {
            return false;
        }

        $resolved = self::resolved($config, $disk);

        // A local disk served with `serve` and public visibility is found below: its own root is one of the web's.
        if ($resolved['url'] !== null) {
            return true;
        }

        if ($resolved['root'] === null) {
            return false;
        }

        $media = $resolved['root'].'media/';

        foreach (self::webRoots($config) as $web) {
            if (str_starts_with($media, $web) || str_starts_with($web, $media)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a disk can hold a file at all: it is not local, or its root exists — asked of its configuration, and
     * nothing is built.
     *
     * ⚠️ BUILDING A LOCAL DISK CREATES ITS ROOT. A served or private disk that was configured and never used would be
     * created by the first step that asked it for a copy, from a read-only listing as much as from a move. A disk whose
     * root does not exist holds nothing, so custody does not ask it. A disk that is not configured is refused: the
     * caller decides whether it can be left out.
     *
     * @throws RuntimeException for a disk that is not configured
     */
    public static function mayHold(Repository $config, string $disk): bool
    {
        $root = self::resolved($config, $disk)['root'];

        return $root === null || is_dir($root);
    }

    /**
     * A disk's `media/` directory as the filesystem resolves it, when the disk is local; null when it is not.
     *
     * It builds the disk, so it is asked only of a disk configured as local.
     */
    public static function mediaRoot(string $disk): ?string
    {
        return MediaBytes::local($disk) ? self::normalised(Storage::disk($disk)->path('media')) : null;
    }

    /**
     * Refuse to move a byte while the configured disks cannot keep the promise — ADR-042 decision 5.
     *
     * The public and private disks must be two places, and the private one must be one the web does not serve: a
     * withdrawn file would otherwise stay public.
     *
     * ⚠️ AND NO SERVED OBJECT STORE MAY REACH THE PRIVATE DISK'S OBJECTS — review of slice 5b. `servedBy()` reads a disk's
     * own url and root, so a private object store with no url of its own passed while a served one reached the same
     * bucket prefix — as one place, or through another endpoint, which cannot be told apart from one place. A pair where
     * both disks are local is not asked: `servedBy()` already refuses a local private disk whose media directory meets
     * a served local disk's root, and asking would build a served disk whose root does not exist.
     */
    public static function refuseUnsafeMediaDisks(Repository $config): void
    {
        $public = self::configured($config, 'public');
        $private = self::configured($config, 'private');

        self::refuseCoincidingMediaDisks($config, $public, $private);

        if (self::servedBy($config, $private)) {
            throw new RuntimeException(sprintf(
                'Refusing: kitsune.media.disks.private names [%s], which the web serves — through a url, `serve` with '
                .'public visibility, a public link or the document root — so a withdrawn file would stay public (ADR-042 '
                .'decision 5). '
                .'Nothing was moved.',
                $private,
            ));
        }

        $privateIsLocal = self::resolved($config, $private)['driver'] === 'local';

        foreach (self::servedDisks($config) as $served) {
            if ($served === $public || ($privateIsLocal && self::resolved($config, $served)['driver'] === 'local')) {
                continue;
            }

            $place = self::onePlace($config, $private, $served);

            if ($place !== false) {
                throw new RuntimeException(sprintf(
                    'Refusing: kitsune.media.disks.private names [%s], and [%s], which the web serves, reaches the same '
                    .'objects — %s — so a withdrawn file would stay public (ADR-042 decision 5). Nothing was moved.',
                    $private,
                    $served,
                    $place === true
                        ? 'one bucket and key prefix'
                        : 'one bucket through two endpoints with nesting key prefixes, which cannot be told apart from one',
                ));
            }
        }
    }

    /**
     * Refuse when a disk shares its `media/` directory with any of the others, or cannot be told apart from one that
     * does — ADR-042 decision 5.
     *
     * Two names, one place: a "copy" from one to the other is the file itself, and deleting "the other copy" deletes the
     * only one. Local disks are compared by their media directories as the filesystem resolves them, and a nest counts;
     * others by driver, bucket, endpoint and prefix — and one bucket through two endpoints is refused too, because
     * whether that is one store cannot be told (slice 5b).
     */
    public static function refuseCoincidingMediaDisks(Repository $config, string $disk, string ...$others): void
    {
        foreach ($others as $other) {
            $place = self::onePlace($config, $disk, $other);

            if ($place === false) {
                continue;
            }

            if ($place === null) {
                throw new RuntimeException(sprintf(
                    'Refusing: the [%s] and [%s] disks name one bucket through two endpoints with nesting key prefixes, so '
                    .'whether they are one store cannot be told — a copy from one to the other could be the file itself '
                    .'(ADR-042 decision 5). Give them key prefixes that do not nest, or one endpoint. Nothing was moved.',
                    $disk,
                    $other,
                ));
            }

            throw new RuntimeException(sprintf(
                'Refusing: the [%s] and [%s] disks share their media/ directory, so a file cannot be withdrawn from one '
                .'to the other — the copy would be the file itself (ADR-042 decision 5). Point kitsune.media.disks.public '
                .'and kitsune.media.disks.private at two places. Nothing was moved.',
                $disk,
                $other,
            ));
        }
    }

    /**
     * Whether two disks are one place: true when they are, false when they are provably two, and null when it cannot be
     * told — one bucket, key prefixes that nest, and two endpoints — ADR-042 decision 5.
     *
     * ⚠️ TWO ENDPOINTS MAY NAME ONE STORE. A region's endpoint and a custom domain, or a path-style and a virtual-host
     * address, reach the same objects under different names, and nothing in the configuration says which. The earlier
     * comparison read them as two places, which let a step take the file itself for another copy and delete it; review
     * of slice 5b found it. Every caller refuses the uncertain answer as it refuses one place.
     *
     * It builds a local disk to resolve its media directory, so it is asked only of a disk that can hold something.
     */
    public static function onePlace(Repository $config, string $a, string $b): ?bool
    {
        if ($a === $b) {
            return true;
        }

        $media = self::mediaDirectories($config, $a, $b);

        if ($media === null) {
            return false;
        }

        [$first, $second, $sameEndpoint] = $media;

        if (! str_starts_with($first, $second) && ! str_starts_with($second, $first)) {
            return false;
        }

        return $sameEndpoint ? true : null;
    }

    /**
     * Whether two disks' media directories nest without being one directory: a local disk rooted inside another's
     * `media/`, or an object store's prefix inside another's in the same bucket — ADR-042 decision 5.
     *
     * ⚠️ `onePlace()` IS TRUE OF BOTH, AND ONLY ONE IS AN ALIAS — review of slice 5b. Two names for one directory list the
     * same files at the same paths; a directory inside another is listed by the outer one under longer paths, where no
     * row names them, so the inner disk's files read as the outer's orphans. It builds a local disk to resolve its
     * media directory, so it is asked only of a disk that can hold something.
     */
    public static function nested(Repository $config, string $a, string $b): bool
    {
        $media = $a === $b ? null : self::mediaDirectories($config, $a, $b);

        if ($media === null) {
            return false;
        }

        [$first, $second] = $media;

        return $first !== $second && (str_starts_with($first, $second) || str_starts_with($second, $first));
    }

    /**
     * Two disks' media directories, comparable: local ones as the filesystem resolves them, or object stores' key
     * prefixes in one bucket, with whether their endpoints are the same; null when they are not in one namespace.
     *
     * @return array{0: string, 1: string, 2: bool}|null
     */
    private static function mediaDirectories(Repository $config, string $a, string $b): ?array
    {
        $one = self::resolved($config, $a);
        $two = self::resolved($config, $b);

        /*
         * Local disks by their media directories as the filesystem resolves them — the instance's, so a faked disk counts
         * at its own root. Only a disk configured as local is built here: building an object store needs its SDK.
         */
        if ($one['driver'] === 'local' && $two['driver'] === 'local') {
            return [self::localMediaRoot($config, $a, $one), self::localMediaRoot($config, $b, $two), true];
        }

        if ($one['driver'] !== $two['driver'] || $one['bucket'] !== $two['bucket']) {
            return null;
        }

        return [
            ($one['prefix'] === '' ? '' : $one['prefix'].'/').'media/',
            ($two['prefix'] === '' ? '' : $two['prefix'].'/').'media/',
            $one['endpoint'] === $two['endpoint'],
        ];
    }

    /**
     * A local disk's media directory: its instance's, so a faked disk counts at its own root — except a scoped disk,
     * which is not built here (it needs Flysystem's path-prefixing package) and is rooted as the framework would root it.
     *
     * @param  array{root: ?string}  $resolved
     */
    private static function localMediaRoot(Repository $config, string $disk, array $resolved): string
    {
        $scoped = ($config->get("filesystems.disks.{$disk}.driver") ?? null) === 'scoped';

        return ($scoped ? null : self::mediaRoot($disk)) ?? ($resolved['root'] ?? '').'media/';
    }

    /** @param  array<string, mixed>  $entry */
    private static function servesPublicly(array $entry): bool
    {
        return ($entry['driver'] ?? null) === 'local'
            && (bool) ($entry['serve'] ?? false)
            && ($entry['visibility'] ?? 'private') === 'public';
    }

    /**
     * Directories the web serves as they are: the document root, public link targets, and the roots of local disks
     * served with a url or with `serve` and public visibility.
     *
     * @return list<string>
     */
    private static function webRoots(Repository $config): array
    {
        $roots = [];

        foreach ((array) $config->get('filesystems.links', []) as $target) {
            if (is_string($target) && $target !== '') {
                $roots[] = self::normalised($target);
            }
        }

        /*
         * ⚠️ AND THE WEB SERVER'S DOCUMENT ROOT ITSELF — review. A link's target counts because its source sits in
         * `public/`; a disk rooted in `public/` is served the same way, with nothing in the configuration to say so, and
         * a private disk there would take every withdrawn file onto the web.
         */
        $roots[] = self::normalised(public_path());

        foreach (array_keys((array) $config->get('filesystems.disks', [])) as $name) {
            $entry = $config->get("filesystems.disks.{$name}");

            if (! is_array($entry)) {
                continue;
            }

            try {
                $resolved = self::resolved($config, (string) $name);
            } catch (RuntimeException) {
                continue;
            }

            if ($resolved['root'] !== null && ($resolved['url'] !== null || self::servesPublicly($entry))) {
                $roots[] = $resolved['root'];
            }
        }

        return $roots;
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
