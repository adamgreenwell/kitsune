<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Storage writes', 'slug' => 'field-write-lock']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'handle' => 'main', 'slug' => 'field-write-main', 'name' => 'Main']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);
    $this->storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'amount', 'type' => 'number',
        'settings' => ['format' => 'decimal'], 'pii_class' => 'none',
    ]);
    Field::create(['entry_type_id' => $this->type->id, 'field_storage_id' => $this->storage->id, 'label' => 'Amount']);
});

afterEach(fn () => app(Context::class)->forget());

it('arms storage when the first inline value is saved quietly', function (): void {
    Entry::withoutEvents(fn (): Entry => Entry::create([
        'org_id' => $this->org->id, 'site_id' => $this->site->id,
        'entry_type_id' => $this->type->id, 'title' => 'Quiet amount', 'values' => ['amount' => 1.5],
    ]));

    expect($this->storage->fresh()->is_locked)->toBeTrue();
});

it('arms a first quiet update without locking empty configured fields', function (): void {
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Empty']);
    expect($this->storage->fresh()->is_locked)->toBeFalse();
    $entry->forceFill(['values' => ['amount' => 0]])->saveQuietly();
    expect($this->storage->fresh()->is_locked)->toBeTrue()
        ->and($entry->fresh()->values['amount'])->toBe(0);
});

it('leaves promoted storage unlocked when an unconvertible bulk or arithmetic write is refused', function (bool $arithmetic): void {
    $this->storage->update(['type' => 'slug', 'settings' => []]);
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'No slug']);
    expect($this->storage->fresh()->is_locked)->toBeFalse();
    $query = Entry::query()->whereKey($entry->id);
    $write = $arithmetic
        ? fn () => $query->increment('author_id', 0, ['slug' => 'first-value'])
        : fn () => $query->update(['slug' => 'first-value']);

    expect($write)->toThrow(RuntimeException::class, '[slug] cannot be written in bulk')
        ->and($this->storage->fresh()->is_locked)->toBeFalse()
        ->and($entry->fresh()->slug)->toBeNull();
})->with([false, true]);

it('arms configured promoted storage through an ordinary or quiet model save', function (bool $quiet): void {
    $this->storage->update(['type' => 'slug', 'settings' => []]);
    $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'No slug']);
    expect($this->storage->fresh()->is_locked)->toBeFalse();

    $entry->slug = 'First Value';
    $quiet ? $entry->saveQuietly() : $entry->save();

    expect($this->storage->fresh()->is_locked)->toBeTrue()->and($entry->fresh()->slug)->toBe('first-value');
})->with([false, true]);

it('arms a quiet relation create and preserves the quiet move refusal', function (): void {
    $this->storage->update(['type' => 'relation', 'settings' => []]);
    $source = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    $target = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Target']);
    $pivot = EntryRelation::createQuietly(['org_id' => $this->org->id, 'source_entry_id' => $source->id,
        'target_entry_id' => $target->id, 'field_storage_id' => $this->storage->id]);
    expect($this->storage->fresh()->is_locked)->toBeTrue();
    $other = FieldStorage::create(['org_id' => $this->org->id, 'handle' => 'other', 'type' => 'relation', 'pii_class' => 'none']);
    $pivot->field_storage_id = $other->id;
    expect(fn () => $pivot->saveQuietly())->toThrow(RuntimeException::class);
    expect($pivot->fresh()->field_storage_id)->toBe($this->storage->id)->and($other->fresh()->is_locked)->toBeFalse();
});

it('refuses relation data after an empty storage changes to a scalar strategy', function (): void {
    $this->storage->update(['type' => 'relation', 'settings' => []]);
    $source = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    $target = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Target']);
    $this->storage->update(['type' => 'text']);
    expect(fn () => EntryRelation::createQuietly(['org_id' => $this->org->id,
        'source_entry_id' => $source->id, 'target_entry_id' => $target->id,
        'field_storage_id' => $this->storage->id]))->toThrow(RuntimeException::class, 'Only relational');
    expect(EntryRelation::count())->toBe(0)->and($this->storage->fresh()->is_locked)->toBeFalse();
});

it('takes no storage row lock from early relation model guards', function (): void {
    $this->storage->update(['type' => 'relation', 'settings' => []]);
    $source = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Source']);
    $target = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Target']);
    $storageQueries = [];
    $beforeBuilder = [];
    DB::listen(function ($query) use (&$storageQueries): void {
        if (str_contains(strtolower($query->sql), 'field_storage')) {
            $storageQueries[] = strtolower($query->sql);
        }
    });
    EntryRelation::creating(function () use (&$storageQueries, &$beforeBuilder): void {
        $beforeBuilder = $storageQueries;
    });
    DB::transaction(fn () => EntryRelation::create(['org_id' => $this->org->id,
        'source_entry_id' => $source->id, 'target_entry_id' => $target->id,
        'field_storage_id' => $this->storage->id]));
    expect($beforeBuilder)->not->toBeEmpty();
    foreach ($beforeBuilder as $query) {
        expect($query)->not->toContain('for update');
    }
    expect($this->storage->fresh()->is_locked)->toBeTrue();
});

it('compares a stale shape edit with the current locked acceptance bounds', function (): void {
    $this->storage->update(['settings' => ['format' => 'decimal', 'max' => 10]]);
    $stale = $this->storage->fresh();
    $this->storage->update(['settings' => ['format' => 'decimal', 'max' => 20]]);
    Entry::create(['entry_type_id' => $this->type->id, 'values' => ['amount' => 15]]);
    $stale->settings = ['format' => 'decimal', 'max' => 15];
    expect(fn () => $stale->save())->toThrow(RuntimeException::class, 'cannot be narrowed');
    expect($this->storage->fresh()->settings['max'])->toBe(20);
    $this->storage->fresh()->update(['settings' => ['format' => 'decimal', 'max' => 30]]);
    expect($this->storage->fresh()->settings['max'])->toBe(30);
});

it('rolls data and storage arming back when its audit cannot be recorded', function (): void {
    $beforeEntries = Entry::count();
    $level = DB::connection()->transactionLevel();
    AuditLog::creating(static function (): never {
        throw new RuntimeException('Owned audit failure fixture.');
    });
    expect(fn () => Entry::create(['org_id' => $this->org->id, 'site_id' => $this->site->id,
        'entry_type_id' => $this->type->id, 'values' => ['amount' => 1.5]]))->toThrow(RuntimeException::class);
    expect(Entry::count())->toBe($beforeEntries)->and($this->storage->fresh()->is_locked)->toBeFalse()
        ->and(DB::connection()->transactionLevel())->toBe($level);
});

function fieldWriteCopyApplication(string $source, string $target): void
{
    File::ensureDirectoryExists($target);
    foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->getFilename() === 'vendor' || $item->isLink()) {
            continue;
        }
        $destination = $target.'/'.$item->getFilename();
        if ($item->isDir()) {
            if (str_ends_with($destination, '/bootstrap/cache')) {
                File::ensureDirectoryExists($destination);
            } else {
                fieldWriteCopyApplication($item->getPathname(), $destination);
            }
        } else {
            File::copy($item->getPathname(), $destination);
        }
    }
}

/** @param list<Process> $processes */
function fieldWriteBarrier(string $path, array $processes = []): array
{
    $deadline = microtime(true) + 25;
    while (! is_file($path)) {
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException('Storage race child exited before '.basename($path).': '.$process->getOutput().$process->getErrorOutput());
            }
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Storage race barrier timed out: '.basename($path));
        }
        usleep(5000);
    }

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

it('serializes the first data write and shape changes on independent connections', function (string $scenario): void {
    $root = dirname(__DIR__, 3);
    $directory = (getenv('FIELD_STORAGE_WRITE_LOCK_TMP') ?: sys_get_temp_dir()).'/kitsune-field-write-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory, 0700);
    touch($directory.'/race.sqlite');
    foreach (['controller', 'A', 'B', 'read', 'cleanup'] as $role) {
        fieldWriteCopyApplication($root.'/vendor/orchestra/testbench-core/laravel', $directory.'/app-'.$role);
    }
    $processes = [];
    $child = static function (string $mode, string $role) use ($root, $directory, $scenario, &$processes): Process {
        $process = new Process([PHP_BINARY, $root.'/tests/Core/Fixtures/FieldStorageWriteLockRace.php', $root, $directory, $mode, $role, $scenario], $root);
        $process->setTimeout(40);
        $processes[] = $process;

        return $process;
    };
    try {
        $child('init', 'controller')->mustRun();
        $initialized = fieldWriteBarrier($directory.'/initialized.json');
        $a = $child('worker', 'A');
        $b = $child('worker', 'B');
        $a->start();
        $b->start();
        foreach (['A', 'B'] as $role) {
            expect(fieldWriteBarrier($directory.'/'.$role.'-ready.json', [$a, $b]))->toBe(['level' => 0, 'pdoTransaction' => false]);
        }
        touch($directory.'/go-A');
        $paused = fieldWriteBarrier($directory.'/A-paused.json', [$a, $b]);
        touch($directory.'/go-B');
        fieldWriteBarrier($directory.'/B-attempted.json', [$b]);
        usleep(100000);
        $queued = $b->isRunning() && ! is_file($directory.'/B-result.json');
        touch($directory.'/release-A');
        $a->wait();
        $resultA = fieldWriteBarrier($directory.'/A-result.json');
        $resultB = fieldWriteBarrier($directory.'/B-result.json');
        touch($directory.'/retry-B');
        $b->wait();
        $child('read', 'read')->mustRun();
        $read = fieldWriteBarrier($directory.'/read.json');

        expect($a->isSuccessful())->toBeTrue($a->getOutput().$a->getErrorOutput())
            ->and($b->isSuccessful())->toBeTrue($b->getOutput().$b->getErrorOutput())
            ->and($resultA['success'])->toBeTrue()
            ->and($paused['level'])->toBeGreaterThanOrEqual(1)
            ->and($paused['pdoTransaction'])->toBeTrue();
        foreach ([$resultA, $resultB, $read] as $result) {
            expect($result['level'])->toBe(0)->and($result['pdoTransaction'])->toBeFalse();
        }

        if (str_ends_with($scenario, 'shape-first')) {
            $lastB = is_file($directory.'/B-retry.json') ? fieldWriteBarrier($directory.'/B-retry.json') : $resultB;
            if (str_starts_with($scenario, 'relation')) {
                expect($lastB['success'])->toBeFalse()->and($read['relations'])->toBe(0)
                    ->and($read['locked'])->toBeFalse()->and($read['revisions'])->toBe(1)->and($read['audits'])->toBe(2);
            } else {
                expect($lastB['success'])->toBeTrue()->and($read['values'])->toBe(['amount' => 1])
                    ->and($read['locked'])->toBeTrue()->and($read['revisions'])->toBe(2)->and($read['audits'])->toBe(3);
            }
        } else {
            expect($resultB['success'])->toBeFalse()->and($read['locked'])->toBeTrue()
                ->and($read['revisions'])->toBe(2)->and($read['audits'])->toBe(str_starts_with($scenario, 'relation') ? 2 : 3);
            if (str_starts_with($scenario, 'relation')) {
                expect($read['settings'])->toBe([])->and($read['relations'])->toBe(1);
            } elseif (str_starts_with($scenario, 'promoted')) {
                expect($read['type'])->toBe('slug')->and($read['slug'])->toBe('first-value');
            } else {
                expect($read['settings'])->toBe(['format' => 'decimal'])->and($read['values'])->toBe(['amount' => 1.5]);
            }
        }
        // Native engines must queue while A holds the storage row; SQLite may instead refuse a snapshot upgrade.
        if ($initialized['driver'] !== 'sqlite') {
            expect($queued)->toBeTrue('The competing operation finished while the first write was paused.');
        }
    } finally {
        foreach (['release-A', 'go-A', 'go-B', 'retry-B'] as $barrier) {
            touch($directory.'/'.$barrier);
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0.2);
            }
        }
        try {
            $cleanup = $child('cleanup', 'cleanup');
            $cleanup->mustRun();
            if (! is_file($directory.'/cleanup.json')) {
                throw new RuntimeException('Storage race cleanup produced no receipt: '.$cleanup->getOutput().$cleanup->getErrorOutput());
            }
            expect(fieldWriteBarrier($directory.'/cleanup.json'))->toBe(['ownedRows' => 0]);
        } finally {
            File::deleteDirectory($directory);
        }
    }
})->with(['inline-writer-first', 'inline-shape-first', 'promoted-writer-first', 'relation-writer-first', 'relation-shape-first', 'relation-strategy-shape-first']);
