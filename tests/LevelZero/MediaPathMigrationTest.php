<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/*
 * The migration that makes media file paths unique, run as a deploy runs it — ADR-042 decision 5 (Adam, decision 8,
 * 2026-09-25; T59).
 *
 * ⚠️ AT LEVEL ZERO, ON A DATABASE OF ITS OWN, because the order is the point: a refusal after the index would leave it
 * behind, and every retry would fail on the index rather than on the problem. Here `down()` and `up()` run for real,
 * outside any wrapper, and the index is read back from the catalog.
 */

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);

    $org = Org::create(['slug' => 'path-migration', 'name' => 'Path migration']);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'path-migration-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    $this->migration = require __DIR__.'/../../packages/core/database/migrations/0001_01_01_000010_make_media_file_paths_unique.php';
});

afterEach(fn () => app(Context::class)->forget());

/** @return array{int, string} a stored file's entry id and path */
function pathMigrationStored(EntryType $type): array
{
    $source = tempnam(sys_get_temp_dir(), 'kitsune-path-migration-');
    file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $entry = MediaLibrary::store($source, 'photo.png', $type, 'public');
    unlink($source);

    return [(int) $entry->getKey(), (string) DB::table('media_files')->where('entry_id', $entry->getKey())->value('path')];
}

function pathMigrationIndexed(): bool
{
    return collect(Schema::getIndexes('media_files'))->contains(
        static fn (array $index): bool => $index['unique'] && $index['columns'] === ['path'],
    );
}

/**
 * Run `up()`, and return what it threw with every statement it sent.
 *
 * @return array{?Throwable, list<string>}
 */
function pathMigrationUp(object $migration): array
{
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower(ltrim($query->sql));
    });

    try {
        $migration->up();
    } catch (Throwable $thrown) {
        return [$thrown, $statements];
    }

    return [null, $statements];
}

/**
 * ⚠️ THE ENGINE'S OWN REFUSAL WOULD BE A `QueryException`, which is a `RuntimeException` too, so its class is asserted
 * away explicitly rather than trusted to the message. The log holds reads only, and some, so a refusal that ran no query
 * cannot pass either.
 */
it('refuses two rows naming one path before it builds the index, naming both entries', function (): void {
    [$a, $path] = pathMigrationStored($this->image);
    [$b] = pathMigrationStored($this->image);

    $this->migration->down();
    DB::table('media_files')->where('entry_id', $b)->update(['path' => $path]);

    [$thrown, $statements] = pathMigrationUp($this->migration);

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown)->not->toBeInstanceOf(QueryException::class)
        ->and($thrown?->getMessage())->toStartWith("Cannot make media file paths unique: [{$path}: entries {$a}, {$b}]")
        ->and($statements)->not->toBeEmpty()
        ->and(array_values(array_filter($statements, static fn (string $sql): bool => ! str_starts_with($sql, 'select'))))->toBe([])
        ->and(pathMigrationIndexed())->toBeFalse();
});

/** The build would succeed here — the strings differ — so only a check before it keeps the index from being left behind. */
it('refuses a path not written as the disks read it, though the index would build', function (): void {
    [$a, $path] = pathMigrationStored($this->image);

    $this->migration->down();
    DB::table('media_files')->where('entry_id', $a)->update(['path' => '/'.$path]);

    [$thrown, $statements] = pathMigrationUp($this->migration);

    expect($thrown?->getMessage())->toStartWith("Cannot make media file paths unique: [/{$path}: entry {$a}, which every disk reads as {$path}]")
        ->and(array_values(array_filter($statements, static fn (string $sql): bool => ! str_starts_with($sql, 'select'))))->toBe([])
        ->and(pathMigrationIndexed())->toBeFalse();
});

it('builds a unique index on the path once every path is one row\'s, and down() removes it', function (): void {
    pathMigrationStored($this->image);
    pathMigrationStored($this->image);

    expect(pathMigrationIndexed())->toBeTrue();

    $this->migration->down();

    expect(pathMigrationIndexed())->toBeFalse();

    [$thrown] = pathMigrationUp($this->migration);

    expect($thrown)->toBeNull()
        ->and(pathMigrationIndexed())->toBeTrue();
});
