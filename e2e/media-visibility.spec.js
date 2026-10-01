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

/** A 1×1 PNG. */
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** A PNG stored as an upload stores it, this site's own; its entry id. */
function storedPng(title, visibility) {
    return Number(tinker(IN_GOLFDOM
        + " $type = \\Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'image')->whereNull('org_id')->firstOrFail());"
        + " $source = tempnam(sys_get_temp_dir(), 'kitsune-visibility-e2e-'); file_put_contents($source, base64_decode('" + PNG_BASE64 + "'));"
        + ` try { $entry = \\Kitsune\\Core\\Media\\MediaLibrary::store($source, 'probe.png', $type, '${visibility}', '${title}', true); } finally { @unlink($source); }`
        + ' echo $entry->id;'));
}

/**
 * The image list, searched down to these words and showing `count` cards — never the page's select-all: golfdom holds
 * other specs' files.
 *
 * ⚠️ AND SETTLED BEFORE ANYTHING IS SELECTED: a search clears the selection when its debounced update lands, so a card
 * ticked before it would be unticked under the test. Its response arriving is not its being applied, so the cards
 * are waited on too: the list holds other files before the search, and these alone after it.
 */
async function listed(page, words, count) {
    await page.goto('/admin/golfdom/c/image');
    const searched = page.waitForResponse((response) => response.url().includes('/livewire') && (response.request().postData() ?? '').includes(words));
    await page.locator('.fi-ta').getByPlaceholder('Search').fill(words);
    await searched;
    await expect(page.locator('.fi-ta-record')).toHaveCount(count);
}

/** A card of the list, by its title. */
const card = (page, title) => page.locator('.fi-ta-record').filter({ hasText: title });

/** The list's *Bulk actions* menu, opened, and the action in it. */
async function bulkAction(page, label) {
    await page.getByRole('button', { name: /bulk actions/i }).click();

    return page.getByRole('button', { name: label, exact: true });
}

/** Show the list's trash, as Filament's filter shows it: '' not in the trash, '1' everything, '0' only the trash. */
async function showTrash(page, value) {
    // Exactly: a search shows its own *Remove filter* beside it.
    await page.getByRole('button', { name: 'Filter', exact: true }).click();
    await page.locator('select[wire\\:model="tableDeferredFilters.trashed.value"]').selectOption(value);
    await page.getByRole('button', { name: 'Apply filters' }).click();
    // Filament keeps its filter panel open once applied, over the cards.
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Apply filters' })).toBeHidden();
}

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

/*
 * A SELECTION MADE PUBLIC — Adam, ADR-042 decision 34. One acknowledgement covers it; each file is switched on its own,
 * so the two that can be are served without their GPS data and the one that cannot stays private, named in the one
 * notification, and stays selected for another try.
 */
test('makes the selected files public with one acknowledgement, and names the one it could not', async ({ page }) => {
    const photo = locatedJpeg('photo(true)');
    const ok = storedJpeg(`${PROBE} bulk ok`);
    const shared = storedJpeg(`${PROBE} bulk shared`, 'photo(true)', false);
    const bad = storedJpeg(`${PROBE} bulk bad`, 'unremovable()');

    await listed(page, `${PROBE} bulk`, 3);
    for (const title of ['ok', 'shared', 'bad']) {
        await card(page, `${PROBE} bulk ${title}`).getByRole('checkbox').check();
    }

    await (await bulkAction(page, 'Make selected public')).click();
    const dialog = modal(page, 'Make public');
    await expect(dialog).toContainText('Make the 3 selected files public');
    await expect(dialog).toContainText('One of them is shared with every site in the organisation, so it becomes public for all of those sites.');
    await expect(dialog).toContainText('A JPEG loses the GPS coordinates in its EXIF and XMP data as it is made public');
    await expect(dialog).toContainText('Unless this is ticked, the files stay private.');

    // Unticked: the acknowledgement is asked, and nothing changes.
    await dialog.getByRole('button', { name: 'Make public', exact: true }).click();
    await expect(dialog).toContainText('Tick the box to make these files public. Unticked, they stay private.');
    for (const id of [ok, shared, bad]) {
        expect(row(id).visibility).toBe('private');
    }

    await dialog.getByLabel('Make these files public').check();
    await dialog.getByRole('button', { name: 'Make public', exact: true }).click();

    const said = notice(page, 'One file was not made public');
    await expect(said).toBeVisible({ timeout: 30_000 });
    await expect(said).toContainText(`"${PROBE} bulk bad" was not made public`);
    await expect(said).toContainText('cannot be removed with certainty');
    await expect(said).toContainText('2 files were made public.');
    await expect(page.locator('.fi-no-notification')).toHaveCount(1);

    for (const id of [ok, shared]) {
        const published = row(id);
        const served = await page.request.get(`/storage/${published.path}`);
        expect(served.status()).toBe(200);
        const bytes = await served.body();

        expect(published.visibility).toBe('public');
        expect(published.checksum).toBe(sha256(bytes));
        expect(published.checksum).not.toBe(sha256(photo));
        expect(bytes.includes('SENTINEL-')).toBe(false);
        expect(bytes.includes('GGGGPPPP')).toBe(false);
    }

    expect(row(bad).visibility).toBe('private');
    expect((await page.request.get(`/storage/${row(bad).path}`)).status()).not.toBe(200);
    // Kept selected, so running it again goes on where it stopped.
    await expect(card(page, `${PROBE} bulk bad`).getByRole('checkbox')).toBeChecked();
});

/* A selection made private: its links stop, the panel still opens each, and the selection is cleared. */
test('makes the selected files private, says what that does, and clears the selection', async ({ page }) => {
    const ids = [storedPng(`${PROBE} bulk one`, 'public'), storedPng(`${PROBE} bulk two`, 'public')];
    const before = ids.map((id) => row(id));
    for (const file of before) {
        expect((await page.request.get(`/storage/${file.path}`)).status()).toBe(200);
    }

    await listed(page, `${PROBE} bulk`, 2);
    for (const title of ['one', 'two']) {
        await card(page, `${PROBE} bulk ${title}`).getByRole('checkbox').check();
    }

    await (await bulkAction(page, 'Make selected private')).click();
    const dialog = modal(page, 'Make private');
    await expect(dialog).toContainText('Make the 2 selected files private');
    await expect(dialog).toContainText('Their links stop opening them');
    await dialog.getByRole('button', { name: 'Make private', exact: true }).click();

    const said = notice(page, '2 files were made private');
    await expect(said).toBeVisible({ timeout: 30_000 });
    await expect(said).toContainText('Their public links no longer open them.');

    for (const [i, id] of ids.entries()) {
        expect(row(id).visibility).toBe('private');
        expect((await page.request.get(`/storage/${before[i].path}`)).status()).not.toBe(200);
        const opened = await page.request.get(`/admin/golfdom/media/${id}`);
        expect(opened.status()).toBe(200);
        expect(sha256(await opened.body())).toBe(before[i].checksum);
    }

    for (const title of ['one', 'two']) {
        await expect(card(page, `${PROBE} bulk ${title}`).getByRole('checkbox')).not.toBeChecked();
    }
});

/* Who sees them: no selection for a reader; disabled, naming the permission, for an editor who may only update. */
test('hides the selection switches from a reader, and disables them without publish, naming the permission', async ({ browser }) => {
    storedPng(`${PROBE} bulk grants`, 'private');
    const context = await browser.newContext({ storageState: READER_STATE });
    const page = await context.newPage();

    try {
        copyEditor('grant', ['view']);
        await listed(page, `${PROBE} bulk grants`, 1);
        await expect(card(page, `${PROBE} bulk grants`)).toHaveCount(1);
        await expect(card(page, `${PROBE} bulk grants`).getByRole('checkbox')).toHaveCount(0);

        copyEditor('grant', ['update']);
        await listed(page, `${PROBE} bulk grants`, 1);
        await card(page, `${PROBE} bulk grants`).getByRole('checkbox').check();
        const disabled = await bulkAction(page, 'Make selected public');
        await expect(disabled).toBeDisabled();
        await disabled.hover({ force: true });
        await expect(page.getByText(/entry\.image\.publish/)).toBeVisible();

        copyEditor('grant', ['publish']);
        await listed(page, `${PROBE} bulk grants`, 1);
        await card(page, `${PROBE} bulk grants`).getByRole('checkbox').check();
        await expect(await bulkAction(page, 'Make selected public')).toBeEnabled();
    } finally {
        copyEditor('revoke', ['view', 'update', 'publish']);
        await context.close();
    }
});

/* Not offered while the list shows only the trash, as Delete selected is not. */
test('does not offer the selection switches while the list shows only the trash', async ({ page }) => {
    const id = storedPng(`${PROBE} bulk trashed`, 'private');
    tinker(IN_GOLFDOM + ` \\Kitsune\\Core\\Models\\Entry::query()->findOrFail(${id})->delete();`);

    await listed(page, `${PROBE} bulk trashed`, 0);
    await showTrash(page, '0');
    await card(page, `${PROBE} bulk trashed`).getByRole('checkbox').check();
    await page.getByRole('button', { name: /bulk actions/i }).click();

    await expect(page.getByRole('button', { name: 'Restore selected', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Make selected public', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Make selected private', exact: true })).toHaveCount(0);
});
