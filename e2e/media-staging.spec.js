// @ts-check
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

/*
 * Livewire's upload staging, owned by core — ADR-042 decision 4, where Livewire actually runs.
 *
 * ⚠️ THE REAL ENDPOINT, WITH EVERYTHING IN FRONT OF IT. The PHP suite cannot see it: Livewire fakes its disk under unit
 * tests, skips CSRF there, and its own test helper goes round the middleware and the signature. So a signed upload URL
 * is minted here the way any page can mint one — by Filament's topbar, which is not Kitsune's to restrict — and files
 * are posted to it with the page's own session and CSRF token.
 *
 * ⚠️ BY STATUS AND BY MESSAGE, NEVER BY STATUS ALONE. A request refused by CSRF (419), the signature (401) or the
 * throttle (429) is also "refused", so each refusal names the rule that made it; and each "nothing was staged" is the
 * intake directory listed before and after, with a control that stages something.
 *
 * ⚠️ SERIAL, AND THE ONLY SPEC THAT STAGES, so the before-and-after listings see nobody else's uploads.
 */
test.describe.configure({ mode: 'serial' });

const READER_STATE = '.playwright/admin-reader-auth.json';

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

    if (! fs.existsSync(root)) {
        return [];
    }

    return fs.readdirSync(root, { recursive: true })
        .map(String)
        .filter((entry) => fs.statSync(path.join(root, entry)).isFile())
        .sort();
}

/** The smallest valid PNG, as bytes. */
const PNG = [137, 80, 78, 71, 13, 10, 26, 10, 0, 0, 0, 13, 73, 72, 68, 82, 0, 0, 0, 1, 0, 0, 0, 1, 8, 6, 0, 0, 0, 31,
    21, 196, 137, 0, 0, 0, 13, 73, 68, 65, 84, 120, 156, 99, 248, 15, 0, 0, 1, 1, 0, 5, 24, 216, 78, 0, 0, 0, 0, 73, 69,
    78, 68, 174, 66, 96, 130];

const bytesOf = (text) => Array.from(Buffer.from(text, 'utf8'));

/**
 * A signed upload URL, minted as any signed-in page can mint one: by asking a component that uses `WithFileUploads`
 * to start an upload. Filament's topbar does, on every panel page, and is not Kitsune's to restrict.
 */
async function mintUploadUrl(page) {
    await page.waitForFunction(() => window.Livewire && window.Livewire.all().length > 0);

    return page.evaluate(() => new Promise((resolve, reject) => {
        const topbar = window.Livewire.all().find((component) => component.name === 'Filament\\Livewire\\Topbar');
        topbar.$wire.$on('upload:generatedSignedUrl', ({ url }) => resolve(url));
        setTimeout(() => reject(new Error('no signed upload URL was minted')), 10_000);
        topbar.$wire.call('_startUpload', 'probe', [{ name: 'photo.png', size: 67, type: 'image/png' }], false);
    }));
}

/** POST files to the endpoint from this page, with its session and CSRF token, as Livewire's own client does. */
async function stage(page, url, files) {
    return page.evaluate(async ({ url, files }) => {
        const form = new FormData();

        for (const file of files) {
            form.append('files[]', new File([new Uint8Array(file.bytes)], file.name, { type: file.type }));
        }

        const token = window.livewireScriptConfig?.csrf ?? document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(url, {
            method: 'POST',
            body: form,
            headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
        });

        let body = null;

        try {
            body = await response.json();
        } catch {
            body = null;
        }

        return { status: response.status, body };
    }, { url, files });
}

const png = { bytes: PNG, name: 'photo.png', type: 'image/png' };

let gateRefusal;

test.beforeAll(() => {
    gateRefusal = tinker('echo Kitsune\\Core\\Http\\Middleware\\GuardUploadStaging::REFUSAL;');

    /*
     * ⚠️ EMPTIED FIRST, which review found the counts needed: every accepted upload sweeps files older than a day, so a
     * previous day's run left behind would disappear during this one and each "gained two" would read one short. What
     * is here is staged uploads by definition, and nothing a test run needs.
     */
    fs.rmSync(path.join(media().intakePath, 'livewire-tmp'), { recursive: true, force: true });
});

test.describe('who may stage a file', () => {
    /*
     * The endpoint is reachable from every panel page, and staging is bounded by the same trust as uploading: a
     * reader — who may view and edit articles and upload nothing — is refused before a byte is written.
     */
    test('refuses a signed-in user who may not upload media, and stages for one who may', async ({ page, browser }) => {
        const readerContext = await browser.newContext({ storageState: READER_STATE });
        const reader = await readerContext.newPage();

        try {
            await reader.goto('/admin/golfdom');
            const before = intake();
            const refused = await stage(reader, await mintUploadUrl(reader), [png]);

            expect(refused.status).toBe(403);
            expect(refused.body?.message).toBe(gateRefusal);
            expect(intake()).toEqual(before);
        } finally {
            await readerContext.close();
        }

        // The control: the owner, the same file, the same kind of URL — staged, sidecar and all.
        await page.goto('/admin/golfdom');
        const before = intake();
        const staged = await stage(page, await mintUploadUrl(page), [png]);

        expect(staged.status).toBe(200);
        expect(staged.body?.paths).toHaveLength(1);
        expect(intake().length).toBe(before.length + 2);
    });

    /** With a still-valid URL the owner minted, and a real session and CSRF token of its own — so not a 419. */
    test('refuses anyone not signed in, whatever URL they hold', async ({ page, browser }) => {
        await page.goto('/admin/golfdom');
        const url = await mintUploadUrl(page);

        const guestContext = await browser.newContext({ storageState: undefined });
        const guest = await guestContext.newPage();

        try {
            await guest.goto('/admin/login');
            await guest.waitForFunction(() => window.Livewire !== undefined);

            const before = intake();
            const refused = await stage(guest, url, [png]);

            expect(refused.status).toBe(403);
            expect(refused.body?.message).toBe(gateRefusal);
            expect(intake()).toEqual(before);
        } finally {
            await guestContext.close();
        }
    });
});

test.describe('what may be staged', () => {
    /*
     * The endpoint's rule is `MediaIntake`: a file it refuses is refused there, in its words, before Livewire writes
     * the file or the sidecar that would hold its name.
     */
    test('refuses what MediaIntake refuses before anything is staged, and stages what it accepts', async ({ page }) => {
        await page.goto('/admin/golfdom');

        const refusals = [
            [{ bytes: bytesOf('<?php echo 1;'), name: 'evil.php', type: 'image/png' }, 'Refusing [evil.php]: [php] is not an accepted file type.'],
            [{ bytes: bytesOf('just some text'), name: 'photo.png', type: 'image/png' }, 'Refusing [photo.png]: it is named .png but its contents are [text/plain].'],
        ];

        for (const [file, words] of refusals) {
            const before = intake();
            const refused = await stage(page, await mintUploadUrl(page), [file]);

            expect(refused.status, file.name).toBe(422);
            expect(refused.body?.errors?.['files.0']?.[0], file.name).toContain(words);
            expect(intake(), file.name).toEqual(before);
        }

        const before = intake();
        expect((await stage(page, await mintUploadUrl(page), [png])).status).toBe(200);
        expect(intake().length).toBe(before.length + 2);
    });

    /**
     * No scheduler runs here, and none needs to: the sweep follows every accepted upload. A refused one sweeps
     * nothing, which is the control that the accepted one is what swept.
     */
    test('sweeps a stale staged file on the next accepted upload, and not on a refused one', async ({ page }) => {
        const dir = path.join(media().intakePath, 'livewire-tmp');
        fs.mkdirSync(dir, { recursive: true });

        const dayAndAnHourAgo = new Date(Date.now() - 25 * 3600 * 1000);
        const anHourAgo = new Date(Date.now() - 3600 * 1000);

        for (const [name, when] of [['stale-e2e.png', dayAndAnHourAgo], ['stale-e2e.png.json', dayAndAnHourAgo], ['fresh-e2e.png', anHourAgo], ['fresh-e2e.png.json', anHourAgo]]) {
            fs.writeFileSync(path.join(dir, name), 'x');
            fs.utimesSync(path.join(dir, name), when, when);
        }

        try {
            await page.goto('/admin/golfdom');

            await stage(page, await mintUploadUrl(page), [{ bytes: bytesOf('<?php echo 1;'), name: 'evil.php', type: 'image/png' }]);
            expect(intake()).toContain(path.join('livewire-tmp', 'stale-e2e.png'));

            expect((await stage(page, await mintUploadUrl(page), [png])).status).toBe(200);

            expect(intake()).not.toContain(path.join('livewire-tmp', 'stale-e2e.png'));
            expect(intake()).not.toContain(path.join('livewire-tmp', 'stale-e2e.png.json'));
            expect(intake()).toContain(path.join('livewire-tmp', 'fresh-e2e.png'));
            expect(intake()).toContain(path.join('livewire-tmp', 'fresh-e2e.png.json'));
        } finally {
            for (const name of ['stale-e2e.png', 'stale-e2e.png.json', 'fresh-e2e.png', 'fresh-e2e.png.json']) {
                fs.rmSync(path.join(dir, name), { force: true });
            }
        }
    });
});

/**
 * Call a method on a Livewire component by name and report the status its update request answered with.
 *
 * ⚠️ THAT REQUEST, BY WHAT IT CARRIED — the dashboard's widgets poll, and review found a poll's 200 could otherwise
 * stand in for the call being tested.
 */
async function statusOfCall(page, componentName, method, params) {
    const [response] = await Promise.all([
        page.waitForResponse((r) => /\/livewire-[0-9a-f]+\/update/.test(r.url()) && r.request().method() === 'POST'
            && (r.request().postData() ?? '').includes(`"method":"${method}"`)),
        page.evaluate(({ componentName, method, params }) => {
            const component = window.Livewire.all().find((c) => c.name === componentName);
            component.$wire.call(method, ...params).catch(() => {});
        }, { componentName, method, params }),
    ]);

    return response.status();
}

const probeUpload = ['probe', [{ name: 'photo.png', size: 67, type: 'image/png' }], false];

test.describe('where an upload may start', () => {
    /*
     * ⚠️ EVERY KITSUNE COMPONENT ON EVERY KITSUNE PAGE, AND FILAMENT'S TOPBAR AS THE CONTROL. The restriction refuses an
     * upload to any property that is not a schema upload field, and no Kitsune page has one yet; the topbar is not
     * Kitsune's, still mints, and shows that the refusal is the restriction's rather than the endpoint's.
     */
    test('refuses an upload to anything but a schema upload field, on each of Kitsune\'s pages', async ({ page }) => {
        const ids = JSON.parse(tinker(
            "$site = DB::table('sites')->where('slug', 'golfdom')->first(['id', 'org_id']);"
            + " echo json_encode(['type' => DB::table('entry_types')->where('org_id', $site->org_id)->orderBy('id')->value('id'),"
            + " 'role' => DB::table('roles')->where('org_id', $site->org_id)->orderBy('id')->value('id'),"
            + " 'article' => DB::table('entries')->where('site_id', $site->id)->where('type_handle', 'article')->whereNull('deleted_at')->orderBy('id')->value('id')]);",
        ));

        const pages = [
            '/admin/golfdom',
            '/admin/golfdom/c/article',
            '/admin/golfdom/c/article/create',
            `/admin/golfdom/c/article/${ids.article}`,
            `/admin/golfdom/c/article/${ids.article}/edit`,
            `/admin/golfdom/c/article/${ids.article}/related`,
            '/admin/golfdom/entry-types',
            '/admin/golfdom/entry-types/create',
            `/admin/golfdom/entry-types/${ids.type}/edit`,
            '/admin/golfdom/roles',
            '/admin/golfdom/roles/create',
            `/admin/golfdom/roles/${ids.role}/edit`,
        ];

        const seen = new Set();

        for (const url of pages) {
            await page.goto(url);
            await page.waitForFunction(() => window.Livewire && window.Livewire.all().length > 0);

            const kitsune = await page.evaluate(() => window.Livewire.all().map((c) => c.name).filter((name) => name.startsWith('Kitsune\\')));
            expect(kitsune.length, url).toBeGreaterThan(0);

            for (const name of kitsune) {
                expect(await statusOfCall(page, name, '_startUpload', probeUpload), `${name} at ${url}`).toBe(403);
                seen.add(name);
            }

            await page.goto(url);
            await page.waitForFunction(() => window.Livewire && window.Livewire.all().length > 0);
            expect(await statusOfCall(page, 'Filament\\Livewire\\Topbar', '_startUpload', probeUpload), `the topbar at ${url}`).toBe(200);
        }

        // Every Livewire class Kitsune ships was reached by one of these pages.
        expect([...seen].sort()).toEqual([
            'Kitsune\\Core\\Filament\\Resources\\Entries\\Pages\\CreateEntry',
            'Kitsune\\Core\\Filament\\Resources\\Entries\\Pages\\EditEntry',
            'Kitsune\\Core\\Filament\\Resources\\Entries\\Pages\\ListEntries',
            'Kitsune\\Core\\Filament\\Resources\\Entries\\Pages\\ManageEntryRelations',
            'Kitsune\\Core\\Filament\\Resources\\Entries\\Pages\\ViewEntry',
            'Kitsune\\Core\\Filament\\Resources\\Entries\\RelationManagers\\RevisionsRelationManager',
            'Kitsune\\Core\\Filament\\Resources\\EntryTypes\\Pages\\CreateEntryType',
            'Kitsune\\Core\\Filament\\Resources\\EntryTypes\\Pages\\EditEntryType',
            'Kitsune\\Core\\Filament\\Resources\\EntryTypes\\Pages\\ListEntryTypes',
            'Kitsune\\Core\\Filament\\Resources\\EntryTypes\\RelationManagers\\FieldsRelationManager',
            'Kitsune\\Core\\Filament\\Resources\\Roles\\Pages\\CreateRole',
            'Kitsune\\Core\\Filament\\Resources\\Roles\\Pages\\EditRole',
            'Kitsune\\Core\\Filament\\Resources\\Roles\\Pages\\ListRoles',
            'Kitsune\\Core\\Filament\\Widgets\\EntryCountsWidget',
            'Kitsune\\Core\\Filament\\Widgets\\RecentEntriesWidget',
        ]);
    });

    /*
     * ⚠️ RICH TEXT HAS NO ATTACHMENTS, AND ITS ATTACH ACTION CANNOT BE USED AS A WAY IN. Turning attachments off leaves
     * Filament's action registered, with an upload field in its modal; mounted by a hand-built request — writing
     * `mountedActions` directly, which nothing locks — its schema is cached without asking whether it is hidden. Core
     * replaces it with a hidden action that holds nothing, so an upload to its field is refused like any other.
     */
    test('offers no attachment in rich text, and its attach action is no way in', async ({ page }) => {
        const { article } = JSON.parse(tinker(
            "echo json_encode(['article' => DB::table('entries')->where('site_id', DB::table('sites')->where('slug', 'golfdom')->value('id'))"
            + "->where('type_handle', 'article')->whereNull('deleted_at')->orderBy('id')->value('id')]);",
        ));
        const editPage = 'Kitsune\\Core\\Filament\\Resources\\Entries\\Pages\\EditEntry';

        await page.goto(`/admin/golfdom/c/article/${article}/edit`);
        const editor = page.locator('.fi-fo-rich-editor').first();

        // The toolbar is there — the control — and attaching is not.
        await expect(editor.getByRole('button', { name: 'Bold' })).toBeVisible();
        await expect(editor.getByRole('button', { name: 'Attach files' })).toHaveCount(0);
        expect(await page.locator('[x-data*="richEditorFormComponent"]').first().getAttribute('x-data')).toContain('canAttachFiles: false');

        // Mounted the ordinary way, the hidden action does not mount.
        await page.evaluate(async (name) => {
            const component = window.Livewire.all().find((c) => c.name === name);
            await component.$wire.mountAction('attachFiles', {}, { schemaComponent: 'form.values.body' }).catch(() => {});
        }, editPage);
        expect(await page.evaluate((name) => window.Livewire.all().find((c) => c.name === name).$wire.mountedActions.length, editPage)).toBe(0);

        // Written into `mountedActions` directly, it mounts — and has no field to upload to.
        await page.evaluate(async (name) => {
            const component = window.Livewire.all().find((c) => c.name === name);
            await component.$wire.set('mountedActions', [{ name: 'attachFiles', arguments: {}, context: { schemaComponent: 'form.values.body' } }]);
        }, editPage);

        expect(await statusOfCall(page, editPage, '_startUpload', ['mountedActions.0.data.file', probeUpload[1], false])).toBe(403);
    });
});
