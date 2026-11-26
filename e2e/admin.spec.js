// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { randomBytes } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { selectTrigger, openSelect } = require('./select-control');

/*
 * The admin loop, and the isolation boundary underneath it.
 *
 * These exist because the two worst bugs found while building this panel
 * were both invisible to the PHP suite: the navigation item that 500d every
 * page outside /c/{type}, and the tenant bootstrap cycle that 404d /admin.
 * Neither is reachable without rendering a real page in a real browser.
 */

const SITE = 'golfdom';

test.describe('admin', () => {
    test('reaches the dashboard, which lives OUTSIDE /c/{type}', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        // The standing regression test CONTRIBUTING requires. Filament calls
        // getUrl() on every Resource nav item while rendering the sidebar;
        // with {type} in the URI and none in this URL, that throws unless
        // $shouldRegisterNavigation is false.
        await expect(page).toHaveTitle(/Dashboard/);
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    });

    test('requests nothing from another host', async ({ page }) => {
        /*
         * ⚠️ ADR-027'S FLOOR SAYS NO EXTERNAL SERVICES, AND EVERY ADMIN PAGE BROKE IT. Filament's default
         * avatar provider built each avatar as a ui-avatars.com URL from the signed-in user's and the site's
         * initials. So every page view sent both to a third party, and a host with no outbound network
         * showed two broken images. The alpha's local smoke test found it in the network log; nothing on
         * screen looked wrong.
         */
        const requested = [];
        page.on('request', (request) => requested.push(request.url()));

        for (const path of [`/admin/${SITE}`, `/admin/${SITE}/c/article`, `/admin/${SITE}/c/article/1/edit`, `/admin/${SITE}/credentials`, `/admin/${SITE}/entitlements`]) {
            await page.goto(path);
            await page.waitForLoadState('networkidle');
        }

        const own = new URL(page.url()).host;
        const foreign = requested
            .map((url) => new URL(url))
            // A data: URI never leaves the page, which is how the avatars are drawn now.
            .filter((url) => ['http:', 'https:'].includes(url.protocol) && url.host !== own)
            .map((url) => url.href);

        expect(foreign).toEqual([]);
    });

    test('lists entries of a type', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);

        // ⚠️ Headed by the type, as the sidebar is. One Resource serves every type, and until the labels read
        // the bound type this page said "Entries" under a sidebar item reading "Articles".
        await expect(page).toHaveTitle(/Articles/);
        await expect(page.getByRole('heading', { name: 'Articles', exact: true })).toBeVisible();
        await expect(page.locator('.fi-breadcrumbs')).toContainText('Articles');
        await expect(page.locator('.fi-breadcrumbs')).not.toContainText('Entries');
        await expect(page.getByText('Course maintenance in week 1')).toBeVisible();
    });

    test('opens an entry for editing and drives Livewire', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);
        await page.getByRole('link', { name: 'Edit' }).first().click();

        await expect(page).toHaveURL(/\/c\/article\/\d+\/edit/);
        // Filament generates its own input ids, so match on the wire binding,
        // which is stable and states what the field actually is.
        const title = page.locator('input[wire\\:model="data.title"]');
        await expect(title).toHaveCount(1);
        await expect(title).not.toBeEmpty();
    });

    test('opens Site menus from the dashboard and edits a named menu', async ({ page }) => {
        await page.goto('/admin/golfdom');
        await page.getByRole('link', { name: 'Menus', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/golfdom\/navigation-menus$/);
        await expect(page.getByRole('heading', { name: 'Menus', exact: true })).toBeVisible();

        const handle = 'menu-spec-' + randomBytes(5).toString('hex');
        const name = 'Local navigation ' + handle;
        await page.getByRole('button', { name: 'Create menu' }).click();
        const modal = page.getByRole('dialog');
        await modal.getByRole('textbox', { name: /^Name/ }).fill(name);
        await modal.getByLabel('Theme handle').fill(handle);
        await modal.getByRole('button', { name: /Add to links/i }).click();
        await modal.getByRole('textbox', { name: /^Label/ }).fill('Home link');
        await selectTrigger(modal, /^Destination/).click();
        await modal.getByRole('option', { name: 'Site home' }).click();
        await modal.getByRole('button', { name: 'Submit' }).click();

        const row = page.getByRole('row', { name: new RegExp(name) });
        await expect(row).toBeVisible();
        await row.getByRole('button', { name: 'Edit' }).click();
        await expect(modal.getByRole('textbox', { name: /^Label/ })).toHaveValue('Home link');
        await modal.getByRole('textbox', { name: /^Name/ }).fill(name + ' updated');
        await modal.getByRole('button', { name: 'Submit' }).click();
        await expect(page.getByText(name + ' updated', { exact: true })).toBeVisible();
        await row.getByRole('button', { name: 'Remove' }).click();
        const confirmation = page.getByRole('alertdialog');
        await expect(confirmation).toContainText(name + ' updated');
        await confirmation.getByRole('button', { name: 'Confirm' }).click();
        await expect(row).toHaveCount(0);
    });

    test('keeps Site navigation tools scoped to authorised editors', async ({ browser, page }) => {
        await page.goto('/admin/golfdom');
        const base = page.url();
        const limited = await browser.newContext({ storageState: '.playwright/admin-reader-auth.json' });
        try {
            const restricted = await limited.newPage();
            await restricted.goto(base);
            await expect(restricted.getByRole('link', { name: 'Articles', exact: true })).toBeVisible();
            await expect(restricted.getByRole('link', { name: 'Menus', exact: true })).toHaveCount(0);
            for (const slug of ['navigation-menus', 'navigation-redirects']) {
                const response = await restricted.goto(base + '/' + slug);
                expect(response?.status()).toBe(403);
            }
        } finally {
            await limited.close();
        }

        const foreign = await page.goto('/admin/rival-golfdom/navigation-menus');
        expect([403, 404]).toContain(foreign?.status());
    });

    test('lets an editor retain or release a former public address', async ({ page, request }) => {
        const skeleton = path.join(__dirname, '..', 'skeleton');
        const handle = 'redirect-spec-' + randomBytes(5).toString('hex');
        const directory = path.join(skeleton, 'resources', 'views', 'themes', handle);
        const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], {
            cwd: skeleton,
            encoding: 'utf8',
        }).trim();
        const scope = String.raw`
            $org = \Kitsune\Core\Models\Org::query()->where('slug', 'golfdom-media')->firstOrFail();
            $context = app(\Kitsune\Core\Tenancy\Context::class);
            $context->setOrg($org);
            $site = \Kitsune\Core\Models\Site::query()->where('slug', 'golfdom')->firstOrFail();
            $context->setSite($site);
        `;
        const oldSlug = handle + '-old';
        const firstSlug = handle + '-new';
        const finalSlug = handle + '-final';

        fs.mkdirSync(directory, { recursive: true });
        fs.writeFileSync(path.join(directory, 'theme.json'), '{"entry_types":["article"]}');
        fs.writeFileSync(path.join(directory, 'home.blade.php'), '<p>Theme fixture</p>');
        fs.writeFileSync(path.join(directory, 'entry.blade.php'), '<h1>{{ $entry->title }}</h1>');

        let id;
        let previousTheme;
        try {
            const fixture = JSON.parse(tinker(String.raw`${scope}
                $before = $site->theme;
                $site->update(['theme' => '${handle}']);
                $seed = \Kitsune\Core\Models\Entry::query()->findOrFail(1);
                $entry = \Kitsune\Core\Models\Entry::create([
                    'entry_type_id' => $seed->entry_type_id, 'title' => 'Redirect browser fixture',
                    'slug' => '${oldSlug}', 'status' => 'published',
                ]);
                echo json_encode(['id' => $entry->id, 'previousTheme' => $before]);
            `));
            id = fixture.id;
            previousTheme = fixture.previousTheme;
            const url = (slug) => '/golfdom/article/' + slug;
            const slugInput = page.locator('input[wire\\:model="data.slug"]');
            const preserve = page.getByRole('switch', { name: 'Redirect the previous address' });

            await page.goto('/admin/golfdom/c/article/' + id + '/edit');
            await expect(preserve).toBeChecked();
            await slugInput.fill(firstSlug);
            await page.getByRole('button', { name: 'Save changes' }).click();

            await expect.poll(async () => (await request.get(url(oldSlug), { maxRedirects: 0 })).status()).toBe(302);
            const retained = await request.get(url(oldSlug), { maxRedirects: 0 });
            expect(retained.headers()['location']).toBe(url(firstSlug));

            await page.reload();
            await expect(slugInput).toHaveValue(firstSlug);
            await preserve.click();
            await expect(preserve).not.toBeChecked();
            await slugInput.fill(finalSlug);
            await page.getByRole('button', { name: 'Save changes' }).click();

            await expect.poll(async () => (await request.get(url(finalSlug))).status()).toBe(200);
            const released = await request.get(url(firstSlug), { maxRedirects: 0 });
            expect(released.status()).toBe(404);
            const older = await request.get(url(oldSlug), { maxRedirects: 0 });
            expect(older.headers()['location']).toBe(url(finalSlug));

            await page.getByRole('link', { name: 'Redirects', exact: true }).click();
            await expect(page.getByRole('heading', { name: 'Redirects', exact: true })).toBeVisible();
            const redirect = page.getByRole('row', { name: new RegExp(oldSlug) });
            await expect(redirect).toBeVisible();
            await redirect.getByRole('button', { name: 'Remove' }).click();
            const modal = page.getByRole('dialog');
            await page.getByRole('alertdialog').getByRole('button', { name: 'Confirm' }).click();
            await expect(redirect).toHaveCount(0);
            expect((await request.get(url(oldSlug), { maxRedirects: 0 })).status()).toBe(404);

            await page.getByRole('button', { name: 'Create redirect' }).click();
            await modal.getByLabel('Former type handle').fill('article');
            await modal.getByLabel('Former slug').fill(oldSlug);
            const search = await openSelect(modal, /^Destination/);
            await search.fill('Redirect browser fixture');
            await modal.getByRole('option', { name: 'Redirect browser fixture' }).click();
            await modal.getByRole('button', { name: 'Submit' }).click();
            await expect(redirect).toBeVisible();
            const restored = await request.get(url(oldSlug), { maxRedirects: 0 });
            expect(restored.headers()['location']).toBe(url(finalSlug));
        } finally {
            if (id) {
                tinker(String.raw`${scope}
                    \Kitsune\Core\Models\Entry::query()->findOrFail(${id})->forceDelete();
                    $site->update(['theme' => ${previousTheme === null ? 'null' : JSON.stringify(previousTheme)}]);
                `);
            }
            fs.rmSync(directory, { recursive: true });
        }
    });

    test('creates an entry, stamping the type the URL identified', async ({ page }) => {
        // Regression: entry_type_id is NOT NULL and appears in no form field,
        // so before CreateEntry stamped it, every create failed on a database
        // constraint. Caught in review, never by a test — because no test
        // exercised create at all.
        await page.goto(`/admin/${SITE}/c/product/create`);

        const title = `Spec-created product ${Date.now()}`;
        await page.locator('input[wire\\:model="data.title"]').fill(title);
        await page.locator('form').getByRole('button', { name: /Create/i }).first().click();

        // Filament redirects to the edit form, where the title is an input
        // value rather than page text.
        await expect(page).toHaveURL(/\/c\/product\/\d+/, { timeout: 15_000 });
        await expect(page.locator('input[wire\\:model="data.title"]')).toHaveValue(title);

        // And it must land under the type the URL identified, not another.
        await page.goto(`/admin/${SITE}/c/product`);
        await expect(page.getByText(title).first()).toBeVisible();
    });

    test('emits no URL with an empty {type} segment', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);

        const hrefs = await page.locator('a[href*="/c/"]').evaluateAll(
            (links) => links.map((a) => a.getAttribute('href')),
        );

        expect(hrefs.length).toBeGreaterThan(0);
        expect(hrefs.filter((h) => /\/c\/(\/|$|\?)/.test(h ?? ''))).toEqual([]);
    });
    test('drives a ManageRelatedRecords PAGE under {type}', async ({ page }) => {
        // Spike #10, the last untested corner of ADR-012's URL contract.
        // RelationManager *components* register no routes; this construct is
        // a resource page and does, which is why it needed covering
        // separately.
        await page.goto(`/admin/${SITE}/c/article/1/related`);

        await expect(page).toHaveTitle(/Related entries/);
        await expect(page.getByText('Course maintenance in week 2')).toBeVisible();

        const hrefs = await page.locator('a[href*="/c/"]').evaluateAll(
            (links) => links.map((a) => a.getAttribute('href')),
        );
        expect(hrefs.filter((h) => /\/c\/(\/|$|\?)/.test(h ?? ''))).toEqual([]);
    });

    test('reaches a working Attach action under {type}', async ({ page }) => {
        // Scope note, because the previous version of this claim was wrong.
        //
        // What this proves is BROWSER-specific: the Attach action is reachable
        // and its modal opens on a page nested under {type}, with a searchable picker
        // to choose from and a live submit control. That is the part only a
        // browser can answer.
        //
        // What it deliberately does NOT prove is the attach semantics —
        // whether org_id is stamped on the pivot. Two tests in
        // EntrySchemaTest cover that directly, and they exist because this
        // very action failed on a NOT NULL constraint while a commit message
        // claimed it "operates". Driving Filament's custom combobox from
        // Playwright is selector archaeology that would assert less, less
        // reliably, than those do.
        await page.goto(`/admin/${SITE}/c/article/1/related`);

        await page.getByRole('button', { name: 'Attach' }).first().click();

        // The outer role=dialog stays hidden under Alpine; the window shows.
        const modal = page.locator('.fi-modal-window').first();
        await expect(modal).toBeVisible({ timeout: 15_000 });

        await expect(selectTrigger(modal)).toHaveCount(1);
        await expect(selectTrigger(modal)).toBeVisible();
        await expect(selectTrigger(modal)).toHaveAccessibleName(/\S/);
        await expect(modal.locator('button[type="submit"]')).toBeVisible();
    });
});

test.describe('isolation, from the attacker side', () => {
    test('404s an entry type belonging to another org', async ({ page }) => {
        const response = await page.goto(`/admin/${SITE}/c/confidential`);

        // Seeded against Rival Publishing. {type} is user-controlled input
        // and this is a security boundary, not a convenience (ADR-012).
        expect(response?.status()).toBe(404);
    });

    test('404s an entry type that does not exist', async ({ page }) => {
        const response = await page.goto(`/admin/${SITE}/c/nonsense`);

        expect(response?.status()).toBe(404);
    });

    test('allows a global system type, which every org shares', async ({ page }) => {
        const response = await page.goto(`/admin/${SITE}/c/image`);

        // org_id NULL means available to all — the pattern ADR-021 reuses.
        expect(response?.status()).toBe(200);
    });

    test('404s a type this org owns but has DISABLED for this site', async ({ page }) => {
        // ADR-022: a type may exist, belong to this org, and still be
        // disabled for this site. Until this test the availability half of
        // IdentifyEntryType had no route-level coverage — the resolver was
        // tested in isolation, so removing the middleware's call to it would
        // have left every test green.
        const response = await page.goto(`/admin/${SITE}/c/podcast`);

        expect(response?.status()).toBe(404);
    });

    test('does not offer a disabled type in navigation', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        /*
         * ⚠️ IN THE SIDEBAR, because the dashboard links each type too. Asked of the whole page, this would also
         * pass with the sidebar offering Podcasts while the dashboard left it out. `dashboard.spec.js` asks the
         * stats.
         */
        await expect(page.locator('.fi-sidebar').getByRole('link', { name: 'Podcasts' })).toHaveCount(0);
    });

    test('does not show another org\'s content in any list', async ({ page }) => {
        await page.goto(`/admin/${SITE}/c/article`);

        await expect(page.getByText('Should never be visible from Golfdom')).toHaveCount(0);
    });

    /*
     * The one that actually exercises the SCOPE. The row above carries the other org's own type, so the list
     * excludes it on the type predicate alone and the assertion would survive deleting every scope from
     * Entry. A global type is the only case where two orgs legitimately share an entry_type_id, so here the
     * type predicate cannot help and the scopes are the whole defence — Kitsune's SiteScope and OrgScope, and
     * Filament's tenant scope on this list.
     *
     * ⚠️ WITH TWO CONTROLS, because "not on the page" is also what a page that failed to render says, and what
     * a fixture that was never seeded says. Golfdom's own file shows the list rendered; the rival's owner
     * seeing the rival's file in their own site shows it exists. The fixture is a stored file rather than a
     * byte-less entry: a media entry with no file behind it is the state ADR-042 arranges never to exist.
     */
    test('does not show another org\'s content under a type both orgs share', async ({ page, browser }) => {
        await page.goto(`/admin/${SITE}/c/image`);

        // A media list is a grid of tiles (ADR-042 decision 6): each entry is a card rather than a table row.
        await expect(page.locator('.fi-ta-record').filter({ hasText: 'Course map' })).toBeVisible();
        await expect(page.getByText('Rival private asset')).toHaveCount(0);

        const rival = await browser.newContext({ storageState: '.playwright/admin-rival-auth.json' });
        const rivalPage = await rival.newPage();

        await rivalPage.goto('/admin/rival-golfdom/c/image');

        await expect(rivalPage.locator('.fi-ta-record').filter({ hasText: 'Rival private asset' })).toBeVisible();

        await rival.close();
    });

    for (const failure of ['HTTP 500', 'a dropped connection']) {
        test(`recovers Select All after ${failure}`, async ({ page }) => {
            await page.goto(`/admin/${SITE}/c/image`);

            const checkbox = page.locator('.fi-ta-record-checkbox').first();
            const selection = page.locator('.fi-ta-selection-indicator');
            const selectAll = selection.getByRole('button', { name: /^Select all \d+$/i });
            await checkbox.check();
            await expect(selection).toContainText(/\b1 record selected\b/i);

            const keysRequest = (url) => /\/livewire(?:-[^/]+)?\/update$/.test(url.pathname);
            await page.route(keysRequest, async (route) => {
                if (!route.request().postData()?.includes('"method":"getAllSelectableTableRecordKeys"')) {
                    await route.fallback();
                    return;
                }

                if (failure === 'HTTP 500') {
                    await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
                } else {
                    await route.abort();
                }
            });

            const failed = failure === 'HTTP 500'
                ? page.waitForResponse((response) => keysRequest(new URL(response.url())) && response.status() === 500)
                : page.waitForEvent('requestfailed', (request) => keysRequest(new URL(request.url())));
            await selectAll.click();
            await failed;
            await page.unroute(keysRequest);

            const table = page.locator('[x-data^="filamentTable("]').filter({ has: selection });
            await expect.poll(() => table.evaluate((element) => {
                const alpine = /** @type {{ Alpine?: { $data: (node: Element) => { isLoading: boolean } } }} */ (window);
                return alpine.Alpine?.$data(element).isLoading;
            })).toBe(false);
            await expect(checkbox).toBeChecked();
            await expect(selection).toContainText(/\b1 record selected\b/i);

            if (failure === 'HTTP 500') {
                const errorDialog = page.locator('dialog#livewire-error');
                await expect(errorDialog).toBeVisible();
                await page.keyboard.press('Escape');
                await expect(errorDialog).toBeHidden();
            }

            // Retry through the same control. The count in its label is the fixture's current total.
            const total = (await selectAll.innerText()).match(/\d+/)?.[0];
            expect(Number(total)).toBeGreaterThan(1);
            await selectAll.click();
            await expect(selection).toContainText(`${total} records selected`);
        });
    }

    test('does not offer another org\'s type in navigation', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        // In the sidebar, which is what navigation means: the dashboard's Articles stat links there too.
        await expect(page.locator('.fi-sidebar').getByRole('link', { name: 'Articles' })).toBeVisible();
        // The whole page, on purpose: another org's type must not reach the dashboard's widgets either.
        await expect(page.getByText('Confidential')).toHaveCount(0);
    });
});
