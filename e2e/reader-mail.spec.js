// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { READERS } = require('./accounts');
const { mailMark, mailTo, mailSubject, mailLink, mailFacts } = require('./mail');

/*
 * Creating an account and choosing a new password by email — ADR-037's second part, as built. The PHP suite drives
 * every refusal and race; only a browser says the link's secret leaves the address bar, the pages pass axe in both
 * colour schemes and at 320 px, a password manager is told which account a password is for, and a reset signs another
 * browser out.
 *
 * ⚠️ NO STORAGE STATE, AND A FRESH ADDRESS PER ATTEMPT: a retry must not meet the account its first attempt made.
 *
 * ⚠️ THE THROTTLES ARE RESET BEFORE EACH TEST, by clearing the cache, as `readers.spec.js` does: this suite is one
 * connection, and one address may be mailed once in five minutes. Reader 2's password is put back too, so a retry does
 * not start from the password its first attempt chose.
 */

const SKELETON = path.join(__dirname, '..', 'skeleton');
const GOLFDOM = READERS.golfdom;
const GOLFDOM2 = READERS.golfdom2;
const CHOSEN = 'surf the left break at dawn';

function artisan(...args) {
    return execFileSync('php', ['artisan', ...args], { cwd: SKELETON, encoding: 'utf8' }).trim();
}

/** Reader 2's password, as the seeder set it. */
function resetReader2() {
    const printed = artisan('tinker', '--execute', [
        "app(Kitsune\\Core\\Tenancy\\Context::class)->forget()->setOrg(Kitsune\\Core\\Models\\Org::query()->where('slug', 'golfdom-media')->firstOrFail());",
        `$r = App\\Models\\Reader::findByEmail('${GOLFDOM2.email}');`,
        `$r->forceFill(['password' => Illuminate\\Support\\Facades\\Hash::make('${GOLFDOM2.password}')])->save();`,
        "echo 'reset';",
    ].join(' '));

    if (printed.split('\n').pop() !== 'reset') {
        throw new Error(`reader-mail.spec: reader 2's password was not put back:\n${printed}`);
    }
}

/** An address no attempt has used. */
function fresh(testInfo, name) {
    return `${name}-${Date.now()}-${testInfo.retry}@kitsune.test`;
}

async function signIn(page, prefix, { email, password }) {
    await page.goto(`${prefix}/account/sign-in`);
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
}

/** Asks for a link on a page that has the email field, and answers the mail that went to the address. */
async function ask(page, email) {
    const mark = mailMark();
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByRole('button', { name: 'Send me a link', exact: true }).click();
    await expect(page).toHaveTitle(/^Check your email — /);

    return mailTo(email, mark);
}

/** The contrast ratio of an element's own text against its own background — what axe does not measure inside an `<input>`. */
async function contrastOf(locator) {
    return locator.evaluate((element) => {
        const style = getComputedStyle(element);
        const channels = (color) => color.match(/[\d.]+/g).slice(0, 3).map(Number);
        const luminance = (color) => {
            const [r, g, b] = channels(color).map((c) => {
                const v = c / 255;
                return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
            });
            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };
        const [light, dark] = [luminance(style.color), luminance(style.backgroundColor)].sort((a, b) => b - a);

        return (light + 0.05) / (dark + 0.05);
    });
}

async function axe(page) {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

    expect(results.violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`)).toEqual([]);
}

test.describe.configure({ mode: 'serial' });

test.beforeEach(() => {
    artisan('cache:clear');
    resetReader2();
});

test('signs up from the keyboard, end to end — and the link works no more', async ({ page }, testInfo) => {
    const email = fresh(testInfo, 'newcomer');

    await page.goto('/golfdom/account/sign-in');
    await page.getByRole('link', { name: 'Create an account', exact: true }).click();
    await expect(page).toHaveURL(/\/golfdom\/account\/register$/);
    await expect(page).toHaveTitle('Create an account — Golfdom');

    const mark = mailMark();
    await page.getByLabel('Email address', { exact: true }).focus();
    await page.keyboard.type(email);
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(/\/golfdom\/account\/register$/);
    await expect(page).toHaveTitle('Check your email — Golfdom');

    const mail = await mailTo(email, mark);
    const link = mailLink(mail);

    expect(mailSubject(mail)).toBe('Finish creating your Golfdom account');
    expect(link).toMatch(/^\/golfdom\/account\/register\/complete\?token=[A-Za-z0-9]{43}$/);

    await page.goto(String(link));

    // The secret is out of the address bar, and so out of the history and any later Referer.
    await expect(page).toHaveURL(/\/golfdom\/account\/register\/complete$/);
    await expect(page).toHaveTitle('Choose your password — Golfdom');
    await expect(page.getByLabel('Account', { exact: true })).toHaveValue(email);
    await expect(page.getByLabel('Account', { exact: true })).toHaveAttribute('readonly', '');

    await page.getByLabel('New password', { exact: true }).focus();
    await page.keyboard.type(CHOSEN);
    await page.keyboard.press('Tab');
    await expect(page.getByLabel('Type the same password again', { exact: true })).toBeFocused();
    await page.keyboard.type(CHOSEN);
    await page.keyboard.press('Enter');

    await expect(page).toHaveURL(/\/golfdom\/account$/);
    await expect(page.getByRole('status')).toHaveText('Your account is ready.');
    await expect(page.getByText(`Signed in as ${email}.`, { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(page).toHaveURL(/\/golfdom\/account\/sign-in$/);

    const used = await page.goto(String(link));
    expect(used?.status()).toBe(410);
    await expect(page.getByText('This link doesn\'t work any more.', { exact: false })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Ask for a new link', exact: true })).toHaveAttribute('href', '/golfdom/account/register');

    // And the password chosen signs in.
    await signIn(page, '/golfdom', { email, password: CHOSEN });
    await expect(page).toHaveURL(/\/golfdom\/account$/);
});

test('answers an address that has an account with the same page, and mails it where to sign in instead', async ({ page }) => {
    await page.goto('/golfdom/account/register');
    const mail = await ask(page, GOLFDOM.email);
    const origin = new URL(mailFacts().url).origin;

    expect(mailSubject(mail)).toBe('You already have a Golfdom account');
    expect(mail).toContain(`${origin}/golfdom/account/sign-in`);
    expect(mail).toContain(`${origin}/golfdom/account/recover`);
    expect(mailLink(mail)).toBeNull();
});

test('recovers reader 2: a new password, and the browser signed in with the old one is signed out', async ({ page, browser }) => {
    const elsewhere = await browser.newContext();
    const other = await elsewhere.newPage();
    await signIn(other, '/golfdom', GOLFDOM2);
    await expect(other).toHaveURL(/\/golfdom\/account$/);

    await page.goto('/golfdom/account/sign-in');
    await page.getByRole('link', { name: 'Forgotten your password?', exact: true }).click();
    await expect(page).toHaveTitle('Forgotten your password? — Golfdom');

    const mail = await ask(page, GOLFDOM2.email);
    expect(mailSubject(mail)).toBe('Choose a new password for Golfdom');

    await page.goto(String(mailLink(mail)));
    await expect(page).toHaveURL(/\/golfdom\/account\/reset$/);
    await expect(page).toHaveTitle('Choose a new password — Golfdom');
    await page.getByLabel('New password', { exact: true }).fill(CHOSEN);
    await page.getByLabel('Type the same password again', { exact: true }).fill(CHOSEN);
    await page.getByRole('button', { name: 'Change my password', exact: true }).click();

    await expect(page).toHaveURL(/\/golfdom\/account$/);
    await expect(page.getByRole('status')).toHaveText('Your password has been changed, and you\'ve been signed out everywhere else.');

    await other.reload();
    await expect(other).toHaveURL(/\/golfdom\/account\/sign-in$/);

    await signIn(other, '/golfdom', GOLFDOM2);
    await expect(other).toHaveTitle('Error: Sign in — Golfdom');
    await signIn(other, '/golfdom', { email: GOLFDOM2.email, password: CHOSEN });
    await expect(other).toHaveURL(/\/golfdom\/account$/);

    await elsewhere.close();
});

test('opens Golfdom\'s link at Golfdom only — not at its French sibling, not at another org\'s site', async ({ page }) => {
    await page.goto('/golfdom/account/recover');
    const link = String(mailLink(await ask(page, GOLFDOM2.email)));

    for (const prefix of ['/golfdom-fr', '/rival']) {
        const elsewhere = await page.goto(link.replace(/^\/golfdom/, prefix));
        expect(elsewhere?.status(), prefix).toBe(410);
    }

    const own = await page.goto(link);
    expect(own?.status()).toBe(200);
    await expect(page).toHaveTitle('Choose a new password — Golfdom');
});

test('follows each site\'s mode: sign-up where accounts are open, recovery wherever a reader can sign in', async ({ page }) => {
    expect((await page.goto('/golfdom-fr/account/register'))?.status()).toBe(404);
    expect((await page.goto('/golfdom-fr/account/recover'))?.status()).toBe(200);

    await page.goto('/golfdom-fr/account/sign-in');
    await expect(page.getByRole('link', { name: 'Forgotten your password?', exact: true })).toHaveAttribute('href', '/golfdom-fr/account/recover');
    await expect(page.getByRole('link', { name: 'Create an account', exact: true })).toHaveCount(0);

    await page.goto('/golfdom/account/sign-in');
    await expect(page.getByRole('link', { name: 'Create an account', exact: true })).toHaveAttribute('href', '/golfdom/account/register');
});

test('answers an address with no account with the same page, and mails it a note with no link', async ({ page }, testInfo) => {
    const email = fresh(testInfo, 'nobody');

    await page.goto('/golfdom/account/recover');
    const mail = await ask(page, email);

    expect(mailSubject(mail)).toBe('No Golfdom account uses this address');
    expect(mailLink(mail)).toBeNull();
});

test('passes axe on every new page in both colour schemes, and reflows at 320 px', async ({ page }, testInfo) => {
    // A long address with no hyphen, which a browser cannot break at `@` or `.` on its own (review).
    const long = `alexandrakonstantinopoulou${Date.now()}r${testInfo.retry}@universityofcambridgealumni.kitsune.test`;

    await page.goto('/golfdom/account/register');
    const link = String(mailLink(await ask(page, long)));

    const pages = [
        ['the sign-up page', async () => page.goto('/golfdom/account/register')],
        ['a refused address', async () => {
            await page.goto('/golfdom/account/recover');
            await page.getByLabel('Email address', { exact: true }).fill('not-an-address');
            await page.getByRole('button', { name: 'Send me a link', exact: true }).click();
            await expect(page).toHaveTitle('Error: Forgotten your password? — Golfdom');
        }],
        ['the password page', async () => {
            await page.goto(link);
            // The address a password manager saves under, read-only, legible in either scheme.
            expect(await contrastOf(page.getByLabel('Account', { exact: true }))).toBeGreaterThanOrEqual(4.5);
        }],
        ['a refused password', async () => {
            await page.goto('/golfdom/account/register/complete');
            await page.getByLabel('New password', { exact: true }).fill(CHOSEN);
            await page.getByLabel('Type the same password again', { exact: true }).fill(`${CHOSEN}!`);
            await page.getByRole('button', { name: 'Create my account', exact: true }).click();
            await expect(page).toHaveTitle('Error: Choose your password — Golfdom');
        }],
        ['a link that does not work', async () => page.goto('/golfdom/account/reset')],
    ];

    for (const scheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme: /** @type {'light' | 'dark'} */ (scheme) });

        for (const [name, open] of pages) {
            await page.setViewportSize({ width: 1280, height: 800 });
            await open();
            await axe(page);

            await page.setViewportSize({ width: 320, height: 640 });
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            expect(overflow, `${name} in ${scheme} at 320 px`).toBeLessThanOrEqual(0);
        }
    }
});
