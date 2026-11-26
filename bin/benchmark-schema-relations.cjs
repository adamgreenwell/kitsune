/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const { waitForAdminPassword } = require('../e2e/admin-sign-in');
const { openSelect, callsMethod } = require('../e2e/select-control');

function fixtureFile(fixture, name) {
    const file = path.join(fixture, name);
    const stat = fs.lstatSync(file);
    assert(stat.isFile() && (stat.mode & 0o077) === 0 && stat.uid === process.getuid(), 'Expected an owned private file in the fixture');
    return file;
}

function listen(server) {
    return new Promise((resolve, reject) => {
        let started = '';
        const timer = setTimeout(() => reject(new Error('PHP did not report a local listener')), 5000);
        server.once('error', reject);
        server.once('exit', () => reject(new Error('PHP exited during startup')));
        server.stderr.on('data', (chunk) => {
            started = (started + chunk).slice(-1024);
            const match = started.match(/Development Server \((http:\/\/127\.0\.0\.1:\d+)\) started/);
            if (match) {
                server.stderr.removeAllListeners('data');
                server.stderr.resume();
                clearTimeout(timer);
                resolve(match[1]);
            }
        });
    });
}

async function measure(origin, credentials) {
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({ baseURL: origin, viewport: { width: 1280, height: 900 } });
        await context.route('**/*', (route) => new URL(route.request().url()).origin === origin
            ? route.continue()
            : route.abort());
        const page = await context.newPage();
        page.setDefaultTimeout(30_000);
        assert.equal((await page.goto('/admin/login')).status(), 200);
        await waitForAdminPassword(page);
        await page.locator('input[type=email]').fill(credentials.email);
        await page.locator('input[type=password]').fill(credentials.password);
        await page.locator('form').getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL((url) => url.pathname.startsWith('/admin/') && ! url.pathname.endsWith('/login'));
        assert.equal((await page.goto('/admin/scale-site-1/c/article/1/edit')).status(), 200);
        await page.locator('[contenteditable=true]').first().waitFor();

        const samples = [];
        for (let iteration = 0; iteration <= 25; iteration++) {
            const input = await openSelect(page, /^links/i);
            const response = page.waitForResponse((item) => callsMethod(item, 'callSchemaComponentMethod', 'getSearchResultsForJs'));
            const start = performance.now();
            await input.fill(iteration % 2 === 0 ? 'synthetic' : 'Synthetic');
            const result = await response;
            assert.equal(result.status(), 200);
            const payload = await result.json();
            const [options] = payload.components.flatMap((component) => component.effects.returns ?? []);
            assert.equal(options.length, 50);
            await page.locator('[role=option]:visible').first().waitFor();
            if (iteration > 0) {
                samples.push(performance.now() - start);
            }
            await page.keyboard.press('Escape');
        }

        return samples;
    } finally {
        await browser.close();
    }
}

async function main() {
    assert.equal(process.argv.length, 5, 'Use: node bin/benchmark-schema-relations.cjs PHP APPLICATION FIXTURE');
    const [php, application, fixture] = process.argv.slice(2).map((name) => fs.realpathSync(name));
    const marker = JSON.parse(fs.readFileSync(fixtureFile(fixture, 'owned-experiment.json'), 'utf8'));
    assert.equal(marker.purpose, 'kitsune-schema-scale');
    assert.equal(marker.root, path.dirname(__dirname));
    assert([10_000, 100_000, 1_000_000].includes(marker.rows));
    fixtureFile(fixture, '.env');
    for (const directory of ['storage', 'storage/framework', 'storage/framework/sessions']) {
        assert(fs.lstatSync(path.join(fixture, directory)).isDirectory(), 'Expected private fixture storage');
    }
    const database = fixtureFile(fixture, 'scale.sqlite');
    const credentials = JSON.parse(fs.readFileSync(fixtureFile(fixture, 'panel-credentials.json'), 'utf8'))['owner-1'];
    assert(! fs.existsSync(path.join(application, 'bootstrap/cache/config.php')), 'The built application has cached configuration');
    const output = path.join(fixture, 'relation-picker-browser.json');
    assert(! fs.existsSync(output), 'The fixture already has a relation-picker report');

    const server = spawn(php, [
        '-d', 'memory_limit=128M', '-S', '127.0.0.1:0', '-t', path.join(application, 'public'),
        path.join(__dirname, 'benchmarks/SchemaScaleBrowser.php'),
    ], {
        stdio: ['ignore', 'ignore', 'pipe'],
        env: {
            KITSUNE_SCALE_APPLICATION: application,
            KITSUNE_SCALE_FIXTURE: fixture,
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: database,
            DB_URL: '',
            DB_FOREIGN_KEYS: 'true',
            SESSION_DRIVER: 'file',
            APP_PACKAGES_CACHE: path.join(fixture, 'packages.php'),
            APP_SERVICES_CACHE: path.join(fixture, 'services.php'),
        },
    });
    const closed = new Promise((resolve) => server.once('close', resolve));
    const stop = () => server.kill('SIGTERM');
    process.once('SIGINT', stop);
    process.once('SIGTERM', stop);
    let samples;
    try {
        samples = await measure(await listen(server), credentials);
    } finally {
        stop();
        await closed;
        process.removeListener('SIGINT', stop);
        process.removeListener('SIGTERM', stop);
    }

    const ordered = [...samples].sort((a, b) => a - b);
    const receipt = {
        complete: true,
        case: 'owner_site1_broad_relation_picker',
        rows: marker.rows,
        observations: samples.length,
        options_each: 50,
        includes_debounce: true,
        median_ms: ordered[12],
        observed_p95_ms: ordered[23],
    };
    const result = { ...receipt, observations_ms: samples };
    fs.writeFileSync(output, JSON.stringify(result, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
    console.log(JSON.stringify({ ...receipt, result: output }));
}

main().catch((error) => {
    // Playwright errors can contain credential-bearing form actions.
    console.error('Relation-picker benchmark failed: ' + error.name);
    process.exitCode = 1;
});
