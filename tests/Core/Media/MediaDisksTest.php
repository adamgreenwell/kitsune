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

    /**
     * ⚠️ `..` WHERE THE FILESYSTEM PUTS IT — Codex, #152. `mkdir` creates a missing directory and then steps back out
     * of it, so a root of `<base>/missing/../public/intake` lands inside `<base>/public`. With its control: a root that
     * uses `..` to leave the served disk is apart from it, so `..` is resolved rather than refused on sight.
     */
    it('resolves `..` in a root as the filesystem will', function (): void {
        $base = realpath(sys_get_temp_dir()).'/kitsune-dots-'.bin2hex(random_bytes(4));
        mkdir($base.'/public', 0777, true);

        $disks = fn (string $intake): Repository => new Repository(['filesystems' => ['disks' => [
            'public' => ['driver' => 'local', 'root' => $base.'/public', 'serve' => true],
            MediaDisks::INTAKE => ['driver' => 'local', 'root' => $intake],
        ]]]);

        try {
            expect(fn () => MediaDisks::refuseOverlaps($disks($base.'/missing/../public/intake')))
                ->toThrow(RuntimeException::class, 'kitsune-intake')
                ->and(fn () => MediaDisks::refuseOverlaps($disks($base.'/public/../kitsune/intake')))
                ->not->toThrow(RuntimeException::class);
        } finally {
            rmdir($base.'/public');
            rmdir($base);
        }
    });

    /**
     * And `..` after a symlink is its target's parent, not the link's: a root reached through `<b>/link/..` lands in
     * the directory holding the link's target. Collapsed as text, it would land beside the link and miss the overlap.
     */
    it('steps back from a symlink\'s target, not from the link', function (): void {
        $a = realpath(sys_get_temp_dir()).'/kitsune-target-'.bin2hex(random_bytes(4));
        $b = realpath(sys_get_temp_dir()).'/kitsune-linker-'.bin2hex(random_bytes(4));
        mkdir($a.'/real', 0777, true);
        mkdir($a.'/exposed', 0777, true);
        mkdir($b, 0777, true);
        symlink($a.'/real', $b.'/link');

        try {
            $config = new Repository(['filesystems' => ['disks' => [
                'public' => ['driver' => 'local', 'root' => $a.'/exposed', 'serve' => true],
                MediaDisks::INTAKE => ['driver' => 'local', 'root' => $b.'/link/../exposed/intake'],
            ]]]);

            expect(fn () => MediaDisks::refuseOverlaps($config))->toThrow(RuntimeException::class, 'kitsune-intake');
        } finally {
            unlink($b.'/link');
            rmdir($b);
            rmdir($a.'/real');
            rmdir($a.'/exposed');
            rmdir($a);
        }
    });

    /** Wired where the configuration is final: after every provider has booted, which `config:cache` runs too. */
    it('is refused when the provider boots', function (): void {
        config(['filesystems.disks.exports' => ['driver' => 'local', 'root' => storage_path('app/kitsune/intake/exports')]]);

        // The application has booted, so `booted()` runs the check at once.
        expect(fn () => (new KitsuneServiceProvider(app()))->boot())->toThrow(RuntimeException::class, 'Refusing to boot');
    });
});

/*
 * Core's names redefined after core wrote them — Codex, #152. A host's own provider registers and boots after
 * core's, so the definition `define()` wrote is checked again once every provider has booted.
 */
describe('a core disk redefined after core', function (): void {
    /**
     * ⚠️ EACH DEFINITION BUILT IN THE TEST AND CHECKED BEFORE BOOT. A first version built them in nested dataset
     * closures that handed the test an empty array, so every case was refused for being blank and none for what it
     * named — the mutation run found it.
     *
     * ⚠️ CORE'S OWN DEFINITION WITH ONE KEY CHANGED, AND THE REFUSAL NAMES THAT KEY — Codex, #152, found `throw`
     * passing. The definition is compared whole, so a case missing a key core wrote would be refused for the missing
     * key rather than for the one it is about.
     */
    it('is refused when the provider boots, naming what changed', function (string $name, string $redefinition, string $changed): void {
        $written = config('filesystems.disks.'.$name);

        $definition = match ($redefinition) {
            // Livewire presigns straight to the bucket, past the endpoint's gate and rule.
            's3' => ['driver' => 's3', 'bucket' => 'anywhere'],
            // Another driver at core's own root: only the driver differs.
            'sftp at its own root' => [...$written, 'driver' => 'sftp'],
            'served' => [...$written, 'serve' => true],
            'moved' => [...$written, 'root' => storage_path('app/elsewhere')],
            // A sidecar the sweep removed, read by Livewire, becomes an exception where it expects an empty answer.
            'throwing' => [...$written, 'throw' => true],
            // Laravel's local adapter then skips symlinks rather than refusing them.
            'with a key core did not write' => [...$written, 'links' => 'skip'],
        };

        // As a host provider running after core's `register()` would leave it.
        config(['filesystems.disks.'.$name => $definition]);
        expect(config('filesystems.disks.'.$name))->toBe($definition);

        expect(fn () => (new KitsuneServiceProvider(app()))->boot())
            ->toThrow(function (RuntimeException $refused) use ($name, $changed): void {
                expect($refused->getMessage())->toContain("Refusing to boot: the [{$name}] disk is core's")
                    ->and($refused->getMessage())->toContain("changed its [{$changed}]")
                    ->and($refused->getMessage())->not->toContain('anywhere');
            });
    })->with([
        'the intake, on s3' => [MediaDisks::INTAKE, 's3', 'driver, root, serve, throw, report, bucket'],
        'the intake, on another driver at its own root' => [MediaDisks::INTAKE, 'sftp at its own root', 'driver'],
        'the intake, served' => [MediaDisks::INTAKE, 'served', 'serve'],
        'the intake, throwing' => [MediaDisks::INTAKE, 'throwing', 'throw'],
        'the intake, skipping links' => [MediaDisks::INTAKE, 'with a key core did not write', 'links'],
        'the private disk, served' => [MediaDisks::PRIVATE, 'served', 'serve'],
        'the private disk, moved' => [MediaDisks::PRIVATE, 'moved', 'root'],
        'the private disk, throwing' => [MediaDisks::PRIVATE, 'throwing', 'throw'],
    ]);

    /** The control: core's own definitions, as `register()` left them, boot — and a root written another way. */
    it('boots with the definitions core wrote', function (): void {
        expect(fn () => (new KitsuneServiceProvider(app()))->boot())->not->toThrow(RuntimeException::class);

        config(['filesystems.disks.'.MediaDisks::INTAKE.'.root' => storage_path('app/kitsune/../kitsune/intake/')]);

        expect(fn () => (new KitsuneServiceProvider(app()))->boot())->not->toThrow(RuntimeException::class);
    });
});

/*
 * Which disks the web serves, decided as the framework serves them — ADR-042 decision 5 (T12). Laravel's own shipped
 * disks and links, loaded as a host inherits them, with the cases custody has to tell apart added beside them.
 */
describe('the disks the web serves', function (): void {
    /** Laravel's shipped filesystems configuration, with core's disks written into it and these added. */
    function servedConfiguration(array $extra = []): Repository
    {
        // The repository's own framework, which the suite runs on. ⚠️ Not `base_path('vendor/…')`: that is Testbench's
        // skeleton, which holds a `vendor` only where something linked one — a checkout on CI has none.
        $laravel = require dirname(__DIR__, 3).'/vendor/laravel/framework/config/filesystems.php';

        $laravel['disks']['local']['serve'] = true;
        $laravel['disks'] = [...$laravel['disks'], ...$extra];
        $laravel['links'] = [public_path('storage') => storage_path('app/public')];

        $config = new Repository(['filesystems' => $laravel, 'kitsune' => ['media' => ['disks' => ['public' => 'public', 'private' => MediaDisks::PRIVATE]]]]);
        MediaDisks::define($config);

        return $config;
    }

    it('lists what the framework serves, and nothing it does not', function (): void {
        $config = servedConfiguration([
            's3-cdn' => ['driver' => 's3', 'bucket' => 'b', 'url' => 'https://cdn.example.test'],
            'media-cdn' => ['driver' => 'scoped', 'disk' => 's3-cdn', 'prefix' => 'x'],
            'public-scoped' => ['driver' => 'scoped', 'disk' => 'public', 'prefix' => 'p'],
            'under-public' => ['driver' => 'local', 'root' => storage_path('app/public/sub')],
            'pub-serve' => ['driver' => 'local', 'root' => storage_path('app/elsewhere'), 'serve' => true, 'visibility' => 'public'],
            'signed-scoped' => ['driver' => 'scoped', 'disk' => 'local', 'prefix' => 's'],
            'in-docroot' => ['driver' => 'local', 'root' => public_path('assets')],
        ]);

        // Testbench ships `local` unserved; Laravel ships it served, and that is the case to decide.
        expect($config->get('filesystems.disks.local.serve'))->toBeTrue()
            ->and($config->get('filesystems.disks.s3.url'))->toBeNull();

        // The whole list, not `not->toContain(a, b, …)`, which passes as soon as any one of them is missing.
        expect(MediaDisks::servedDisks($config))
            ->toEqualCanonicalizing(['public', 's3-cdn', 'media-cdn', 'public-scoped', 'under-public', 'pub-serve', 'in-docroot']);
    });

    it('refuses a scoped cycle, naming the disk', function (): void {
        $config = servedConfiguration([
            'loop-a' => ['driver' => 'scoped', 'disk' => 'loop-b', 'prefix' => 'a'],
            'loop-b' => ['driver' => 'scoped', 'disk' => 'loop-a', 'prefix' => 'b'],
        ]);

        expect(fn () => MediaDisks::servedDisks($config))->toThrow(RuntimeException::class, 'cycle');
    });
});

/*
 * Disks that cannot keep the promise — ADR-042 decision 5 (T13). Asked of the configuration the application runs with,
 * because a local disk is compared by the directory its instance writes to.
 */
describe('unsafe and coinciding media disks', function (): void {
    afterEach(fn () => Storage::forgetDisk(['public', MediaDisks::PRIVATE, 'twin', 'cdn', 'linked', 'open', 'scoped-public']));

    it('refuses a configuration where withdrawing would keep a file public', function (array $disks, string $private, string $words): void {
        config(['filesystems.disks' => [...config('filesystems.disks'), ...$disks], 'kitsune.media.disks.private' => $private]);
        config(['filesystems.links' => [public_path('storage') => storage_path('app/public'), public_path('linked') => storage_path('app/linked')]]);

        expect(fn () => MediaDisks::refuseUnsafeMediaDisks(config()))->toThrow(RuntimeException::class, $words);
    })->with([
        // Closures where a path is built: the application does not exist while the file loads.
        'the same disk' => [[], 'public', 'share their media/ directory'],
        'two names for one root' => [fn (): array => ['twin' => ['driver' => 'local', 'root' => storage_path('app/public')]], 'twin', 'share their media/ directory'],
        'private inside public\'s media/' => [fn (): array => ['twin' => ['driver' => 'local', 'root' => storage_path('app/public/media/x')]], 'twin', 'share their media/ directory'],
        'a private disk with a url' => [fn (): array => ['cdn' => ['driver' => 'local', 'root' => storage_path('app/cdn'), 'url' => 'https://cdn.example.test']], 'cdn', 'kitsune.media.disks.private'],
        'a private disk a link exposes' => [fn (): array => ['linked' => ['driver' => 'local', 'root' => storage_path('app/linked')]], 'linked', 'kitsune.media.disks.private'],
        'a private disk served publicly' => [fn (): array => ['open' => ['driver' => 'local', 'root' => storage_path('app/open'), 'serve' => true, 'visibility' => 'public']], 'open', 'kitsune.media.disks.private'],
        // Under the document root, with nothing in the configuration saying the web serves it — review.
        'a private disk in the document root' => [fn (): array => ['open' => ['driver' => 'local', 'root' => public_path('private-media')]], 'open', 'the document root'],
    ]);

    /** The control: a disk rooted above both media directories is not either of them. */
    it('allows a disk rooted above both media directories', function (): void {
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => storage_path('app')]]);

        expect(fn () => MediaDisks::refuseUnsafeMediaDisks(config()))->not->toThrow(RuntimeException::class);
    });

    it('compares object stores by bucket, endpoint and media prefix', function (array $a, array $b, bool $coincide): void {
        config(['filesystems.disks.store-a' => $a, 'filesystems.disks.store-b' => $b]);

        $refused = fn () => MediaDisks::refuseCoincidingMediaDisks(config(), 'store-a', 'store-b');

        $coincide
            ? expect($refused)->toThrow(RuntimeException::class, 'share their media/ directory')
            : expect($refused)->not->toThrow(RuntimeException::class);
    })->with([
        'one bucket, one prefix' => [['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'prefix' => 'a'], ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'prefix' => 'a'], true],
        'a prefix inside the other\'s media/' => [['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'prefix' => 'a'], ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'prefix' => 'a/media/x'], true],
        'sibling prefixes' => [['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'prefix' => 'a'], ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'prefix' => 'a/b'], false],
        'another bucket' => [['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'prefix' => 'a'], ['driver' => 's3', 'bucket' => 'c', 'endpoint' => 'e', 'prefix' => 'a'], false],
        // The store's own `root` is a key prefix too, innermost — review found it ignored, both ways.
        'one place, spelt as a root and as a prefix' => [['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'root' => 'site'], ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'prefix' => 'site'], true],
        'one root, one prefix under it' => [['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'root' => 'site'], ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'root' => 'site', 'prefix' => 'media/x'], true],
        'sibling roots in one bucket' => [['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'root' => 'public'], ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'root' => 'private'], false],
    ]);

    it('sees a scoped disk over a bare bucket as the disk whose root is its prefix', function (): void {
        config([
            'filesystems.disks.bucket' => ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e'],
            'filesystems.disks.scoped-site' => ['driver' => 'scoped', 'disk' => 'bucket', 'prefix' => 'site'],
            'filesystems.disks.rooted-site' => ['driver' => 's3', 'bucket' => 'b', 'endpoint' => 'e', 'root' => 'site'],
        ]);

        expect(fn () => MediaDisks::refuseCoincidingMediaDisks(config(), 'scoped-site', 'rooted-site'))
            ->toThrow(RuntimeException::class, 'share their media/ directory');
    });

    it('sees a scoped disk inside another\'s media/', function (): void {
        config(['filesystems.disks.scoped-public' => ['driver' => 'scoped', 'disk' => 'public', 'prefix' => 'media/x']]);

        expect(fn () => MediaDisks::refuseCoincidingMediaDisks(config(), 'public', 'scoped-public'))
            ->toThrow(RuntimeException::class, 'share their media/ directory');
    });
});
