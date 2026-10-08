// @ts-check
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const { menuItem } = require('./menu');

/*
 * The DAM blueprint, as `kitsune:blueprint apply dam` left it — Phase 5, ADR-039, the DAM as built: AGENTS.md §9
 * coverage, outside `/c/{type}`, of what the command wrote.
 *
 * ⚠️ IN BLOG'S ORG, AFTER BLOG AND THE MARKETING SITE — where a library will usually be: added to a site that exists.
 * `e2e/global-setup.js` applies it into `inkwell` with its own command, no `--owner`, and gives the seeder's asset
 * contributor and viewer their roles through the audited path. The DAM's own one command on an empty installation is
 * proven in the PHP suite (`BlueprintCommandOwnerTest`).
 *
 * ⚠️ THE FIRST TYPE A BLUEPRINT DECLARED AS MEDIA, so what is asserted here is what no other blueprint could reach: the
 * flag on the type's page, the Upload action and FilePond, the private download, and the staging endpoint's gate —
 * each as the role the blueprint created, and each refusal beside the same thing succeeding for someone it allows.
 *
 * ⚠️ EVERY FILE UPLOADED HERE IS REMOVED AGAIN, force-deleted with its org in context as `media-upload.spec.js` does,
 * because the suite shares one database and a later run's reverse would be refused by a file left behind.
 */
test.describe.configure({ mode: 'serial' });

const SITE = 'inkwell';
const OWNER = '.playwright/blog-owner-auth.json';
const CONTRIBUTOR = '.playwright/dam-contributor-auth.json';
const VIEWER = '.playwright/dam-viewer-auth.json';
const BLOG_WRITER = '.playwright/blog-writer-auth.json';
const GOLFDOM_OWNER = '.playwright/admin-auth.json';

/* Made per run, so a re-run against the same database meets its own rows and not the last run's. */
const RUN = Date.now();
const PHOTO = `dam-photo-${RUN}`;
const TERMS = `dam-terms-${RUN}`;

/** A 1×1 PNG, and the smallest PDF `finfo` names as one. */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        cwd: path.join(__dirname, '..', 'skeleton'),
        encoding: 'utf8',
    }).trim();
}

function media() {
    return JSON.parse(fs.readFileSync(path.join(__dirname, '..', '.playwright', 'media-fixture.json'), 'utf8'));
}

/** Every file under the intake disk, relative and sorted — what the server has staged. */
function intake() {
    const root = media().intakePath;
    const found = [];
    const walk = (dir) => {
        if (! fs.existsSync(dir)) {
            return;
        }

        for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
            const full = path.join(dir, entry.name);
            entry.isDirectory() ? walk(full) : found.push(path.relative(root, full));
        }
    };

    walk(root);

    return found.sort();
}

/** The entry an upload of this title made, with its file's row, or null. */
function stored(title) {
    return JSON.parse(tinker("$e = \\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('reading a browser-test upload', fn ($q) => $q->where('title', "
        + JSON.stringify(title) + ")->first()); $f = $e === null ? null : DB::table('media_files')->where('entry_id', $e->id)->first();"
        + " echo json_encode($e === null ? null : ['id' => $e->id, 'type' => $e->type_handle, 'site_id' => $e->site_id, 'status' => $e->status,"
        + " 'visibility' => $f->visibility, 'mime' => $f->mime]);"));
}

/** Force-delete every entry with one of these titles, with its org in context: a force-delete is audited. */
function removeUploads(titles) {
    tinker("\\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing a browser-test upload', fn ($q) => $q->whereIn('title', "
        + JSON.stringify(titles) + ")->get())"
        + "->each(function ($entry) { app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($entry->org_id)); $entry->forceDelete(); });");
}

/** The form section whose heading is `heading`, located through the heading rather than by text. */
function sectionFor(page, heading) {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: heading, exact: true }) }).first();
}

/** The media list's Upload button — `media-upload.spec.js` says why it is found by its `wire:click`. */
const uploadButton = (page) => page.locator('button[wire\\:click="mountAction(\'upload\')"]');

/**
 * Open the Upload modal, add the files, wait until FilePond has staged every one, and submit — `upload()` in
 * `media-upload.spec.js`, less the controls this spec leaves at their defaults, which is the point: private, shared.
 */
async function upload(page, files) {
    await uploadButton(page).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.locator('.filepond--root')).toBeAttached();
    await dialog.locator('input[type=file]').setInputFiles(files);
    await expect(dialog.locator('.filepond--item[data-filepond-item-state="processing-complete"]'))
        .toHaveCount(files.length, { timeout: 20_000 });

    await dialog.getByRole('button', { name: 'Upload', exact: true }).click();
    await expect(dialog).toBeHidden({ timeout: 20_000 });
}

/** A signed upload URL, minted by Filament's topbar as any panel page can — `media-staging.spec.js` says why. */
async function mintUploadUrl(page) {
    await page.waitForFunction(() => window.Livewire && window.Livewire.all().length > 0);

    return page.evaluate(() => new Promise((resolve, reject) => {
        const topbar = window.Livewire.all().find((component) => component.name === 'Filament\\Livewire\\Topbar');
        topbar.$wire.$on('upload:generatedSignedUrl', ({ url }) => resolve(url));
        setTimeout(() => reject(new Error('no signed upload URL was minted')), 10_000);
        topbar.$wire.call('_startUpload', 'probe', [{ name: 'photo.png', size: 67, type: 'image/png' }], false);
    }));
}

/** POST a file to the staging endpoint from this page, with its session and CSRF token, as Livewire's client does. */
async function stage(page, url) {
    return page.evaluate(async ({ url, bytes }) => {
        const form = new FormData();
        form.append('files[]', new File([new Uint8Array(bytes)], 'photo.png', { type: 'image/png' }));

        const token = window.livewireScriptConfig?.csrf ?? document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(url, { method: 'POST', body: form, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } });

        let body = null;

        try {
            body = await response.json();
        } catch {
            body = null;
        }

        return { status: response.status, body };
    }, { url, bytes: [...PNG] });
}

test.afterAll(() => removeUploads([PHOTO, TERMS]));

test('gives the owner a dashboard that lists Assets after Tags, beside the seeded Images', async ({ browser }) => {
    const owner = await browser.newContext({ storageState: OWNER });
    const page = await owner.newPage();

    const response = await page.goto(`/admin/${SITE}`);
    expect(response?.status()).toBe(200);
    await expect(page.locator('body')).not.toContainText('Internal Server Error');

    const sidebar = page.locator('.fi-sidebar');
    await expect(sidebar.getByRole('link', { name: 'Assets', exact: true })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/asset$`));
    // ⚠️ The seeded global `image` is still there: the DAM declares its own type, and grants nothing on that one.
    await expect(sidebar.getByRole('link', { name: 'Images', exact: true })).toBeVisible();

    // Ordering 20 puts Assets after the content that uses them — Pages (5), Posts (10), Tags (11).
    const names = (await sidebar.getByRole('link').allInnerTexts()).map((text) => text.trim());
    expect(names.indexOf('Tags')).toBeGreaterThan(-1);
    expect(names.indexOf('Assets')).toBeGreaterThan(names.indexOf('Tags'));

    const stats = page.locator('a.fi-wi-stats-overview-stat');
    await expect(stats.filter({ hasText: 'Assets' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/asset$`));

    await owner.close();
});

test('lists the DAM\'s roles with exactly their grants', async ({ browser }) => {
    const owner = await browser.newContext({ storageState: OWNER });
    const page = await owner.newPage();

    const expected = {
        'Asset manager': ['View', 'Create', 'Update', 'Delete', 'Publish'],
        'Asset contributor': ['View', 'Create', 'Update', 'Publish'],
        'Asset viewer': ['View'],
    };

    for (const [role, granted] of Object.entries(expected)) {
        await page.goto(`/admin/${SITE}/roles`);
        await page.getByRole('link', { name: role, exact: true }).first().click();
        await page.waitForURL(/\/roles\/\d+\/edit$/);

        await page.getByRole('heading', { name: 'Assets', exact: true }).click();
        const assets = sectionFor(page, 'Assets');

        for (const action of ['View', 'Create', 'Update', 'Delete', 'Publish']) {
            const box = assets.getByRole('checkbox', { name: action, exact: true });
            granted.includes(action) ? await expect(box, `${role} ${action}`).toBeChecked() : await expect(box, `${role} ${action}`).not.toBeChecked();
        }

        // ⚠️ NOT THE WILDCARD, AND NOTHING ON THE SEEDED IMAGES: a blueprint grants only on what it declares.
        for (const heading of ['Images', 'Every entry type, including ones added later']) {
            if (heading !== 'Every entry type, including ones added later') {
                await page.getByRole('heading', { name: heading, exact: true }).click();
            }

            const section = sectionFor(page, heading);
            await expect(section.getByRole('checkbox')).toHaveCount(5);
            for (const box of await section.getByRole('checkbox').all()) {
                await expect(box).not.toBeChecked();
            }
        }
    }

    await owner.close();
});

test('shows the owner the asset type as a media type taking every format, with its fields and a missing subject', async ({ browser }) => {
    const owner = await browser.newContext({ storageState: OWNER });
    const page = await owner.newPage();

    const response = await page.goto(`/admin/${SITE}/entry-types`);
    expect(response?.status()).toBe(200);

    // ⚠️ Rights holder is `personal` and nominated by nobody yet, so the list says the subject is missing (ADR-020) —
    // and says nothing of the kind for a type that holds no personal data.
    const row = (name) => page.locator('.fi-ta-row').filter({ has: page.getByRole('link', { name, exact: true }) });
    await expect(row('Asset').locator('.fi-color-warning')).toHaveCount(1);
    await expect(row('Page').locator('.fi-color-warning')).toHaveCount(0);

    await page.goto(String(await page.getByRole('link', { name: 'Asset', exact: true }).first().getAttribute('href')));

    // Decided when it was created, and locked after — by the blueprint, here, as by the admin's toggle.
    const toggle = page.getByRole('switch', { name: 'Holds media' });
    await expect(toggle).toBeDisabled();
    await expect(toggle).toHaveAttribute('aria-checked', 'true');

    for (const format of ['JPEG', 'PNG', 'GIF', 'WebP', 'AVIF', 'SVG', 'PDF', 'MP4', 'WebM', 'MP3', 'TXT', 'CSV']) {
        await expect(page.getByRole('checkbox', { name: format, exact: true }), format).toBeChecked();
    }

    // The field list is a lazy Livewire component below the fold; a user scrolls, and so does this.
    await page.getByRole('button', { name: 'Save changes' }).scrollIntoViewIfNeeded();
    await page.mouse.wheel(0, 1200);

    for (const label of ['Rights holder', 'Licence', 'Licence expires']) {
        await expect(page.getByRole('cell', { name: label, exact: true }).first()).toBeVisible({ timeout: 10_000 });
    }

    await owner.close();
});

test('lets the contributor upload a picture and a document and describe them, and delete neither', async ({ browser }) => {
    const contributor = await browser.newContext({ storageState: CONTRIBUTOR });
    const page = await contributor.newPage();

    const list = await page.goto(`/admin/${SITE}/c/asset`);
    expect(list?.status()).toBe(200);
    await expect(uploadButton(page)).toBeEnabled();

    await upload(page, [
        { name: `${PHOTO}.png`, mimeType: 'image/png', buffer: PNG },
        { name: `${TERMS}.pdf`, mimeType: 'application/pdf', buffer: PDF },
    ]);

    for (const title of [PHOTO, TERMS]) {
        await expect(page.locator('.fi-ta-record, .fi-ta-row').filter({ hasText: title })).toHaveCount(1, { timeout: 15_000 });
    }

    // Every format, each one published, shared with the org's sites and private until someone makes it public.
    const photo = stored(PHOTO);
    expect(photo).toMatchObject({ type: 'asset', site_id: null, status: 'published', visibility: 'private', mime: 'image/png' });
    expect(stored(TERMS)).toMatchObject({ type: 'asset', site_id: null, status: 'published', visibility: 'private', mime: 'application/pdf' });

    /*
     * ⚠️ NOT IN THE SELECTION'S ACTIONS: a contributor may make a selection public or private, and remove none of it. A
     * live card offers nobody a removal of its own — Restore and Delete forever are a trashed row's, and Delete is the
     * page's — so the list's one place to ask is here, with the owner below as the control.
     */
    await page.locator('.fi-ta-record').filter({ hasText: PHOTO }).getByRole('checkbox').check();
    await page.getByRole('button', { name: /bulk actions/i }).click();
    await expect(menuItem(page, 'Make selected public')).toBeVisible();
    for (const label of ['Delete selected', 'Restore selected', 'Delete selected forever']) {
        await expect(menuItem(page, label), label).toHaveCount(0);
    }

    const edit = await page.goto(`/admin/${SITE}/c/asset/${photo.id}/edit`);
    expect(edit?.status()).toBe(200);
    await expect(page.getByRole('button', { name: /^(Delete|Delete forever|Restore)$/ })).toHaveCount(0);

    await page.getByRole('textbox', { name: 'Rights holder', exact: true }).fill('Jo Lens Photography');
    await page.getByRole('textbox', { name: 'Licence', exact: true }).fill('Editorial use only, not in print.');
    await page.getByRole('textbox', { name: 'Licence expires', exact: true }).fill('2027-12-31');
    await page.getByRole('button', { name: /^Save changes$/ }).click();
    await expect(page.locator('.fi-no-notification').filter({ hasText: /Saved/ }).first()).toBeVisible({ timeout: 15_000 });

    await page.reload();
    await expect(page.getByRole('textbox', { name: 'Rights holder', exact: true })).toHaveValue('Jo Lens Photography');
    await expect(page.getByRole('textbox', { name: 'Licence', exact: true })).toHaveValue('Editorial use only, not in print.');
    await expect(page.getByRole('textbox', { name: 'Licence expires', exact: true })).toHaveValue('2027-12-31');

    // A media type is uploaded into, never written: there is no create page to reach.
    const create = await page.goto(`/admin/${SITE}/c/asset/create`);
    expect(create?.status()).toBe(404);

    await contributor.close();

    // The control for "deletes nothing": the owner, on the same list and the same page, is offered both.
    const owner = await browser.newContext({ storageState: OWNER });
    const ownerPage = await owner.newPage();
    await ownerPage.goto(`/admin/${SITE}/c/asset`);
    await ownerPage.locator('.fi-ta-record').filter({ hasText: PHOTO }).getByRole('checkbox').check();
    await ownerPage.getByRole('button', { name: /bulk actions/i }).click();
    await expect(menuItem(ownerPage, 'Delete selected')).toBeVisible();
    await ownerPage.goto(`/admin/${SITE}/c/asset/${photo.id}/edit`);
    await expect(ownerPage.getByRole('button', { name: 'Delete', exact: true }).first()).toBeVisible();
    await owner.close();
});

test('lets the viewer open and download a private file, and upload nothing — refused at staging before a byte is written', async ({ browser }) => {
    const photo = stored(PHOTO);
    expect(photo?.visibility).toBe('private');

    const viewer = await browser.newContext({ storageState: VIEWER });
    const page = await viewer.newPage();

    const list = await page.goto(`/admin/${SITE}/c/asset`);
    expect(list?.status()).toBe(200);
    await expect(page.locator('.fi-ta-record, .fi-ta-row').filter({ hasText: PHOTO })).toHaveCount(1);
    await expect(uploadButton(page)).toHaveCount(0);

    const view = await page.goto(`/admin/${SITE}/c/asset/${photo.id}`);
    expect(view?.status()).toBe(200);

    // ⚠️ Private means behind sign-in and `view`, not managers only.
    const download = await page.request.get(`/admin/${SITE}/media/${photo.id}`);
    expect(download.status()).toBe(200);
    expect(Buffer.from(await download.body()).equals(PNG)).toBe(true);

    const gateRefusal = tinker('echo Kitsune\\Core\\Http\\Middleware\\GuardUploadStaging::REFUSAL;');

    await page.goto(`/admin/${SITE}`);
    const before = intake();
    const refused = await stage(page, await mintUploadUrl(page));

    expect(refused.status).toBe(403);
    expect(refused.body?.message).toBe(gateRefusal);
    expect(intake()).toEqual(before);

    await viewer.close();

    // The control: the contributor, the same file, the same kind of URL — staged, sidecar and all.
    const contributor = await browser.newContext({ storageState: CONTRIBUTOR });
    const contributorPage = await contributor.newPage();
    await contributorPage.goto(`/admin/${SITE}`);
    const beforeContributor = intake();
    const staged = await stage(contributorPage, await mintUploadUrl(contributorPage));

    expect(staged.status).toBe(200);
    expect(staged.body?.paths).toHaveLength(1);
    expect(intake().length).toBe(beforeContributor.length + 2);

    await contributor.close();
});

test('keeps the contributor to assets and their own site, and the Blog writer out of assets', async ({ browser }) => {
    const contributor = await browser.newContext({ storageState: CONTRIBUTOR });
    const page = await contributor.newPage();

    for (const url of [`/admin/${SITE}/c/post`, `/admin/${SITE}/c/page`, `/admin/${SITE}/roles`, `/admin/${SITE}/entry-types`]) {
        const refused = await page.goto(url);
        expect(refused?.status(), url).toBe(403);
    }

    // ⚠️ A SITE IN THEIR OWN ORG THAT THEY ARE NOT ATTACHED TO is a tenancy boundary, not a permission one: 404.
    const otherSite = await page.goto('/admin/inkwell-fr');
    expect(otherSite?.status()).toBe(404);

    await contributor.close();

    // And the other way: a Blog writer holds nothing on assets until an owner gives them Asset viewer too.
    const writer = await browser.newContext({ storageState: BLOG_WRITER });
    const writerPage = await writer.newPage();

    const assets = await writerPage.goto(`/admin/${SITE}/c/asset`);
    expect(assets?.status()).toBe(403);

    await writer.close();
});

test('keeps each org out of the other\'s library, from the side of the one crossing', async ({ browser }) => {
    const golfdom = await browser.newContext({ storageState: GOLFDOM_OWNER });
    const intruder = await golfdom.newPage();

    // Golfdom's owner holds every grant in Golfdom, and none of it reaches Inkwell's assets — the list or a file.
    const intoInkwell = await intruder.goto(`/admin/${SITE}/c/asset`);
    expect(intoInkwell?.status()).toBe(404);

    const file = await intruder.request.get(`/admin/${SITE}/media/${stored(PHOTO).id}`);
    expect(file.status()).toBe(404);

    await golfdom.close();

    const contributor = await browser.newContext({ storageState: CONTRIBUTOR });
    const page = await contributor.newPage();

    const intoGolfdom = await page.goto('/admin/golfdom/c/article');
    expect(intoGolfdom?.status()).toBe(404);

    await contributor.close();
});
