// @ts-check
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

/*
 * Shared media, measured where Filament's scope actually runs — ADR-042 decision 2.
 *
 * ⚠️ THE BROWSER, BECAUSE THIS IS FILAMENT'S BOUNDARY. `EntryResource::scopeEloquentQueryToTenant()` widens the tenant
 * scope Filament registers when the real panel boots, and its creation hook replaces Filament's. The PHP suite registers
 * the same scope through `PanelTenancy` and pins the rule row for row; this file asserts the outcome at the URLs an editor
 * uses — listed, opened, served, offered by a picker and accepted by a save — on the panel as it really boots.
 *
 * The fixtures, which `global-setup.js` refuses to run without: "Shared course photo" is shared across Golfdom's org,
 * "Course map" is kept to `golfdom`, the rival's file is shared across the rival's org, and the global `image` type is
 * switched off at `golfdom-nested`.
 *
 * ⚠️ EVERY REFUSAL HAS A CONTROL. "Not listed" is also what a list that failed to render says, and "404" is what a file
 * that was never seeded says; each is paired with the same thing succeeding where it should.
 */

const RIVAL_STATE = '.playwright/admin-rival-auth.json';

/* Read inside each test, for the reason `media-delivery.spec.js` gives: collection runs before global setup. */
function media() {
    return JSON.parse(fs.readFileSync(path.join(__dirname, '..', '.playwright', 'media-fixture.json'), 'utf8'));
}

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        cwd: path.join(__dirname, '..', 'skeleton'),
        encoding: 'utf8',
    }).trim();
}

/**
 * Types into a searchable select and waits for the server's answer.
 *
 * ⚠️ BY THE RESPONSE, NOT BY THE WORDS. The admin speaks each site's language — `golfdom-fr` is French and
 * `golfdom-nested` Hebrew — so Filament's "No options match your search." is not on those pages to wait for, and a
 * negative assertion made before the answer arrives passes against a dropdown that simply has not filled yet. The
 * search is one debounced Livewire request; once it has answered, what the dropdown shows is the answer.
 */
async function search(page, label, term, within = page) {
    await within.getByRole('combobox', label === null ? {} : { name: label }).first().click();

    const answered = page.waitForResponse(
        (response) => response.url().includes('/livewire') && response.request().method() === 'POST',
    );

    await page.keyboard.type(term);
    await answered;
}

/** A string from the admin's own translations, in a site's language — the admin speaks each site's. */
const translations = new Map();

function translated(key, locale) {
    const cacheKey = `${locale}:${key}`;

    if (! translations.has(cacheKey)) {
        translations.set(cacheKey, tinker(`echo __('${key}', [], '${locale}');`));
    }

    return translations.get(cacheKey);
}

/**
 * The options in the open picker's dropdown — not every `option` on the page, which includes the native `<option>`s of
 * the Status select.
 */
const dropdownOptions = (page) => page.locator('[id^="fi-select-input-dropdown-"]:visible').getByRole('option');

/**
 * Nothing offered, and the dropdown saying so.
 *
 * ⚠️ BY THE "NO RESULTS" WORDS THEMSELVES, in the site's language, and review found why nothing looser will do. The
 * select shows "Searching…" in the same element while the answer is still arriving, with the options taken out — so a
 * check for "a message and no options" passed on that frame, before the server had said whether anything matched.
 */
async function expectNoOptions(page, locale) {
    const none = translated('filament-forms::components.select.no_search_results_message', locale);

    await expect(page.locator('.fi-select-input-message:visible').first()).toHaveText(none, { timeout: 10_000 });
    await expect(dropdownOptions(page)).toHaveCount(0);
}

test.describe('a shared file travels across the org', () => {
    test('is listed at a second site, where a file kept to the first is not', async ({ page }) => {
        await page.goto('/admin/golfdom-fr/c/image');

        await expect(page.locator('.fi-ta-row').filter({ hasText: 'Shared course photo' })).toBeVisible();
        await expect(page.getByText('Course map')).toHaveCount(0);

        // The control: the first site lists both.
        await page.goto('/admin/golfdom/c/image');

        await expect(page.locator('.fi-ta-row').filter({ hasText: 'Shared course photo' })).toBeVisible();
        await expect(page.locator('.fi-ta-row').filter({ hasText: 'Course map' })).toBeVisible();
    });

    test('is served and opened at a second site, where a file kept to the first answers 404', async ({ page }) => {
        const { sharedPhotoId, courseMapId } = media();

        await page.goto('/admin/golfdom-fr');

        expect((await page.request.get(`/admin/golfdom-fr/media/${sharedPhotoId}`)).status()).toBe(200);
        expect((await page.request.get(`/admin/golfdom-fr/media/${courseMapId}`)).status()).toBe(404);
        expect((await page.request.get(`/admin/golfdom/media/${courseMapId}`)).status()).toBe(200);

        expect((await page.goto(`/admin/golfdom-fr/c/image/${sharedPhotoId}/edit`))?.status()).toBe(200);
        expect((await page.goto(`/admin/golfdom-fr/c/image/${courseMapId}/edit`))?.status()).toBe(404);
        expect((await page.goto(`/admin/golfdom/c/image/${courseMapId}/edit`))?.status()).toBe(200);
    });

    /* ADR-021: an org-shared entry is not publicly addressable, so its edit page offers no slug to give it one. */
    test('offers no slug on a shared file, and does on one kept to a site', async ({ page }) => {
        const { sharedPhotoId, courseMapId } = media();

        await page.goto(`/admin/golfdom/c/image/${sharedPhotoId}/edit`);
        await expect(page.locator('[id="form.title"]')).toBeVisible();
        await expect(page.locator('[id="form.slug"]')).toHaveCount(0);

        await page.goto(`/admin/golfdom/c/image/${courseMapId}/edit`);
        await expect(page.locator('[id="form.slug"]')).toBeVisible();
    });

    test('is offered by a picker at a second site, and the choice is saved', async ({ page }) => {
        try {
            await page.goto('/admin/golfdom-fr/c/product/create');
            await page.locator('[id="form.title"]').fill('Sharing probe');

            await search(page, /Photo/, 'course');

            const offered = page.getByRole('option', { name: 'Shared course photo' });
            await expect(offered).toBeVisible({ timeout: 10_000 });
            await expect(page.getByRole('option', { name: 'Course map' })).toHaveCount(0);

            await offered.click();

            // Submitted from the title field: the Create button is labelled in the site's language.
            await page.locator('[id="form.title"]').press('Enter');
            await page.waitForURL(/\/c\/product\/\d+$/);

            // The edit page, which hydrates relation fields from what was stored; the view page Filament lands on does not.
            await page.goto(`${page.url()}/edit`);
            await expect(page.getByRole('combobox', { name: /Photo/ }).first()).toContainText('Shared course photo');
        } finally {
            // The suite shares one database, and a stray product changes what the product specs count.
            // A force-delete is audited, so it runs with the entry's own org in context.
            tinker("\\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing a browser-test fixture', fn ($q) => $q->where('title', 'Sharing probe')->get())"
                + "->each(function ($entry) { app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($entry->org_id)); $entry->forceDelete(); });");
        }
    });
});

test.describe('the media list, and a shared file\'s links', () => {
    /*
     * ADR-042's measurement: a media list's count walks every site's files of the type, so it pages with Previous and
     * Next and states no total. The article list is the control — it still says how many there are.
     */
    test('pages a media list without a total, and an article list with one', async ({ page }) => {
        await page.goto('/admin/golfdom/c/image');
        await expect(page.locator('.fi-ta-row').first()).toBeVisible();
        await expect(page.getByText(/Showing \d+ to \d+ of \d+/)).toHaveCount(0);

        // Counted, not required visible: Filament hides the overview at narrow widths, and it is there to count.
        await page.goto('/admin/golfdom/c/article');
        await expect(page.locator('.fi-ta-row').first()).toBeVisible();
        await expect(page.getByText(/Showing \d+ to \d+ of \d+/)).toHaveCount(1);
    });

    /*
     * ⚠️ A LINK THIS SITE CANNOT SEE SURVIVES A SAVE HERE — decided by Adam. The shared photo links to an article only
     * `golfdom` sees. At `golfdom-fr` the form used to be hydrated through the scoped join, which left that link out, and
     * the save detached it without anyone having seen it. It is shown withheld now, and kept.
     */
    test('keeps a link it cannot show when a shared file is saved at a second site', async ({ page }) => {
        const { sharedPhotoId, hiddenTargetId } = media();

        await page.goto(`/admin/golfdom-fr/c/image/${sharedPhotoId}/edit`);
        await expect(page.getByRole('combobox', { name: /Subjects/ }).first())
            .toContainText(`Entry #${hiddenTargetId} — not visible from this site`);

        // Saved from the title field, as `golfdom-fr`'s buttons are in French; the save notice is what confirms it.
        await page.locator('[id="form.title"]').press('Enter');
        await expect(page.locator('.fi-no-notification').first()).toBeVisible({ timeout: 15_000 });

        const links = JSON.parse(tinker(`echo json_encode(DB::table('entry_relations')->where('source_entry_id', ${Number(sharedPhotoId)})->pluck('target_entry_id'));`));

        expect(links.map(Number)).toContain(Number(hiddenTargetId));
    });
});

test.describe('where a shared file must not reach', () => {
    /* ADR-022: the widened scope applies only together with the site's type availability. */
    test('is neither listed, served nor offered where its type is switched off', async ({ page }) => {
        const { sharedPhotoId } = media();

        expect((await page.goto('/admin/golfdom-nested/c/image'))?.status()).toBe(404);
        expect((await page.request.get(`/admin/golfdom-nested/media/${sharedPhotoId}`)).status()).toBe(404);

        await page.goto('/admin/golfdom-nested/c/product/create');
        await search(page, /Photo/, 'course');
        await expectNoOptions(page, 'he');
        await expect(dropdownOptions(page).filter({ hasText: 'Shared course photo' })).toHaveCount(0);

        // The control: the same picker, the same search, at a site where the type is on.
        await page.goto('/admin/golfdom-fr/c/product/create');
        await search(page, /Photo/, 'course');
        await expect(page.getByRole('option', { name: 'Shared course photo' })).toBeVisible({ timeout: 10_000 });
    });

    test('is refused, and not offered, on another org\'s site', async ({ browser }) => {
        const { sharedPhotoId, rivalFileId } = media();
        const rival = await browser.newContext({ storageState: RIVAL_STATE });
        const page = await rival.newPage();

        try {
            expect((await page.request.get(`/admin/rival-golfdom/media/${sharedPhotoId}`)).status()).toBe(404);
            expect((await page.request.get(`/admin/rival-golfdom/media/${rivalFileId}`)).status()).toBe(200);

            await page.goto('/admin/rival-golfdom/c/confidential/create');
            await search(page, /Cover/, 'course');
            await expectNoOptions(page, 'en');

            // The control: the rival's own shared file is offered by the same picker.
            await page.keyboard.press('Escape');
            await page.goto('/admin/rival-golfdom/c/confidential/create');
            await search(page, /Cover/, 'Rival');
            await expect(page.getByRole('option', { name: 'Rival private asset' })).toBeVisible({ timeout: 10_000 });
        } finally {
            await rival.close();
        }
    });

    /*
     * ADR-042: not offered by the Attach dialog of a site where its type is switched off — and offered where it is on.
     *
     * ⚠️ EACH DIALOG MUST ANSWER WITH SOMETHING, or an empty one proves nothing: the dialog's search threw on every
     * term until this test searched in it. At `golfdom-nested` the note itself matches "course", so the search is seen
     * to run and the shared photo is seen to be missing from what it returned.
     */
    test('is not offered by the Attach dialog where its type is switched off', async ({ page }) => {
        const { nestedNoteId, frNoteId } = media();

        await page.goto(`/admin/golfdom-nested/c/article/${nestedNoteId}/related`);
        await page.getByRole('button', { name: translated('filament-actions::attach.single.label', 'he'), exact: true }).first().click();
        await search(page, null, 'course', page.getByRole('dialog'));
        await expect(dropdownOptions(page).filter({ hasText: 'Nested course note' })).toBeVisible({ timeout: 10_000 });
        await expect(dropdownOptions(page).filter({ hasText: 'Shared course photo' })).toHaveCount(0);

        // The control: the same dialog, the same search, at a site where the type is on.
        await page.goto(`/admin/golfdom-fr/c/article/${frNoteId}/related`);
        await page.getByRole('button', { name: translated('filament-actions::attach.single.label', 'fr'), exact: true }).first().click();
        await search(page, null, 'course', page.getByRole('dialog'));
        await expect(dropdownOptions(page).filter({ hasText: 'Shared course photo' })).toBeVisible({ timeout: 10_000 });
    });
});
