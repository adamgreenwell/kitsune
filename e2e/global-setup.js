// @ts-check
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
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

    /*
     * The person module, installed and enabled explicitly — ADR-038. `migrate:fresh` drops the `modules`
     * table, so the receipt has to be written again on every run: requiring the package puts its CODE in
     * vendor, and that is deliberately not the same as running it.
     */
    run(['kitsune:module', 'install', 'kitsune/person', '--no-interaction']);
    run(['kitsune:module', 'enable', 'kitsune/person', '--no-interaction']);

    run(['filament:assets']);

    /*
     * ⚠️ THE PUBLIC MEDIA PATH, WRITTEN OUT SO A BROWSER CAN FETCH IT — ADR-041, and review found why it has
     * to be fetched rather than computed. `MediaIntake::storedName()` generates the filename from
     * `random_bytes`, on purpose, so a spec cannot know it; and a PHP assertion that
     * `MediaDelivery::urlFor()` equals `Storage::disk('public')->url(...)` calls the same method on both
     * sides and cannot fail. It passed happily while a bare install served 403 at that URL, because nothing
     * created `public/storage`.
     *
     * So the seeded path is emitted here and `media-delivery.spec.js` requests it over HTTP. That asserts the
     * whole chain a public file depends on: the bytes reached the public disk, `storage:link` ran during
     * installation, and the web server serves the result without PHP in the path.
     */
    const seeded = execFileSync('php', [
        'artisan', 'tinker', '--execute',
        "echo optional(DB::table('media_files')->where('visibility','public')->first())->path;",
    ], { cwd: skeleton, encoding: 'utf8' }).trim();

    if (! seeded) {
        throw new Error('global-setup: the seeder produced no public media file for media-delivery.spec.js');
    }

    /*
     * ⚠️ AND THE RIVAL ORG'S FILE, BY ID, because nobody in Golfdom can walk the admin to it — that is the
     * point of it. Joined through `media_files` so the id is a media entry with bytes behind it, not merely a
     * row carrying the title.
     */
    const rivalFileId = execFileSync('php', [
        'artisan', 'tinker', '--execute',
        "echo DB::table('media_files')->join('entries', 'entries.id', '=', 'media_files.entry_id')"
            + "->where('entries.title', 'Rival private asset')->value('entries.id');",
    ], { cwd: skeleton, encoding: 'utf8' }).trim();

    if (! /^\d+$/.test(rivalFileId)) {
        throw new Error('global-setup: the seeder produced no rival media file for media-delivery.spec.js');
    }

    fs.mkdirSync(path.join(__dirname, '..', '.playwright'), { recursive: true });
    fs.writeFileSync(
        path.join(__dirname, '..', '.playwright', 'media-fixture.json'),
        JSON.stringify({ publicPath: seeded, rivalFileId }, null, 4) + '\n',
    );
};
