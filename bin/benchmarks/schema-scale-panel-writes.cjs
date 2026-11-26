/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require('playwright');
const { openSelect, selectField, callsMethod } = require('../../e2e/select-control');
const { openPanelPage } = require('./schema-scale-panel-reads.cjs');

const entries = [{ id: 1, bodyBytes: 32768 }, { id: 3, bodyBytes: 4096 }, { id: 11, bodyBytes: 768 }];

function inspectWriteState(php, application, fixture, id, after = null) {
    const args = [path.join(__dirname, 'schema-scale-write-state.php'), application, fixture, String(id)];
    if (after !== null) args.push(String(after));
    const result = JSON.parse(execFileSync(php, args, { encoding: 'utf8', env: {} }));
    assert.equal(result.complete, true);
    assert.equal(Number(result.entry.id), id);
    return result;
}

function flipScore(original) {
    return Number((100 - Number(original)).toFixed(2));
}

async function setLinks(page, targets) {
    const field = selectField(page, /^links/i).first();
    const removals = field.locator('[aria-label^="Remove"]');
    for (let i = (await removals.count()) - 1; i >= 0; i--) {
        await removals.nth(i).click();
    }
    for (const id of targets) {
        const term = 'Synthetic asset ' + id;
        const input = await openSelect(page, /^links/i);
        await input.fill(term);
        await page.getByRole('option', { name: term, exact: true }).click();
        await page.keyboard.press('Escape');
    }
}

const edits = [
    {
        name: 'body and score',
        change(candidate) {
            candidate.data.body = candidate.data.body.replaceAll('Synthetic', 'Benchmark');
            candidate.data.score = flipScore(candidate.data.score);
        },
        async fill(page, state) {
            const editor = page.locator('.tiptap[contenteditable="true"]').first();
            const text = state.data.body.replace(/^<p dir="auto">|<\/p>$/g, '');
            await editor.fill(text);
            await page.getByLabel('score', { exact: true }).fill(String(state.data.score));
        },
    },
    {
        name: 'category',
        change(candidate) {
            candidate.data.category = candidate.data.category === 'guide' ? 'feature' : 'guide';
        },
        async fill(page, state) {
            await page.getByLabel('category', { exact: true }).selectOption(state.data.category);
        },
    },
    {
        name: 'tags',
        change(candidate) {
            candidate.data.tags = ['measured-1', 'measured-2'];
        },
        async fill(page, state) {
            const rows = page.locator('input[id*="values.tags."]');
            assert.equal(await rows.count(), state.data.tags.length);
            for (const [position, value] of state.data.tags.entries()) {
                await rows.nth(position).fill(value);
            }
        },
    },
    {
        name: 'ordered links and score',
        change(candidate, sharedTargets) {
            candidate.data.score = flipScore(candidate.data.score);
            candidate.targets = candidate.targets.length === 2 ? [...candidate.targets].reverse() : sharedTargets;
        },
        async fill(page, state) {
            await page.getByLabel('score', { exact: true }).fill(String(state.data.score));
            await setLinks(page, state.targets);
        },
    },
];

function checkWrite(stored, before, expected, id) {
    assert.deepEqual(stored.entry.data, expected.data);
    assert.deepEqual(stored.latest.data, expected.data);
    assert.deepEqual(stored.links.map((link) => Number(link.target_entry_id)), expected.targets);
    assert(stored.links.every((link) => Number(link.org_id) === 1));
    assert.deepEqual(Object.values(stored.latest.relation_state).flat().map(Number), expected.targets);
    assert(Number(stored.latest.id) > Number(before.latest.id));
    assert.equal(stored.history, Math.min(50, before.history + 1));
    assert.equal(Number(stored.audit.count), Number(before.audit.count) + 1);
    assert.equal(stored.after_audit.length, 1);
    const [row] = stored.after_audit;
    assert.equal(row.action, 'entry.updated');
    assert.equal(Number(row.org_id), 1);
    assert.equal(Number(row.site_id), 1);
    assert.equal(Number(row.actor_id), stored.actor_id);
    assert.equal(Number(row.target_id), id);
    assert.equal(row.actor_type, 'App\\Models\\User');
    assert.equal(row.target_type, 'Kitsune\\Core\\Models\\Entry');
}

class PanelWriteCaseError extends Error {}

async function measurePanelWrites(origin, account, { php, application, fixture, media, repetitions }) {
    const browser = await chromium.launch({ headless: true });
    const initialAudit = Number(inspectWriteState(php, application, fixture, entries[0].id).audit.count);
    const results = [];
    const sharedTargets = [media.shared, media.shared + 1];

    try {
        const context = await browser.newContext({ baseURL: origin, viewport: { width: 1280, height: 900 } });
        const page = await openPanelPage(context, origin, account);

        async function save(id, expected, before, edit) {
            const route = '/admin/scale-site-1/c/article/' + id + '/edit';
            const opened = await page.goto(route);
            assert.equal(opened.status(), 200);
            assert.equal(opened.url(), new URL(route, origin).href);
            await page.getByRole('button', { name: 'Save changes' }).waitFor();
            await edit.fill(page, expected);

            const response = page.waitForResponse((item) => callsMethod(item, 'save'));
            const start = performance.now();
            await page.getByRole('button', { name: 'Save changes' }).click();
            const saved = await response;
            assert.equal(saved.status(), 200);
            const payload = await saved.json();
            assert(Array.isArray(payload.components) && payload.components.length > 0);
            const elapsed = performance.now() - start;

            const stored = inspectWriteState(php, application, fixture, id, Number(before.audit.latest));
            checkWrite(stored, before, expected, id);
            return { elapsed, stored };
        }

        for (const entry of entries) {
            for (const edit of edits) {
                let before = inspectWriteState(php, application, fixture, entry.id);
                const original = { data: before.entry.data, targets: before.links.map((link) => Number(link.target_entry_id)) };
                assert.equal(Buffer.byteLength(original.data.body), entry.bodyBytes);
                const candidate = structuredClone(original);
                edit.change(candidate, sharedTargets);
                const changes = [], restores = [];
                for (let i = 0; i < repetitions; i++) {
                    try {
                        const changed = await save(entry.id, candidate, before, edit);
                        const restored = await save(entry.id, original, changed.stored, edit);
                        changes.push(changed.elapsed);
                        restores.push(restored.elapsed);
                        before = restored.stored;
                    } catch (error) {
                        throw new PanelWriteCaseError(edit.name + ' with ' + entry.bodyBytes + ' body bytes failed (' + error.name + ')');
                    }
                }

                results.push({ entry: entry.id, body_bytes: entry.bodyBytes, name: edit.name,
                    edit_response_ms: changes, restore_response_ms: restores, history_rows: before.history });
            }
        }

        const writes = Number(inspectWriteState(php, application, fixture, entries[0].id).audit.count) - initialAudit;
        assert.equal(writes, results.length * repetitions * 2);
        return { cases: results, pairs_per_case: repetitions, audited_writes: writes, timing_end: 'save response body available' };
    } finally {
        await browser.close();
    }
}

module.exports = { measurePanelWrites, PanelWriteCaseError };
