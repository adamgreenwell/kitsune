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
     * ⚠️ AND THE MEDIA FIXTURES BY ID — the rival org's file, which nobody in Golfdom can walk the admin to, and the
     * shared and site-only pair `media-sharing.spec.js` compares at a second site. Joined through `media_files` so each
     * id is a media entry with bytes behind it, not merely a row carrying the title.
     *
     * ⚠️ AND REFUSED UNLESS THEY ARE STORED AS THE SPECS ASSUME (ADR-042 decision 2). A spec asserting that the shared
     * photo is listed at `golfdom-fr` passes just as well against a seeder that kept it to one site and a list that
     * admits everything; the storage is checked here, once, where a wrong fixture is a setup failure rather than a
     * test that proves the wrong thing.
     */
    const media = JSON.parse(execFileSync('php', [
        'artisan', 'tinker', '--execute',
        "echo json_encode(DB::table('media_files')->join('entries', 'entries.id', '=', 'media_files.entry_id')"
            + "->whereIn('entries.title', ['Rival private asset', 'Shared course photo', 'Course map'])"
            + "->get(['entries.id', 'entries.title', 'entries.site_id', 'entries.slug'])->keyBy('title'));",
    ], { cwd: skeleton, encoding: 'utf8' }).trim());

    const rival = media['Rival private asset'];
    const shared = media['Shared course photo'];
    const siteOnly = media['Course map'];

    if (! rival || rival.site_id !== null) {
        throw new Error('global-setup: the rival org\'s media file is missing, or was not stored shared');
    }

    if (! shared || shared.site_id !== null || shared.slug !== null) {
        throw new Error('global-setup: "Shared course photo" is missing, or was not stored shared with no slug');
    }

    if (! siteOnly || siteOnly.site_id === null || siteOnly.slug === null) {
        throw new Error('global-setup: "Course map" is missing, or was not kept to one site with a slug');
    }

    const rivalFileId = String(rival.id);

    /*
     * ⚠️ AND THE HIDDEN-LINK FIXTURE, refused unless it is there: the shared photo links to an article only `golfdom`
     * sees, which `media-sharing.spec.js` saves the photo at `golfdom-fr` to keep. Without the link that spec would pass
     * against a save that detached nothing because there was nothing to detach.
     */
    const fixtures = JSON.parse(execFileSync('php', [
        'artisan', 'tinker', '--execute',
        "echo json_encode(['notes' => DB::table('entries')->whereIn('slug', ['note-fr', 'nested-note'])->pluck('id', 'slug'),"
            + " 'week4' => DB::table('entries')->where('slug', 'course-maintenance-week-4')->value('id'),"
            + ` 'links' => DB::table('entry_relations')->where('source_entry_id', ${Number(shared.id)})->pluck('target_entry_id')]);`,
    ], { cwd: skeleton, encoding: 'utf8' }).trim());

    if (! fixtures.notes['note-fr'] || ! fixtures.notes['nested-note']) {
        throw new Error('global-setup: the articles at golfdom-fr and golfdom-nested were not seeded');
    }

    if (! fixtures.links.map(Number).includes(Number(fixtures.week4))) {
        throw new Error('global-setup: the shared photo does not link to the golfdom-only article');
    }

    /*
     * ⚠️ AND WHERE THE SERVER STAGES UPLOADS, asked of the application rather than guessed: `media-staging.spec.js`
     * lists it before and after each request. Refused unless Livewire stages on core's intake disk (ADR-042 decision
     * 4), which is the configuration that spec exists to exercise.
     */
    const staging = JSON.parse(execFileSync('php', [
        'artisan', 'tinker', '--execute',
        "echo json_encode(['disk' => config('livewire.temporary_file_upload.disk'), 'path' => Storage::disk('kitsune-intake')->path('')]);",
    ], { cwd: skeleton, encoding: 'utf8' }).trim());

    if (staging.disk !== 'kitsune-intake') {
        throw new Error(`global-setup: Livewire stages on [${staging.disk}], not on core's intake disk`);
    }

    fs.mkdirSync(path.join(__dirname, '..', '.playwright'), { recursive: true });
    fs.writeFileSync(
        path.join(__dirname, '..', '.playwright', 'media-fixture.json'),
        JSON.stringify({
            publicPath: seeded,
            rivalFileId,
            sharedPhotoId: String(shared.id),
            courseMapId: String(siteOnly.id),
            hiddenTargetId: String(fixtures.week4),
            frNoteId: String(fixtures.notes['note-fr']),
            nestedNoteId: String(fixtures.notes['nested-note']),
            intakePath: staging.path,
        }, null, 4) + '\n',
    );
};
