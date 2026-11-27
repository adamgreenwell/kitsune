/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
const fs = require('node:fs');
const path = require('node:path');
const ROOT = path.resolve(__dirname, '../..');
const STATUSES = new Set(['passed', 'failed', 'timedOut', 'skipped', 'interrupted']);
const STAGES = new Set(['navigation', 'readiness', 'credentials', 'rejection', 'reader-account', 'complete']);
const PAGES = new Set(['staff-login', 'reader-account', 'other']);
const VARIANTS = new Set(['normal', 'delayed-password']);
const STAFF_SETUP_FILES = new Set(['e2e/auth.setup.js', 'e2e/auth-reader.setup.js', 'e2e/auth-rtl.setup.js']);
const STAFF_SETUP_OUTCOMES = new Set(['throttled', 'rejected', 'no-completion']);
const integer = (value, maximum) => Number.isFinite(value) ? Math.max(0, Math.min(maximum, Math.floor(value))) : 0;
const httpStatuses = (values) => values.slice(0, 16).filter((v) => Number.isInteger(v) && v >= 100 && v <= 599);

/** Only the explicit diagnostic schema crosses into an uploaded artifact. Never copy errors, logs or traces. */
function boundary(attachments = []) {
    for (const attachment of attachments) {
        if (attachment.name !== 'reader-staff-boundary' || attachment.contentType !== 'application/json'
            || !Buffer.isBuffer(attachment.body) || attachment.body.length > 8192) continue;
        try {
            const value = JSON.parse(attachment.body.toString('utf8'));
            if (!value || !STAGES.has(value.stage)) continue;
            const safe = { stage: value.stage };
            if (VARIANTS.has(value.variant)) safe.variant = value.variant;
            if (PAGES.has(value.page)) safe.page = value.page;
            for (const key of ['component_ready', 'rejection_visible', 'reader_account_accessible']) {
                if (typeof value[key] === 'boolean') safe[key] = value[key];
            }
            if (value.throttle_visible === true) safe.throttle_visible = true;
            if (Array.isArray(value.panel_redirects)) safe.panel_redirects = value.panel_redirects.slice(0, 3).filter((v) => typeof v === 'boolean');
            if (Array.isArray(value.update_statuses)) safe.update_statuses = httpStatuses(value.update_statuses);
            return safe;
        } catch {
            // A malformed diagnostic must not become an error dump or prevent a retry report from being written.
        }
    }
}

function staffSetup(attachments = []) {
    for (const attachment of attachments) {
        if (attachment.name !== 'staff-setup-sign-in' || attachment.contentType !== 'application/json'
            || !Buffer.isBuffer(attachment.body) || attachment.body.length > 8192) continue;
        try {
            const value = JSON.parse(attachment.body.toString('utf8'));
            if (!value || !STAFF_SETUP_OUTCOMES.has(value.outcome)) continue;
            return {
                outcome: value.outcome,
                update_statuses: Array.isArray(value.update_statuses) ? httpStatuses(value.update_statuses) : [],
            };
        } catch {
            // Ignore malformed diagnostics without importing their content into the summary.
        }
    }
}

class SafeDiagnostics {
    constructor(options = {}) {
        this.outputDir = options.outputDir || path.join(ROOT, 'playwright-diagnostics');
        this.attempts = [];
        this.total = 0;
        this.omitted = 0;
    }

    onTestEnd(test, result) {
        this.total = integer(this.total + 1, 1000000);
        if (result.status === 'passed' && result.retry === 0) return;
        if (this.attempts.length >= 128) { this.omitted++; return; }
        const relative = path.relative(ROOT, test.location.file).replaceAll(path.sep, '/');
        const row = {
            file: /^e2e\/[a-z0-9-]+\.spec\.js$/.test(relative) || STAFF_SETUP_FILES.has(relative) ? relative : 'unlisted',
            line: integer(test.location.line, 1000000),
            retry: integer(result.retry, 20),
            status: STATUSES.has(result.status) ? result.status : 'unknown',
            duration_ms: integer(result.duration, 3600000),
        };
        const diagnostic = boundary(result.attachments);
        if (diagnostic) row.boundary = diagnostic;
        if (STAFF_SETUP_FILES.has(relative)) {
            const setup = staffSetup(result.attachments);
            if (setup) row.staff_setup = setup;
        }
        this.attempts.push(row);
    }

    onEnd(result) {
        const report = { version: 1, status: STATUSES.has(result.status) ? result.status : 'unknown', total_attempts: this.total, omitted: this.omitted, attempts: this.attempts };
        let text = JSON.stringify(report, null, 2) + '\n';
        while (Buffer.byteLength(text) > 65536 && report.attempts.length) {
            report.attempts.pop(); report.omitted++;
            text = JSON.stringify(report, null, 2) + '\n';
        }
        fs.mkdirSync(this.outputDir, { recursive: true });
        fs.writeFileSync(path.join(this.outputDir, 'summary.json'), text);
    }
}

module.exports = SafeDiagnostics;
