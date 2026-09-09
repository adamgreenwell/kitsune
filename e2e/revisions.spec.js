// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * Version history, driven the way a person drives it.
 *
 * The restore is the reason this is a browser test rather than only a PHP
 * one: the database was correct and the FORM was not. Restoring left the
 * pre-restore values sitting in the form above, so the next "Save changes"
 * wrote them back and silently undid the restore the user had just watched
 * succeed. A feature test cannot see that, because it never renders the form
 * the restore left behind.
 */

const EDIT = '/admin/golfdom/c/article/1/edit';

/*
 * Click save and wait for the write to land.
 *
 * ⚠️ NOT `expect(getByText('Saved'))`. The History table has a column headed
 * "Saved", so that assertion passed instantly against the header while the
 * save was still in flight — a test that waited for nothing and then measured
 * a count that had not changed yet.
 */
async function save(page) {
    await Promise.all([
        // `livewire`, not `/livewire/`: the endpoint is hash-obfuscated in
        // this app — `/livewire-d936e517/update` — so the slashed form never
        // matched and the wait timed out instead of waiting.
        page.waitForResponse((r) => r.url().includes('livewire') && r.request().method() === 'POST'),
        page.getByRole('button', { name: 'Save changes' }).click(),
    ]);
}

async function openHistory(page) {
    await page.goto(EDIT);
    // The relation manager is a lazy Livewire component below the fold, so
    // it never mounts at 1280x720 unless something scrolls.
    await page.getByRole('button', { name: 'Save changes' }).scrollIntoViewIfNeeded();
    await page.mouse.wheel(0, 1200);
    await expect(page.getByText('History')).toBeVisible();
}

test.describe('revisions', () => {
    test('shows a version for the entry as it stands', async ({ page }) => {
        await openHistory(page);

        await expect(page.getByRole('button', { name: 'Restore' }).first()).toBeVisible();
    });

    test('records a new version when the entry is edited', async ({ page }) => {
        await openHistory(page);
        const before = await page.getByRole('button', { name: 'Restore' }).count();

        await page.getByLabel('Title').fill('Edited in a browser');
        await save(page);

        await openHistory(page);

        expect(await page.getByRole('button', { name: 'Restore' }).count()).toBe(before + 1);
        await expect(page.getByText('Edited in a browser').first()).toBeVisible();
    });

    test('restoring puts the value back IN THE FORM, not only in the database', async ({ page }) => {
        await page.goto(EDIT);
        await page.getByLabel('Title').fill('Before restore');
        await save(page);

        await page.getByLabel('Title').fill('After restore');
        await save(page);

        await openHistory(page);

        // The second row is the "Before restore" version.
        await page.getByRole('row', { name: /Before restore/ }).getByRole('button', { name: 'Restore' }).first().click();
        await page.getByRole('button', { name: 'Confirm' }).click();

        // ⚠️ The assertion that matters. Without the redirect the form still
        // held "After restore", and saving would have undone the restore.
        await expect(page.getByLabel('Title')).toHaveValue('Before restore');
    });

    test('keeps the versions that came after the one restored', async ({ page }) => {
        await openHistory(page);
        const before = await page.getByRole('button', { name: 'Restore' }).count();

        await page.getByRole('button', { name: 'Restore' }).nth(1).click();
        await page.getByRole('button', { name: 'Confirm' }).click();

        await openHistory(page);

        // Restoring ADDS a version. Rewriting history would make "what did
        // this say last Tuesday" unanswerable, which is the whole point.
        expect(await page.getByRole('button', { name: 'Restore' }).count()).toBe(before + 1);
    });

    test('offers no way to delete a version', async ({ page }) => {
        await openHistory(page);

        // Erasure goes through redactField(), which replaces a value in place
        // so the history of WHAT CHANGED WHEN survives an erasure of WHAT IT
        // SAID (ADR-020). Deleting a revision destroys both.
        await expect(page.getByRole('button', { name: 'Delete selected' })).toHaveCount(0);
    });
});
