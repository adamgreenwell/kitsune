// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { callsMethod } = require('./select-control');
const {
    SITE_A, SITE_B, EMPTY_SITE, RTL_SITE, NESTED_PREFIX, RIVAL_SITE, ENTITLEMENT,
    COURSE_A, COURSE_B, PRIVATE_PRODUCT, RIVAL_PRODUCT, READER, RIVAL_READER,
    resetCatalogueEntitlements, grantCatalogueEntitlement, revokeCatalogueEntitlement,
    setCatalogueProductType, setCatalogueReaderMode, catalogueCourseId, setCatalogueEntitlementName, catalogueMedia, artisan, php,
} = require('./public-catalogue-fixture');

/*
 * The host's opt-in catalogue — #192, ADR-040. These organisations have their own products, readers and media, so no
 * catalogue reset changes an older spec's list or grant count. Every public test starts without a staff or reader
 * session. Reader sign-in uses core's ordinary form; no extra throttled Filament login is spent here.
 */
const catalogue = (site = SITE_A) => `/${site}/catalogue`;
const card = (page, name) => page.getByRole('article').filter({ has: page.getByRole('heading', { level: 2, name, exact: true }) });
const held = (scope) => scope.getByText('You have access', { exact: true });

async function signIn(page, site = SITE_A, reader = READER) {
    await page.goto(`/${site}/account/sign-in`);
    await page.getByLabel('Email address', { exact: true }).fill(reader.email);
    await page.getByLabel('Password', { exact: true }).fill(reader.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/${site}/account$`));
}

async function axe(page) {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(results.violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

test.describe.configure({ mode: 'serial' });

test.beforeEach(() => {
    // Five reader sign-ins per minute share this harness's one address; clearing the cache stands in for time passing.
    artisan(['cache:clear']);
    resetCatalogueEntitlements();
});

test('shows guests this site\'s published products with exact prices and public images, keeping private media private', async ({ page }) => {
    const requested = [];
    page.on('request', (request) => requested.push(new URL(request.url()).pathname));
    const response = await page.goto(catalogue());
    expect(response.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Catalogue', exact: true })).toBeVisible();
    await expect(page.getByRole('article')).toHaveCount(24);
    const course = card(page, COURSE_A);
    await expect(course).toContainText('Learn beside the sea.');
    await expect(course).toContainText('USD 20.00');
    await expect(course).toContainText('Available');
    await expect(course.getByRole('img')).toHaveCount(1);
    await expect(course.getByRole('img')).toBeVisible();
    await expect.poll(() => course.getByRole('img').evaluate((image) => image.complete && image.naturalWidth > 0)).toBe(true);
    await expect(card(page, PRIVATE_PRODUCT).getByRole('img')).toHaveCount(0);
    await expect(held(page)).toHaveCount(0);
    await expect(page.getByRole('button', { name: /buy|checkout/i })).toHaveCount(0);
    await expect(page.getByRole('link', { name: /buy|checkout/i })).toHaveCount(0);
    for (const name of [COURSE_B, RIVAL_PRODUCT, 'Hidden unavailable course', 'Hidden draft course', 'Hidden trashed course']) {
        await expect(page.getByRole('heading', { name, exact: true })).toHaveCount(0);
    }
    const privateImage = catalogueMedia().find((image) => image.visibility === 'private');
    expect(privateImage).toBeTruthy();
    const html = await page.content();
    expect(html).not.toContain(privateImage.path);
    expect(html).not.toContain('Unpublished private image');
    expect(requested.filter((pathname) => pathname.startsWith('/admin/'))).toEqual([]);
    await axe(page);
});

test('keeps each brand\'s content and image choices while showing their shared price and stock', async ({ page }) => {
    await page.goto(catalogue());
    const first = card(page, COURSE_A);
    const imageA = await first.getByRole('img').getAttribute('src');
    await page.goto(catalogue(SITE_B));
    await expect(page.getByRole('article')).toHaveCount(1);
    const second = card(page, COURSE_B);
    await expect(second).toContainText('Learn in the mountains.');
    await expect(second).toContainText('USD 20.00');
    await expect(second).toContainText('Available');
    const imageB = await second.getByRole('img').getAttribute('src');
    expect(imageA).toBeTruthy();
    expect(imageB).toBeTruthy();
    expect(imageB).not.toBe(imageA);
    await expect(page.getByRole('heading', { name: COURSE_A, exact: true })).toHaveCount(0);
    const nested = await page.goto(catalogue(NESTED_PREFIX));
    expect(nested.status()).toBe(200);
    await expect(card(page, 'Nested course')).toContainText('USD 20.00');
});

test('gives a staff session no reader access badge or private image URL', async ({ browser }) => {
    const context = await browser.newContext({ storageState: '.playwright/admin-auth.json' });
    try {
        const page = await context.newPage();
        const response = await page.goto(catalogue());
        expect(response.status()).toBe(200);
        await expect(card(page, COURSE_A)).toBeVisible();
        await expect(held(page)).toHaveCount(0);
        await expect(card(page, PRIVATE_PRODUCT).getByRole('img')).toHaveCount(0);
        const privateImage = catalogueMedia().find((image) => image.visibility === 'private');
        expect(await page.content()).not.toContain(privateImage.path);
    } finally {
        await context.close();
    }
});

test('saves one shared access entitlement from product admin and clears its badges when that mapping is removed', async ({ browser }) => {
    const ownerContext = await browser.newContext({ storageState: '.playwright/admin-auth.json' });
    const readerContext = await browser.newContext();
    const id = catalogueCourseId();
    const edit = (site) => `/admin/${site}/storefront/products/${id}/edit`;
    const newName = 'course.short-game';
    try {
        const owner = await ownerContext.newPage();
        const reader = await readerContext.newPage();
        await owner.goto(edit(SITE_A));
        const access = owner.getByRole('textbox', { name: 'Access entitlement', exact: true });
        await expect(access).toHaveValue(ENTITLEMENT);
        await access.fill(newName);
        await Promise.all([
            owner.waitForResponse((response) => callsMethod(response, 'save')),
            owner.getByRole('button', { name: 'Save changes', exact: true }).click(),
        ]);
        await expect(owner.locator('.fi-no-notification').getByText('Saved', { exact: true })).toBeVisible();
        await owner.reload();
        await expect(access).toHaveValue(newName);
        await owner.goto(edit(SITE_B));
        await expect(access).toHaveValue(newName);

        await signIn(reader);
        await reader.goto(catalogue());
        await expect(held(reader)).toHaveCount(0);
        expect(grantCatalogueEntitlement('e2e.catalogue:short-game', SITE_A, newName)).toBe('Granted');
        await reader.reload();
        await expect(held(card(reader, COURSE_A))).toBeVisible();

        await access.fill('');
        await Promise.all([
            owner.waitForResponse((response) => callsMethod(response, 'save')),
            owner.getByRole('button', { name: 'Save changes', exact: true }).click(),
        ]);
        await expect(owner.locator('.fi-no-notification').getByText('Saved', { exact: true })).toBeVisible();
        await owner.goto(edit(SITE_A));
        await expect(access).toHaveValue('');
        await reader.reload();
        await expect(card(reader, COURSE_A)).toBeVisible();
        await expect(held(reader)).toHaveCount(0);
    } finally {
        try {
            setCatalogueEntitlementName(ENTITLEMENT);
        } finally {
            await ownerContext.close();
            await readerContext.close();
        }
    }
});

test('paginates in a stable order and can be followed by keyboard without horizontal overflow on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(catalogue());
    const headings = await page.getByRole('article').getByRole('heading', { level: 2 }).allTextContents();
    await expect(page.getByRole('link', { name: 'Previous', exact: true })).toHaveCount(0);
    const next = page.getByRole('link', { name: 'Next', exact: true });
    await expect(next).toHaveAttribute('href', `${catalogue()}?page=2`);
    await next.focus();
    await expect(next).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(new RegExp(`${catalogue()}\\?page=2$`));
    await expect(page.getByRole('article')).toHaveCount(2);
    await expect(card(page, 'Catalogue item 24')).toContainText('Sold out');
    await expect(page.getByRole('link', { name: 'Next', exact: true })).toHaveCount(0);
    const previous = page.getByRole('link', { name: 'Previous', exact: true });
    await previous.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('article').getByRole('heading', { level: 2 })).toHaveText(headings);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await axe(page);
});

test('shows a reader\'s held badge only on the site that granted it, and never from a different org\'s session', async ({ page }) => {
    await signIn(page);
    const response = await page.goto(catalogue());
    expect(response.headers()['cache-control']).toContain('no-store');
    await expect(held(card(page, COURSE_A))).toBeVisible();
    await page.goto(catalogue(SITE_B));
    await expect(card(page, COURSE_B)).toBeVisible();
    await expect(held(page)).toHaveCount(0);
    await page.goto(catalogue(RIVAL_SITE));
    await expect(card(page, RIVAL_PRODUCT)).toBeVisible();
    await expect(held(page)).toHaveCount(0);

    // This org's own reader holds the same name here. Its session still gives no badge on another org's catalogue.
    expect(grantCatalogueEntitlement('e2e.catalogue:rival', RIVAL_SITE)).toBe('Granted');
    await signIn(page, RIVAL_SITE, RIVAL_READER);
    await page.goto(catalogue(RIVAL_SITE));
    await expect(held(card(page, RIVAL_PRODUCT))).toBeVisible();
    await page.goto(catalogue());
    await expect(held(page)).toHaveCount(0);
});

test('keeps access while either source is live and removes it after both sources are revoked', async ({ page }) => {
    await signIn(page);
    await page.goto(catalogue());
    await expect(held(card(page, COURSE_A))).toBeVisible();
    expect(revokeCatalogueEntitlement('e2e.catalogue:1')).toBe('revoked');
    await page.reload();
    await expect(held(card(page, COURSE_A))).toBeVisible();
    expect(revokeCatalogueEntitlement('e2e.catalogue:2')).toBe('revoked');
    await page.reload();
    await expect(card(page, COURSE_A)).toBeVisible();
    await expect(held(page)).toHaveCount(0);
});

test('keeps the public catalogue usable when reader pages are switched off', async ({ page }) => {
    setCatalogueReaderMode('off');
    try {
        const response = await page.goto(catalogue());
        expect(response.status()).toBe(200);
        await expect(card(page, COURSE_A)).toBeVisible();
        await expect(held(page)).toHaveCount(0);
        const signInResponse = await page.goto(`/${SITE_A}/account/sign-in`);
        expect(signInResponse.status()).toBe(404);
    } finally {
        setCatalogueReaderMode('sign-in');
    }
});

test('keeps English interface copy readable on an Arabic site and resets the document for the next site', async ({ page }) => {
    await page.goto(catalogue(RTL_SITE));
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('heading', { level: 1, name: 'Catalogue' })).toBeVisible();
    const arabic = card(page, 'درس ساحلي');
    await expect(arabic).toBeVisible();
    await expect(arabic.getByRole('heading', { level: 2 })).toHaveAttribute('lang', 'ar');
    await expect(arabic.getByRole('heading', { level: 2 })).toHaveAttribute('dir', 'auto');
    await expect(arabic.getByText('تعلّم بجانب البحر.', { exact: true })).toHaveAttribute('lang', 'ar');
    await expect(arabic.getByRole('img')).toHaveAttribute('lang', 'ar');
    await expect(arabic.getByRole('img')).toHaveAttribute('alt', 'درس ساحلي');
    await expect(page.getByRole('main')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('main')).toHaveAttribute('dir', 'ltr');
    expect(await page.getByRole('main').evaluate((element) => getComputedStyle(element).direction)).toBe('ltr');
    await expect(page.getByRole('link', { name: 'Skip to catalogue', exact: true })).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('link', { name: 'Skip to catalogue', exact: true })).toHaveAttribute('dir', 'ltr');
    await expect(page.getByRole('navigation', { name: 'Site navigation', exact: true })).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('navigation', { name: 'Site navigation', exact: true })).toHaveAttribute('dir', 'ltr');
    await axe(page);
    await page.goto(catalogue());
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
});

test('renders an empty catalogue and refuses malformed or unclaimed addresses', async ({ page }) => {
    const response = await page.goto(catalogue(EMPTY_SITE));
    expect(response.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: 'Catalogue' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'No products listed yet', exact: true })).toBeVisible();
    await expect(page.getByRole('article')).toHaveCount(0);
    await expect(page.getByRole('link', { name: /^(Previous|Next)$/ })).toHaveCount(0);
    await axe(page);
    const later = await page.goto(`${catalogue(EMPTY_SITE)}?page=2`);
    expect(later.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 2, name: 'No products available on this page', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Previous', exact: true })).toHaveAttribute('href', catalogue(EMPTY_SITE));
    const last = await page.goto(`${catalogue()}?page=10000`);
    expect(last.status()).toBe(200);
    await expect(page.getByRole('article')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Next', exact: true })).toHaveCount(0);
    for (const suffix of ['?page=0', '?page=01', '?page=1.0', '?page=10001', '?page[]=1']) {
        const refused = await page.goto(`${catalogue()}${suffix}`);
        expect(refused.status(), suffix).toBe(404);
    }
    const unclaimed = await page.goto('/no-catalogue-site/catalogue');
    expect(unclaimed.status()).toBe(404);
});

test('refuses the catalogue when its product type or commerce is disabled and recovers after each is enabled', async ({ page }) => {
    setCatalogueProductType(false);
    try {
        const disabled = await page.goto(catalogue());
        expect(disabled.status()).toBe(404);
    } finally {
        setCatalogueProductType(true);
    }
    artisan(['kitsune:module', 'disable', 'kitsune/commerce', '--no-interaction']);
    try {
        const disabled = await page.goto(catalogue());
        expect(disabled.status()).toBe(404);
        const admin = await page.goto('/admin/login');
        expect(admin.status()).toBe(200);
    } finally {
        artisan(['kitsune:module', 'enable', 'kitsune/commerce', '--no-interaction']);
    }
    const restored = await page.goto(catalogue());
    expect(restored.status()).toBe(200);
    await expect(card(page, COURSE_A)).toBeVisible();
});

test('shows an unavailable response during an entitlement-store outage instead of a false no-access badge', async ({ page }) => {
    await signIn(page);
    php("Illuminate\\Support\\Facades\\Schema::rename('entitlements','catalogue_entitlements_held');");
    try {
        const response = await page.goto(catalogue());
        expect(response.status()).toBe(503);
        expect(response.headers()['cache-control']).toContain('no-store');
        await expect(page.getByRole('heading', { level: 1, name: 'Catalogue unavailable', exact: true })).toBeVisible();
        await expect(page.getByRole('article')).toHaveCount(0);
        await expect(held(page)).toHaveCount(0);
        await expect(page.getByRole('button', { name: /buy|checkout/i })).toHaveCount(0);
        expect(await page.content()).not.toContain(READER.email);
    } finally {
        php("Illuminate\\Support\\Facades\\Schema::rename('catalogue_entitlements_held','entitlements');");
    }
    await page.goto(catalogue());
    await expect(held(card(page, COURSE_A))).toBeVisible();
});
