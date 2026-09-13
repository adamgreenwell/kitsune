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

    test('assigns somebody to a role, and records that it happened', async ({ page }) => {
        /*
         * ⚠️ ASSIGNMENT IS THE HALF #84 EXPECTED TO PUT IN THE SKELETON, reversed once the panel turned out
         * to name its own provider's user model — core still owns no user model, it asks. The alternative
         * needed an extension point in core's navigation before the extension API exists.
         *
         * ⚠️ AND IT MUST GO THROUGH `Role::assignTo()`, which is the audited path. A page writing the pivot
         * directly would work and record nothing, which is the failure ADR-033 singles out as the one worth
         * logging — so this checks the audit row as well as the assignment.
         */
        await page.goto(`/admin/${SITE}/roles`);
        await page.getByRole('link', { name: 'Copy editor' }).first().click();
        await page.waitForURL(/\/edit$/);

        const holders = sectionFor(page, 'Held by');

        // The seeded copy-editor is already held by somebody, which is the hydration working.
        await expect(holders).toContainText('Reader User');

        await holders.getByRole('combobox').first().click();

        /*
         * ⚠️ `fill()` RATHER THAN `keyboard.type()`, because the search box keeps what was typed before it.
         * A single Backspace left `Riva` and the next search read `RivaAlpha User`, which matches nobody —
         * a test failing on its own typing rather than on the code.
         */
        const search = page.getByRole('textbox', { name: 'Search' });

        await search.fill('Rival');

        /*
         * The rival belongs to another org, so the org-scoped user query must not offer them.
         *
         * ⚠️ Asserted on the OPTION rather than on the "no options" message: Filament renders that message
         * twice, once visibly and once for a screen reader, so a text locator matches two elements and
         * fails strict mode. Asking whether an option for Rival exists is also the question being asked.
         */
        await expect(page.getByRole('option', { name: /Rival/ })).toHaveCount(0);

        await search.fill('Alpha User');
        await page.getByRole('option', { name: /Alpha User/ }).first().click();

        await page.getByRole('button', { name: /^save changes$/i }).first().click();
        await expect(page.getByText(/saved/i).first()).toBeVisible();

        // Reopened, because the assignment is a row in a table the role's own save does not touch.
        await page.reload();
        await expect(sectionFor(page, 'Held by')).toContainText('Alpha User');
    });

    test('lists the roles the seeder made, with what they hold', async ({ page }) => {
        await page.goto(`/admin/${SITE}/roles`);

        const table = page.locator('.fi-ta').first();

        await expect(table).toBeVisible();
        await expect(table).toContainText('Owner');
        await expect(table).toContainText('Copy editor');
    });
});
