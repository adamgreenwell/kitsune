// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {
    SITE_A, SITE_B, RIVAL_SITE, RTL_SITE, NESTED_PREFIX, COURSE_A, COURSE_B, PRIVATE_PRODUCT, READER,
    artisan, setCatalogueProductType,
} = require('./public-catalogue-fixture');
const { resetCartFixtures, setCartCourse, cartStorage, expireCartFixtures, cartCookieNames } = require('./cart-fixture');

const cart = (site = SITE_A) => `/${site}/cart`;
const catalogue = (site = SITE_A) => `/${site}/catalogue`;
const item = (page, title) => page.getByRole('article').filter({ has: page.getByRole('heading', { level: 2, name: title, exact: true }) });
const quantity = (page, title = COURSE_A) => item(page, title).getByRole('spinbutton', { name: `Quantity for ${title}`, exact: true });

async function add(page, title = COURSE_A, site = SITE_A) {
    await page.goto(catalogue(site));
    await item(page, title).getByRole('button', { name: 'Add to cart', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${cart(site)}$`));
}

async function signIn(page, site = SITE_A) {
    await page.goto(`/${site}/account/sign-in`);
    await page.getByLabel('Email address', { exact: true }).fill(READER.email);
    await page.getByLabel('Password', { exact: true }).fill(READER.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/${site}/account$`));
}

async function axe(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(result.violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function bodyToken(page) {
    return page.locator('input[name="_token"]').first().inputValue();
}

test.describe.configure({ mode: 'serial' });
test.beforeEach(() => {
    artisan(['cache:clear']);
    resetCartFixtures();
});
test.afterEach(() => {
    setCatalogueProductType(true);
    resetCartFixtures();
});

test('adds, replays, changes and removes quantities by keyboard with exact totals, without reserving stock', async ({ page }) => {
    await page.goto(cart());
    await expect(page.getByRole('heading', { level: 2, name: 'Your cart is empty' })).toBeVisible();
    await axe(page);
    await page.getByRole('link', { name: 'Continue browsing', exact: true }).click();
    const addButton = item(page, COURSE_A).getByRole('button', { name: 'Add to cart', exact: true });
    await addButton.focus();
    await page.keyboard.press('Enter');
    await expect(quantity(page)).toHaveValue('1');
    await expect(page.getByRole('heading', { name: 'Current total: USD 20.00', exact: true })).toBeVisible();
    await quantity(page).focus();
    await quantity(page).fill('3');
    await page.keyboard.press('Tab');
    await expect(item(page, COURSE_A).getByRole('button', { name: 'Update quantity', exact: true })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { name: 'Current total: USD 60.00', exact: true })).toBeVisible();
    await add(page);
    await expect(page.getByRole('article')).toHaveCount(1);
    await expect(quantity(page)).toHaveValue('3');
    await add(page, PRIVATE_PRODUCT);
    await expect(page.getByRole('heading', { name: 'Current total: USD 72.50', exact: true })).toBeVisible();
    await expect(item(page, PRIVATE_PRODUCT).getByRole('img')).toHaveCount(0);
    await axe(page);
    expect(cartStorage().stock).toBe('12');
    await item(page, PRIVATE_PRODUCT).getByRole('button', { name: `Remove ${PRIVATE_PRODUCT}`, exact: true }).click();
    await item(page, COURSE_A).getByRole('button', { name: `Remove ${COURSE_A}`, exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: /checkout/i })).toHaveCount(0);
});

test('keeps guest selections private to their browser and exact site, including a sibling and a rival org', async ({ page, browser }) => {
    await add(page);
    const other = await browser.newContext();
    try {
        const stranger = await other.newPage();
        await stranger.goto(cart());
        await expect(stranger.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
        await page.goto(catalogue());
        const listing = await item(page, COURSE_A).locator('input[name="listing"]').inputValue();
        await page.goto(catalogue(SITE_B));
        const refused = await page.request.post(`${cart(SITE_B)}/add`, { form: { _token: await bodyToken(page), listing, quantity: '1' } });
        expect(refused.status()).toBe(422);
        await page.goto(cart(SITE_B));
        await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
        await add(page, COURSE_B, SITE_B);
        await page.goto(cart(RIVAL_SITE));
        await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
        await page.goto(cart());
        await expect(item(page, COURSE_A)).toBeVisible();
        await expect(item(page, COURSE_B)).toHaveCount(0);
    } finally {
        await other.close();
    }
});

test('requires the body token and refuses malformed quantities without echoing submitted fields', async ({ page }) => {
    await page.goto(catalogue());
    const listing = await item(page, COURSE_A).locator('input[name="listing"]').inputValue();
    const token = await bodyToken(page);
    const missing = await page.request.post(`${cart()}/add?_token=${encodeURIComponent(token)}`, {
        form: { listing, quantity: '1', password: 'cart-private-body-probe' },
        headers: { 'X-CSRF-TOKEN': token, 'Sec-Fetch-Site': 'same-origin' },
        maxRedirects: 0,
    });
    expect(missing.status()).toBe(419);
    expect(missing.headers()['cache-control']).toContain('no-store');
    expect(await missing.text()).not.toContain('cart-private-body-probe');
    const invalid = await page.request.post(`${cart()}/add`, {
        form: { _token: token, listing, quantity: '100', password: 'cart-private-body-probe' }, maxRedirects: 0,
    });
    expect(invalid.status()).toBe(422);
    expect(await invalid.text()).not.toContain('cart-private-body-probe');
    await page.goto(cart());
    await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
});

test('shows a stale-tab refusal and the latest quantity rather than overwriting another tab', async ({ page, context }) => {
    await add(page);
    const oldTab = await context.newPage();
    try {
        await oldTab.goto(cart());
        await quantity(page).fill('2');
        await item(page, COURSE_A).getByRole('button', { name: 'Update quantity', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Current total: USD 40.00', exact: true })).toBeVisible();
        await quantity(oldTab).fill('3');
        const refused = oldTab.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === `${cart()}/update`);
        await item(oldTab, COURSE_A).getByRole('button', { name: 'Update quantity', exact: true }).click();
        expect((await refused).status()).toBe(409);
        await expect(oldTab.getByRole('alert')).toContainText('Your cart changed in another tab');
        await expect(oldTab.getByRole('alert')).toBeFocused();
        await expect(quantity(oldTab)).toHaveValue('2');
        await axe(oldTab);
    } finally {
        await oldTab.close();
    }
});

test('rechecks price and stock, keeps unavailable lines removable and does not show their stale price', async ({ page }) => {
    await add(page);
    setCartCourse({ price: '2500' });
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Current total: USD 25.00', exact: true })).toBeVisible();
    setCartCourse({ price: '2500', stock: '0' });
    await page.reload();
    await expect(page.getByText('This item is no longer available. Remove it from your cart to continue.', { exact: true })).toBeVisible();
    await expect(page.getByRole('spinbutton')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: /^Current total:/ })).toHaveCount(0);
    await expect(page.getByText('USD 25.00', { exact: false })).toHaveCount(0);
    await page.getByRole('article').getByRole('button', { name: /^Remove / }).click();
    await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
    await page.goto(`${catalogue()}?page=2`);
    await expect(item(page, 'Catalogue item 24').getByRole('button', { name: 'Add to cart', exact: true })).toBeDisabled();
});

test('merges a guest selection into the reader cart by maximum quantity and keeps it saved after sign-out', async ({ page, browser }) => {
    const saved = await browser.newContext();
    try {
        const reader = await saved.newPage();
        await signIn(reader);
        await add(reader);
        await quantity(reader).fill('2');
        await item(reader, COURSE_A).getByRole('button', { name: 'Update quantity', exact: true }).click();
    } finally {
        await saved.close();
    }
    await add(page);
    await quantity(page).fill('3');
    await item(page, COURSE_A).getByRole('button', { name: 'Update quantity', exact: true }).click();
    await add(page, PRIVATE_PRODUCT);
    await signIn(page);
    await page.goto(cart());
    await expect(page.getByRole('article')).toHaveCount(2);
    await expect(quantity(page)).toHaveValue('3');
    await expect(page.getByRole('heading', { name: 'Current total: USD 72.50', exact: true })).toBeVisible();
    await page.goto(`/${SITE_A}/account`);
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await page.goto(cart());
    await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
    await signIn(page);
    await page.goto(cart());
    await expect(quantity(page)).toHaveValue('3');
    await expect(page.getByRole('article')).toHaveCount(2);
});

test('recovers a guest cart from its private 30-day cookie in a fresh session and adopts it at direct sign-in', async ({ page, browser, context }) => {
    await add(page);
    await quantity(page).fill('2');
    await item(page, COURSE_A).getByRole('button', { name: 'Update quantity', exact: true }).click();
    await add(page, COURSE_B, SITE_B);
    const names = cartCookieNames();
    const remember = (await context.cookies()).find((cookie) => cookie.name === names.remember);
    expect(remember !== undefined).toBe(true);
    if (!remember) throw new Error('The cart remember cookie was not issued.');
    expect({ httpOnly: remember.httpOnly, secure: remember.secure, sameSite: remember.sameSite, path: remember.path })
        .toEqual({ httpOnly: true, secure: false, sameSite: 'Lax', path: '/' });
    expect(remember.expires - Date.now() / 1000).toBeGreaterThan(30 * 86400 - 90);
    expect(remember.expires - Date.now() / 1000).toBeLessThan(30 * 86400 + 90);
    expect(/^[a-f0-9]{64}$/.test(remember.value)).toBe(false);
    expect(await page.evaluate((name) => document.cookie.split(';').some((cookie) => cookie.trim().startsWith(`${name}=`)), names.remember)).toBe(false);
    const persisted = cartStorage();
    const restored = await browser.newContext();
    try {
        // Copy only the encrypted cart cookie. The original auth/session cookie is intentionally absent.
        await restored.addCookies([remember]);
        const fresh = await restored.newPage();
        await fresh.goto(cart());
        await expect(quantity(fresh)).toHaveValue('2');
        await expect(fresh.getByRole('heading', { name: 'Current total: USD 40.00', exact: true })).toBeVisible();
        await fresh.goto(cart(SITE_B));
        await expect(item(fresh, COURSE_B)).toBeVisible();
        expect(cartStorage()).toEqual(persisted);
        // Exercise Login's own restore before adoption, without a cart read in the next auth session.
        await restored.clearCookies({ name: names.session });
        await signIn(fresh);
        await fresh.goto(cart());
        await expect(quantity(fresh)).toHaveValue('2');
        await fresh.goto(cart(SITE_B));
        await expect(fresh.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
        await fresh.goto(`/${SITE_A}/account`);
        await fresh.getByRole('button', { name: 'Sign out', exact: true }).click();
        expect((await restored.cookies()).some((cookie) => cookie.name === names.remember)).toBe(false);
        await fresh.goto(cart(SITE_B));
        await expect(fresh.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
    } finally {
        await restored.close();
    }
});

test('drops guest handles at reader logout even while commerce is disabled, keeping the reader cart saved', async ({ page }) => {
    await add(page, COURSE_B, SITE_B);
    await signIn(page);
    await add(page);
    const names = cartCookieNames();
    expect((await page.context().cookies()).some((cookie) => cookie.name === names.remember)).toBe(true);
    artisan(['kitsune:module', 'disable', 'kitsune/commerce', '--no-interaction']);
    try {
        await page.goto(`/${SITE_A}/account`);
        await page.getByRole('button', { name: 'Sign out', exact: true }).click();
        expect((await page.context().cookies()).some((cookie) => cookie.name === names.remember)).toBe(false);
    } finally {
        artisan(['kitsune:module', 'enable', 'kitsune/commerce', '--no-interaction']);
    }
    await page.goto(cart(SITE_B));
    await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
    await page.goto(cart());
    await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
    await signIn(page);
    await page.goto(cart());
    await expect(item(page, COURSE_A)).toBeVisible();
    await page.goto(cart(SITE_B));
    await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
});

test('keeps both carts when a merge reaches the distinct-item limit, then retries after saved items are removed', async ({ page, browser }) => {
    const saved = await browser.newContext();
    let listings;
    try {
        const reader = await saved.newPage();
        await signIn(reader);
        await reader.goto(catalogue());
        listings = await reader.getByRole('article').locator('input[name="listing"]').evaluateAll((inputs) => inputs.map((input) => input.value));
        const token = await bodyToken(reader);
        for (const listing of listings.slice(0, 20)) {
            const added = await reader.request.post(`${cart()}/add`, { form: { _token: token, listing, quantity: '1' }, maxRedirects: 0 });
            expect(added.status()).toBe(303);
        }
    } finally {
        await saved.close();
    }
    await page.goto(catalogue());
    const token = await bodyToken(page);
    for (const [listing, requested] of [[listings[0], '3'], [listings[20], '1']]) {
        const added = await page.request.post(`${cart()}/add`, { form: { _token: token, listing, quantity: requested }, maxRedirects: 0 });
        expect(added.status()).toBe(303);
    }
    await signIn(page);
    await page.goto(cart());
    await expect(page.getByRole('status')).toContainText('Some guest items could not be added');
    await expect(page.getByRole('article')).toHaveCount(20);
    await expect(quantity(page)).toHaveValue('1');
    await item(page, PRIVATE_PRODUCT).getByRole('button', { name: `Remove ${PRIVATE_PRODUCT}`, exact: true }).click();
    await expect(page.getByRole('status')).toHaveCount(0);
    await expect(page.getByRole('article')).toHaveCount(20);
    await expect(quantity(page)).toHaveValue('3');
});

test('handles expired carts, disabled product types, nested addresses and mobile Arabic controls', async ({ page }) => {
    await add(page);
    expireCartFixtures();
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
    await add(page);
    setCatalogueProductType(false);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Unavailable product', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Remove Unavailable product', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Your cart is empty', exact: true })).toBeVisible();
    setCatalogueProductType(true);
    await add(page, 'Nested course', NESTED_PREFIX);
    await expect(page.getByRole('heading', { name: 'Nested course', exact: true })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await add(page, 'درس ساحلي', RTL_SITE);
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.getByRole('main')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('heading', { name: 'درس ساحلي', exact: true })).toHaveAttribute('lang', 'ar');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await axe(page);
});
