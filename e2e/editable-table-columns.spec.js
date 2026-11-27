const { expect, test } = require('@playwright/test');

const responses = [
    { name: 'success', status: 200, message: 'Saved' },
    { name: 'validation', status: 200, message: 'The fixture refused this value.' },
    { name: 'server', status: 500, message: 'Save failed' },
    { name: 'transport', message: 'Save failed' },
];

for (const column of ['checkbox', 'toggle', 'text-input', 'select', 'custom-select']) {
    for (const response of responses) {
        test(column + ' clears busy and keeps the correct server value after a ' + response.name + ' response', async ({ page }) => {
            const isBoolean = column === 'checkbox' || column === 'toggle';
            const before = isBoolean ? false : 'before';
            const after = isBoolean ? true : 'after';
            const savedBefore = isBoolean ? '0' : 'before';
            const savedAfter = isBoolean ? '1' : 'after';

            await page.goto('/__e2e/editable-columns/' + column + '/' + response.name);
            const cell = page.locator('#editable-cell');
            const origin = new URL(page.url()).origin;
            const isUpdate = url => url.origin === origin && /^\/livewire(?:-[^/]+)?\/update$/.test(url.pathname);
            const readColumn = () => cell.evaluate(element => {
                const state = window.Alpine?.$data(element);

                return {
                    busy: state?.isLoading,
                    edit: state?.state,
                    message: state?.status,
                    saved: element.querySelector('[x-ref=serverState]').value,
                };
            });

            await expect.poll(readColumn).toMatchObject({ busy: false, edit: before, saved: savedBefore });

            if (response.name === 'server') {
                await page.route(isUpdate, route => route.fulfill({
                    status: 500, contentType: 'application/json', body: '{}',
                }), { times: 1 });
            } else if (response.name === 'transport') {
                await page.route(isUpdate, route => route.abort('failed'), { times: 1 });
            }

            const terminal = page.waitForEvent(response.name === 'transport' ? 'requestfailed' : 'response', {
                predicate: event => isUpdate(new URL(event.url())),
            });
            // The fixture owns the RPC and server value. The published Alpine factory handles the request.
            await cell.evaluate((element, value) => { window.Alpine.$data(element).state = value; }, after);
            const result = await terminal;
            if (response.status !== undefined) {
                expect(result.status()).toBe(response.status);
            }

            await expect.poll(readColumn).toEqual({
                busy: false,
                edit: response.name === 'validation' ? before : after,
                message: response.message,
                saved: response.name === 'success' ? savedAfter : savedBefore,
            });
        });
    }
}
