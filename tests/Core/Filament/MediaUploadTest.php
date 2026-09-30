<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\MediaUpload;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\Pages\CreateEntry;
use Kitsune\Core\Filament\Resources\Entries\Pages\EditEntry;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Media\MediaCustodyFailure;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\PanelTenancy;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;
use League\Flysystem\UnableToWriteFile;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * The media list's Upload action — ADR-042 decisions 3, 4 and 7.
 *
 * ⚠️ THE HANDLER IS DRIVEN WITH REAL STAGED FILES, each written as Livewire writes one — the file and its `.json` sidecar
 * on Livewire's temporary disk — so "nothing staged survives" is read off the disk, not assumed. The action's guards are
 * asked of the action itself, as `UploadSurfaceTest` asks the rich editor's: the suite mounts no Livewire component, and
 * the browser suite (`e2e/media-upload.spec.js`) drives the modal where FilePond and the endpoint really run.
 */

const UPLOAD_ACTION_PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

/** A file staged as Livewire stages one: the bytes and a sidecar naming it, on its temporary disk. */
function stagedForUpload(string $bytes, string $name): TemporaryUploadedFile
{
    $stored = FileUploadConfiguration::storeTemporaryFile(UploadedFile::fake()->createWithContent($name, $bytes), FileUploadConfiguration::disk());

    return new TemporaryUploadedFile(basename((string) $stored), FileUploadConfiguration::disk());
}

/** Everything still on Livewire's temporary disk. @return list<string> */
function stillStaged(): array
{
    return Storage::disk(FileUploadConfiguration::disk())->allFiles();
}

/** @return list<array<string, mixed>> */
function uploadNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

/** A file stored through the library itself, as the panel's upload would store it. */
function storedMedia(string $bytes, string $name, EntryType $type, string $visibility = 'private', bool $siteOnly = true): Entry
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-upload-');
    file_put_contents($source, $bytes);

    try {
        return MediaLibrary::store($source, $name, $type, $visibility, null, $siteOnly);
    } finally {
        unlink($source);
    }
}

/** A media entry's File section, built as its edit page builds it. @return array<string, Component> */
function fileSection(Entry $entry): array
{
    return uploadComponents(EntryResource::form(Schema::make(app(EditEntry::class))->record($entry)));
}

/** Every component in a schema, nested ones included, keyed by name where it has one. @return array<string, Component> */
function uploadComponents(Schema $schema): array
{
    $found = [];

    foreach ($schema->getFlatComponents(withHidden: true) as $component) {
        $key = method_exists($component, 'getName') ? $component->getName() : null;
        $found[$key ?? spl_object_id($component)] = $component;
    }

    return $found;
}

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    Storage::fake(FileUploadConfiguration::disk());
    Storage::fake(MediaDisks::PRIVATE);
    Storage::fake('public');

    $this->org = Org::create(['slug' => 'uploads', 'name' => 'Uploads']);
    app(Context::class)->setOrg($this->org);
    // Not UTC, so an instant shown anywhere but in the site's timezone is seen to be.
    $this->site = Site::create(['handle' => 'main', 'slug' => 'uploads-main', 'name' => 'Main', 'locale' => 'en', 'settings' => ['timezone' => 'Asia/Tokyo']]);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create(['org_id' => $this->org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);
    app()->instance(EntryType::class, $this->type);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'uploader@kitsune.test']);
    $this->user = $user;
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    $this->role = Role::create(['handle' => 'uploader', 'name' => 'Uploader']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $user->getKey()]);

    $this->grant = function (string ...$actions): void {
        foreach ($actions as $action) {
            $this->role->grant(Permissions::forEntryType('image', $action));
        }
    };

    $this->actingAs($user);
    $this->roots = [];
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

describe('the handler', function (): void {
    beforeEach(fn () => ($this->grant)('view', 'create', 'publish'));

    it('stores a file private and shared, and leaves nothing staged', function (): void {
        $results = MediaUpload::store($this->user, $this->type, [stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png')], 'private', false, false);

        $entry = Entry::query()->where('title', 'photo')->firstOrFail();

        expect($results)->toBe([['name' => 'photo.png', 'outcome' => MediaUpload::STORED, 'reason' => null]])
            ->and($entry->site_id)->toBeNull()
            ->and($entry->status)->toBe('published')
            ->and(MediaFile::query()->where('entry_id', $entry->id)->value('visibility'))->toBe('private')
            ->and(stillStaged())->toBe([]);
    });

    /* ADR-041 makes public an explicit act, and decision 3 an acknowledged one: without it, stored private — never refused. */
    it('stores a file private when public is chosen without the confirmation, and says so', function (): void {
        $results = MediaUpload::store($this->user, $this->type, [stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png')], 'public', false, false);

        expect($results[0]['outcome'])->toBe(MediaUpload::KEPT_PRIVATE)
            ->and(MediaFile::query()->value('visibility'))->toBe('private');
    });

    it('stores a file public once public is confirmed', function (): void {
        $results = MediaUpload::store($this->user, $this->type, [stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png')], 'public', true, false);

        expect($results[0]['outcome'])->toBe(MediaUpload::STORED)
            ->and(MediaFile::query()->value('visibility'))->toBe('public');
    });

    /* A confirmation with any other visibility stores private: only `public` is public. */
    it('stores a file private for any visibility but public', function (): void {
        MediaUpload::store($this->user, $this->type, [stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png')], 'everyone', true, false);

        expect(MediaFile::query()->value('visibility'))->toBe('private');
    });

    it('keeps a file to this site when asked', function (): void {
        MediaUpload::store($this->user, $this->type, [stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png')], 'private', false, true);

        expect(Entry::query()->where('title', 'photo')->value('site_id'))->toBe($this->site->id);
    });

    /* Each file is its own upload: one refused leaves the rest stored, and the refusal is the library's own words. */
    it('refuses a file the library refuses, in its own words, and stores the rest', function (): void {
        $results = MediaUpload::store($this->user, $this->type, [
            stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png'),
            stagedForUpload('<?php echo 1;', 'evil.php'),
        ], 'private', false, false);

        expect($results[0])->toBe(['name' => 'photo.png', 'outcome' => MediaUpload::STORED, 'reason' => null])
            ->and($results[1]['name'])->toBe('evil.php')
            ->and($results[1]['outcome'])->toBe(MediaUpload::REFUSED)
            ->and($results[1]['reason'])->toStartWith('Refusing [evil.php]: [php] is not an accepted file type.')
            ->and(Entry::query()->count())->toBe(1)
            ->and(stillStaged())->toBe([]);
    });

    /* A staged file whose bytes went while its sidecar stayed is refused by that name — and a file already gone is no failure to report. */
    it('refuses a staged file whose bytes are gone by its name, naming no path, and removes its sidecar', function (): void {
        Exceptions::fake();
        $file = stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png');
        unlink($file->getRealPath());

        $results = MediaUpload::store($this->user, $this->type, [$file], 'private', false, false);

        expect($results)->toBe([['name' => 'photo.png', 'outcome' => MediaUpload::REFUSED, 'reason' => __('kitsune::media.upload.incomplete')]])
            ->and($results[0]['reason'])->not->toContain('/')
            ->and(Entry::query()->count())->toBe(0)
            ->and(stillStaged())->toBe([]);
        Exceptions::assertNothingReported();
    });

    /*
     * ⚠️ WHAT A FULL DISK REALLY HANDS THE HANDLER, driven through Livewire's own endpoint and round trip. Livewire
     * writes the sidecar, then the file; the file's write answers false, and the endpoint signs the empty path — so the
     * upload arrives named for Livewire's staging directory, with no sidecar there (decision 4's *What this costs*). It
     * is refused unnamed, and the sidecar Livewire wrote first is not that file: it is left to the intake sweep. The gate
     * refuses such an upload at staging now (decision 18, `UploadStagingGateTest`); this is the handler's own refusal,
     * for one that reaches it all the same.
     */
    it('refuses an upload a full disk staged as nothing, unnamed, and leaves its sidecar to the sweep', function (): void {
        // The endpoint signs what it answers with the application key, which this suite does not set.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->roots[] = $root = sys_get_temp_dir().'/kitsune-intake-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $disk = RefusingDisk::install(FileUploadConfiguration::disk(), $root);
        // The sidecar is written; the file is not.
        $disk->onOperation(2, function () use ($disk): void {
            $disk->failWrites = true;
        });

        $signed = (new FileUploadController)->validateAndStore([UploadedFile::fake()->createWithContent('photo.png', UPLOAD_ACTION_PNG)], FileUploadConfiguration::disk())->first();
        $disk->failWrites = false;
        // What `_finishUpload` builds, sent to the browser and hydrated again as the submit receives it.
        $file = TemporaryUploadedFile::unserializeFromLivewireRequest(
            TemporaryUploadedFile::createFromLivewire(TemporaryUploadedFile::extractPathFromSignedPath($signed))->serializeForLivewireResponse(),
        );

        expect(TemporaryUploadedFile::extractPathFromSignedPath($signed))->toBe('')
            ->and(MediaUpload::store($this->user, $this->type, [$file], 'private', false, false))
            ->toBe([['name' => null, 'outcome' => MediaUpload::REFUSED, 'reason' => __('kitsune::media.upload.incomplete')]])
            ->and(Entry::query()->count())->toBe(0)
            ->and(Storage::disk(MediaDisks::PRIVATE)->allFiles())->toBe([])
            ->and(stillStaged())->toHaveCount(1)
            ->and(stillStaged()[0])->toEndWith('.png.json');
    });

    /*
     * ⚠️ A DELETE THE INTAKE DISK REFUSES IS REPORTED, and the files are left to the sweep. Core's intake disk neither
     * throws nor reports, so the refusal is only a `false` — installed here as core defines it. Reported once per file,
     * not again by `store()`'s own `finally`.
     */
    it('reports a staged file the intake disk would not remove, once, and leaves it to the sweep', function (): void {
        $this->roots[] = $root = sys_get_temp_dir().'/kitsune-intake-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $disk = RefusingDisk::install(FileUploadConfiguration::disk(), $root, ['throw' => false, 'report' => false]);
        $file = stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png');
        $staged = FileUploadConfiguration::path($file->getFilename(), false);
        $disk->failDeletes = true;
        Exceptions::fake();

        expect(MediaUpload::store($this->user, $this->type, [$file], 'private', false, false))
            ->toBe([['name' => 'photo.png', 'outcome' => MediaUpload::STORED, 'reason' => null]])
            ->and(stillStaged())->toEqualCanonicalizing([$staged, $staged.'.json']);
        Exceptions::assertReportedCount(2);
        Exceptions::assertReported(fn (MediaCustodyFailure $e): bool => $e->reason === 'delete' && $e->path === $staged);
        Exceptions::assertReported(fn (MediaCustodyFailure $e): bool => $e->reason === 'delete' && $e->path === $staged.'.json');
    });

    /*
     * Without a sidecar that names it a staged file has no name, and Livewire would make one up from its staging
     * directory's — six bytes of `base64_decode('livewire')`, refused as a file with no extension (review of decision 3).
     * Livewire writes the sidecar empty itself for a name `json_encode()` refuses.
     */
    it('refuses a staged file whose sidecar is gone or names nothing, and removes it', function (string $case): void {
        $file = stagedForUpload(UPLOAD_ACTION_PNG, $case === 'a name json_encode refuses' ? "caf\xe9.png" : 'photo.png');
        $sidecar = FileUploadConfiguration::path($file->getFilename(), false).'.json';
        $disk = Storage::disk(FileUploadConfiguration::disk());

        match ($case) {
            'gone' => $disk->delete($sidecar),
            'empty' => $disk->put($sidecar, ''),
            'not JSON' => $disk->put($sidecar, '{"name":"photo.p'),
            'an empty name' => $disk->put($sidecar, '{"name":""}'),
            'a name that is not text' => $disk->put($sidecar, '{"name":["photo.png"]}'),
            'a name json_encode refuses' => expect($disk->get($sidecar))->toBe(''),
        };

        $results = MediaUpload::store($this->user, $this->type, [$file], 'private', false, false);

        expect($results)->toBe([['name' => null, 'outcome' => MediaUpload::REFUSED, 'reason' => __('kitsune::media.upload.incomplete')]])
            ->and(Entry::query()->count())->toBe(0)
            ->and(stillStaged())->toBe([]);
    })->with([
        'gone' => 'gone',
        'empty' => 'empty',
        'not JSON' => 'not JSON',
        'an empty name' => 'an empty name',
        'a name that is not text' => 'a name that is not text',
        'a name json_encode refuses' => 'a name json_encode refuses',
    ]);

    /*
     * ⚠️ THE SUBMIT'S OWN VALIDATION LETS A STAGED FILE THAT IS NOT THERE THROUGH, so the handler can refuse it. Outside
     * the unit-test environment `TemporaryUploadedFile::getSize()` asks the intake disk, which throws for a file that is
     * not there, as on a full disk — and a `maxSize()` on the field is a `max:` rule that asks it, before the handler
     * runs (review of decision 3). So the field's rules are run as production runs them.
     */
    it('lets a submit whose staged file is missing reach the handler, which refuses it', function (): void {
        $file = stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png');
        unlink($file->getRealPath());
        $rules = uploadComponents(MediaUpload::action()->getSchema(Schema::make(app(ListEntries::class))))['files']->getValidationRules();

        $environment = app()['env'];
        app()['env'] = 'production';

        try {
            expect(app()->runningUnitTests())->toBeFalse()
                ->and(validator(['files' => [$file]], ['files' => $rules])->passes())->toBeTrue();
        } finally {
            app()['env'] = $environment;
        }

        expect(MediaUpload::store($this->user, $this->type, [$file], 'private', false, false))
            ->toBe([['name' => 'photo.png', 'outcome' => MediaUpload::REFUSED, 'reason' => __('kitsune::media.upload.incomplete')]])
            ->and(stillStaged())->toBe([]);
    });

    /* `preventFilePathTampering()` refuses a path the browser sends back; the handler never opens one either. */
    it('opens nothing that was not uploaded here, and says it ignored it', function (): void {
        $results = MediaUpload::store($this->user, $this->type, [base_path('composer.json'), stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png')], 'private', false, false);

        expect($results)->toBe([
            ['name' => 'photo.png', 'outcome' => MediaUpload::STORED, 'reason' => null],
            ['name' => null, 'outcome' => MediaUpload::REFUSED, 'reason' => __('kitsune::media.upload.not_staged')],
        ])->and(Entry::query()->count())->toBe(1);
    });

    /*
     * Decision 7: anything but the library's own refusals is a generic failure, reported and never shown — a disk
     * that would not take the bytes names the disk and the stored path, a failed row write its SQL and bindings. Each
     * case asserts the failure it meant to cause was the one reported, so a different failure cannot pass for it.
     */
    it('fails generically, reports it, and shows none of its words, where the library fails for any other reason', function (string $case): void {
        Exceptions::fake();
        $siteOnly = false;

        if (str_starts_with($case, 'the private disk')) {
            $this->roots[] = $root = sys_get_temp_dir().'/kitsune-upload-'.bin2hex(random_bytes(4));
            mkdir($root, 0777, true);
            // A disk configured to throw, as a host's may be, fails by an exception rather than a `false`.
            $disk = RefusingDisk::install(MediaDisks::PRIVATE, $root, str_contains($case, 'configured to throw') ? ['throw' => true] : []);

            // A full disk writes what fits and then fails; the part it wrote goes with the failure.
            if (str_contains($case, 'part-way')) {
                $disk->failAfterWriting = true;
                $disk->truncateWritesTo = 10;
            } else {
                $disk->failWrites = true;
            }
        } elseif ($case === 'the row write fails') {
            MediaFile::creating(static fn () => throw new QueryException('testing', 'insert into "media_files" ("path") values (?)', ['bound-secret'], new PDOException('boom')));
        } else {
            // "This site only" with no site: the panel always has one, so this is the extra, not the case that matters.
            app(Context::class)->forget();
            app(Context::class)->setOrg($this->org);
            $siteOnly = true;
        }

        $results = MediaUpload::store($this->user, $this->type, [stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png')], 'private', false, $siteOnly);
        MediaUpload::notify($results);

        expect($results)->toBe([['name' => 'photo.png', 'outcome' => MediaUpload::FAILED, 'reason' => null]])
            ->and(uploadNotices()[0]['body'])->toBe(e(__('kitsune::media.upload.line_failed', ['name' => 'photo.png'])))
            ->and(Entry::query()->count())->toBe(0)
            ->and(MediaFile::query()->count())->toBe(0)
            ->and(Storage::disk(MediaDisks::PRIVATE)->allFiles())->toBe([])
            ->and(stillStaged())->toBe([]);
        // `assertReported()` matches the closure's parameter class exactly, so a MediaRefused is not a RuntimeException here.
        match ($case) {
            'the private disk refuses the write',
            'the private disk fails part-way through the write' => Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'writing to ['.MediaDisks::PRIVATE.':')),
            'the private disk, configured to throw, fails part-way' => Exceptions::assertReported(fn (UnableToWriteFile $e): bool => str_contains($e->getMessage(), 'refused by the test after writing')),
            'the row write fails' => Exceptions::assertReported(fn (QueryException $e): bool => str_contains($e->getMessage(), 'bound-secret')),
            default => Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'this site only')),
        };
    })->with([
        'the private disk refuses the write' => 'the private disk refuses the write',
        'the private disk fails part-way through the write' => 'the private disk fails part-way through the write',
        'the private disk, configured to throw, fails part-way' => 'the private disk, configured to throw, fails part-way',
        'the row write fails' => 'the row write fails',
        'this site only, with no site' => 'this site only, with no site',
    ]);
});

describe('who may upload', function (): void {
    /* Authorised first, and every staged file removed all the same: nothing is stored, and nothing staged survives. */
    it('stores nothing for a user who may not create or publish, and removes what was staged', function (array $held): void {
        ($this->grant)('view', ...$held);

        $results = MediaUpload::store($this->user, $this->type, [stagedForUpload(UPLOAD_ACTION_PNG, 'photo.png')], 'private', false, false);

        expect($results[0]['outcome'])->toBe(MediaUpload::REFUSED)
            ->and($results[0]['reason'])->toContain('entry.image.create')->toContain('entry.image.publish')
            ->and(Entry::query()->count())->toBe(0)
            ->and(Storage::disk(MediaDisks::PRIVATE)->allFiles())->toBe([])
            ->and(stillStaged())->toBe([]);
    })->with([
        'create without publish' => [['create']],
        'publish without create' => [['publish']],
        'neither' => [[]],
    ]);

    it('is hidden, with no field to upload to, without create', function (): void {
        ($this->grant)('view', 'publish');

        $action = MediaUpload::action();

        expect($action->isHidden())->toBeTrue()
            ->and($action->getSchema(Schema::make(app(ListEntries::class))))->toBeNull();
    });

    it('is disabled, naming the permission it lacks, with no field to upload to, without publish', function (): void {
        ($this->grant)('view', 'create');

        $action = MediaUpload::action();

        expect($action->isHidden())->toBeFalse()
            ->and($action->isDisabled())->toBeTrue()
            ->and($action->getTooltip())->toContain('entry.image.publish')
            ->and($action->getSchema(Schema::make(app(ListEntries::class))))->toBeNull();
    });

    it('offers the files, their visibility — private — and sharing — shared — to a user who may upload', function (): void {
        ($this->grant)('view', 'create', 'publish');

        $action = MediaUpload::action();
        $components = uploadComponents($action->getSchema(Schema::make(app(ListEntries::class))));

        $files = $components['files'];
        $visibility = $components['visibility'];

        expect($action->isHidden())->toBeFalse()
            ->and($action->isDisabled())->toBeFalse()
            ->and($files)->toBeInstanceOf(FileUpload::class)
            ->and($files->isMultiple())->toBeTrue()
            ->and($files->shouldStoreFiles())->toBeFalse()
            ->and($files->shouldPreventFilePathTampering())->toBeTrue()
            ->and($visibility)->toBeInstanceOf(Radio::class)
            ->and($visibility->getDefaultState())->toBe('private')
            ->and($components['public_confirmed'])->toBeInstanceOf(Checkbox::class)
            ->and($components['site_only']->getDefaultState())->toBeFalse();
    });
});

describe('the notification', function (): void {
    it('names every file and what became of it, escaped as text', function (): void {
        MediaUpload::notify([
            ['name' => '<b>logo</b>.png', 'outcome' => MediaUpload::STORED, 'reason' => null],
            ['name' => 'evil.php', 'outcome' => MediaUpload::REFUSED, 'reason' => 'Refusing [<i>evil</i>]'],
            ['name' => null, 'outcome' => MediaUpload::FAILED, 'reason' => null],
        ]);

        $notice = uploadNotices()[0];

        expect($notice['status'])->toBe('warning')
            ->and($notice['title'])->toBe('1 of 3 files were uploaded')
            ->and($notice['body'])->toContain('&lt;b&gt;logo&lt;/b&gt;.png — stored')
            ->and($notice['body'])->toContain('evil.php — not stored: Refusing [&lt;i&gt;evil&lt;/i&gt;]')
            ->and($notice['body'])->toContain('A file — not stored: something went wrong')
            ->and($notice['body'])->not->toContain('<b>');
    });

    /* A file stored private because public went unconfirmed WAS uploaded: told otherwise, an editor uploads it again. */
    it('succeeds only where every file was stored as asked, and counts a file kept private as uploaded', function (array $outcomes, string $status, string $title): void {
        MediaUpload::notify(array_map(static fn (string $outcome): array => ['name' => 'a.png', 'outcome' => $outcome, 'reason' => null], $outcomes));

        $notice = uploadNotices()[0];

        expect($notice['status'])->toBe($status)
            ->and($notice['title'])->toBe($title);
    })->with([
        'all stored' => [[MediaUpload::STORED, MediaUpload::STORED], 'success', 'All 2 files were uploaded'],
        'the only file, stored' => [[MediaUpload::STORED], 'success', 'The file was uploaded'],
        'one stored private, unconfirmed' => [[MediaUpload::STORED, MediaUpload::KEPT_PRIVATE], 'warning', 'All 2 files were uploaded'],
        'the only file, stored private, unconfirmed' => [[MediaUpload::KEPT_PRIVATE], 'warning', 'The file was uploaded'],
        'one stored private, one refused' => [[MediaUpload::KEPT_PRIVATE, MediaUpload::REFUSED], 'warning', '1 of 2 files were uploaded'],
        'the only file, refused' => [[MediaUpload::REFUSED], 'danger', 'The file was not uploaded'],
        'none stored' => [[MediaUpload::REFUSED, MediaUpload::FAILED], 'danger', 'None of the 2 files was uploaded'],
    ]);
});

describe('a media type\'s pages', function (): void {
    beforeEach(fn () => ($this->grant)('view', 'create', 'publish', 'update'));

    it('offers Upload on a media list, and Create on any other', function (): void {
        $actions = (new ReflectionMethod(ListEntries::class, 'getHeaderActions'))->invoke(app(ListEntries::class));

        expect($actions)->toHaveCount(1)
            ->and($actions[0]->getName())->toBe('upload');

        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));
        $actions = (new ReflectionMethod(ListEntries::class, 'getHeaderActions'))->invoke(app(ListEntries::class));

        expect($actions[0])->toBeInstanceOf(CreateAction::class);
    });

    it('withholds the status control and column for a media type, and keeps them for another', function (): void {
        $status = fn (): bool => uploadComponents(EntryResource::form(Schema::make(app(EditEntry::class))))['status']->isHidden();
        // Withheld: a media list's tiles carry no status column at all (decision 6), and another type's shows one.
        $column = fn (): bool => EntryResource::table(Table::make(app(ListEntries::class)))->getColumn('status')?->isHidden() ?? true;

        expect($status())->toBeTrue()->and($column())->toBeTrue();

        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));

        expect($status())->toBeFalse()->and($column())->toBeFalse();
    });

    it('shows what is stored, and no preview, on a media entry', function (): void {
        // Stored at a known instant, and shown at another: the section says when the file was stored, not when it is read.
        $this->travelTo(Carbon::parse('2026-09-18 13:00:00', 'UTC'));
        $entry = storedMedia(UPLOAD_ACTION_PNG, 'photo.png', $this->type);
        $this->travelBack();

        $components = fileSection($entry);
        $section = array_values(array_filter($components, static fn (Component $c): bool => $c instanceof Section))[0];

        expect($section->isHidden())->toBeFalse()
            ->and($components['media_file_type']->getState())->toBe('image/png')
            ->and($components['media_file_size']->getState())->toBe('67 B')
            ->and($components['media_file_dimensions']->getState())->toBe('1 × 1 pixels')
            ->and($components['media_file_dimensions']->isHidden())->toBeFalse()
            ->and($components['media_file_visibility']->getState())->toBe('Private')
            ->and($components['media_file_sharing']->getState())->toBe('This site only')
            ->and($components['media_file_stored']->formatState($components['media_file_stored']->getState()))->toBe('Sep 18, 2026 22:00:00')
            ->and($components['media_file_stored']->getTimezone())->toBe('Asia/Tokyo')
            // A private file is linked through the panel's route, and this suite registers none: no link, not a made-up one.
            ->and($components['media_file_link']->getUrl())->toBeNull()
            ->and($components['media_file_link']->isHidden())->toBeTrue()
            ->and(array_filter($components, static fn (Component $c): bool => $c instanceof ImageEntry))->toBe([]);

        // A file with no dimensions shows none, rather than an empty row.
        $notes = fileSection(storedMedia("plain words\n", 'notes.txt', $this->type));

        expect($notes['media_file_type']->getState())->toBe('text/plain')
            ->and($notes['media_file_dimensions']->isHidden())->toBeTrue();

        // A media entry with no file row says so, and shows no facts.
        $bare = fileSection(Entry::create(['entry_type_id' => $this->type->id, 'title' => 'No bytes', 'status' => 'draft']));

        expect($bare['media_file_missing']->getState())->toBe(__('kitsune::media.file.missing'))
            ->and($bare)->not->toHaveKey('media_file_type');

        // The control: an entry of a type that holds no files shows no File section.
        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));
        $article = Entry::create(['entry_type_id' => app(EntryType::class)->id, 'title' => 'A note', 'status' => 'draft']);
        $sections = array_filter(
            uploadComponents(EntryResource::form(Schema::make(app(EditEntry::class))->record($article))),
            static fn (Component $c): bool => $c instanceof Section,
        );

        expect(array_values($sections)[0]->isHidden())->toBeTrue();
    });

    /* Decision 16's other half: whether a file is served to anyone with its link, and where that link goes. */
    /*
     * ⚠️ ON THE HOST SERVING THE ADMIN (decision 6): the disk's URL is APP_URL's, absolute, and the link is its path — a
     * disk faked with no URL at all would give the path either way, and could not tell the two apart.
     */
    it('shows a public, shared file as public, shared, and linked to the public disk\'s direct URL', function (): void {
        config(['app.url' => 'http://localhost']);
        Storage::fake('public', ['url' => 'http://localhost/storage']);
        $entry = storedMedia(UPLOAD_ACTION_PNG, 'photo.png', $this->type, 'public', siteOnly: false);
        $path = MediaFile::query()->where('entry_id', $entry->id)->value('path');

        $components = fileSection($entry);

        expect($components['media_file_visibility']->getState())->toBe('Public')
            ->and($components['media_file_sharing']->getState())->toBe('Every site in the organisation')
            ->and(Storage::disk('public')->url($path))->toBe('http://localhost/storage/'.$path)
            ->and($components['media_file_link']->getUrl())->toBe('/storage/'.$path);
    });

    /*
     * ⚠️ A HIDDEN SECTION STILL BUILDS ITS CONTENTS: filling a form walks a hidden component's children (validation
     * skips them). So the File section returns nothing on any other type's page. On a media entry's it builds its
     * contents twice in a request — once when the form is filled or validated, which Filament caches, and again when
     * the section renders, which it does not — and reads the row once each time: the link is built from the row
     * already read (review of decision 3).
     */
    it('reads a media entry\'s file row once each time the section is built, and no other entry\'s at all', function (): void {
        $entry = storedMedia(UPLOAD_ACTION_PNG, 'photo.png', $this->type);
        $reads = function (Entry $record): int {
            $count = 0;
            DB::listen(static function ($query) use (&$count): void {
                $count += str_contains($query->sql, 'media_files') ? 1 : 0;
            });

            $form = EntryResource::form(Schema::make(app(EditEntry::class))->statePath('data')->record($record));
            $form->fill();
            $form->toHtml();

            return $count;
        };

        expect($reads($entry))->toBe(2);

        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));
        $article = Entry::create(['entry_type_id' => app(EntryType::class)->id, 'title' => 'A note', 'status' => 'draft']);

        expect($reads($article))->toBe(0);
    });
});

/*
 * ⚠️ 404 BEFORE THE PERMISSION IS ASKED, so a reader who may not create is told the page does not exist rather than
 * that it is theirs to be refused. Asked of a user holding only `view`, inside the panel, where the parent check
 * answers a real 403 — the control — rather than failing to resolve Filament (review of decision 3).
 */
describe('a media type\'s create page', function (): void {
    it('is missing — 404, not 403 — for a reader who may not create', function (): void {
        ($this->grant)('view');
        PanelTenancy::enter($this->site);

        $status = function (): ?int {
            try {
                (new ReflectionMethod(CreateEntry::class, 'authorizeAccess'))->invoke(app(CreateEntry::class));
            } catch (HttpException $e) {
                return $e->getStatusCode();
            }

            return null;
        };

        expect($status())->toBe(404);

        // The control: the same reader on another type's create page is refused by the permission, not as missing.
        app()->instance(EntryType::class, EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']));

        expect($status())->toBe(403);

        // And allowed there, once they may create.
        $this->role->grant(Permissions::forEntryType('article', 'create'));

        expect($status())->toBeNull();
    });

    it('is missing for a user who may create, too', function (): void {
        ($this->grant)('view', 'create', 'publish');
        PanelTenancy::enter($this->site);

        expect(fn () => (new ReflectionMethod(CreateEntry::class, 'authorizeAccess'))->invoke(app(CreateEntry::class)))
            ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(404));
    });
});

describe('decision 7\'s refusals', function (): void {
    it('throws the library\'s refusals as MediaRefused', function (string $case): void {
        $refuse = match ($case) {
            'type' => fn () => MediaIntake::accept('evil.php', __FILE__, 10),
            'contents' => fn () => MediaIntake::accept('photo.png', __FILE__, 10),
            'svg' => fn () => MediaIntake::accept('logo.svg', __FILE__, 10),
            'ceiling' => fn () => MediaIntake::refuseIfTooLarge(MediaIntake::MAX_BYTES + 1, 'big.png'),
            'visibility' => fn () => MediaLibrary::store(__FILE__, 'a.png', $this->type, 'everyone'),
            'not media' => fn () => MediaLibrary::store(__FILE__, 'a.png', EntryType::create(['org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles'])),
        };

        expect($refuse)->toThrow(MediaRefused::class);
    })->with([
        'a type not accepted' => 'type',
        'contents that are not what the name says' => 'contents',
        'an SVG with no sanitiser installed' => 'svg',
        'a file over the ceiling' => 'ceiling',
        'a visibility not known' => 'visibility',
        'a type that holds no files' => 'not media',
    ]);

    /*
     * A failure that names a server path or a class is not a refusal, and is never shown as written. The vanished
     * sanitiser — bound for `accept()`, gone before the copy — cannot be reached through the handler without a hook
     * between the two calls, so it is asked of the copy itself.
     */
    it('does not throw the library\'s other failures as MediaRefused', function (string $case): void {
        $fail = match ($case) {
            'path' => fn () => MediaLibrary::store('/nowhere/at/all.png', 'a.png', $this->type),
            'sanitiser' => fn () => (new ReflectionMethod(MediaLibrary::class, 'sanitisedCopy'))->invoke(null, __FILE__, 'logo.svg'),
        };

        expect($fail)->toThrow(fn (RuntimeException $e) => expect($e)->not->toBeInstanceOf(MediaRefused::class)
            ->and($e->getMessage())->toContain($case === 'path' ? 'is not readable' : 'any more'));
    })->with([
        'a path it cannot read' => 'path',
        'a sanitiser that vanished' => 'sanitiser',
    ]);
});
