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
/*
 * Arabic, because it is one of the six locales Filament ships a
 * `direction => 'rtl'` translation for (ar, ckb, fa, he, ku, ur) and the one
 * ADR-018 names first.
 */
const RTL_PORT = 8137;
const RTL_BASE_URL = process.env.KITSUNE_RTL_BASE_URL || `http://127.0.0.1:${RTL_PORT}`;

module.exports = defineConfig({
    testDir: './e2e',
    globalSetup: require.resolve('./e2e/global-setup.js'),
    // Serial on purpose, everywhere.
    //
    // The suite drives one dev server backed by one SQLite file, and every
    // admin spec signs in. Run in parallel, workers contend on that single
    // database and sign-in times out - which looked like a broken selector
    // for two rounds before the timings gave it away: passing tests took
    // 3.1s, failing ones sat at the 20s timeout.
    //
    // The layer is deliberately small (ADR-024), so serial costs seconds.
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : [['list']],

    use: {
        baseURL: process.env.KITSUNE_BASE_URL || 'http://127.0.0.1:8125',
        // Trace on first retry was the deciding factor for Playwright over Dusk.
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    projects: [
        // Signs in once; everything after reuses the saved session.
        { name: 'setup', testMatch: /auth\.setup\.js/ },
        {
            name: 'skeleton',
            testMatch: /skeleton\.spec\.js/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'admin',
            // ⚠️ `direction` runs under the LTR admin deliberately. Issue #39's failure
            // is a value rendering in the direction of the CHROME, so the interesting
            // case is RTL content in an LTR panel — the RTL project would hide it by
            // agreeing with the content.
            testMatch: /(admin|accessibility|direction)\.spec\.js/,
            dependencies: ['setup'],
            use: { ...devices['Desktop Chrome'], storageState: '.playwright/admin-auth.json' },
        },
        // Same admin, served under an RTL locale on its own port.
        //
        // The RTL specs need a DIFFERENT baseURL, not a different browser: the
        // direction comes from the server's locale, so the only way to render
        // the admin right-to-left is to ask a server that is running in one.
        {
            name: 'admin-rtl',
            testMatch: /rtl\.spec\.js/,
            dependencies: ['setup'],
            use: {
                ...devices['Desktop Chrome'],
                storageState: '.playwright/admin-auth.json',
                baseURL: RTL_BASE_URL,
            },
        },
    ],

    // Playwright boots the skeleton itself, so there is no orchestration in CI
    // beyond installing its dependencies.
    //
    // TWO servers, one skeleton, one database. The second exists only to serve
    // the admin in an RTL locale, because `dir` is rendered from
    // `__('filament-panels::layout.direction')` and therefore fixed for the
    // life of a process by APP_LOCALE. A per-test override cannot reach it.
    webServer: [
        {
            command: 'php artisan serve --port=8125 --no-interaction',
            cwd: './skeleton',
            url: 'http://127.0.0.1:8125/up',
            reuseExistingServer: !process.env.CI,
            timeout: 60_000,
        },
        {
            command: `php artisan serve --port=${RTL_PORT} --no-interaction`,
            cwd: './skeleton',
            // ⚠️ Spread process.env. Playwright REPLACES the child environment
            // when this key is set rather than merging into it, so a bare
            // `{ APP_LOCALE: 'ar' }` drops PATH and the server never starts.
            env: { ...process.env, APP_LOCALE: 'ar' },
            url: `${RTL_BASE_URL}/up`,
            reuseExistingServer: !process.env.CI,
            timeout: 60_000,
        },
    ],
});
