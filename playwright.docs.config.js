// @ts-check
const { defineConfig } = require('@playwright/test');
const { randomUUID } = require('node:crypto');

// Workers inherit one invocation ID. A later run never clears an earlier failure's output.
const readingRun = process.env.KITSUNE_DOCS_TEST_RUN_ID || randomUUID();
if (!/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/.test(readingRun)) throw new Error('Invalid documentation test run ID');
process.env.KITSUNE_DOCS_TEST_RUN_ID = readingRun;

// Static documentation only: no skeleton, accounts, database or provider traffic.
module.exports = defineConfig({
    testDir: './e2e/documentation',
    workers: 1,
    fullyParallel: false,
    retries: 0,
    forbidOnly: !!process.env.CI,
    outputDir: `build/documentation-browser-results/${readingRun}`,
    reporter: 'list',
    use: { baseURL: 'http://127.0.0.1:8346' },
    webServer: {
        command: 'node e2e/documentation/server.cjs',
        url: 'http://127.0.0.1:8346/versions.json',
        reuseExistingServer: false,
        gracefulShutdown: { signal: 'SIGTERM', timeout: 10_000 },
        timeout: 60_000,
    },
});
