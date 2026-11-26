/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const { waitForAdminPassword } = require('../../e2e/admin-sign-in');

async function openPanelPage(context, origin, account) {
    await context.route('**/*', (route) => new URL(route.request().url()).origin === origin
        ? route.continue()
        : route.abort());
    const page = await context.newPage();
    page.setDefaultTimeout(30_000);
    assert.equal((await page.goto('/admin/login')).status(), 200);
    await waitForAdminPassword(page);
    await page.locator('input[type=email]').fill(account.email);
    await page.locator('input[type=password]').fill(account.password);
    await page.locator('form').getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/admin/') && ! url.pathname.endsWith('/login'));

    return page;
}

async function measurePrivateMedia(browser, origin, credentials, media) {
    const owner = await browser.newContext({ baseURL: origin, viewport: { width: 1280, height: 900 } });
    const decoded = [];
    const shared = '/admin/scale-site-2/media/' + media.shared;
    let otherOrgStatus;
    try {
        const page = await openPanelPage(owner, origin, credentials['owner-1']);
        assert.equal((await page.goto('/admin/scale-site-2')).status(), 200);

        for (let i = 0; i <= 25; i++) {
            const sample = await page.evaluate(async (route) => {
                const start = performance.now();
                const response = await fetch(route, { cache: 'no-store', redirect: 'manual' });
                const body = await response.blob();
                const image = await createImageBitmap(body);
                try {
                    return { status: response.status, mime: body.type, bytes: body.size, width: image.width, height: image.height,
                        ms: performance.now() - start };
                } finally {
                    image.close();
                }
            }, shared);
            assert.equal(sample.status, 200);
            assert.equal(sample.mime, 'image/png');
            assert.equal(sample.bytes, 70);
            assert.equal(sample.width, 1);
            assert.equal(sample.height, 1);
            if (i > 0) decoded.push(sample.ms);
        }

        const refused = await page.request.get('/admin/scale-site-2/media/' + media.other_org, { maxRedirects: 0 });
        otherOrgStatus = refused.status();
        assert.equal(otherOrgStatus, 404);
    } finally {
        await owner.close();
    }

    const editor = await browser.newContext({ baseURL: origin, viewport: { width: 1280, height: 900 } });
    let withheldStatus;
    try {
        const page = await openPanelPage(editor, origin, credentials.editor);
        assert.equal((await page.goto('/admin/scale-site-1/c/article/1/edit')).status(), 200);
        await page.getByRole('button', { name: 'Save changes' }).waitFor();
        const refused = await page.request.get('/admin/scale-site-1/media/' + media.shared, { maxRedirects: 0 });
        withheldStatus = refused.status();
        assert.equal(withheldStatus, 403);
    } finally {
        await editor.close();
    }

    const ordered = [...decoded].sort((a, b) => a - b);
    return { decoded_ms: decoded, median_ms: ordered[12], observed_p95_ms: ordered[23],
        image: { bytes: 70, width: 1, height: 1 }, other_org_status: otherOrgStatus, withheld_by_role_status: withheldStatus };
}

class PanelReadCaseError extends Error {}

async function measurePanelReads(origin, credentials, targets, media, captureSql = false) {
    const browser = await chromium.launch({ headless: true });
    const results = [];
    const sessions = new Map();
    let sequence = 0;

    async function navigate(page, route, ready) {
        const tag = captureSql ? 'p' + String(++sequence).padStart(5, '0') : null;
        if (tag !== null) {
            await page.setExtraHTTPHeaders({ 'X-Kitsune-Scale-Read': tag });
        }
        const start = performance.now();
        try {
            const response = await page.goto(route, { waitUntil: 'domcontentloaded' });
            assert.equal(response.status(), 200);
            assert.equal(response.url(), new URL(route, origin).href);
            await ready();

            return { ms: performance.now() - start, tag };
        } finally {
            if (tag !== null) await page.setExtraHTTPHeaders({});
        }
    }

    async function summary(page) {
        const text = await page.locator('.fi-ta .fi-pagination-overview').first().innerText();
        const match = text.match(/Showing (\d[\d,]*) to (\d[\d,]*) of (\d[\d,]*)/i);
        assert(match, 'Read the list pagination summary');

        return match.slice(1).map((number) => Number(number.replaceAll(',', '')));
    }

    try {
        for (const target of targets) {
            if (! sessions.has(target.owner)) {
                const context = await browser.newContext({ baseURL: origin, viewport: { width: 1280, height: 900 } });
                sessions.set(target.owner, await openPanelPage(context, origin, credentials['owner-' + target.owner]));
            }
            const page = sessions.get(target.owner);
            const root = '/admin/scale-site-' + target.site;
            const articles = root + '/c/article';
            const entry = articles + '/' + target.article;
            const searchId = target.selective_article;
            const selectiveTitle = 'Synthetic article ' + searchId;
            const articleRows = page.locator('.fi-ta-row');
            const first = () => articleRows.first().waitFor();
            await navigate(page, articles, first);
            const [firstRow, lastRow, total] = await summary(page);
            assert.equal(firstRow, 1);
            const perPage = lastRow - firstRow + 1;
            const lastPage = Math.ceil(total / perPage);
            assert(lastPage > 1);

            const cases = [
                ['dashboard', root, async () => {
                    await page.locator('.fi-wi-widget a[href$="/c/article"]').first().waitFor();
                    await page.locator('.fi-wi-widget .fi-ta-row').first().waitFor();
                }],
                ['article first page', articles, first, async () => {
                    assert.equal((await summary(page))[0], 1);
                }],
                ['article last page', articles + '?page=' + lastPage, first, async () => {
                    const [shownFrom, shownTo, shownTotal] = await summary(page);
                    assert.deepEqual([shownFrom, shownTo, shownTotal], [(lastPage - 1) * perPage + 1, total, total]);
                }],
                ['article broad search', articles + '?search=Synthetic', first, async () => {
                    assert.equal(await page.locator('.fi-ta').getByPlaceholder('Search').inputValue(), 'Synthetic');
                    assert((await articleRows.first().innerText()).includes('Synthetic article'));
                    const [, , filtered] = await summary(page);
                    // Site 1 also holds the audited write samples, whose titles do not match.
                    assert(target.site === 1 ? filtered < total : filtered === total);
                }],
                ['article selective search', articles + '?search=' + searchId, first, async () => {
                    const titles = await articleRows.allInnerTexts();
                    assert.equal(titles.length, 1);
                    assert(titles[0].includes(selectiveTitle));
                }],
                ['article edit', entry + '/edit', () => page.getByRole('button', { name: 'Save changes' }).waitFor()],
                ['article view', entry, () => page.getByRole('heading', { name: /^View / }).waitFor()],
                ['article related records', entry + '/related', () => page.locator('.fi-ta-row').first().waitFor()],
                ['asset first page', root + '/c/asset',
                    () => page.locator(target.site === 5 ? '.fi-ta-empty-state' : '.fi-ta-record').first().waitFor()],
            ].map(([name, route, ready, check]) => ({ name, route, ready, check, elapsed: [], tags: [] }));

            for (let pass = 0; pass <= 25; pass++) {
                for (const test of cases) {
                    try {
                        const ms = await navigate(page, test.route, test.ready);
                        await test.check?.();
                        if (pass > 0) {
                            test.elapsed.push(ms.ms);
                            if (ms.tag !== null) test.tags.push(ms.tag);
                        }
                    } catch (error) {
                        throw new PanelReadCaseError('Site ' + target.site + ' ' + test.name + ' failed (' + error.name + ')');
                    }
                }
            }
            for (const test of cases) {
                const ordered = [...test.elapsed].sort((a, b) => a - b);
                results.push({ site: target.site, name: test.name, elapsed_ms: test.elapsed,
                    ...(captureSql ? { sample_tags: test.tags } : {}),
                    median_ms: ordered[12], observed_p95_ms: ordered[23] });
            }
        }

        return { navigation: results, privateMedia: await measurePrivateMedia(browser, origin, credentials, media) };
    } finally {
        await browser.close();
    }
}

module.exports = { measurePanelReads, openPanelPage, PanelReadCaseError };
