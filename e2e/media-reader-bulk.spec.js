// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const AxeBuilder = require('@axe-core/playwright').default;
const { menuItem } = require('./menu');
const { seed, state, remove } = require('./media-reader-bulk-fixture');

const NAME = 'course.selected';
const notice = (page) => page.locator('.fi-no-notification').filter({ hasText: 'Reader offer results' });
const selection = (page) => page.locator('.fi-ta-selection-indicator');
const perPage = (page) => page.locator('.fi-ta select[wire\\:model\\.live="tableRecordsPerPage"]').filter({ visible: true }).first();
const modal = (page) => page.locator('.fi-modal-window').filter({ has: page.getByRole('button', { name: 'Offer', exact: true }) }).last();

async function choose(page, fixture, count) {
    const response = await page.goto(`/admin/${fixture.slug}/c/image`);
    expect(response.status()).toBe(200);
    const searched = page.waitForResponse((r) => r.url().includes('/livewire') && (r.request().postData() || '').includes(fixture.slug));
    await page.locator('.fi-ta').getByPlaceholder('Search').fill(fixture.slug);
    await searched;
    if (count > 10) {
        pageSizeBefore = await perPage(page).inputValue();
        await perPage(page).selectOption('50');
    }
    await expect(page.locator('.fi-ta-record')).toHaveCount(count);
    await page.locator('.fi-ta-page-checkbox').filter({ visible: true }).first().check();
    await expect(selection(page)).toContainText(`${count} records selected`);
    await page.getByRole('button', { name: /bulk actions/i }).click();
    await menuItem(page, 'Offer selected to readers').click();
    await expect(modal(page)).toBeVisible();
    await modal(page).getByLabel('Entitlement*', { exact: true }).fill(NAME);
    await modal(page).getByLabel('Type the entitlement name again*', { exact: true }).fill(NAME);
}

// A separate hook preserves the original assertion if teardown itself refuses.
let owned;
let pageSizeBefore;
test.afterEach(async ({ page }) => {
    try {
        // Filament keeps this table preference across sites in the shared authenticated session.
        if (pageSizeBefore !== undefined) {
            await page.goto('/admin/golfdom/c/image');
            if (await perPage(page).inputValue() !== pageSizeBefore) {
                const stored = page.waitForResponse((response) => response.url().includes('/livewire')
                    && (response.request().postData() ?? '').includes('tableRecordsPerPage'));
                await perPage(page).selectOption(pageSizeBefore);
                await stored;
            }
            await expect(perPage(page)).toHaveValue(pageSizeBefore);
        }
    } finally {
        pageSizeBefore = undefined;
        if (owned) { remove(owned); owned = null; }
    }
});

async function axe(page) {
    // Check actual settled opacity too: a newly mounted toast can begin its transition after an animation snapshot.
    await expect.poll(() => page.evaluate(() => {
        const moving = document.getAnimations().some((a) => a.effect?.getComputedTiming().iterations !== Infinity
            && (a.playState === 'running' || a.pending));
        const fading = [...document.querySelectorAll('.fi-no-notification')].some((n) => getComputedStyle(n).opacity !== '1');
        return !moving && !fading;
    })).toBe(true);
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const blocking = result.violations.filter((v) => ['critical', 'serious'].includes(v.impact || ''));
    expect(blocking.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => ({ target: n.target, explanation: n.failureSummary })) }))).toEqual([]);
}

test('confirms retags, keeps partial successes and refusals selected, and leaves another site alone', async ({ page }) => {
    const fixture = owned = seed(4, true);
    const before = state(fixture);
    await choose(page, fixture, 4);
    await expect(modal(page)).toContainText('replaces any different name');
    await expect(modal(page)).toContainText('will no longer be able to download it');
    await expect(modal(page)).toContainText('as it is stored');
    await axe(page);
    await modal(page).getByLabel('Type the entitlement name again*', { exact: true }).fill('course.other');
    await modal(page).getByRole('button', { name: 'Offer', exact: true }).click();
    await expect(modal(page)).toContainText('type exactly the same name again');
    expect(state(fixture)).toEqual(before);
    await modal(page).getByLabel('Type the entitlement name again*', { exact: true }).fill(NAME);
    await modal(page).getByRole('button', { name: 'Offer', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(notice(page)).toBeVisible();
    await expect(notice(page)).toContainText('Offered now: 2');
    await expect(notice(page)).toContainText('refused or unavailable: 2');
    await expect(notice(page).locator('li')).toHaveCount(4);
    await expect(notice(page)).toContainText('anyone can already download a public one');
    await expect(notice(page)).toContainText('shared with every site');
    await expect(page.locator('.fi-no-notification')).toHaveCount(1);
    await expect(selection(page)).toContainText('4 records selected');
    await axe(page);
    const after = state(fixture);
    expect(after.slice(0, 2).map((r) => r.offer)).toEqual([NAME, NAME]);
    expect(after[0].audits).toEqual(['media.offered_to_readers', 'media.reader_offer_changed']);
    expect(after[1].audits).toEqual(['media.offered_to_readers']);
    expect(after.slice(2)).toEqual(before.slice(2));
    expect(after.map((r) => r.actual_checksum)).toEqual(before.map((r) => r.actual_checksum));
    const outside = await page.goto(`/admin/${fixture.slug}`);
    expect(outside.status()).toBe(200);
    await expect(page.locator('.fi-header')).toBeVisible();
    const control = await page.goto(`/admin/${fixture.slug}-control/c/image`);
    expect(control.status()).toBe(200);
    await expect(page.locator('.fi-ta-record').filter({ hasText: `${fixture.slug} control` })).toHaveCount(1);
});

test('measures fifty audited offers and makes every result reachable at 320 pixels', async ({ page }, testInfo) => {
    const fixture = owned = seed(50);
    const before = state(fixture);
    await choose(page, fixture, 50);
    const started = performance.now();
    await modal(page).getByRole('button', { name: 'Offer', exact: true }).click();
    await expect(notice(page)).toBeVisible();
    const milliseconds = Math.round(performance.now() - started);
    await expect(notice(page)).toContainText('Offered now: 50');
    await expect(notice(page).locator('li')).toHaveCount(50);
    await expect(selection(page)).toBeHidden();
    const after = state(fixture);
    expect(after.slice(0, 50).every((r) => r.offer === NAME && r.audits.length === 1)).toBe(true);
    expect(after.map((r) => r.actual_checksum)).toEqual(before.map((r) => r.actual_checksum));
    expect(after[50]).toEqual(before[50]);
    const measurement = testInfo.outputPath('bulk-reader-batch-measurement.json');
    fs.writeFileSync(measurement, JSON.stringify({ count: 50, milliseconds, offered: 50, refused: 0, retries: testInfo.retry }));
    await testInfo.attach('bulk-reader-batch-measurement', { contentType: 'application/json', path: measurement });
    await page.setViewportSize({ width: 320, height: 700 });
    const bounds = await notice(page).boundingBox();
    expect(bounds).not.toBeNull();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.width).toBeLessThanOrEqual(320);
    expect(bounds.y).toBeGreaterThanOrEqual(0);
    expect(bounds.height).toBeLessThanOrEqual(700);
    const results = notice(page).getByRole('region', { name: 'Per-file reader offer results' });
    await results.focus();
    await page.keyboard.press('End');
    await expect(notice(page).locator('li').last()).toBeInViewport();
    await page.keyboard.press('Home');
    await expect(notice(page).locator('li').first()).toBeInViewport();
    await axe(page);
    await notice(page).screenshot({ path: testInfo.outputPath('owned-bulk-reader-results-320.png') });
});
