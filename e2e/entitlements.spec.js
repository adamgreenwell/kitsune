// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

/*
 * The entitlements page — ADR-040, entitlements' second half: who holds what on a site, from which source, until when
 * and how it got there; a comp; and revoking one source, as an owner sees them in a browser. The PHP suite drives each
 * handler; only a browser says the table re-renders without a navigation, that the reader's id reaches no address, that a
 * stored `"` stays text, and that a refused comp keeps its modal open with what was typed.
 *
 * ⚠️ THE READERS ARE THE TEST MODULE'S, `kitsune/e2e-reader-guard`, which `global-setup.js` enables and seeds: Golfdom
 * Media's public readers are 1 and 2, Rival's 3 and 4, and reader 1 holds `course.advanced-php` from `e2e.order:1`,
 * granted by the system. A CI retry re-runs this group against what the first attempt left, and a revoked source stays
 * revoked by design — so `beforeAll` erases both Golfdom readers' rows through the console's own door, and the seed
 * grants again.
 *
 * This session is Golfdom's owner. The refusal for a member who is not one is `permissions.spec.js`'s.
 */

const SITE = 'golfdom';
const PAGE = `/admin/${SITE}/entitlements`;
const READER = '1';
const RIVAL_READER = '3';
// Printable ASCII with no space: an id the guard's grammar admits, and one that would close an unescaped attribute.
const HOSTILE = 'e2e"onfocus=window.__kp=1//';
const HOSTILE_NAME = 'e2e.hostile-id';

function artisan(...args) {
    return execFileSync('php', ['artisan', ...args], { cwd: path.join(__dirname, '..', 'skeleton'), encoding: 'utf8' }).trim();
}

function tinker(code) {
    return artisan('tinker', '--execute', code);
}

/** Golfdom's site row, below Eloquent: a browser-test fixture, never a write the page could make. */
const GOLFDOM = "$site = DB::table('sites')->where('slug', 'golfdom')->first();";

function plantHostile() {
    tinker(GOLFDOM
        + " DB::table('entitlements')->insertOrIgnore(['org_id' => $site->org_id, 'site_id' => $site->id, 'reader_id' => '" + HOSTILE + "',"
        + ` 'entitlement' => '${HOSTILE_NAME}', 'source' => 'e2e.plant:1', 'changed_at' => now('UTC')->format('Y-m-d H:i:s')]);`);
}

function removeHostile() {
    tinker(`DB::table('entitlements')->where('entitlement', '${HOSTILE_NAME}')->delete();`);
}

/** A row's id, read below Eloquent. */
function rowId(reader, entitlement, source) {
    return tinker(GOLFDOM
        + ` echo DB::table('entitlements')->where('site_id', $site->id)->where('reader_id', '${reader}')->where('entitlement', '${entitlement}')->where('source', '${source}')->value('id');`);
}

function rowCount() {
    return Number(tinker("echo DB::table('entitlements')->count();"));
}

const rows = (page) => page.locator('.fi-ta-row');
const orderRow = (page) => rows(page).filter({ hasText: 'e2e.order:1' });
const compRow = (page, entitlement = 'course.advanced-php') => rows(page).filter({ hasText: entitlement }).filter({ has: page.locator('td', { hasText: /^\s*Comp\s*$/ }) });
const notice = (page, text) => page.locator('.fi-no-notification').filter({ hasText: text });
// Every field of Comp is required, and its label says so with Filament's asterisk.
const field = (form, label) => form.getByLabel(`${label}*`, { exact: true });
const modal = (page, heading) => page.locator('.fi-modal-window').filter({ has: page.getByRole('heading', { name: heading, exact: true }) });

/** Open Comp, fill it as the owner would, and press Give. */
async function comp(page, { reader, entitlement, ends }) {
    await page.getByRole('button', { name: 'Comp', exact: true }).click();
    const form = modal(page, 'Give access by hand');
    await expect(form).toBeVisible();
    await field(form, 'Reader').fill(reader);
    await field(form, 'Entitlement').fill(entitlement);

    if (ends === null) {
        await form.getByLabel('No end', { exact: true }).check();
    } else {
        await form.getByLabel('On a date', { exact: true }).check();
        await field(form, 'Ends on').fill(ends);
    }

    await form.getByRole('button', { name: 'Give', exact: true }).click();

    return form;
}

/** Revoke a row, confirming in its modal, after checking the modal's words. */
async function revoke(page, row, heading, description) {
    await row.getByRole('button', { name: 'Revoke', exact: true }).click();
    const confirm = modal(page, heading);
    await expect(confirm).toBeVisible();
    await expect(confirm).toContainText(description);
    await confirm.getByRole('button', { name: 'Revoke', exact: true }).click();
}

/** The History modal's rows: change and by whom, each with a when in the site's own format. */
async function history(page, row, scan = false) {
    await row.getByRole('button', { name: 'History', exact: true }).click();
    const opened = page.locator('.fi-modal-window').filter({ has: page.locator('table') }).last();
    await expect(opened).toBeVisible();
    await expect(opened.locator('th[scope="col"]')).toHaveText(['Change', 'When', 'By']);

    if (scan) {
        await expect(opened).toHaveCSS('opacity', '1');
        await page.evaluate(() => Promise.all(document.getAnimations().map((animation) => animation.finished)));
        await expectNoSeriousViolations(page);
    }

    // A cell's content; its term repeats the column header for a screen reader alone.
    const lines = await opened.locator('tbody tr').evaluateAll((trs) => trs.map((tr) => {
        const cells = [...tr.querySelectorAll('td')].map((td) => (td.querySelector('[role="definition"]')?.textContent || td.textContent || '').trim());

        return cells;
    }));

    for (const cells of lines) {
        expect(cells[1]).toMatch(/^[A-Z][a-z]{2} \d{1,2}, \d{4} \d{2}:\d{2}:\d{2}$/);
    }

    await opened.locator('.fi-modal-footer').getByRole('button', { name: 'Close', exact: true }).click();
    await expect(opened).toBeHidden();

    return lines.map((cells) => [cells[0], cells[2]]);
}

/** WCAG 2.1 A and AA, critical and serious, over whatever is on screen. */
async function expectNoSeriousViolations(page) {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const blocking = results.violations.filter((violation) => ['critical', 'serious'].includes(violation.impact || ''));
    expect(blocking.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ') + ' ' + node.failureSummary).join(' | ')}`)).toEqual([]);
}

/** Fill the filters above the table and apply them. */
async function filter(page, fields) {
    const filters = page.locator('.fi-ta-filters');

    for (const [label, value] of Object.entries(fields)) {
        await filters.getByLabel(label, { exact: true }).fill(value);
    }

    await filters.getByRole('button', { name: 'Apply filters', exact: true }).click();
}

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
    for (const reader of [READER, '2']) {
        artisan('kitsune:entitlements', 'forget', '--org=golfdom-media', `--reader=${reader}`, '--force');
    }

    artisan('e2e:public-readers-seed', '--no-interaction');
    removeHostile();
    plantHostile();
});

test.afterAll(() => removeHostile());

test('is linked from an owner\'s sidebar, and shows the producer\'s row it did not make', async ({ page }) => {
    // From the dashboard, not the page itself: a nav item's URL closure runs on every page (AGENTS.md §9).
    await page.goto(`/admin/${SITE}`);
    const link = page.locator('.fi-sidebar').getByRole('link', { name: 'Entitlements', exact: true });
    await expect(link).toHaveAttribute('href', new RegExp(`${PAGE}$`));

    const response = await page.goto(PAGE);
    expect(response?.status()).toBe(200);
    await expect(page.getByText('A reader holds an entitlement while any of its sources is live.', { exact: true })).toBeVisible();

    const row = orderRow(page);
    await expect(row).toHaveCount(1);
    await expect(row.locator('td').nth(0)).toHaveText(READER);
    await expect(row).toContainText('course.advanced-php');
    await expect(row.locator('.fi-badge')).toHaveText('Live');
    await expect(row).toContainText('No end');
});

test('tells the producer\'s row\'s history, granted by the system, as a table with no serious WCAG violations', async ({ page }) => {
    await page.goto(PAGE);

    expect(await history(page, orderRow(page), true)).toEqual([['Granted', 'the system']]);
});

test('comps beside the producer\'s source', async ({ page }) => {
    await page.goto(PAGE);
    await comp(page, { reader: READER, entitlement: 'course.advanced-php', ends: null });

    await expect(notice(page, 'Given.')).toBeVisible();
    await expect(compRow(page).locator('.fi-badge')).toHaveText('Live');
    await expect(orderRow(page).locator('.fi-badge')).toHaveText('Live');
});

test('revokes the order\'s source alone, and shows it without a navigation', async ({ page }) => {
    await page.goto(PAGE);
    // A navigation would make a new window object, and this marker with it.
    await page.evaluate(() => { window.__entitlementsStayed = true; });

    await revoke(page, orderRow(page), 'Revoke e2e.order:1?', 'It stays revoked');

    await expect(notice(page, 'Revoked.')).toBeVisible();
    await expect(orderRow(page).locator('.fi-badge')).toHaveText('Revoked');
    await expect(orderRow(page).getByRole('button', { name: 'Revoke', exact: true })).toHaveCount(0);
    await expect(compRow(page).locator('.fi-badge')).toHaveText('Live');
    expect(await page.evaluate(() => window.__entitlementsStayed)).toBe(true);
});

test('gives a revoked comp back', async ({ page }) => {
    await page.goto(PAGE);
    await revoke(page, compRow(page), 'Revoke this comp?', 'You can comp again later.');
    await expect(compRow(page).locator('.fi-badge')).toHaveText('Revoked');

    await comp(page, { reader: READER, entitlement: 'course.advanced-php', ends: null });

    await expect(notice(page, 'Given again — this comp had been revoked.')).toBeVisible();
    await expect(compRow(page).locator('.fi-badge')).toHaveText('Live');
});

test('changes nothing when the comp already gives more', async ({ page }) => {
    await page.goto(PAGE);
    await comp(page, { reader: READER, entitlement: 'course.advanced-php', ends: '2030-01-01T09:00' });

    await expect(notice(page, 'Already comped for as long or longer — nothing changed.')).toBeVisible();
    await expect(compRow(page)).toContainText('No end');
});

test('tells the comp\'s history, naming the owner', async ({ page }) => {
    await page.goto(PAGE);

    expect(await history(page, compRow(page))).toEqual([
        ['Granted', 'Alpha User'],
        ['Revoked', 'Alpha User'],
        ['Given again', 'Alpha User'],
    ]);
});

test('answers "does reader 1 hold it?" by filtering, and puts the reader in no address', async ({ page }) => {
    const requested = [];
    page.on('request', (request) => requested.push(request.url()));

    await page.goto(PAGE);
    await filter(page, { Reader: READER, Entitlement: 'course.advanced-php' });

    await expect(rows(page)).toHaveCount(2);
    await expect(rows(page).locator('.fi-badge', { hasText: 'Live' })).toHaveCount(1);
    expect(new URL(page.url()).pathname + new URL(page.url()).search).toBe(PAGE);
    expect(requested.filter((url) => url.includes('tableFilters') || url.includes('course.advanced-php'))).toEqual([]);

    // Exact, as the integer key reads it: `01` is not reader 1 — and the empty table says nothing about the site.
    await filter(page, { Reader: '01' });
    await expect(rows(page)).toHaveCount(0);
    await expect(page.getByText('No row matches these filters', { exact: true })).toBeVisible();
    await expect(page.getByText('Nothing has been given on this site', { exact: true })).toHaveCount(0);
});

test('refuses another org\'s reader in the writer\'s words, keeping the form', async ({ page }) => {
    await page.goto(PAGE);
    const before = rowCount();
    const form = await comp(page, { reader: RIVAL_READER, entitlement: 'course.advanced-php', ends: null });

    await expect(notice(page, 'no reader with that identifier belongs to this organisation')).toBeVisible();
    await expect(form).toBeVisible();
    await expect(field(form, 'Reader')).toHaveValue(RIVAL_READER);
    await expect(field(form, 'Entitlement')).toHaveValue('course.advanced-php');
    expect(rowCount()).toBe(before);

    await form.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(form).toBeHidden();
});

test('is per site', async ({ page }) => {
    // Golfdom's French edition: the same org, another site. Rows only — its chrome may be French.
    const response = await page.goto('/admin/golfdom-fr/entitlements');
    expect(response?.status()).toBe(200);
    await expect(page.locator('.fi-ta')).toBeVisible();
    await expect(rows(page)).toHaveCount(0);
});

test('is this org\'s alone', async ({ page, browser }) => {
    expect((await page.request.get('/admin/rival-golfdom/entitlements')).status()).toBe(404);

    const rival = await browser.newContext({ storageState: '.playwright/admin-rival-auth.json' });

    try {
        const rivalPage = await rival.newPage();
        const own = await rivalPage.goto('/admin/rival-golfdom/entitlements');
        expect(own?.status()).toBe(200);
        await expect(rivalPage.locator('body')).not.toContainText('course.advanced-php');
    } finally {
        await rival.close();
    }
});

test('lets nobody signed in in', async ({ browser }) => {
    // An empty state explicitly: a context made here otherwise takes the project's signed-in one.
    const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });

    try {
        const anonPage = await anonymous.newPage();
        await anonPage.goto(PAGE);
        await expect(anonPage).toHaveURL(/\/admin\/login$/);
    } finally {
        await anonymous.close();
    }
});

test('can be used from the keyboard alone, with no serious WCAG violations in the Comp modal', async ({ page }) => {
    await page.goto(PAGE);
    await page.getByRole('button', { name: 'Comp', exact: true }).focus();
    await page.keyboard.press('Enter');

    const form = modal(page, 'Give access by hand');
    const reader = field(form, 'Reader');
    await expect(reader).toBeVisible();
    await expect(reader).toBeFocused();

    // Scanned once the modal has finished fading in: text caught mid-transition reads as low contrast.
    await expect(form).toHaveCSS('opacity', '1');
    await page.evaluate(() => Promise.all(document.getAnimations().map((animation) => animation.finished)));

    await expectNoSeriousViolations(page);

    await page.keyboard.type('2');
    await page.keyboard.press('Tab');
    await page.keyboard.type('course.keyboard');
    await page.keyboard.press('Tab');
    await expect(form.getByLabel('No end', { exact: true })).toBeFocused();
    await page.keyboard.press('Space');
    await expect(form.getByLabel('No end', { exact: true })).toBeChecked();
    await page.keyboard.press('Tab');
    await expect(form.getByRole('button', { name: 'Give', exact: true })).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(notice(page, 'Given.')).toBeVisible();
    await expect(compRow(page, 'course.keyboard')).toHaveCount(1);
});

test('keeps a stored id with a quote in it as text', async ({ page }) => {
    await page.goto(PAGE);
    await filter(page, { Entitlement: HOSTILE_NAME });

    const row = rows(page);
    await expect(row).toHaveCount(1);
    await expect(row.locator('td').nth(0)).toHaveText(HOSTILE);
    expect(await page.locator('[onfocus]').count()).toBe(0);

    for (const name of ['History', 'Revoke']) {
        await row.getByRole('button', { name, exact: true }).focus();
    }

    expect(await page.evaluate(() => window.__kp)).toBeUndefined();

    // Nor in either modal the row opens.
    await row.getByRole('button', { name: 'History', exact: true }).click();
    const opened = modal(page, `${HOSTILE_NAME} from e2e.plant:1`);
    await expect(opened).toBeVisible();
    // Planted below the writer, so nothing records it.
    await expect(opened).toContainText('Nothing is recorded for this source.');
    expect(await page.locator('[onfocus]').count()).toBe(0);
    await opened.locator('.fi-modal-footer').getByRole('button', { name: 'Close', exact: true }).click();
    await expect(opened).toBeHidden();

    await row.getByRole('button', { name: 'Revoke', exact: true }).click();
    const confirming = modal(page, 'Revoke e2e.plant:1?');
    await expect(confirming).toBeVisible();
    expect(await page.locator('[onfocus]').count()).toBe(0);
    await confirming.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(confirming).toBeHidden();

    // The writer refuses it — the guard's key is an integer — in its own words, and the page says so.
    await revoke(page, row, 'Revoke e2e.plant:1?', 'It stays revoked');
    await expect(notice(page, 'Not revoked')).toBeVisible();
    await expect(notice(page, 'Not revoked')).toContainText('that is not an identifier a reader can have here');
    expect(await page.evaluate(() => window.__kp)).toBeUndefined();
    await expect(row.locator('.fi-badge')).toHaveText('Live');
});

test('opens from a link, and never acts from one', async ({ page }) => {
    const id = rowId('2', 'course.keyboard', 'core.comp');
    expect(id).toMatch(/^\d+$/);

    await page.goto(`${PAGE}?tableAction=revoke&tableActionRecord=${id}`);
    const confirm = modal(page, 'Revoke this comp?');
    await expect(confirm).toBeVisible();
    await confirm.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(confirm).toBeHidden();
    await expect(compRow(page, 'course.keyboard').locator('.fi-badge')).toHaveText('Live');

    const before = rowCount();
    await page.goto(`${PAGE}?action=comp&actionArguments[reader]=2&actionArguments[entitlement]=course.linked`);
    const form = modal(page, 'Give access by hand');
    await expect(form).toBeVisible();
    await expect(field(form, 'Reader')).toHaveValue('');
    await expect(field(form, 'Entitlement')).toHaveValue('');
    expect(rowCount()).toBe(before);
});
