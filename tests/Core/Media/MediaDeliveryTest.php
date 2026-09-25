<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Http\Controllers\MediaDownloadController;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * Delivery — ADR-041's two paths and the headers that make the read side mean something.
 *
 * ⚠️ THE REAL ROUTE IS A PANEL ROUTE AND IS ASSERTED IN THE BROWSER, NOT HERE. ADR-024 says this layer
 * structurally cannot see whether the panel wires it, and AGENTS.md §9 requires an admin route shape to be
 * loaded by a browser outside `/c/{type}` — so `e2e/media-delivery.spec.js` measures the URL. What this file
 * owns is the CONTROLLER's own decisions, exercised through a route of its own so that the status codes and
 * the headers are asserted where they are produced rather than where they are described.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);

    Storage::fake(MediaDisks::PRIVATE);
    Storage::fake('public');

    $this->org = Org::create(['slug' => 'acme', 'name' => 'Acme']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'main', 'name' => 'Main', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);

    $this->imageType = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true,
    ]);

    /** @var TestUser $user */
    $user = TestUser::create(['email' => 'editor@kitsune.test']);
    $this->user = $user;

    DB::table('org_user')->insert(['org_id' => $this->org->getKey(), 'user_id' => $user->getKey()]);

    $this->role = Role::create(['handle' => 'editor', 'name' => 'Editor']);
    DB::table('role_user')->insert(['role_id' => $this->role->getKey(), 'user_id' => $user->getKey()]);

    /*
     * A route of this file's own, carrying NO middleware. The panel supplies auth and `SetKitsuneContext` in
     * production; here the test establishes both directly, so what is measured is the controller rather than
     * the pipeline around it.
     *
     * ⚠️ NOT THE `web` GROUP, and the reason is worth naming: it carries `EncryptCookies`, which needs an
     * `APP_KEY` this package's Testbench host does not set — so every one of these would fail with
     * `MissingAppKeyException` and say nothing about media. The controller reads no session and no cookie;
     * `actingAs()` sets the user on the guard directly, which is all `Gate::authorize()` consults.
     *
     * ⚠️ AND IT CARRIES A LEADING `{tenant}` SEGMENT IT NEVER READS, WHICH IS THE POINT. The real route is
     * `admin/{tenant:slug}/media/{media}`, and Laravel resolves controller parameters that are not
     * type-hinted as classes POSITIONALLY rather than by name — so a controller taking a lone `string $media`
     * is handed the tenant slug on the real route and the id on a one-parameter test route. Every assertion
     * below passed against that bug until a browser loaded the real URL. A fixture whose shape differs from
     * production is a fixture that cannot see production's failure, so this one matches it.
     */
    Route::get('/test-media/{tenant}/{media}', MediaDownloadController::class)
        ->where('media', '[0-9]+')
        ->name('test.media');
});

afterEach(function (): void {
    app(Context::class)->forget();

    foreach (glob(sys_get_temp_dir().'/kitsune-del-*') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

function aDeliverableImage(EntryType $type, string $visibility = 'private', string $name = 'photo.png', bool $siteOnly = false): Entry
{
    $path = tempnam(sys_get_temp_dir(), 'kitsune-del-');
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    return MediaLibrary::store($path, $name, $type, $visibility, siteOnly: $siteOnly);
}

/*
 * ────────────────────────────────  The two paths  ────────────────────────────────
 */

/**
 * ADR-041: a public file gets a direct URL a CDN can cache, and no PHP is involved.
 *
 * ⚠️ NOT `->toBe(Storage::disk('public')->url($file->path))`, WHICH IS WHAT THIS ASSERTED AND WHAT REVIEW
 * CAUGHT: both sides of that equals call the same method, so it holds however broken the URL is. It passed
 * while a bare install served 403 there, because nothing outside `deploy/release.sh` created `public/storage`.
 * The shape is asserted here and the URL is FETCHED in `e2e/media-delivery.spec.js`, which is the only layer
 * that can tell whether a web server answers it.
 */
it('gives a public file a direct disk URL under the linked path', function (): void {
    $entry = aDeliverableImage($this->imageType, 'public', 'logo.png');
    $file = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    $url = MediaDelivery::urlFor($entry);

    expect($url)->toContain('/storage/'.$file->path)
        /* No panel, no tenant, no query string — the whole point is that nothing has to run to serve it. */
        ->and($url)->not->toContain('/admin/')
        ->and($url)->not->toContain('?')
        ->and(Storage::disk('public')->exists($file->path))->toBeTrue();
});

/**
 * ⚠️ THE DISK DECIDES, NOT THE VISIBILITY — ADR-042 decision 5. A public file whose row names another disk — awaiting
 * publication on the private disk, or a legacy row on `local` — is not at the public link's path, so it is delivered
 * as private, and outside a panel that is no URL at all.
 */
it('gives no direct URL to a public file its row places on another disk', function (string $disk): void {
    $entry = aDeliverableImage($this->imageType, 'public', 'logo.png');
    DB::table('media_files')->where('entry_id', $entry->getKey())->update(['disk' => $disk]);

    expect(MediaDelivery::urlFor($entry))->toBeNull();
})->with(['awaiting publication on the private disk' => MediaDisks::PRIVATE, 'a legacy row on local' => 'local']);

/**
 * ⚠️ AND THE INSTALLER HAS TO MAKE THE LINK, or the URL above names a path no web server can reach.
 * AGENTS.md §14: a published constraint nothing enforces is worse than an absent one. The browser suite
 * proves the link RESOLVES; this proves it is not removed from the two flows that create it.
 */
it('creates the public storage link in both documented install flows', function (): void {
    $root = dirname(__DIR__, 3);

    $skeleton = json_decode((string) file_get_contents($root.'/skeleton/composer.json'), true);
    $package = json_decode((string) file_get_contents($root.'/composer.json'), true);

    expect(implode("\n", $skeleton['scripts']['post-create-project-cmd']))->toContain('storage:link')
        ->and(implode("\n", $package['scripts']['skeleton:install']))->toContain('storage:link');
});

/**
 * ⚠️ NULL IS THE ANSWER, NOT A FAILURE. A private file's URL needs the site the user is operating in, and
 * outside the panel there is none — a console command, a queue worker, this test. Throwing would make one
 * non-media row in a list a 500.
 */
it('has no private URL to give outside a panel, rather than throwing', function (): void {
    $entry = aDeliverableImage($this->imageType);

    expect(MediaDelivery::urlFor($entry))->toBeNull();
});

/**
 * ⚠️ AND IT DOES NOT THROW WHEN NO PANEL IS REGISTERED AT ALL. `getCurrentOrDefaultPanel()` raises
 * `NoDefaultPanelSetException` despite its nullable return type, and core must work in a headless host
 * (ADR-002) — the case `PanelLessHostTest` pins for `Permissions`.
 */
it('resolves no route name in a host with no panel', function (): void {
    expect(MediaDelivery::routeName())->toBeNull();
});

it('has no URL for an entry that is not a media entry', function (): void {
    $type = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);

    $entry = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

    expect(MediaDelivery::urlFor($entry))->toBeNull()
        ->and(MediaDelivery::fileFor($entry))->toBeNull();
});

/*
 * ────────────────────────────────  Disposition  ────────────────────────────────
 */

it('serves a listed image inline and everything else as an attachment', function (): void {
    $inline = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'];

    foreach ($inline as $mime) {
        expect(MediaDelivery::dispositionFor(new MediaFile(['mime' => $mime])))->toBe('inline');
    }

    /* Every other type `MediaIntake` accepts. Video and audio are a deliberate absence, not an oversight. */
    foreach (['application/pdf', 'video/mp4', 'video/webm', 'audio/mpeg', 'text/plain', 'text/csv'] as $mime) {
        expect(MediaDelivery::dispositionFor(new MediaFile(['mime' => $mime])))->toBe('attachment');
    }
});

/** A type nobody has considered is downloaded, never rendered — the direction a mistake has to fall in. */
it('treats an unrecognised type as an attachment', function (): void {
    expect(MediaDelivery::dispositionFor(new MediaFile(['mime' => 'application/x-newfangled'])))->toBe('attachment');
});

/**
 * ⚠️ SVG IS NOW A TYPE THE INSTALLATION CAN ACCEPT, AND IT IS STILL AN ATTACHMENT — which is the case this
 * used to cover as "unrecognised" and no longer can. `kitsune/svg-sanitizer` opens the INTAKE gate; it does
 * not open this one, and the two are deliberately separate. An `image/svg+xml` that reached the disk was
 * sanitised on the way in, and it is still the one accepted type that can carry script if anything ever slips
 * through, so it never renders as a document from the private path.
 */
it('never serves svg inline, sanitised or not', function (): void {
    expect(MediaDelivery::dispositionFor(new MediaFile(['mime' => 'image/svg+xml'])))->toBe('attachment')
        ->and(MediaDelivery::INLINE)->not->toContain('image/svg+xml');
});

/*
 * ────────────────────────────────  The offered filename  ────────────────────────────────
 */

/**
 * ⚠️ A TITLE CONTAINING `/` WOULD OTHERWISE BE A 500. Symfony's `makeDisposition()` throws on a filename
 * containing `/`, `\` or `%`, and `MediaLibrary` seeds the title from the uploader's own filename.
 */
it('makes a safe filename from a title that could not be one', function (): void {
    $entry = new Entry(['title' => 'before/after 100% "done"']);
    $file = new MediaFile(['path' => 'media/1/2026/09/abc123.png']);

    $name = MediaDelivery::filenameFor($entry, $file);

    expect($name)->not->toContain('/')
        ->and($name)->not->toContain('%')
        ->and($name)->not->toContain('"')
        ->and($name)->toEndWith('.png');
});

/** The extension comes from the STORED path, which core generated — never from the caller's title. */
it('takes the extension from the stored path, not the title', function (): void {
    $entry = new Entry(['title' => 'invoice.pdf.exe']);
    $file = new MediaFile(['path' => 'media/1/2026/09/abc123.pdf']);

    expect(MediaDelivery::filenameFor($entry, $file))->toEndWith('.pdf')
        ->and(MediaDelivery::filenameFor($entry, $file))->not->toContain('.exe.');
});

it('falls back to a name when the title slugs away to nothing', function (): void {
    $entry = new Entry(['title' => '???']);
    $file = new MediaFile(['path' => 'media/1/2026/09/abc123.png']);

    expect(MediaDelivery::filenameFor($entry, $file))->toBe('file.png');
});

/*
 * ────────────────────────────────  Headers  ────────────────────────────────
 */

it('sends the stored mime, nosniff, a restrictive CSP and no-store', function (): void {
    $headers = MediaDelivery::headersFor(new MediaFile(['mime' => 'image/png', 'size_bytes' => 70]));

    expect($headers['Content-Type'])->toBe('image/png')
        ->and($headers['X-Content-Type-Options'])->toBe('nosniff')
        ->and($headers['Content-Security-Policy'])->toContain("default-src 'none'")
        ->and($headers['Content-Security-Policy'])->toContain('sandbox')
        ->and($headers['Cache-Control'])->toContain('no-store');
});

/*
 * ────────────────────────────────  The controller  ────────────────────────────────
 */

it('streams the bytes to a user who may view the entry', function (): void {
    $this->role->grant('entry.image.view');
    $entry = aDeliverableImage($this->imageType);
    $file = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    $response = $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey());

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->streamedContent())->toBe(Storage::disk($file->disk)->get($file->path));
});

/**
 * ⚠️ THE ADR-041 READ-SIDE RULE, ASSERTED AGAINST THE RESPONSE RATHER THAN AGAINST THE ARRAY. "Delivery never
 * infers a type from the path" is easy to believe and easy to lose: `FilesystemAdapter::response()` fills
 * `Content-Type` from `$this->mimeType($path)`, which Flysystem resolves from the EXTENSION. It uses `??=`,
 * so the header passed in wins — and if a later refactor stops passing it, this is the test that notices.
 *
 * So the row deliberately disagrees with its own path: the bytes are a PNG at a `.png` path, and the stored
 * `mime` says `application/pdf`. The response must say what the ROW says.
 */
it('sends the stored mime even when the path would say otherwise', function (): void {
    $this->role->grant('entry.image.view');
    $entry = aDeliverableImage($this->imageType);

    // Past the model, which fixes `mime` at creation: the disagreement is the fixture, not a write Kitsune makes.
    DB::table('media_files')->where('entry_id', $entry->getKey())->update(['mime' => 'application/pdf']);

    $response = $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey());

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        /* And the disposition follows the stored type too, so a PDF is downloaded rather than rendered. */
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

/**
 * ⚠️ 403, NOT 404 — the user is inside the panel and the entry is one their listings can show them, so
 * answering "not found" for a file that is plainly there sends an operator hunting a storage problem they do
 * not have. The refusal that DOES hide existence is the scope one below.
 */
it('refuses a user who holds no grant on the type', function (): void {
    $entry = aDeliverableImage($this->imageType);

    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey())->assertForbidden();
});

/**
 * ⚠️ WRITTEN FROM THE ATTACKER'S SIDE, AND THE GRANT IS HELD SO THAT ONLY THE SCOPE CAN DECIDE — AGENTS.md
 * §9. A test that withheld the permission too would pass against a controller with no scope check at all.
 */
/**
 * ⚠️ A FILE KEPT TO ONE SITE, which since ADR-042 decision 2 is the uploader's choice rather than every upload's
 * fate. Its shared twin is served at the same sibling site — the difference between the two is the sharing, and
 * nothing else. This measures `SiteScope`; the panel's own tenant rule is `MediaTenantScopeTest`'s.
 */
it('does not confirm that another site\'s file exists, even to a granted user', function (): void {
    $this->role->grant('entry.image.view');

    $kept = aDeliverableImage($this->imageType, siteOnly: true);
    $shared = aDeliverableImage($this->imageType, name: 'shared.png');

    $other = Site::create(['handle' => 'other', 'slug' => 'other', 'name' => 'Other', 'locale' => 'en']);
    app(Context::class)->setSite($other);

    $this->actingAs($this->user)->get('/test-media/t/'.$kept->getKey())->assertNotFound();
    $this->actingAs($this->user)->get('/test-media/t/'.$shared->getKey())->assertOk();
});

/** The cross-ORG boundary, which has no framework safety net and is the more important of the two. */
it('does not confirm that another org\'s file exists', function (): void {
    $this->role->grant('entry.image.view');

    $entry = aDeliverableImage($this->imageType);

    $otherOrg = Org::create(['slug' => 'other-org', 'name' => 'Other Org']);
    app(Context::class)->setOrg($otherOrg);
    app(Context::class)->setSite(Site::create([
        'org_id' => $otherOrg->getKey(), 'handle' => 'o', 'slug' => 'o', 'name' => 'O', 'locale' => 'en',
    ]));

    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey())->assertNotFound();
});

it('answers 404 for an entry that carries no media', function (): void {
    $type = EntryType::create([
        'org_id' => $this->org->getKey(), 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles',
    ]);
    $this->role->grant('entry.article.view');

    $entry = Entry::create(['entry_type_id' => $type->getKey(), 'title' => 'Plain', 'slug' => 'plain']);

    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey())->assertNotFound();
});

/**
 * ⚠️ A ROW WITHOUT ITS BYTES IS A 404 AND A LOG LINE, NEVER A 500. This is the state the write and disposal
 * orders were chosen to make impossible; if it happens anyway it is an operator's problem to find.
 */
it('answers 404 and reports when the row survives but the bytes do not', function (): void {
    $this->role->grant('entry.image.view');

    $entry = aDeliverableImage($this->imageType);
    $file = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    Storage::disk($file->disk)->delete($file->path);

    /*
     * ⚠️ A SPY RATHER THAN `shouldReceive()`. A strict mock replaces the whole `LogManager`, so any unrelated
     * log call anywhere in the request — the exception handler's own, for one — fails with "no expectations
     * were specified" and reports it as this test's failure.
     */
    Log::spy();

    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey())->assertNotFound();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message): bool => str_contains($message, $file->path)
    );
});

/**
 * ⚠️ AND IT SENDS THE OPERATOR TO THE RESIDUE ADR-042 DECISION 5 CAN LEAVE (T40), before concluding the file was removed
 * outside Kitsune: a public row whose only copy is on the private disk is what a refused delete leaves when its
 * compensation fails.
 */
it('names the residue custody can leave when a row survives without its bytes', function (): void {
    $this->role->grant('entry.image.view');

    $entry = aDeliverableImage($this->imageType, 'public');
    $file = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();
    Storage::disk(MediaDisks::PRIVATE)->put($file->path, (string) Storage::disk('public')->get($file->path));
    Storage::disk('public')->delete($file->path);
    Log::spy();

    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey())->assertNotFound();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'kitsune:media-prune')
        && str_contains($message, 'kept')
        && str_contains($message, 'kitsune:media-reconcile --entry='.$entry->getKey().' --force')
        && ! str_contains($message, 'the state the write and disposal orders were chosen to avoid'));
});

/**
 * ⚠️ `1abc`, NOT `abc`, AND THE DIFFERENCE IS THE ENTIRE TEST. `abc` returns 404 with or without the route's
 * digit constraint — no row has id 0 — so asserting on it would pass against a controller that has no
 * constraint at all, which is this project's most-repeated instrument trap.
 *
 * `1abc` is the input that bites: `entries.id` is an integer column, and MySQL compares a non-numeric string
 * against one by juggling it, so `WHERE id = '1abc'` matches row 1 and serves somebody else's bytes. SQLite
 * and Postgres refuse it on their own, which is exactly why the guard cannot be left to the engine
 * (AGENTS.md §5). The route declines the request before any of it runs.
 */
it('never reaches the database with an id the column cannot hold', function (): void {
    $this->role->grant('entry.image.view');

    /* The row the juggle would land on, so that a missing constraint has something to leak. */
    $entry = aDeliverableImage($this->imageType);

    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey())->assertOk();

    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey().'abc')->assertNotFound();
    $this->actingAs($this->user)->get('/test-media/t/abc')->assertNotFound();
});

/*
 * ────────────────────────────────  Two boundaries review found  ────────────────────────────────
 */

/**
 * ⚠️ ADR-022 — A TYPE MAY BELONG TO THIS ORG AND STILL BE DISABLED FOR THIS SITE, and no guard above asks.
 * `SiteScope` answers "is the row in this site"; `EntryPolicy` resolves a grant that is keyed per ORG. The
 * check normally arrives with `IdentifyEntryType`, which this route cannot invoke — it has no `{type}`
 * segment. Without it the same boundary answers two ways depending on which URL you ask.
 *
 * ⚠️ THE GRANT IS HELD SO THAT ONLY AVAILABILITY CAN DECIDE. Withholding it would produce a 403 and the
 * assertion below would pass against a controller that never consulted availability at all.
 */
it('refuses a file whose type is switched off for this site', function (): void {
    $this->role->grant('entry.image.view');

    $entry = aDeliverableImage($this->imageType);

    /* It works first, so the refusal below is the availability change and not the fixture. */
    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey())->assertOk();

    EntryTypeAvailability::create([
        'entry_type_id' => $this->imageType->getKey(),
        'scope_type' => 'site',
        'scope_id' => $this->site->getKey(),
        'is_enabled' => false,
    ]);

    /* 404 rather than 403, matching `IdentifyEntryType`: a site without this type has nothing to say. */
    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey())->assertNotFound();
});

/**
 * ⚠️ A 404, NOT A 500, AND ONLY POSTGRESQL EVER SAID OTHERWISE — AGENTS.md invariant 5. `[0-9]+` accepts
 * `999999999999999999999999`, which reaches `whereKey()`; PostgreSQL refuses to coerce it to the `bigint`
 * key and raises SQLSTATE 22003, while MySQL, MariaDB and SQLite return no rows and say nothing. Measured on
 * all four before the guard was written, which is why the guard is in PHP rather than left to the engine.
 */
it('answers 404 for an id larger than the key column can hold', function (): void {
    $this->role->grant('entry.image.view');

    $entry = aDeliverableImage($this->imageType);

    $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey())->assertOk();

    foreach (['999999999999999999999999', '9223372036854775808'] as $tooBig) {
        $this->actingAs($this->user)->get('/test-media/t/'.$tooBig)->assertNotFound();
    }

    /* The largest key the column CAN hold is a lookup rather than a refusal — the bound is not off by one. */
    $this->actingAs($this->user)->get('/test-media/t/9223372036854775807')->assertNotFound();
});

/** `007` and `7` must not be two URLs for one file. */
it('refuses a padded id rather than resolving it to the same file', function (): void {
    $this->role->grant('entry.image.view');

    $entry = aDeliverableImage($this->imageType);

    $this->actingAs($this->user)->get('/test-media/t/0'.$entry->getKey())->assertNotFound();
});

/**
 * ⚠️ A ROW STORED BEFORE ADR-042 STILL NAMES `local`, and is served from there. Delivery reads each row's own
 * `disk` rather than the configured one, which is what lets the private disk move without stranding a file.
 */
it('streams a row that still names local from local', function (): void {
    Storage::fake('local');
    $this->role->grant('entry.image.view');

    $entry = aDeliverableImage($this->imageType);
    $file = MediaFile::query()->where('entry_id', $entry->getKey())->firstOrFail();

    Storage::disk('local')->put($file->path, Storage::disk(MediaDisks::PRIVATE)->get($file->path));
    Storage::disk(MediaDisks::PRIVATE)->delete($file->path);
    DB::table('media_files')->where('id', $file->getKey())->update(['disk' => 'local']);

    $response = $this->actingAs($this->user)->get('/test-media/t/'.$entry->getKey());

    $response->assertOk();

    expect($response->streamedContent())->toBe(Storage::disk('local')->get($file->path));
});
