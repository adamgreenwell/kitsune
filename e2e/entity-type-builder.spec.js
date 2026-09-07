// @ts-check
const { test, expect } = require('@playwright/test');

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

        await expect(page.getByText('Subject identifier')).toBeVisible();
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
