// @ts-check
const fs = require('node:fs');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { test, expect } = require('@playwright/test');

function staffFiles() {
    return JSON.parse(fs.readFileSync(path.join(__dirname, '..', '.playwright', 'media-fixture.json'), 'utf8'));
}

test('staff GET and HEAD share checked headers through the real panel route outside content pages', async ({ page }) => {
    const dashboard = await page.goto('/admin/golfdom');
    expect(dashboard.status()).toBe(200);
    await expect(page.locator('.fi-main')).toBeVisible();
    const { courseMapId } = staffFiles();
    const url = `/admin/golfdom/media/${courseMapId}`;
    const first = await page.request.get(url);
    const head = await page.request.head(url);
    const repeated = await page.request.get(url);
    expect(first.status()).toBe(200);
    expect(head.status()).toBe(200);
    expect(repeated.status()).toBe(200);
    const bytes = await first.body();
    expect(bytes.length).toBe(Number(first.headers()['content-length']));
    expect(createHash('sha256').update(await repeated.body()).digest('hex')).toBe(createHash('sha256').update(bytes).digest('hex'));
    expect((await head.body()).length).toBe(0);
    for (const key of ['content-type', 'content-length', 'content-disposition', 'x-content-type-options', 'content-security-policy', 'cache-control']) {
        expect(head.headers()[key]).toBe(first.headers()[key]);
    }
    expect(first.headers()['content-type']).toBe('image/png');
    expect(first.headers()['content-disposition']).toContain("filename*=utf-8''Course%20map.png");
    expect(first.headers()['cache-control']).toContain('no-store');
    expect(first.headers()['x-content-type-options']).toBe('nosniff');
    expect(first.headers()['content-security-policy']).toContain("default-src 'none'");
});

test('staff streaming keeps Site-only bytes private while admitting Org-shared bytes', async ({ page }) => {
    const { courseMapId, sharedPhotoId } = staffFiles();
    const refused = await page.request.get(`/admin/golfdom-fr/media/${courseMapId}`);
    expect(refused.status()).toBe(404);
    const shared = await page.request.get(`/admin/golfdom-fr/media/${sharedPhotoId}`);
    expect(shared.status()).toBe(200);
    expect((await shared.body()).length).toBe(Number(shared.headers()['content-length']));
});

test('staff streaming cannot be reached by an anonymous session', async ({ browser }) => {
    const { courseMapId } = staffFiles();
    const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
        const response = await anonymous.request.get(`/admin/golfdom/media/${courseMapId}`, { maxRedirects: 0 });
        expect(response.status()).toBe(302);
        expect(response.headers()['location']).toContain('/admin/login');
    } finally {
        await anonymous.close();
    }
});
