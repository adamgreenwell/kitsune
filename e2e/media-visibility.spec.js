// @ts-check
const path = require('node:path');
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

/*
 * A stored file made public or private — Adam, ADR-042 decision 32 — from its own page, as an editor does it.
 *
 * ⚠️ ASKED OF THE WEB SERVER, NOT OF THE ROW ALONE. Whether a file is public is whether `/storage/…` serves it, and
 * whether a JPEG made public lost its location is what those bytes say — and the browser must still draw it turned as it
 * was. The PHP suite drives the switch, its refusals and every crash point; only a browser says the buttons are where an
 * editor looks, the acknowledgement stops an unticked click, and the page shows what happened.
 *
 * ⚠️ THIS SPEC'S OWN ENTRIES, found by their titles and removed again, force-deleted with their org in context, because
 * the suite shares one database and the other specs count what the lists hold.
 */

const PROBE = 'Visibility probe';

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        cwd: path.join(__dirname, '..', 'skeleton'),
        encoding: 'utf8',
    }).trim();
}

/** Golfdom's org and site in context, as a request to its admin would have them. */
const IN_GOLFDOM = "$site = \\Kitsune\\Core\\Models\\Site::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('slug', 'golfdom')->firstOrFail());"
    + ' app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($site->org_id));'
    + ' app(\\Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);';

/** Bytes from the PHP suite's own fixture, which needs no autoloader — CI's browser job installs the skeleton alone. */
function locatedJpeg(expression) {
    return Buffer.from(execFileSync('php', ['-r', `require 'tests/Core/Fixtures/LocatedJpeg.php'; echo base64_encode(\\Kitsune\\Core\\Tests\\Fixtures\\LocatedJpeg::${expression});`], {
        cwd: path.join(__dirname, '..'),
        encoding: 'utf8',
    }), 'base64');
}

const sha256 = (bytes) => crypto.createHash('sha256').update(bytes).digest('hex');

/** A JPEG stored as an upload stores it, private; shared with every site unless `siteOnly`. Its entry id. */
function storedJpeg(title, expression = 'photo(true)', siteOnly = true) {
    return Number(tinker(IN_GOLFDOM
        + " $type = \\Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'image')->whereNull('org_id')->firstOrFail());"
        + " $source = tempnam(sys_get_temp_dir(), 'kitsune-visibility-e2e-'); file_put_contents($source, base64_decode('" + locatedJpeg(expression).toString('base64') + "'));"
        + ` try { $entry = \\Kitsune\\Core\\Media\\MediaLibrary::store($source, 'probe.jpg', $type, 'private', '${title}', ${siteOnly ? 'true' : 'false'}); } finally { @unlink($source); }`
        + ' echo $entry->id;'));
}

/** The file's row, by its entry id. */
function row(id) {
    return JSON.parse(tinker(`$f = DB::table('media_files')->where('entry_id', ${id})->first();`
        + " echo json_encode(['visibility' => $f->visibility, 'disk' => $f->disk, 'path' => $f->path, 'checksum' => $f->checksum, 'size_bytes' => (int) $f->size_bytes]);"));
}

function removeFixtures() {
    tinker("\\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing a browser-test fixture', fn ($q) => $q->withTrashed()->where('title', 'like', "
        + JSON.stringify(`${PROBE} %`) + ")->get())"
        + "->each(function ($entry) { app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($entry->org_id)); $entry->forceDelete(); });");
}

/** Grant or revoke `entry.image.{action}` on the copy-editor's role, as `media-upload.spec.js` does. */
function copyEditor(method, actions) {
    tinker("app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail(DB::table('sites')->where('slug', 'golfdom')->value('org_id')));"
        + " $role = \\Kitsune\\Core\\Models\\Role::query()->where('handle', 'copy-editor')->firstOrFail();"
        + ` foreach (${JSON.stringify(actions.map((action) => `entry.image.${action}`))} as $p) { if (${method === 'grant' ? '! ' : ''}$role->permissions()->where('permission', $p)->exists()) { $role->${method}($p); } }`);
}

const READER_STATE = '.playwright/admin-reader-auth.json';

/** The header action, by its name: a modal's submit button carries the same one. */
const headerAction = (page, name) => page.locator('.fi-header').getByRole('button', { name, exact: true });

/** The open modal holding this submit button. */
const modal = (page, submit) => page.locator('.fi-modal-window').filter({ has: page.getByRole('button', { name: submit, exact: true }) }).last();

/** A notification, by its words. */
const notice = (page, words) => page.locator('.fi-no-notification').filter({ hasText: words });

test.describe.configure({ mode: 'serial' });

test.beforeEach(() => removeFixtures());

test.afterAll(() => removeFixtures());

/*
 * ADR-033's line, on a user who is not an owner: without `update` or `publish` neither button is there; with `update` the
 * button is there, disabled, naming the permission it lacks; with `publish` it is enabled.
 */
test('hides the switch from a reader, and disables it without publish, naming the permission', async ({ browser }) => {
    const id = storedJpeg(`${PROBE} grants`);
    const context = await browser.newContext({ storageState: READER_STATE });
    const page = await context.newPage();

    try {
        copyEditor('grant', ['view']);
        await page.goto(`/admin/golfdom/c/image/${id}`);
        await expect(page.locator('.fi-header')).toBeVisible();
        await expect(headerAction(page, 'Make public')).toHaveCount(0);

        copyEditor('grant', ['update']);
        await page.goto(`/admin/golfdom/c/image/${id}`);
        await expect(headerAction(page, 'Make public')).toBeDisabled();
        await headerAction(page, 'Make public').hover({ force: true });
        await expect(page.getByText(/entry\.image\.publish/)).toBeVisible();

        copyEditor('grant', ['publish']);
        await page.goto(`/admin/golfdom/c/image/${id}`);
        await expect(headerAction(page, 'Make public')).toBeEnabled();
    } finally {
        copyEditor('revoke', ['view', 'update', 'publish']);
        await context.close();
    }
});

/*
 * ⚠️ WHERE A PHOTO WAS MADE, GONE FROM WHAT THE WEB SERVES ONCE IT IS MADE PUBLIC — decisions 30 and 32. Unticked, nothing
 * changes; ticked, `/storage/…` serves the photo without its GPS data, its picture and orientation as uploaded, and its
 * row describes those bytes. Made private, the link stops; made public again, it is the same file.
 */
test('makes a private JPEG public without its GPS data, private again, and public again unchanged', async ({ page }) => {
    const photo = locatedJpeg('photo(true)');
    const body = locatedJpeg('body()');
    const id = storedJpeg(`${PROBE} photo`);
    const before = row(id);

    expect((await page.request.get(`/storage/${before.path}`)).status()).not.toBe(200);

    await page.goto(`/admin/golfdom/c/image/${id}`);
    await headerAction(page, 'Make public').click();
    const dialog = modal(page, 'Make public');
    await expect(dialog).toContainText('A JPEG loses the GPS coordinates in its EXIF and XMP data as it is made public');

    // Unticked: the acknowledgement is asked, and nothing changes.
    await dialog.getByRole('button', { name: 'Make public', exact: true }).click();
    await expect(dialog).toContainText('Tick the box to make this file public. Unticked, it stays private.');
    expect(row(id).visibility).toBe('private');

    await dialog.getByLabel('Make this file public').check();
    await dialog.getByRole('button', { name: 'Make public', exact: true }).click();
    await expect(notice(page, `"${PROBE} photo" is public`)).toBeVisible({ timeout: 15_000 });
    await expect(headerAction(page, 'Make private')).toBeVisible();

    const published = row(id);
    const served = await page.request.get(`/storage/${published.path}`);
    expect(served.status()).toBe(200);
    const bytes = await served.body();

    expect(published.visibility).toBe('public');
    expect(bytes.length).toBe(photo.length);
    expect(published.size_bytes).toBe(bytes.length);
    expect(published.checksum).toBe(sha256(bytes));
    expect(published.checksum).not.toBe(sha256(photo));
    expect(bytes.includes('SENTINEL-')).toBe(false);
    expect(bytes.includes('GGGGPPPP')).toBe(false);
    for (const kept of ['TRAILER-KEPT', 'Kept City', 'hdrgm:Version="1.0"']) {
        expect(bytes.includes(kept)).toBe(true);
    }
    for (let at = photo.indexOf(body); at !== -1; at = photo.indexOf(body, at + 1)) {
        expect(bytes.subarray(at, at + body.length).equals(body)).toBe(true);
    }

    const drawn = await page.evaluate(async (src) => {
        const image = new Image();
        image.src = src;
        await image.decode();

        return [image.naturalWidth, image.naturalHeight];
    }, `/storage/${published.path}`);
    expect(drawn).toEqual([8, 16]);

    // Made private: the link stops, and the panel still opens it — stripped, as it was made public.
    await headerAction(page, 'Make private').click();
    await modal(page, 'Make private').getByRole('button', { name: 'Make private', exact: true }).click();
    await expect(notice(page, `"${PROBE} photo" is private`)).toBeVisible({ timeout: 15_000 });

    expect(row(id).visibility).toBe('private');
    expect((await page.request.get(`/storage/${published.path}`)).status()).not.toBe(200);
    const opened = await page.request.get(`/admin/golfdom/media/${id}`);
    expect(opened.status()).toBe(200);
    expect(sha256(await opened.body())).toBe(published.checksum);

    // Made public again: the same file, nothing stripped twice.
    await headerAction(page, 'Make public').click();
    const again = modal(page, 'Make public');
    await again.getByLabel('Make this file public').check();
    await again.getByRole('button', { name: 'Make public', exact: true }).click();
    await expect(notice(page, `"${PROBE} photo" is public`)).toBeVisible({ timeout: 15_000 });

    expect(row(id).checksum).toBe(published.checksum);
    expect(sha256(await (await page.request.get(`/storage/${published.path}`)).body())).toBe(published.checksum);
});

/* A shared file changes for every site of the organisation, and its modal says so. */
test('says a shared file becomes public for every site', async ({ page }) => {
    const id = storedJpeg(`${PROBE} shared`, 'photo(true)', false);

    await page.goto(`/admin/golfdom/c/image/${id}`);
    await headerAction(page, 'Make public').click();

    await expect(modal(page, 'Make public')).toContainText('This file is shared with every site in the organisation, so it becomes public for all of them.');
});

/* Where its location cannot be removed with certainty, a JPEG stays private, and the page says why. */
test('keeps a JPEG private whose GPS data cannot be removed, and says why', async ({ page }) => {
    const id = storedJpeg(`${PROBE} unremovable`, 'unremovable()');

    await page.goto(`/admin/golfdom/c/image/${id}`);
    await headerAction(page, 'Make public').click();
    const dialog = modal(page, 'Make public');
    await dialog.getByLabel('Make this file public').check();
    await dialog.getByRole('button', { name: 'Make public', exact: true }).click();

    await expect(notice(page, `"${PROBE} unremovable" was not made public`)).toContainText('cannot be removed with certainty', { timeout: 15_000 });
    expect(row(id).visibility).toBe('private');
});
