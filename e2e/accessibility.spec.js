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
            // Log EVERY violation before filtering. The previous version
            // logged only the blocking ones, so a minor or moderate finding
            // was neither failed nor recorded — it passed silently, which is
            // the opposite of what the surrounding comment claimed.
            if (results.violations.length > 0) {
                console.log(`\n${name} — ${results.violations.length} violation(s) at all levels:`);
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
    }

    test('every admin page has a document language', async ({ page }) => {
        // Trivial for a machine, invisible to a sighted reviewer, and the
        // difference between a screen reader pronouncing content correctly
        // or not (WCAG 3.1.1).
        await page.goto(`/admin/${SITE}`);

        await expect(page.locator('html')).toHaveAttribute('lang', /.+/);
    });
});

test.describe('RTL readiness (ADR-018)', () => {
    /*
     * ⚠️ THIS IS NOT AN RTL RENDER CHECK, and the earlier version of this
     * block implied it was. It visited the English site and asserted
     * dir="ltr", which would stay green even if every RTL layout in the
     * admin were broken. Caught in review.
     *
     * An actual RTL render check needs the admin served under an RTL locale,
     * which needs the locale switcher that does not exist yet. That half of
     * #12 stays open, alongside the screen-reader pass.
     *
     * What follows measures READINESS, which is a different and weaker claim:
     * whether the CSS that ships would mirror if direction flipped.
     */

    test('the shipped CSS is overwhelmingly direction-agnostic', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        const counts = await page.evaluate(() => {
            let logical = 0;
            let physical = 0;

            for (const sheet of [...document.styleSheets]) {
                let rules;
                try {
                    rules = [...(sheet.cssRules ?? [])];
                } catch {
                    continue; // cross-origin
                }

                for (const rule of rules) {
                    const text = rule.cssText ?? '';
                    logical += (text.match(/margin-inline|padding-inline|inset-inline|border-inline|text-align:\s*(start|end)/g) ?? []).length;
                    // Direction-sensitive properties the first version missed:
                    // bare left/right and border-left/right break RTL just as
                    // surely as margin-left does.
                    physical += (text.match(/(?:^|[;{\s])(?:margin|padding|border)-(?:left|right)\s*:|(?:^|[;{\s])(?:left|right)\s*:|text-align:\s*(?:left|right)/g) ?? []).length;
                }
            }

            return { logical, physical };
        });

        console.log(`  logical: ${counts.logical}  physical: ${counts.physical}`);

        expect(counts.logical).toBeGreaterThan(0);

        // Enforce the RATIO, not merely presence. Only asserting that one
        // logical property exists would stay green if hundreds of physical
        // ones were added and RTL broke entirely — the measured value was
        // computed and then thrown away.
        //
        // Measured 2026-09-07: 535 logical, 139 physical — about 79%
        // direction-agnostic. The first version of this count reported 18
        // physical because it missed bare left/right, border-left/right and
        // text-align, which made the CSS look far more RTL-ready than it is.
        // 0.4 is a regression guard with headroom, not a target.
        expect(counts.physical).toBeLessThan(counts.logical * 0.4);
    });
});
