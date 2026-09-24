// @ts-check
const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

/*
 * Deleting a public file takes it off the web, and a delete that cannot is refused in words — ADR-042 decision 5,
 * measured at the URL and in the admin.
 *
 * ⚠️ THE PHP SUITE CANNOT SEE EITHER HALF. Whether the public URL still answers is the web server's to say, through the
 * storage link; and whether a refused delete reaches the editor as a notification rather than a 500 is Filament's, which
 * no PHP test drives here. Two files the seeder stores for this spec alone, so no other spec loses one.
 *
 * ⚠️ A REFUSAL IS MADE BY TAKING WRITE PERMISSION FROM THE PUBLIC DIRECTORY THAT HOLDS THE FILES: the public copy can be
 * read and copied to the private disk, and cannot be deleted — the refusal the promise exists for. The mode is put
 * back in `finally`, because the directory is shared with the seeded logo other specs fetch.
 */

const SITE = 'golfdom';

test.describe.configure({ mode: 'serial' });

/** Read inside the test: `global-setup.js` writes it, and a top-level read fails collection before it runs. */
function fixture() {
    return JSON.parse(fs.readFileSync(path.join(__dirname, '..', '.playwright', 'media-fixture.json'), 'utf8'));
}

/** Run with the directory holding the scorecards unwritable, and put its mode back whatever happens. */
async function withPublicDiskRefusingDeletes(work) {
    const { publicRoot, pinned } = fixture();
    const directory = path.dirname(path.join(publicRoot, pinned.path));
    const mode = fs.statSync(directory).mode & 0o777;

    fs.chmodSync(directory, 0o555);

    try {
        await work();
    } finally {
        fs.chmodSync(directory, mode);
    }
}

async function confirmDelete(page) {
    const modal = page.locator('.fi-modal-window').filter({ hasText: 'Delete' }).last();
    await expect(modal).toBeVisible();
    await modal.getByRole('button', { name: 'Delete' }).click();
}

test('shows a refused delete as a notification naming the entry, and keeps its file on the web', async ({ page }) => {
    const { pinned } = fixture();

    await withPublicDiskRefusingDeletes(async () => {
        await page.goto(`/admin/${SITE}/c/image/${pinned.id}/edit`);
        await page.getByRole('button', { name: 'Delete' }).first().click();
        await confirmDelete(page);

        await expect(page.getByText('"Pinned scorecard" was not deleted')).toBeVisible();
        // Still on the entry: a refusal is not a success's redirect, and not an error page.
        await expect(page).toHaveURL(new RegExp(`/c/image/${pinned.id}/edit`));
    });

    expect((await page.request.get(`/storage/${pinned.path}`)).status()).toBe(200);
});

test('names every entry a bulk delete could not take off the web', async ({ page }) => {
    const { pinned, withdrawn } = fixture();

    await withPublicDiskRefusingDeletes(async () => {
        await page.goto(`/admin/${SITE}/c/image`);

        for (const title of ['Pinned scorecard', 'Withdrawn scorecard']) {
            await page.locator('.fi-ta-row').filter({ hasText: title }).getByRole('checkbox').check();
        }

        await page.getByRole('button', { name: /bulk actions/i }).click();
        await page.getByRole('button', { name: 'Delete selected' }).click();
        await confirmDelete(page);

        await expect(page.getByText('2 entries were not deleted')).toBeVisible();
        await expect(page.getByText('"Pinned scorecard" was not deleted:')).toBeVisible();
        await expect(page.getByText('"Withdrawn scorecard" was not deleted:')).toBeVisible();
    });

    expect((await page.request.get(`/storage/${pinned.path}`)).status()).toBe(200);
    expect((await page.request.get(`/storage/${withdrawn.path}`)).status()).toBe(200);
});

/*
 * ⚠️ NOT A 404: A 403. A path the storage link does not hold falls through to Laravel's own `/storage/{path}` route for
 * the `local` disk, which answers only a signed request. What matters is that the bytes are not served, and that they
 * are gone from the disk the link exposes — both asserted.
 */
test('takes a deleted public file off the web', async ({ page }) => {
    const { withdrawn, pinned, publicRoot } = fixture();

    // The control: the URL answers before the delete, so a refusal after it is the delete's.
    expect((await page.request.get(`/storage/${withdrawn.path}`)).status()).toBe(200);

    await page.goto(`/admin/${SITE}/c/image/${withdrawn.id}/edit`);
    await page.getByRole('button', { name: 'Delete' }).first().click();
    await confirmDelete(page);

    await expect(page).not.toHaveURL(new RegExp(`/c/image/${withdrawn.id}/edit`));

    expect((await page.request.get(`/storage/${withdrawn.path}`)).status()).not.toBe(200);
    expect(fs.existsSync(path.join(publicRoot, withdrawn.path))).toBe(false);
    expect((await page.request.get(`/storage/${pinned.path}`)).status()).toBe(200);
});
