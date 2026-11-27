// @ts-check

/**
 * Filament's revealable password component loads asynchronously. In 5.10.1 the input already has
 * type=password before Alpine and wire:model initialize, so filling it immediately can be overwritten by
 * hydration. Wait for the real component, rather than a delay or a retry of an invalid sign-in.
 * @param {import('@playwright/test').Page} page
 */
async function waitForAdminPassword(page) {
    await page.waitForFunction(() => {
        const input = document.querySelector('input[type="password"]');
        const wrapper = input?.closest('[x-data]');
        const alpine = /** @type {any} */ (window).Alpine;

        return wrapper && alpine && typeof alpine.$data(wrapper).isPasswordRevealed === 'boolean';
    });
}

/** @param {import('@playwright/test').Page} page */
function staffThrottleToast(page) {
    return page.getByText('Too many login attempts', { exact: true }).first();
}

/**
 * Classify the normal staff setup response while a short-lived refusal is visible.
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').TestInfo} testInfo
 */
async function submitStaffSetupSignIn(page, testInfo) {
    const statuses = [];
    const recordStatus = (response) => {
        if (statuses.length < 16 && response.request().method() === 'POST'
            && /\/livewire(?:-[^/]+)?\/update$/.test(new URL(response.url()).pathname)) {
            statuses.push(response.status());
        }
    };
    page.on('response', recordStatus);

    try {
        const timeout = 30_000;
        const result = Promise.any([
            page.waitForURL((url) => url.pathname.startsWith('/admin/') && !url.pathname.endsWith('/login'), { timeout })
                .then(() => 'signed-in'),
            staffThrottleToast(page).waitFor({ timeout }).then(() => 'throttled'),
            page.getByText('These credentials do not match our records.', { exact: true }).first()
                .waitFor({ timeout }).then(() => 'rejected'),
        ]).catch(() => 'no-completion');
        await page.locator('form').getByRole('button', { name: 'Sign in' }).click();
        const outcome = await result;

        if (outcome !== 'signed-in') {
            await testInfo.attach('staff-setup-sign-in', {
                contentType: 'application/json',
                body: Buffer.from(JSON.stringify({ outcome, update_statuses: statuses })),
            });
            throw new Error('Staff setup sign-in: ' + outcome);
        }
    } finally {
        page.off('response', recordStatus);
    }
}

module.exports = { waitForAdminPassword, staffThrottleToast, submitStaffSetupSignIn };
