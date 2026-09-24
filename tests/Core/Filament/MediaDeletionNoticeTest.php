<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\DeleteBulkAction;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Filament\MediaDeletionNotice;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\AuditorStandIn;
use Kitsune\Core\Tests\Fixtures\RefusingDisk;

/*
 * A delete the admin could not make, shown as Kitsune wrote it and escaped as text — ADR-042 decisions 5 and 7.
 *
 * ⚠️ READ FROM THE SESSION, WHERE FILAMENT KEEPS A NOTIFICATION UNTIL IT RENDERS IT. Filament passes the title and body
 * through `sanitizeHtml()`, which keeps markup, so an entry titled with a tag is the case that shows whether Kitsune
 * escaped it first. The browser spec `e2e/media-deletion.spec.js` shows the notification reaching the editor.
 */

beforeEach(function (): void {
    $this->roots = [];

    foreach (['public', MediaDisks::PRIVATE] as $name) {
        $root = sys_get_temp_dir().'/kitsune-notice-'.$name.'-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        $this->roots[] = $root;
        $this->disks[$name] = RefusingDisk::install($name, $root);
    }

    $org = Org::create(['slug' => 'notice', 'name' => 'Notice']);
    app(Context::class)->setOrg($org);
    app(Context::class)->setSite(Site::create(['handle' => 'main', 'slug' => 'notice-main', 'name' => 'Main', 'locale' => 'en']));
    $this->image = EntryType::create(['org_id' => $org->id, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true]);

    $this->store = function (string $title, string $visibility): Entry {
        $source = tempnam(sys_get_temp_dir(), 'kitsune-notice-');
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $entry = MediaLibrary::store($source, 'upload.png', $this->image, $visibility, title: $title);
        unlink($source);

        return $entry;
    };

    session()->forget('filament.notifications');
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach ($this->roots as $root) {
        exec('rm -rf '.escapeshellarg($root));
    }
});

/** @return list<array<string, mixed>> */
function sentNotices(): array
{
    return array_values((array) session('filament.notifications', []));
}

function stillLive(Entry $entry): bool
{
    return DB::table('entries')->where('id', $entry->id)->whereNull('deleted_at')->exists();
}

it('answers a refused delete with a notification naming the entry, escaped', function (): void {
    $entry = ($this->store)('<b>Logo</b>', 'public');
    $this->disks['public']->failDeletes = true;

    expect(MediaDeletionNotice::deleteOne($entry))->toBeFalse()
        ->and(stillLive($entry))->toBeTrue();

    $notices = sentNotices();

    expect($notices)->toHaveCount(1)
        ->and($notices[0]['status'])->toBe('danger')
        ->and($notices[0]['title'])->toBe('&quot;&lt;b&gt;Logo&lt;/b&gt;&quot; was not deleted')
        ->and($notices[0]['body'])->toStartWith("Refusing to trash entry {$entry->id}")
        ->and($notices[0]['body'])->not->toContain('<');
});

it('deletes quietly when nothing refuses', function (): void {
    $entry = ($this->store)('Logo', 'public');

    expect(MediaDeletionNotice::deleteOne($entry))->toBeTrue()
        ->and(stillLive($entry))->toBeFalse()
        ->and(sentNotices())->toBe([]);
});

/** Only a refusal is shown as written; anything else is Filament's to handle, as it was before. */
it('lets any other failure through', function (): void {
    $entry = ($this->store)('Logo', 'public');
    AuditorStandIn::install()->throwOnce(new RuntimeException('the audit row could not be written'));

    expect(fn () => MediaDeletionNotice::deleteOne($entry))->toThrow(RuntimeException::class, 'the audit row could not be written');

    expect(sentNotices())->toBe([]);
});

it('deletes each entry on its own, and names every one that stayed, escaped', function (): void {
    $refused = ($this->store)('<i>Scorecard</i>', 'public');
    $deleted = ($this->store)('Private notes', 'private');
    $this->disks['public']->failDeletes = true;

    MediaDeletionNotice::deleteEach(DeleteBulkAction::make(), [$refused, $deleted]);

    $notices = sentNotices();

    expect(stillLive($refused))->toBeTrue()
        ->and(stillLive($deleted))->toBeFalse()
        ->and($notices)->toHaveCount(1)
        ->and($notices[0]['title'])->toBe('One entry was not deleted; its file could not be taken off the web')
        ->and($notices[0]['body'])->toStartWith('&quot;&lt;i&gt;Scorecard&lt;/i&gt;&quot; was not deleted: Refusing to trash entry '.$refused->id)
        ->and($notices[0]['body'])->not->toContain('Private notes');
});
