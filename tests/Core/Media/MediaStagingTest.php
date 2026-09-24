<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Lottery;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Http\Middleware\GuardUploadStaging;
use Kitsune\Core\Http\Middleware\HoldMediaStaging;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Media\MediaStaging;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;

/*
 * Livewire's upload staging, owned by core — ADR-042 decision 4.
 *
 * ⚠️ WHAT THE PHP SUITE CAN AND CANNOT SEE. Livewire forces a faked `tmp-for-tests` disk whenever it runs under unit
 * tests, skips CSRF, and its own `Testable::upload()` goes round the endpoint's middleware and signature — and this
 * suite registers no Livewire provider at all. So these read the configuration core pinned, run Livewire's own
 * validate-then-write step with the disk named explicitly, and call the rule and the sweep directly. The endpoint
 * itself, with its session, CSRF, signature and middleware, is exercised in the browser (`media-staging.spec.js`).
 */

/** A file on disk holding these bytes, and the upload PHP would hand over for it. Removed after each test. */
function stagedUpload(string $bytes, string $name, int $error = UPLOAD_ERR_OK): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'kitsune-staging-');
    file_put_contents($path, $bytes);
    $GLOBALS['kitsuneStagedUploads'][] = $path;

    return new UploadedFile($path, $name, null, $error, true);
}

afterEach(function (): void {
    foreach ($GLOBALS['kitsuneStagedUploads'] ?? [] as $path) {
        @unlink($path);
    }

    $GLOBALS['kitsuneStagedUploads'] = [];
});

/** The smallest valid PNG, by its signature — enough for finfo to call it image/png. */
const STAGING_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

/** The first refusal the endpoint's rules give this file, as the uploader would read it. */
function endpointRefusal(UploadedFile|string $file): ?string
{
    $validator = Validator::make(['files' => [$file]], ['files.*' => FileUploadConfiguration::rules()]);

    return $validator->fails() ? $validator->errors()->first('files.0') : null;
}

/** What `MediaIntake` itself says about the same file, or null when it accepts it. */
function intakeRefusal(UploadedFile $file): ?string
{
    try {
        MediaIntake::accept($file->getClientOriginalName(), (string) $file->getRealPath(), (int) $file->getSize());
    } catch (RuntimeException $refused) {
        return $refused->getMessage();
    }

    return null;
}

describe('Livewire\'s staging configuration', function (): void {
    it('stages on the intake disk, which is local', function (): void {
        /* From config, not `FileUploadConfiguration::disk()`, which answers `tmp-for-tests` under unit tests. */
        expect(config('livewire.temporary_file_upload.disk'))->toBe(MediaDisks::INTAKE)
            ->and(config('filesystems.disks.'.MediaDisks::INTAKE.'.driver'))->toBe('local')
            ->and(FileUploadConfiguration::isUsingS3())->toBeFalse();
    });

    /** Empty, not absent: absent is Livewire's own list, which includes `svg`. */
    it('previews nothing', function (): void {
        expect(config('livewire.temporary_file_upload.preview_mimes'))->toBe([]);
    });

    /** A configured middleware list REPLACES Livewire's throttle, so keeping it means naming it. */
    it('keeps Livewire\'s throttle on the endpoint and adds the staging gate after it', function (): void {
        $names = array_map(static fn ($middleware): string => (string) $middleware->middleware, FileUploadController::middleware());

        expect($names)->toBe(['web', 'throttle:60,1', GuardUploadStaging::class]);
    });

    it('replaces Livewire\'s 12 MiB rule with the intake rule, by name', function (): void {
        expect(FileUploadConfiguration::rules())->toBe(['bail', 'required', 'file', MediaStaging::RULE])
            ->and(implode('|', FileUploadConfiguration::rules()))->not->toContain('max:');
    });

    /** `deploy/release.sh` runs `config:cache`, which exports the config and reads it back. */
    it('holds nothing config:cache cannot export', function (): void {
        $staging = config('livewire.temporary_file_upload');

        expect(eval('return '.var_export($staging, true).';'))->toBe($staging)
            ->and(array_filter($staging['rules'], static fn (mixed $rule): bool => ! is_string($rule)))->toBe([])
            ->and(array_filter($staging['middleware'], static fn (mixed $entry): bool => ! is_string($entry)))->toBe([]);
    });

    /** Over a host's own values, key by key, so the keys core does not own keep whatever was set. */
    it('overrides a host\'s own staging keys and leaves the rest alone', function (): void {
        $config = new Repository(['livewire' => ['temporary_file_upload' => [
            'disk' => 'local',
            'rules' => null,
            'directory' => 'host-tmp',
            'middleware' => 'throttle:5,1',
            'preview_mimes' => ['png', 'svg'],
            'max_upload_time' => 9,
            'cleanup' => false,
        ]]]);

        MediaStaging::pin($config);

        expect($config->get('livewire.temporary_file_upload'))->toBe([
            'disk' => MediaDisks::INTAKE,
            'rules' => MediaStaging::RULES,
            'directory' => 'host-tmp',
            'middleware' => [MediaStaging::THROTTLE, GuardUploadStaging::class],
            'preview_mimes' => [],
            'max_upload_time' => 9,
            'cleanup' => false,
        ]);
    });
});

describe('the endpoint rule', function (): void {
    it('accepts a file MediaIntake accepts', function (): void {
        expect(endpointRefusal(stagedUpload(STAGING_PNG, 'photo.png')))->toBeNull();
    });

    /**
     * ⚠️ ONE ENCODING: THE RULE IS `MediaIntake`, NOT A COPY OF IT. Each refusal must read exactly as `MediaIntake`
     * words it for the same file — a type, a disguise, no extension, the SVG that nothing can sanitise here, and
     * both ceilings, which `MediaIntake` checks against the size PHP measured rather than the size the client sent.
     */
    it('refuses what MediaIntake refuses, in MediaIntake\'s own words', function (UploadedFile $file): void {
        $expected = intakeRefusal($file);

        expect($expected)->not->toBeNull()
            ->and(endpointRefusal($file))->toBe($expected);
    })->with([
        'a script' => fn (): UploadedFile => stagedUpload('<?php echo 1;', 'evil.php'),
        'text named as an image' => fn (): UploadedFile => stagedUpload('just text', 'photo.png'),
        'no extension' => fn (): UploadedFile => stagedUpload(STAGING_PNG, 'photo'),
        'svg with no sanitiser' => fn (): UploadedFile => stagedUpload('<svg xmlns="http://www.w3.org/2000/svg"/>', 'logo.svg'),
        'over 64 MiB' => fn (): UploadedFile => UploadedFile::fake()->create('big.png', 65_537),
        'svg over 2 MiB' => fn (): UploadedFile => UploadedFile::fake()->create('big.svg', 2_049),
    ]);

    /** A client's filename is data: the validator's placeholders inside it must reach the uploader untouched. */
    it('delivers the refusal word for word, whatever the filename holds', function (): void {
        $refusal = endpointRefusal(stagedUpload('<?php echo 1;', ':attribute :input.php'));

        expect($refusal)->toContain('[:attribute :input.php]')
            ->and($refusal)->not->toContain('files.0');
    });

    it('refuses an upload PHP marked incomplete, and a value that is not a file', function (): void {
        expect(endpointRefusal(stagedUpload(STAGING_PNG, 'photo.png', UPLOAD_ERR_PARTIAL)))->not->toBeNull()
            ->and(endpointRefusal('photo.png'))->not->toBeNull()
            ->and(MediaStaging::passes(stagedUpload(STAGING_PNG, 'photo.png', UPLOAD_ERR_PARTIAL)))->toBeFalse()
            ->and(MediaStaging::passes('photo.png'))->toBeFalse();
    });

    /**
     * ⚠️ THE RULE RUNS BEFORE LIVEWIRE WRITES, and this is Livewire's own validate-then-write step, the disk named
     * explicitly because its unit-test mode would otherwise substitute one. With its control: a file the rule
     * accepts is staged, sidecar and all — so an empty disk after a refusal is the refusal, not a step that never
     * writes.
     */
    it('lets nothing reach the intake disk when it refuses', function (): void {
        Storage::fake(MediaDisks::INTAKE);

        // Livewire signs what it staged, and Testbench ships no key to sign with.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        expect(fn () => (new FileUploadController)->validateAndStore([stagedUpload('<?php echo 1;', 'evil.php')], MediaDisks::INTAKE))
            ->toThrow(ValidationException::class);

        expect(Storage::disk(MediaDisks::INTAKE)->allFiles())->toBe([]);

        (new FileUploadController)->validateAndStore([stagedUpload(STAGING_PNG, 'photo.png')], MediaDisks::INTAKE);

        expect(Storage::disk(MediaDisks::INTAKE)->allFiles())->toHaveCount(2);
    });
});

/** Files on a faked disk, each with its modification time set this many hours ago. */
function stagedAt(string $disk, array $hoursAgoByPath): void
{
    foreach ($hoursAgoByPath as $path => $hoursAgo) {
        Storage::disk($disk)->put($path, 'x');
        touch(Storage::disk($disk)->path($path), Carbon::now()->getTimestamp() - $hoursAgo * 3600);
    }
}

/** @return list<string> */
function stagedFiles(string $disk): array
{
    $files = Storage::disk($disk)->allFiles();
    sort($files);

    return $files;
}

describe('the sweep', function (): void {
    beforeEach(function (): void {
        Storage::fake(MediaDisks::INTAKE);

        stagedAt(MediaDisks::INTAKE, [
            'livewire-tmp/old.png' => 25,
            'livewire-tmp/old.png.json' => 25,
            // A stale file whose sidecar is younger: the sidecar goes with its file whatever its own age.
            'livewire-tmp/odd.png' => 25,
            'livewire-tmp/odd.png.json' => 1,
            'livewire-tmp/new.png' => 23,
            'livewire-tmp/new.png.json' => 23,
            // Outside Livewire's directory, which a host may move: the whole disk is swept.
            'elsewhere/old.bin' => 25,
            // A sidecar whose file is already gone, which goes by its own age.
            'livewire-tmp/orphan.png.json' => 25,
        ]);
    });

    it('removes staged files and sidecars older than a day, and leaves younger ones', function (): void {
        $swept = MediaStaging::sweep();

        expect(stagedFiles(MediaDisks::INTAKE))->toBe(['livewire-tmp/new.png', 'livewire-tmp/new.png.json'])
            ->and($swept['removed'])->toBe(count($swept['stale']));
    });

    it('only lists when asked not to delete', function (): void {
        $swept = MediaStaging::sweep(delete: false);

        expect($swept['stale'])->toContain('livewire-tmp/old.png', 'elsewhere/old.bin', 'livewire-tmp/orphan.png.json')
            ->and($swept['stale'])->not->toContain('livewire-tmp/new.png', 'livewire-tmp/odd.png.json')
            ->and(stagedFiles(MediaDisks::INTAKE))->toHaveCount(8);
    });
});

describe('kitsune:media-intake-sweep', function (): void {
    beforeEach(function (): void {
        Storage::fake(MediaDisks::INTAKE);

        stagedAt(MediaDisks::INTAKE, [
            'livewire-tmp/old.png' => 25,
            'livewire-tmp/old.png.json' => 25,
            'livewire-tmp/new.png' => 2,
            'livewire-tmp/new.png.json' => 2,
        ]);
    });

    it('lists stale staged files and deletes nothing without --force', function (): void {
        $this->artisan('kitsune:media-intake-sweep')
            ->expectsOutputToContain('livewire-tmp/old.png')
            ->assertSuccessful();

        expect(stagedFiles(MediaDisks::INTAKE))->toHaveCount(4);
    });

    it('deletes them with --force and leaves younger ones', function (): void {
        $this->artisan('kitsune:media-intake-sweep --force')->assertSuccessful();

        expect(stagedFiles(MediaDisks::INTAKE))->toBe(['livewire-tmp/new.png', 'livewire-tmp/new.png.json']);
    });

    /**
     * ⚠️ A DISK A LATER CALLBACK CHANGED IS REFUSED, AND NOTHING ON IT DELETED — Codex, #152. The command and the
     * scheduler never pass the request middleware, and a host's `booted()` callback runs after core's check at boot.
     */
    it('refuses a disk a later callback redefined or overlapped, deleting nothing', function (string $change): void {
        config(match ($change) {
            'redefined' => ['filesystems.disks.'.MediaDisks::INTAKE.'.throw' => true],
            'overlapped' => ['filesystems.disks.exports' => ['driver' => 'local', 'root' => storage_path('app/kitsune/intake/exports')]],
        });

        $this->artisan('kitsune:media-intake-sweep --force')
            ->expectsOutputToContain($change === 'redefined' ? 'the [kitsune-intake] disk is core\'s' : 'core\'s [kitsune-intake] disk')
            ->assertFailed();

        expect(stagedFiles(MediaDisks::INTAKE))->toHaveCount(4);
    })->with(['redefined', 'overlapped']);

    /** For an installation that runs a scheduler — and none needs one: every accepted upload sweeps, and so may any request. */
    it('is scheduled hourly, deleting', function (): void {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn ($event): bool => str_contains((string) $event->command, 'kitsune:media-intake-sweep'),
        ));

        expect($events)->toHaveCount(1)
            ->and((string) $events[0]->command)->toContain('--force')
            ->and($events[0]->expression)->toBe('0 * * * *');
    });
});

/*
 * ⚠️ AND AGAIN ON EVERY REQUEST — Codex, #152. A host provider's own `booted()` callback runs after core's check at
 * boot, so what it changes is put back, or refused, by the global middleware, which runs after every callback.
 */
describe('on every request', function (): void {
    beforeEach(function (): void {
        Route::get('/kitsune-staging-probe', static fn (): string => (string) config('livewire.temporary_file_upload.disk'));
    });

    afterEach(fn () => Lottery::determineResultNormally());

    /**
     * ⚠️ AND A SWEEP BY LOTTERY AFTER THE RESPONSE — Codex, #152. The sweep after an upload leaves the last batch until
     * the next upload, which may never come; any request may draw it instead. Both draws, so a sweep that always or
     * never runs fails one of them.
     */
    it('sweeps stale staged files after the response when the lottery says so, and only then', function (): void {
        Storage::fake(MediaDisks::INTAKE);
        stagedAt(MediaDisks::INTAKE, ['livewire-tmp/old.png' => 25, 'livewire-tmp/new.png' => 1]);

        Lottery::alwaysLose();
        $this->get('/kitsune-staging-probe')->assertOk();

        expect(stagedFiles(MediaDisks::INTAKE))->toHaveCount(2);

        Lottery::alwaysWin();
        $this->get('/kitsune-staging-probe')->assertOk();

        expect(stagedFiles(MediaDisks::INTAKE))->toBe(['livewire-tmp/new.png']);
    });

    it('draws at Laravel\'s session odds, 2 in 100', function (): void {
        $drawn = [];
        Lottery::setResultFactory(function (int $chances, ?int $outOf) use (&$drawn): bool {
            $drawn[] = [$chances, $outOf];

            return false;
        });

        $this->get('/kitsune-staging-probe')->assertOk();

        expect($drawn)->toBe([[2, 100]]);
    });

    it('reports a sweep that fails, and answers the request all the same', function (): void {
        Exceptions::fake();
        Lottery::alwaysWin();
        Storage::shouldReceive('disk')->with(MediaDisks::INTAKE)->andThrow(new RuntimeException('the intake disk is gone'));

        $this->get('/kitsune-staging-probe')->assertOk();

        Exceptions::assertReported(fn (RuntimeException $failed): bool => $failed->getMessage() === 'the intake disk is gone');
    });

    it('runs first, before any other global middleware', function (): void {
        expect(app(HttpKernel::class)->getGlobalMiddleware()[0] ?? null)->toBe(HoldMediaStaging::class);
    });

    it('puts back a staging key a later callback changed', function (): void {
        // As a host's `booted()` callback, running after core's, would leave it.
        config(['livewire.temporary_file_upload.disk' => 'local']);

        $this->get('/kitsune-staging-probe')->assertOk()->assertSee(MediaDisks::INTAKE);
    });

    it('refuses a disk a later callback put inside core\'s', function (): void {
        config(['filesystems.disks.exports' => ['driver' => 'local', 'root' => storage_path('app/kitsune/intake/exports')]]);

        expect(fn () => $this->withoutExceptionHandling()->get('/kitsune-staging-probe'))
            ->toThrow(RuntimeException::class, 'Refusing to boot: core\'s [kitsune-intake] disk');
    });

    it('refuses a core disk a later callback redefined', function (): void {
        config(['filesystems.disks.'.MediaDisks::INTAKE => ['driver' => 's3', 'bucket' => 'anywhere']]);

        expect(fn () => $this->withoutExceptionHandling()->get('/kitsune-staging-probe'))
            ->toThrow(RuntimeException::class, 'Refusing to boot: the [kitsune-intake] disk is core\'s');
    });
});
