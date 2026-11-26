/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

const assert = require('node:assert/strict');
const { execFileSync, spawn } = require('node:child_process');
const { createHash } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const { openSelect, callsMethod } = require('../e2e/select-control');
const { measurePanelReads, openPanelPage, PanelReadCaseError } = require('./benchmarks/schema-scale-panel-reads.cjs');
const { measurePanelWrites, PanelWriteCaseError } = require('./benchmarks/schema-scale-panel-writes.cjs');

function fixtureFile(fixture, name) {
    const file = path.join(fixture, name);
    const stat = fs.lstatSync(file);
    assert(stat.isFile() && (stat.mode & 0o077) === 0 && stat.uid === process.getuid(), 'Expected an owned private file in the fixture');
    return file;
}

function sha256(bytes) {
    return createHash('sha256').update(bytes).digest('hex');
}

function preparedFixture(application, fixture, rows, requirePair, engine, includeReads) {
    const bytes = fs.readFileSync(fixtureFile(fixture, 'result.json'));
    const result = JSON.parse(bytes);
    assert.equal(result.rows, rows);
    assert.equal(result.engine, engine);
    assert(Number.isSafeInteger(result.checks_passed) && result.checks_passed > 0, 'Expected a completed seed workload');
    const provenance = {
        seed_result_sha256: sha256(bytes),
        composer_lock_sha256: sha256(fs.readFileSync(path.join(application, 'composer.lock'))),
        paired_profile: null,
    };

    if (requirePair) {
        const profile = path.basename(fixture);
        assert(['floor', 'control'].includes(profile), 'Expected a floor or control fixture from the pair launcher');
        const parent = path.dirname(fixture);
        const pair = JSON.parse(fs.readFileSync(fixtureFile(parent, 'pair.receipt.json')));
        const reference = pair.profiles?.[profile];
        assert.equal(pair.version, 1);
        assert.equal(pair.mode, 'run');
        assert.equal(pair.rows, rows);
        const source = execFileSync('git', ['--no-replace-objects', 'rev-parse', 'HEAD'], {
            cwd: path.dirname(__dirname), encoding: 'utf8', env: { PATH: process.env.PATH },
        }).trim();
        assert.equal(pair.source_commit, source, 'The checkout revision differs from the seed workload');
        assert.equal(reference?.file, profile + '.receipt.json');

        const receiptBytes = fs.readFileSync(fixtureFile(parent, reference.file));
        assert.equal(sha256(receiptBytes), reference.sha256, 'The profile receipt has changed');
        const receipt = JSON.parse(receiptBytes);
        assert.equal(receipt.profile, profile);
        for (const key of ['version', 'mode', 'source_commit', 'image_id', 'rows', 'runtime']) {
            assert.deepEqual(receipt[key], pair[key]);
        }
        assert.equal(receipt.result?.file, profile + '/result.json');
        assert.equal(receipt.result.sha256, provenance.seed_result_sha256, 'The seed result has changed');
        assert.equal(receipt.runtime.composer_lock_sha256, provenance.composer_lock_sha256, 'The application lockfile differs from the seed workload');
        provenance.paired_profile = profile;
    }

    const targets = result.panel_read_targets;
    const media = result.panel_media_targets;
    if (includeReads) {
        assert(Array.isArray(targets) && targets.length === 5, 'Expected five Site targets from the seed');
        const sites = [...targets].map((target) => target.site).sort((a, b) => a - b);
        assert.deepEqual(sites, [1, 2, 3, 4, 5]);
        assert(media && media.shared !== media.other_org, 'Expected distinct media targets');
        for (const id of [media.shared, media.other_org]) {
            assert(Number.isSafeInteger(id) && id > 0 && id <= rows);
        }
        for (const target of targets) {
            assert([1, 2, 3].includes(target.owner));
            for (const id of [target.article, target.selective_article]) {
                assert(Number.isSafeInteger(id) && id > 0 && id <= rows);
            }
        }
    }

    return { provenance, targets, media };
}

function nativeDatabase(marker, fixture) {
    const native = marker.native;
    assert.deepEqual(Object.keys(native).sort(), ['container_id', 'database', 'driver', 'host', 'nonce', 'port']);
    const internalPorts = { pgsql: '5432/tcp', mysql: '3306/tcp', mariadb: '3306/tcp' };
    assert(Object.hasOwn(internalPorts, native.driver));
    assert.equal(native.host, '127.0.0.1');
    assert(Number.isInteger(native.port) && native.port >= 1024 && native.port <= 65535);
    assert(/^[a-f0-9]{64}$/.test(native.container_id));
    assert(/^[a-f0-9]{24}$/.test(native.nonce));
    assert.equal(native.database, 'kitsune_scale_' + native.nonce);

    const [context, recordedEndpoint] = fs.readFileSync(fixtureFile(fixture, 'docker-context.txt'), 'utf8').trimEnd().split('\n');
    assert.equal(context, process.env.KITSUNE_SCALE_DOCKER_CONTEXT, 'The launcher context has changed');
    assert(context && /^[a-zA-Z0-9_.-]+$/.test(context), 'Use the native launcher context');
    const docker = (args) => execFileSync('docker', ['--context', context, ...args], {
        encoding: 'utf8', env: { PATH: process.env.PATH, HOME: process.env.HOME },
    }).trim();
    const endpoint = docker(['context', 'inspect', context, '--format', '{{.Endpoints.docker.Host}}']);
    assert(endpoint.startsWith('unix://'), 'Use a local Docker socket');
    assert.equal(endpoint, recordedEndpoint, 'The launcher endpoint has changed');
    const format = [
        '{{json .Id}}', '{{json .State.Running}}',
        '{{json (index .Config.Labels "io.kitsune.scale.native")}}', '{{json .NetworkSettings.Ports}}',
    ].join('\n');
    const [id, running, nonce, ports] = docker(['container', 'inspect', native.container_id, '--format', format])
        .split('\n').map((line) => JSON.parse(line));
    assert.equal(id, native.container_id);
    assert.equal(running, true);
    assert.equal(nonce, native.nonce);
    assert.deepEqual(ports[internalPorts[native.driver]], [{ HostIp: native.host, HostPort: String(native.port) }]);

    return {
        DB_CONNECTION: native.driver,
        DB_HOST: native.host,
        DB_PORT: String(native.port),
        DB_DATABASE: native.database,
        DB_SOCKET: '',
    };
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
        const page = await openPanelPage(context, origin, credentials);
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

function reservePanelWrites(fixture, marker, engine, native, provenance) {
    const authority = JSON.parse(fs.readFileSync(fixtureFile(fixture, 'panel-writes-authority.json'), 'utf8'));
    let database;
    if (native) {
        database = { nonce: marker.native.nonce };
    } else {
        const stat = fs.statSync(fixtureFile(fixture, 'scale.sqlite'), { bigint: true });
        database = { device: String(stat.dev), inode: String(stat.ino) };
    }
    assert.deepStrictEqual(authority, {
        fixture,
        engine,
        seed_sha256: provenance.seed_result_sha256,
        composer_lock_sha256: provenance.composer_lock_sha256,
        database,
    }, 'The seed did not authorize writes on this fixture');

    fs.closeSync(fs.openSync(path.join(fixture, 'panel-writes.started'), 'wx', 0o600));
}

async function main() {
    const args = process.argv.slice(2);
    const requirePair = args[0] === '--paired';
    const native = args[0] === '--native';
    if (requirePair || native) args.shift();
    const stage = ['--panel-reads', '--panel-sql', '--panel-writes'].includes(args[0]) ? args.shift() : null;
    const panelSql = stage === '--panel-sql';
    const panelReads = panelSql || stage === '--panel-reads';
    const panelWrites = stage === '--panel-writes';
    assert.equal(args.length, 3, 'Use: node bin/benchmark-schema-relations.cjs [--paired|--native] [--panel-reads|--panel-sql|--panel-writes] PHP APPLICATION FIXTURE');
    assert(! (panelWrites && requirePair), 'Start panel writes on a new fixture that explicitly permits writes');
    const [php, application, fixture] = args.map((name) => fs.realpathSync(name));
    const marker = JSON.parse(fs.readFileSync(fixtureFile(fixture, 'owned-experiment.json'), 'utf8'));
    assert.equal(marker.purpose, 'kitsune-schema-scale');
    assert.equal(marker.root, path.dirname(__dirname));
    assert([10_000, 100_000, 1_000_000].includes(marker.rows));
    const lease = path.join(fixture, 'browser-stage.active');
    fs.closeSync(fs.openSync(lease, 'wx', 0o600));
    try {
        await runStage(php, application, fixture, marker, { stage, native, requirePair, panelReads, panelSql, panelWrites });
    } finally {
        fs.unlinkSync(lease);
    }
}

async function runStage(php, application, fixture, marker, { stage, native, requirePair, panelReads, panelSql, panelWrites }) {
    if (! panelWrites) {
        assert(! fs.existsSync(path.join(fixture, 'panel-writes.started')),
            'Run read stages before starting panel writes on a fixture');
    }
    const database = native ? nativeDatabase(marker, fixture) : {
        DB_CONNECTION: 'sqlite', DB_DATABASE: fixtureFile(fixture, 'scale.sqlite'),
    };
    const { provenance, targets, media } = preparedFixture(application, fixture, marker.rows, requirePair, database.DB_CONNECTION, panelReads || panelWrites);
    fixtureFile(fixture, '.env');
    for (const directory of ['storage', 'storage/framework', 'storage/framework/sessions']) {
        assert(fs.lstatSync(path.join(fixture, directory)).isDirectory(), 'Expected private fixture storage');
    }
    const credentials = JSON.parse(fs.readFileSync(fixtureFile(fixture, 'panel-credentials.json'), 'utf8'));
    const accounts = panelReads ? ['owner-1', 'owner-2', 'owner-3', 'editor'] : ['owner-1'];
    for (const account of accounts) {
        const user = credentials[account];
        assert(user && typeof user.email === 'string' && typeof user.password === 'string',
            'Expected the synthetic accounts from the seed');
    }
    assert(! fs.existsSync(path.join(application, 'bootstrap/cache/config.php')), 'The built application has cached configuration');
    const reportName = {
        '--panel-reads': 'schema-scale-panel-reads.json',
        '--panel-sql': 'schema-scale-panel-sql.json',
        '--panel-writes': 'schema-scale-panel-writes.json',
    }[stage] ?? 'relation-picker-browser.json';
    const output = path.join(fixture, reportName);
    assert(! fs.existsSync(output), 'The fixture already has the selected browser report');
    if (panelWrites) reservePanelWrites(fixture, marker, database.DB_CONNECTION, native, provenance);
    if (panelSql) fs.mkdirSync(path.join(fixture, 'read-sql'), { mode: 0o700 });

    const measuredPhp = execFileSync(php, ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8', env: {} });
    const server = spawn(php, [
        '-d', 'memory_limit=128M', '-S', '127.0.0.1:0', '-t', path.join(application, 'public'),
        path.join(__dirname, 'benchmarks/SchemaScaleBrowser.php'),
    ], {
        stdio: ['ignore', 'ignore', 'pipe'],
        env: {
            KITSUNE_SCALE_APPLICATION: application,
            KITSUNE_SCALE_FIXTURE: fixture,
            ...(panelSql ? { KITSUNE_SCALE_CAPTURE_READ_SQL: '1' } : {}),
            ...database,
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
        const origin = await listen(server);
        if (panelReads) {
            samples = await measurePanelReads(origin, credentials, targets, media, panelSql);
        } else if (panelWrites) {
            samples = await measurePanelWrites(origin, credentials['owner-1'], { php, application, fixture, media, repetitions: 25 });
        } else {
            samples = await measure(origin, credentials['owner-1']);
        }
    } finally {
        stop();
        await closed;
        process.removeListener('SIGINT', stop);
        process.removeListener('SIGTERM', stop);
    }

    if (panelReads) {
        const { navigation, privateMedia } = samples;
        assert.equal(navigation.length, 45);
        assert(navigation.every((test) => test.elapsed_ms.length === 25));
        assert.equal(privateMedia.decoded_ms.length, 25);
        const receipt = {
            complete: true,
            engine: database.DB_CONNECTION,
            rows: marker.rows,
            cases: navigation.length,
            observations_per_case: 25,
            fixture: provenance,
            measurement: { php: measuredPhp, node: process.version, resource_limits_applied: null },
        };
        const report = { ...receipt, capture_sql: panelSql, observations: navigation, private_media: privateMedia };
        if (panelSql) {
            const preliminary = path.join(fixture, 'schema-scale-panel-sql.partial.json');
            fs.writeFileSync(preliminary, JSON.stringify({ ...report, complete: false }, null, 2) + '\n',
                { flag: 'wx', mode: 0o600 });
            const plansPath = path.join(fixture, 'schema-scale-read-plans.json');
            const bytes = execFileSync(php, [path.join(__dirname, 'benchmarks/schema-scale-read-plans.php'), application, fixture], { env: {} });
            const plans = JSON.parse(bytes);
            assert.equal(plans.complete, true);
            fs.writeFileSync(plansPath, bytes, { flag: 'wx', mode: 0o600 });
            report.plans = { file: path.basename(plansPath), sha256: sha256(bytes) };
        }
        fs.writeFileSync(output, JSON.stringify(report, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
        if (panelSql) fs.unlinkSync(path.join(fixture, 'schema-scale-panel-sql.partial.json'));
        console.log(JSON.stringify({ ...receipt, result: output }));

        return;
    }

    if (panelWrites) {
        assert.equal(samples.cases.length, 12);
        assert.equal(samples.audited_writes, samples.cases.length * samples.pairs_per_case * 2);
        const receipt = {
            complete: true, rows: marker.rows, engine: database.DB_CONNECTION,
            fixture: provenance, measurement: { php: measuredPhp, node: process.version, resource_limits_applied: null },
        };
        fs.writeFileSync(output, JSON.stringify({ ...samples, ...receipt }, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
        console.log(JSON.stringify({ ...receipt, cases: samples.cases.length, audited_writes: samples.audited_writes, result: output }));
        return;
    }

    const ordered = [...samples].sort((a, b) => a - b);
    const receipt = {
        complete: true,
        case: 'owner_site1_broad_relation_picker',
        rows: marker.rows,
        ...(native ? { engine: database.DB_CONNECTION } : {}),
        observations: samples.length,
        options_each: 50,
        includes_debounce: true,
        fixture: provenance,
        measurement: {
            php: measuredPhp,
            node: process.version,
            server: 'loopback PHP server launched by the browser command',
            resource_limits_applied: null,
        },
        median_ms: ordered[12],
        observed_p95_ms: ordered[23],
    };
    const result = { ...receipt, observations_ms: samples };
    fs.writeFileSync(output, JSON.stringify(result, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
    console.log(JSON.stringify({ ...receipt, result: output }));
}

main().catch((error) => {
    // Playwright errors can contain credential-bearing form actions.
    console.error('Schema-scale browser benchmark failed: ' + (error instanceof PanelReadCaseError || error instanceof PanelWriteCaseError ? error.message : error.name));
    process.exitCode = 1;
});
