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
