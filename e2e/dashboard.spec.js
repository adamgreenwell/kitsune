// @ts-check
const { test, expect } = require('@playwright/test');

/*
 * The dashboard says something — the alpha pass found it empty.
 *
 * A new install opened onto Filament's dashboard with no widgets: a heading and nothing under it. It now counts
 * each entry type the sidebar offers and lists what changed most recently. `DashboardWidgetsTest` pins which
 * types, counts and rows; this pins that the page renders them and that each link lands.
 *
 * ⚠️ NO COUNT IS ASSERTED AS A NUMBER. Other specs in this project create and delete entries, so the numbers on
 * the page are not this spec's to know — the reason `permissions.spec.js` asserts a table rather than a row.
 *
 * The session is an owner's. What a copy-editor sees is in `permissions.spec.js`.
 */

const SITE = 'golfdom';

test.describe('dashboard', () => {
    test('counts each type the sidebar offers, linking to its list', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        const stats = page.locator('a.fi-wi-stats-overview-stat');

        await expect(stats.filter({ hasText: 'Articles' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/article$`));
        await expect(stats.filter({ hasText: 'Products' })).toHaveAttribute('href', new RegExp(`/admin/${SITE}/c/product$`));

        // Turned off for this site (ADR-022), so absent here exactly as it is from the sidebar.
        await expect(stats.filter({ hasText: 'Podcasts' })).toHaveCount(0);
    });

    test('restores the latest chart dataset when a decimated widget is destroyed', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);
        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

        const results = await page.evaluate(async () => {
            const { default: createChart } = await import('/js/filament/widgets/components/chart.js');
            const points = (count, offset = 0) => Array.from({ length: count }, (_, x) => ({ x, y: offset + x % 17 }));
            const restored = (dataset, source) => dataset.data === source
                && Object.getOwnPropertyDescriptor(dataset, 'data')?.writable === true
                && !Object.hasOwn(dataset, '_data');

            // Mount the real published widget with a canvas and its color references.
            const mount = async (dataset) => {
                const element = document.createElement('section');
                const canvas = document.createElement('canvas');
                const color = document.createElement('span');
                canvas.width = 200;
                canvas.height = 150;
                element.append(canvas, color);
                document.body.append(element);
                const widget = createChart({
                    cachedData: { datasets: [dataset] },
                    options: {
                        parsing: false,
                        scales: { x: { type: 'linear' } },
                        plugins: { decimation: { enabled: true, algorithm: 'lttb', samples: 12, threshold: 20 } },
                    },
                    type: 'line',
                });
                Object.assign(widget, {
                    $el: element,
                    $refs: {
                        canvas, backgroundColorElement: color, borderColorElement: color,
                        textColorElement: color, gridColorElement: color,
                    },
                    $wire: { $el: element, $on: () => () => {} },
                    $nextTick: (callback) => queueMicrotask(callback),
                    $dispatch: () => {},
                });
                widget.init();
                await new Promise((resolve) => requestAnimationFrame(resolve));
                return {
                    chart: widget.getChart(),
                    destroy() {
                        widget.destroy();
                        element.remove();
                    },
                };
            };

            const dataset = { data: points(400), label: 'Synthetic chart' };
            const replacement = points(300, 23);
            const mounted = await mount(dataset);
            const initiallyDecimated = dataset.data.length === 12;
            dataset.data = replacement;
            mounted.chart.update('none');
            const replacementDecimated = dataset.data.length === 12;
            mounted.destroy();
            const restoredOnDestroy = restored(dataset, replacement);

            const remounted = await mount(dataset);
            const decimatedOnRemount = dataset.data.length === 12;
            remounted.destroy();

            const controls = {};
            for (const mode of ['disabled', 'belowThreshold', 'small']) {
                const source = points(mode === 'small' ? 8 : 400);
                const control = { data: source, label: mode };
                const mounted = await mount(control);
                const decimation = mounted.chart.options.plugins.decimation;
                if (mode === 'disabled') decimation.enabled = false;
                if (mode === 'belowThreshold') decimation.threshold = 1000;
                mounted.chart.update('none');
                const beforeDestroy = restored(control, source);
                mounted.destroy();
                controls[mode] = beforeDestroy && restored(control, source);
            }

            return {
                initiallyDecimated, replacementDecimated, restoredOnDestroy,
                decimatedOnRemount, restoredOnRemountDestroy: restored(dataset, replacement), controls,
            };
        });

        expect(results).toEqual({
            initiallyDecimated: true,
            replacementDecimated: true,
            restoredOnDestroy: true,
            decimatedOnRemount: true,
            restoredOnRemountDestroy: true,
            controls: { disabled: true, belowThreshold: true, small: true },
        });
    });

    test('lists recently updated entries, each opening its own entry', async ({ page }) => {
        await page.goto(`/admin/${SITE}`);

        const row = page.locator('.fi-wi-table .fi-ta-row').first();
        await expect(row).toBeVisible();

        await row.locator(`a[href*="/admin/${SITE}/c/"]`).first().click();

        // The view page, which is the grant that listed the row, rather than a form an editor may be refused.
        await page.waitForURL(new RegExp(`/admin/${SITE}/c/[a-z0-9_-]+/\\d+$`));
    });
});
