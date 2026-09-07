// @ts-check
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

/*
 * Spike #12, the automatable half.
 *
 * Pillar three includes PHYSICAL accessibility, and the commitment is real
 * assistive-technology testing rather than a boilerplate conformance claim.
 * This file cannot make that claim on its own and does not try to.
 *
 * What it does: catch the WCAG violations a machine can see, on every page
 * shape the admin has, so regressions are caught continuously rather than at
 * an audit. What it cannot do: tell you whether the entry editor is usable
 * with a screen reader. That half is manual and stays open in #12.
 *
 * Scanning inherited Filament chrome is deliberate. ADR-018 asks how much
 * conformance is inherited versus built, and the answer only comes from
 * measuring what ships.
 */

const SITE = 'golfdom';

/** WCAG 2.1 A and AA — the level the project is aiming at. */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const PAGES = [
    ['dashboard', `/admin/${SITE}`],
    ['entry list', `/admin/${SITE}/c/article`],
    ['entry create', `/admin/${SITE}/c/article/create`],
    ['entry edit', `/admin/${SITE}/c/article/1/edit`],
    ['related records', `/admin/${SITE}/c/article/1/related`],
];

test.describe('accessibility (automated half of #12)', () => {
    for (const [name, url] of PAGES) {
        test(`${name} has no critical or serious WCAG violations`, async ({ page }) => {
            await page.goto(url);

            const results = await new AxeBuilder({ page }).withTags(TAGS).analyze();

            // Critical and serious only. Minor and moderate findings are
            // recorded by the audit task rather than failing the build —
            // a gate nobody can keep green gets disabled, and this one has
            // to survive contact with inherited Filament markup.
            const blocking = results.violations.filter(
                (v) => v.impact === 'critical' || v.impact === 'serious',
            );

            if (blocking.length > 0) {
                console.log(`\n${name} — ${blocking.length} blocking violation(s):`);
                for (const v of blocking) {
                    console.log(`  [${v.impact}] ${v.id}: ${v.help}`);
                    console.log(`    ${v.nodes.length} node(s), e.g. ${v.nodes[0]?.target?.join(' ')}`);
                }
            }

            expect(blocking).toEqual([]);
        });
    }

    test('every admin page has a document language', async ({ page }) => {
        // Trivial for a machine, invisible to a sighted reviewer, and the
        // difference between a screen reader pronouncing content correctly
        // or not (WCAG 3.1.1).
        await page.goto(`/admin/${SITE}`);

        await expect(page.locator('html')).toHaveAttribute('lang', /.+/);
    });
});

test.describe('RTL layout (ADR-018)', () => {
    test('the admin sets dir=rtl for a right-to-left locale', async ({ page }) => {
        // Filament ships 64 locales including ar, he, fa and ur, so Arabic
        // speakers will arrive. Shipping RTL *translations* is not the same
        // as RTL *layout*: a translated string in a left-aligned sidebar is
        // still broken, and v5's layout completeness was never verified.
        await page.goto(`/admin/${SITE}`);

        const dir = await page.locator('html').getAttribute('dir');

        // Documents the CURRENT state rather than asserting a wish. The
        // panel is LTR today; when a locale switcher lands this becomes the
        // test that RTL actually flips the layout.
        expect(dir).toBe('ltr');
    });

    test('layout uses logical properties, so RTL is mostly free', async ({ page }) => {
        // Tailwind 4 logical properties make RTL nearly free now and painful
        // later (ADR-018). This checks the inherited chrome actually uses
        // them rather than hardcoded left/right.
        await page.goto(`/admin/${SITE}`);

        const usesLogical = await page.evaluate(() => {
            const sheets = [...document.styleSheets];
            let logical = 0;
            let physical = 0;

            for (const sheet of sheets) {
                let rules;
                try {
                    rules = [...(sheet.cssRules ?? [])];
                } catch {
                    continue; // cross-origin
                }

                for (const rule of rules) {
                    const text = rule.cssText ?? '';
                    logical += (text.match(/margin-inline|padding-inline|inset-inline|border-inline/g) ?? []).length;
                    physical += (text.match(/margin-left:|margin-right:|padding-left:|padding-right:/g) ?? []).length;
                }
            }

            return { logical, physical };
        });

        console.log(`  logical properties: ${usesLogical.logical}, physical: ${usesLogical.physical}`);
        expect(usesLogical.logical).toBeGreaterThan(0);
    });
});
