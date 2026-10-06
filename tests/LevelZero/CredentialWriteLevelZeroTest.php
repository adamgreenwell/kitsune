<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as Fx;

/*
 * The credential writer at transaction level 0, on a file another process can read — ADR-040: a write that cannot be
 * recorded is not kept, and a rival holding the database is a refusal, never a half-written credential.
 */

beforeEach(function (): void {
    config(['app.key' => Fx::appKey(), 'app.previous_keys' => []]);
    Fx::forget();
    app(CredentialSlots::class)->flush();
    app(CredentialSlots::class)->register(new CredentialSlot(Fx::SHARED, 'Fixture shared secret', 'Help.', false, minLength: 32));

    $this->org = Org::create(['slug' => 'zero', 'name' => 'Zero']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->getKey(), 'handle' => 'zero', 'slug' => 'zero', 'name' => 'Zero', 'locale' => 'en']);
    app(Context::class)->setSite($this->site);
});

afterEach(function (): void {
    app(Context::class)->forget();
    app(CredentialSlots::class)->flush();
    Fx::forget();
});

/** What another process sees: the committed state of the file. @return array{ciphertext: ?string, audit: int} */
function credentialZeroSeen(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);
    $ciphertext = $pdo->query("select ciphertext from credentials where slot = 'fx.shared-secret'")->fetchColumn();

    return [
        'ciphertext' => $ciphertext === false ? null : $ciphertext,
        'audit' => (int) $pdo->query("select count(*) from audit_log where action like 'credential.%'")->fetchColumn(),
    ];
}

it('keeps the old value when the record of a replace cannot be written, and chains nothing', function (): void {
    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
    $before = credentialZeroSeen($this->custodyFile);

    // The site in context goes underneath, so `audit_log.site_id`'s foreign key refuses the record of the next write.
    DB::table('sites')->where('id', $this->site->getKey())->delete();

    try {
        Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
        $this->fail('a write that could not be recorded was kept');
    } catch (CredentialRefused $refused) {
        expect($refused->reason)->toBe(CredentialRefusal::Database)
            ->and($refused->getPrevious())->toBeNull()
            ->and($refused->getMessage())->toStartWith('Fixture shared secret was not saved: the database refused the write (SQLSTATE ');
    }

    expect(credentialZeroSeen($this->custodyFile))->toBe($before)
        ->and($before['audit'])->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('refuses, writing nothing, while a rival holds the database, and writes once it lets go', function (string $mode): void {
    DB::statement("pragma journal_mode = {$mode}");
    DB::connection()->getPdo()->exec('pragma busy_timeout = 200');

    $rival = new PDO('sqlite:'.$this->custodyFile);
    $rival->exec('begin immediate');

    try {
        try {
            Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));
            $this->fail('a write went through a rival holding the database');
        } catch (CredentialRefused $refused) {
            expect($refused->reason)->toBeIn([CredentialRefusal::Database, CredentialRefusal::Race])
                ->and($refused->getPrevious())->toBeNull();
        }
    } finally {
        $rival->exec('rollback');
    }

    expect(credentialZeroSeen($this->custodyFile))->toBe(['ciphertext' => null, 'audit' => 0])
        ->and(DB::connection()->transactionLevel())->toBe(0);

    Fx::writer()->set(Fx::SHARED, null, Fx::value('', 40));

    expect(credentialZeroSeen($this->custodyFile)['audit'])->toBe(1)
        ->and(DB::table('audit_log')->where('action', CredentialWriter::SET)->count())->toBe(1);
})->with(['delete', 'wal']);
