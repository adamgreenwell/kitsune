<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Privacy\ErasureJournalCipher;
use Kitsune\Core\Schema\RevisionErasure;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** @param array<string,mixed> $options @return array{int,string} */
function revisionErasureRun(string $action, array $options, string $input = ''): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $input."\n");
    rewind($stream);
    $arguments = new ArrayInput(['command' => 'kitsune:revision-erasure', 'action' => $action, ...$options]);
    $arguments->setStream($stream);
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->handle($arguments, $output);
    fclose($stream);

    return [$exit, trim($output->fetch())];
}

it('replays only the witnessed revision in a physical SQLite backup', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
        'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'array',
    ]);
    $org = Org::create(['slug' => 'clinic', 'name' => 'Clinic']);
    $context = app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'clinic-main', 'name' => 'Main']);
    Site::create(['handle' => 'sibling', 'slug' => 'clinic-sibling', 'name' => 'Sibling']);
    $context->setSite($site);
    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'patient', 'name' => 'Patient', 'plural_name' => 'Patients']);
    $email = FieldStorage::create(['org_id' => $org->id, 'handle' => 'email', 'type' => 'text', 'pii_class' => 'personal', 'cardinality' => 1]);
    $subject = Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $email->id, 'label' => 'Email']);
    $notes = FieldStorage::create(['org_id' => $org->id, 'handle' => 'notes', 'type' => 'text', 'pii_class' => 'sensitive', 'cardinality' => 1]);
    Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $notes->id, 'label' => 'Notes']);
    $type->update(['subject_field_id' => $subject->id]);

    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Visit', 'values' => ['email' => 'person-a@example.test', 'notes' => 'private notes A']]);
    $earlier = $entry->revisions()->latest('id')->firstOrFail();
    $entry->update(['values' => ['email' => 'person-b@example.test', 'notes' => 'private notes B']]);
    $later = $entry->revisions()->latest('id')->firstOrFail();
    $backup = (string) tempnam(sys_get_temp_dir(), 'kitsune-revision-restore-');
    unlink($backup);

    try {
        DB::connection()->statement('VACUUM INTO ?', [$backup]);
        $eraser = app(RevisionErasure::class);
        expect(fn () => $eraser->erase($entry->id, $later->id, $email->id, 'person-a@example.test'))
            ->toThrow(RuntimeException::class, 'cannot be attributed');
        $armed = true;
        EntryRevision::saved(static function (EntryRevision $revision) use (&$armed, $earlier): void {
            if ($armed && $revision->id === $earlier->id && ($revision->values['notes'] ?? null) === null) {
                DB::table('entry_revisions')->where('id', $earlier->id)->update([
                    'values' => json_encode(['email' => 'person-a@example.test', 'notes' => null], JSON_THROW_ON_ERROR),
                ]);
            }
        });
        expect(fn () => $eraser->erase($entry->id, $earlier->id, $email->id, 'person-a@example.test'))
            ->toThrow(RuntimeException::class, 'retained data')
            ->and(DB::table('erasure_log')->count())->toBe(0)
            ->and($earlier->fresh()->values['notes'])->toBe('private notes A');
        $armed = false;

        $scope = ['--org' => 'clinic', '--site' => 'main'];
        [$exit, $output] = revisionErasureRun('redact', [
            '--force' => true, '--entry' => (string) $entry->id, '--revision' => (string) $earlier->id,
            '--storage' => (string) $email->id, ...$scope,
        ], '"person-a@example.test"');
        expect($exit)->toBe(0, $output)->and($output)->toContain('Revision fields erased: 2.');
        $receipts = DB::table('erasure_log')->get([
            'org_id', 'site_id', 'entry_id', 'entry_type_id', 'entry_revision_id', 'field_storage_id',
            'revision_witness', 'field_handle', 'representation', 'record_target',
        ])->map(static fn (object $receipt): array => (array) $receipt)->all();
        expect($receipts)->toHaveCount(2)
            ->and(json_encode($receipts, JSON_THROW_ON_ERROR))->not->toContain('person-a@example.test', 'private notes A')
            ->and(DB::table('erasure_log')->where('entry_revision_id', $earlier->id)->count())->toBe(2)
            ->and($earlier->fresh()->values)->toBe(['email' => null, 'notes' => null])
            ->and($later->fresh()->values['notes'])->toBe('private notes B');

        [$exit, $journal] = revisionErasureRun('export-journal', $scope);
        expect($exit)->toBe(0, $journal)->and($journal)->not->toContain('person-a@example.test', 'private notes A');
        $context->setSite($site);

        config(['database.connections.replay_fixture' => [
            'driver' => 'sqlite', 'database' => $backup, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('replay_fixture');
        expect(DB::table('erasure_log')->count())->toBe(0)
            ->and(EntryRevision::query()->findOrFail($earlier->id)->values['notes'])->toBe('private notes A');

        $wrong = ['entry_revision_id' => $later->id] + $receipts[0];
        expect($eraser->replay([$wrong]))->toBe(['replayed' => 0, 'already_erased' => 0, 'unavailable' => 1]);
        $replay = ['--force' => true, ...$scope];
        [$exit, $message] = revisionErasureRun('replay', $replay, $journal);
        expect($exit)->toBe(1)->and($message)->toContain('maintenance mode');
        app()->maintenanceMode()->activate([]);
        [$exit, $message] = revisionErasureRun('replay', $replay, 'INVALID'.$journal);
        expect($exit)->toBe(1)->and($message)->toContain('could not be authenticated');

        $cipher = app(ErasureJournalCipher::class);
        $plain = $cipher->unseal($journal);
        $originalKey = config('app.key');
        $previousKeys = config('app.previous_keys');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'app.previous_keys' => []]);
        $foreignJournal = $cipher->seal($plain);
        expect(fn () => $cipher->unseal($journal))->toThrow(DecryptException::class);
        config(['app.previous_keys' => [$originalKey]]);
        expect($cipher->unseal($journal))->toBe($plain);
        config(['app.key' => $originalKey, 'app.previous_keys' => $previousKeys]);

        $applicationKey = str_repeat('j', 32);
        $applicationCipher = new Encrypter($applicationKey, 'AES-256-GCM');
        $credentialCipher = new Encrypter(hash_hkdf('sha256', $applicationKey, 32, 'kitsune/credentials/v1'), 'AES-256-GCM');
        expect(fn () => $applicationCipher->decryptString($journal))->toThrow(DecryptException::class)
            ->and(fn () => $credentialCipher->decryptString($journal))->toThrow(DecryptException::class);
        [$exit, $message] = revisionErasureRun('replay', $replay, $foreignJournal);
        expect($exit)->toBe(1)->and($message)->toContain('could not be authenticated');
        [$exit, $message] = revisionErasureRun('replay', ['--site' => 'sibling'] + $replay, $journal);
        expect($exit)->toBe(1)->and($message)->toContain('does not match this Org and Site')
            ->and(DB::table('erasure_log')->count())->toBe(0)
            ->and(EntryRevision::query()->findOrFail($earlier->id)->values['notes'])->toBe('private notes A');

        [$exit, $result] = revisionErasureRun('replay', $replay, $journal);
        expect($exit)->toBe(0, $result)
            ->and(json_decode($result, true, flags: JSON_THROW_ON_ERROR))->toBe(['replayed' => 2, 'already_erased' => 0, 'unavailable' => 0]);
        [$exit, $result] = revisionErasureRun('replay', $replay, $journal);
        expect($exit)->toBe(0, $result)
            ->and(json_decode($result, true, flags: JSON_THROW_ON_ERROR))->toBe(['replayed' => 0, 'already_erased' => 2, 'unavailable' => 0])
            ->and(DB::table('erasure_log')->count())->toBe(2);
        $context->setSite($site);
        expect(EntryRevision::query()->findOrFail($earlier->id)->values)->toBe(['email' => null, 'notes' => null])
            ->and(EntryRevision::query()->findOrFail($later->id)->values['notes'])->toBe('private notes B')
            ->and(Entry::query()->findOrFail($entry->id)->values['email'])->toBe('person-b@example.test');
    } finally {
        app()->maintenanceMode()->deactivate();
        DB::setDefaultConnection('custody');
        DB::purge('replay_fixture');
        if (is_file($backup)) {
            unlink($backup);
        }
    }
});

it('replays live receipts only against the exact subject and entry version in physical SQLite backups', function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('b', 32)),
        'app.maintenance.driver' => 'cache',
        'app.maintenance.store' => 'array',
    ]);
    $org = Org::create(['slug' => 'practice', 'name' => 'Practice']);
    $context = app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'practice-main', 'name' => 'Main']);
    $context->setSite($site);
    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'patient', 'name' => 'Patient', 'plural_name' => 'Patients']);
    $email = FieldStorage::create(['org_id' => $org->id, 'handle' => 'email', 'type' => 'text', 'pii_class' => 'personal', 'cardinality' => 1]);
    $subject = Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $email->id, 'label' => 'Email']);
    $notes = FieldStorage::create(['org_id' => $org->id, 'handle' => 'notes', 'type' => 'text', 'pii_class' => 'sensitive', 'cardinality' => 1]);
    Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $notes->id, 'label' => 'Notes']);
    $type->update(['subject_field_id' => $subject->id]);

    $first = ['email' => 'person-a@example.test', 'notes' => 'private notes A'];
    $second = ['email' => 'person-b@example.test', 'notes' => 'private notes B'];
    $changed = ['email' => 'person-c@example.test', 'notes' => 'private notes B'];
    $entry = Entry::create(['entry_type_id' => $type->id, 'title' => 'Visit', 'values' => $first]);
    $earlier = $entry->revisions()->latest('id')->firstOrFail();
    $backups = [];
    $snapshot = function (string $name) use (&$backups): void {
        $path = (string) tempnam(sys_get_temp_dir(), 'kitsune-live-replay-');
        unlink($path);
        $backups[$name] = $path;
        DB::connection()->statement('VACUUM INTO ?', [$path]);
    };
    $scope = ['--org' => 'practice', '--site' => 'main'];
    $redact = [
        '--force' => true, '--include-live' => true, '--entry' => (string) $entry->id,
        '--storage' => (string) $email->id, ...$scope,
    ];

    try {
        $snapshot('before');
        $entry->update(['values' => $second]);
        $later = $entry->revisions()->latest('id')->firstOrFail();
        $snapshot('matching');

        $entry->withoutRevisions(fn (Entry $model) => $model->update(['values' => $changed]));
        expect($entry->revisions()->latest('id')->value('id'))->toBe($later->id);
        $snapshot('changed');
        [$exit, $message] = revisionErasureRun('redact', ['--revision' => (string) $later->id] + $redact, json_encode($second['email'], JSON_THROW_ON_ERROR));
        expect($exit)->toBe(1)->and($message)->toContain('does not match the witnessed revision')
            ->and(DB::table('erasure_log')->count())->toBe(0);

        $context->setSite($site);
        $entry->withoutRevisions(fn (Entry $model) => $model->update(['values' => $second]));
        [$exit, $message] = revisionErasureRun('redact', ['--revision' => (string) $earlier->id] + $redact, json_encode($first['email'], JSON_THROW_ON_ERROR));
        expect($exit)->toBe(1)->and($message)->toContain('does not match the witnessed revision')
            ->and(DB::table('erasure_log')->count())->toBe(0);
        [$exit, $message] = revisionErasureRun('redact', ['--revision' => (string) $later->id] + $redact, json_encode($second['email'], JSON_THROW_ON_ERROR));
        expect($exit)->toBe(0, $message)->and($message)->toContain('Entry and revision fields erased: 4.');

        $blank = ['email' => null, 'notes' => null];
        $context->setSite($site);
        expect($entry->fresh()->values)->toBe($blank)
            ->and($earlier->fresh()->values)->toBe($first)
            ->and($later->fresh()->values)->toBe($blank)
            ->and($entry->revisions()->latest('id')->value('id'))->toBe($later->id)
            ->and(DB::table('erasure_log')->where('record_target', 'entry')->count())->toBe(2)
            ->and(DB::table('erasure_log')->where('record_target', 'revision')->count())->toBe(2);

        [$exit, $journal] = revisionErasureRun('export-journal', $scope);
        expect($exit)->toBe(0, $journal);
        app()->maintenanceMode()->activate([]);
        $armed = false;
        EntryRevision::saved(static function (EntryRevision $revision) use (&$armed, $later): void {
            if ($armed && $revision->id === $later->id && ($revision->values['notes'] ?? null) === null) {
                DB::table('entry_revisions')->where('id', $later->id)->update([
                    'values' => json_encode(['email' => null, 'notes' => 'private notes B'], JSON_THROW_ON_ERROR),
                ]);
            }
        });
        foreach ($backups as $name => $path) {
            DB::purge('live_restore');
            config(['database.connections.live_restore' => [
                'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
            DB::setDefaultConnection('live_restore');

            if ($name === 'matching') {
                $armed = true;
                [$exit, $message] = revisionErasureRun('replay', ['--force' => true, ...$scope], $journal);
                $armed = false;
                $context->setSite($site);
                expect($exit)->toBe(1)->and($message)->toContain('retained data')
                    ->and(DB::table('erasure_log')->count())->toBe(0)
                    ->and(Entry::query()->findOrFail($entry->id)->values)->toBe($second)
                    ->and(EntryRevision::query()->findOrFail($later->id)->values)->toBe($second);
            }

            [$exit, $message] = revisionErasureRun('replay', ['--force' => true, ...$scope], $journal);
            $context->setSite($site);
            $restored = Entry::query()->findOrFail($entry->id);
            expect($restored->revisions()->findOrFail($earlier->id)->values)->toBe($first);
            if ($name === 'matching') {
                expect($exit)->toBe(0, $message)
                    ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe(['replayed' => 4, 'already_erased' => 0, 'unavailable' => 0])
                    ->and($restored->values)->toBe($blank)
                    ->and($restored->revisions()->findOrFail($later->id)->values)->toBe($blank);
                [$exit, $message] = revisionErasureRun('replay', ['--force' => true, ...$scope], $journal);
                expect($exit)->toBe(0, $message)
                    ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe(['replayed' => 0, 'already_erased' => 4, 'unavailable' => 0])
                    ->and(DB::table('erasure_log')->count())->toBe(4);
            } else {
                expect($exit)->toBe(1, $message)->and($restored->values)->toBe($name === 'before' ? $first : $changed);
                if ($name === 'before') {
                    expect(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe(['replayed' => 0, 'already_erased' => 0, 'unavailable' => 4])
                        ->and($restored->revisions()->find($later->id))->toBeNull()
                        ->and(DB::table('erasure_log')->count())->toBe(0);
                } else {
                    expect(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe(['replayed' => 2, 'already_erased' => 0, 'unavailable' => 2])
                        ->and($restored->revisions()->findOrFail($later->id)->values)->toBe($blank)
                        ->and(DB::table('erasure_log')->where('record_target', 'revision')->count())->toBe(2);
                    [$exit, $message] = revisionErasureRun('replay', ['--force' => true, ...$scope], $journal);
                    expect($exit)->toBe(1, $message)
                        ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe(['replayed' => 0, 'already_erased' => 2, 'unavailable' => 2])
                        ->and(DB::table('erasure_log')->count())->toBe(2);
                }
            }
        }
    } finally {
        app()->maintenanceMode()->deactivate();
        DB::setDefaultConnection('custody');
        DB::purge('live_restore');
        foreach ($backups as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});

it('erases all witnessed content for one subject in the selected Site and reports unresolved records', function (): void {
    $org = Org::create(['slug' => 'batch-clinic', 'name' => 'Batch clinic']);
    $context = app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'main', 'slug' => 'batch-main', 'name' => 'Main']);
    $sibling = Site::create(['handle' => 'other', 'slug' => 'batch-other', 'name' => 'Other']);
    $context->setSite($site);
    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'patient', 'name' => 'Patient', 'plural_name' => 'Patients']);
    $email = FieldStorage::create(['org_id' => $org->id, 'handle' => 'email', 'type' => 'text', 'pii_class' => 'personal', 'cardinality' => 1]);
    $subject = Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $email->id, 'label' => 'Email']);
    $notes = FieldStorage::create(['org_id' => $org->id, 'handle' => 'notes', 'type' => 'text', 'pii_class' => 'sensitive', 'cardinality' => 1]);
    Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $notes->id, 'label' => 'Notes']);
    $type->update(['subject_field_id' => $subject->id]);

    $first = ['email' => 'one@example.test', 'notes' => 'first private value'];
    $newer = ['email' => 'one@example.test', 'notes' => 'newer private value'];
    $other = ['email' => 'two@example.test', 'notes' => 'other private value'];
    $blank = ['email' => null, 'notes' => null];
    $current = Entry::create(['entry_type_id' => $type->id, 'title' => 'Current', 'values' => $first]);
    $older = $current->revisions()->latest('id')->firstOrFail();
    $current->update(['values' => $newer]);
    $latest = $current->revisions()->latest('id')->firstOrFail();
    $changed = Entry::create(['entry_type_id' => $type->id, 'title' => 'Changed', 'values' => $first]);
    $prior = $changed->revisions()->latest('id')->firstOrFail();
    $changed->update(['values' => $other]);
    $replacement = $changed->revisions()->latest('id')->firstOrFail();

    $context->setSite($sibling);
    $outside = Entry::create(['entry_type_id' => $type->id, 'title' => 'Outside', 'values' => $first]);
    $outsideRevision = $outside->revisions()->latest('id')->firstOrFail();

    $options = ['--org' => 'batch-clinic', '--site' => 'main', '--type' => (string) $type->id, '--storage' => (string) $email->id];
    $identifier = json_encode($first['email'], JSON_THROW_ON_ERROR);
    [$exit, $message] = revisionErasureRun('redact-subject', $options, $identifier);
    expect($exit)->not->toBe(0)->and($message)->toContain('require --force');
    expect(DB::table('erasure_log')->count())->toBe(0);
    [$exit, $message] = revisionErasureRun('redact-subject', ['--force' => true] + $options, $identifier);
    expect($exit)->toBe(0, $message)
        ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'entry_fields_erased' => 2, 'revision_fields_erased' => 6, 'live_identifier_covered' => true,
            'unavailable_live_entries' => 0, 'indeterminate_revisions' => 0, 'refused_revisions' => 0,
        ])
        ->and(DB::table('erasure_log')->where('site_id', $site->id)->count())->toBe(8)
        ->and(DB::table('erasure_log')->where('site_id', $sibling->id)->count())->toBe(0);
    $context->setOrg($org)->setSite($site);
    expect($current->fresh()->values)->toBe($blank)
        ->and($older->fresh()->values)->toBe($blank)
        ->and($latest->fresh()->values)->toBe($blank)
        ->and($changed->fresh()->values)->toBe($other)
        ->and($replacement->fresh()->values)->toBe($other)
        ->and($prior->fresh()->values)->toBe($blank);

    $unproved = Entry::create(['entry_type_id' => $type->id, 'title' => 'Unproved', 'values' => $other]);
    $unproved->withoutRevisions(fn (Entry $model) => $model->update(['values' => $first]));

    $drifted = Entry::create(['entry_type_id' => $type->id, 'title' => 'Drifted', 'values' => $first]);
    $driftedRevision = $drifted->revisions()->latest('id')->firstOrFail();
    $drifted->withoutRevisions(fn (Entry $model) => $model->update(['values' => $newer]));
    [$exit, $message] = revisionErasureRun('redact-subject', ['--force' => true] + $options, $identifier);
    expect($exit)->toBe(1, $message)
        ->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'entry_fields_erased' => 0, 'revision_fields_erased' => 2, 'live_identifier_covered' => true,
            'unavailable_live_entries' => 2, 'indeterminate_revisions' => 3, 'refused_revisions' => 0,
        ])
        ->and(DB::table('erasure_log')->count())->toBe(10);
    $context->setOrg($org)->setSite($site);
    expect($unproved->fresh()->values)->toBe($first)
        ->and($drifted->fresh()->values)->toBe($newer)
        ->and($driftedRevision->fresh()->values)->toBe($blank);
    $context->setSite($sibling);
    expect($outside->fresh()->values)->toBe($first)
        ->and($outsideRevision->fresh()->values)->toBe($first);
});

it('erases Site-owned and shared content across the Org in one pass', function (): void {
    $org = Org::create(['slug' => 'all-clinic', 'name' => 'All clinic']);
    $context = app(Context::class)->setOrg($org);
    $main = Site::create(['handle' => 'main', 'slug' => 'all-main', 'name' => 'Main']);
    $other = Site::create(['handle' => 'other', 'slug' => 'all-other', 'name' => 'Other']);
    $context->setSite($main);
    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'visit', 'name' => 'Visit', 'plural_name' => 'Visits']);
    $email = FieldStorage::create(['org_id' => $org->id, 'handle' => 'email', 'type' => 'text', 'pii_class' => 'personal', 'cardinality' => 1]);
    $field = Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $email->id, 'label' => 'Email']);
    $type->update(['subject_field_id' => $field->id]);

    $data = ['entry_type_id' => $type->id, 'title' => 'Visit', 'values' => ['email' => 'same@example.test']];
    $first = Entry::create($data);
    $shared = Entry::create(['site_id' => null] + $data);
    $context->setSite($other);
    $second = Entry::create($data);
    expect($shared->site_id)->toBeNull();

    $options = ['--org' => 'all-clinic', '--all-sites' => true, '--type' => (string) $type->id, '--storage' => (string) $email->id, '--force' => true];
    [$exit, $message] = revisionErasureRun('redact-subject', ['--site' => 'main'] + $options, '"same@example.test"');
    expect($exit)->not->toBe(0)->and(DB::table('erasure_log')->count())->toBe(0);
    [$exit, $message] = revisionErasureRun('redact-subject', $options, '"same@example.test"');
    $complete = ['live_identifier_covered' => true, 'unavailable_live_entries' => 0, 'indeterminate_revisions' => 0, 'refused_revisions' => 0];
    expect($exit)->toBe(0, $message)->and(json_decode($message, true, flags: JSON_THROW_ON_ERROR))->toBe(['sites' => [
        'main' => ['entry_fields_erased' => 2, 'revision_fields_erased' => 2] + $complete,
        'other' => ['entry_fields_erased' => 1, 'revision_fields_erased' => 1] + $complete,
    ]])->and(DB::table('erasure_log')->count())->toBe(6)
        ->and(DB::table('erasure_log')->where('entry_id', $shared->id)->where('site_id', $main->id)->count())->toBe(2)
        ->and(DB::table('erasure_log')->where('entry_id', $shared->id)->where('site_id', $other->id)->count())->toBe(0);

    $context->setOrg($org)->setSite($main);
    expect($first->fresh()->values)->toBe(['email' => null])->and($shared->fresh()->values)->toBe(['email' => null]);
    $context->setSite($other);
    expect($second->fresh()->values)->toBe(['email' => null]);
});
