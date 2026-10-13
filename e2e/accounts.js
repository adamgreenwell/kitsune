// @ts-check

/*
 * Accounts the browser suite creates through the product rather than the seeder — ADR-026, as amended by ADR-039.
 *
 * ⚠️ A THROWAWAY CREDENTIAL FOR A THROWAWAY DATABASE. `e2e/global-setup.js` pipes it to
 * `kitsune:blueprint apply --owner-password-stdin`, so it has to pass the first owner's rules (fifteen characters at
 * least), and it is never an argument or an environment variable, as the command requires of any real one.
 */
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
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

/*
 * The file a reader downloads — ADR-041, the reader's download route (decisions 1–4, Adam, 2026-10-11): a private PDF
 * kept to Golfdom alone, offered for `club.rules-2026`, which reader 1 holds there from `e2e.order:2`. Reader 2 holds
 * nothing of it, and Rival's readers are another org's. A title in French, so the download's name is sent as
 * `filename*` and arrives as typed, not as its ASCII slug.
 */
const DOWNLOAD = {
    title: 'Règles du parcours 2026',
    filename: 'Règles du parcours 2026.pdf',
    entitlement: 'club.rules-2026',
    source: 'e2e.order:2',
};

/**
 * The PDF's bytes, the same on every call: a spec hashes them to know what a download must be, without asking the server
 * that served it. Longer than two of `MediaStream`'s 256 KiB chunks, so a download that arrives whole went round its loop
 * three times.
 */
function downloadBytes() {
    const line = '% Kitsune e2e: a reader\'s download, longer than one 256 KiB chunk.\n';

    return Buffer.from(`%PDF-1.4\n${line.repeat(9000)}1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n`, 'latin1');
}

/**
 * Golfdom's offered PDF and reader 1's grant of its name — stored once, offered by `MediaVisibility::offer()` as Golfdom's
 * owner (the audited door an editor's *Offer to readers* uses), and granted with nobody signed in, as `seedReaderGrant()`
 * grants. Idempotent: a file already there is offered again (unchanged), and a grant already live is unchanged — so it is
 * also the reset a spec runs after `entitlements.spec.js` has erased reader 1's rows, or after its own owner stopped the
 * offer.
 *
 * ⚠️ ON THE GLOBAL `image` TYPE, AS `media-tiles.spec.js` STORES ITS PDF: Golfdom Media has no other media type, and
 * one more would put a link in every sidebar a dozen specs read. The type's list of formats (ADR-042 decision 33) is set
 * aside below the model for the one store and put back whatever happens.
 *
 * ⚠️ MADE OLD, BELOW THE MODEL, as `media-tiles.spec.js` makes its tile states: the image list sorts by when each entry
 * changed, ten a page, and specs that look for `Course map` or `Golfdom logo` on its unsearched first page must not find
 * this file in their place. An offer writes `media_files` alone, so it stays old.
 *
 * ⚠️ REFUSED UNLESS IT IS AS THE SPEC ASSUMES — private, kept to Golfdom, offered for the name, granted — so a wrong
 * fixture is a setup failure rather than a download test that proves nothing.
 *
 * @returns {{ id: string, sha256: string, size: number }}
 */
function seedReaderDownload() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'kitsune-download-e2e-'));
    const source = path.join(dir, 'rules.pdf');
    fs.writeFileSync(source, downloadBytes());

    try {
        const printed = execFileSync('php', ['artisan', 'tinker', '--execute', [
            '$c = app(Kitsune\\Core\\Tenancy\\Context::class);',
            "$org = Kitsune\\Core\\Models\\Org::query()->where('slug', 'golfdom-media')->firstOrFail();",
            '$c->forget()->setOrg($org);',
            "$site = Kitsune\\Core\\Models\\Site::query()->where('slug', 'golfdom')->firstOrFail();",
            '$c->setSite($site);',
            "auth()->setUser(App\\Models\\User::query()->where('email', 'alpha@kitsune.test')->firstOrFail());",
            `$id = DB::table('entries')->where('org_id', $org->id)->where('site_id', $site->id)->where('title', '${DOWNLOAD.title}')->whereNull('deleted_at')->value('id');`,
            "$stored = 'kept';",
            'if ($id === null) {',
            "$type = Kitsune\\Core\\Models\\EntryType::withoutScopeBecause('a browser-test fixture', fn ($q) => $q->where('handle', 'image')->whereNull('org_id')->firstOrFail());",
            "$settings = DB::table('entry_types')->where('id', $type->id)->value('settings');",
            "DB::table('entry_types')->where('id', $type->id)->update(['settings' => null]);",
            `try { $id = Kitsune\\Core\\Media\\MediaLibrary::store('${source.replace(/['\\]/g, '\\$&')}', 'rules.pdf', $type, 'private', '${DOWNLOAD.title}', true)->getKey(); }`,
            "finally { DB::table('entry_types')->where('id', $type->id)->update(['settings' => $settings]); }",
            "DB::table('entries')->where('id', $id)->update(['updated_at' => '2001-01-01 00:00:00']);",
            "$stored = 'stored'; }",
            `$offered = Kitsune\\Core\\Media\\MediaVisibility::offer(Kitsune\\Core\\Models\\Entry::query()->findOrFail($id), '${DOWNLOAD.entitlement}');`,
            'auth()->forgetUser();',
            `$granted = app(Kitsune\\Core\\Entitlements\\EntitlementWriter::class)->grant('${READERS.golfdom.id}', '${DOWNLOAD.entitlement}', '${DOWNLOAD.source}', null)->name;`,
            "$f = DB::table('media_files')->where('entry_id', $id)->first();",
            "echo json_encode(['id' => (string) $id, 'stored' => $stored, 'offered' => $offered, 'granted' => $granted, 'visibility' => $f->visibility,",
            "'mime' => $f->mime, 'gate' => $f->reader_entitlement, 'site' => (int) DB::table('entries')->where('id', $id)->value('site_id') === (int) $site->id,",
            "'sha256' => hash('sha256', (string) Storage::disk($f->disk)->get($f->path)), 'size' => (int) $f->size_bytes]);",
        ].join(' ')], { cwd: path.join(__dirname, '..', 'skeleton'), encoding: 'utf8' }).trim();

        const seeded = JSON.parse(printed);
        const expected = crypto.createHash('sha256').update(downloadBytes()).digest('hex');

        if (! ['stored', 'kept'].includes(seeded.stored)
            || ! ['switched', 'unchanged'].includes(seeded.offered)
            || ! ['Granted', 'Unchanged'].includes(seeded.granted)
            || seeded.visibility !== 'private' || seeded.mime !== 'application/pdf' || seeded.gate !== DOWNLOAD.entitlement
            || seeded.site !== true || seeded.sha256 !== expected || seeded.size !== downloadBytes().length) {
            throw new Error(`seedReaderDownload: Golfdom's offered PDF is not as the specs assume:\n${printed}`);
        }

        return { id: seeded.id, sha256: seeded.sha256, size: seeded.size };
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

module.exports = {
    BLOG_OWNER: { email: 'blog-owner@kitsune.test', password: 'inkwell-first-owner-e2e' },
    CROSS_ORG,
    DOWNLOAD,
    READERS,
    downloadBytes,
    plantCrossOrgRows,
    seedReaderDownload,
    seedReaderGrant,
};
