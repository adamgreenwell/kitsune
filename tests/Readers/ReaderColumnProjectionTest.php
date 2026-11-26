<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use Kitsune\Core\Readers\Contracts\ReaderColumnClass;
use Kitsune\Core\Readers\ReaderColumnProjection;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tests\Fixtures\StringKeyedReaderAccount;

it('reports gaps and omits undeclared, malformed, duplicate and secret mappings', function (): void {
    $reader = new #[OrgScoped] class extends Reader
    {
        public static function readerColumnPrivacy(): array
        {
            $columns = parent::readerColumnPrivacy();
            unset($columns['updated_at']);
            $columns['password']['export_as'] = 'unexpected_secret';
            $columns['removed'] = ['class' => ReaderColumnClass::Personal, 'export_as' => 'removed'];
            $columns['created_at']['export_as'] = 'email';
            $columns['email_verified_at']['export_as'] = 'password';
            $columns['remember_token'] = ReaderColumnClass::Secret;
            $columns['org_id']['export_as'] = 123;

            return $columns;
        }
    };
    $reader->setRawAttributes([
        'id' => 'sample',
        'email' => 'duplicate alias',
        'email_verified_at' => 'secret column alias',
        'password' => 'must not appear',
        'remember_token' => 'must not appear either',
        'updated_at' => 'undeclared column',
        'removed' => 'stale column',
    ], sync: true);
    $result = ReaderColumnProjection::for($reader);

    expect($result['account'])->toBe(['reader' => 'sample'])
        ->and($result['coverage'])->toBe([
            'status' => 'partial',
            'scope' => 'reader_table',
            'unclassified_columns' => 1,
            'stale_column_declarations' => 1,
            'invalid_export_mappings' => 5,
        ]);
});

it('still accepts the existing projection from a legacy string-keyed reader model', function (): void {
    $reader = new StringKeyedReaderAccount;
    $reader->forceFill(['id' => 'acct|011', 'email' => 'legacy@example.test']);

    expect(ReaderColumnProjection::for($reader))->toBe([
        'account' => ['reader' => 'acct|011', 'email' => 'legacy@example.test'],
        'coverage' => ['status' => 'legacy_host_projection'],
    ])->and(ReaderColumnProjection::for(null))->toBe([
        'account' => null,
        'coverage' => ['status' => 'account_absent'],
    ]);
});

it('exports the cleartext of a declared Laravel encrypted column and withholds unreadable ciphertext', function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
    $reader = new #[OrgScoped] class extends Reader
    {
        protected function casts(): array
        {
            return parent::casts() + ['email' => 'encrypted'];
        }
    };
    $encrypted = Reader::currentEncrypter()->encrypt('encrypted@example.test', false);
    $reader->setRawAttributes(['id' => 7, 'email' => $encrypted, 'created_at' => null, 'updated_at' => null, 'email_verified_at' => null], sync: true);
    $report = ReaderColumnProjection::for($reader);
    expect($report['account']['email'])->toBe('encrypted@example.test')
        ->and($report['coverage']['status'])->toBe('declared');

    $reader->setRawAttributes(['email' => 'damaged-ciphertext'] + $reader->getRawOriginal(), sync: true);
    $report = ReaderColumnProjection::for($reader);
    expect($report['account'])->not->toHaveKey('email')
        ->and($report['coverage'])->toMatchArray(['status' => 'partial', 'invalid_export_mappings' => 1]);
});
