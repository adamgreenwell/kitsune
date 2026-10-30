// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { HOLDER, EMPTY, PREFIX, NESTED, RTL, PDF, artisan, seedDownloadList, downloadListMode } = require('./reader-download-list-fixture');

// No staff storageState: these are the actual reader forms, in a fixture Org/Site separate from other browser lanes.
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => seedDownloadList());
test.beforeEach(() => artisan('cache:clear'));

async function signIn(page, prefix, account = HOLDER) {
    await page.goto(`${prefix}/account/sign-in`);
    await page.getByLabel('Email address', { exact: true }).fill(account.email);
    await page.getByLabel('Password', { exact: true }).fill(account.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Your account', exact: true })).toBeVisible();
}

async function axe(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(result.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`)).toEqual([]);
}

test('account navigation, labels, keyboard pagination and landing revalidation', async ({ page }) => {
    await signIn(page, PREFIX);
    const link = page.getByRole('link', { name: 'Your downloads', exact: true });
    await link.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { name: 'Your downloads', exact: true })).toBeVisible();
    await expect(page.locator('main li')).toHaveCount(24);
    await expect(page.getByText('Hidden private offer')).toHaveCount(0);
    await expect(page.getByRole('navigation', { name: 'Download pages', exact: true })).toBeVisible();
    await axe(page);
    await page.getByRole('link', { name: 'More downloads', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/account/downloads/page/2$`));
    await expect(page.locator('main li')).toHaveCount(1);
    await expect(page.getByRole('link', { name: 'Fixture file 25', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'More downloads', exact: true })).toHaveCount(0);
    await page.getByRole('link', { name: 'Previous downloads', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${PREFIX}/account/downloads$`));
    const target = page.getByRole('link', { name: 'Fixture file 01', exact: true });
    const href = await target.getAttribute('href');
    expect(href).toMatch(new RegExp(`^${PREFIX}/account/downloads/[0-9]+$`));
    await target.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('\u2068Fixture file 01\u2069');
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('link', { name: 'Download', exact: true }).click(),
    ]);
    expect(download.suggestedFilename()).toBe('Fixture file 01.pdf');
    const stream = await download.createReadStream();
    expect(stream).not.toBeNull();
    const chunks = [];
    if (stream) for await (const chunk of stream) chunks.push(chunk);
    expect(Buffer.concat(chunks).toString('utf8')).toBe(PDF);
    await page.goto(`${PREFIX}/account/downloads`);
    const missing = page.getByRole('link', { name: 'Fixture file 02', exact: true });
    const missingHref = await missing.getAttribute('href');
    const [refused] = await Promise.all([
        page.waitForResponse((r) => r.url().endsWith(missingHref || 'never')),
        missing.click(),
    ]);
    expect(refused.status()).toBe(404); // This authorized offer has no disk bytes: the same existing landing refuses it.
    await expect(page.getByText('This download isn\'t available to this account.', { exact: false })).toBeVisible();
});

test('guest sign-in returns to an exact nested-prefix page', async ({ page }) => {
    await page.goto(`${NESTED}/account/downloads/page/2`);
    await expect(page).toHaveURL(new RegExp(`${NESTED}/account/sign-in$`));
    await page.getByLabel('Email address', { exact: true }).fill(HOLDER.email);
    await page.getByLabel('Password', { exact: true }).fill(HOLDER.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${NESTED}/account/downloads/page/2$`));
    await expect(page.getByRole('link', { name: 'Fixture file 25', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Your account', exact: true })).toHaveAttribute('href', `${NESTED}/account`);
});

test('empty accounts expose no offer metadata or pagination', async ({ page }) => {
    await signIn(page, PREFIX, EMPTY);
    await page.getByRole('link', { name: 'Your downloads', exact: true }).click();
    await expect(page.getByText('There are no downloads offered to your account here right now.', { exact: true })).toBeVisible();
    await expect(page.locator('main li')).toHaveCount(0);
    await expect(page.getByRole('navigation')).toHaveCount(0);
    await expect(page.getByText('Fixture file', { exact: false })).toHaveCount(0);
    await axe(page);
});

test('Arabic document retains readable fallback copy and isolated filenames', async ({ page }) => {
    await signIn(page, RTL);
    await page.getByRole('link', { name: 'Your downloads', exact: true }).click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('main')).toHaveAttribute('lang', 'en');
    await expect(page.locator('main')).toHaveAttribute('dir', 'ltr');
    await expect(page.locator('main li bdi')).toHaveCount(24);
    await axe(page);
});

test('warns at the real settings door before closing offered links and keeps a sibling site usable', async ({ page, browser, baseURL }, testInfo) => {
    const siblingContext = await browser.newContext({ baseURL });
    const sibling = await siblingContext.newPage();
    let primaryFailure = false;

    try {
        await signIn(page, PREFIX);
        await page.goto(`${PREFIX}/account/downloads`);
        const href = await page.getByRole('link', { name: 'Fixture file 01', exact: true }).getAttribute('href');
        expect(href).toMatch(new RegExp(`^${PREFIX}/account/downloads/[0-9]+$`));

        const refused = downloadListMode('off');
        expect(refused.status).toBe(1);
        // Fifty recorded offers here; another hundred on this org's other sites do not enter the warning.
        expect(refused.output).toContain('offered files: 50; affected sites: 1');
        expect(refused.output).toContain('download links will return 404');
        expect(refused.output).toContain('--force');
        expect(refused.output).toContain('Nothing was written.');
        for (const hidden of ['Hidden private offer', 'Fixture file 01', 'fixture.downloads', 'fixture.unheld', HOLDER.email, EMPTY.email]) {
            expect(refused.output).not.toContain(hidden);
        }
        expect((await page.goto(href || 'missing'))?.status()).toBe(200);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('\u2068Fixture file 01\u2069');
        const fileHref = await page.getByRole('link', { name: 'Download', exact: true }).getAttribute('href');
        expect(fileHref).toBeTruthy();

        await signIn(sibling, NESTED);
        await sibling.goto(`${NESTED}/account/downloads`);
        const siblingHref = await sibling.getByRole('link', { name: 'Fixture file 01', exact: true }).getAttribute('href');
        expect((await sibling.goto(siblingHref || 'missing'))?.status()).toBe(200);
        const siblingFileHref = await sibling.getByRole('link', { name: 'Download', exact: true }).getAttribute('href');

        const changed = downloadListMode('off', true);
        expect(changed.status).toBe(0);
        expect(changed.output).toContain('offered files: 50; affected sites: 1');
        expect(changed.output.indexOf('download links')).toBeLessThan(changed.output.indexOf('Reader accounts on'));
        expect((await page.goto(href || 'missing'))?.status()).toBe(404);
        expect((await page.request.get(fileHref || 'missing')).status()).toBe(404);
        expect((await page.request.get(`${PREFIX}/account/sign-in`)).status()).toBe(404);

        expect((await sibling.reload())?.status()).toBe(200);
        await expect(sibling.getByRole('heading', { level: 1 })).toHaveText('\u2068Fixture file 01\u2069');
        const siblingFile = await sibling.request.get(siblingFileHref || 'missing');
        expect(siblingFile.status()).toBe(200);
        expect((await siblingFile.body()).toString('utf8')).toBe(PDF);

        const restored = downloadListMode('sign-in');
        expect(restored.status).toBe(0);
        expect(restored.output).toBe('Reader accounts on [downloads-list]: sign-in.');
        expect((await page.goto(href || 'missing'))?.status()).toBe(200);
        const originalFile = await page.request.get(fileHref || 'missing');
        expect(originalFile.status()).toBe(200);
        expect((await originalFile.body()).toString('utf8')).toBe(PDF);
    } catch (error) {
        primaryFailure = true;
        throw error;
    } finally {
        const restored = downloadListMode('sign-in');
        await siblingContext.close();
        if (restored.status !== 0) {
            await testInfo.attach('reader-mode-fixture-restoration', {
                body: Buffer.from(JSON.stringify({ restored: false, status: restored.status })),
                contentType: 'application/json',
            });
            if (!primaryFailure) throw new Error('Reader mode fixture restoration failed.');
        }
    }
});
