/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const { openSelect } = require('../../e2e/select-control');
const { openPanelPage } = require('./schema-scale-panel-reads.cjs');

class PanelControlCaseError extends Error {}

function isPickerSearch(request, term) {
    if (request.method() !== 'POST' || ! /^\/livewire(?:-[a-f0-9]{8})?\/update$/.test(new URL(request.url()).pathname)) {
        return false;
    }
    try {
        const components = request.postDataJSON()?.components;
        const calls = components?.length === 1 ? components[0].calls : null;
        const call = calls?.length === 1 ? calls[0] : null;
        return call?.method === 'callSchemaComponentMethod' && call.params?.[1] === 'getSearchResultsForJs'
            && call.params?.[2]?.search === term;
    } catch {
        return false;
    }
}

async function measurePanelControls(origin, accounts, targets, media, fixture = null) {
    const browser = await chromium.launch({ headless: true });
    const context = () => browser.newContext({ baseURL: origin, viewport: { width: 1280, height: 900 } });
    const root = (site) => '/admin/scale-site-' + site;
    const article = (site, id) => root(site) + '/c/article/' + id + '/edit';
    const image = (site, id) => root(site) + '/media/' + id;
    const [primary, sibling, otherOrg] = [1, 2, 3].map((site) => targets.find((target) => target.site === site));
    assert(primary && sibling && otherOrg, 'Expected three Sites for the control stage');
    const editPath = (target) => article(target.site, target.article);

    async function checkStatus(name, page, route, expected) {
        const response = await page.request.get(route, { maxRedirects: 0 });
        try {
            if (response.status() !== expected) {
                throw new PanelControlCaseError(name + ' expected HTTP ' + expected + ' but received ' + response.status());
            }
        } finally {
            await response.dispose();
        }
    }

    const taggedSearches = new Map();
    let sequence = 0;
    async function search(page, route, term, expectedIds, timed = false) {
        const navigation = await page.goto(route);
        assert.equal(navigation.status(), 200);
        assert.equal(navigation.url(), new URL(route, origin).href);
        await page.locator('[contenteditable=true]').first().waitFor();
        const input = await openSelect(page, /^links/i);
        const tag = fixture !== null && timed ? 'k' + String(++sequence).padStart(5, '0') : null;
        if (tag !== null) taggedSearches.set(page, { tag, term });
        const start = performance.now();
        const [received] = await Promise.all([
            page.waitForResponse((item) => isPickerSearch(item.request(), term)),
            input.fill(term),
        ]);
        assert.equal(received.status(), 200);
        const body = await received.json();
        const elapsed = performance.now() - start;
        const [options] = body.components.flatMap((entry) => entry.effects.returns ?? []);
        assert.deepEqual(options.map((option) => Number(option.value)), expectedIds);

        if (tag !== null) {
            assert(! taggedSearches.has(page), 'The submitted picker search was not tagged');
            const profile = path.join(fixture, 'picker-sql', tag + '.json');
            const deadline = performance.now() + 5000;
            while (! fs.existsSync(profile) && performance.now() < deadline) {
                await new Promise((resolve) => setTimeout(resolve, 10));
            }
            assert(fs.existsSync(profile), 'The timed picker search is missing its SELECT profile');
        }

        return { elapsed, tag };
    }

    try {
        const owner = await openPanelPage(await context(), origin, accounts['owner-1']);
        const editor = await openPanelPage(await context(), origin, accounts.editor);
        const other = await openPanelPage(await context(), origin, accounts['owner-2']);
        if (fixture !== null) {
            for (const page of [owner, editor, other]) {
                await page.route((url) => url.origin === origin
                    && /^\/livewire(?:-[a-f0-9]{8})?\/update$/.test(url.pathname), async (route) => {
                    const active = taggedSearches.get(page);
                    const request = route.request();
                    if (active && isPickerSearch(request, active.term)) {
                        taggedSearches.delete(page);
                        await route.continue({ headers: { ...await request.allHeaders(), 'x-kitsune-scale-picker': active.tag } });
                    } else {
                        await route.fallback();
                    }
                });
            }
        }

        const accessible = [
            ['owner primary article', owner, editPath(primary)],
            ['owner sibling article', owner, editPath(sibling)],
            ['editor primary article', editor, editPath(primary)],
            ['second owner article', other, editPath(otherOrg)],
            ['owner Site-only asset', owner, image(1, media.site_only)],
            ['owner shared asset from their Site', owner, image(1, media.shared)],
            ['owner shared asset from sibling Site', owner, image(2, media.shared)],
            ['owner asset list', owner, root(1) + '/c/asset'],
            ['second owner shared asset', other, image(3, media.other_org)],
        ];
        for (const [name, page, route] of accessible) await checkStatus(name, page, route, 200);

        const membershipDenials = [
            ['first owner second Org dashboard', owner, root(3)],
            ['first owner second Org article', owner, editPath(otherOrg)],
            ['first owner second Org asset', owner, image(3, media.other_org)],
            ['second owner first Org dashboard', other, root(1)],
        ];
        const membership = [];
        for (const [name, page, route] of membershipDenials) {
            await checkStatus(name, page, route, 404);
            membership.push({ case: name, status: 404 });
        }

        await search(owner, editPath(primary), 'Synthetic asset ' + media.shared, [media.shared]);
        await search(other, editPath(otherOrg), 'Synthetic article ' + otherOrg.selective_article, [otherOrg.selective_article]);

        const denials = [
            ['sibling Site article', owner, article(1, sibling.article), 404],
            ['other Org article', owner, article(1, otherOrg.article), 404],
            ['sibling Site asset', owner, image(2, media.site_only), 404],
            ['other Org asset', owner, image(2, media.other_org), 404],
            ['editor asset', editor, image(1, media.shared), 403],
            ['editor asset list', editor, root(1) + '/c/asset', 403],
            ['editor sibling dashboard', editor, root(2), 404],
            ['editor other Org dashboard', editor, root(3), 404],
        ];
        const permissions = [];
        for (const [name, page, route, status] of denials) {
            await checkStatus(name, page, route, status);
            permissions.push({ case: name, status });
        }

        const cases = [
            ['owner sees shared asset from sibling Site', owner, editPath(sibling), 'Synthetic asset ' + media.shared, [media.shared]],
            ['editor sees article from their Site', editor, editPath(primary), 'Synthetic article ' + primary.selective_article, [primary.selective_article]],
            ['editor cannot see shared asset', editor, editPath(primary), 'Synthetic asset ' + media.shared, []],
            ['other Org cannot see article', other, editPath(otherOrg), 'Synthetic article ' + primary.selective_article, []],
        ];
        const observations = [];
        for (const [name, page, route, term, ids] of cases) {
            const elapsed = [], tags = [];
            try {
                for (let observation = 0; observation <= 25; observation++) {
                    const sample = await search(page, route, term, ids, observation > 0);
                    if (observation > 0) {
                        elapsed.push(sample.elapsed);
                        if (sample.tag !== null) tags.push(sample.tag);
                    }
                }
            } catch (error) {
                throw new PanelControlCaseError(name + ' failed (' + error.name + ')');
            }
            const ordered = [...elapsed].sort((a, b) => a - b);
            observations.push({ case: name, matches: ids.length,
                ...(fixture === null
                    ? { elapsed_ms: elapsed, median_ms: ordered[12], observed_p95_ms: ordered[23] }
                    : { instrumented_elapsed_ms: elapsed, instrumented_median_ms: ordered[12],
                        instrumented_observed_p95_ms: ordered[23], tags, route }) });
        }

        return { permissions, membership, observations, observations_per_case: 25, includes_debounce: true };
    } finally {
        await browser.close();
    }
}

module.exports = { measurePanelControls, PanelControlCaseError };
