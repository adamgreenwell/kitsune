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

/** The rows `plantCrossOrgRows()` plants: Rival's reader 4 on Golfdom's site, and on Rival's own. */
const CROSS_ORG = {
    golfdom: { entitlement: 'e2e.cross-org', source: 'e2e.plant:2' },
    rival: { entitlement: 'e2e.rival-hidden', source: 'e2e.plant:3' },
};

/**
 * Rival's reader 4 (`rival-subscriber@…`) holding a row on Golfdom's site, and the same reader on Rival's own site —
 * the attacker's side of readers named on the owner's page (ADR-037 decisions 1–3, AGENTS.md §9). Golfdom Media shows
 * addresses and Rival does not, so one row asks Golfdom's page to name another org's reader, and the other is the
 * reader's own org keeping them hidden.
 *
 * ⚠️ BELOW ELOQUENT, ON PURPOSE: the writer refuses another org's reader (`entitlements.spec.js` shows it does), so a
 * row like the first exists only where something wrote past it — which is what the page must still name nobody for.
 * `insertOrIgnore` on the table's unique key, so it is idempotent; and refused unless reader 4 is Rival's, with the
 * address the specs look for, and Golfdom is another org — otherwise the specs would prove nothing.
 */
function plantCrossOrgRows() {
    const printed = execFileSync('php', ['artisan', 'tinker', '--execute', [
        "$golfdom = DB::table('sites')->where('slug', 'golfdom')->first();",
        "$rival = DB::table('sites')->where('slug', 'rival-golfdom')->first();",
        `$reader = DB::table('readers')->where('id', ${READERS.rival2.id})->first();`,
        "$rivalOrg = DB::table('orgs')->where('slug', 'rival')->value('id');",
        `if ($reader === null || $golfdom === null || $rival === null || (int) $reader->org_id !== (int) $rivalOrg || (int) $rival->org_id !== (int) $rivalOrg || (int) $golfdom->org_id === (int) $rivalOrg || $reader->email !== '${READERS.rival2.email}') { echo 'not as the specs assume'; } else {`,
        "$at = now('UTC')->format('Y-m-d H:i:s');",
        `DB::table('entitlements')->insertOrIgnore(['org_id' => $golfdom->org_id, 'site_id' => $golfdom->id, 'reader_id' => '${READERS.rival2.id}', 'entitlement' => '${CROSS_ORG.golfdom.entitlement}', 'source' => '${CROSS_ORG.golfdom.source}', 'changed_at' => $at]);`,
        `DB::table('entitlements')->insertOrIgnore(['org_id' => $rival->org_id, 'site_id' => $rival->id, 'reader_id' => '${READERS.rival2.id}', 'entitlement' => '${CROSS_ORG.rival.entitlement}', 'source' => '${CROSS_ORG.rival.source}', 'changed_at' => $at]);`,
        `echo DB::table('entitlements')->where('site_id', $golfdom->id)->where('org_id', $golfdom->org_id)->where('reader_id', '${READERS.rival2.id}')->where('entitlement', '${CROSS_ORG.golfdom.entitlement}')->count()`,
        `.' '.DB::table('entitlements')->where('site_id', $rival->id)->where('org_id', $rival->org_id)->where('reader_id', '${READERS.rival2.id}')->where('entitlement', '${CROSS_ORG.rival.entitlement}')->count(); }`,
    ].join(' ')], { cwd: path.join(__dirname, '..', 'skeleton'), encoding: 'utf8' }).trim();

    if (printed !== '1 1') {
        throw new Error(`plantCrossOrgRows: Rival's reader was not planted on both sites:\n${printed}`);
    }
}

module.exports = {
    BLOG_OWNER: { email: 'blog-owner@kitsune.test', password: 'inkwell-first-owner-e2e' },
    CROSS_ORG,
    READERS,
    plantCrossOrgRows,
    seedReaderGrant,
};
