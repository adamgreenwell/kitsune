<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Models\Reader;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Models\ReaderToken;
use Kitsune\Core\Readers\ReaderLinkUse;
use Kitsune\Core\Readers\ReaderTokens;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\BlindFindReader;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture as Fx;
use Kitsune\Core\Tests\Fixtures\UsernameClashReader;
use Kitsune\Core\Tests\TestCase;

/*
 * A mailed link's use at transaction level 0, on a file another process can read — ADR-037's second part, as built: the
 * link goes with the account it makes or the password it changes, or neither does, so a link is never used up for
 * nothing and never works twice.
 */

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'custody', '--path' => [TestCase::READERS_MIGRATION], '--realpath' => true, '--force' => true]);
    config(AppServiceProvider::readerConfig());
    Fx::boot();

    $this->org = Fx::org('zero');
    $this->site = Fx::site($this->org, 'zero');
    $this->reader = Reader::createReader('zero@kitsune.test', Hash::make('correct-horse-battery-staple'), null);
    Fx::nobody();
    app(Context::class)->forget()->setSite($this->site);
});

afterEach(fn () => Fx::tearDown());

/** What another process sees. @return array{readers: int, links: int, password: string} */
function linkZeroSeen(string $file): array
{
    $pdo = new PDO('sqlite:'.$file);

    return [
        'readers' => (int) $pdo->query('select count(*) from readers')->fetchColumn(),
        'links' => (int) $pdo->query('select count(*) from reader_tokens')->fetchColumn(),
        'password' => (string) $pdo->query("select password from readers where email = 'zero@kitsune.test'")->fetchColumn(),
    ];
}

/** A link minted for this subject, as its page finds it when it is used. */
function linkZeroToken(string $purpose, string $subject): ReaderToken
{
    $secret = (string) app(ReaderTokens::class)->mint($purpose, $subject);

    return app(ReaderTokens::class)->live($purpose, (string) ReaderTokens::hashOf($secret)) ?? throw new LogicException('The link is not live.');
}

it('commits the new account and the link\'s use together', function (): void {
    $token = linkZeroToken(ReaderTokens::REGISTER, 'newcomer@kitsune.test');

    [$outcome, $reader] = app(ReaderLinkUse::class)->complete($token, Hash::make('a long new password for zero'));

    expect($outcome)->toBe(ReaderLinkUse::CREATED)
        ->and($reader?->readerEmail())->toBe('newcomer@kitsune.test')
        ->and($reader?->email_verified_at)->not->toBeNull()
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(array_slice(linkZeroSeen($this->custodyFile), 0, 2))->toBe(['readers' => 2, 'links' => 0]);
});

it('keeps the link when the account cannot be made', function (): void {
    $token = linkZeroToken(ReaderTokens::REGISTER, 'newcomer@kitsune.test');
    Reader::creating(static function (): bool {
        throw new RuntimeException('The host refused to create the row.');
    });

    expect(fn () => app(ReaderLinkUse::class)->complete($token, Hash::make('a long new password for zero')))
        ->toThrow(RuntimeException::class, 'The host refused to create the row.');

    expect(array_slice(linkZeroSeen($this->custodyFile), 0, 2))->toBe(['readers' => 1, 'links' => 1])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('uses the link up on its own when the insert meets the unique index, and the connection still works', function (): void {
    config(['auth.providers.readers.model' => BlindFindReader::class]);
    $token = linkZeroToken(ReaderTokens::REGISTER, 'zero@kitsune.test');
    BlindFindReader::$misses = 1;

    expect(app(ReaderLinkUse::class)->complete($token, Hash::make('a long new password for zero')))->toBe([ReaderLinkUse::EXISTS, null])
        ->and(array_slice(linkZeroSeen($this->custodyFile), 0, 2))->toBe(['readers' => 1, 'links' => 0])
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::table('readers')->count())->toBe(1);
});

it('keeps the link when the host refuses the insert for a reason of its own — another unique column', function (): void {
    config(['auth.providers.readers.model' => UsernameClashReader::class]);
    $token = linkZeroToken(ReaderTokens::REGISTER, 'newcomer@kitsune.test');

    expect(fn () => app(ReaderLinkUse::class)->complete($token, Hash::make('a long new password for zero')))
        ->toThrow(RuntimeException::class, 'The readers table could not be read or written (SQLSTATE 23000).');

    expect(array_slice(linkZeroSeen($this->custodyFile), 0, 2))->toBe(['readers' => 1, 'links' => 1])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('commits the new password and the link\'s use together', function (): void {
    $before = linkZeroSeen($this->custodyFile)['password'];
    $token = linkZeroToken(ReaderTokens::RECOVER, (string) $this->reader->getKey());

    $reader = app(ReaderLinkUse::class)->reset($token, Hash::make('a different long password'));
    $seen = linkZeroSeen($this->custodyFile);

    expect($reader?->getKey())->toBe($this->reader->getKey())
        ->and($seen['links'])->toBe(0)
        ->and($seen['password'])->not->toBe($before)
        ->and(Hash::check('a different long password', $seen['password']))->toBeTrue()
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('keeps the link and the old password when the new one cannot be written', function (): void {
    $before = linkZeroSeen($this->custodyFile)['password'];
    $token = linkZeroToken(ReaderTokens::RECOVER, (string) $this->reader->getKey());
    Reader::saving(static function (): bool {
        throw new RuntimeException('The host refused to save the row.');
    });

    expect(fn () => app(ReaderLinkUse::class)->reset($token, Hash::make('a different long password')))
        ->toThrow(RuntimeException::class, 'The host refused to save the row.');

    expect(linkZeroSeen($this->custodyFile))->toBe(['readers' => 1, 'links' => 1, 'password' => $before])
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('does nothing with a link another request used first', function (): void {
    $register = linkZeroToken(ReaderTokens::REGISTER, 'newcomer@kitsune.test');
    $recover = linkZeroToken(ReaderTokens::RECOVER, (string) $this->reader->getKey());
    $before = linkZeroSeen($this->custodyFile)['password'];

    // The other request, through its own connection.
    (new PDO('sqlite:'.$this->custodyFile))->exec('delete from reader_tokens');

    expect(app(ReaderLinkUse::class)->complete($register, Hash::make('a long new password for zero')))->toBe([ReaderLinkUse::DEAD, null])
        ->and(app(ReaderLinkUse::class)->reset($recover, Hash::make('a different long password')))->toBeNull()
        ->and(linkZeroSeen($this->custodyFile))->toBe(['readers' => 1, 'links' => 0, 'password' => $before]);
});

it('does nothing with a link that expired between its page and its use', function (): void {
    $token = linkZeroToken(ReaderTokens::REGISTER, 'newcomer@kitsune.test');
    $this->travel(ReaderTokens::LIFETIME_MINUTES)->minutes();

    expect(app(ReaderLinkUse::class)->complete($token, Hash::make('a long new password for zero')))->toBe([ReaderLinkUse::DEAD, null])
        ->and(array_slice(linkZeroSeen($this->custodyFile), 0, 2))->toBe(['readers' => 1, 'links' => 1]);
});
