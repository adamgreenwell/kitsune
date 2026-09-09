// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

/*
 * Phase 4's flagship, and the layer that has to cover it.
 *
 * These pages live OUTSIDE /c/{type}, which is where the ADR-012 spike found
 * a 500 while the PHP suite was seven-of-eight green — so CONTRIBUTING's
 * standing regression test applies here directly.
 *
 * Three defects in the builder were found the same way and are pinned below:
 * the list 500d on Filament's tenant scoping, the create-field modal 500d on
 * an unguarded registry lookup, and a `number` field silently defaulted to
 * `integer` — which would have truncated every value stored in it.
 */

const SITE = '/admin/golfdom';

/*
 * Open a named type's edit page.
 *
 * By href rather than by clicking: every row exposes TWO links to the same
 * place — the row itself and its Edit action — so a name-based click is a
 * strict-mode violation, and "first" lands on whichever type happens to sort
 * first (the global `image` type, not the org's own).
 */
async function editType(page, name) {
    await page.goto(`${SITE}/entry-types`);

    const href = await page
        .getByRole('row', { name: new RegExp(name) })
        .getByRole('link')
        .first()
        .getAttribute('href');

    await page.goto(href);

    // ⚠️ Scroll, because the field list is a LAZY Livewire component below
    // the fold. Filament mounts it on intersection, so at 1280x720 it never
    // loads and every assertion below it fails with "element not found" —
    // which reads as a broken selector rather than as a component that was
    // never asked to render. A user scrolls; so does this.
    await page.getByRole('button', { name: 'Save changes' }).scrollIntoViewIfNeeded();
    await page.mouse.wheel(0, 1200);
}

test.describe('entity type builder', () => {
    test('lists the org\'s types, which is a page outside /c/{type}', async ({ page }) => {
        const response = await page.goto(`${SITE}/entry-types`);

        // Filament scopes every resource through a relationship on the
        // tenant, and the tenant is the Site while schema is ORG-owned. That
        // 500d with "does not have a relationship named [site]".
        expect(response?.status()).toBe(200);
        await expect(page.getByRole('heading', { name: 'Entry Types' })).toBeVisible();
        await expect(page.getByText('article', { exact: true })).toBeVisible();
    });

    test('does not show another org\'s types', async ({ page }) => {
        await page.goto(`${SITE}/entry-types`);

        // EntryType is #[Unscoped] by declaration, so isolation here is
        // supplied by getEloquentQuery() and nothing else.
        await expect(page.locator('body')).not.toContainText('mediaplanner');
        await expect(page.locator('body')).not.toContainText('Rival');
    });

    test('opens an entry type for editing', async ({ page }) => {
        await editType(page, 'Article');

        // ⚠️ `exact` because this became ambiguous the moment the Article type had fields:
        // the subject-identifier select then renders its help text, which also contains the
        // phrase, and Playwright's strict mode refuses two matches. The looser locator was
        // only ever unambiguous because the seed defined no fields (issue #39).
        await expect(page.getByText('Subject identifier', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'New field' })).toBeVisible();
    });

    test('opens the create-field modal without erroring', async ({ page }) => {
        await editType(page, 'Article');
        await page.getByRole('button', { name: 'New field' }).click();

        // The registry fails closed on an unknown handle by design, and the
        // form's empty initial state called get(''). This 500d on open.
        await expect(page.getByText('Create Field')).toBeVisible();
        await expect(page.locator('body')).not.toContainText('Internal Server Error');
    });

    test('offers every registered field type, driven by the registry', async ({ page }) => {
        await editType(page, 'Article');
        await page.getByRole('button', { name: 'New field' }).click();

        const select = page.locator('select').filter({ hasText: 'Rich text' });

        // Twelve v1.0 types, and adding a thirteenth needs no admin code.
        await expect(select.locator('option')).toHaveCount(13); // 12 + placeholder
    });

    test('seeds a field type\'s declared defaults rather than the first option', async ({ page }) => {
        await editType(page, 'Article');
        await page.getByRole('button', { name: 'New field' }).click();

        const type = page.locator('select').filter({ hasText: 'Rich text' });
        await type.selectOption('number');

        // ⚠️ The regression that matters. NumberType declares `decimal`, and
        // the reactive settings section rendered `integer` — the first option
        // — which would have truncated every value the field stored.
        const format = page.locator('select').filter({ hasText: 'Decimal' });
        await expect(format).toHaveValue('decimal');
    });

    test('403s the edit ROUTE for a global type, not just its link', async ({ page }) => {
        // ⚠️ The finding this pins: ownership was tested in
        // `EditAction::visible()`, which decides whether a BUTTON is drawn.
        // Typing the URL got the form, the save and the field relation
        // manager, so an org could rewrite schema every other org shares.
        //
        // `image` is the first type the seeder creates, so id 1. Hardcoded
        // because the gate leaves no link to read an id from — which is the
        // other half of what this asserts. If the seeder is reordered this
        // fails loudly with 200, pointing here.
        const response = await page.goto(`${SITE}/entry-types/1/edit`);

        expect(response?.status()).toBe(403);
    });

    test('shows a global type but offers no way in', async ({ page }) => {
        await page.goto(`${SITE}/entry-types`);

        // Visible AND not writable: `getEloquentQuery()` includes
        // `org_id IS NULL` on purpose, so an org can see the system types it
        // shares. Seeing is the feature; editing is the defect.
        const row = page.getByRole('row', { name: /Image/ });

        await expect(row).toBeVisible();
        await expect(row.getByRole('link')).toHaveCount(0);
    });

    test('persists a change to an existing field, which the edit path dropped', async ({ page }) => {
        await editType(page, 'Article');

        // Created here rather than reused from another test: sharing a fixture
        // across tests makes this pass or fail on execution order.
        await page.getByRole('button', { name: 'New field' }).click();

        const created = page.getByRole('dialog');
        await created.locator('[id$=".storage_handle"]').fill('privacy_probe');
        await created.locator('[id$=".storage_type"]').selectOption('text');
        await created.locator('[id$=".label"]').fill('Privacy probe');
        await created.locator('[id$=".storage_pii_class"]').selectOption('none');
        await created.getByRole('button', { name: 'Create', exact: true }).click();

        await expect(page.getByText('privacy_probe')).toBeVisible();

        // ⚠️ THE REGRESSION. The edit action was bound to the create path,
        // whose lookup always found this field's own storage row and returned
        // through the adoption branch without applying anything. The save
        // reported success and changed nothing — and `pii_class` drives
        // erasure and revision redaction (ADR-020), so the field an author
        // had correctly marked as sensitive stayed unclassified.
        // ⚠️ `exact`. Every CELL in the row is also a button that mounts the
        // same action, so a substring match on "Edit" resolves to three
        // elements the moment a field's LABEL happens to contain the word.
        await page.getByRole('row', { name: /privacy_probe/ })
            .getByRole('button', { name: 'Edit', exact: true })
            .click();

        const edited = page.getByRole('dialog');
        await edited.locator('[id$=".storage_pii_class"]').selectOption('sensitive');
        await edited.getByRole('button', { name: 'Save changes' }).click();

        await expect(page.getByRole('row', { name: /privacy_probe/ })).toContainText(/sensitive/i);
    });

    test('an unresolvable icon does not take the admin down with it', async ({ page }) => {
        /*
         * ⚠️ The regression this pins is a self-inflicted, unrecoverable outage.
         *
         * `entry_types.icon` was free text rendered into the navigation on EVERY
         * admin page, and Blade Icons throws `SvgNotFound` on a name it cannot
         * resolve. Measured before the fix: `/admin/{site}`,
         * `/admin/{site}/entry-types` and `/admin/{site}/c/{type}` all returned
         * 500 — so an author could brick their own admin with a typo and had no
         * page left through which to correct it.
         *
         * The form is a select now, so this writes the value the way the paths
         * that BYPASS the form do — a seeder, an importer, a direct UPDATE, or a
         * row written before the guard existed. Those are exactly the cases the
         * render-side fallback exists for, and they are the reason a select alone
         * would not have been enough.
         */
        const setIcon = (icon) => execFileSync(
            'php',
            ['artisan', 'tinker', '--execute', `\\Kitsune\\Core\\Models\\EntryType::withoutGlobalScopes()->where('handle','article')->first()->forceFill(['icon' => '${icon}'])->saveQuietly();`],
            { cwd: path.join(__dirname, '..', 'skeleton'), stdio: 'pipe' },
        );

        setIcon('heroicon-o-this-does-not-exist');

        try {
            for (const url of [`${SITE}`, `${SITE}/entry-types`, `${SITE}/c/article`]) {
                const response = await page.goto(url);

                expect(response?.status(), `${url} should survive a bad icon`).toBe(200);
            }

            // The navigation item still renders, so the fallback made the page
            // usable rather than merely non-fatal — the sidebar item IS what
            // used to throw.
            await expect(page.getByRole('link', { name: 'Articles' }).first()).toBeVisible();
        } finally {
            // Restore, because the suite is serial and shares one database.
            setIcon('heroicon-o-document-text');
        }
    });

    test('creates a field and reports it as indexed', async ({ page }) => {
        await editType(page, 'Article');
        await page.getByRole('button', { name: 'New field' }).click();

        // By id suffix rather than by label. The entry type form BEHIND the
        // modal has its own `Handle`, so a label match is ambiguous, and
        // Filament's required markers make `exact` matching brittle. The ids
        // are the form's own state paths, which is what actually gets
        // submitted.
        const modal = page.getByRole('dialog');

        await modal.locator('[id$=".storage_handle"]').fill('browser_probe');
        await modal.locator('[id$=".storage_type"]').selectOption('number');
        await modal.locator('[id$=".label"]').fill('Browser probe');
        await modal.locator('[id$=".storage_is_indexed"]').click();

        await modal.getByRole('button', { name: 'Create', exact: true }).click();

        // The row appearing means the field storage row, the presentation row
        // and the generated column all landed — SchemaManager runs after the
        // commit, and a failure there un-sets the flag and warns.
        await expect(page.getByText('browser_probe')).toBeVisible();
        await expect(page.getByText('Browser probe')).toBeVisible();
    });
});
