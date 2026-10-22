<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Http\Controllers\MediaDownloadController;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaStream;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\StaffMediaStreamDisk;
use Kitsune\Core\Tests\Fixtures\TestUser;
use Symfony\Component\HttpFoundation\StreamedResponse;

/* Controller decisions here; the real authenticated panel route is measured in the browser and PHP-FPM harness. */
beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    $local = Storage::fake(MediaDisks::PRIVATE);
    $this->disk = new StaffMediaStreamDisk($local->getDriver(), $local->getAdapter(), $local->getConfig());
    Storage::set(MediaDisks::PRIVATE, $this->disk);
    $this->org = Org::create(['name' => 'Staff stream', 'slug' => 'staff-stream']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['name' => 'Main', 'handle' => 'main', 'slug' => 'main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
    $this->type = EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'document', 'name' => 'Document', 'plural_name' => 'Documents', 'is_media' => true]);
    $this->user = TestUser::create(['email' => 'staff-stream@kitsune.test']);
    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $this->user->getKey()]);
    $this->role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $this->user->getKey()]);
    $this->role->grant('entry.document.view');
    // Keep both parameters: a positional controller argument previously read the Site slug as the media ID.
    Route::get('/staff-stream/{tenant}/{media}', MediaDownloadController::class);
});

afterEach(function (): void {
    if (is_resource($this->disk->opened)) {
        fclose($this->disk->opened);
    }

    app(Context::class)->forget();
});

/** Fixture bytes bypass intake deliberately: this test owns only the read side and no scratch files. */
function staffStreamFile(EntryType $type, string $bytes = 'pdf', string $title = '国際文書', bool $shared = false): Entry
{
    $entry = Entry::create(['entry_type_id' => $type->getKey(), 'site_id' => $shared ? null : app(Context::class)->site()?->getKey(), 'title' => $title]);
    $path = 'media/staff-stream-'.$entry->getKey().'.pdf';
    Storage::disk(MediaDisks::PRIVATE)->put($path, $bytes);
    MediaFile::create(['entry_id' => $entry->getKey(), 'disk' => MediaDisks::PRIVATE, 'path' => $path, 'mime' => 'application/pdf', 'size_bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'visibility' => 'private']);

    return $entry;
}

it('opens before success and sends the staff file in bounded writes with its checked international name', function (): void {
    $bytes = str_repeat('x', 4 * 262_144 + 5);
    $entry = staffStreamFile($this->type, $bytes, '国際文書 / invoice.pdf.exe');
    $response = $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertOk();

    expect($this->disk->opens)->toBe(1)
        ->and(is_resource($this->disk->opened))->toBeTrue()
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($bytes))
        ->and($response->headers->get('Content-Disposition'))->toBe("attachment; filename=invoicepdfexe.pdf; filename*=utf-8''%E5%9B%BD%E9%9A%9B%E6%96%87%E6%9B%B8%20invoice%20pdf%20exe.pdf")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $writes = [];
    $hash = hash_init('sha256');
    ob_start(static function (string $chunk) use (&$writes, $hash): string {
        if ($chunk !== '') {
            $writes[] = strlen($chunk);
            hash_update($hash, $chunk);
        }

        return '';
    }, 1);

    try {
        $response->baseResponse->sendContent();
    } finally {
        ob_end_clean();
    }

    expect($writes)->toBe([262_144, 262_144, 262_144, 262_144, 5])
        ->and(hash_final($hash))->toBe(hash('sha256', $bytes))
        ->and(is_resource($this->disk->opened))->toBeFalse();
});

it('opens and closes HEAD before returning an empty non-streaming response', function (): void {
    $entry = staffStreamFile($this->type);
    $response = $this->actingAs($this->user)->head('/staff-stream/main/'.$entry->getKey())->assertOk();

    expect($this->disk->opens)->toBe(1)
        ->and(is_resource($this->disk->opened))->toBeFalse()
        ->and($response->baseResponse)->not->toBeInstanceOf(StreamedResponse::class)
        ->and($response->getContent())->toBe('')
        ->and($response->headers->get('Content-Length'))->toBe('3');
});

it('refuses a failed open before success even while the file exists', function (string $failure): void {
    $entry = staffStreamFile($this->type);
    $this->disk->failure = $failure;
    Log::spy();

    $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertNotFound();

    expect($this->disk->opens)->toBe(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'kitsune:media-reconcile --entry='.$entry->getKey()));
})->with(['false', 'throw']);

it('refuses bytes whose opened size disagrees with the row before GET or HEAD succeeds', function (string $method, string $bytes): void {
    $entry = staffStreamFile($this->type, 'pdf');
    $file = $entry->mediaFile;
    Storage::disk(MediaDisks::PRIVATE)->put($file->path, $bytes);

    $response = $this->actingAs($this->user)->call($method, '/staff-stream/main/'.$entry->getKey())->assertNotFound();

    expect($response->headers->has('Content-Disposition'))->toBeFalse()
        ->and(is_resource($this->disk->opened))->toBeFalse();
})->with(['GET', 'HEAD'])->with(['shorter' => 'p', 'longer' => 'pdf changed']);

it('checks staff permission before opening any stored bytes', function (): void {
    $entry = staffStreamFile($this->type);
    $this->role->revoke('entry.document.view');

    $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertForbidden();

    expect($this->disk->opens)->toBe(0);
});

it('checks current Site availability before opening any stored bytes', function (): void {
    $entry = staffStreamFile($this->type);
    EntryTypeAvailability::create(['entry_type_id' => $this->type->getKey(), 'scope_type' => 'site', 'scope_id' => $this->site->getKey(), 'is_enabled' => false]);

    $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertNotFound();

    expect($this->disk->opens)->toBe(0);
});

it('refuses a sibling Site file but admits an Org-shared file from that Site', function (): void {
    $sibling = Site::create(['name' => 'Sibling', 'handle' => 'sibling', 'slug' => 'sibling', 'locale' => 'en']);
    app(Context::class)->setSite($sibling);
    $local = staffStreamFile($this->type);
    $shared = staffStreamFile($this->type, shared: true);
    app(Context::class)->setSite($this->site);

    $this->actingAs($this->user)->get('/staff-stream/main/'.$local->getKey())->assertNotFound();
    expect($this->disk->opens)->toBe(0);
    $this->actingAs($this->user)->head('/staff-stream/main/'.$shared->getKey())->assertOk();
    expect($this->disk->opens)->toBe(1);
});

it('refuses a different Org shared file even when the attacker holds the matching grant', function (): void {
    $rival = Org::create(['name' => 'Rival', 'slug' => 'rival']);
    app(Context::class)->setOrg($rival);
    // Same globally available type in both Orgs: the entry boundary, rather than a foreign type, must refuse.
    $globalType = EntryType::create(['org_id' => null, 'handle' => 'document', 'name' => 'Document', 'plural_name' => 'Documents', 'is_media' => true]);
    $entry = staffStreamFile($globalType, shared: true);
    app(Context::class)->setOrg($this->org);
    app(Context::class)->setSite($this->site);

    $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertNotFound();

    expect($this->disk->opens)->toBe(0);
});

it('refuses a forged recorded length rather than sending an apparently complete prefix', function (): void {
    $entry = staffStreamFile($this->type, '1234567890');
    DB::table('media_files')->where('entry_id', $entry->getKey())->update(['size_bytes' => 4]);
    $response = $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertNotFound();

    expect($response->headers->has('Content-Disposition'))->toBeFalse()
        ->and(is_resource($this->disk->opened))->toBeFalse();
});

it('refuses an unsupported opened stream before GET or HEAD sends success', function (string $method, string $fault): void {
    $entry = staffStreamFile($this->type);
    $this->disk->factory = static function () use ($fault) {
        $stream = Utils::streamFor('pdf');

        if ($fault === 'unknown size') {
            $stream = FnStream::decorate($stream, ['getSize' => static fn () => null]);
        } elseif ($fault === 'stat throws') {
            $stream = FnStream::decorate($stream, ['getSize' => static fn () => throw new RuntimeException('private adapter diagnostic')]);
        } elseif ($fault === 'not readable') {
            $stream = FnStream::decorate($stream, ['isReadable' => static fn () => false]);
        } elseif ($fault === 'misleading wrapper mode') {
            $stream = FnStream::decorate($stream, [
                'isReadable' => static fn () => false,
                'read' => static fn () => throw new RuntimeException('unreadable private stream'),
            ]);
            StreamWrapper::register();
            $handle = fopen('guzzle://stream', 'r+', false, StreamWrapper::createStreamContext($stream));
            expect(stream_get_meta_data($handle)['mode'])->toBe('r+');

            return $handle;
        } else {
            $stream = FnStream::decorate($stream, ['isSeekable' => static fn () => false, 'seek' => static fn () => throw new RuntimeException('unseekable')]);
        }

        return StreamWrapper::getResource($stream);
    };

    $response = $this->actingAs($this->user)->call($method, '/staff-stream/main/'.$entry->getKey())->assertNotFound();

    expect($response->headers->has('Content-Disposition'))->toBeFalse()
        ->and($response->getContent())->not->toContain('private adapter diagnostic')
        ->and(is_resource($this->disk->opened))->toBeFalse();
})->with(['GET', 'HEAD'])->with(['unknown size', 'stat throws', 'not readable', 'misleading wrapper mode', 'unseekable']);

it('resets the actual PSR-7 object even when its wrapper initially reports logical offset zero', function (): void {
    $entry = staffStreamFile($this->type);
    $this->disk->factory = static function () {
        $stream = Utils::streamFor('pdf');
        $stream->read(1);
        $handle = StreamWrapper::getResource($stream);
        expect(ftell($handle))->toBe(0);

        return $handle;
    };

    $response = $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertOk();

    expect($response->streamedContent())->toBe('pdf')
        ->and(is_resource($this->disk->opened))->toBeFalse();
});

it('accepts and closes a stat-capable zero-byte file for GET and HEAD', function (string $method): void {
    $entry = staffStreamFile($this->type, '');
    $response = $this->actingAs($this->user)->call($method, '/staff-stream/main/'.$entry->getKey())->assertOk();

    expect($response->headers->get('Content-Length'))->toBe('0')
        ->and($method === 'GET' ? $response->streamedContent() : $response->getContent())->toBe('')
        ->and(is_resource($this->disk->opened))->toBeFalse();
})->with(['GET', 'HEAD']);

it('refuses changed custody or offer metadata before opening a stale file snapshot', function (array $change): void {
    $entry = staffStreamFile($this->type);
    $file = $entry->mediaFile;
    DB::table('media_files')->where('entry_id', $entry->getKey())->update($change);

    expect(MediaStream::openCurrent($entry, $file))->toBeNull()
        ->and($this->disk->opens)->toBe(0);
})->with([
    'disk' => [['disk' => 'public']],
    'path' => [['path' => 'media/replacement.pdf']],
    'size' => [['size_bytes' => 4]],
    'content type' => [['mime' => 'text/html']],
    'checksum' => [['checksum' => hash('sha256', 'new')]],
    'visibility' => [['visibility' => 'public']],
    'offer' => [['reader_entitlement' => 'course.changed']],
]);

it('refuses a file moved or retyped after its entry was authorized', function (string $change): void {
    $entry = staffStreamFile($this->type);
    $file = $entry->mediaFile;
    $other = $change === 'site_id'
        ? Site::create(['name' => 'Other', 'handle' => 'other', 'slug' => 'other', 'locale' => 'en'])
        : EntryType::create(['org_id' => $this->org->getKey(), 'handle' => 'other', 'name' => 'Other', 'plural_name' => 'Others', 'is_media' => true]);
    DB::table('entries')->where('id', $entry->getKey())->update([$change => $other->getKey()]);

    expect(MediaStream::openCurrent($entry, $file))->toBeNull()
        ->and($this->disk->opens)->toBe(0);
})->with(['site_id', 'entry_type_id']);

it('accepts a stat-capable PSR-7 stream without reading or buffering its bytes ahead', function (): void {
    $bytes = str_repeat('p', 262_145);
    $entry = staffStreamFile($this->type, $bytes);
    $reads = 0;
    $this->disk->factory = static function () use ($bytes, &$reads) {
        $stream = Utils::streamFor($bytes);

        return StreamWrapper::getResource(FnStream::decorate($stream, ['read' => static function (int $count) use ($stream, &$reads): string {
            $reads++;

            return $stream->read($count);
        }]));
    };

    $response = $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertOk();

    expect($reads)->toBe(0)
        ->and($response->streamedContent())->toBe($bytes)
        ->and($reads)->toBeGreaterThan(0)
        ->and(is_resource($this->disk->opened))->toBeFalse();
});

it('opens under the custody fence but releases its own fence before the body is sent', function (): void {
    $entry = staffStreamFile($this->type);
    $level = DB::connection()->transactionLevel();
    $this->disk->afterOpen = static function () use ($level): void {
        expect(DB::connection()->transactionLevel())->toBe($level + 1);
    };

    $response = $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertOk();

    expect(DB::connection()->transactionLevel())->toBe($level)
        ->and($response->streamedContent())->toBe('pdf');
});

it('keeps the checked open object when a pathname is replaced before its stat', function (): void {
    $entry = staffStreamFile($this->type);
    $moved = null;
    $this->disk->afterOpen = function ($handle, string $path) use (&$moved): void {
        $location = Storage::disk(MediaDisks::PRIVATE)->path($path);
        $moved = $location.'.checked';
        rename($location, $moved);
        file_put_contents($location, 'a different replacement');
    };

    try {
        $response = $this->actingAs($this->user)->get('/staff-stream/main/'.$entry->getKey())->assertOk();
        expect($response->streamedContent())->toBe('pdf');
    } finally {
        if ($moved !== null) {
            unlink($moved);
        }
    }
});

it('refuses malformed or foreign key spellings before an entry lookup', function (string $suffix): void {
    $entry = staffStreamFile($this->type);
    $id = match ($suffix) {
        'padded' => '0'.$entry->getKey(),
        'tail' => $entry->getKey().'abc',
        default => $suffix,
    };
    $entryReads = 0;
    DB::listen(static function ($query) use (&$entryReads): void {
        if (str_contains($query->sql, '"entries"') || str_contains($query->sql, '`entries`')) {
            $entryReads++;
        }
    });

    $this->actingAs($this->user)->get('/staff-stream/main/'.$id)->assertNotFound();

    expect($entryReads)->toBe(0)
        ->and($this->disk->opens)->toBe(0);
})->with(['padded', 'tail', '9223372036854775808', '999999999999999999999999', '٤٨٢١', '1e3']);
