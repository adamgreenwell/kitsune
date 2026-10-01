// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

/*
 * An entry list's selection deleted, restored or deleted forever — ADR-042 decisions 35 and 36: at most fifty at a time,
 * each entry on its own within the request's budget, in one notification of Kitsune's — a media list's, and an article
 * list's, whose *Select all* selects every row across pages.
 *
 * ⚠️ THE PAGE, BECAUSE THAT IS WHERE IT CAN FAIL. The PHP suite drives each handler, its refusals and every crash point;
 * only a browser says Filament resolves what the handlers ask of it in a real Livewire request, that its own notices stay
 * away, that a selection made across pages is counted before it is submitted, and that what is refused stays selected.
 *
 * ⚠️ THIS SPEC'S OWN ENTRIES, found by their titles and removed again, force-deleted with their org in context, because
 * the suite shares one database and the other specs count what the lists hold.
 */

const PROBE = 'Bulk removal probe';

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        cwd: path.join(__dirname, '..', 'skeleton'),
        encoding: 'utf8',
    }).trim();
}

/** Golfdom's org and site in context, as a request to its admin would have them. */
const IN_GOLFDOM = "$site = \\Kitsune\\Core\\Models\\Site::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('slug', 'golfdom')->firstOrFail());"
    + ' app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($site->org_id));'
    + ' app(\\Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);';

/** A 1×1 PNG. */
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** Public PNGs stored as an upload stores them, this site's own, in one call — trashed where asked; their paths. */
function storedPngs(titles, trashed = false) {
    return JSON.parse(tinker(IN_GOLFDOM
        + " $type = \\Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'image')->whereNull('org_id')->firstOrFail());"
        + ' $paths = [];'
        + ` foreach (${JSON.stringify(titles)} as $title) {`
        + " $source = tempnam(sys_get_temp_dir(), 'kitsune-bulk-removal-e2e-'); file_put_contents($source, base64_decode('" + PNG_BASE64 + "'));"
        + " try { $entry = \\Kitsune\\Core\\Media\\MediaLibrary::store($source, 'probe.png', $type, 'public', $title, true); } finally { @unlink($source); }"
        + (trashed ? ' $entry->delete();' : '')
        + " $paths[] = \\Illuminate\\Support\\Facades\\DB::table('media_files')->where('entry_id', $entry->id)->value('path'); }"
        + ' echo json_encode($paths);'));
}

/** Drafts of golfdom's own article type, titled so, made in one call. */
function articles(titles) {
    tinker(IN_GOLFDOM
        + " $type = \\Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'article')->where('org_id', $site->org_id)->firstOrFail());"
        + ` foreach (${JSON.stringify(titles)} as $title) { \\Kitsune\\Core\\Models\\Entry::create(['entry_type_id' => $type->id, 'title' => $title, 'status' => 'draft']); }`);
}

/** Where an entry is — live, trashed or gone — by its title. */
function whereIs(title) {
    return tinker("$row = \\Illuminate\\Support\\Facades\\DB::table('entries')->where('title', " + JSON.stringify(title) + ")->first(['deleted_at']);"
        + " echo $row === null ? 'gone' : ($row->deleted_at === null ? 'live' : 'trashed');");
}

/** How many of the entries titled so are in the trash. */
function trashedLike(prefix) {
    return Number(tinker("echo \\Illuminate\\Support\\Facades\\DB::table('entries')->where('title', 'like', " + JSON.stringify(`${prefix}%`) + ")->whereNotNull('deleted_at')->count();"));
}

function removeFixtures() {
    tinker("\\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing a browser-test fixture', fn ($q) => $q->withTrashed()->where('title', 'like', "
        + JSON.stringify(`${PROBE} %`) + ")->get())"
        + "->each(function ($entry) { app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($entry->org_id)); $entry->forceDelete(); });");
}

const status = async (page, filePath) => (await page.request.get(`/storage/${filePath}`)).status();

/** The open modal holding this submit button. */
const modal = (page, submit) => page.locator('.fi-modal-window').filter({ has: page.getByRole('button', { name: submit, exact: true }) }).last();

/** A notification, by its words. */
const notice = (page, words) => page.locator('.fi-no-notification').filter({ hasText: words });

/** A card of the list, by its title. */
const card = (page, title) => page.locator('.fi-ta-record').filter({ hasText: title });

/** Filament's *N records selected* bar, which shows while anything is selected. */
const selection = (page) => page.locator('.fi-ta-selection-indicator');

/** By what Filament binds it to, not by its words, which it pads with whitespace; two per-page selects render. */
const perPage = (page) => page.locator('.fi-ta select[wire\\:model\\.live="tableRecordsPerPage"]').filter({ visible: true }).first();

/**
 * The image list, searched down to these words and showing `count` cards — never the page's select-all on the whole
 * list: golfdom holds other specs' files.
 *
 * ⚠️ AND SETTLED BEFORE ANYTHING IS SELECTED: a search clears the selection when its debounced update lands, so a card
 * ticked before it would be unticked under the test. Its response arriving is not its being applied, so the cards are
 * waited on too.
 */
async function listed(page, words, count) {
    await page.goto('/admin/golfdom/c/image');
    const searched = page.waitForResponse((response) => response.url().includes('/livewire') && (response.request().postData() ?? '').includes(words));
    await page.locator('.fi-ta').getByPlaceholder('Search').fill(words);
    await searched;
    await expect(page.locator('.fi-ta-record')).toHaveCount(count);
}

/** The article list, searched down to these words and showing `count` rows, settled as `listed()` settles the cards. */
async function listedArticles(page, words, count) {
    await page.goto('/admin/golfdom/c/article');
    const searched = page.waitForResponse((response) => response.url().includes('/livewire') && (response.request().postData() ?? '').includes(words));
    await page.locator('.fi-ta').getByPlaceholder('Search').fill(words);
    await searched;
    await expect(page.locator('.fi-ta-record-checkbox')).toHaveCount(count);
}

/** The list's *Bulk actions* menu, opened, and the action in it clicked. */
async function bulkAction(page, label) {
    await page.getByRole('button', { name: /bulk actions/i }).click();
    await page.getByRole('button', { name: label, exact: true }).click();
}

/** Show the list's trash, as Filament's filter shows it: '' not in the trash, '1' everything, '0' only the trash. */
async function showTrash(page, value) {
    // Exactly: a search shows its own *Remove filter* beside it.
    await page.getByRole('button', { name: 'Filter', exact: true }).click();
    await page.locator('select[wire\\:model="tableDeferredFilters.trashed.value"]').selectOption(value);
    await page.getByRole('button', { name: 'Apply filters' }).click();
    // Filament keeps its filter panel open once applied, over the cards.
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Apply filters' })).toBeHidden();
}

test.describe.configure({ mode: 'serial' });

test.beforeEach(() => removeFixtures());

test.afterAll(() => removeFixtures());

/* A selection deleted whole: one notification, Kitsune's, each file off the web, and the selection cleared. */
test('deletes a selection in one notification, takes each file off the web, and clears it', async ({ page }) => {
    const titles = [`${PROBE} delete one`, `${PROBE} delete two`];
    const paths = storedPngs(titles);
    // The control: each URL answers before the delete, so a refusal after it is the delete's.
    for (const filePath of paths) {
        expect(await status(page, filePath)).toBe(200);
    }

    await listed(page, `${PROBE} delete`, 2);
    for (const title of titles) {
        await card(page, title).getByRole('checkbox').check();
    }
    await expect(selection(page)).toContainText('2 records selected');

    await bulkAction(page, 'Delete selected');
    await modal(page, 'Delete').getByRole('button', { name: 'Delete', exact: true }).click();

    await expect(notice(page, '2 entries were deleted')).toBeVisible({ timeout: 30_000 });
    // Filament's own *Deleted* would be a second.
    await expect(page.locator('.fi-no-notification')).toHaveCount(1);
    await expect(selection(page)).toBeHidden();

    for (const [i, title] of titles.entries()) {
        expect(whereIs(title)).toBe('trashed');
        expect(await status(page, paths[i])).not.toBe(200);
    }
});

/*
 * ⚠️ FIFTY-ONE, SELECTED ACROSS TWO PAGES — the page's select-all box selects the page in view alone. The modal says
 * before anything is submitted that nothing will be deleted; submitted, nothing is, and all fifty-one stay selected.
 *
 * ⚠️ AND TEN A PAGE AGAIN AFTERWARDS, because Filament keeps the choice in the session, which every spec here shares
 * through one signed-in state.
 */
test('says before it is submitted that more than fifty will not be deleted, refuses them, and keeps them selected', async ({ page }) => {
    const titles = Array.from({ length: 51 }, (_, i) => `${PROBE} many ${String(i + 1).padStart(2, '0')}`);
    storedPngs(titles);

    try {
        await listed(page, `${PROBE} many`, 10);
        await perPage(page).selectOption('50');
        await expect(page.locator('.fi-ta-record')).toHaveCount(50);
        await page.locator('.fi-ta-page-checkbox').filter({ visible: true }).first().check();
        await expect(selection(page)).toContainText('50 records selected');

        await page.getByRole('button', { name: 'Next' }).click();
        await expect(page.locator('.fi-ta-record')).toHaveCount(1);
        const last = page.locator('.fi-ta-record').first();
        await last.getByRole('checkbox').check();
        await expect(selection(page)).toContainText('51 records selected');

        await bulkAction(page, 'Delete selected');
        const dialog = modal(page, 'Delete');
        await expect(dialog).toContainText('At most 50 entries are deleted at a time, and 51 are selected, so as it is nothing will be deleted. Select fewer first.');
        await dialog.getByRole('button', { name: 'Delete', exact: true }).click();

        const said = notice(page, 'Too many entries are selected');
        await expect(said).toBeVisible({ timeout: 30_000 });
        await expect(said).toContainText('More than 50 entries are selected, and at most 50 are deleted at a time, so nothing was deleted.');
        await expect(page.locator('.fi-no-notification')).toHaveCount(1);

        expect(trashedLike(`${PROBE} many`)).toBe(0);
        await expect(last.getByRole('checkbox')).toBeChecked();
        await expect(selection(page)).toContainText('51 records selected');
    } finally {
        await page.goto('/admin/golfdom/c/image');
        // Kept once the server has it, which the select's own value does not say.
        const stored = page.waitForResponse((response) => response.url().includes('/livewire') && (response.request().postData() ?? '').includes('tableRecordsPerPage'));
        await perPage(page).selectOption('10');
        await stored;
        await expect(perPage(page)).toHaveValue('10');
    }
});

/*
 * ⚠️ AND A RESTORED PUBLIC FILE IS ON THE WEB AGAIN, at its URL, as decision 5 publishes it after each restore commits —
 * asked of the web server, with the control that the trash took it off first. Then one deleted forever from the trash,
 * its modal saying first what that takes.
 */
test('restores a selection from the trash, puts each file back on the web, and deletes another forever', async ({ page }) => {
    const restored = [`${PROBE} trash one`, `${PROBE} trash two`];
    const erased = `${PROBE} trash erase`;
    const paths = storedPngs([...restored, erased], true);
    for (const filePath of paths) {
        expect(await status(page, filePath)).not.toBe(200);
    }

    await listed(page, `${PROBE} trash`, 0);
    await showTrash(page, '0');
    await expect(page.locator('.fi-ta-record')).toHaveCount(3);
    for (const title of restored) {
        await card(page, title).getByRole('checkbox').check();
    }

    await bulkAction(page, 'Restore selected');
    await modal(page, 'Restore').getByRole('button', { name: 'Restore', exact: true }).click();

    const said = notice(page, '2 entries were restored');
    await expect(said).toBeVisible({ timeout: 30_000 });
    // Restored and published: *"…, and their files are not yet published"* would begin with the same words.
    await expect(said).not.toContainText('not yet published');
    // Filament's own *Restored* would be a second.
    await expect(page.locator('.fi-no-notification')).toHaveCount(1);
    for (const [i, title] of restored.entries()) {
        expect(whereIs(title)).toBe('live');
        await expect.poll(() => status(page, paths[i])).toBe(200);
    }

    await expect(page.locator('.fi-ta-record')).toHaveCount(1);
    await card(page, erased).getByRole('checkbox').check();
    await bulkAction(page, 'Delete selected forever');
    const dialog = modal(page, 'Delete forever');
    await expect(dialog).toContainText('This cannot be undone. The entry, its file and its history are deleted for good, and any link to it from another entry is removed.');
    await dialog.getByRole('button', { name: 'Delete forever', exact: true }).click();

    await expect(notice(page, 'One entry was deleted forever')).toBeVisible({ timeout: 30_000 });
    expect(whereIs(erased)).toBe('gone');
    expect(await status(page, paths[2])).not.toBe(200);
});

/*
 * ⚠️ AN ARTICLE LIST'S *SELECT ALL* SELECTS EVERY ROW IT HOLDS, ACROSS PAGES — decision 36, and refused above fifty, the
 * modal saying so before it is submitted; with five deselected, the fifty are deleted in one notification, and restored in
 * another. Ten a page throughout, so the per-page choice every list shares is never changed.
 */
test('selects every article the list holds, refuses more than fifty, and deletes and restores fifty in one notification each', async ({ page }) => {
    const words = `${PROBE} article`;
    articles(Array.from({ length: 55 }, (_, i) => `${words} ${String(i + 1).padStart(2, '0')}`));

    await listedArticles(page, words, 10);
    await page.locator('.fi-ta-record-checkbox').first().check();
    await page.getByRole('button', { name: 'Select all 55', exact: true }).click();
    await expect(selection(page)).toContainText('55 records selected');

    await bulkAction(page, 'Delete selected');
    const tooMany = modal(page, 'Delete');
    await expect(tooMany).toContainText('At most 50 entries are deleted at a time, and 55 are selected, so as it is nothing will be deleted. Select fewer first.');
    await tooMany.getByRole('button', { name: 'Delete', exact: true }).click();

    const refused = notice(page, 'Too many entries are selected');
    await expect(refused).toBeVisible({ timeout: 30_000 });
    await expect(refused).toContainText('More than 50 entries are selected, and at most 50 are deleted at a time, so nothing was deleted.');
    await expect(page.locator('.fi-no-notification')).toHaveCount(1);
    expect(trashedLike(words)).toBe(0);
    await expect(selection(page)).toContainText('55 records selected');

    for (let i = 0; i < 5; i++) {
        await page.locator('.fi-ta-record-checkbox').nth(i).uncheck();
    }
    await expect(selection(page)).toContainText('50 records selected');

    await bulkAction(page, 'Delete selected');
    const fifty = modal(page, 'Delete');
    await expect(fifty).toBeVisible();
    await expect(fifty).not.toContainText('At most 50');
    await fifty.getByRole('button', { name: 'Delete', exact: true }).click();

    await expect(notice(page, '50 entries were deleted')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('.fi-no-notification').filter({ hasText: 'entries were deleted' })).toHaveCount(1);
    await expect(selection(page)).toBeHidden();
    expect(trashedLike(words)).toBe(50);
    await expect(page.locator('.fi-ta-record-checkbox')).toHaveCount(5);

    await listedArticles(page, words, 5);
    await showTrash(page, '0');
    await expect(page.locator('.fi-ta-record-checkbox')).toHaveCount(10);
    await page.locator('.fi-ta-record-checkbox').first().check();
    await page.getByRole('button', { name: 'Select all 50', exact: true }).click();
    await expect(selection(page)).toContainText('50 records selected');

    await bulkAction(page, 'Restore selected');
    await modal(page, 'Restore').getByRole('button', { name: 'Restore', exact: true }).click();

    const restored = notice(page, '50 entries were restored');
    await expect(restored).toBeVisible({ timeout: 30_000 });
    await expect(restored).not.toContainText('not yet published');
    await expect(page.locator('.fi-no-notification')).toHaveCount(1);
    expect(trashedLike(words)).toBe(0);
});
