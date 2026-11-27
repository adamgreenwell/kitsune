/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const SafeDiagnostics = require('./safe-diagnostics.cjs');

function run(callback) {
    const outputDir = fs.mkdtempSync(path.join(os.tmpdir(), 'kitsune-safe-diagnostics-'));
    try {
        const reporter = new SafeDiagnostics({ outputDir });
        callback(reporter);
        reporter.onEnd({ status: 'passed' });
        return fs.readFileSync(path.join(outputDir, 'summary.json'), 'utf8');
    } finally {
        fs.rmSync(outputDir, { recursive: true, force: true });
    }
}

const location = { file: path.resolve(__dirname, '../readers.spec.js'), line: 208, column: 1 };

test('keeps the failed attempt and successful retry, without arbitrary runner or credential content', () => {
    const secret = 'credential-canary-do-not-retain';
    const text = run((reporter) => {
        const spec = { location, title: secret, titlePath: () => [secret], id: secret };
        reporter.onTestEnd(spec, {
            retry: 0, status: 'timedOut', duration: 5000,
            error: { message: secret, stack: secret }, stdout: [secret], stderr: [secret],
            attachments: [{ name: 'trace', body: Buffer.from(secret) }, {
                name: 'reader-staff-boundary', contentType: 'application/json',
                body: Buffer.from(JSON.stringify({
                    stage: 'rejection', page: 'staff-login', component_ready: true,
                    update_statuses: [429, 500, secret], panel_redirects: [true, true, true],
                    rejection_visible: false, reader_account_accessible: false,
                    password: secret, cookies: secret, request_body: secret, error: secret,
                })),
            }],
        });
        reporter.onTestEnd(spec, { retry: 1, status: 'passed', duration: 900, attachments: [] });
    });
    const data = JSON.parse(text);
    assert.equal(data.status, 'passed');
    assert.deepEqual(data.attempts.map((row) => [row.retry, row.status]), [[0, 'timedOut'], [1, 'passed']]);
    assert.deepEqual(data.attempts[0].boundary.update_statuses, [429, 500]);
    assert.equal(text.includes(secret), false);
    assert.equal(text.includes('stdout'), false);
    assert.equal(text.includes('request_body'), false);
});

test('rejects malformed or path-based attachments and bounds rows, bytes and diagnostic arrays', () => {
    const text = run((reporter) => {
        for (let i = 0; i < 1000; i++) reporter.onTestEnd({ location }, {
            retry: 0, status: 'failed', duration: Infinity,
            attachments: [
                { name: 'reader-staff-boundary', contentType: 'application/json', path: '/must-not-read' },
                { name: 'reader-staff-boundary', contentType: 'application/json', body: Buffer.from('{broken') },
                { name: 'reader-staff-boundary', contentType: 'application/json', body: Buffer.from('x'.repeat(9000)) },
            ],
        });
    });
    const data = JSON.parse(text);
    assert.equal(data.attempts.length <= 128, true);
    assert.equal(data.omitted > 0, true);
    assert.equal(Buffer.byteLength(text) <= 65536, true);
    assert.equal(text.includes('/must-not-read'), false);
    assert.equal(data.attempts.every((row) => row.boundary === undefined), true);
});

test('writes a bounded empty report for a clean first-attempt run', () => {
    const data = JSON.parse(run((reporter) => reporter.onTestEnd({ location }, {
        retry: 0, status: 'passed', duration: 10, attachments: [],
    })));
    assert.deepEqual(data.attempts, []);
    assert.equal(data.total_attempts, 1);
});

test('distinguishes generated variants at one source location without retaining arbitrary variant content', () => {
    const secret = 'credential-like-variant-do-not-retain';
    const data = JSON.parse(run((reporter) => {
        for (const variant of ['normal', 'delayed-password', secret]) {
            reporter.onTestEnd({ location }, {
                retry: 0, status: 'failed', duration: 100,
                attachments: [{
                    name: 'reader-staff-boundary', contentType: 'application/json',
                    body: Buffer.from(JSON.stringify({ stage: 'rejection', variant })),
                }],
            });
        }
    }));
    assert.deepEqual(data.attempts.map((row) => row.boundary.variant), ['normal', 'delayed-password', undefined]);
    assert.equal(JSON.stringify(data).includes(secret), false);
    assert.equal(new Set(data.attempts.map((row) => `${row.file}:${row.line}`)).size, 1);
});

test('keeps only a known staff setup outcome and bounded status codes', () => {
    const canary = 'fixture-credential-canary';
    const setupLocation = { file: path.resolve(__dirname, '../auth-reader.setup.js'), line: 40 };
    const attachment = (outcome) => ({
        name: 'staff-setup-sign-in', contentType: 'application/json',
        body: Buffer.from(JSON.stringify({
            outcome, update_statuses: [200, 200, 700, canary],
            credentials: canary, response_body: canary,
        })),
    });

    const text = run((reporter) => {
        for (const [file, outcome] of [[setupLocation, 'throttled'], [setupLocation, canary], [location, 'throttled']]) {
            reporter.onTestEnd({ location: file }, {
                retry: 0, status: 'failed', duration: 500,
                attachments: [attachment(outcome)],
            });
        }
    });
    const rows = JSON.parse(text).attempts;
    assert.equal(rows[0].file, 'e2e/auth-reader.setup.js');
    assert.deepEqual(rows[0].staff_setup, { outcome: 'throttled', update_statuses: [200, 200] });
    assert.equal(rows[1].staff_setup, undefined);
    assert.equal(rows[2].staff_setup, undefined);
    assert.equal(text.includes(canary), false);
});

test('does not discard a throttle result when another setup observation rejects first', async () => {
    const { submitStaffSetupSignIn } = require('../admin-sign-in');
    const events = new Map();
    const attachments = [];
    const response = {
        url: () => 'http://127.0.0.1:8125/livewire-setup/update',
        request: () => ({ method: () => 'POST' }),
        status: () => 200,
    };
    const page = {
        on: (name, callback) => events.set(name, callback),
        off: (name) => events.delete(name),
        waitForURL: () => Promise.reject(new Error('fixture rejected a navigation observation')),
        getByText: (text) => ({
            first: () => ({
                waitFor: () => text === 'Too many login attempts'
                    ? new Promise((resolve) => setImmediate(resolve))
                    : Promise.reject(new Error('fixture rejected a message observation')),
            }),
        }),
        locator: () => ({ getByRole: () => ({
            click: async () => events.get('response')(response),
        }) }),
    };
    const testInfo = { attach: async (name, attachment) => attachments.push({ name, attachment }) };

    await assert.rejects(submitStaffSetupSignIn(page, testInfo), /Staff setup sign-in: throttled/);
    assert.equal(attachments.length, 1);
    assert.equal(attachments[0].name, 'staff-setup-sign-in');
    assert.deepEqual(JSON.parse(attachments[0].attachment.body.toString()), {
        outcome: 'throttled', update_statuses: [200],
    });
    assert.equal(events.size, 0);
});
