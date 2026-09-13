// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * Defining a role through the admin — issue #84, and the half of RBAC that lives in core.
 *
 * ⚠️ THE SEEDER WAS THE ONLY WAY TO CREATE A ROLE UNTIL THIS PAGE EXISTED, which made permissions enforced
 * and not administrable — the honest reason Phase 4's criterion ("entirely through the admin") was not met
 * with #81 alone.
 *
 * This session is the seeded owner. Administering roles is owner-only in v1.0 (ADR-033); the refusal for
 * everybody else is asserted in `permissions.spec.js`, where the session belongs to a copy-editor.
 */

const SITE = 'golfdom';

/** The form section whose heading is `heading`, located through the heading rather than by text. */
function sectionFor(page, heading) {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: heading, exact: true }) }).first();
}

test.describe('a role can be defined in the admin', () => {
    test('creates one, with grants that survive the round trip', async ({ page }) => {
        await page.goto(`/admin/${SITE}/roles/create`);

        // ⚠️ `^Name` rather than an exact match: Filament appends the required marker to the accessible
        // name, so the label reads `Name*` and an exact locator waits for something that never appears.
        await page.getByRole('textbox', { name: /^Name/ }).fill('Sub-editor');
        await page.getByRole('textbox', { name: /^Handle/ }).fill('sub-editor');

        /*
         * ⚠️ The per-type section is collapsed by default — an org with forty types would otherwise open on
         * forty expanded panels — so it has to be opened before its checkboxes exist to click.
         */
        await page.getByRole('heading', { name: 'Articles' }).click();

        const articles = sectionFor(page, 'Articles');
        await articles.getByRole('checkbox', { name: 'View', exact: true }).check();
        await articles.getByRole('checkbox', { name: 'Update', exact: true }).check();

        await page.getByRole('button', { name: /^create$/i }).first().click();
        await page.waitForURL(/\/roles(\/\d+\/edit)?$/);

        /*
         * ⚠️ REOPENED RATHER THAN TRUSTED, because the save is two writes: the role's own row, and grants
         * that are rows in another table entirely (ADR-015's shape applied to authorization). A page that
         * wrote the first and silently dropped the second would look identical at this point.
         */
        await page.goto(`/admin/${SITE}/roles`);
        await page.getByRole('link', { name: 'Sub-editor' }).first().click();
        await page.waitForURL(/\/edit$/);

        await page.getByRole('heading', { name: 'Articles' }).click();

        const saved = sectionFor(page, 'Articles');
        await expect(saved.getByRole('checkbox', { name: 'View', exact: true })).toBeChecked();
        await expect(saved.getByRole('checkbox', { name: 'Update', exact: true })).toBeChecked();
        await expect(saved.getByRole('checkbox', { name: 'Delete', exact: true })).not.toBeChecked();
    });

    test('offers the wildcard as its own decision, not one checkbox among two hundred', async ({ page }) => {
        /*
         * ⚠️ `entry.*.{action}` COVERS TYPES CREATED LATER — that is what it is for (ADR-033) and also how
         * somebody grants more than they meant to. It gets its own section, above the per-type ones, with
         * words saying what it does rather than a checkbox that reads like the others.
         */
        await page.goto(`/admin/${SITE}/roles/create`);

        const wildcard = sectionFor(page, 'Every entry type, including ones added later');

        await expect(wildcard).toBeVisible();
        await expect(wildcard).toContainText('created next month');

        /*
         * ⚠️ EXACTLY THE FIVE ACTIONS THE REGISTRY ACCEPTS, because a control that could produce a sixth
         * would turn a save into an exception — `Role::grant()` fails closed on an unregistered action. The
         * form reads `Permissions::ACTIONS` rather than listing them, and this is where that is visible: a
         * PHP test asserting the same array against itself would prove nothing.
         */
        const actions = await wildcard.getByRole('checkbox').evaluateAll(
            (boxes) => boxes.map((box) => (box.closest('label')?.innerText ?? '').trim()),
        );

        expect(actions).toEqual(['View', 'Create', 'Update', 'Delete', 'Publish']);
    });

    test('lists the roles the seeder made, with what they hold', async ({ page }) => {
        await page.goto(`/admin/${SITE}/roles`);

        const table = page.locator('.fi-ta').first();

        await expect(table).toBeVisible();
        await expect(table).toContainText('Owner');
        await expect(table).toContainText('Copy editor');
    });
});
