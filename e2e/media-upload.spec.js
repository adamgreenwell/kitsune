// @ts-check
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

/*
 * The media list's Upload action, driven through FilePond as an editor drives it — ADR-042 decision 3.
 *
 * ⚠️ THE BROWSER, BECAUSE THIS IS WHERE THE UPLOAD LIVES. Under the PHP suite Livewire stages to a faked disk, with no
 * panel tenant and no HTTP request; the endpoint's rule, the staging gate, the schema restriction and Filament's own
 * handling of a mounted action only run here.
 *
 * ⚠️ EVERY FILE UPLOADED HERE IS REMOVED AGAIN, force-deleted with its org in context, because the suite shares one
 * database and the other media specs count what the image list holds.
 *
 * ⚠️ EVERY REFUSAL HAS A CONTROL, as in `media-sharing.spec.js`: a call that stores nothing is also what a call that
 * never carried a file does, so each refusal is made with a real staged file, or beside the same call succeeding.
 */

function media() {
    return JSON.parse(fs.readFileSync(path.join(__dirname, '..', '.playwright', 'media-fixture.json'), 'utf8'));
}

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        cwd: path.join(__dirname, '..', 'skeleton'),
        encoding: 'utf8',
    }).trim();
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

/** A 1×1 PNG, named as asked. */
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const png = (name) => ({ name, mimeType: 'image/png', buffer: PNG });

/**
 * Bytes from the PHP suite's own fixture (`tests/Core/Fixtures/LocatedJpeg.php`), which needs no autoloader — CI's browser
 * job installs the skeleton alone.
 */
function locatedJpeg(expression) {
    return Buffer.from(execFileSync('php', ['-r', `require 'tests/Core/Fixtures/LocatedJpeg.php'; echo base64_encode(\\Kitsune\\Core\\Tests\\Fixtures\\LocatedJpeg::${expression});`], {
        cwd: path.join(__dirname, '..'),
        encoding: 'utf8',
    }), 'base64');
}

const sha256 = (bytes) => crypto.createHash('sha256').update(bytes).digest('hex');

/** The media_files row and entry for an uploaded title, or null. */
function stored(title) {
    const row = tinker("$e = \\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('reading a browser-test upload', fn ($q) => $q->where('title', "
        + JSON.stringify(title) + ")->first()); $f = $e === null ? null : DB::table('media_files')->where('entry_id', $e->id)->first();"
        + " echo json_encode($e === null ? null : ['id' => $e->id, 'site_id' => $e->site_id, 'status' => $e->status,"
        + " 'visibility' => $f->visibility, 'disk' => $f->disk, 'path' => $f->path, 'checksum' => $f->checksum, 'size_bytes' => (int) $f->size_bytes]);");

    return JSON.parse(row);
}

/** Force-delete every entry with one of these titles, with its org in context: a force-delete is audited. */
function removeUploads(titles) {
    tinker("\\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing a browser-test upload', fn ($q) => $q->whereIn('title', "
        + JSON.stringify(titles) + ")->get())"
        + "->each(function ($entry) { app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($entry->org_id)); $entry->forceDelete(); });");
}

/** How many image entries exist in every org — what a refused or forged upload must leave unchanged. */
function imageCount() {
    return Number(tinker("echo DB::table('entries')->where('type_handle', 'image')->count();"));
}

/** What the media disks and rows hold — what a refused upload must leave exactly as it was. */
function mediaHeld() {
    return JSON.parse(tinker("echo json_encode(['rows' => DB::table('media_files')->count(), 'private' => count(Storage::disk(\\Kitsune\\Core\\Media\\MediaDisks::PRIVATE)->allFiles()),"
        + " 'public' => count(Storage::disk('public')->allFiles())]);"));
}

/**
 * Grant or revoke `entry.image.{action}` on the copy-editor's role, with its org in context — the reader's grants are
 * articles only, so each permission test gives the role exactly what it measures and takes it away again.
 */
function copyEditor(method, actions, type = 'image') {
    tinker("app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail(DB::table('sites')->where('slug', 'golfdom')->value('org_id')));"
        + " $role = \\Kitsune\\Core\\Models\\Role::query()->where('handle', 'copy-editor')->firstOrFail();"
        + ` foreach (${JSON.stringify(actions.map((action) => `entry.${type}.${action}`))} as $p) { if (${method === 'grant' ? '! ' : ''}$role->permissions()->where('permission', $p)->exists()) { $role->${method}($p); } }`);
}

/** A second media type in Golfdom's org, for a user who may upload there and not to `image`. */
const PROBE_TYPE = 'upload_probe';

function probeType(method) {
    const org = "app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail(DB::table('sites')->where('slug', 'golfdom')->value('org_id')));";

    tinker(method === 'create'
        ? `${org} \\Kitsune\\Core\\Models\\EntryType::create(['org_id' => app(\\Kitsune\\Core\\Tenancy\\Context::class)->orgId(), 'handle' => '${PROBE_TYPE}', 'name' => 'Upload probe', 'plural_name' => 'Upload probes', 'is_media' => true]);`
        : `${org} \\Kitsune\\Core\\Models\\EntryType::query()->where('handle', '${PROBE_TYPE}')->get()->each->delete();`);
}

/**
 * A Livewire call's HTTP status, by the response it produced — the endpoint's refusal is a 403, and a `$wire` promise
 * does not carry one. As `media-staging.spec.js` asks it.
 */
async function statusOfCall(page, componentName, method, params) {
    const [response] = await Promise.all([
        page.waitForResponse((r) => /\/livewire-[0-9a-f]+\/update/.test(r.url()) && r.request().method() === 'POST'
            && (r.request().postData() ?? '').includes(`"method":"${method}"`)),
        page.evaluate(({ componentName, method, params }) => {
            const component = /** @type {any} */ (window).Livewire.all().find((c) => c.name === componentName);
            component.$wire.call(method, ...params).catch(() => {});
        }, { componentName, method, params }),
    ]);

    return response.status();
}

/** Types into a searchable select and waits for the server's answer — as `media-sharing.spec.js` does, and for its reasons. */
async function search(page, label, term, within = page) {
    await within.getByRole('combobox', label === null ? {} : { name: label }).first().click();

    const answered = page.waitForResponse(
        (response) => response.url().includes('/livewire') && response.request().method() === 'POST',
    );

    await page.keyboard.type(term);
    await answered;
}

/** A string from the admin's own translations, in a site's language. */
function translated(key, locale) {
    return tinker(`echo __('${key}', [], '${locale}');`);
}

/** The options in the open picker's dropdown, not the Status select's native ones. */
const dropdownOptions = (page) => page.locator('[id^="fi-select-input-dropdown-"]:visible').getByRole('option');

/** Nothing offered, by the "no results" words in the site's language — never by an absence alone, which a pending search shows too. */
async function expectNoOptions(page, locale) {
    const none = translated('filament-forms::components.select.no_search_results_message', locale);

    await expect(page.locator('.fi-select-input-message:visible').first()).toHaveText(none, { timeout: 10_000 });
    await expect(dropdownOptions(page)).toHaveCount(0);
}

const LIST_PAGE = 'Kitsune\\Core\\Filament\\Resources\\Entries\\Pages\\ListEntries';

const READER_STATE = '.playwright/admin-reader-auth.json';

/**
 * The list's Upload button — by the action it mounts, since the modal's submit button carries the same name and stays in
 * the page while the modal closes.
 */
const uploadButton = (page) => page.locator('button[wire\\:click="mountAction(\'upload\')"]');

/**
 * Give the Upload field files, once FilePond has taken over its input.
 *
 * ⚠️ NOT BEFORE: the modal's input is there as soon as it opens, and FilePond wraps it a moment later. Files set on it
 * before then go to an input nothing listens to, and no item ever appears — which a slow run showed, on any test here.
 */
async function addFiles(dialog, files) {
    await expect(dialog.locator('.filepond--root')).toBeAttached();
    await dialog.locator('input[type=file]').setInputFiles(files);
}

/**
 * Open the Upload modal, add the files, wait until FilePond has staged every one, set the controls, and submit.
 *
 * ⚠️ BY FILEPOND'S OWN STATE, NOT A TIMEOUT: a file still processing is not in the form's state, and submitting then
 * stores fewer files than were added.
 */
async function upload(page, files, { visibility = null, confirm = false, siteOnly = false } = {}) {
    await uploadButton(page).click();

    const dialog = page.getByRole('dialog');
    await addFiles(dialog, files);
    await expect(dialog.locator('.filepond--item[data-filepond-item-state="processing-complete"]'))
        .toHaveCount(files.length, { timeout: 20_000 });

    if (visibility !== null) {
        await dialog.getByRole('radio', { name: visibility === 'public' ? /^Public/ : /^Private/ }).check();
    }

    if (confirm) {
        await dialog.getByLabel('Make these files public').check();
    }

    if (siteOnly) {
        await dialog.getByLabel('This site only').check();
    }

    await dialog.getByRole('button', { name: 'Upload', exact: true }).click();
}

test.describe('uploading through the media list', () => {
    // The staging specs leave files behind on purpose; each test here starts from an empty intake so "nothing staged" means it.
    test.beforeEach(() => {
        fs.rmSync(path.join(media().intakePath, 'livewire-tmp'), { recursive: true, force: true });
    });

    /*
     * ⚠️ A FILE REFUSED AT STAGING IS SHOWN ON THE FIELD, IN THE REFUSAL'S OWN WORDS, BEFORE UPLOAD IS PRESSED. The endpoint
     * answers a refusal in Livewire's shape — 422, keyed `files.N` — and Livewire raises it on the field. That is the path
     * decision 18 puts a full disk on: the gate answers a file the intake disk did not store the same way, which
     * `UploadStagingGateTest` drives through Livewire's own store on a disk that fails the write. A write that answers
     * false cannot be made here — the suite runs as root in places, which a read-only directory does not stop, and a
     * directory it cannot create throws instead of answering false — so the refusal made here is the endpoint rule's,
     * of a `.png` whose contents are text, which travels the same way.
     */
    test('shows a file refused at staging on the field, in its words, before Upload is pressed', async ({ page }) => {
        const title = 'upload-probe-refused';

        try {
            await page.goto('/admin/golfdom/c/image');
            await uploadButton(page).click();

            const dialog = page.getByRole('dialog');
            await addFiles(dialog, [{ name: `${title}.png`, mimeType: 'image/png', buffer: Buffer.from('just some text') }]);

            await expect(dialog.getByText(`Refusing [${title}.png]: it is named .png but its contents are [text/plain].`, { exact: false }))
                .toBeVisible({ timeout: 20_000 });
            await expect(dialog.locator('.filepond--item[data-filepond-item-state="processing-complete"]')).toHaveCount(0);
            expect(intake().filter((file) => file.endsWith('.png'))).toEqual([]);
        } finally {
            removeUploads([title]);
        }

        // The control: a real image, staged, and FilePond shows it complete with no refusal.
        await page.goto('/admin/golfdom/c/image');
        await uploadButton(page).click();
        await addFiles(page.getByRole('dialog'), [png(`${title}.png`)]);
        await expect(page.getByRole('dialog').locator('.filepond--item[data-filepond-item-state="processing-complete"]')).toHaveCount(1, { timeout: 20_000 });
        await expect(page.getByRole('dialog').getByText('its contents are')).toHaveCount(0);
    });

    /*
     * ⚠️ A FILE THE TYPE DOES NOT TAKE IS REFUSED ON THE FIELD, BEFORE ANYTHING IS STAGED — ADR-042 decision 33. `image`
     * takes images alone, the field names them, and the browser checks a file's name against them: a PDF never reaches
     * the endpoint. The library's own refusal, for a file past the browser, is `MediaUploadTest`'s.
     */
    test('refuses on the field a file the type does not take, before anything is staged', async ({ page }) => {
        const staged = [];
        page.on('request', (request) => {
            // Livewire's upload route carries a hash of the application: `/livewire-{hash}/upload-file`.
            if (/^\/livewire[^/]*\/upload-file$/.test(new URL(request.url()).pathname)) {
                staged.push(request.url());
            }
        });

        await page.goto('/admin/golfdom/c/image');
        await uploadButton(page).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText('Takes JPEG, PNG, GIF, WebP, AVIF and SVG files.')).toBeVisible();
        await addFiles(dialog, [{ name: 'upload-probe-rules.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF\n') }]);

        await expect(dialog.locator('.filepond--item[data-filepond-item-state="load-invalid"]')).toHaveCount(1, { timeout: 20_000 });
        await expect(dialog.locator('.filepond--item[data-filepond-item-state="processing-complete"]')).toHaveCount(0);
        expect(staged).toEqual([]);
        expect(intake()).toEqual([]);

        // The control: an image the type takes is staged, on the same field.
        await page.goto('/admin/golfdom/c/image');
        await uploadButton(page).click();
        await addFiles(page.getByRole('dialog'), [png('upload-probe-rules.png')]);
        await expect(page.getByRole('dialog').locator('.filepond--item[data-filepond-item-state="processing-complete"]')).toHaveCount(1, { timeout: 20_000 });
        expect(staged).toHaveLength(1);
    });

    test('offers Upload on a media list, and no create page, where an article list keeps both', async ({ page }) => {
        await page.goto('/admin/golfdom/c/image');
        await expect(page.getByRole('button', { name: 'Upload', exact: true })).toBeVisible();
        await expect(page.locator('a[href$="/c/image/create"]')).toHaveCount(0);
        expect((await page.goto('/admin/golfdom/c/image/create'))?.status()).toBe(404);

        // The control: a type that is not media keeps its create page and its link.
        await page.goto('/admin/golfdom/c/article');
        await expect(page.locator('a[href$="/c/article/create"]').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Upload', exact: true })).toHaveCount(0);
        expect((await page.goto('/admin/golfdom/c/article/create'))?.status()).toBe(200);
    });

    test('stores a file private and shared, lists it, and leaves nothing staged', async ({ page }) => {
        const title = 'upload-probe-private';

        try {
            await page.goto('/admin/golfdom/c/image');

            await upload(page, [png(`${title}.png`)]);

            await expect(page.locator('.fi-no-notification').filter({ hasText: 'The file was uploaded' })).toBeVisible({ timeout: 15_000 });
            await expect(page.locator('.fi-ta-record').filter({ hasText: title })).toBeVisible();

            const row = stored(title);
            expect(row).not.toBeNull();
            expect(row.visibility).toBe('private');
            expect(row.site_id).toBeNull();
            expect(row.status).toBe('published');

            expect(intake()).toEqual([]);
        } finally {
            removeUploads([title]);
        }
    });

    test('stores a file private when public is chosen without the confirmation, and says so', async ({ page }) => {
        const title = 'upload-probe-unconfirmed';

        try {
            await page.goto('/admin/golfdom/c/image');

            await upload(page, [png(`${title}.png`)], { visibility: 'public' });

            // Counted as uploaded — it was — and not as success, since it was not stored as asked.
            const notice = page.locator('.fi-no-notification')
                .filter({ hasText: 'The file was uploaded' })
                .filter({ hasText: `${title}.png — stored private: public was chosen but not confirmed` });
            await expect(notice).toBeVisible({ timeout: 15_000 });
            await expect(notice).toHaveClass(/\bfi-status-warning\b/);
            expect(stored(title)?.visibility).toBe('private');
            expect(intake()).toEqual([]);
        } finally {
            removeUploads([title]);
        }
    });

    test('stores a file public once public is confirmed, on the public disk', async ({ page }) => {
        const title = 'upload-probe-public';

        try {
            await page.goto('/admin/golfdom/c/image');

            await upload(page, [png(`${title}.png`)], { visibility: 'public', confirm: true });

            await expect(page.locator('.fi-no-notification').filter({ hasText: 'The file was uploaded' })).toBeVisible({ timeout: 15_000 });
            const row = stored(title);
            expect(row?.visibility).toBe('public');
            expect(row?.disk).toBe('public');

            /*
             * Its File section says so, and links to the public disk's direct URL rather than the panel's route — served,
             * with no PHP in the path. On the host serving the admin (decision 6): a path, with no scheme and no host, where
             * the absolute URL would have named APP_URL's (`http://localhost` here, not the suite's server).
             */
            await page.goto(`/admin/golfdom/c/image/${row.id}/edit`);
            const section = page.locator('.fi-section').filter({ hasText: 'File' }).first();
            await expect(section).toContainText('Public');
            const href = String(await section.getByRole('link', { name: 'Open file' }).getAttribute('href'));
            expect(href).toBe(`/storage/${row.path}`);
            const served = await page.request.get(href);
            expect(served.status()).toBe(200);
            expect(served.headers()['content-type']).toContain('image/png');
            expect(served.headers()['content-disposition']).toBeUndefined();
            await page.goto('/admin/golfdom/c/image');

            // Reopened, the modal is private again — no choice carries over — and the confirmation says both things.
            await expect(page.getByRole('dialog')).toBeHidden();
            await uploadButton(page).click();

            const dialog = page.getByRole('dialog');
            await expect(dialog.getByRole('radio', { name: /^Private/ })).toBeChecked();
            await expect(dialog.getByText(/served to anyone who has its link/)).toHaveCount(0);
            await dialog.getByRole('radio', { name: /^Public/ }).check();
            await expect(dialog.getByText(/served to anyone who has its link/)).toBeVisible();
            await expect(dialog.getByText(/loses the GPS coordinates in its EXIF and XMP data as it is made public/)).toBeVisible();
            await expect(dialog.getByText(/every other type of file are served as uploaded/)).toBeVisible();
        } finally {
            removeUploads([title]);
        }
    });

    /*
     * ⚠️ WHERE A PHOTO WAS MADE, GONE FROM WHAT THE WEB SERVES — Adam, ADR-042 decision 30. Asked of the web server, at
     * the file's public URL, and of the browser, which must still draw it turned as it was: Orientation 6 makes the 16×8
     * picture 8 wide and 16 tall. The control is the same photo uploaded private, served through the panel as uploaded.
     */
    test('serves a public JPEG without its GPS data, its picture and orientation as uploaded, beside a private one kept as it was', async ({ page }) => {
        const titles = ['upload-probe-located-public', 'upload-probe-located-private'];
        const photo = locatedJpeg('photo(true)');
        const body = locatedJpeg('body()');

        try {
            await page.goto('/admin/golfdom/c/image');
            await upload(page, [{ name: `${titles[0]}.jpg`, mimeType: 'image/jpeg', buffer: photo }], { visibility: 'public', confirm: true });
            await expect(page.locator('.fi-no-notification').filter({ hasText: 'The file was uploaded' })).toBeVisible({ timeout: 15_000 });

            const row = stored(titles[0]);
            const served = await page.request.get(`/storage/${row.path}`);
            expect(served.status()).toBe(200);
            expect(served.headers()['content-type']).toContain('image/jpeg');
            const bytes = await served.body();

            expect(bytes.length).toBe(photo.length);
            expect(row.size_bytes).toBe(bytes.length);
            expect(row.checksum).toBe(sha256(bytes));
            expect(row.checksum).not.toBe(sha256(photo));
            expect(bytes.includes('SENTINEL-')).toBe(false);
            expect(bytes.includes('GGGGPPPP')).toBe(false);
            for (const kept of ['TRAILER-KEPT', 'Kept City', 'PAYLOAD!', 'hdrgm:Version="1.0"']) {
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
            }, `/storage/${row.path}`);
            expect(drawn).toEqual([8, 16]);

            await page.goto('/admin/golfdom/c/image');
            await upload(page, [{ name: `${titles[1]}.jpg`, mimeType: 'image/jpeg', buffer: photo }], { visibility: 'private' });
            await expect(page.locator('.fi-no-notification').filter({ hasText: 'The file was uploaded' }).last()).toBeVisible({ timeout: 15_000 });

            const kept = stored(titles[1]);
            expect(kept.visibility).toBe('private');
            expect(kept.checksum).toBe(sha256(photo));
            const opened = await page.request.get(`/admin/golfdom/media/${kept.id}`);
            expect(opened.status()).toBe(200);
            expect((await opened.body()).equals(photo)).toBe(true);
        } finally {
            removeUploads(titles);
        }
    });

    test('stores several files, each with its own result', async ({ page }) => {
        const titles = ['upload-probe-first', 'upload-probe-second'];

        try {
            await page.goto('/admin/golfdom/c/image');

            await upload(page, titles.map((title) => png(`${title}.png`)));

            const notice = page.locator('.fi-no-notification').filter({ hasText: 'All 2 files were uploaded' });
            await expect(notice).toBeVisible({ timeout: 15_000 });
            await expect(notice).toContainText(`${titles[0]}.png — stored`);
            await expect(notice).toContainText(`${titles[1]}.png — stored`);

            for (const title of titles) {
                expect(stored(title)?.visibility).toBe('private');
            }

            expect(intake()).toEqual([]);
        } finally {
            removeUploads(titles);
        }
    });
});

test.describe('a file uploaded through the panel, across sites', () => {
    test.beforeEach(() => {
        fs.rmSync(path.join(media().intakePath, 'livewire-tmp'), { recursive: true, force: true });
    });

    /*
     * ADR-042 decision 2's browser half, with files uploaded here: a shared one travels across the org — listed, served,
     * offered and accepted by a save at a second site — and not where its type is switched off, nor to another org; one
     * kept to this site answers 404 from the second.
     */
    test('shares a file across the org unless it is kept to this site', async ({ page, browser }) => {
        const shared = 'upload-probe-shared';
        const kept = 'upload-probe-kept';
        const linked = 'Upload sharing probe';

        try {
            await page.goto('/admin/golfdom/c/image');
            await upload(page, [png(`${shared}.png`)]);
            await expect(page.locator('.fi-no-notification').filter({ hasText: 'The file was uploaded' })).toBeVisible({ timeout: 15_000 });
            await page.goto('/admin/golfdom/c/image');
            await upload(page, [png(`${kept}.png`)], { siteOnly: true });
            await expect(page.locator('.fi-no-notification').filter({ hasText: 'The file was uploaded' })).toBeVisible({ timeout: 15_000 });

            const sharedRow = stored(shared);
            const keptRow = stored(kept);
            expect(sharedRow?.site_id).toBeNull();
            expect(keptRow?.site_id).not.toBeNull();

            await page.goto('/admin/golfdom-fr/c/image');
            await expect(page.locator('.fi-ta-record').filter({ hasText: shared })).toBeVisible();
            await expect(page.getByText(kept)).toHaveCount(0);

            expect((await page.request.get(`/admin/golfdom-fr/media/${sharedRow.id}`)).status()).toBe(200);
            expect((await page.request.get(`/admin/golfdom-fr/media/${keptRow.id}`)).status()).toBe(404);
            // The control: the site it was kept to serves it.
            expect((await page.request.get(`/admin/golfdom/media/${keptRow.id}`)).status()).toBe(200);

            // Offered by a picker at the second site, and the one kept to the first is not — and the choice is saved.
            await page.goto('/admin/golfdom-fr/c/product/create');
            await page.locator('[id="form.title"]').fill(linked);
            await search(page, /Photo/, 'upload-probe');
            const offered = page.getByRole('option', { name: shared });
            await expect(offered).toBeVisible({ timeout: 10_000 });
            await expect(page.getByRole('option', { name: kept })).toHaveCount(0);
            await offered.click();
            // Submitted from the title field: the Create button is labelled in the site's language.
            await page.locator('[id="form.title"]').press('Enter');
            await page.waitForURL(/\/c\/product\/\d+$/);
            await page.goto(`${page.url()}/edit`);
            await expect(page.getByRole('combobox', { name: /Photo/ }).first()).toContainText(shared);

            // Neither served, offered by a picker, nor by the Attach dialog where its type is switched off.
            const { nestedNoteId, frNoteId } = media();
            expect((await page.request.get(`/admin/golfdom-nested/media/${sharedRow.id}`)).status()).toBe(404);
            await page.goto('/admin/golfdom-nested/c/product/create');
            await search(page, /Photo/, 'upload-probe');
            await expectNoOptions(page, 'he');

            await page.goto(`/admin/golfdom-nested/c/article/${nestedNoteId}/related`);
            await page.getByRole('button', { name: translated('filament-actions::attach.single.label', 'he'), exact: true }).first().click();
            await search(page, null, 'upload-probe', page.getByRole('dialog'));
            await expectNoOptions(page, 'he');

            // The control: the same dialog, the same search, at a site where the type is on.
            await page.goto(`/admin/golfdom-fr/c/article/${frNoteId}/related`);
            await page.getByRole('button', { name: translated('filament-actions::attach.single.label', 'fr'), exact: true }).first().click();
            await search(page, null, 'upload-probe', page.getByRole('dialog'));
            await expect(dropdownOptions(page).filter({ hasText: shared })).toBeVisible({ timeout: 10_000 });

            // Not to another org's site: refused, and not offered — beside the rival's own file, offered by the same picker.
            const rival = await browser.newContext({ storageState: '.playwright/admin-rival-auth.json' });
            const rivalPage = await rival.newPage();

            try {
                expect((await rivalPage.request.get(`/admin/rival-golfdom/media/${sharedRow.id}`)).status()).toBe(404);

                await rivalPage.goto('/admin/rival-golfdom/c/confidential/create');
                await search(rivalPage, /Cover/, 'upload-probe');
                await expectNoOptions(rivalPage, 'en');

                await rivalPage.goto('/admin/rival-golfdom/c/confidential/create');
                await search(rivalPage, /Cover/, 'Rival');
                await expect(rivalPage.getByRole('option', { name: 'Rival private asset' })).toBeVisible({ timeout: 10_000 });
            } finally {
                await rival.close();
            }
        } finally {
            removeUploads([shared, kept, linked]);
        }
    });
});

test.describe('a media entry\'s pages', () => {
    test('show what is stored, and no status, where an article keeps its status', async ({ page }) => {
        const title = 'upload-probe-facts';

        try {
            await page.goto('/admin/golfdom/c/image');
            await upload(page, [png(`${title}.png`)]);
            await expect(page.locator('.fi-no-notification').filter({ hasText: 'The file was uploaded' })).toBeVisible({ timeout: 15_000 });

            // The list shows no status for a media type — beside its tiles, so the grid is seen to have rendered, and with
            // no Status among what it sorts by, the one place a grid would offer the column.
            await page.goto('/admin/golfdom/c/image');
            await expect(page.locator('[data-kitsune-tile]').first()).toBeVisible();
            await expect(page.locator('.fi-ta').getByRole('button', { name: 'Status', exact: true })).toHaveCount(0);
            const sortable = await page.locator('.fi-ta select[x-model="column"] option').evaluateAll((options) => options.map((o) => o.value));
            expect(sortable).toContain('title');
            expect(sortable).not.toContain('status');

            const { id } = stored(title);

            for (const suffix of ['/edit', '']) {
                await page.goto(`/admin/golfdom/c/image/${id}${suffix}`);
                const section = page.locator('.fi-section').filter({ hasText: 'File' }).first();

                await expect(section).toContainText('image/png');
                await expect(section).toContainText('Private');
                await expect(section).toContainText('Every site in the organisation');
                await expect(section.getByRole('link', { name: 'Open file' })).toHaveAttribute('href', new RegExp(`/admin/golfdom/media/${id}$`));
                await expect(page.locator('[id="form.status"]')).toHaveCount(0);
            }

            // The control: an article's edit page and list keep their status.
            await page.goto('/admin/golfdom/c/article');
            await expect(page.locator('.fi-ta').getByRole('button', { name: 'Status', exact: true })).toHaveCount(1);
            await expect(page.locator('[data-kitsune-tile]')).toHaveCount(0);
            await page.locator('.fi-ta-row a[href*="/edit"]').first().click();
            await expect(page.locator('[id="form.status"]')).toBeVisible();
        } finally {
            removeUploads([title]);
        }
    });
});

test.describe('who may upload', () => {
    test.beforeEach(() => {
        fs.rmSync(path.join(media().intakePath, 'livewire-tmp'), { recursive: true, force: true });
    });

    test.afterEach(() => {
        copyEditor('revoke', ['view', 'create', 'publish']);
    });

    /*
     * ADR-033's line, on a user who is not an owner: without `create` nothing on the page is theirs to do, so Upload is
     * not there; with `create` and not `publish` it is there, disabled, naming the permission it lacks.
     */
    test('hides Upload without create, and disables it without publish, naming the permission', async ({ browser }) => {
        const context = await browser.newContext({ storageState: READER_STATE });
        const page = await context.newPage();

        try {
            copyEditor('grant', ['view']);
            await page.goto('/admin/golfdom/c/image');
            await expect(page.locator('.fi-ta')).toBeVisible();
            await expect(page.getByRole('button', { name: 'Upload', exact: true })).toHaveCount(0);

            copyEditor('grant', ['create']);
            await page.goto('/admin/golfdom/c/image');
            const button = page.getByRole('button', { name: 'Upload', exact: true });
            await expect(button).toBeVisible();
            await expect(button).toBeDisabled();
            await button.hover({ force: true });
            await expect(page.getByText(/entry\.image\.publish/)).toBeVisible();

            // The control: with publish too, it is enabled.
            copyEditor('grant', ['publish']);
            await page.goto('/admin/golfdom/c/image');
            await expect(page.getByRole('button', { name: 'Upload', exact: true })).toBeEnabled();
        } finally {
            await context.close();
        }
    });

    /* ADR-033: the list's create page does not exist for a media type, for a reader too — missing, not forbidden. */
    test('answers 404 for a media type\'s create page to a reader who may not create, and 403 for another type\'s', async ({ browser }) => {
        const context = await browser.newContext({ storageState: READER_STATE });
        const page = await context.newPage();

        try {
            copyEditor('grant', ['view']);

            expect((await page.goto('/admin/golfdom/c/image/create'))?.status()).toBe(404);
            // The control: the same reader, a type they may view and not create — refused by the permission.
            expect((await page.goto('/admin/golfdom/c/article/create'))?.status()).toBe(403);
        } finally {
            await context.close();
        }
    });

    /*
     * The positive control for the refusals below: this reader, given `create` and `publish`, uploads — so the gate
     * admits them, and a file refused afterwards is refused by what the test changed.
     */
    test('stores a file for a reader given create and publish', async ({ browser }) => {
        const context = await browser.newContext({ storageState: READER_STATE });
        const page = await context.newPage();
        const title = 'upload-probe-reader';

        try {
            copyEditor('grant', ['view', 'create', 'publish']);
            await page.goto('/admin/golfdom/c/image');
            await upload(page, [png(`${title}.png`)]);

            await expect(page.locator('.fi-no-notification').filter({ hasText: 'The file was uploaded' })).toBeVisible({ timeout: 15_000 });
            expect(stored(title)?.visibility).toBe('private');
            expect(intake()).toEqual([]);
        } finally {
            removeUploads([title]);
            await context.close();
        }
    });

    /*
     * ⚠️ A FILE REALLY STAGED, AND THE PERMISSION TAKEN AWAY BEFORE THE SUBMIT: the call then carries a staged file.
     * "Stored nothing" alone does not say who refused — without `publish`, the model's own guard refuses a published
     * entry too, so a handler reached with every Upload guard gone still stores nothing. What only a refusal BEFORE the
     * handler leaves is the staged file itself, untouched, and no result: the handler would have removed the file and
     * said what became of it. That staged file is left to the intake sweep (decision 3's defaults) and is cleared here.
     */
    for (const revoked of ['publish', 'create']) {
        test(`stores nothing from a staged upload once ${revoked} is taken away`, async ({ browser }) => {
            const context = await browser.newContext({ storageState: READER_STATE });
            const page = await context.newPage();
            const title = `upload-probe-no-${revoked}`;

            try {
                copyEditor('grant', ['view', 'create', 'publish']);
                await page.goto('/admin/golfdom/c/image');
                await uploadButton(page).click();

                const dialog = page.getByRole('dialog');
                await addFiles(dialog, [png(`${title}.png`)]);
                await expect(dialog.locator('.filepond--item[data-filepond-item-state="processing-complete"]')).toHaveCount(1, { timeout: 20_000 });
                const staged = intake();
                expect(staged.length).toBeGreaterThan(0);

                const images = imageCount();
                const held = mediaHeld();
                copyEditor('revoke', [revoked]);

                expect(await statusOfCall(page, LIST_PAGE, 'callMountedAction', [])).toBe(200);

                expect(stored(title)).toBeNull();
                expect(imageCount()).toBe(images);
                expect(mediaHeld()).toEqual(held);
                expect(intake()).toEqual(staged);
                await expect(page.locator('.fi-no-notification')).toHaveCount(0);
            } finally {
                removeUploads([title]);
                fs.rmSync(path.join(media().intakePath, 'livewire-tmp'), { recursive: true, force: true });
                await context.close();
            }
        });
    }

    /*
     * ⚠️ THE SCHEMA GUARD, WHICH ONLY A HAND-BUILT MOUNT REACHES. Written into `mountedActions` directly, the Upload
     * action mounts whether or not it is hidden or disabled — Filament caches its schema without asking — so it must
     * hold no upload field unless the user may upload to this type. The reader may upload to a second media type, so
     * the staging gate admits them: the refusal here is the schema's, and the same call on that type's list is the
     * control.
     */
    test('offers no upload field to a hand-built mount, to a reader who may upload elsewhere', async ({ browser }) => {
        const context = await browser.newContext({ storageState: READER_STATE });
        const page = await context.newPage();
        const probe = ['mountedActions.0.data.files', [{ name: 'photo.png', size: 67, type: 'image/png' }], true];

        try {
            probeType('create');
            copyEditor('grant', ['view', 'create', 'publish'], PROBE_TYPE);

            for (const grants of [['view'], ['view', 'create']]) {
                copyEditor('revoke', ['view', 'create', 'publish']);
                copyEditor('grant', grants);

                await page.goto('/admin/golfdom/c/image');
                await expect(page.locator('.fi-ta')).toBeVisible();
                await page.evaluate(async (name) => {
                    const list = /** @type {any} */ (window).Livewire.all().find((c) => c.name === name);
                    await list.$wire.set('mountedActions', [{ name: 'upload', arguments: {}, context: {} }]);
                }, LIST_PAGE);

                expect(await statusOfCall(page, LIST_PAGE, '_startUpload', probe), grants.join('+')).toBe(403);
            }

            // The control: on the type the reader may upload to, the same call mints.
            await page.goto(`/admin/golfdom/c/${PROBE_TYPE}`);
            await uploadButton(page).click();
            await expect(page.getByRole('dialog').locator('input[type=file]')).toBeAttached();

            expect(await statusOfCall(page, LIST_PAGE, '_startUpload', probe)).toBe(200);
        } finally {
            copyEditor('revoke', ['view', 'create', 'publish'], PROBE_TYPE);
            probeType('delete');
            fs.rmSync(path.join(media().intakePath, 'livewire-tmp'), { recursive: true, force: true });
            await context.close();
        }
    });
});
