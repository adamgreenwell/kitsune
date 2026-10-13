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

module.exports = { waitForAdminPassword };
