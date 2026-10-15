// @ts-check
const fs = require('node:fs');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { SITE_A, SITE_B, RIVAL_SITE, COURSE_A, READER, artisan, php } = require('./public-catalogue-fixture');
const { literal } = require('./storefront-fixture');
const { resetCartFixtures, setCartCourse, cartStorage } = require('./cart-fixture');
const { mailMark, mailTo, mailFacts } = require('./mail');

const cart = `/${SITE_A}/cart`;
const atSite = `$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause('public order browser fixture bootstrap',fn($q)=>$q->where('slug',${literal(SITE_A)})->firstOrFail());`
    + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);auth()->forgetUser();';

/** Only the dedicated catalogue fixture org: retained historical snapshots deliberately have no cart/catalogue FK. */
function clearOrders() {
    php(atSite + "DB::table('commerce_checkout_attempts')->where('org_id',$site->org_id)->delete();"
        + "DB::table('commerce_orders')->where('org_id',$site->org_id)->delete();"
        + "DB::table('commerce_sales_policies')->where('org_id',$site->org_id)->delete();"
        + "DB::table('commerce_tax_rates')->where('org_id',$site->org_id)->delete();"
        + "DB::table('commerce_settings')->where('org_id',$site->org_id)->delete();");
}

/** Synthetic custody only; no key or provider call. Backend probes separately prove verified transitions. */
function plantOpenTestCheckout(reference) {
    return php(atSite + `$order=app(Kitsune\\Commerce\\Orders\\OrderReaderData::class)->find(DB::connection(),$site->org_id,$site->id,${literal(reference)});`
        + '$record=app(Kitsune\\Commerce\\Orders\\OrderReaderData::class)->record(DB::connection(),$order);'
        + '$reference=bin2hex(random_bytes(16));$now=Carbon\\CarbonImmutable::now("UTC")->startOfSecond();$expires=$now->addSeconds(1860);'
        + '$payload=Kitsune\\Commerce\\Payments\\CheckoutAttempts::payload($record,$reference,$expires->timestamp,' + literal(`http://127.0.0.1:8125/${SITE_A}`) + ');'
        + "DB::table('commerce_checkout_attempts')->insert(['org_id'=>$site->org_id,'site_id'=>$site->id,'order_id'=>$order->id,'reference'=>$reference,'active_order_id'=>$order->id,"
        + "'mode'=>'test','currency'=>'USD','account_id'=>'acct_browserFixture','api_version'=>Kitsune\\Commerce\\Payments\\StripeCheckout::API_VERSION,'credential_witness'=>str_repeat('a',64),'state'=>'open',"
        + "'request_payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'request_hash'=>Kitsune\\Commerce\\Payments\\CheckoutAttempts::digest($payload),'order_hash'=>Kitsune\\Commerce\\Payments\\CheckoutReview::orderHash($record),"
        + "'session_id'=>'cs_test_'.$reference,'checkout_url'=>'https://checkout.stripe.com/c/pay/cs_test_'.$reference.'#private-checkout-capability','reviewed_at'=>$now->format('Y-m-d H:i:s'),'expires_at'=>$expires->format('Y-m-d H:i:s'),"
        + "'lease_token'=>null,'lease_until'=>null,'created_at'=>$now->format('Y-m-d H:i:s'),'updated_at'=>$now->format('Y-m-d H:i:s')]);echo $reference;");
}

function configureOrders() {
    php(atSite + '$writer=app(Kitsune\\Commerce\\Checkout\\CommerceSetupWriter::class);'
        + "$writer->setSettings(['tax_mode'=>'exclusive','shipping_tax_mode'=>'exclusive','shipping_tax_class'=>'shipping']);"
        + "$writer->saveTaxRate(null,['country'=>'US','region'=>'*','tax_class'=>'standard','rate_parts'=>200000]);"
        + "$product=Kitsune\\Commerce\\Models\\Sellable::query()->where('sku','CAT-000')->firstOrFail();"
        + "$writer->setSalesPolicy($product->id,['tax_class'=>'standard','delivery_kind'=>'digital']);");
}

function storedOrders() {
    return JSON.parse(php(atSite + "echo json_encode(['orders'=>DB::table('commerce_orders')->where('org_id',$site->org_id)->count(),"
        + "'lines'=>DB::table('commerce_order_lines')->where('org_id',$site->org_id)->count(),"
        + "'consumed'=>DB::table('commerce_order_access_tokens')->where('org_id',$site->org_id)->whereNotNull('consumed_at')->count(),"
        + "'tokens'=>DB::table('commerce_order_access_tokens')->where('org_id',$site->org_id)->count()]);"));
}

async function axe(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(result.violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

async function add(page) {
    await page.goto(`/${SITE_A}/catalogue`);
    await page.getByRole('article').filter({ has: page.getByRole('heading', { name: COURSE_A, exact: true }) })
        .getByRole('button', { name: 'Add to cart', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${cart}$`));
}

async function review(page, email) {
    await page.getByRole('link', { name: 'Prepare an order', exact: true }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Prepare an order', exact: true })).toBeVisible();
    await page.getByLabel('Email for order access', { exact: true }).fill(email);
    const billing = page.getByRole('group', { name: 'Billing details', exact: true });
    await billing.getByLabel('Name', { exact: true }).fill('Private Browser Buyer');
    await billing.getByLabel('Address line 1', { exact: true }).fill('42 Private Browser Lane');
    await billing.getByLabel('City', { exact: true }).fill('Honolulu');
    await billing.getByLabel('State or region code', { exact: true }).fill('HI');
    await billing.getByLabel('Postal code', { exact: true }).fill('96801');
    await billing.getByLabel('Country code (two uppercase letters)', { exact: true }).fill('US');
    await axe(page);
    await page.getByRole('button', { name: 'Review tax and delivery', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('heading', { level: 1, name: 'Review your order', exact: true })).toBeVisible();
    await expect(page.getByRole('radio', { name: 'No physical delivery — USD 0.00', exact: true })).toBeChecked();
    await axe(page);
}

async function prepare(page) {
    await page.getByRole('button', { name: 'Prepare order', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(new RegExp(`${cart}/orders/[a-f0-9]{32}$`));
    await expect(page.getByRole('heading', { level: 1, name: 'Your order', exact: true })).toBeVisible();
    return new URL(page.url()).pathname;
}

async function signIn(page) {
    await page.goto(`/${SITE_A}/account/sign-in`);
    await page.getByLabel('Email address', { exact: true }).fill(READER.email);
    await page.getByLabel('Password', { exact: true }).fill(READER.password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/${SITE_A}/account$`));
}

/** Check the actual mailed origin, then open only its path on this test server, as core's mail specs do. */
function orderMailLink(mail) {
    const match = mail.match(/https?:\/\/[^\s]+\/cart\/orders\/[a-f0-9]{32}\/access\?token=[a-f0-9]{64}(?=\s)/);
    expect(match).not.toBeNull();
    const link = new URL(match[0]);
    expect(link.origin).toBe(new URL(mailFacts().url).origin);
    return link.pathname + link.search;
}

test.describe.configure({ mode: 'serial' });
test.beforeEach(() => {
    artisan(['cache:clear']);
    clearOrders();
    resetCartFixtures();
    configureOrders();
});
test.afterEach(() => {
    clearOrders();
    resetCartFixtures();
});

test('reviews test checkout by keyboard and permits its native POST redirect under the actual checkout CSP', async ({ page }, testInfo) => {
    await add(page);
    await review(page, `checkout-csp-${testInfo.retry}-${Date.now()}@kitsune.test`);
    const path = await prepare(page);
    await page.getByRole('link', { name: 'Test checkout', exact: true }).click();
    await expect(page).toHaveURL(`${new URL(page.url()).origin}${path}/payment`);
    await expect(page.getByRole('heading', { name: 'Test checkout', exact: true })).toBeVisible();
    const reviewed = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === `${path}/payment/review`);
    await page.getByRole('button', { name: 'Review test checkout', exact: true }).focus();
    await page.keyboard.press('Enter');
    expect((await reviewed).headers()['content-security-policy']).toContain("form-action 'self' https://checkout.stripe.com");
    await expect(page.getByRole('heading', { name: 'Test total: USD 24.00', exact: true })).toBeVisible();
    await axe(page);
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const action = await page.evaluate(() => document.querySelector('form[action$="/payment/start"]')?.getAttribute('action'));
    expect(action).toBe(`${path}/payment/start`);
    let redirected = 0;
    let submitted = 0;
    const problems = [];
    const fixtureUrl = 'https://checkout.stripe.com/c/pay/cs_test_browserFixture';
    const startUrl = new URL(`${path}/payment/start`, page.url()).href;
    // page.route only intercepts the first URL in a redirect chain. Fetch pauses both legs before any network I/O.
    // The actual writer is covered by native HTTP tests; this Chromium witness isolates the real page's form-action.
    const session = await page.context().newCDPSession(page);
    session.on('Fetch.requestPaused', async ({ requestId, request }) => {
        try {
            if (request.url === startUrl && request.method === 'POST') {
                if (! /^[a-f0-9]{64}$/.test(new URLSearchParams(request.postData).get('review'))) {
                    problems.push('The native checkout POST did not carry its reviewed token.');
                    return await session.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' });
                }
                submitted++;
                return await session.send('Fetch.fulfillRequest', { requestId, responseCode: 303, responseHeaders: [{ name: 'Location', value: fixtureUrl }], body: '' });
            }
            if (request.url === fixtureUrl && request.method === 'GET') {
                redirected++;
                return await session.send('Fetch.fulfillRequest', { requestId, responseCode: 200,
                    responseHeaders: [{ name: 'Content-Type', value: 'text/html' }],
                    body: Buffer.from('<!doctype html><html lang="en"><title>Stripe redirect fixture</title><h1>Stripe redirect fixture</h1></html>').toString('base64') });
            }
            problems.push('Unexpected request during the isolated checkout redirect.');
            await session.send('Fetch.failRequest', { requestId, errorReason: 'BlockedByClient' });
        } catch {
            problems.push('The checkout redirect fixture could not fulfil its intercepted request.');
        }
    });
    await page.context().setOffline(true);
    await session.send('Fetch.enable', { patterns: [{ urlPattern: '*', requestStage: 'Request' }] });
    try {
        await page.getByRole('button', { name: 'Continue to Stripe test checkout', exact: true }).focus();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(fixtureUrl);
        await expect(page.getByRole('heading', { name: 'Stripe redirect fixture', exact: true })).toBeVisible();
    } finally {
        await session.send('Fetch.disable');
        await session.detach();
        await page.context().setOffline(false);
    }
    expect(problems).toEqual([]);
    expect(submitted).toBe(1);
    expect(redirected).toBe(1);
    expect(cartStorage().stock).toBe('12');
});

test('shows unresolved test custody without exposing its Session URL and preserves exact guest export and erasure fences', async ({ page }, testInfo) => {
    await add(page);
    await review(page, `checkout-custody-${testInfo.retry}-${Date.now()}@kitsune.test`);
    const path = await prepare(page);
    const reference = path.split('/').pop();
    const attempt = plantOpenTestCheckout(reference);
    const response = await page.goto(`${path}/payment/return?session_id=forged-browser-session`);
    expect(response.status()).toBe(200);
    await expect(page.getByText('Test checkout is open', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Review test checkout', exact: true })).toHaveCount(0);
    expect(await page.content()).not.toMatch(/private-checkout-capability|cs_test_|forged-browser-session/);
    await axe(page);
    await page.getByRole('link', { name: 'Back to order', exact: true }).click();
    const downloadPending = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Download my order data', exact: true }).click();
    const exported = JSON.parse(fs.readFileSync(await (await downloadPending).path(), 'utf8'));
    expect(exported.checkout_attempts[0]).toMatchObject({ reference: attempt, state: 'open', mode: 'test', total_minor: '2400' });
    expect(JSON.stringify(exported)).not.toMatch(/private-checkout-capability|cs_test_|credential_witness|request_payload|lease_token/);
    await page.getByRole('button', { name: 'Cancel this order', exact: true }).click();
    await expect(page.getByText('The order or delivery quote is unavailable. Please check your cart and try again shortly.', { exact: true })).toBeVisible();
    expect(storedOrders().orders).toBe(1);
    await page.goto(path);
    await page.getByRole('button', { name: 'Erase this unpaid order and its personal data', exact: true }).click();
    await expect(page.getByText('The order or delivery quote is unavailable. Please check your cart and try again shortly.', { exact: true })).toBeVisible();
    expect(storedOrders().orders).toBe(1);
    // Simulated verified expiry; native backend probes separately exercise Stripe retrieval and expiry.
    php(atSite + `DB::table('commerce_checkout_attempts')->where('org_id',$site->org_id)->where('site_id',$site->id)->where('reference',${literal(attempt)})`
        + "->update(['state'=>'expired','active_order_id'=>null,'checkout_url'=>null]);");
    await page.goto(`${path}/payment`);
    await expect(page.getByText('Test checkout expired', { exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Back to order', exact: true }).click();
    await page.getByRole('button', { name: 'Erase this unpaid order and its personal data', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${cart}$`));
    expect(storedOrders()).toEqual({ orders: 0, lines: 0, consumed: 0, tokens: 0 });
});

test('prepares an exact unpaid guest invoice by keyboard, retains its original amount and supports export cancellation and erasure', async ({ page }, testInfo) => {
    await add(page);
    await review(page, `order-invoice-${testInfo.retry}-${Date.now()}@kitsune.test`);
    const path = await prepare(page);
    await expect(page.getByRole('heading', { name: 'Quoted total: USD 24.00', exact: true })).toBeVisible();
    await expect(page.getByText('No payment has been taken. This order does not grant access or reserve stock.', { exact: true })).toBeVisible();
    await axe(page);
    expect(cartStorage().stock).toBe('12');
    setCartCourse({ price: '2500' });
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Quoted total: USD 24.00', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Your orders', exact: true }).last().click();
    const reference = path.split('/').pop();
    await page.getByRole('link', { name: reference, exact: true }).click();
    const downloadPending = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Download my order data', exact: true }).click();
    const download = await downloadPending;
    expect(download.suggestedFilename()).toBe('kitsune-order.json');
    const exported = JSON.parse(fs.readFileSync(await download.path(), 'utf8'));
    expect(JSON.stringify(exported)).toContain('Private Browser Buyer');
    expect(JSON.stringify(exported)).toContain('42 Private Browser Lane');
    expect(JSON.stringify(exported)).not.toMatch(/owner_key|request_digest|token_hash|cart_ref/);
    await page.getByRole('button', { name: 'Cancel this order', exact: true }).click();
    await expect(page.getByText('Cancelled', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Erase this unpaid order and its personal data', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${cart}$`));
    expect(storedOrders()).toEqual({ orders: 0, lines: 0, consumed: 0, tokens: 0 });
    expect((await page.goto(path)).status()).toBe(404);
});

test('recovers a guest order in a fresh browser through an actual one-use email link with a clean confirmation address', async ({ page, browser }, testInfo) => {
    await add(page);
    const email = `order-access-${testInfo.retry}-${Date.now()}@kitsune.test`;
    await review(page, email);
    const mark = mailMark();
    const path = await prepare(page);
    const link = orderMailLink(await mailTo(email, mark));
    const strangerContext = await browser.newContext();
    const replayContext = await browser.newContext();
    try {
        const stranger = await strangerContext.newPage();
        expect((await stranger.goto(path)).status()).toBe(404);
        await stranger.goto(link);
        await expect(stranger).toHaveURL(new RegExp(`${path}/access$`));
        await expect(stranger.getByRole('heading', { name: 'Open your order', exact: true })).toBeVisible();
        expect(storedOrders().consumed).toBe(0);
        expect(await stranger.content()).not.toContain('token=');
        await axe(stranger);
        await stranger.getByRole('button', { name: 'Continue to my order', exact: true }).focus();
        await stranger.keyboard.press('Enter');
        await expect(stranger).toHaveURL(new RegExp(`${path}$`));
        await expect(stranger.getByText('Private Browser Buyer', { exact: false })).toBeVisible();
        expect(storedOrders().consumed).toBe(1);
        const replay = await replayContext.newPage();
        await replay.goto(link);
        await replay.getByRole('button', { name: 'Continue to my order', exact: true }).click();
        await expect(replay.getByText('This access link is expired, already used or unavailable. You can request a fresh link below.', { exact: true })).toBeVisible();
        expect((await replay.goto(path)).status()).toBe(404);
    } finally {
        await strangerContext.close();
        await replayContext.close();
    }
});

test('refuses stale prepared quotes and never exposes a guest invoice through sibling rival or unknown site addresses', async ({ page }, testInfo) => {
    await add(page);
    await review(page, `order-stale-${testInfo.retry}-${Date.now()}@kitsune.test`);
    setCartCourse({ price: '2500' });
    await page.getByRole('button', { name: 'Prepare order', exact: true }).click();
    await expect(page.getByText('The cart, prices or delivery settings changed. Review a fresh quote before preparing the order.', { exact: true })).toBeVisible();
    expect(storedOrders().orders).toBe(0);
    await page.goto(cart);
    await review(page, `order-scopes-${testInfo.retry}-${Date.now()}@kitsune.test`);
    const path = await prepare(page);
    const reference = path.split('/').pop();
    for (const site of [SITE_B, RIVAL_SITE, 'unknown-order-site']) {
        const response = await page.goto(`/${site}/cart/orders/${reference}?invoice=PrivateBrowserSecret`);
        expect(response.status()).toBe(404);
        expect(response.headers()['cache-control']).toContain('no-store');
        expect(await page.content()).not.toMatch(/Private Browser Buyer|Private Browser Lane|PrivateBrowserSecret/);
    }
    const unknown = await page.goto('/unknown-order-site/cart/checkout');
    expect(unknown.status()).toBe(404);
});

test('keeps a reader invoice saved across sign-out and a later sign-in while denying the signed-out browser', async ({ page }) => {
    await signIn(page);
    await add(page);
    await review(page, READER.email);
    const path = await prepare(page);
    await expect(page.getByRole('button', { name: 'Email me an access link', exact: true })).toHaveCount(0);
    await page.goto(`/${SITE_A}/account`);
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    expect((await page.goto(path)).status()).toBe(404);
    await signIn(page);
    await page.goto(`${cart}/orders`);
    const reference = path.split('/').pop();
    await page.getByRole('link', { name: reference, exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Quoted total: USD 24.00', exact: true })).toBeVisible();
    expect(cartStorage().stock).toBe('12');
});

for (const oldLinkState of ['used', 'expired']) {
    test(`requests fresh access mail without owning an order after the previous link is ${oldLinkState}`, async ({ page, browser }, testInfo) => {
        await add(page);
        const email = `order-resend-${oldLinkState}-${testInfo.retry}-${Date.now()}@kitsune.test`;
        await review(page, email);
        const firstMark = mailMark();
        const path = await prepare(page);
        const oldLink = orderMailLink(await mailTo(email, firstMark));
        const reference = path.split('/').pop();
        if (oldLinkState === 'used') {
            await page.goto(oldLink);
            await page.getByRole('button', { name: 'Continue to my order', exact: true }).click();
            await expect(page).toHaveURL(new RegExp(`${path}$`));
            expect(storedOrders().consumed).toBe(1);
        } else {
            // Only this invoice's test token is aged; the live web worker keeps its real clock.
            php(atSite + `$order=DB::table('commerce_orders')->where('org_id',$site->org_id)->where('site_id',$site->id)->where('reference',${literal(reference)})->firstOrFail();`
                + "DB::table('commerce_order_access_tokens')->where('org_id',$site->org_id)->where('site_id',$site->id)->where('order_id',$order->id)"
                + "->update(['created_at'=>now('UTC')->subMinutes(31)->format('Y-m-d H:i:s'),'expires_at'=>now('UTC')->subSecond()->format('Y-m-d H:i:s')]);");
        }
        const visitorContext = await browser.newContext();
        const replayContext = await browser.newContext();
        try {
            const visitor = await visitorContext.newPage();
            expect((await visitor.goto(path)).status()).toBe(404);
            await visitor.goto(oldLink);
            await expect(visitor).toHaveURL(new RegExp(`${path}/access$`));
            await expect(visitor.getByRole('button', { name: 'Email me an access link', exact: true })).toBeVisible();
            await expect(visitor.getByRole('textbox')).toHaveCount(0);
            expect(await visitor.content()).not.toContain(email);
            if (oldLinkState === 'expired') {
                await visitor.getByRole('button', { name: 'Continue to my order', exact: true }).click();
                await expect(visitor.getByText('This access link is expired, already used or unavailable. You can request a fresh link below.', { exact: true })).toBeVisible();
                await expect(visitor.getByRole('button', { name: 'Email me an access link', exact: true })).toBeVisible();
                await expect(visitor.getByRole('textbox')).toHaveCount(0);
                expect(await visitor.content()).not.toContain(email);
            }
            await axe(visitor);
            const resend = visitor.locator(`form[action="${path}/link"]`);
            await expect(resend).toHaveAttribute('method', 'post');
            await expect(resend.locator('input[name="_token"]')).toHaveCount(1);
            await expect(resend.locator('input[name="email"]')).toHaveCount(0);
            // Clear only this fixture recipient's mail cooldown instead of sleeping five minutes.
            // Keep the daily and connection limits intact, and leave other recipients' counters alone.
            php(atSite + `Illuminate\\Support\\Facades\\RateLimiter::clear('kitsune:readers:mail:'.hash_hmac('sha256',$site->org_id.'|'.${literal(email)},(string)config('app.key')));`);
            const freshMark = mailMark();
            await resend.getByRole('button', { name: 'Email me an access link', exact: true }).click();
            await expect(visitor.getByText('If the order is available, an access link will be sent to its saved email address.', { exact: true })).toBeVisible();
            const freshLink = orderMailLink(await mailTo(email, freshMark));
            expect(freshLink).not.toBe(oldLink);
            expect(storedOrders().tokens).toBe(1);
            expect(storedOrders().consumed).toBe(0);
            await visitor.goto(freshLink);
            await expect(visitor).toHaveURL(new RegExp(`${path}/access$`));
            expect(await visitor.content()).not.toContain('token=');
            await visitor.getByRole('button', { name: 'Continue to my order', exact: true }).click();
            await expect(visitor).toHaveURL(new RegExp(`${path}$`));
            await expect(visitor.getByText('Private Browser Buyer', { exact: false })).toBeVisible();
            const replay = await replayContext.newPage();
            await replay.goto(oldLink);
            await replay.getByRole('button', { name: 'Continue to my order', exact: true }).click();
            await expect(replay.getByText('This access link is expired, already used or unavailable. You can request a fresh link below.', { exact: true })).toBeVisible();
            expect((await replay.goto(path)).status()).toBe(404);
        } finally {
            await visitorContext.close();
            await replayContext.close();
        }
    });
}
