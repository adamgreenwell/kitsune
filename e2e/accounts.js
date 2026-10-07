// @ts-check

/*
 * Accounts the browser suite creates through the product rather than the seeder — ADR-026, as amended by ADR-039.
 *
 * ⚠️ A THROWAWAY CREDENTIAL FOR A THROWAWAY DATABASE. `e2e/global-setup.js` pipes it to
 * `kitsune:blueprint apply --owner-password-stdin`, so it has to pass the first owner's rules (fifteen characters at
 * least), and it is never an argument or an environment variable, as the command requires of any real one.
 */
const path = require('node:path');
const { execFileSync } = require('node:child_process');

/*
 * The seeded readers (ADR-037) — the skeleton's own `App\Models\Reader`, made by `DatabaseSeeder` on the fresh table
 * every run starts with, so their ids are fixed. Golfdom Media's are 1 and 2, Rival's 3 and 4, and reader 3 has reader
 * 1's address on purpose: one address in two orgs is two readers with two passwords — each org's its own.
 */
const READERS = {
    golfdom: { id: '1', email: 'subscriber@kitsune.test', password: 'correct-horse-battery-staple' },
    golfdom2: { id: '2', email: 'subscriber2@kitsune.test', password: 'correct-horse-battery-staple' },
    rival: { id: '3', email: 'subscriber@kitsune.test', password: 'rival-horse-battery-staple' },
    rival2: { id: '4', email: 'rival-subscriber@kitsune.test', password: 'rival-horse-battery-staple' },
};

/**
 * One producer's grant on Golfdom that no browser made: reader 1 holds `course.advanced-php` from `e2e.order:1`, granted
 * with nobody signed in — the system, as a verified payment will be.
 *
 * ⚠️ IT PRINTS THE OUTCOME, NEVER AN IDENTIFIER, and anything but a grant stops the caller: a guard that did not take
 * effect is found before any spec runs. Idempotent, so it is also the reset `entitlements.spec.js` runs after erasing.
 */
function seedReaderGrant() {
    const printed = execFileSync('php', ['artisan', 'tinker', '--execute', [
        '$c = app(Kitsune\\Core\\Tenancy\\Context::class);',
        "$c->forget()->setOrg(Kitsune\\Core\\Models\\Org::query()->where('slug', 'golfdom-media')->firstOrFail());",
        "$c->setSite(Kitsune\\Core\\Models\\Site::query()->where('slug', 'golfdom')->firstOrFail());",
        `echo 'e2e.order:1 '.app(Kitsune\\Core\\Entitlements\\EntitlementWriter::class)->grant('${READERS.golfdom.id}', 'course.advanced-php', 'e2e.order:1', null)->name;`,
    ].join(' ')], { cwd: path.join(__dirname, '..', 'skeleton'), encoding: 'utf8' }).trim();

    if (! ['e2e.order:1 Granted', 'e2e.order:1 Unchanged'].includes(printed)) {
        throw new Error(`seedReaderGrant: the producer's grant was not made:\n${printed}`);
    }

    return printed;
}

module.exports = {
    BLOG_OWNER: { email: 'blog-owner@kitsune.test', password: 'inkwell-first-owner-e2e' },
    READERS,
    seedReaderGrant,
};
