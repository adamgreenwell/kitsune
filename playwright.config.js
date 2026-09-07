// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/*
 * The browser layer stays deliberately small (ADR-024). Its job is the class
 * of defect the PHP suite structurally cannot see: URL generation across page
 * boundaries, and anything that only manifests once a page actually renders.
 *
 * The relation-manager spike is the worked example. The PHP suite was
 * seven-of-eight green while the dashboard returned 500, because it only ever
 * requested pages inside /c/{type} and the failure only fires outside it.
 *
 * One browser, on purpose. A matrix here would invert the pyramid.
 */
module.exports = defineConfig({
    testDir: './e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],

    use: {
        baseURL: process.env.KITSUNE_BASE_URL || 'http://127.0.0.1:8125',
        // Trace on first retry was the deciding factor for Playwright over Dusk.
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],

    // Playwright boots the skeleton itself, so there is no orchestration in CI
    // beyond installing its dependencies.
    webServer: {
        command: 'php artisan serve --port=8125 --no-interaction',
        cwd: './skeleton',
        url: 'http://127.0.0.1:8125/up',
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    },
});
