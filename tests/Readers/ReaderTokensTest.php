<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Models\ReaderToken;
use Kitsune\Core\Readers\ReaderTokens;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderMailbox;
use Kitsune\Core\Tests\Fixtures\RecordingTimebox;

/*
 * The links core mails to readers, as `reader_tokens` keeps them — ADR-037's second part, as built: the hash only, one
 * live link per subject, used up by deleting it, working on one site of one org for sixty minutes.
 */

const TOKENS_RESET = '/golfdom/account/reset';

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'open');
    ReaderFixture::mode($this->world['rival'], 'open');
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    RecordingTimebox::install();
});

afterEach(function (): void {
    ReaderFixture::forget();
});

/** The rows of every org, as stored. */
function tokensRows(): array
{
    return DB::table('reader_tokens')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
}

/** A link for this reader at Golfdom, minted as the recovery page mints one — and its secret. */
function tokensMint(?string $subject = null): string
{
    app(Context::class)->forget()->setSite(test()->world['sites']['golfdom']);

    try {
        return (string) app(ReaderTokens::class)->mint(ReaderTokens::RECOVER, $subject ?? (string) app(ReaderGuard::class)->key(test()->reader->getKey()));
    } finally {
        ReaderFixture::forget();
    }
}

/** Opens a recovery link at a site's prefix and answers what the page then says. */
function tokensOpen(string $secret, string $prefix = '/golfdom'): int
{
    test()->readerGet($prefix.'/account/reset?token='.$secret)->assertStatus(303);

    return test()->readerGet($prefix.'/account/reset')->getStatusCode();
}

it('stores the secret\'s hash and nothing that opens anything', function (): void {
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $secret = (string) ReaderMailbox::secret();
    $rows = tokensRows();

    expect($rows)->toHaveCount(1)
        ->and(array_keys($rows[0]))->toBe(['id', 'org_id', 'site_id', 'purpose', 'subject', 'token_hash', 'expires_at'])
        ->and($rows[0]['token_hash'])->toBe(hash('sha256', $secret))
        ->and($rows[0]['subject'])->toBe((string) $this->reader->getKey())
        ->and($rows[0]['purpose'])->toBe('recover')
        ->and($rows[0]['site_id'])->toBe($this->world['sites']['golfdom']->getKey())
        ->and($rows[0]['org_id'])->toBe($this->world['golfdom']->getKey())
        ->and(json_encode($rows))->not->toContain($secret)
        ->and(strlen($secret))->toBe(43)
        ->and(ReaderToken::query()->getModel()->getHidden())->toBe(['subject', 'token_hash']);
});

it('runs no query for a value that is not a secret', function (mixed $value): void {
    expect(ReaderTokens::hashOf($value))->toBeNull();

    $queries = 0;
    DB::listen(static function ($query) use (&$queries): void {
        $queries += str_contains($query->sql, 'reader_tokens') ? 1 : 0;
    });

    if (is_string($value)) {
        $this->readerGet(TOKENS_RESET.'?token='.rawurlencode($value))->assertStatus(303);
        $this->readerGet(TOKENS_RESET)->assertStatus(410);
    }

    expect($queries)->toBe(0);
})->with([
    'nothing' => [''],
    'one short' => [str_repeat('a', 42)],
    'one long' => [str_repeat('a', 44)],
    'outside the alphabet' => [str_repeat('a', 42).'_'],
    'a space inside' => [str_repeat('a', 21).' '.str_repeat('a', 21)],
    'a number' => [42],
    'a list' => [[str_repeat('a', 43)]],
    'null' => [null],
]);

it('reads a secret exactly, a newline after it included — the request trims one before it gets here', function (): void {
    expect(ReaderTokens::hashOf(str_repeat('a', 43)."\n"))->toBeNull()
        ->and(ReaderTokens::hashOf(str_repeat('a', 43)))->toBe(hash('sha256', str_repeat('a', 43)));
});

it('works for sixty minutes to the second: live at 59:59, dead at 60:00', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00.750', 'UTC'));
    $secret = tokensMint();

    expect(tokensRows()[0]['expires_at'])->toBe('2026-10-09 13:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:59:59.999', 'UTC'));
    expect(tokensOpen($secret))->toBe(200);

    $this->travelTo(CarbonImmutable::parse('2026-10-09 13:00:00', 'UTC'));
    expect(tokensOpen($secret))->toBe(410);

    // And a POST at the same instant uses nothing up.
    $this->readerPost(TOKENS_RESET, ['password' => 'a different long password', 'password_confirmation' => 'a different long password'])->assertStatus(410);
});

it('replaces an older link with a newer one, so only the newest works', function (): void {
    $older = tokensMint();
    $newer = tokensMint();

    expect(tokensRows())->toHaveCount(1)
        ->and(tokensOpen($older))->toBe(410)
        ->and(tokensOpen($newer))->toBe(200);
});

it('keeps one link per subject and purpose: a sign-up and a recovery of one address stand side by side', function (): void {
    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $this->readerPost('/rival/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    expect(array_map(static fn (array $row): array => [$row['org_id'], $row['purpose'], $row['subject']], tokensRows()))->toBe([
        [$this->world['golfdom']->getKey(), 'register', 'newcomer@kitsune.test'],
        [$this->world['golfdom']->getKey(), 'recover', (string) $this->reader->getKey()],
        [$this->world['rival']->getKey(), 'register', 'newcomer@kitsune.test'],
    ]);
});

it('mails nothing when a simultaneous request minted the subject\'s link first, and answers the same', function (): void {
    $before = $this->readerPost('/golfdom/account/recover', ['email' => 'nobody@kitsune.test']);
    ReaderMailbox::flush();

    // The other request's row lands between this one's delete and its insert — committed on its own, as it would be.
    $planted = false;
    DB::listen(function (QueryExecuted $query) use (&$planted): void {
        if ($planted || preg_match('/^delete from ["`]?reader_tokens["`]?/i', $query->sql) !== 1) {
            return;
        }

        $planted = true;
        DB::table('reader_tokens')->insert([
            'org_id' => $this->world['golfdom']->getKey(),
            'site_id' => $this->world['sites']['golfdom']->getKey(),
            'purpose' => ReaderTokens::RECOVER,
            'subject' => (string) $this->reader->getKey(),
            'token_hash' => hash('sha256', 'the other request'),
            'expires_at' => '2099-01-01 00:00:00',
        ]);
    });

    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });

    $raced = $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test']);

    // A race handled, not a mail that failed: nothing logged, and the other request's link is the one kept.
    expect($raced->getStatusCode())->toBe($before->getStatusCode())
        ->and($raced->headers->get('Location'))->toBe($before->headers->get('Location'))
        ->and(ReaderMailbox::messages())->toBe([])
        ->and($logged)->toBe([])
        ->and(array_column(tokensRows(), 'token_hash'))->toBe([hash('sha256', 'the other request')]);
});

it('works once, whatever happens between the two uses', function (): void {
    $secret = tokensMint();
    tokensOpen($secret);
    $this->readerPost(TOKENS_RESET, ['password' => 'a different long password', 'password_confirmation' => 'a different long password'])->assertStatus(303);
    $this->readerPost('/golfdom/account/sign-out')->assertStatus(303);

    expect(tokensRows())->toBe([])
        ->and(tokensOpen($secret))->toBe(410);
});

it('uses a link up only on the site in context, whatever the caller found', function (): void {
    $secret = tokensMint();
    app(Context::class)->forget()->setSite($this->world['sites']['golfdom']);
    $token = app(ReaderTokens::class)->live(ReaderTokens::RECOVER, (string) ReaderTokens::hashOf($secret));

    app(Context::class)->forget()->setSite($this->world['sites']['golfdom-fr']);
    expect(app(ReaderTokens::class)->claim($token))->toBeFalse()
        ->and(tokensRows())->toHaveCount(1);

    app(Context::class)->forget()->setSite($this->world['sites']['golfdom']);
    expect(app(ReaderTokens::class)->claim($token))->toBeTrue()
        ->and(app(ReaderTokens::class)->claim($token))->toBeFalse()
        ->and(tokensRows())->toBe([]);
});

it('refuses a row whose site belongs to another org, even when its hash matches', function (): void {
    $secret = str_repeat('Q', 43);

    // Below Eloquent, as only a hand-written query could: Rival's org with Golfdom's site.
    DB::table('reader_tokens')->insert([
        'org_id' => $this->world['rival']->getKey(),
        'site_id' => $this->world['sites']['golfdom']->getKey(),
        'purpose' => 'recover',
        'subject' => (string) $this->reader->getKey(),
        'token_hash' => hash('sha256', $secret),
        'expires_at' => '2099-01-01 00:00:00',
    ]);

    expect(tokensOpen($secret))->toBe(410)
        ->and(tokensOpen($secret, '/rival'))->toBe(410)
        ->and(tokensRows())->toHaveCount(1);
});

it('sweeps the org\'s expired links after a request, and no other org\'s', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
    $this->readerPost('/golfdom/account/register', ['email' => 'early@kitsune.test'])->assertStatus(303);
    $this->readerPost('/rival/account/register', ['email' => 'early@kitsune.test'])->assertStatus(303);

    $this->travelTo(CarbonImmutable::parse('2026-10-09 13:00:00', 'UTC'));
    $this->readerPost('/golfdom/account/register', ['email' => 'late@kitsune.test'])->assertStatus(303);

    expect(array_column(tokensRows(), 'subject'))->toBe(['early@kitsune.test', 'late@kitsune.test'])
        ->and(array_column(tokensRows(), 'org_id'))->toBe([$this->world['rival']->getKey(), $this->world['golfdom']->getKey()]);
});

it('sweeps nothing once the org in context is another', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
    tokensMint();
    $this->travelTo(CarbonImmutable::parse('2026-10-09 14:00:00', 'UTC'));

    app(Context::class)->forget()->setOrg($this->world['rival']);
    expect(app(ReaderTokens::class)->sweep((int) $this->world['golfdom']->getKey()))->toBe(0);

    app(Context::class)->forget()->setOrg($this->world['golfdom']);
    expect(app(ReaderTokens::class)->sweep((int) $this->world['rival']->getKey()))->toBe(0)
        ->and(app(ReaderTokens::class)->sweep((int) $this->world['golfdom']->getKey()))->toBe(1)
        ->and(tokensRows())->toBe([]);
});

it('goes with its site, and with its org', function (): void {
    tokensMint();
    $this->readerPost('/golfdom-fr/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $this->readerPost('/rival/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    app(Context::class)->forget()->setOrg($this->world['golfdom']);
    $this->world['sites']['golfdom-fr']->delete();

    expect(array_column(tokensRows(), 'site_id'))->toBe([$this->world['sites']['golfdom']->getKey(), $this->world['sites']['rival']->getKey()]);

    ReaderFixture::forget();
    $this->world['golfdom']->forceDelete();

    expect(array_column(tokensRows(), 'org_id'))->toBe([$this->world['rival']->getKey()]);
});

it('refuses to mint without a site in context', function (): void {
    app(Context::class)->forget()->setOrg($this->world['golfdom']);

    expect(fn () => app(ReaderTokens::class)->mint(ReaderTokens::RECOVER, '1'))
        ->toThrow(LogicException::class, 'A reader link is minted on the site in context, and there is none.');
});

it('names no address, key or hash when the table fails', function (): void {
    DB::statement('DROP TABLE reader_tokens');

    app(Context::class)->forget()->setSite($this->world['sites']['golfdom']);

    try {
        app(ReaderTokens::class)->forgetSubject(ReaderTokens::REGISTER, 'subscriber@kitsune.test');
        $message = null;
    } catch (RuntimeException $refused) {
        $message = $refused->getMessage();
        $previous = $refused->getPrevious();
    }

    // Each engine has its own SQLSTATE for a missing table; the address is in none of them.
    expect($message)->toMatch('/^The reader link table could not be read or written \(SQLSTATE [0-9A-Z]{5}\)\.$/D')
        ->and($previous)->toBeNull();
});
