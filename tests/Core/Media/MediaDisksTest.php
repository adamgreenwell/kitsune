<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Support\Facades\Route;
use Kitsune\Core\KitsuneServiceProvider;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;

/*
 * Private media on a disk nothing serves — ADR-042 decision 4a.
 *
 * ⚠️ ASSERTED FROM CONFIG AND THE ROUTE TABLE, NOT FROM A FAKE. `Storage::fake()` replaces a disk's definition,
 * so a test that faked this disk could not see what core defined for it. These read the definition the service
 * provider wrote, and the routes Laravel registered from it.
 */

it('sends private media to core\'s own disk unless a host points it elsewhere', function (): void {
    expect(MediaLibrary::diskFor('private'))->toBe(MediaDisks::PRIVATE)
        ->and(MediaLibrary::diskFor('public'))->toBe('public');

    /* The fallback, for a host whose `config/kitsune.php` restated `media` and left the key out. */
    config(['kitsune.media.disks' => []]);

    expect(MediaLibrary::diskFor('private'))->toBe(MediaDisks::PRIVATE);
});

it('defines the disk as local and never served', function (): void {
    $disk = config('filesystems.disks.'.MediaDisks::PRIVATE);

    expect($disk['driver'] ?? null)->toBe('local')
        ->and($disk['serve'] ?? null)->toBeFalse();
});

/**
 * ⚠️ WITH ITS CONTROL, ARRANGED BECAUSE THIS HOST HAS NONE. Laravel's shipped `local` is served, but Testbench's
 * copy of it is not, so on its own "no route for this disk" would hold just as well if the route table could
 * show no served disk at all. So `local` is marked served the way a real application ships it, and the
 * framework's own registration pass is run again over the disks as core left them: it must route `local` and
 * must not route this one.
 */
it('registers no route that serves it', function (): void {
    expect(Route::has('storage.local'))->toBeFalse();

    config(['filesystems.disks.local.serve' => true]);

    /* The app has booted, so `booted()` runs each registration immediately. */
    (new FilesystemServiceProvider(app()))->boot();
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('storage.local'))->toBeTrue()
        ->and(Route::has('storage.'.MediaDisks::PRIVATE))->toBeFalse();
});

/**
 * A disk nested under another local disk is served by it whenever that one is — `local` through its signed route,
 * `public` through the symlink — whatever this one's own flag says.
 */
it('keeps its root outside every other local disk\'s root', function (): void {
    $root = rtrim((string) config('filesystems.disks.'.MediaDisks::PRIVATE.'.root'), '/').'/';

    $others = array_filter(
        (array) config('filesystems.disks'),
        static fn (mixed $disk, string $name): bool => $name !== MediaDisks::PRIVATE
            && is_array($disk) && ($disk['driver'] ?? null) === 'local',
        ARRAY_FILTER_USE_BOTH,
    );

    expect(array_keys($others))->toContain('local', 'public');

    foreach ($others as $name => $disk) {
        $otherRoot = rtrim((string) ($disk['root'] ?? ''), '/').'/';

        expect(str_starts_with($root, $otherRoot))->toBeFalse("[{$name}] is rooted at {$otherRoot}, which holds {$root}");
    }
});

/** The name is core's: a host's definition under it is replaced, because the promise is about the definition. */
it('replaces a definition a host gave the same name', function (): void {
    $config = new Repository(['filesystems' => ['disks' => [
        MediaDisks::PRIVATE => ['driver' => 'local', 'root' => '/srv/anywhere', 'serve' => true],
    ]]]);

    MediaDisks::define($config);

    expect($config->get('filesystems.disks.'.MediaDisks::PRIVATE.'.serve'))->toBeFalse()
        ->and($config->get('filesystems.disks.'.MediaDisks::PRIVATE.'.root'))->not->toBe('/srv/anywhere');
});

/**
 * ⚠️ IN `register()`, NOT `boot()`, and the placement is the guarantee. `FilesystemServiceProvider::boot()` reads
 * every disk's `serve` flag to decide which get a route, and Laravel's own providers boot before a package's — so a
 * definition written in `boot()` would come after that read, and a host's served definition under this name would
 * already have its route. Registering the provider again, without booting it, has to put core's definition back.
 */
it('writes the definition when the provider registers, before anything boots', function (): void {
    config(['filesystems.disks.'.MediaDisks::PRIVATE => ['driver' => 'local', 'root' => '/srv/anywhere', 'serve' => true]]);

    (new KitsuneServiceProvider(app()))->register();

    expect(config('filesystems.disks.'.MediaDisks::PRIVATE.'.serve'))->toBeFalse();
});

/*
 * The upload intake — ADR-042 decision 4. Livewire stages every upload here before anything has accepted it.
 */
describe('the intake disk', function (): void {
    it('is local and never served', function (): void {
        $disk = config('filesystems.disks.'.MediaDisks::INTAKE);

        /* Local is the security property: on an `s3` disk Livewire presigns a PUT that no middleware or rule sees. */
        expect($disk['driver'] ?? null)->toBe('local')
            ->and($disk['serve'] ?? null)->toBeFalse();
    });

    /** With `local` marked served as the control, for the reason the private disk's test gives. */
    it('registers no route that serves it', function (): void {
        config(['filesystems.disks.local.serve' => true]);

        (new FilesystemServiceProvider(app()))->boot();
        Route::getRoutes()->refreshNameLookups();

        expect(Route::has('storage.local'))->toBeTrue()
            ->and(Route::has('storage.'.MediaDisks::INTAKE))->toBeFalse();
    });

    /**
     * ⚠️ APART IN BOTH DIRECTIONS, AND THE SECOND ONE IS NEW. Nested under a served disk it would be served; and
     * holding another disk, the sweep — which deletes everything on this disk by age — would delete that disk's
     * files. Rooted at `storage/app/kitsune`, it would hold the private disk.
     */
    it('keeps its root apart from every other local disk\'s, in both directions', function (): void {
        $root = rtrim((string) config('filesystems.disks.'.MediaDisks::INTAKE.'.root'), '/').'/';

        $others = array_filter(
            (array) config('filesystems.disks'),
            static fn (mixed $disk, string $name): bool => $name !== MediaDisks::INTAKE
                && is_array($disk) && ($disk['driver'] ?? null) === 'local',
            ARRAY_FILTER_USE_BOTH,
        );

        expect(array_keys($others))->toContain('local', 'public', MediaDisks::PRIVATE);

        foreach ($others as $name => $disk) {
            $otherRoot = rtrim((string) ($disk['root'] ?? ''), '/').'/';

            expect(str_starts_with($root, $otherRoot))->toBeFalse("[{$name}] is rooted at {$otherRoot}, which holds {$root}")
                ->and(str_starts_with($otherRoot, $root))->toBeFalse("the intake at {$root} holds [{$name}] at {$otherRoot}");
        }
    });

    it('replaces a definition a host gave the same name, when the provider registers', function (): void {
        $config = new Repository(['filesystems' => ['disks' => [
            MediaDisks::INTAKE => ['driver' => 's3', 'bucket' => 'anywhere', 'serve' => true],
        ]]]);

        MediaDisks::define($config);

        expect($config->get('filesystems.disks.'.MediaDisks::INTAKE.'.driver'))->toBe('local')
            ->and($config->get('filesystems.disks.'.MediaDisks::INTAKE.'.serve'))->toBeFalse();

        config(['filesystems.disks.'.MediaDisks::INTAKE => ['driver' => 's3', 'bucket' => 'anywhere']]);

        (new KitsuneServiceProvider(app()))->register();

        expect(config('filesystems.disks.'.MediaDisks::INTAKE.'.driver'))->toBe('local');
    });
});

/*
 * A host's own disks against core's — Codex, #152. `define()` owns the two names, not where the host's disks are, so
 * the configuration as the host finished it is checked once every provider has booted.
 */
describe('a host disk that overlaps core\'s', function (): void {
    /** Core's two disks as `define()` writes them, beside whatever the host configured. */
    function withHostDisks(array $disks, array $links = []): Repository
    {
        $config = new Repository(['filesystems' => ['disks' => $disks, 'links' => $links]]);
        MediaDisks::define($config);

        return $config;
    }

    it('refuses a served disk, or a public link, that holds core\'s disks', function (array $disks, array $links): void {
        expect(fn () => MediaDisks::refuseOverlaps(withHostDisks($disks, $links)))
            ->toThrow(RuntimeException::class, 'Refusing to boot: core\'s [kitsune-private] disk');
    })->with([
        // Closures: the paths need the application, which does not exist while the file loads.
        'a served disk at storage/app' => [fn (): array => ['local' => ['driver' => 'local', 'root' => storage_path('app'), 'serve' => true]], fn (): array => []],
        'a public link to storage/app' => [fn (): array => [], fn (): array => [public_path('files') => storage_path('app')]],
    ]);

    /** Laravel 10 and earlier rooted `local` at `storage/app`; a disk nothing serves exposes nothing. */
    it('allows a disk nothing serves or links to hold them', function (): void {
        expect(fn () => MediaDisks::refuseOverlaps(withHostDisks(
            ['local' => ['driver' => 'local', 'root' => storage_path('app')], 'public' => ['driver' => 'local', 'root' => storage_path('app/public')]],
            [public_path('storage') => storage_path('app/public')],
        )))->not->toThrow(RuntimeException::class);
    });

    /** The sweep deletes everything on the intake disk by age, so nothing of the host's may be inside it. */
    it('refuses any disk inside core\'s, served or not', function (string $core, string $inside): void {
        expect(fn () => MediaDisks::refuseOverlaps(withHostDisks(['exports' => ['driver' => 'local', 'root' => storage_path($inside)]])))
            ->toThrow(RuntimeException::class, "core's [{$core}] disk");
    })->with([
        'inside the intake' => [MediaDisks::INTAKE, 'app/kitsune/intake/exports'],
        'inside the private disk' => [MediaDisks::PRIVATE, 'app/kitsune/private/exports'],
    ]);

    /**
     * ⚠️ THROUGH A SYMLINK, as deployments share storage: the host's disk named by the shared directory, core's through
     * the release's link to it, and core's own directory not there yet. Compared as strings they never meet.
     */
    it('sees through a symlinked storage directory', function (): void {
        $shared = sys_get_temp_dir().'/kitsune-shared-'.bin2hex(random_bytes(4));
        $release = sys_get_temp_dir().'/kitsune-release-'.bin2hex(random_bytes(4));
        mkdir($shared.'/app', 0777, true);
        symlink($shared, $release);

        try {
            $config = new Repository(['filesystems' => ['disks' => [
                'local' => ['driver' => 'local', 'root' => $shared.'/app', 'serve' => true],
                MediaDisks::INTAKE => ['driver' => 'local', 'root' => $release.'/app/kitsune/intake'],
            ]]]);

            expect(fn () => MediaDisks::refuseOverlaps($config))->toThrow(RuntimeException::class, 'kitsune-intake');
        } finally {
            unlink($release);
            rmdir($shared.'/app');
            rmdir($shared);
        }
    });

    /** Wired where the configuration is final: after every provider has booted, which `config:cache` runs too. */
    it('is refused when the provider boots', function (): void {
        config(['filesystems.disks.exports' => ['driver' => 'local', 'root' => storage_path('app/kitsune/intake/exports')]]);

        // The application has booted, so `booted()` runs the check at once.
        expect(fn () => (new KitsuneServiceProvider(app()))->boot())->toThrow(RuntimeException::class, 'Refusing to boot');
    });
});
