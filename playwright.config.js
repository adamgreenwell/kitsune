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
 * ADR-018 names first. It is carried by a seeded USER's `locale` now rather than
 * by a port of its own — see the `admin-rtl` project.
 */

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
        // The Arabic-preferring editor, whose session is what makes the admin RTL.
        { name: 'setup-rtl', testMatch: /auth-rtl\.setup\.js/ },
        // The user with one grant, whose session is what makes the permission specs mean anything.
        { name: 'setup-reader', testMatch: /auth-reader\.setup\.js/ },
        {
            name: 'skeleton',
            testMatch: /(skeleton|public-site-locale)\.spec\.js/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'admin',
            // ⚠️ `direction` runs under the LTR admin deliberately. Issue #39's failure
            // is a value rendering in the direction of the CHROME, so the interesting
            // case is RTL content in an LTR panel — the RTL project would hide it by
            // agreeing with the content.
            // ⚠️ The UNION of both branches, not either one. #38 added `locale-persistence`
            // and #39 added `relation-picker`, and a merge that kept one silently stops
            // running the other's suite — green, and testing less than it did.
            testMatch: /(admin|accessibility|dashboard|direction|locale-persistence|person-module|relation-picker|entity-type-builder|revisions|roles)\.spec\.js/,
            dependencies: ['setup'],
            use: { ...devices['Desktop Chrome'], storageState: '.playwright/admin-auth.json' },
        },
        // Same admin, same server, a different EDITOR.
        //
        // ⚠️ This used to be a second `php artisan serve` with APP_LOCALE=ar, and the
        // comment here explained that the direction was "fixed for the life of a process
        // by APP_LOCALE" so "a per-test override cannot reach it". Issue #38 is precisely
        // the change that made that false: the UI locale now resolves per request from
        // the viewer's own preference (ADR-018 rule 2).
        //
        // So the fixture had to change with it. Proving RTL through an environment
        // variable would now be proving something Kitsune no longer does — and worse, it
        // would keep passing if the per-request resolution broke.
        /*
         * Same admin, same server, a user holding ONE grant — ADR-033.
         *
         * ⚠️ A PROJECT OF ITS OWN RATHER THAN A SIGN-IN INSIDE THE SPEC, for the reason `auth.setup.js`
         * records: signing in per test against one dev server and one SQLite file was intermittently timing
         * out, and it read as a broken selector rather than as contention.
         */
        /*
         * Media delivery, which needs TWO sessions and therefore two setups — AGENTS.md §9.
         *
         * ⚠️ A PROJECT RATHER THAN A SIGN-IN, for the reason recorded just below: signing in inside a spec
         * against one dev server and one SQLite file timed out intermittently. The owner's state is the
         * project's own; the copy-editor's is read from `setup-reader`'s output file, which is why that
         * setup is a dependency here even though no test in this project signs in as her.
         */
        {
            name: 'media-delivery',
            testMatch: /media-(delivery|sharing|staging)\.spec\.js/,
            dependencies: ['setup', 'setup-reader'],
            use: { ...devices['Desktop Chrome'], storageState: '.playwright/admin-auth.json' },
        },
        {
            name: 'admin-reader',
            testMatch: /permissions\.spec\.js/,
            dependencies: ['setup-reader'],
            use: {
                ...devices['Desktop Chrome'],
                storageState: '.playwright/admin-reader-auth.json',
            },
        },
        {
            name: 'admin-rtl',
            testMatch: /rtl\.spec\.js/,
            dependencies: ['setup-rtl'],
            use: {
                ...devices['Desktop Chrome'],
                storageState: '.playwright/admin-rtl-auth.json',
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
    // ⚠️ ONE server now, where there were two. The second existed only to serve the
    // admin in an RTL locale, because direction was a property of the process. Issue #38
    // made it a property of the request, so a second process is no longer how you get a
    // second direction — and keeping it would have hidden a regression in exactly the
    // mechanism that replaced it.
    webServer: [
        {
            command: 'php artisan serve --port=8125 --no-interaction',
            cwd: './skeleton',
            url: 'http://127.0.0.1:8125/up',
            reuseExistingServer: !process.env.CI,
            timeout: 60_000,
        },
    ],
});
