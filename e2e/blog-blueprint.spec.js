// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * The Blog blueprint, as `kitsune:blueprint apply blog` left it — Phase 5, ADR-039: AGENTS.md §9 coverage, outside
 * `/c/{type}`, of what the command wrote. Not the test of an apply flow in the admin that ADR-039 still owes, because
 * there is no admin route to a blueprint yet.
 *
 * ⚠️ WHAT THE COMMAND WROTE, NOT WHAT A SEEDER THOUGHT IT WOULD. `inkwell` is seeded bare — two sites, an owner, a
 * writer holding no role — and `e2e/global-setup.js` applies Blog into it with the command, then gives the writer
 * Blog's writer role through the audited path. So the dashboard, the role list, the schema and the forms below are
 * the apply's, end to end, on every build.
 *
 * ⚠️ AND WHAT THE WRITER MAY NOT DO IS HALF OF IT. A role is authority, so the writer's limits are asserted at the URL
 * (403), in the sidebar (no link) and in the status control (no Published) — and the other site and the other org at
 * the URL, from the side of the person who would be crossing.
 */

const SITE = 'inkwell';
const OWNER = '.playwright/blog-owner-auth.json';
const WRITER = '.playwright/blog-writer-auth.json';
const GOLFDOM_OWNER = '.playwright/admin-auth.json';

/* Made per run, so a re-run against the same database meets its own rows and not the last run's. */
const RUN = Date.now();
const TAG = `Releases ${RUN}`;
const POST = `Kitsune ${RUN} is out`;

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

/** Chooses a tag in the post's Tags picker — typed, because the picker preloads nothing (see relation-picker.spec.js). */
async function chooseTag(page, name) {
    await page.getByRole('combobox', { name: /^Tags/ }).first().click();
    await page.keyboard.type(name);

    const option = page.getByRole('option', { name }).first();
    await expect(option).toBeVisible({ timeout: 10_000 });
    await option.click();
}

/** Types into the post's rich text body. */
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

/**
 * Creates through a create form, then opens the new entry's edit form.
 *
 * ⚠️ THE EDIT FORM, NOT THE VIEW PAGE FILAMENT REDIRECTS TO. The view page does not hydrate relation fields, so a post
 * whose tags saved shows an empty Tags control there — a gap of its own, not this blueprint's — and asserting on it
 * would measure that gap rather than the save.
 */
async function create(page, type) {
    await page.locator('form').getByRole('button', { name: /^Create$/ }).first().click();
    await expect(page).toHaveURL(new RegExp(`/admin/${SITE}/c/${type}/\\d+(/edit)?$`), { timeout: 15_000 });

    const id = new URL(page.url()).pathname.match(/\/(\d+)(\/edit)?$/)?.[1];
    await page.goto(`/admin/${SITE}/c/${type}/${id}/edit`);
}

test.describe.serial('the Blog blueprint, applied by its command', () => {
    test('gives the owner a dashboard that links Posts and Tags', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        const response = await page.goto(`/admin/${SITE}`);
        expect(response?.status()).toBe(200);
        await expect(page.locator('body')).not.toContainText('Internal Server Error');

        const sidebar = page.locator('.fi-sidebar');
        await expect(sidebar.getByRole('link', { name: 'Posts' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/post$`));
        await expect(sidebar.getByRole('link', { name: 'Tags' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/tag$`));

        const stats = page.locator('a.fi-wi-stats-overview-stat');
        await expect(stats.filter({ hasText: 'Posts' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/post$`));
        await expect(stats.filter({ hasText: 'Tags' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/tag$`));

        await owner.close();
    });

    test('lists Blog\'s roles with exactly the grants it declared', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        await page.goto(`/admin/${SITE}/roles`);

        for (const name of ['Owner', 'Blog editor', 'Blog writer']) {
            await expect(page.getByRole('link', { name, exact: true }).first()).toBeVisible();
        }

        await page.getByRole('link', { name: 'Blog writer', exact: true }).first().click();
        await page.waitForURL(/\/roles\/\d+\/edit$/);

        await page.getByRole('heading', { name: 'Posts' }).click();
        const posts = sectionFor(page, 'Posts');

        for (const action of ['View', 'Create', 'Update']) {
            await expect(posts.getByRole('checkbox', { name: action, exact: true })).toBeChecked();
        }

        for (const action of ['Delete', 'Publish']) {
            await expect(posts.getByRole('checkbox', { name: action, exact: true })).not.toBeChecked();
        }

        await page.getByRole('heading', { name: 'Tags' }).click();
        const tags = sectionFor(page, 'Tags');

        await expect(tags.getByRole('checkbox', { name: 'View', exact: true })).toBeChecked();

        for (const action of ['Create', 'Update', 'Delete', 'Publish']) {
            await expect(tags.getByRole('checkbox', { name: action, exact: true })).not.toBeChecked();
        }

        // ⚠️ AND NOT THE WILDCARD: a blueprint grants only on what it declares.
        const wildcard = sectionFor(page, 'Every entry type, including ones added later');
        await expect(wildcard.getByRole('checkbox')).toHaveCount(5);
        for (const box of await wildcard.getByRole('checkbox').all()) {
            await expect(box).not.toBeChecked();
        }

        await owner.close();
    });

    test('shows the owner the post and tag types, with Post\'s fields', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        const response = await page.goto(`/admin/${SITE}/entry-types`);
        expect(response?.status()).toBe(200);

        const post = page.getByRole('link', { name: 'Post', exact: true });
        await expect(post).toBeVisible();
        await expect(page.getByRole('link', { name: 'Tag', exact: true })).toBeVisible();

        await page.goto(String(await post.getAttribute('href')));

        // The field list is a lazy Livewire component below the fold; a user scrolls, and so does this.
        await page.getByRole('button', { name: 'Save changes' }).scrollIntoViewIfNeeded();
        await page.mouse.wheel(0, 1200);

        for (const label of ['Body', 'Excerpt', 'Tags']) {
            await expect(page.getByRole('cell', { name: label, exact: true }).first()).toBeVisible({ timeout: 10_000 });
        }

        await owner.close();
    });

    test('lets the owner write a tagged post and publish it', async ({ browser }) => {
        const owner = await browser.newContext({ storageState: OWNER });
        const page = await owner.newPage();

        await page.goto(`/admin/${SITE}/c/tag/create`);
        await page.locator('input[wire\\:model="data.title"]').fill(TAG);
        await create(page, 'tag');

        await page.goto(`/admin/${SITE}/c/post/create`);
        await page.locator('input[wire\\:model="data.title"]').fill(POST);
        await writeBody(page, 'Everything that changed, in one place.');
        await page.getByRole('textbox', { name: /^Excerpt/ }).fill('The release notes, summed up.');
        await chooseTag(page, TAG);
        await page.locator('select[id$="status"]').selectOption({ label: 'Published' });
        await create(page, 'post');

        // ⚠️ RELOADED, because a relation is written after the entry exists (ADR-015) and the page could show a chip
        // the database never received.
        await page.reload();
        await expect(page.locator('[id="form.relations.post_tags"]')).toContainText(TAG);
        await expect(page.locator('select[id$="status"]')).toHaveValue('published');

        await page.goto(`/admin/${SITE}/c/post`);
        await expect(page.locator('.fi-ta-row').filter({ hasText: POST }).first()).toContainText(/published/i);

        await owner.close();
    });

    test('lets the writer draft and edit posts, and publish nothing', async ({ browser }) => {
        const writer = await browser.newContext({ storageState: WRITER });
        const page = await writer.newPage();

        const dashboard = await page.goto(`/admin/${SITE}`);
        expect(dashboard?.status()).toBe(200);

        const sidebar = page.locator('.fi-sidebar');
        await expect(sidebar.getByRole('link', { name: 'Posts' })).toBeVisible();
        await expect(sidebar.getByRole('link', { name: 'Tags' })).toBeVisible();
        await expect(sidebar.getByRole('link', { name: 'Entry types' })).toHaveCount(0);
        await expect(sidebar.getByRole('link', { name: 'Roles' })).toHaveCount(0);

        // A new post: Draft and Archived, and no Published — publishing is the editor's.
        await page.goto(`/admin/${SITE}/c/post/create`);
        expect(await statusOptions(page)).toEqual(['Draft', 'Archived']);

        await page.locator('input[wire\\:model="data.title"]').fill(`A writer's draft ${RUN}`);
        await chooseTag(page, TAG);
        await create(page, 'post');
        await page.reload();
        await expect(page.locator('[id="form.relations.post_tags"]')).toContainText(TAG);

        // The owner's published post keeps Published, so a typo fix saves without unpublishing it (ADR-033).
        await page.goto(`/admin/${SITE}/c/post`);
        const href = await page.locator('.fi-ta-row').filter({ hasText: POST }).first().getByRole('link').first().getAttribute('href');
        const id = String(href).match(/\/c\/post\/(\d+)/)?.[1];
        await page.goto(`/admin/${SITE}/c/post/${id}/edit`);

        expect(await statusOptions(page)).toContain('Published');
        await page.getByRole('textbox', { name: /^Excerpt/ }).fill('The release notes, summed up — typo fixed.');
        await saveChanges(page);
        await page.reload();
        await expect(page.locator('select[id$="status"]')).toHaveValue('published');

        for (const url of [`/admin/${SITE}/c/tag/create`, `/admin/${SITE}/roles`, `/admin/${SITE}/entry-types`]) {
            const refused = await page.goto(url);
            expect(refused?.status(), url).toBe(403);
        }

        // ⚠️ A SITE IN HER OWN ORG THAT SHE IS NOT ATTACHED TO is a tenancy boundary, not a permission one: 404.
        const otherSite = await page.goto('/admin/inkwell-fr');
        expect(otherSite?.status()).toBe(404);

        await writer.close();
    });

    test('keeps each org out of the other, from the side of the one crossing', async ({ browser }) => {
        const golfdom = await browser.newContext({ storageState: GOLFDOM_OWNER });
        const intruder = await golfdom.newPage();

        // Golfdom's owner holds every grant in Golfdom, and none of it reaches Inkwell.
        const intoInkwell = await intruder.goto(`/admin/${SITE}/c/post`);
        expect(intoInkwell?.status()).toBe(404);

        await golfdom.close();

        const writer = await browser.newContext({ storageState: WRITER });
        const page = await writer.newPage();

        const intoGolfdom = await page.goto('/admin/golfdom/c/article');
        expect(intoGolfdom?.status()).toBe(404);

        await writer.close();
    });
});
