// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * The module kernel's admin seam, crossed in a browser — AGENTS.md §9 and ADR-038.
 *
 * ⚠️ THIS SPEC EXISTS BECAUSE A FEATURE TEST STRUCTURALLY CANNOT SEE THE FAILURE. ADR-024 records the
 * incident: seven of eight specs green while the dashboard returned 500, caused by Filament calling
 * `getUrl()` on a Resource's navigation item. A module contributes a navigation item through
 * `AdminSurface`, and its URL is a closure resolved at render time — so whether it resolves is a question
 * only a rendered page can answer. Core's own test application cannot: registering a panel there throws
 * `Target class [livewire.finder] does not exist`, which is why `PanelLessHostTest` populates the registry
 * directly and why the PHP side of this asserts only that the item carries a closure.
 *
 * ⚠️ AND IT LOADS A PAGE OUTSIDE `/c/{type}` ON PURPOSE. That is §9's actual requirement: the load-bearing
 * risk is URL generation ACROSS page boundaries, so a spec that only ever visited the person list would miss
 * exactly the shape that broke the dashboard.
 *
 * The session is `alpha@kitsune.test`, an org owner, because the item is gated on `entry.person.create` and
 * an owner is the user who certainly holds it. The module is installed and enabled by `global-setup.js`,
 * which has to do it on every run: `migrate:fresh` drops the `modules` table, and requiring the package only
 * puts its code in vendor.
 */

const SITE = 'golfdom';

test.describe('the person module reaches the admin', () => {
    test('renders its navigation item on a page that is not its own', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        // The dashboard, deliberately: a different page shape from the one the link points at.
        await expect(page.locator('body')).not.toContainText('Sign in');

        const shortcut = page.getByRole('link', { name: /new person/i });

        await expect(shortcut).toBeVisible();

        /*
         * ⚠️ THE HREF IS READ, NOT ASSUMED. A navigation item whose closure threw would have taken the whole
         * page down with a 500 — which is the ADR-024 failure — so reaching this line already proves the
         * closure resolved. Asserting the target as well proves it resolved to the right place rather than to
         * a URL that merely exists.
         */
        const href = await shortcut.getAttribute('href');

        expect(href).toContain('/c/person');
        expect(href).toContain('/create');
    });

    test('follows the shortcut to a working create form', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        await page.getByRole('link', { name: /new person/i }).click();
        await page.waitForURL(/\/c\/person\/create/);

        /* The module's own fields, rendered by core's generic resource from rows the module's install wrote. */
        await expect(page.getByLabel('Full name')).toBeVisible();
        await expect(page.getByLabel('Email')).toBeVisible();

        /* And no 500 anywhere on the way: Filament renders its error page with this text. */
        await expect(page.locator('body')).not.toContainText('Server Error');
    });

    test('lists people in the sidebar without the module doing anything', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        /*
         * The plural name from the module's `entry_types` row, offered by core's generic navigation because
         * the type is global. This is the half of "end to end" that needed no seam at all — recorded here
         * because it is the evidence that the seam is exercised by choice rather than by necessity.
         */
        await expect(page.getByRole('link', { name: /^people$/i })).toBeVisible();
    });
});
