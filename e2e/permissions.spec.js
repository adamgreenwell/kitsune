// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * Per-type authorization, measured in a browser — ADR-033, issue #81, and Phase 4's last unchecked line.
 *
 * ⚠️ THE LINK IS NOT THE GUARANTEE, and this file exists because the PHP suite structurally cannot say so.
 * Hiding a navigation item an authenticated user can still reach by typing the URL is the classic shape of
 * this bug: the panel looks correct, the boundary is not there, and every unit test passes because it asks
 * the policy directly rather than asking the application. ADR-024 makes the browser layer mandatory for
 * exactly this class.
 *
 * The session here belongs to `reader@kitsune.test`, a copy-editor: `entry.article.view` and
 * `entry.article.update`, and deliberately nothing else. An owner cannot measure a permission system, and a
 * user with only `view` cannot reach the form on which `publish` is enforced.
 *
 * One block signs in as `viewer@kitsune.test` instead, who holds `view` alone — the user a restore must refuse.
 */

const SITE = 'golfdom';

/** Open the edit form of the first row whose status cell reads `status`. */
async function openForEditing(page, status) {
    const row = page.locator('.fi-ta-row').filter({ hasText: status }).first();
    await expect(row).toBeVisible();

    await row.locator('a[href*="/c/article/"]').first().click();
    await page.waitForURL(/\/c\/article\/\d+/);
    await page.getByRole('link', { name: /^edit$/i }).first().click();
    await page.waitForURL(/\/edit$/);
}

/**
 * The status control's options, trimmed.
 *
 * `allInnerTexts()` returns the option markup's whitespace with it, so an untrimmed `toContain('Draft')`
 * fails on a list that plainly contains Draft.
 */
async function statusOptions(page) {
    const status = page.locator('select[id$="status"]');
    await expect(status).toBeVisible();

    return (await status.locator('option').allInnerTexts()).map((text) => text.trim());
}


test.describe('a user holds only what was granted', () => {
    test('reaches the entry type they may view', async ({ page }) => {
        const response = await page.goto(`/admin/${SITE}/c/article`);

        expect(response?.status()).toBe(200);

        /*
         * ⚠️ THE TABLE, NOT A PARTICULAR ROW, and the first version asserted a row. It named a seeded
         * article and passed in isolation while failing in the full suite — other specs write entries, the
         * list sorts by `updated_at desc` and pages at ten, so which rows are on page one is not this
         * spec's to know. A fixture another test can move is a fixture that fails for the wrong reason.
         */
        await expect(page.locator('.fi-ta').first()).toBeVisible();
    });

    test('is refused at the URL for a type they may not view', async ({ page }) => {
        /*
         * ⚠️ 403 AND NOT 404, which is worth being precise about. `IdentifyEntryType` 404s a type that does
         * not exist, belongs to another org, or is disabled for this site — a tenancy boundary. This type
         * passes all three and the refusal is an authorization one, so conflating them would hide a tenancy
         * failure behind a permission message or the reverse.
         */
        const response = await page.goto(`/admin/${SITE}/c/product`);

        expect(response?.status()).toBe(403);
    });

    test('is refused at the URL for an action they may not take', async ({ page }) => {
        // `view` on articles is held; `create` is not, and they are separate permissions rather than levels.
        const response = await page.goto(`/admin/${SITE}/c/article/create`);

        expect(response?.status()).toBe(403);
    });

    test('is offered no link to a type they may not view', async ({ page }) => {
        /*
         * The other half, and the one a user actually experiences. Navigation is supplied explicitly by
         * `KitsunePanel`, so Filament never asks a resource whether an item should appear — the filter is
         * Kitsune's own, and this is what asserts it runs.
         */
        await page.goto(`/admin/${SITE}/c/article`);

        const sidebar = page.locator('.fi-sidebar');
        await expect(sidebar.getByRole('link', { name: 'Articles' })).toBeVisible();
        await expect(sidebar.getByRole('link', { name: 'Products' })).toHaveCount(0);
    });

    test('is refused the schema builder, at the URL and in the sidebar', async ({ page }) => {
        /*
         * ⚠️ RBAC EXISTING MADE THIS A HOLE RATHER THAN A DEFAULT — review found it. Before permissions,
         * every member of an org could do everything the tenancy scopes allowed, so an unguarded entry-type
         * builder was consistent. With them in place this copy-editor could still create, rewrite and delete
         * the org's schema while being refused `/c/product`: a permission system that governs the content
         * and not the shape of the content governs the smaller half.
         *
         * Schema editing is owner-only in v1.0. The vocabulary `architecture.md` publishes is five actions
         * on ENTRIES and nothing else, so there is no `schema.manage` to ask for — and inventing one widens
         * the extension surface, which Standing Principle #1 keeps shut until v1.2.
         */
        const refused = await page.goto(`/admin/${SITE}/entry-types`);
        expect(refused?.status()).toBe(403);

        const create = await page.goto(`/admin/${SITE}/entry-types/create`);
        expect(create?.status()).toBe(403);

        // And the link is gone, which is the half a user meets — the URL above is the one that matters.
        await page.goto(`/admin/${SITE}/c/article`);
        await expect(page.locator('.fi-sidebar').getByRole('link', { name: 'Entry types' })).toHaveCount(0);
    });

    test('is refused role administration, at the URL and in the sidebar', async ({ page }) => {
        /*
         * ⚠️ THE SAME BOUNDARY AS THE SCHEMA BUILDER, AND FOR A SHARPER REASON — issue #84. Administering
         * roles is administering the permission system itself: a user who could open this page could grant
         * themselves everything, including the owner flag, which bypasses every check there is.
         *
         * Owner-only in v1.0, because `architecture.md` publishes five actions on ENTRIES and nothing else,
         * so there is no `role.manage` to ask for and inventing a subject widens the extension surface
         * Standing Principle #1 keeps shut until v1.2.
         */
        const refused = await page.goto(`/admin/${SITE}/roles`);
        expect(refused?.status()).toBe(403);

        const create = await page.goto(`/admin/${SITE}/roles/create`);
        expect(create?.status()).toBe(403);

        await page.goto(`/admin/${SITE}/c/article`);
        await expect(page.locator('.fi-sidebar').getByRole('link', { name: 'Roles' })).toHaveCount(0);
    });

    test('is offered no published status on a draft, because publishing is its own permission', async ({ page }) => {
        /*
         * ⚠️ THE OPTIONS ARE THE VISIBLE HALF ONLY. `EntryResource` also validates the value against the
         * same list, because a hand-built request never opens a select — and a permission enforced only by
         * what a page renders is a permission enforced only against people who use the page. That half is
         * asserted in PHP, where a request can be built without a browser.
         *
         * ⚠️ AND IT IS THE EDIT FORM, WHICH IS WHY THE FIXTURE HOLDS `update`. Create is refused for this
         * user — the assertion two tests up — so the form that carries the control has to be reached the
         * other way.
         *
         * ⚠️ A DRAFT ROW SPECIFICALLY, and picking whichever row came first was wrong. Two of every three
         * seeded articles are published, and a published entry KEEPS its own status in the list whoever is
         * editing it (see the test below) — so a test that took the first row was asserting about whichever
         * status the seeder happened to give it.
         */
        await page.goto(`/admin/${SITE}/c/article`);

        await openForEditing(page, 'Draft');

        const options = await statusOptions(page);

        expect(options).toContain('Draft');
        expect(options).toContain('Archived');
        expect(options).not.toContain('Published');
    });

    test('may still keep an entry that is already published', async ({ page }) => {
        /*
         * ⚠️ THE OTHER HALF OF THE SAME RULE, and without it the permission is a licence to unpublish.
         * `publish` is permission to move an entry INTO the published state; withholding the option outright
         * also withheld the entry's own current value, so this user could not fix a typo on a published
         * article without demoting or archiving it first. Review found it.
         *
         * The concession cannot be used to REACH the state, because the stored status is what decides — the
         * test above is that half, on a draft.
         */
        await page.goto(`/admin/${SITE}/c/article`);

        await openForEditing(page, 'Published');

        expect(await statusOptions(page)).toContain('Published');
    });
});

test.describe('restoring a version that was published', () => {
    /*
     * ⚠️ A MODEL GUARD THE PANEL STILL OFFERS IS A 500, which review said in as many words. Restoring a
     * revision that was published PUBLISHES the entry — `EntryRevision::SNAPSHOT_ATTRIBUTES` carries
     * `status` — so `Entry::restoreRevision()` refuses it without `entry.article.publish`. Without the
     * button asking the same question, this copy-editor confirms a modal and gets a server error.
     *
     * ⚠️ THE FIXTURE IS SEEDED, because no ordinary row has this shape: every other article's history holds
     * only the status it was created with. `Bunker renovation` was published and then pulled back, so its
     * history contains a published version and its current status is draft.
     */
    test('is offered the restore as unavailable rather than as an error', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);

        const row = page.locator('.fi-ta-row').filter({ hasText: 'Bunker renovation' }).first();
        await expect(row).toBeVisible();

        await row.locator('a[href*="/c/article/"]').first().click();
        await page.waitForURL(/\/c\/article\/\d+/);
        await page.getByRole('link', { name: /^edit$/i }).first().click();
        await page.waitForURL(/\/edit$/);

        // The relation manager is a lazy Livewire component below the fold — see `revisions.spec.js`.
        await page.getByRole('button', { name: 'Save changes' }).scrollIntoViewIfNeeded();
        await page.mouse.wheel(0, 1200);
        await expect(page.getByText('History')).toBeVisible();

        /*
         * The published version's row. Two versions exist: the one it was created with (published) and the
         * demotion (draft), so the badge is what tells them apart.
         */
        const published = page.locator('.fi-ta-row').filter({ hasText: 'Published' }).first();
        await expect(published).toBeVisible();

        const restore = published.getByRole('button', { name: 'Restore' });

        await expect(restore).toBeVisible();
        await expect(restore).toBeDisabled();
    });

    test('leaves the draft version restorable, so this is a permission and not a lock', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);

        const row = page.locator('.fi-ta-row').filter({ hasText: 'Bunker renovation' }).first();
        await row.locator('a[href*="/c/article/"]').first().click();
        await page.waitForURL(/\/c\/article\/\d+/);
        await page.getByRole('link', { name: /^edit$/i }).first().click();
        await page.waitForURL(/\/edit$/);

        await page.getByRole('button', { name: 'Save changes' }).scrollIntoViewIfNeeded();
        await page.mouse.wheel(0, 1200);
        await expect(page.getByText('History')).toBeVisible();

        const draft = page.locator('.fi-ta-row').filter({ hasText: 'Draft' }).first();
        await expect(draft).toBeVisible();
        await expect(draft.getByRole('button', { name: 'Restore' })).toBeEnabled();
    });
});

test.describe('the history of an entry somebody may only view', () => {
    /*
     * ⚠️ A RESTORE IS AN EDIT, AND THE VIEW PAGE RENDERS THE HISTORY TOO. Filament's `ViewRecord` shows a
     * resource's relation managers, and the Restore action carried no authorization of its own — so a user
     * holding `entry.article.view` alone could put an old version back from a page that never asked whether
     * they may edit. Review found it.
     *
     * ⚠️ BOTH HALVES, and the second is the boundary. A missing button is what a browser is shown; a hand-built
     * Livewire call is what somebody sends instead, and it has to meet the same answer — the reasoning this
     * file already applies to the status control's options and its validation rule.
     */
    test.use({ storageState: '.playwright/admin-viewer-auth.json' });

    async function openHistory(page) {
        await page.goto(`/admin/${SITE}/c/article`);

        const row = page.locator('.fi-ta-row').filter({ hasText: 'Bunker renovation' }).first();
        await expect(row).toBeVisible();

        await row.locator('a[href*="/c/article/"]').first().click();
        await page.waitForURL(/\/c\/article\/\d+/);

        // The relation manager is a lazy Livewire component below the fold — see `revisions.spec.js`.
        await page.mouse.wheel(0, 1200);
        await expect(page.getByText('History')).toBeVisible();
    }

    test('shows the history and offers no restore', async ({ page }) => {
        await openHistory(page);

        await expect(page.locator('.fi-ta-row').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Restore' })).toHaveCount(0);
    });

    test('refuses the restore when it is requested by hand', async ({ page }) => {
        await openHistory(page);

        const rows = page.locator('.fi-ta-row');
        const before = await rows.count();

        /*
         * The version before the seeded rewrite, and rows are newest first. It is a DRAFT, so no publish
         * permission is involved, and it differs from the entry as it stands, so a restore that went through
         * would file a version rather than nothing — which is what makes an unchanged count a refusal.
         */
        const key = await rows.nth(1).getAttribute('wire:key');
        expect(key).toContain('.table.records.');

        const [component, record] = String(key).split('.table.records.');

        await page.evaluate(async ({ component, record }) => {
            const wire = /** @type {any} */ (window).Livewire.find(component);

            await wire.mountAction('restore', {}, { table: true, recordKey: record });
            await wire.callMountedAction();
        }, { component, record });

        await openHistory(page);

        await expect(rows).toHaveCount(before);
    });
});

test.describe('the related entries of an entry somebody may only view', () => {
    /*
     * ⚠️ ATTACH AND DETACH ARE EDITS, AND THIS PAGE IS REACHABLE BY A VIEWER. `ManageRelatedRecords` authorizes
     * the page with `viewAny`, and Filament's default authorization there covers create, edit, delete and view —
     * not attach or detach — so both ran for anybody who could open it. Review found it, one page along from the
     * History's restore; the same two halves are asserted for the same reason.
     */
    test.use({ storageState: '.playwright/admin-viewer-auth.json' });

    // The seeded first article is related to the others, and the viewer may view articles.
    const RELATED = `/admin/${SITE}/c/article/1/related`;

    test('lists the related entries and offers no way to change them', async ({ page }) => {
        await page.goto(RELATED);

        await expect(page.locator('.fi-ta-row').first()).toBeVisible();
        await expect(page.getByRole('button', { name: 'Attach' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Detach' })).toHaveCount(0);
    });

    test('refuses a detach requested by hand', async ({ page }) => {
        await page.goto(RELATED);

        const rows = page.locator('.fi-ta-row');
        await expect(rows.first()).toBeVisible();
        const before = await rows.count();

        const key = await rows.first().getAttribute('wire:key');
        expect(key).toContain('.table.records.');

        const [component, record] = String(key).split('.table.records.');

        await page.evaluate(async ({ component, record }) => {
            const wire = /** @type {any} */ (window).Livewire.find(component);

            await wire.mountAction('detach', {}, { table: true, recordKey: record });
            await wire.callMountedAction();
        }, { component, record });

        await page.goto(RELATED);
        await expect(rows.first()).toBeVisible();

        // A detach that went through would leave one row fewer.
        await expect(rows).toHaveCount(before);
    });
});

test.describe('a relation pointing at something the editor may not view', () => {
    /*
     * ⚠️ THE PAGE COULD NOT BE SAVED AT ALL, which is the defect review found and the reason this is a
     * browser test rather than a PHP one. `SyncsFieldRelations` hydrates every id an entry is related
     * through, and the seeded `related_products` field points week five at a product — a type this
     * copy-editor holds nothing on. The label resolvers withheld it, and Filament validates a select's
     * submitted options THROUGH those resolvers (`Select::getInValidationRuleValues()`), so the id became an
     * invalid option: a permission narrowing one relation froze the whole record, including a title change
     * on a field the editor was not touching.
     *
     * ⚠️ AND THE PHP TEST CANNOT SEE IT. `FieldValueRenderer::relationLabels()` is asserted directly in
     * `RelationPickerSearchTest`, but whether Filament hands that callback the RECORD — an evaluation
     * parameter the fix depends on — is a property of the panel. ADR-024: that layer is measured here.
     */
    const TITLE = 'Course maintenance in week 5';

    /** Open week five for editing, found by search rather than by position. */
    async function openWeekFive(page) {
        await page.goto(`/admin/${SITE}/c/article`);

        // The list pages at ten and sorts by `updated_at`, so which page a row is on is not this spec's to
        // know — the title column is searchable, so ask for it by name.
        await page.locator('.fi-ta-search-field input').first().fill(TITLE);

        const row = page.locator('.fi-ta-row').filter({ hasText: TITLE }).first();
        await expect(row).toBeVisible();

        await row.locator('a[href*="/c/article/"]').first().click();
        await page.waitForURL(/\/c\/article\/\d+/);
        await page.getByRole('link', { name: /^edit$/i }).first().click();
        await page.waitForURL(/\/edit$/);
    }

    test('withholds the title of the linked record', async ({ page }) => {
        await openWeekFive(page);

        // The link is kept AS A VALUE and withheld AS A TITLE: the id was already in the form state, and the
        // product's name is what the grant protects.
        const products = page.getByRole('combobox', { name: 'Related products' });

        await expect(products).toContainText(/you may not view this entry type/);
        await expect(page.getByText('Fairway mower')).toHaveCount(0);
    });

    test('saves an unrelated change, and keeps the link', async ({ page }) => {
        await openWeekFive(page);

        const minutes = page.getByLabel('Reading minutes');
        await minutes.fill('42');

        await page.getByRole('button', { name: /^save changes$/i }).click();

        /*
         * ⚠️ THE NOTIFICATION, NOT THE ABSENCE OF AN ERROR. Filament renders a validation failure as a
         * message beside the field and leaves the page exactly where it was, so a test that only asserted
         * the URL had not changed would pass on the broken behaviour.
         */
        await expect(page.getByRole('heading', { name: 'Saved' })).toBeVisible();

        // The relation the editor could not see is still there, which is the other half of "preserve".
        await page.reload();
        await expect(page.getByLabel('Reading minutes')).toHaveValue('42');
        await expect(page.getByRole('combobox', { name: 'Related products' }))
            .toContainText(/you may not view this entry type/);
    });
});
