// @ts-check
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
 * renders `dir` from `__('filament-panels::layout.direction')`, so APP_LOCALE
 * alone decides it. `playwright.config.js` runs a second server with
 * `APP_LOCALE=ar` and points this project at it. No product feature required.
 *
 * The load-bearing test here is the MIRROR one. Asserting `dir="rtl"` proves
 * an attribute; asserting that the sidebar moved to the other side of the
 * viewport proves the attribute did something.
 */

const SITE = 'golfdom';

/** WCAG 2.1 A and AA — the level the project is aiming at. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/*
 * The LTR server, addressed absolutely.
 *
 * This project's baseURL is the RTL one, and the mirror test needs both in a
 * single test: the assertion is a RELATION between the two renders, so
 * measuring them in separate tests would mean carrying geometry across test
 * boundaries in module state.
 */
const LTR_BASE_URL = process.env.KITSUNE_BASE_URL || 'http://127.0.0.1:8125';

const PAGES = [
    ['dashboard', `/admin/${SITE}`],
    ['entry list', `/admin/${SITE}/c/article`],
    ['entry create', `/admin/${SITE}/c/article/create`],
    ['entry edit', `/admin/${SITE}/c/article/1/edit`],
    ['related records', `/admin/${SITE}/c/article/1/related`],
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
        test(`${name} is laid out as the mirror of its LTR render`, async ({ page }) => {
            await page.goto(LTR_BASE_URL + url);
            const ltr = await page.evaluate(geometry, LANDMARKS);

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
});

test.describe('Kitsune\'s own output carries a direction', () => {
    test('the public page is served RTL, not just in Arabic', async ({ page }) => {
        // ⚠️ The gap this spike found in OUR code rather than Filament's.
        //
        // The skeleton's page emitted `lang` from the app locale and no `dir`
        // at all, so an Arabic locale served Arabic text in a left-to-right
        // document. Filament supplies `dir` for the admin from its own
        // translations; the public side has no panel and never got one
        // (ADR-002 keeps core headless-capable), so nothing was going to
        // supply it. `Kitsune::textDirection()` does.
        await page.goto('/');

        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    });
});
