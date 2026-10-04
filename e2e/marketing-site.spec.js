// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * The Marketing Site blueprint, as `kitsune:blueprint apply marketing-site` left it — Phase 5, ADR-039, and the one
 * ADR-030 moves kitsunecms.org onto: AGENTS.md §9 coverage, outside `/c/{type}`, of what the command wrote.
 *
 * ⚠️ IN BLOG'S ORG, AFTER BLOG — kitsunecms.org's likely shape. `e2e/global-setup.js` creates `inkwell` and its owner
 * with Blog's command, then applies the Marketing Site into it with its own, no `--owner` (the org exists), and gives
 * the seeder's marketing writer `marketing_writer` through the audited path. The Marketing Site's own one command on
 * an empty installation is proven in the PHP suite, so Blog's browser evidence stays as ADR-039 records it.
 *
 * ⚠️ ADR-030's FOURTH CONDITION, ON EVERY BUILD: "editable by its operator through the admin — content changes without
 * a deploy". The owner writes a page, publishes it and changes it; the writer edits and publishes nothing; the other
 * site and the other org are refused from the side of the person crossing; and what 1.1.0 leaves out reaches the org
 * through the admin, because its owner can add a field to `page` there.
 */

const SITE = 'inkwell';
const OWNER = '.playwright/blog-owner-auth.json';
const WRITER = '.playwright/marketing-writer-auth.json';
const GOLFDOM_OWNER = '.playwright/admin-auth.json';

/* Made per run, so a re-run against the same database meets its own rows and not the last run's. */
const RUN = Date.now();
const PAGE = `About ${RUN}`;
const RENAMED = `About us ${RUN}`;

/** The form section whose heading is `heading`, located through the heading rather than by text. */
function sectionFor(page, heading) {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: heading, exact: true }) }).first();
}

/** The statuses the control offers, trimmed — its "Select an option" placeholder, which sets none, left out. */
async function statusOptions(page) {
    const status = page.locator('select[id$="status"]');
    await expect(status).toBeVisible();

    return (await status.locator('option:not([value=""])').allInnerTexts()).map((text) => text.trim());
}

/** Types into the page's rich text body. */
async function writeBody(page, text) {
    const editor = page.locator('.tiptap').first();
    await editor.click();
    await page.keyboard.type(text);
}

/** Saves an edit form and waits for the notification, not for the revisions table's "Saved" header. */
async function saveChanges(page) {
    await page.getByRole('button', { name: /^Save changes$/ }).click();

    await expect(page.locator('.fi-no-notification').filter({ hasText: /Saved/ }).first()).toBeVisible({ timeout: 15_000 });
}

/** Creates through a create form, then opens the new entry's edit form (see `blog-blueprint.spec.js` for why). */
async function create(page, type) {
    await page.locator('form').getByRole('button', { name: /^Create$/ }).first().click();
    await expect(page).toHaveURL(new RegExp(`/admin/${SITE}/c/${type}/\\d+(/edit)?$`), { timeout: 15_000 });

    const id = new URL(page.url()).pathname.match(/\/(\d+)(\/edit)?$/)?.[1];
    await page.goto(`/admin/${SITE}/c/${type}/${id}/edit`);
}

/** The edit form of the page whose list row carries `title`. */
async function editPage(page, title) {
    await page.goto(`/admin/${SITE}/c/page`);
    const href = await page.locator('.fi-ta-row').filter({ hasText: title }).first().getByRole('link').first().getAttribute('href');
    const id = String(href).match(/\/c\/page\/(\d+)/)?.[1];
    await page.goto(`/admin/${SITE}/c/page/${id}/edit`);
}

test.describe.serial('the Marketing Site blueprint, applied by its command', () => {
    test('gives the owner a dashboard that lists Pages before Posts', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        const response = await page.goto(`/admin/${SITE}`);
        expect(response?.status()).toBe(200);
        await expect(page.locator('body')).not.toContainText('Internal Server Error');

        const sidebar = page.locator('.fi-sidebar');
        await expect(sidebar.getByRole('link', { name: 'Pages' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/page$`));

        // Ordering 5 puts Pages before Blog's Posts (10) — the order kitsunecms.org's admin will read in.
        const names = (await sidebar.getByRole('link').allInnerTexts()).map((text) => text.trim());
        expect(names.indexOf('Pages')).toBeGreaterThan(-1);
        expect(names.indexOf('Pages')).toBeLessThan(names.indexOf('Posts'));

        const stats = page.locator('a.fi-wi-stats-overview-stat');
        await expect(stats.filter({ hasText: 'Pages' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/page$`));

        await owner.close();
    });

    test('lists the Marketing Site\'s roles with exactly their grants', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        await page.goto(`/admin/${SITE}/roles`);

        for (const name of ['Marketing editor', 'Marketing writer']) {
            await expect(page.getByRole('link', { name, exact: true }).first()).toBeVisible();
        }

        await page.getByRole('link', { name: 'Marketing writer', exact: true }).first().click();
        await page.waitForURL(/\/roles\/\d+\/edit$/);

        await page.getByRole('heading', { name: 'Pages' }).click();
        const pages = sectionFor(page, 'Pages');

        for (const action of ['View', 'Create', 'Update']) {
            await expect(pages.getByRole('checkbox', { name: action, exact: true })).toBeChecked();
        }

        for (const action of ['Delete', 'Publish']) {
            await expect(pages.getByRole('checkbox', { name: action, exact: true })).not.toBeChecked();
        }

        // ⚠️ NOTHING ON BLOG'S TYPES, AND NOT THE WILDCARD: a blueprint grants only on what it declares.
        for (const heading of ['Posts', 'Tags', 'Every entry type, including ones added later']) {
            // A type's section opens on its heading; the wildcard's is open already, and a click would close it.
            if (heading !== 'Every entry type, including ones added later') {
                await page.getByRole('heading', { name: heading, exact: true }).click();
            }

            const section = sectionFor(page, heading);
            await expect(section.getByRole('checkbox')).toHaveCount(5);
            for (const box of await section.getByRole('checkbox').all()) {
                await expect(box).not.toBeChecked();
            }
        }

        await page.goto(`/admin/${SITE}/roles`);
        await page.getByRole('link', { name: 'Marketing editor', exact: true }).first().click();
        await page.waitForURL(/\/roles\/\d+\/edit$/);

        await page.getByRole('heading', { name: 'Pages' }).click();
        const editorPages = sectionFor(page, 'Pages');

        for (const action of ['View', 'Create', 'Update', 'Delete', 'Publish']) {
            await expect(editorPages.getByRole('checkbox', { name: action, exact: true })).toBeChecked();
        }

        await owner.close();
    });

    test('shows the owner the page type with its fields', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        const response = await page.goto(`/admin/${SITE}/entry-types`);
        expect(response?.status()).toBe(200);

        const type = page.getByRole('link', { name: 'Page', exact: true });
        await expect(type).toBeVisible();

        await page.goto(String(await type.getAttribute('href')));

        // The field list is a lazy Livewire component below the fold; a user scrolls, and so does this.
        await page.getByRole('button', { name: 'Save changes' }).scrollIntoViewIfNeeded();
        await page.mouse.wheel(0, 1200);

        for (const label of ['Body', 'Summary', 'Meta description']) {
            await expect(page.getByRole('cell', { name: label, exact: true }).first()).toBeVisible({ timeout: 10_000 });
        }

        await owner.close();
    });

    test('lets the owner write a page, publish it, and change it without a deploy', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        await page.goto(`/admin/${SITE}/c/page/create`);
        await page.locator('input[wire\\:model="data.title"]').fill(PAGE);
        await page.locator('input[wire\\:model="data.slug"]').fill(`about-${RUN}`);
        await writeBody(page, 'Kitsune is a content management system you can run on one small server.');
        await page.getByRole('textbox', { name: /^Summary/ }).fill('What Kitsune is, in a sentence.');
        await page.locator('select[id$="status"]').selectOption({ label: 'Published' });
        await create(page, 'page');

        await page.reload();
        await expect(page.locator('input[wire\\:model="data.title"]')).toHaveValue(PAGE);
        await expect(page.locator('input[wire\\:model="data.slug"]')).toHaveValue(`about-${RUN}`);
        await expect(page.getByRole('textbox', { name: /^Summary/ })).toHaveValue('What Kitsune is, in a sentence.');
        await expect(page.locator('select[id$="status"]')).toHaveValue('published');

        // ⚠️ THE CONDITION ITSELF: the content changes, and nothing was deployed.
        await page.locator('input[wire\\:model="data.title"]').fill(RENAMED);
        await page.getByRole('textbox', { name: /^Summary/ }).fill('What Kitsune is, and who it is for.');
        await saveChanges(page);
        await page.reload();
        await expect(page.locator('input[wire\\:model="data.title"]')).toHaveValue(RENAMED);
        await expect(page.getByRole('textbox', { name: /^Summary/ })).toHaveValue('What Kitsune is, and who it is for.');

        await page.goto(`/admin/${SITE}/c/page`);
        await expect(page.locator('.fi-ta-row').filter({ hasText: RENAMED }).first()).toContainText(/published/i);

        await owner.close();
    });

    /* ⚠️ A page belongs to the site it was written on: the org's other site lists Pages, and not this one. */
    test('keeps a page to the site it was written on', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        const response = await page.goto('/admin/inkwell-fr/c/page');
        expect(response?.status()).toBe(200);
        await expect(page.locator('.fi-ta')).toBeVisible();
        await expect(page.locator('.fi-ta-row').filter({ hasText: RENAMED })).toHaveCount(0);

        await owner.close();
    });

    test('lets the writer draft and edit pages, publish nothing, and reach no blog', async ({ browser }) => {
        const writer = await browser.newContext({ storageState: WRITER });
        const page = await writer.newPage();

        const dashboard = await page.goto(`/admin/${SITE}`);
        expect(dashboard?.status()).toBe(200);

        const sidebar = page.locator('.fi-sidebar');
        await expect(sidebar.getByRole('link', { name: 'Pages' })).toBeVisible();

        for (const name of ['Posts', 'Tags', 'Entry types', 'Roles']) {
            await expect(sidebar.getByRole('link', { name })).toHaveCount(0);
        }

        // A new page: Draft and Archived, and no Published — publishing is the editor's.
        await page.goto(`/admin/${SITE}/c/page/create`);
        expect(await statusOptions(page)).toEqual(['Draft', 'Archived']);

        await page.locator('input[wire\\:model="data.title"]').fill(`A writer's page ${RUN}`);
        await create(page, 'page');

        // The owner's published page keeps Published, so a typo fix saves without unpublishing it (ADR-033).
        await editPage(page, RENAMED);
        expect(await statusOptions(page)).toContain('Published');
        await page.getByRole('textbox', { name: /^Summary/ }).fill('What Kitsune is, and who it is for — typo fixed.');
        await saveChanges(page);
        await page.reload();
        await expect(page.locator('select[id$="status"]')).toHaveValue('published');

        for (const url of [`/admin/${SITE}/c/post`, `/admin/${SITE}/c/tag`, `/admin/${SITE}/roles`, `/admin/${SITE}/entry-types`]) {
            const refused = await page.goto(url);
            expect(refused?.status(), url).toBe(403);
        }

        // ⚠️ A SITE IN THEIR OWN ORG THAT THEY ARE NOT ATTACHED TO is a tenancy boundary, not a permission one: 404.
        const otherSite = await page.goto('/admin/inkwell-fr');
        expect(otherSite?.status()).toBe(404);

        await writer.close();
    });

    test('keeps each org out of the other, from the side of the one crossing', async ({ browser }) => {
        const golfdom = await browser.newContext({ storageState: GOLFDOM_OWNER });
        const intruder = await golfdom.newPage();

        // Golfdom's owner holds every grant in Golfdom, and none of it reaches Inkwell's pages.
        const intoInkwell = await intruder.goto(`/admin/${SITE}/c/page`);
        expect(intoInkwell?.status()).toBe(404);

        await golfdom.close();

        const writer = await browser.newContext({ storageState: WRITER });
        const page = await writer.newPage();

        const intoGolfdom = await page.goto('/admin/golfdom/c/article');
        expect(intoGolfdom?.status()).toBe(404);

        await writer.close();
    });

    /*
     * ⚠️ WHAT ~~1.0.0~~ 1.1.0 LEAVES OUT REACHES THE ORG THROUGH THE ADMIN. ~~A meta description,~~ An image, a date —
     * neither ships (1.1.0 ships the meta description), and a newer version may only add one, so the owner's own reaches
     * `page` here, under a handle that is not `page_`. Last, because it changes the form the tests above read.
     */
    test('lets the owner add a field to the page type in the admin', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        await page.goto(`/admin/${SITE}/entry-types`);
        await page.goto(String(await page.getByRole('link', { name: 'Page', exact: true }).getAttribute('href')));
        await page.getByRole('button', { name: 'Save changes' }).scrollIntoViewIfNeeded();
        await page.mouse.wheel(0, 1200);

        await page.getByRole('button', { name: 'New field' }).click();

        const modal = page.getByRole('dialog');
        await modal.locator('[id$=".storage_handle"]').fill(`teaser_${RUN}`);
        await modal.locator('[id$=".storage_type"]').selectOption('textarea');
        await modal.locator('[id$=".storage_pii_class"]').selectOption('none');
        await modal.locator('[id$=".label"]').fill(`Teaser ${RUN}`);
        await modal.getByRole('button', { name: 'Create', exact: true }).click();

        await expect(page.getByText(`Teaser ${RUN}`)).toBeVisible();

        await page.goto(`/admin/${SITE}/c/page/create`);
        await expect(page.getByRole('textbox', { name: new RegExp(`^Teaser ${RUN}`) })).toBeVisible();

        await owner.close();
    });
});
