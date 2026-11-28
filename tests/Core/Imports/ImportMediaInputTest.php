<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Imports\ImportJournal;
use Kitsune\Core\Imports\ImportMediaAsset;
use Kitsune\Core\Imports\ImportMediaInput;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\ImportMapping;
use Kitsune\Core\Models\ImportMediaReservation;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

beforeEach(function (): void {
    $this->mediaFixture = sys_get_temp_dir().'/kitsune-import-media-'.bin2hex(random_bytes(8));
    mkdir($this->mediaFixture.'/inside', 0700, true);
    $this->mediaBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXWQAAAAASUVORK5CYII=', true);
    $this->mediaPath = $this->mediaFixture.'/inside/photo.png';
    file_put_contents($this->mediaPath, $this->mediaBytes);
    $this->descriptor = new ImportMediaAsset(
        'image', '0042', 'photo.png', 'photo.png', strlen($this->mediaBytes), hash('sha256', $this->mediaBytes),
    );
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->mediaFixture);
});

it('hands the writer a private verified copy even if the source changes after verification', function (): void {
    $root = $this->mediaFixture.'/inside';
    expect(ImportMediaInput::inspect($root, $this->descriptor))->toBe(['extension' => 'png', 'mime' => 'image/png']);

    $copy = null;
    $verified = ImportMediaInput::withVerifiedCopy($root, $this->descriptor, function (string $path) use (&$copy): string {
        $copy = $path;
        expect($path)->not->toBe($this->mediaPath)
            ->and(fileperms($path) & 0777)->toBe(0600);
        file_put_contents($this->mediaPath, str_repeat('x', strlen($this->mediaBytes)));

        return file_get_contents($path);
    });

    expect($verified)->toBe($this->mediaBytes)
        ->and(is_file($copy))->toBeFalse()
        ->and(fn () => ImportMediaInput::withVerifiedCopy($root, $this->descriptor, fn () => throw new LogicException('Unexpected write.')))
        ->toThrow(DomainException::class, 'differs from the approved bytes');
});

it('refuses an escaped source and checks the file type even when the digest is correct', function (): void {
    $root = $this->mediaFixture.'/inside';
    $outside = $this->mediaFixture.'/outside.png';
    file_put_contents($outside, $this->mediaBytes);
    symlink($outside, $root.'/outside.png');

    $make = fn (string $path, string $name): ImportMediaAsset => new ImportMediaAsset(
        'image', '0042', $path, $name, strlen($this->mediaBytes), hash('sha256', $this->mediaBytes)
    );

    expect(fn () => $make('../outside.png', 'photo.png'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ImportMediaInput::inspect($root, $make('outside.png', 'photo.png')))
        ->toThrow(DomainException::class, 'inside the import media directory')
        ->and(fn () => ImportMediaInput::inspect($root, $make('photo.png', 'photo.jpg')))
        ->toThrow(DomainException::class, 'not accepted by the media library');
});

it('hands verified bytes to normal Site-only private media storage', function (): void {
    $context = app(Context::class);
    try {
        $org = Org::create(['name' => 'Imports', 'slug' => 'imports']);
        $context->setOrg($org);
        $site = Site::create(['handle' => 'fixtures', 'slug' => 'fixtures', 'name' => 'Fixtures']);
        $context->setSite($site);
        $type = EntryType::create([
            'handle' => 'imported_asset', 'name' => 'Asset', 'plural_name' => 'Assets', 'is_media' => true,
        ]);

        Storage::fake(MediaDisks::PRIVATE);
        $entry = ImportMediaInput::withVerifiedCopy(
            $this->mediaFixture.'/inside',
            $this->descriptor,
            fn (string $file) => MediaLibrary::store($file, $this->descriptor->originalName, $type, 'private', siteOnly: true),
        );
        $media = $entry->mediaFile()->firstOrFail();

        expect($entry->org_id)->toBe($org->id)
            ->and($entry->site_id)->toBe($site->id)
            ->and($media->visibility)->toBe('private')
            ->and($media->checksum)->toBe($this->descriptor->sha256)
            ->and($media->size_bytes)->toBe($this->descriptor->size)
            ->and(Storage::disk($media->disk)->get($media->path))->toBe($this->mediaBytes);
    } finally {
        $context->forget();
    }
});

it('stages only the media listed in the exact approval and recovers the reservation after interruption', function (): void {
    $context = app(Context::class);
    try {
        $org = Org::create(['name' => 'Reservations', 'slug' => 'reservations']);
        $context->setOrg($org);
        $site = Site::create(['handle' => 'primary', 'slug' => 'primary', 'name' => 'Primary']);
        $sibling = Site::create(['handle' => 'sibling', 'slug' => 'sibling', 'name' => 'Sibling']);
        $context->setSite($site);
        $journal = app(ImportJournal::class);
        $asset = $this->descriptor;
        $root = $this->mediaFixture.'/inside';
        $disk = Storage::fake(MediaDisks::PRIVATE);
        $plan = $journal->preview('catalogue', 'json', hash('sha256', 'fixture'), [], true, [$asset], $root);
        $incomplete = $journal->preview('catalogue', 'json', hash('sha256', 'fixture'), [], true, [$asset]);
        expect($plan->complete)->toBeTrue()
            ->and($incomplete->findings)->toBe([['position' => 1, 'code' => 'unavailable-media-asset']])
            ->and($plan->media[0]->descriptor())->toBe($asset->descriptor())
            ->and(fn () => $journal->stageMedia($plan->report(), 'wrong', $root))->toThrow(DomainException::class, 'differs from the approved')
            ->and($disk->allFiles())->toBe([])
            ->and(ImportMediaReservation::count())->toBe(0);

        $refuse = true;
        ImportMediaReservation::updating(static function () use (&$refuse): ?bool {
            return $refuse ? false : null;
        });
        expect(fn () => $journal->stageMedia($plan->report(), $plan->digest(), $root))
            ->toThrow(RuntimeException::class, 'receipt was refused');
        $reservation = ImportMediaReservation::query()->firstOrFail();
        expect($reservation->staged)->toBeFalse()
            ->and($disk->allFiles())->toBe([$reservation->path])
            ->and($disk->get($reservation->path))->toBe($this->mediaBytes);

        $refuse = false;
        $disk->put($reservation->path, str_repeat('x', $asset->size));
        $resume = new ImportJournal($context);
        expect(fn () => $resume->stageMedia($plan->report(), $plan->digest(), $root))
            ->toThrow(DomainException::class, 'does not match')
            ->and($disk->get($reservation->path))->toBe(str_repeat('x', $asset->size))
            ->and($reservation->fresh()->staged)->toBeFalse();

        $disk->put($reservation->path, $this->mediaBytes);
        expect($resume->stageMedia($plan->report(), $plan->digest(), $root))->toBe([$reservation->id])
            ->and($reservation->fresh()->staged)->toBeTrue()
            ->and($resume->stageMedia($plan->report(), $plan->digest(), $root))->toBe([$reservation->id])
            ->and($disk->allFiles())->toBe([$reservation->path])
            ->and(fn () => $resume->apply($plan->report(), $plan->digest()))->toThrow(DomainException::class, 'final import custody');

        $journal->abandon($plan->report(), $plan->digest());
        $replacement = $journal->preview('catalogue', 'json', hash('sha256', 'later fixture'), [], true, [$asset], $root);
        expect($replacement->findings)->toBe([['position' => 1, 'code' => 'mapped-media-conflict']])
            ->and($disk->allFiles())->toBe([$reservation->path]);

        $disk->put($reservation->managed_path, 'unconfirmed bytes');
        expect(fn () => $journal->purgeStagedMedia($plan->report(), $plan->digest()))
            ->toThrow(DomainException::class, 'uncommitted managed file')
            ->and($disk->get($reservation->path))->toBe($this->mediaBytes)
            ->and($disk->get($reservation->managed_path))->toBe('unconfirmed bytes');
        $disk->delete($reservation->managed_path);

        expect($journal->purgeStagedMedia($plan->report(), $plan->digest()))->toBe([$reservation->id])
            ->and($journal->purgeStagedMedia($plan->report(), $plan->digest()))->toBe([$reservation->id])
            ->and($disk->allFiles())->toBe([])
            ->and($reservation->fresh()->staging_purged)->toBeTrue();
        $replacement = $journal->preview('catalogue', 'json', hash('sha256', 'later fixture'), [], true, [$asset], $root);
        expect($replacement->complete)->toBeTrue()
            ->and($journal->stageMedia($replacement->report(), $replacement->digest(), $root)[0])->not->toBe($reservation->id);

        $context->setSite($sibling);
        expect(fn () => $resume->stageMedia($plan->report(), $plan->digest(), $root))->toThrow(DomainException::class, 'different Org or Site');
    } finally {
        $context->forget();
    }
});

it('finalizes a staged media mapping and links it after a rollback without creating another managed object', function (): void {
    $context = app(Context::class);
    try {
        $org = Org::create(['name' => 'Adoption', 'slug' => 'adoption']);
        $context->setOrg($org);
        $site = Site::create(['handle' => 'primary', 'slug' => 'primary', 'name' => 'Primary']);
        $context->setSite($site);
        $type = EntryType::create(['handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles']);
        $mediaType = EntryType::create(['handle' => 'asset', 'name' => 'Asset', 'plural_name' => 'Assets', 'is_media' => true]);
        $field = FieldStorage::create([
            'handle' => 'lead_image', 'type' => 'relation', 'settings' => ['targetTypes' => ['asset']], 'cardinality' => 1, 'pii_class' => 'none',
        ]);
        Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $field->id, 'label' => 'Lead image']);

        $asset = new ImportMediaAsset('image', '0042', 'photo.png', 'photo.png', $this->descriptor->size, $this->descriptor->sha256, $mediaType->id);
        $journal = app(ImportJournal::class);
        $root = $this->mediaFixture.'/inside';
        $disk = Storage::fake(MediaDisks::PRIVATE);
        $plan = $journal->preview('catalogue', 'json', hash('sha256', 'fixture'), [[
            'source_type' => 'article', 'source_id' => 'draft', 'entry_type_id' => $type->id, 'title' => 'Draft', 'slug' => 'draft',
            'links' => (object) ['lead_image' => [(object) ['source_type' => 'image', 'source_id' => '0042']]],
        ]], true, [$asset], $root);
        $mediaStatus = fn (): array => $journal->executionReport($plan->report(), $plan->digest())['media'];
        expect($plan->complete)->toBeTrue()
            ->and($mediaStatus()[0])->toBe(['source_type' => 'image', 'source_id' => '0042', 'outcome' => 'pending', 'entry_id' => null])
            ->and(fn () => $journal->apply($plan->report(), $plan->digest()))->toThrow(DomainException::class, 'Stage the approved media');

        $mediaType->update(['settings' => ['accepts' => ['jpeg']]]);
        expect(fn () => $journal->apply($plan->report(), $plan->digest()))
            ->toThrow(DomainException::class, 'destination schema changed')
            ->and($journal->preview('catalogue', 'json', hash('sha256', 'fixture'), [], true, [$asset], $root)->findings)
            ->toBe([['position' => 1, 'code' => 'unsupported-media-format']]);
        $mediaType->update(['settings' => []]);

        [$reservationId] = $journal->stageMedia($plan->report(), $plan->digest(), $root);
        $reservation = ImportMediaReservation::findOrFail($reservationId);
        $refuse = true;
        ImportMapping::creating(static function () use (&$refuse): ?bool {
            return $refuse ? false : null;
        });

        expect(fn () => $journal->apply($plan->report(), $plan->digest()))->toThrow(RuntimeException::class, 'media mapping was refused')
            ->and(Entry::count())->toBe(0)
            ->and(MediaFile::count())->toBe(0)
            ->and($reservation->fresh()->entry_id)->toBeNull()
            ->and($mediaStatus()[0]['outcome'])->toBe('staged')
            ->and(fn () => $journal->purgeStagedMedia($plan->report(), $plan->digest()))
            ->toThrow(DomainException::class, 'completed or abandoned approved report')
            ->and($disk->get($reservation->managed_path))->toBe($this->mediaBytes);

        $refuse = false;
        [$draft] = $journal->apply($plan->report(), $plan->digest());
        $id = $reservation->fresh()->entry_id;
        $file = MediaFile::query()->where('entry_id', $id)->firstOrFail();
        expect($file->path)->toBe($reservation->managed_path)
            ->and($file->visibility)->toBe('private')
            ->and($mediaStatus()[0])->toBe(['source_type' => 'image', 'source_id' => '0042', 'outcome' => 'finalized', 'entry_id' => $id])
            ->and($file->checksum)->toBe($asset->sha256)
            ->and(Entry::findOrFail($draft)->relationState())->toBe([$field->id => [$id]])
            ->and(ImportMapping::count())->toBe(2)
            ->and($journal->apply($plan->report(), $plan->digest()))->toBe([$draft])
            ->and(MediaFile::count())->toBe(1)
            ->and($disk->allFiles('media'))->toBe([$file->path]);

        $disk->put($reservation->path, 'different staged object');
        expect(fn () => $journal->purgeStagedMedia($plan->report(), $plan->digest()))
            ->toThrow(DomainException::class, 'differs from the approved asset')
            ->and($disk->get($reservation->path))->toBe('different staged object');

        $disk->put($reservation->path, $this->mediaBytes);
        $disk->put($file->path, 'different managed object');
        expect(fn () => $journal->purgeStagedMedia($plan->report(), $plan->digest()))
            ->toThrow(DomainException::class, 'no longer matches')
            ->and($disk->get($reservation->path))->toBe($this->mediaBytes);

        $disk->put($file->path, $this->mediaBytes);
        expect(fn () => $journal->purgeStagedMedia($plan->report(), str_repeat('0', 64)))
            ->toThrow(DomainException::class, 'differs from the approved report')
            ->and($journal->purgeStagedMedia($plan->report(), $plan->digest()))->toBe([$reservationId])
            ->and($disk->exists($reservation->path))->toBeFalse()
            ->and($disk->get($file->path))->toBe($this->mediaBytes)
            ->and($journal->purgeStagedMedia($plan->report(), $plan->digest()))->toBe([$reservationId])
            ->and($mediaStatus()[0]['outcome'])->toBe('finalized');

        $rerun = $journal->preview('catalogue', 'json', hash('sha256', 'revised fixture'), [[
            'source_type' => 'article', 'source_id' => 'draft', 'entry_type_id' => $type->id, 'title' => 'Revised draft', 'slug' => 'draft',
            'links' => (object) ['lead_image' => [(object) ['source_type' => 'image', 'source_id' => '0042']]],
        ]], true, [$asset], $root);
        expect($rerun->complete)->toBeTrue();
        [$reusedId] = $journal->stageMedia($rerun->report(), $rerun->digest(), $root);
        expect($reusedId)->not->toBe($reservationId)
            ->and($journal->apply($rerun->report(), $rerun->digest()))->toBe([$draft])
            ->and(Entry::findOrFail($draft)->title)->toBe('Revised draft')
            ->and(Entry::findOrFail($draft)->relationState())->toBe([$field->id => [$id]])
            ->and($journal->executionReport($rerun->report(), $rerun->digest())['media'][0])
            ->toBe(['source_type' => 'image', 'source_id' => '0042', 'outcome' => 'reused', 'entry_id' => $id])
            ->and($journal->purgeStagedMedia($rerun->report(), $rerun->digest()))->toBe([])
            ->and($journal->purgeStagedMedia($plan->report(), $plan->digest()))->toBe([$reservationId])
            ->and($mediaStatus()[0]['outcome'])->toBe('finalized')
            ->and(MediaFile::count())->toBe(1)
            ->and(ImportMapping::count())->toBe(2)
            ->and($disk->allFiles('imports/staging'))->toBe([]);

        $changedAsset = new ImportMediaAsset('image', '0042', 'photo.png', 'changed.png', $asset->size, $asset->sha256, $mediaType->id);
        expect($journal->preview('catalogue', 'json', hash('sha256', 'changed asset'), [], true, [$changedAsset], $root)->findings)
            ->toBe([['position' => 1, 'code' => 'mapped-media-conflict']]);

        Entry::findOrFail($id)->update(['title' => 'Independently edited asset']);
        expect($journal->preview('catalogue', 'json', hash('sha256', 'edited destination'), [], true, [$asset], $root)->findings)
            ->toBe([['position' => 1, 'code' => 'mapped-media-conflict']]);
    } finally {
        $context->forget();
    }
});
