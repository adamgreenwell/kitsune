<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** @param array<string, mixed> $options @return array{int, string} */
function subjectExportRun(array $options, string $json): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $json."\n");
    rewind($stream);

    $input = new ArrayInput(['command' => 'kitsune:subject-export', ...$options]);
    $input->setStream($stream);
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->handle($input, $output);
    fclose($stream);

    return [$exit, trim($output->fetch())];
}

it('exports attributed records, reader choices and grants with explicit scope and coverage', function (): void {
    $world = ReaderFixture::world();
    $org = $world['golfdom'];
    $site = $world['sites']['golfdom'];
    ReaderFixture::mode($org, 'sign-in');
    $site->settings = ['reader_consent_profiling' => ['notice_version' => 'v1', 'retention_days' => 30]];
    $site->save();
    $reader = ReaderFixture::reader($org, 'reader@kitsune.test');
    ReaderFixture::signIn($reader);
    $this->readerPost('/golfdom/account/privacy', ['purpose' => 'profiling', 'decision' => 'grant', 'notice' => 'v1'])->assertStatus(303);
    app('session.store')->flush();
    ReaderFixture::forget();
    EntitlementFixture::plant($site, (string) $reader->getKey(), 'course.privacy');
    app(Context::class)->setSite($site);
    $type = EntryType::create(['org_id' => $org->id, 'handle' => 'member', 'name' => 'Member', 'plural_name' => 'Members']);

    $storage = [];
    foreach (['email' => 'personal', 'notes' => 'sensitive', 'internal' => 'none'] as $handle => $class) {
        $field = $storage[$handle] = FieldStorage::create([
            'org_id' => $org->id, 'handle' => $handle, 'type' => 'text', 'pii_class' => $class, 'cardinality' => 1,
        ]);
        $nomination = Field::create(['entry_type_id' => $type->id, 'field_storage_id' => $field->id, 'label' => ucfirst($handle)]);
        if ($handle === 'email') {
            $type->update(['subject_field_id' => $nomination->id]);
        }
    }

    $matched = Entry::create([
        'entry_type_id' => $type->id, 'title' => 'Member',
        'values' => ['email' => 'historical@example.test', 'notes' => 'historical match', 'internal' => 'out-of-scope field'],
    ]);
    $matched->update(['values' => ['email' => 'different@example.test', 'notes' => 'other person']]);
    $storage['notes']->update(['pii_class' => 'none']);
    $storage['internal']->update(['pii_class' => 'personal']);
    app(Context::class)->setSite($world['sites']['golfdom-fr']);
    Entry::create([
        'entry_type_id' => $type->id, 'title' => 'Same identifier at another Site',
        'values' => ['email' => 'historical@example.test', 'notes' => 'another site'],
    ]);
    ReaderFixture::forget();

    [$exit, $json] = subjectExportRun([
        '--org' => 'golfdom', '--site' => ['golfdom'], '--type' => (string) $type->id, '--storage' => (string) $storage['email']->id,
    ], json_encode(['identifier' => 'historical@example.test', 'reader' => $reader->getKey()], JSON_THROW_ON_ERROR));

    expect($exit)->toBe(0, $json);
    $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($report['content'])->toHaveCount(1)
        ->and($report['content'][0]['kind'])->toBe('revision')
        ->and($report['content'][0]['data'])->toBe(['email' => 'historical@example.test', 'notes' => 'historical match'])
        ->and(array_keys($report['content_coverage']))->toBe(['golfdom'])
        ->and($report['reader']['scope'])->toBe('org')
        ->and($report['reader']['host_coverage'])->toMatchArray(['status' => 'declared', 'scope' => 'reader_table'])
        ->and($report['reader']['account'])->not->toHaveKeys(['has_password', 'password', 'remember_token'])
        ->and($report['reader']['consents'])->toHaveCount(1)
        ->and($report['reader']['entitlements'][0]['entitlement'])->toBe('course.privacy')
        ->and($json)->not->toContain('another site', 'other person', 'out-of-scope field', 'password_hash');
});

it('refuses a reader session before handling subject input', function (): void {
    $world = ReaderFixture::world();
    $reader = ReaderFixture::reader($world['golfdom'], 'reader@kitsune.test');
    ReaderFixture::signIn($reader);

    [$exit, $output] = subjectExportRun(['--org' => 'golfdom'], 'malformed private input');
    expect($exit)->toBe(1)
        ->and($output)->toContain('reader')
        ->and($output)->not->toContain('malformed private input', 'JSON object');
});

it('returns verified local reader data with an unavailable module reported explicitly', function (): void {
    $world = ReaderFixture::world();
    $reader = ReaderFixture::reader($world['golfdom'], 'reader@kitsune.test');
    Module::create(['handle' => 'missing/private-store', 'version' => '1.0.0', 'is_enabled' => false, 'installed_at' => now()]);

    [$exit, $json] = subjectExportRun(['--org' => 'golfdom'], json_encode(['reader' => $reader->getKey()], JSON_THROW_ON_ERROR));
    expect($exit)->toBe(0, $json);
    $report = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
    expect($report->content_coverage)->toBeInstanceOf(stdClass::class)
        ->and($report->reader->account->email)->toBe('reader@kitsune.test')
        ->and($report->reader->module_coverage)->toBe('unavailable')
        ->and(property_exists($report->reader, 'module_data'))->toBeFalse();
});
