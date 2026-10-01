// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

/*
 * The trash — ADR-042 decision 31: an entry list's trashed entries, restored or deleted forever, in the admin.
 *
 * ⚠️ THE PAGE, BECAUSE THAT IS WHERE IT CAN FAIL. The PHP suite drives each action's own code; only a browser says the
 * filter lists the trash, that a trashed row offers Restore and Delete forever where a live one offers View and Edit,
 * that Filament resolves a trashed record for its action at all, and that a restored public file answers at its URL.
 *
 * ⚠️ THIS SPEC'S OWN ENTRIES, found by their titles and removed again, force-deleted with their org in context, because
 * the suite shares one database and the other specs count what the lists hold.
 */

const SITE = 'golfdom';
const PROBE = 'Trash probe';

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

/** An article of golfdom's, a draft, trashed or not. */
function article(title, trashed) {
    tinker(IN_GOLFDOM
        + " $type = \\Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'article')->where('org_id', $site->org_id)->firstOrFail());"
        + ` $entry = \\Kitsune\\Core\\Models\\Entry::create(['entry_type_id' => $type->id, 'title' => '${title}', 'status' => 'draft']);`
        + (trashed ? ' $entry->delete();' : ''));
}

/** A public image, stored as an upload stores it and then trashed, which takes it off the web; its path. */
function trashedPublicImage(title) {
    return tinker(IN_GOLFDOM
        + " $type = \\Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'image')->whereNull('org_id')->firstOrFail());"
        + " $source = tempnam(sys_get_temp_dir(), 'kitsune-trash-'); file_put_contents($source, base64_decode('" + PNG_BASE64 + "'));"
        + ` try { $entry = \\Kitsune\\Core\\Media\\MediaLibrary::store($source, 'probe.png', $type, 'public', '${title}'); } finally { @unlink($source); }`
        + " $entry->delete(); echo \\Illuminate\\Support\\Facades\\DB::table('media_files')->where('entry_id', $entry->id)->value('path');");
}

/** Where an entry is — live, trashed or gone — by its title. */
function whereIs(title) {
    return tinker("$row = \\Illuminate\\Support\\Facades\\DB::table('entries')->where('title', " + JSON.stringify(title) + ")->first(['deleted_at']);"
        + " echo $row === null ? 'gone' : ($row->deleted_at === null ? 'live' : 'trashed');");
}

function removeFixtures() {
    tinker("\\Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing a browser-test fixture', fn ($q) => $q->withTrashed()->where('title', 'like', "
        + JSON.stringify(`${PROBE} %`) + ")->get())"
        + "->each(function ($entry) { app(\\Kitsune\\Core\\Tenancy\\Context::class)->setOrg(\\Kitsune\\Core\\Models\\Org::query()->findOrFail($entry->org_id)); $entry->forceDelete(); });");
}

/** Show the list's trash, as Filament's filter shows it: '' not in the trash, '1' everything, '0' only the trash. */
async function showTrash(page, value) {
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.locator('select[wire\\:model="tableDeferredFilters.trashed.value"]').selectOption(value);
    await page.getByRole('button', { name: 'Apply filters' }).click();
    // Filament keeps its filter panel open once applied, over the rows' actions.
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Apply filters' })).toBeHidden();
}

/** A link to one of the list's entries, its row's or an action's. */
const ENTRY_LINK = 'a[href*="/c/article/"]';

/** A row, or a card, of the list, by its title. */
const record = (page, title) => page.locator('.fi-ta-row, .fi-ta-record').filter({ hasText: title });

async function confirm(page, button) {
    const modal = page.locator('.fi-modal-window').filter({ has: page.getByRole('button', { name: button, exact: true }) }).last();
    await expect(modal).toBeVisible();

    return modal;
}

test.describe.configure({ mode: 'serial' });

test.beforeEach(() => removeFixtures());

test.afterAll(() => removeFixtures());

test('lists the trash, and restores a trashed entry from it', async ({ page }) => {
    article(`${PROBE} trashed`, true);
    article(`${PROBE} live`, false);

    await page.goto(`/admin/${SITE}/c/article`);
    await page.locator('.fi-ta').getByPlaceholder('Search').fill(PROBE);
    await expect(record(page, `${PROBE} live`)).toHaveCount(1);
    await expect(record(page, `${PROBE} trashed`)).toHaveCount(0);
    // The control: a live row links to its entry.
    await expect(record(page, `${PROBE} live`).locator(ENTRY_LINK).first()).toBeVisible();

    await showTrash(page, '0');
    await expect(record(page, `${PROBE} trashed`)).toHaveCount(1);
    await expect(record(page, `${PROBE} live`)).toHaveCount(0);

    // A trashed entry is restored or deleted forever, and not opened: its pages resolve live entries alone.
    const trashed = record(page, `${PROBE} trashed`);
    await expect(trashed.getByRole('button', { name: 'Restore' })).toBeVisible();
    await expect(trashed.getByRole('button', { name: 'Delete forever' })).toBeVisible();
    await expect(trashed.getByRole('link', { name: 'Edit' })).toHaveCount(0);
    await expect(trashed.getByRole('link', { name: 'View' })).toHaveCount(0);
    // Nor its row: Filament's own link would fall back to the view page, and a 404 (Codex, #163).
    await expect(trashed.locator(ENTRY_LINK)).toHaveCount(0);

    await trashed.getByRole('button', { name: 'Restore' }).click();
    await (await confirm(page, 'Restore')).getByRole('button', { name: 'Restore', exact: true }).click();

    await expect(page.locator('.fi-no-notification').filter({ hasText: 'Restored' })).toBeVisible();
    expect(whereIs(`${PROBE} trashed`)).toBe('live');
});

test('deletes forever from the trash, saying first what it takes, and leaves what is not in the trash', async ({ page }) => {
    article(`${PROBE} trashed`, true);
    article(`${PROBE} live`, false);

    await page.goto(`/admin/${SITE}/c/article`);
    await page.locator('.fi-ta').getByPlaceholder('Search').fill(PROBE);
    await showTrash(page, '0');

    await record(page, `${PROBE} trashed`).getByRole('button', { name: 'Delete forever' }).click();
    const modal = await confirm(page, 'Delete forever');
    await expect(modal).toContainText('This cannot be undone. The entry and its history are deleted for good, and any link to it from another entry is removed.');
    await modal.getByRole('button', { name: 'Delete forever', exact: true }).click();

    await expect(page.locator('.fi-no-notification').filter({ hasText: 'Deleted' })).toBeVisible();
    expect(whereIs(`${PROBE} trashed`)).toBe('gone');

    // Everything, the trash included: a live entry selected beside a trashed one is named, and left.
    article(`${PROBE} trashed again`, true);
    await page.goto(`/admin/${SITE}/c/article`);
    await page.locator('.fi-ta').getByPlaceholder('Search').fill(PROBE);
    await showTrash(page, '1');

    for (const title of [`${PROBE} live`, `${PROBE} trashed again`]) {
        await record(page, title).getByRole('checkbox').check();
    }

    await page.getByRole('button', { name: /bulk actions/i }).click();
    await page.getByRole('button', { name: 'Delete selected forever' }).click();
    // An article list's own warning, never a file's (decision 36).
    const dialog = await confirm(page, 'Delete forever');
    await expect(dialog).toContainText('This cannot be undone. The entry and its history are deleted for good, and any link to it from another entry is removed.');
    await expect(dialog).not.toContainText('its file');
    await dialog.getByRole('button', { name: 'Delete forever', exact: true }).click();

    await expect(page.getByText('One entry was not deleted forever')).toBeVisible();
    await expect(page.getByText(`"${PROBE} live" was not deleted forever: it is not in the trash. Move it to the trash first.`)).toBeVisible();
    // One notification, Kitsune's, counting the one it did delete forever.
    await expect(page.locator('.fi-no-notification')).toHaveCount(1);
    await expect(page.getByText('One entry was deleted forever.')).toBeVisible();
    expect(whereIs(`${PROBE} live`)).toBe('live');
    expect(whereIs(`${PROBE} trashed again`)).toBe('gone');
});

/*
 * ⚠️ AND A RESTORED PUBLIC FILE IS ON THE WEB AGAIN, at its URL, as decision 5 publishes it after the restore commits —
 * asked of the web server, with the control that the trash took it off first. A media list is cards, and a trashed
 * card offers what a trashed row does.
 */
test('puts a restored public file back on the web', async ({ page }) => {
    const filePath = trashedPublicImage(`${PROBE} image`);
    expect((await page.request.get(`/storage/${filePath}`)).status()).not.toBe(200);

    await page.goto(`/admin/${SITE}/c/image`);
    await page.locator('.fi-ta').getByPlaceholder('Search').fill(PROBE);
    await showTrash(page, '0');

    const card = record(page, `${PROBE} image`);
    await expect(card.getByRole('button', { name: 'Delete forever' })).toBeVisible();
    // Its tile fetches nothing: the route that authorises first resolves live entries alone.
    await expect(card.locator('[data-kitsune-tile="badge"]')).toHaveText('In the trash');
    await expect(card.locator('[data-kitsune-tile] img, [data-kitsune-tile] button')).toHaveCount(0);
    await card.getByRole('button', { name: 'Restore' }).click();
    await (await confirm(page, 'Restore')).getByRole('button', { name: 'Restore', exact: true }).click();
    await expect(page.locator('.fi-no-notification').filter({ hasText: 'Restored' })).toBeVisible();

    await expect.poll(async () => (await page.request.get(`/storage/${filePath}`)).status()).toBe(200);
    expect(whereIs(`${PROBE} image`)).toBe('live');
});
