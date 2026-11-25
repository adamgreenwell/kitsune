// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { randomBytes, randomUUID } = require('node:crypto');
const path = require('node:path');
const { BLOG_OWNER } = require('./accounts');
const { waitForAdminPassword } = require('./admin-sign-in');

// ADR-041's prefix policy, through actual public routes and both authentication boundaries.
const SKELETON = path.join(__dirname, '..', 'skeleton');
const literal = (value) => `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
const readerSecret = randomBytes(32).toString('base64url');
let fixture;

// As in readers.spec.js, reset the fixture's shared sign-in throttle; product throttling is unchanged.
test.beforeEach(() => execFileSync('php', ['artisan', 'cache:clear'], { cwd: SKELETON, encoding: 'utf8' }));

test.beforeAll(() => {
    const tag = randomUUID().slice(0, 8);
    const slug = `prefix-browser-${tag}`;
    const prefix = `/PrEfIx-${tag}/NeWs/Fr`;
    const email = `prefix-${tag}@kitsune.test`;
    const code = [
        "$org=Kitsune\\Core\\Models\\Org::query()->where('slug','inkwell')->firstOrFail();",
        "$c=app(Kitsune\\Core\\Tenancy\\Context::class);$c->forget()->setOrg($org);",
        `$site=Kitsune\\Core\\Models\\Site::create(['handle'=>${literal(slug)},'slug'=>${literal(slug)},'name'=>'Prefix browser fixture','locale'=>'fr','base_url'=>${literal(prefix)},'settings'=>['reader_accounts'=>'sign-in']]);`,
        `$owner=App\\Models\\User::query()->where('email',${literal(BLOG_OWNER.email)})->firstOrFail();$owner->admitToSite($site);`,
        "$secret=rtrim(stream_get_contents(STDIN),\"\\r\\n\");",
        `$reader=App\\Models\\Reader::createReader(${literal(email)},Illuminate\\Support\\Facades\\Hash::make($secret),Carbon\\CarbonImmutable::now());`,
        "echo json_encode(['site'=>(int)$site->id,'org'=>(int)$org->id,'reader'=>(int)$reader->id,'slug'=>$site->slug,'prefix'=>$site->base_url,'canonical'=>$site->path_prefix]);",
    ].join(' ');
    fixture = JSON.parse(execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        cwd: SKELETON, encoding: 'utf8', input: readerSecret + '\n',
    }));
    fixture.readerEmail = email;
});

test.afterAll(() => {
    if (!fixture) return;
    // Only rows created above; no existing account, Site, membership or setting is changed.
    const code = [
        `DB::table('readers')->where('id',${Number(fixture.reader)})->where('org_id',${Number(fixture.org)})->where('email',${literal(fixture.readerEmail)})->delete();`,
        `DB::table('sites')->where('id',${Number(fixture.site)})->where('org_id',${Number(fixture.org)})->where('slug',${literal(fixture.slug)})->delete();`,
    ].join(' ');
    execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: SKELETON, encoding: 'utf8' });
});

test('keeps public prefix aliases working, the sibling boundary closed and the panel path unchanged', async ({ page }) => {
    expect(fixture.canonical).toBe(fixture.prefix.toLowerCase());
    for (const prefix of [fixture.prefix, fixture.canonical, fixture.prefix.toUpperCase()]) {
        const response = await page.goto(prefix);
        expect(response.status()).toBe(200);
        expect(response.request().redirectedFrom()).toBeNull();
        await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
        const href = await page.getByTestId('admin-link').getAttribute('href');
        expect(new URL(href, page.url()).pathname).toBe('/admin');
    }
    const outside = await page.goto(fixture.canonical + 'inge');
    expect(outside.status()).toBe(404);
});

test('returns a real staff sign-in to the requested Site dashboard outside the content route', async ({ page }) => {
    await page.goto(fixture.prefix);
    await page.getByTestId('admin-link').click();
    await expect(page).toHaveURL(/\/admin\/login$/);
    const target = `/admin/${fixture.slug}`;
    await page.goto(target);
    await expect(page).toHaveURL(/\/admin\/login$/);
    await waitForAdminPassword(page);
    await page.getByRole('textbox', { name: 'Email address' }).fill(BLOG_OWNER.email);
    await page.locator('input[type="password"]').fill(BLOG_OWNER.password);
    const [response] = await Promise.all([
        page.waitForResponse((r) => r.request().isNavigationRequest() && new URL(r.url()).pathname === target),
        page.getByRole('button', { name: 'Sign in', exact: true }).click(),
    ]);
    expect(response.status()).toBe(200);
    await expect(page).toHaveURL((url) => url.pathname === target);
    await expect(page.locator('.fi-header')).toBeVisible();
});

for (const spelling of ['canonical', 'mixed', 'upper']) {
    test(`returns a real ${spelling}-prefix reader sign-in to canonical downloads`, async ({ page }) => {
        const prefix = spelling === 'canonical' ? fixture.canonical : spelling === 'mixed' ? fixture.prefix : fixture.prefix.toUpperCase();
        await page.goto(prefix + '/account/downloads');
        await expect(page).toHaveURL((url) => url.pathname === fixture.canonical + '/account/sign-in');
        const form = page.locator('form').filter({ has: page.locator('input[name="email"]') });
        expect(await form.getAttribute('action')).toBe(fixture.canonical + '/account/sign-in');
        await form.locator('input[name="email"]').fill(fixture.readerEmail);
        await form.locator('input[name="password"]').fill(readerSecret);
        const target = fixture.canonical + '/account/downloads';
        const [response] = await Promise.all([
            page.waitForResponse((r) => r.request().isNavigationRequest() && new URL(r.url()).pathname === target),
            form.locator('button[type="submit"]').click(),
        ]);
        expect(response.status()).toBe(200);
        await expect(page).toHaveURL((url) => url.pathname === target);
    });
}
