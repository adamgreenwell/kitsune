const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

async function snapshots(request) {
    const response = await request.get('/versions.json');
    expect(response.ok()).toBeTruthy();
    return response.json();
}

test('reads task guides, crosses ADR fragments and searches only this snapshot', async ({ page, request }) => {
    const versions = await snapshots(request);
    const current = versions.find(version => version.label === 'development');
    const external = [];
    const failures = [];
    page.on('request', request => {
        if (new URL(request.url()).origin !== 'http://127.0.0.1:8346') external.push(request.url());
    });
    page.on('pageerror', error => failures.push(error.message));
    await page.goto(`/${current.id}/docs-index.html`);
    await expect(page.getByRole('heading', { name: 'Run and understand Kitsune', exact: true })).toBeVisible();
    await page.locator('article').getByRole('link', { name: 'Install Kitsune', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Download, inspect, then run' })).toBeVisible();
    await page.locator('article').getByRole('link', { name: 'resource-floor decision' }).click();
    await expect(page).toHaveURL(new RegExp(`/${current.id}/adr-027.html#adr-027--`));
    await expect(page.locator('article h1')).toContainText('ADR-027');
    await page.keyboard.press('/');
    await expect(page.getByRole('searchbox')).toBeFocused();
    await page.getByRole('searchbox').fill('APP_KEY');
    await expect(page.locator('#search-status')).toContainText('matching sections');
    const result = page.locator('#search-results a').filter({ hasText: 'Rotating APP_KEY' }).first();
    await expect(result).toBeVisible();
    await result.click();
    await expect(page).toHaveURL(new RegExp(`/${current.id}/docs-credential-operations.html#rotating-app_key`));
    await expect(page.getByRole('heading', { name: 'Rotating APP_KEY', exact: true })).toBeVisible();
    expect(external).toEqual([]);
    expect(failures).toEqual([]);
});

test('switches to a real built ref and keeps search links in that snapshot', async ({ page, request }) => {
    const versions = await snapshots(request);
    const current = versions.find(version => version.label === 'development');
    const reference = versions.find(version => version.label === 'reference-head');
    await page.goto(`/${current.id}/project-code_of_conduct.html`);
    await page.locator('.versions summary').click();
    await page.locator('#versions a').filter({ hasText: 'reference-head' }).click();
    await expect(page).toHaveURL(`http://127.0.0.1:8346/${reference.id}/project-code_of_conduct.html`);
    await page.getByRole('searchbox').fill('APP_KEY');
    await expect(page.locator('#search-results a').first()).toBeVisible();
    const links = await page.locator('#search-results a').evaluateAll(nodes => nodes.map(node => node.href));
    expect(links.length).toBeGreaterThan(0);
    expect(links.every(href => href.includes(`/${reference.id}/`))).toBeTruthy();
});

test('supports a keyboard skip, mobile navigation, no-result guidance and reduced motion', async ({ page, request }) => {
    const current = (await snapshots(request)).find(version => version.label === 'development');
    await page.setViewportSize({ width: 375, height: 812 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(`/${current.id}/docs-index.html`);
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#main')).toBeFocused();
    await page.locator('.browse summary').first().click();
    await page.locator('.sidebar').getByRole('link', { name: 'Back up and restore', exact: true }).click();
    await expect(page.locator('article h1')).toHaveText('Back up and restore');
    await expect(page.locator('article')).toContainText('APP_PREVIOUS_KEYS');
    await page.getByRole('searchbox').fill('no-match-for-this-exact-task-xyz');
    await expect(page.getByRole('status')).toContainText('No matching sections');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('searchbox')).toHaveValue('');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
});

test('keeps content, source and snapshot navigation usable without JavaScript', async ({ browser, request }) => {
    const current = (await snapshots(request)).find(version => version.label === 'development');
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 375, height: 812 } });
    const page = await context.newPage();
    await page.goto(`http://127.0.0.1:8346/${current.id}/docs-upgrades.html`);
    await expect(page.locator('article h1')).toHaveText('Upgrade and compatibility');
    await page.getByRole('link', { name: 'Read source', exact: true }).click();
    await expect(page.locator('body')).toContainText('# Upgrade and compatibility');
    await page.goBack();
    await page.locator('.versions summary').click();
    await page.getByRole('link', { name: 'All built snapshots' }).click();
    await expect(page.getByRole('heading', { name: 'Documentation snapshots' })).toBeVisible();
    await context.close();
});

test('has no automated accessibility violations on desktop and narrow task pages', async ({ page, request }, testInfo) => {
    const current = (await snapshots(request)).find(version => version.label === 'development');
    for (const [width, document] of [[1440, 'docs-index'], [375, 'docs-install'], [375, 'adr-027']]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`/${current.id}/${document}.html`);
        await page.screenshot({ path: testInfo.outputPath(`${document}-${width}.png`), fullPage: true });
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        expect(result.violations).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    }
});
