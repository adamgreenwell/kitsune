<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;

/*
 * A media file's row: only `disk` moves after creation — ADR-042 decision 5 (T22).
 *
 * ⚠️ AT EVERY DOOR, INSIDE THE ESCAPE HATCH TOO. Custody finds a file's copies by its path and keeps the one matching
 * its checksum, so a row whose path or checksum changed would strand or replace the file. Each refusal is asserted
 * from the row as stored afterwards, not from the exception alone.
 */

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake(MediaDisks::PRIVATE);

    $org = Org::create(['slug' => 'fixed', 'name' => 'Fixed']);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'fixed-main', 'name' => 'Main', 'locale' => 'en']));
    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    $store = static function () use ($type): MediaFile {
        $source = tempnam(sys_get_temp_dir(), 'kitsune-fixed-');
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $entry = MediaLibrary::store($source, 'fixed.png', $type, 'public');
        unlink($source);

        return MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();
    };

    $this->file = $store();
    $this->other = $store();
    $this->stored = (array) DB::table('media_files')->where('id', $this->file->getKey())->first();
});

afterEach(fn () => app(Context::class)->forget());

it('refuses to change a column fixed at creation, at every door', function (string $column, string $door): void {
    $value = match ($column) {
        'entry_id' => $this->other->entry_id,
        'path' => 'media/elsewhere.png',
        'checksum' => str_repeat('0', 64),
        'mime' => 'application/pdf',
        'size_bytes' => 1,
        'visibility' => 'private',
        'width', 'height' => 7,
        'duration_ms' => 5,
        'created_at' => now()->subYear(),
        // ADR-041 decision 1: an offer is written by *Offer to readers* alone, below the model.
        'reader_entitlement' => 'course.advanced-php',
    };
    $id = $this->file->getKey();

    $write = match ($door) {
        'the instance' => function () use ($id, $column, $value): void {
            $file = MediaFile::query()->findOrFail($id);
            $file->{$column} = $value;
            $file->save();
        },
        'a quiet save' => function () use ($id, $column, $value): void {
            $file = MediaFile::query()->findOrFail($id);
            $file->{$column} = $value;
            $file->saveQuietly();
        },
        'a bulk update' => fn () => MediaFile::query()->whereKey($id)->update([$column => $value]),
        'the escape hatch' => fn () => MediaFile::withoutScopeBecause('a test of the fixed columns', fn ($query) => $query
            ->whereKey($id)->update([$column => $value])),
    };

    expect($write)->toThrow(RuntimeException::class, "[{$column}] is fixed when MediaFile is created");

    expect((array) DB::table('media_files')->where('id', $id)->first())->toBe($this->stored);
})->with(['entry_id', 'path', 'checksum', 'mime', 'size_bytes', 'visibility', 'width', 'height', 'duration_ms', 'created_at', 'reader_entitlement'])
    ->with(['the instance', 'a quiet save', 'a bulk update', 'the escape hatch']);

/** The control: the one column custody moves is still written, by a bulk update and by the instance. */
it('writes the disk a file is on', function (): void {
    MediaFile::query()->whereKey($this->file->getKey())->update(['disk' => MediaDisks::PRIVATE]);

    expect(DB::table('media_files')->where('id', $this->file->getKey())->value('disk'))->toBe(MediaDisks::PRIVATE);

    $file = MediaFile::query()->findOrFail($this->file->getKey());
    $file->disk = 'public';
    $file->save();

    expect(DB::table('media_files')->where('id', $this->file->getKey())->value('disk'))->toBe('public');
});

/*
 * ADR-042 decision 32 lifts `visibility` — and, for a JPEG made public, `checksum` and `size_bytes` — through one door,
 * below the model, under custody's lock (`MediaVisibility::writeRow()`). Every model door still refuses all three: the
 * forms of a write the dataset above does not spell, here.
 */
it('refuses the columns the visibility switch writes at every model door', function (string $door): void {
    $id = $this->file->getKey();

    $write = match ($door) {
        'a qualified bulk update' => fn () => MediaFile::query()->whereKey($id)->update(['media_files.visibility' => 'private']),
        'an increment of the size' => fn () => MediaFile::query()->whereKey($id)->increment('size_bytes'),
        'an increment\'s extra columns' => fn () => MediaFile::query()->whereKey($id)->increment('width', 0, ['checksum' => str_repeat('0', 64)]),
        'an upsert\'s update half, in the escape hatch' => fn () => MediaFile::withoutScopeBecause('a test of the fixed columns', fn ($query) => $query
            ->upsert([[...$this->stored, 'visibility' => 'private']], ['id'], ['visibility'])),
        // ADR-041 decision 1: the offer's column, in the same forms.
        'a qualified bulk update of the offer' => fn () => MediaFile::query()->whereKey($id)->update(['media_files.reader_entitlement' => 'course.advanced-php']),
        'an increment\'s extra columns, the offer' => fn () => MediaFile::query()->whereKey($id)->increment('width', 0, ['reader_entitlement' => 'course.advanced-php']),
        'an upsert\'s update half naming the offer, in the escape hatch' => fn () => MediaFile::withoutScopeBecause('a test of the fixed columns', fn ($query) => $query
            ->upsert([[...$this->stored, 'reader_entitlement' => 'course.advanced-php']], ['id'], ['reader_entitlement'])),
    };

    // An upsert naming the offer carries it in its insert half too, which is refused first, as a row created offered
    // (`RefusesInsertedRows`); the update half's refusal stays pinned by the bulk and arithmetic doors above.
    expect($write)->toThrow(RuntimeException::class, str_contains($door, 'naming the offer')
        ? 'Refusing to store a media file offered to readers'
        : 'is fixed when MediaFile is created');

    expect((array) DB::table('media_files')->where('id', $id)->first())->toBe($this->stored);
})->with([
    'a qualified bulk update', 'an increment of the size', 'an increment\'s extra columns', 'an upsert\'s update half, in the escape hatch',
    'a qualified bulk update of the offer', 'an increment\'s extra columns, the offer', 'an upsert\'s update half naming the offer, in the escape hatch',
]);

/*
 * ADR-041 decision 2: no row is created offered. *Offer to readers* decides from the locked rows that a file is private and
 * kept to one site; a row stored offered would skip both. Every Eloquent door that creates refuses, and writes nothing.
 *
 * ⚠️ THE QUIET ONES TOO (review, 2026-10-11): `saveQuietly()` and `createQuietly()` on a new row run no listener, and the
 * builder's `insert()`, `insertGetId()` and `insertOrIgnore()` none either — measured storing the offer as given — so
 * `ScopedBuilder` asks `MediaFile::refuseInsertedRow()` of every row they carry (`RefusesInsertedRows`), and of an
 * upsert's insert half; by column, so another case of the name is the same column (SQLite, MySQL and MariaDB read it
 * so); and refuses a subquery insert outright, whose rows it cannot read. `DB::table()` stays out of reach, the boundary
 * the builder states.
 */
it('refuses to create a file row offered to readers', function (string $door): void {
    // The other entry's row taken away below the model, so its entry is one a new row could be created for.
    DB::table('media_files')->where('id', $this->other->getKey())->delete();
    $row = [...array_diff_key($this->stored, ['id' => true]), 'entry_id' => $this->other->entry_id, 'path' => 'media/offered/new.png', 'reader_entitlement' => 'course.advanced-php'];
    $count = DB::table('media_files')->count();

    $write = match ($door) {
        'create' => fn () => MediaFile::create($row),
        'a new instance, saved' => fn () => (new MediaFile($row))->save(),
        'forceCreate' => fn () => MediaFile::query()->forceCreate($row),
        'a new instance, saved quietly' => fn () => (new MediaFile($row))->saveQuietly(),
        'createQuietly' => fn () => MediaFile::createQuietly($row),
        'the builder\'s insert' => fn () => MediaFile::query()->insert($row),
        'the builder\'s insertGetId' => fn () => MediaFile::query()->insertGetId($row),
        'the builder\'s insertOrIgnore' => fn () => MediaFile::query()->insertOrIgnore([$row]),
        'the name in upper case' => fn () => MediaFile::query()->insertGetId([...array_diff_key($row, ['reader_entitlement' => true]), 'READER_ENTITLEMENT' => 'course.advanced-php']),
        'the name in mixed case, qualified' => fn () => MediaFile::query()->insert([...array_diff_key($row, ['reader_entitlement' => true]), 'media_files.Reader_Entitlement' => 'course.advanced-php']),
        'an upsert\'s insert half, in the escape hatch' => fn () => MediaFile::withoutScopeBecause('a test of the offer at creation', static fn ($query) => $query
            ->upsert([$row], ['path'], ['mime'])),
        'the builder\'s insertUsing' => fn () => MediaFile::withoutScopeBecause('a test of the offer at creation', static fn ($query) => $query
            ->insertUsing(array_keys($row), DB::query()->selectRaw(implode(', ', array_fill(0, count($row), '?')), array_values($row)))),
        'the builder\'s insertOrIgnoreUsing' => fn () => MediaFile::withoutScopeBecause('a test of the offer at creation', static fn ($query) => $query
            ->insertOrIgnoreUsing(array_keys($row), DB::query()->selectRaw(implode(', ', array_fill(0, count($row), '?')), array_values($row)))),
    };

    // A subquery's rows cannot be read, so the builder refuses the insert itself, in its own words.
    expect($write)->toThrow(RuntimeException::class, str_contains($door, 'Using')
        ? 'the rows come from a subquery, so its own refusal of a row cannot be asked of them'
        : 'Refusing to store a media file offered to readers');

    expect(DB::table('media_files')->count())->toBe($count)
        ->and(DB::table('media_files')->where('path', 'media/offered/new.png')->exists())->toBeFalse();
})->with(['create', 'a new instance, saved', 'forceCreate', 'a new instance, saved quietly', 'createQuietly', 'the builder\'s insert', 'the builder\'s insertGetId', 'the builder\'s insertOrIgnore', 'the name in upper case', 'the name in mixed case, qualified', 'an upsert\'s insert half, in the escape hatch', 'the builder\'s insertUsing', 'the builder\'s insertOrIgnoreUsing']);

/* The control: the same row with no offer is created, and reads as offered to no one. */
it('creates a file row offered to no one', function (): void {
    DB::table('media_files')->where('id', $this->other->getKey())->delete();
    $file = MediaFile::create([...array_diff_key($this->stored, ['id' => true]), 'entry_id' => $this->other->entry_id, 'path' => 'media/offered/new.png']);

    expect(DB::table('media_files')->where('id', $file->getKey())->value('reader_entitlement'))->toBeNull()
        ->and($file->readerEntitlement())->toBeNull();
});

/*
 * ...and below the model, one method writes them: a second writer anywhere in core fails here, so the lift stays "for that
 * path alone" — a key naming one of them, qualified or not; a hand-written SET of one, quoted or not; and an increment or
 * a decrement of one. A below-model upsert or a whole-row rewrite spans lines this reads one at a time, and is left to
 * review (review of decision 32).
 */
it('writes visibility, checksum, size_bytes and reader_entitlement after creation in one method alone', function (): void {
    $writers = [];
    $raw = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/packages/core/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        $lines = file((string) $file) ?: [];

        foreach ($lines as $i => $line) {
            // A string that sets one of them is SQL written by hand.
            foreach ([...(preg_match_all('/\'[^\']*\'|"[^"]*"/', $line, $strings) > 0 ? $strings[0] : [])] as $string) {
                if (preg_match('/\bset\b.*(?<![\w.])["`]?(visibility|checksum|size_bytes|reader_entitlement)["`]?\s*=/i', $string) === 1) {
                    $raw[] = basename((string) $file).':'.($i + 1);
                }
            }

            $named = preg_match('/[\'"](?:\w+\.)?(visibility|checksum|size_bytes|reader_entitlement)[\'"]\s*(=>|\]\s*=(?!=))/', $line) === 1;
            $arithmetic = preg_match('/\b(?:increment|decrement)(?:Each)?\(\s*\[?\s*[\'"](?:\w+\.)?(visibility|checksum|size_bytes|reader_entitlement)[\'"]/', $line) === 1;

            if (! $named && ! $arithmetic) {
                continue;
            }

            $where = 'the class body';

            for ($j = $i; $j >= 0; $j--) {
                if (preg_match('/function\s+(\w+)\s*\(/', $lines[$j], $named) === 1) {
                    $where = $named[1].'()';

                    break;
                }

                if (preg_match('/^\s*(?:protected|private|public)\s+(?:static\s+)?(?:array\s+)?\$(\w+)/', $lines[$j], $property) === 1) {
                    $where = '$'.$property[1];

                    break;
                }
            }

            $writers[basename((string) $file, '.php').'::'.$where] = true;
        }
    }

    $writers = array_keys($writers);
    sort($writers);

    expect($raw)->toBe([])
        ->and($writers)->toBe([
            // A disk's configuration — `filesystems.disks.*.visibility` — not the column.
            'MediaDisks::resolvedEntry()',
            // The model's casts and its reasons, which write nothing.
            'MediaFile::$casts',
            'MediaFile::columnsFixedAtCreation()',
            // The row as `store()` creates it.
            'MediaLibrary::write()',
            // The switch, below the model, under custody's lock — ADR-042 decision 32; and the offer to readers, ADR-041
            // decision 1, through the same method.
            'MediaVisibility::writeRow()',
        ]);

    $writeRow = new ReflectionMethod(MediaVisibility::class, 'writeRow');

    expect($writeRow->isPrivate())->toBeTrue()->and($writeRow->isStatic())->toBeTrue();
});

/*
 * ...and the switch opens no window: while it holds the lock, a model write of the same columns — to another row, or to
 * the very row it is switching — is refused as it always is, and the other row is untouched.
 */
it('opens no window for a model write while a file is switched', function (): void {
    $other = $this->other->getKey();
    $otherBefore = (array) DB::table('media_files')->where('id', $other)->first();
    $refused = [];

    AuditorStandIn::install()->beforeRecording(function () use ($other, &$refused): void {
        foreach ([
            fn () => MediaFile::query()->whereKey($other)->update(['visibility' => 'private']),
            function (): void {
                $file = MediaFile::query()->findOrFail($this->file->getKey());
                $file->checksum = str_repeat('0', 64);
                $file->save();
            },
        ] as $write) {
            try {
                $write();
            } catch (RuntimeException $failure) {
                $refused[] = str_contains($failure->getMessage(), 'is fixed when MediaFile is created');
            }
        }
    });

    MediaVisibility::makePrivate($this->file->entry);

    expect($refused)->toBe([true, true])
        ->and(DB::table('media_files')->where('id', $this->file->getKey())->value('visibility'))->toBe('private')
        ->and((array) DB::table('media_files')->where('id', $other)->first())->toBe($otherBefore);
});
