// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * A relation field saves to `entry_relations` and comes back — issue #39's relational leg.
 *
 * ⚠️ THIS HAS TO BE A BROWSER TEST. The defect it guards lives in the SAVE LIFECYCLE, not
 * in the renderer. A relation's state deliberately sits outside the model's attributes
 * (ADR-015 forbids entry IDs in the `values` JSON), so the page carries it separately and
 * writes it after the entry exists. No unit test on the component tree can see whether that
 * plumbing works — and while it was broken the only symptom was three UNRELATED revision
 * tests failing, because the save they depended on had stopped working at all.
 */

const SITE = 'golfdom';

/** The picker control itself, which is where a selected value is rendered. */
const picker = (page) => page.locator('[id="form.relations.related_articles"]');

/**
 * Chooses an option in a Filament searchable select.
 *
 * ⚠️ Three steps, and skipping any finds nothing. The control is a `button[role="combobox"]`
 * rather than a `<select>`; clicking it reveals a search field; and the options list is
 * EMPTY until something is typed, because the picker resolves matches through
 * `getSearchResultsUsing()` rather than preloading an org's entire entry table.
 *
 * ⚠️ TYPED WITH THE KEYBOARD, not into a located input. `input[type="search"]` is Filament's
 * GLOBAL search box at the top of the page — the picker's own field is `type="text"` with
 * placeholder "Start typing to search…". Filling the first `[type=search]` typed into the
 * wrong control entirely, so no options appeared and the picker looked broken while working
 * correctly. The keyboard goes wherever focus already is.
 */
async function choose(page, term, optionPattern) {
    await page.getByRole('combobox', { name: /Related articles/ }).first().click();
    await page.keyboard.type(term);

    const option = page.getByRole('option', { name: optionPattern }).first();
    await expect(option).toBeVisible({ timeout: 10_000 });
    await option.click();
}

/**
 * Removes every selection currently in the picker, in the form only.
 *
 * ⚠️ SCOPED TO THE PICKER, because `page.locator('[aria-label^="Remove"]')` is page-wide and
 * this form is going to grow. Filament renders the chips and their removal controls INSIDE
 * the combobox button, so scoping there cannot reach another field's controls — an unscoped
 * version happens to match only one control against today's seed, and would silently start
 * clearing a neighbouring field the day one is added.
 *
 * Returns how many it removed, which is worth asserting on: a removal control only exists if
 * a value actually hydrated into a chip.
 */
async function removeSelections(page) {
    const removals = picker(page).locator('[aria-label^="Remove"]');
    const count = await removals.count();

    for (let i = count - 1; i >= 0; i--) {
        await removals.nth(i).click();
    }

    return count;
}

/**
 * Clicks Save and waits for the entry to actually be written.
 *
 * ⚠️ SCOPED TO THE NOTIFICATION, and `getByText(/Saved/i)` is the trap. This page renders a
 * revisions table whose `created_at` column is LABELLED "Saved" (`RevisionsRelationManager`),
 * and because it is sortable that label is a visible `<button>`. A page-wide text match
 * therefore resolves against a table header that was already on screen, the assertion passes
 * in ~50ms having waited for nothing, and the test races ahead of the request it was meant to
 * wait for.
 *
 * That cost three rounds chasing a hydration bug that did not exist. Timestamped against the
 * server log, the reload arrived 65ms BEFORE the write — so the reload legitimately saw an
 * empty table and the picker legitimately rendered empty. The product was correct throughout;
 * the wait was not.
 *
 * Filament only mounts `.fi-no-notification` once the save response comes back, and
 * `EditRecord::save()` commits before sending it — so this is also the point after which a
 * reload is guaranteed to see the rows.
 */
async function save(page) {
    await page.getByRole('button', { name: /^Save changes$/ }).click();

    await expect(
        page.locator('.fi-no-notification').filter({ hasText: /Saved/ }).first(),
    ).toBeVisible({ timeout: 15_000 });
}

/**
 * Leaves the picker as the suite found it.
 *
 * ⚠️ NOT TIDINESS — these specs share one seeded database with every other spec, and a
 * selection left behind CHANGED ANOTHER SPEC'S RESULT. `rtl.spec.js` audits entry edit with
 * axe; a leftover chip put a `fi-badge` on that page and the audit went red on a colour
 * contrast failure that has nothing to do with relations (tracked separately, and recorded in
 * `accessibility-inventory.md` so a green suite does not imply it is gone).
 *
 * A test that changes what another test measures is a test that reports on the run order.
 */
async function leaveClean(page) {
    if ((await removeSelections(page)) > 0) {
        await save(page);
    }
}

/** The direction the browser resolved for an element, not the attribute it carries. */
async function resolvedDirection(locator) {
    return locator.evaluate((el) => getComputedStyle(el).direction);
}

test.describe('a relation field round-trips through entry_relations', () => {
    test('saves a selection and hydrates it again after a reload', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article/1/edit`);
        await expect(picker(page)).toBeVisible();

        /*
         * ⚠️ Cleared first so the suite survives a re-run without re-seeding. Filament hides
         * an already-selected option, so on a second run `choose()` would time out looking
         * for an option that is missing precisely BECAUSE the last run passed — a green test
         * that fails the second time for a reason unrelated to what it checks.
         */
        await removeSelections(page);
        await choose(page, 'week 3', /week 3/);
        await save(page);

        /*
         * ⚠️ RELOADED, and asserted on the CONTROL rather than the page. An in-page
         * assertion passes on state that never reached the database — which is precisely the
         * bug class here — and `getByText` does not match a value rendered inside the
         * combobox button, which is how my first version of this failed against a working
         * feature.
         */
        await page.reload();
        await expect(picker(page)).toContainText('week 3');

        await leaveClean(page);
    });

    test('clearing the field actually clears it', async ({ page }) => {
        /*
         * ⚠️ The half a naive implementation gets wrong. An empty selection is a legitimate
         * edit, and a sync treating "no value" as "no change" would make clearing impossible
         * — the author would watch the value reappear after every save, which reads as the
         * admin ignoring them.
         */
        await page.goto(`/admin/${SITE}/c/article/2/edit`);
        await expect(picker(page)).toBeVisible();

        await removeSelections(page);
        await choose(page, 'week 4', /week 4/);
        await save(page);
        await page.reload();
        await expect(picker(page)).toContainText('week 4');

        expect(await removeSelections(page)).toBeGreaterThan(0);

        await save(page);
        await page.reload();
        await expect(picker(page)).toContainText('Select an option');
    });

    test('an Arabic-titled target resolves right-to-left in the picker', async ({ page }) => {
        /*
         * The picker's own direction, on a real value rather than a placeholder — this is the
         * control `Control::EntryPicker` marks `Auto` because it shows entry TITLES, and the
         * related-records table was one of the two places #39 originally missed.
         */
        await page.goto(`/admin/${SITE}/c/article/3/edit`);
        await expect(picker(page)).toBeVisible();

        await removeSelections(page);
        await choose(page, 'صيانة', /صيانة الملاعب/);
        await save(page);
        await page.reload();
        await expect(picker(page)).toContainText('صيانة الملاعب');

        /*
         * ⚠️ MEASURED, not asserted from the attribute. `dir="auto"` can be present and
         * resolve the wrong way — the mistake the original RTL check made — so the thing
         * under test is what the browser actually resolved.
         *
         * This is also the case the documented `<select>` residual does NOT cover. An empty
         * picker resolves LTR because Filament emits "Select an option" first, so the first
         * strong directional character is Latin however Arabic the options are. Once a value
         * is chosen the placeholder is gone and the Arabic title is all that is left.
         */
        expect(await resolvedDirection(picker(page))).toBe('rtl');

        await leaveClean(page);
    });
});
