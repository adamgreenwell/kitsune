// @ts-check
const { execFileSync } = require('node:child_process');
const path = require('node:path');

/*
 * Rebuilds the skeleton's database before the browser suite runs.
 *
 * The admin specs assert on isolation between two seeded orgs, so they need
 * a known dataset. Rebuilding rather than reusing keeps the suite honest:
 * a test that passes only against a database someone left lying around is
 * not a test.
 */
module.exports = async () => {
    const skeleton = path.join(__dirname, '..', 'skeleton');
    const run = (args) => execFileSync('php', ['artisan', ...args], { cwd: skeleton, stdio: 'inherit' });

    run(['migrate:fresh', '--seed', '--no-interaction']);
    run(['filament:assets']);
};
