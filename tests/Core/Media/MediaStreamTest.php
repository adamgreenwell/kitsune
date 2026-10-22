<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaStream;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;
use Kitsune\Core\Tests\Fixtures\TrickleStream;
use League\Flysystem\Filesystem;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * A reader's download from the stream up — ADR-041, the reader's download route (decisions 1–4, Adam, 2026-10-11).
 * `ReaderDownloadTest` asks the route; this asks what the route is made of: the id it accepts, how the bytes leave, and
 * the name they leave under.
 *
 * ⚠️ WHAT REACHES THE OUTPUT LAYER, WRITE BY WRITE. A callback buffer of one byte hands each `echo` to the test as it is
 * made, so the chunk size is read from the output itself — a stream wrapper's own reads are PHP's 8 KiB whatever the
 * caller asked for (measured), and cannot tell a 256 KiB loop from `fpassthru()`.
 */

afterEach(function (): void {
    app(Context::class)->forget();

    // This process's own (#185's lesson): another run sharing the temporary directory has its own in flight.
    foreach (glob(sys_get_temp_dir().'/kitsune-stream-'.getmypid().'-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

/** A file of these bytes on the local disk, outside any disk Kitsune knows. */
function streamFile(string $bytes): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'kitsune-stream-'.getmypid().'-');
    file_put_contents($path, $bytes);

    return $path;
}

/** @return array{0: Entry, 1: MediaFile} an entry and its row, as the route has them once it has said yes */
function streamOf(int $size, string $title = 'Annual report 2026', string $mime = 'application/pdf', string $path = 'media/1/2026/10/abc123.pdf'): array
{
    $entry = new Entry(['title' => $title]);
    $entry->forceFill(['id' => 4821]);

    return [$entry, new MediaFile(['disk' => MediaDisks::PRIVATE, 'path' => $path, 'mime' => $mime, 'size_bytes' => $size])];
}

/**
 * Send a response's body, and say what reached the output layer: each write's length, and the bytes.
 *
 * @return array{0: list<int>, 1: string}
 */
function streamWrites(StreamedResponse $response): array
{
    $writes = [];
    $sent = '';

    ob_start(static function (string $buffer) use (&$writes, &$sent): string {
        if ($buffer !== '') {
            $writes[] = strlen($buffer);
            $sent .= $buffer;
        }

        return '';
    }, 1);

    try {
        $response->sendContent();
    } finally {
        ob_end_clean();
    }

    return [$writes, $sent];
}

/** A request's log lines: level, message and context. @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function streamLogged(Closure $call): array
{
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = [$message->level, $message->message, $message->context];
    });

    try {
        $call();
    } finally {
        Event::forget(MessageLogged::class);
    }

    return $logged;
}

// ---- The id ---------------------------------------------------------------------------------------------------------

it('reads an id from a URL as the key column can hold it, or not at all', function (mixed $raw, ?string $key): void {
    expect(MediaStream::key($raw))->toBe($key);
})->with([
    'one' => ['1', '1'],
    'zero' => ['0', '0'],
    'an id' => ['4821', '4821'],
    'the largest the column holds' => ['9223372036854775807', '9223372036854775807'],
    'one past it' => ['9223372036854775808', null],
    'a longer one' => ['99999999999999999999999', null],
    'nothing' => ['', null],
    'a padded id' => ['007', null],
    'two zeros' => ['00', null],
    'letters' => ['abc', null],
    'digits then letters' => ['1abc', null],
    'a minus sign' => ['-1', null],
    'a plus sign' => ['+1', null],
    'a space before' => [' 1', null],
    'a newline after' => ["1\n", null],
    'a decimal' => ['1.0', null],
    'an exponent' => ['1e3', null],
    'Arabic-Indic digits' => ['٤٨٢١', null],
    'full-width digits' => ['４８２１', null],
    'an integer, not text' => [4821, null],
    'no parameter' => [null, null],
    'a list' => [['1'], null],
]);

// ---- The bytes ------------------------------------------------------------------------------------------------------

it('writes the file 256 KiB at a time, and every byte of it', function (): void {
    $bytes = random_bytes(4 * MediaStream::CHUNK + 5);
    $handle = fopen(streamFile($bytes), 'rb');
    [$entry, $file] = streamOf(strlen($bytes));

    $response = MediaStream::respond(Request::create('/x'), $entry, $file, $handle);

    expect($response)->toBeInstanceOf(StreamedResponse::class);

    [$writes, $sent] = streamWrites($response);

    expect(MediaStream::CHUNK)->toBe(262_144)
        ->and($writes)->toBe([262_144, 262_144, 262_144, 262_144, 5])
        ->and($sent)->toBe($bytes)
        ->and(is_resource($handle))->toBeFalse();
});

it('sends every byte through a stream that hands back fewer than were asked for', function (): void {
    $bytes = random_bytes(700);
    $handle = TrickleStream::open(streamFile($bytes));
    [$entry, $file] = streamOf(strlen($bytes));

    [$writes, $sent] = streamWrites(MediaStream::respond(Request::create('/x'), $entry, $file, $handle));

    expect($sent)->toBe($bytes)
        ->and($writes)->toHaveCount(700)
        ->and(is_resource($handle))->toBeFalse();
});

it('sends no more than the row promises, and closes a file that ends before it', function (): void {
    $bytes = random_bytes(100);

    // Longer than its row: cut where `Content-Length` says.
    $longer = fopen(streamFile($bytes), 'rb');
    [$entry, $file] = streamOf(60);
    $response = MediaStream::respond(Request::create('/x'), $entry, $file, $longer);

    expect($response->headers->get('Content-Length'))->toBe('60')
        ->and(streamWrites($response)[1])->toBe(substr($bytes, 0, 60))
        ->and(is_resource($longer))->toBeFalse();

    // Shorter than its row: what there is, and the handle closed on the way out.
    $shorter = fopen(streamFile($bytes), 'rb');
    [$entry, $file] = streamOf(200);

    expect(streamWrites(MediaStream::respond(Request::create('/x'), $entry, $file, $shorter))[1])->toBe($bytes)
        ->and(is_resource($shorter))->toBeFalse();
});

/*
 * ⚠️ THE MEMORY GUARD. `fpassthru()`, which `FilesystemAdapter::response()` uses, hands a plain file to the output layer
 * in one write: measured, 128 MiB of growth for a 64 MiB file under this buffer, and 66 MiB of a PHP-FPM worker. The loop
 * grows by under 1 MiB. Nothing else in the suite sees memory: the floor harness runs the CLI with no output buffer.
 */
it('streams 64 MiB in well under 8 MiB of memory', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'kitsune-stream-'.getmypid().'-');
    $out = fopen($path, 'wb');

    for ($i = 0; $i < 64; $i++) {
        fwrite($out, random_bytes(1_048_576));
    }

    fclose($out);

    $handle = fopen($path, 'rb');
    [$entry, $file] = streamOf(64 * 1_048_576);
    $response = MediaStream::respond(Request::create('/x'), $entry, $file, $handle);

    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    ob_start(static fn (): string => '', 4096);

    try {
        $response->sendContent();
    } finally {
        ob_end_clean();
    }

    expect(memory_get_peak_usage() - $before)->toBeLessThan(8 * 1_048_576)
        ->and(is_resource($handle))->toBeFalse();
});

it('answers HEAD with the headers and no body, and closes the file at once', function (): void {
    $handle = fopen(streamFile(str_repeat('x', 45)), 'rb');
    [$entry, $file] = streamOf(45);

    $response = MediaStream::respond(Request::create('/x', 'HEAD'), $entry, $file, $handle);

    expect(is_resource($handle))->toBeFalse()
        ->and($response)->not->toBeInstanceOf(StreamedResponse::class)
        ->and($response->getContent())->toBe('')
        ->and($response->headers->get('Content-Length'))->toBe('45')
        ->and($response->headers->get('Content-Disposition'))->toBe("attachment; filename=annual-report-2026.pdf; filename*=utf-8''Annual%20report%202026.pdf");
});

it('sends the stored type and length, and offers the file under the reader\'s name with the slug behind it', function (): void {
    $handle = fopen(streamFile('png'), 'rb');
    [$entry, $file] = streamOf(3, 'Course map: 18 holes', 'image/png', 'media/1/2026/10/abc123.png');

    $response = MediaStream::respond(Request::create('/x'), $entry, $file, $handle);

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Length'))->toBe('3')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toBe("inline; filename=course-map-18-holes.png; filename*=utf-8''Course%20map%2018%20holes.png");

    fclose($handle);
});

// ---- The bytes missing ----------------------------------------------------------------------------------------------

it('opens nothing and writes one line naming the file, when the bytes are not there — or the disk throws', function (): void {
    Storage::fake(MediaDisks::PRIVATE);
    [$entry, $file] = streamOf(3);

    $logged = streamLogged(function () use ($entry, $file): void {
        expect(MediaStream::open($entry, $file))->toBeNull()
            ->and(MediaStream::present($entry, $file))->toBeFalse();
    });

    expect($logged)->toHaveCount(2)
        ->and($logged[0][0])->toBe('warning')
        ->and($logged[0][1])->toBe('Kitsune has a media_files row whose bytes are missing: ['.MediaDisks::PRIVATE.':media/1/2026/10/abc123.pdf] for entry 4821. If a delete of this entry was refused and its compensation failed, the only copy is on the private disk, where kitsune:media-prune lists it as an extra copy, kept; kitsune:media-reconcile --entry=4821 --force puts it back, or says what will (ADR-042 decision 5). Otherwise the file was removed outside Kitsune.')
        ->and($logged[1][1])->toBe($logged[0][1])
        ->and($logged[0][2])->toBe([]);

    // There, it opens; on a disk that throws for it, it is missing again, never a 500.
    Storage::disk(MediaDisks::PRIVATE)->put($file->path, 'pdf');
    $handle = MediaStream::open($entry, $file);

    expect(is_resource($handle))->toBeTrue()
        ->and(MediaStream::present($entry, $file))->toBeTrue();

    fclose($handle);

    $root = rtrim(Storage::disk(MediaDisks::PRIVATE)->path(''), '/');
    $disk = new RefusingDisk($root, MediaDisks::PRIVATE);
    $disk->unreadable[] = $file->path;
    $disk->unknown[] = $file->path;
    Storage::set(MediaDisks::PRIVATE, new LocalFilesystemAdapter(new Filesystem($disk), $disk, ['driver' => 'local', 'root' => $root, 'throw' => true]));

    $logged = streamLogged(function () use ($entry, $file): void {
        expect(MediaStream::open($entry, $file))->toBeNull()
            ->and(MediaStream::present($entry, $file))->toBeFalse();
    });

    expect($logged)->toHaveCount(2);
});

it('writes a database refusal as its SQLSTATE and nothing else', function (string $state, string $written): void {
    $logged = streamLogged(fn () => MediaStream::unavailable($state));

    expect($logged)->toBe([[
        'warning',
        "A reader's download could not be answered: the database refused (SQLSTATE {$written}). The reader, the file and the entitlement are not logged.",
        [],
    ]]);
})->with([
    'a SQLSTATE' => ['42P01', '42P01'],
    'SQLite\'s' => ['HY000', 'HY000'],
    'anything else a driver hands back' => ['no such table: entitlements (reader 81234)', 'unknown'],
    'nothing' => ['', 'unknown'],
]);

it('refuses a malformed recorded size instead of casting it into an apparently matching length', function (mixed $recorded): void {
    Storage::fake(MediaDisks::PRIVATE);
    [$entry, $file] = streamOf(0);
    Storage::disk(MediaDisks::PRIVATE)->put($file->path, '');
    $file->setRawAttributes([...$file->getAttributes(), 'size_bytes' => $recorded]);

    $logged = streamLogged(function () use ($entry, $file): void {
        expect(MediaStream::open($entry, $file))->toBeNull();
    });

    expect($logged)->toHaveCount(1)
        ->and($logged[0][1])->toContain('could not verify an opened media file against its recorded size');
})->with([
    'missing' => [null],
    'word' => ['garbage'],
    'float' => [0.0],
    'boolean' => [false],
    'padded' => ['00'],
    'negative' => [-1],
    'exponent' => ['0e0'],
    'too large' => ['9223372036854775808'],
]);

// ---- What is read, whatever was found -------------------------------------------------------------------------------

it('asks whether the type is served here in one read, whether or not anything was found', function (): void {
    $org = Org::create(['slug' => 'stream', 'name' => 'Stream']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'stream-main', 'name' => 'Main', 'locale' => 'en']);

    foreach ([null, 7] as $typeId) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $enabled = MediaStream::typeEnabled($site, $typeId);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        expect($enabled)->toBeTrue()
            ->and($log)->toHaveCount(1)
            ->and($log[0]['query'])->toContain('entry_type_availability');
    }
});

// ---- The name it is offered under ---------------------------------------------------------------------------------

it('offers a reader the title as the file\'s name, and nothing in it a file system or a renderer would act on', function (string $title, string $path, string $name): void {
    $entry = new Entry(['title' => $title]);
    $file = new MediaFile(['path' => $path]);

    expect(MediaDelivery::readerFilenameFor($entry, $file))->toBe($name);

    // And a header can always be made of it, with the slug behind it.
    expect(HeaderUtils::makeDisposition('attachment', $name, MediaDelivery::filenameFor($entry, $file)))->toStartWith('attachment; filename=');
})->with([
    'Hebrew' => ['דוח שנתי 2026', 'media/1/a.pdf', 'דוח שנתי 2026.pdf'],
    'Japanese' => ['年次報告書', 'media/1/a.pdf', '年次報告書.pdf'],
    'a right-to-left override' => ["invoice\u{202E}fdp.exe", 'media/1/a.pdf', 'invoicefdp exe.pdf'],
    'a right-to-left isolate' => ["report \u{2067}2026\u{2069}", 'media/1/a.pdf', 'report 2026.pdf'],
    'an extension inside the title' => ['invoice.pdf.exe', 'media/1/a.pdf', 'invoice pdf exe.pdf'],
    'a NUL' => ["annual\0report", 'media/1/a.pdf', 'annualreport.pdf'],
    'a line break' => ["annual\r\nreport", 'media/1/a.pdf', 'annual report.pdf'],
    'what a file system refuses' => ['before/after: 100% "done" <draft> | why? * \\ end', 'media/1/a.pdf', 'before after 100 done draft why end.pdf'],
    'leading dots' => ['..hidden', 'media/1/a.pdf', 'hidden.pdf'],
    'three hundred characters' => [str_repeat('a', 300), 'media/1/a.pdf', str_repeat('a', 100).'.pdf'],
    'three hundred characters of Japanese' => [str_repeat('年', 300), 'media/1/a.pdf', str_repeat('年', 100).'.pdf'],
    'nothing but controls' => ["\u{202E}\u{200B}\0\t\u{2069}", 'media/1/a.pdf', 'file.pdf'],
    'nothing at all' => ['', 'media/1/a.pdf', 'file.pdf'],
    'bytes that are not UTF-8' => ["caf\xE9 menu", 'media/1/a.pdf', 'caf menu.pdf'],
    'a title the slug keeps' => ['photo', 'media/1/a.png', 'photo.png'],
    'no extension stored' => ['Notes', 'media/1/a', 'Notes'],
]);
