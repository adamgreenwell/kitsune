// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

/*
 * The RTL half of spike #12, rendered rather than inferred.
 *
 * ⚠️ This file exists because the previous RTL check could not fail. It
 * visited the ENGLISH site and asserted `dir="ltr"`, which stays green while
 * every RTL layout in the admin is broken. What replaced it measured the ratio
 * of logical to physical CSS properties — honest about being a readiness
 * proxy, and still not a render check.
 *
 * The claimed blocker was that a render check "needs the locale switcher that
 * does not exist yet". That was wrong, and one command disproved it: Filament
 * renders `dir` from `__('filament-panels::layout.direction')`, so the app
 * locale decides it.
 *
 * ⚠️ HOW THAT LOCALE IS REACHED HAS CHANGED, and this file changed with it.
 * It used to run a second `php artisan serve` with `APP_LOCALE=ar`, because the
 * direction was a property of the PROCESS. Issue #38 made the UI locale resolve
 * per request from the viewer's own preference (ADR-018 rule 2), so the fixture
 * is now two editors on ONE server: `alpha@kitsune.test` with no preference, and
 * `alpha-rtl@kitsune.test` with `locale = 'ar'`.
 *
 * That is not a tidier fixture, it is a different assertion. The old one would
 * still pass if per-request resolution broke completely — an environment
 * variable would carry it — so it could not fail for the reason that now
 * matters.
 *
 * The load-bearing test here is the MIRROR one. Asserting `dir="rtl"` proves
 * an attribute; asserting that the sidebar moved to the other side of the
 * viewport proves the attribute did something.
 */

const SITE = 'golfdom';

/** WCAG 2.1 A and AA — the level the project is aiming at. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/*
 * The LTR editor's saved session.
 *
 * The mirror test needs both renders in a SINGLE test, because the assertion is a
 * relation between them — measuring them in separate tests would mean carrying
 * geometry across test boundaries in module state. Both now come from the same
 * URL on the same server, so what differs is the signed-in viewer.
 */
const LTR_STORAGE = '.playwright/admin-auth.json';

const PAGES = [
    ['dashboard', `/admin/${SITE}`],
    ['entry list', `/admin/${SITE}/c/article`],
    ['entry create', `/admin/${SITE}/c/article/create`],
    ['entry edit', `/admin/${SITE}/c/article/1/edit`],
    ['related records', `/admin/${SITE}/c/article/1/related`],
    // A media type's list is a grid of tiles, with core's own buttons, words and badges in it (ADR-042 decisions 6, 39–42).
    // After the first three, which the mirror test below compares with their LTR render.
    ['media list', `/admin/${SITE}/c/image`],
    // ADR-040's admin half: core's one hand-built form sits in its Set modal, scanned there by `credentials.spec.js`.
    ['credentials', `/admin/${SITE}/credentials`],
    ['entitlements', `/admin/${SITE}/entitlements`],
];

/** Layout landmarks, and every one of them is direction-sensitive. */
const LANDMARKS = ['.fi-sidebar', '.fi-main', '.fi-topbar', '.fi-sidebar-nav', '.fi-topbar-end'];

/** Bounding boxes of the named selectors, plus the viewport they sit in. */
const geometry = (selectors) => {
    const doc = document.documentElement;
    const boxes = {};

    for (const selector of selectors) {
        const el = document.querySelector(selector);

        boxes[selector] = el === null
            ? null
            : (({ left, right, width }) => ({
                left: Math.round(left),
                right: Math.round(right),
                width: Math.round(width),
            }))(el.getBoundingClientRect());
    }

    return {
        dir: doc.getAttribute('dir'),
        lang: doc.getAttribute('lang'),
        clientWidth: doc.clientWidth,
        scrollWidth: doc.scrollWidth,
        boxes,
    };
};

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: path.join(__dirname, '..', 'skeleton'), encoding: 'utf8' }).trim();
}

/** Golfdom Media's org and Golfdom's site, below Eloquent. */
const GOLFDOM = "$site = DB::table('sites')->where('slug', 'golfdom')->first();";

/**
 * A reader in Golfdom Media for each address, and a row on Golfdom for each under its own entitlement — below Eloquent,
 * as a host could have stored them; idempotent on each table's unique key.
 */
function plantAddresses(pairs) {
    for (const [address, entitlement] of pairs) {
        const printed = tinker(GOLFDOM
            + ` DB::table('readers')->insertOrIgnore(['org_id' => $site->org_id, 'email' => '${address}', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);`
            + ` $id = DB::table('readers')->where('org_id', $site->org_id)->where('email', '${address}')->value('id');`
            + ` DB::table('entitlements')->insertOrIgnore(['org_id' => $site->org_id, 'site_id' => $site->id, 'reader_id' => (string) $id,`
            + ` 'entitlement' => '${entitlement}', 'source' => 'e2e.plant:6', 'changed_at' => now('UTC')->format('Y-m-d H:i:s')]);`
            + ` echo DB::table('entitlements')->where('site_id', $site->id)->where('reader_id', (string) $id)->where('entitlement', '${entitlement}')->count();`);

        if (printed !== '1') {
            throw new Error(`rtl.spec: the reader for ${entitlement} was not planted:\n${printed}`);
        }
    }
}

function removeAddresses(addresses) {
    tinker(GOLFDOM
        + " DB::table('entitlements')->where('source', 'e2e.plant:6')->delete();"
        + ` DB::table('readers')->where('org_id', $site->org_id)->whereIn('email', ${JSON.stringify(addresses).replace(/"/g, "'")})->delete();`);
}

/** The address column's own element in the row for one entitlement — the one carrying `dir`, as Filament renders it. */
const addressCell = (page, entitlement) => page.locator('.fi-ta-row').filter({ hasText: entitlement }).locator('td').nth(1).locator('[dir="auto"]');

/**
 * Run in the page against an address cell: where its address's first and last characters are drawn and, when asked to
 * reach them, whether the cell cuts any of it, how the nearest box that it is wider than scrolls, and whether each end
 * can be scrolled into the viewport there.
 */
function drawnAddress(el, [text, reach]) {
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let node = walker.nextNode();

    while (node !== null && ! (node.textContent || '').includes(text)) {
        node = walker.nextNode();
    }

    const start = (node.textContent || '').indexOf(text);
    const ends = [start, start + text.length - 1];
    const at = (offset) => {
        const range = document.createRange();
        range.setStart(node, offset);
        range.setEnd(node, offset + 1);

        return range.getClientRects()[0];
    };
    const drawn = { first: at(ends[0]).left, last: at(ends[1]).left };

    if (! reach) {
        return drawn;
    }

    let scroller = el.parentElement;

    while (scroller !== null && scroller.scrollWidth <= scroller.clientWidth) {
        scroller = scroller.parentElement;
    }

    const seen = [];

    for (const offset of scroller === null ? [] : ends) {
        const box = scroller.getBoundingClientRect();
        scroller.scrollBy({ left: at(offset).left - (box.left + box.width / 2), behavior: 'instant' });
        const after = at(offset);
        seen.push(after.left >= Math.max(box.left, 0) && after.right <= Math.min(box.right, window.innerWidth));
    }

    return {
        ...drawn,
        cut: el.scrollWidth > el.clientWidth,
        scroller: scroller === null ? null : (scroller.closest('.fi-ta') !== null ? getComputedStyle(scroller).overflowX : `outside the table: ${scroller.className}`),
        seen,
    };
}

test.describe('the admin renders right-to-left (ADR-018)', () => {
    for (const [name, url] of PAGES) {
        test(`${name} is served RTL`, async ({ page }) => {
            await page.goto(url);

            // `dir` comes from a TRANSLATION, so a locale missing the key would
            // render the key itself as the attribute value. Asserting the exact
            // value rather than "not ltr" catches that.
            await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
            await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
        });

        test(`${name} has no critical or serious WCAG violations under RTL`, async ({ page }) => {
            await page.goto(url);

            // Run separately from the LTR scan rather than assumed equivalent:
            // mirroring changes reading order and focus order, and both are
            // things axe has rules about.
            const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();

            if (results.violations.length > 0) {
                console.log(`\n${name} (RTL) — ${results.violations.length} violation(s) at all levels:`);
                for (const v of results.violations) {
                    console.log(`  [${v.impact}] ${v.id}: ${v.help}`);
                    console.log(`    ${v.nodes.length} node(s), e.g. ${v.nodes[0]?.target?.join(' ')}`);
                }
            }

            const blocking = results.violations.filter(
                (v) => v.impact === 'critical' || v.impact === 'serious',
            );

            expect(blocking).toEqual([]);
        });

        test(`${name} does not overflow horizontally under RTL`, async ({ page }) => {
            await page.goto(url);

            // A mirrored layout that still uses a physical offset somewhere
            // usually shows up as content pushed past the far edge, which a
            // screenshot review misses and this does not.
            const { scrollWidth, clientWidth } = await page.evaluate(geometry, LANDMARKS);

            expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
        });
    }

    /*
     * ⚠️ THE test in this file.
     *
     * Everything above would still pass if Filament emitted `dir="rtl"` and
     * laid the page out exactly as before. This asserts the layout is the
     * MIRROR IMAGE of the LTR one: an element `n` pixels from the left edge in
     * LTR has to sit `n` pixels from the RIGHT edge in RTL.
     *
     * Measured 2026-09-08 against Filament v5.7.8, at 1280px, on three page
     * shapes: drift of 0 on every landmark. The tolerance below is for
     * sub-pixel rounding, not for slack.
     */
    for (const [name, url] of PAGES.slice(0, 3)) {
        test(`${name} is laid out as the mirror of its LTR render`, async ({ page, browser }) => {
            // ⚠️ A second CONTEXT, not a second server or a second URL. The same page,
            // requested by an editor with no locale preference, must come back LTR — which
            // is the per-request resolution under test rather than a property of the host.
            const ltrContext = await browser.newContext({ storageState: LTR_STORAGE });
            const ltrPage = await ltrContext.newPage();
            await ltrPage.setViewportSize(page.viewportSize());
            await ltrPage.goto(url);
            const ltr = await ltrPage.evaluate(geometry, LANDMARKS);
            await ltrContext.close();

            await page.goto(url);
            const rtl = await page.evaluate(geometry, LANDMARKS);

            expect(ltr.dir).toBe('ltr');
            expect(rtl.dir).toBe('rtl');
            expect(rtl.clientWidth).toBe(ltr.clientWidth);

            for (const selector of LANDMARKS) {
                const before = ltr.boxes[selector];
                const after = rtl.boxes[selector];

                // A landmark that vanished in one direction is itself the bug,
                // so this is asserted rather than skipped.
                expect(before, `${selector} missing in LTR`).not.toBeNull();
                expect(after, `${selector} missing in RTL`).not.toBeNull();

                expect(after.width, `${selector} changed width`).toBe(before.width);
                expect(
                    Math.abs(after.left - (ltr.clientWidth - before.right)),
                    `${selector} did not mirror: ltr[${before.left},${before.right}] rtl[${after.left},${after.right}]`,
                ).toBeLessThanOrEqual(1);
            }
        });
    }

    test('the off-canvas sidebar parks on the correct side at mobile widths', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`/admin/${SITE}/c/article`);

        // ⚠️ Reads as breakage and is not. Below Filament's breakpoint the
        // sidebar becomes a drawer parked OUTSIDE the viewport, so it is
        // legitimately off-screen — at a NEGATIVE offset in LTR and BEYOND the
        // right edge in RTL. Asserting "nothing off-screen" would fail here
        // for the one element that is supposed to be.
        const { boxes, clientWidth } = await page.evaluate(geometry, LANDMARKS);

        expect(boxes['.fi-sidebar']).not.toBeNull();
        expect(boxes['.fi-sidebar'].left).toBeGreaterThanOrEqual(clientWidth);
    });

    /*
     * ⚠️ A TILE'S WORDS READ IN THEIR OWN DIRECTION — ADR-042 decisions 39–42. Core's words are English under an Arabic
     * admin until core ships a translation, and a status line that took the page's direction put its full stop at the
     * wrong end (measured). So the line is `dir="auto"`; and a public image that did not load is scanned beside it.
     */
    test('the media list\'s tile words read in their own direction under RTL, with a tile refused and one that did not load', async ({ page }) => {
        await page.route(/\/admin\/[^/]+\/media\/\d+$/, (route) => route.fulfill({ status: 403, contentType: 'application/json', body: '{}' }));
        await page.route(/\/storage\//, (route) => route.fulfill({ status: 404, contentType: 'text/plain', body: '' }));
        await page.goto(`/admin/${SITE}/c/image`);

        const tile = page.locator('.fi-ta-record').filter({ hasText: 'Course map' }).locator('[data-kitsune-tile="deferred"]');
        const line = tile.locator('[role="status"]');
        await tile.getByRole('button').click();

        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(line).toHaveText('You may no longer see this file.');
        expect(await line.evaluate((el) => getComputedStyle(el).direction)).toBe('ltr');
        await expect(page.locator('.fi-ta-record').filter({ hasText: 'Golfdom logo' }).locator('.fi-badge')).toHaveText('Did not load');

        const results = await new AxeBuilder({ page }).include('.fi-ta').withTags(TAGS).analyze();

        if (results.violations.length > 0) {
            console.log(`\nmedia list in its states (RTL) — ${results.violations.length} violation(s) at all levels:`);
            for (const v of results.violations) {
                console.log(`  [${v.impact}] ${v.id}: ${v.help}`);
                console.log(`    ${v.nodes.length} node(s), e.g. ${v.nodes[0]?.target?.join(' ')}`);
            }
        }

        expect(results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious')).toEqual([]);
    });

    test('has no critical or serious WCAG violations at a mobile width under RTL', async ({ page }) => {
        // ⚠️ This scan exists because the inventory RECORDED a mobile result
        // the committed suite could not reproduce.
        //
        // It was measured — in the throwaway probe that preceded this file —
        // and the probe was then replaced by the spec above, which sets a mobile
        // viewport and never runs axe. So `accessibility-inventory.md` cited a
        // number no listed command produced. Caught in review, and it is
        // invariant 15 pointed the other way: a measurement nobody can re-run is
        // the same liability as a claim nobody measured.
        //
        // It is a separate scan rather than a wider loop because the mobile
        // layout is a DIFFERENT layout: the sidebar becomes a drawer and the
        // topbar gains a trigger, so it has its own focus order and its own
        // touch-target sizes for axe to judge.
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`/admin/${SITE}/c/article`);

        const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();

        if (results.violations.length > 0) {
            console.log(`\nmobile RTL — ${results.violations.length} violation(s) at all levels:`);
            for (const v of results.violations) {
                console.log(`  [${v.impact}] ${v.id}: ${v.help}`);
                console.log(`    ${v.nodes.length} node(s), e.g. ${v.nodes[0]?.target?.join(' ')}`);
            }
        }

        const blocking = results.violations.filter(
            (v) => v.impact === 'critical' || v.impact === 'serious',
        );

        expect(blocking).toEqual([]);
    });

    /*
     * ⚠️ A READER'S ADDRESS READS IN ITS OWN DIRECTION — ADR-037 decision 2, the address column. Under the Arabic admin a
     * cell that takes the chrome's direction draws an address back to front: with its `dir` taken off in this browser,
     * `12345@kitsune.test`'s `1` was drawn right of its last `t` (measured). And the longest address a reader can have —
     * 255 bytes, `64@63.62.61.e` — must be readable whole at a phone's width and a desktop's.
     *
     * ⚠️ LAST, AND PUT BACK: both readers and their rows are planted below Eloquent in Golfdom Media and removed after,
     * so no scan above meets them and a run after this one starts as this one did.
     */
    test('a reader\'s address reads left to right in its own cell under RTL, and the longest one can be read whole at any width', async ({ page }) => {
        const digits = '12345@kitsune.test';
        const longest = `${'a'.repeat(64)}@${'b'.repeat(63)}.${'c'.repeat(62)}.${'d'.repeat(61)}.e`;
        expect(Buffer.byteLength(longest)).toBe(255);

        plantAddresses([[digits, 'e2e.rtl-digits'], [longest, 'e2e.rtl-longest']]);

        try {
            await page.goto(`/admin/${SITE}/entitlements`);
            await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

            const cell = addressCell(page, 'e2e.rtl-digits');
            await expect(cell).toHaveText(digits);
            expect(await cell.evaluate((el) => getComputedStyle(el).direction)).toBe('ltr');

            // Where the first character and the last are drawn, not what the DOM holds: `1` left of `t`.
            const drawn = await cell.evaluate(drawnAddress, [digits, false]);
            expect(drawn.first).toBeLessThan(drawn.last);

            for (const width of [320, 390, 1280]) {
                await page.setViewportSize({ width, height: 844 });
                await page.goto(`/admin/${SITE}/entitlements`);

                const longestCell = addressCell(page, 'e2e.rtl-longest');
                await expect(longestCell).toHaveText(longest);

                const { scrollWidth, clientWidth } = await page.evaluate(geometry, LANDMARKS);
                expect(scrollWidth, `the page at ${width}px`).toBeLessThanOrEqual(clientWidth);

                /*
                 * ⚠️ AND THE ADDRESS CAN BE READ, which the line above cannot show alone: Filament's `.fi-layout` is
                 * `overflow-x: clip` (measured at all three widths), so a page whose table pushed past the edge is cut
                 * there rather than widened. So: the cell cuts nothing of its address, the width it takes is scrolled
                 * by the table's own container, and each end of the address can be scrolled into the viewport.
                 */
                const { cut, scroller, seen } = await longestCell.evaluate(drawnAddress, [longest, true]);

                expect({ cut, scroller, seen }, `the address at ${width}px`).toEqual({ cut: false, scroller: 'auto', seen: [true, true] });
            }
        } finally {
            removeAddresses([digits, longest]);
        }
    });
});

/*
 * ⚠️ THE PUBLIC-PAGE CHECK MOVED, and it moved because this project no longer runs a
 * server with an Arabic app locale.
 *
 * It asserted that `/` carries `dir="rtl"` and `lang="ar"` — gap G1, Kitsune's own
 * output having no direction at all. That was reachable only through `APP_LOCALE=ar`
 * on a dedicated server, because the public side had no site-scoped routes then. Keeping
 * a whole second web server for one assertion about a Blade template would be paying a
 * lot to test very little.
 *
 * It is now `tests/Core/PublicDirectionTest.php`, which sets the app locale and
 * renders the view directly. Nothing about that assertion needs a browser: there is no
 * JavaScript in it, and the claim is simply that the template emits what
 * `Kitsune::textDirection()` returns.
 *
 * The browser-level version came back with site-scoped public pages, resolving the
 * locale from the site rather than an environment variable: `public-site-locale.spec.js`
 * for the placeholder, and `readers.spec.js` for a reader's pages (ADR-037), whose
 * document is the site's language and whose words are the copy's.
 */
