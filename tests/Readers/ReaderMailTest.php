<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Kitsune\Core\Models\ReaderToken;
use Kitsune\Core\Readers\ReaderMail;
use Kitsune\Core\Tests\Fixtures\DeliveringTransport;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderMailbox;
use Kitsune\Core\Tests\Fixtures\RecordingTimebox;
use Kitsune\Core\Tests\Fixtures\RefusingTransport;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/*
 * The mail a reader gets, and the floor's mail rule — ADR-037's second part, as built. `ReaderMail` is the one place a
 * reader page sends anything; `fault()` is why a page that cannot keep its promise answers 503 instead.
 */

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'open');
    ReaderFixture::mode($this->world['rival'], 'open');
    $this->reader = ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    RecordingTimebox::install();
    config(['mail.from.address' => 'readers@kitsunecms.org']);
});

/** This application, as a production deploy sees itself: both places Laravel keeps the environment. */
function mailProduction(array $config = []): void
{
    app()->detectEnvironment(static fn (): string => 'production');
    config(['app.env' => 'production', ...$config]);
    app('mail.manager')->forgetMailers();
}

/** The mailers a production test chooses between, beside the skeleton's own. */
function mailMailers(): array
{
    return [
        'mail.mailers.stock-failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
        'mail.mailers.robin' => ['transport' => 'roundrobin', 'mailers' => ['delivering', 'log']],
        'mail.mailers.robin-sending' => ['transport' => 'roundrobin', 'mailers' => ['delivering', 'delivering']],
        'mail.mailers.as-url' => ['url' => 'log://localhost'],
        'mail.mailers.loop' => ['transport' => 'failover', 'mailers' => ['loop']],
    ];
}

// ---- After the response ---------------------------------------------------------------------------------------------

it('sends after the response has gone, never inside the request', function (): void {
    $during = null;
    Event::listen(RequestHandled::class, static function () use (&$during): void {
        $during = count(ReaderMailbox::messages());
    });

    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    expect($during)->toBe(0)
        ->and(ReaderMailbox::messages())->toHaveCount(1);
});

it('is no queued job: the floor\'s queue would send inside the request, and keep the link in a table', function (): void {
    expect(new ReaderMail('Subject', 'Body', 'Golfdom'))->not->toBeInstanceOf(ShouldQueue::class);
});

it('sends nothing for a refusal', function (): void {
    $this->readerPost('/golfdom/account/register', ['email' => 'not-an-address'])->assertStatus(422);
    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'], token: false)->assertStatus(419);

    expect(ReaderMailbox::messages())->toBe([]);
});

// ---- The bytes ------------------------------------------------------------------------------------------------------

it('sends plain text from the site\'s name on the installation\'s address, and nothing else', function (): void {
    $this->world['sites']['golfdom']->forceFill(['name' => 'Tom & Jerry\'s <Golf>'])->save();
    ReaderFixture::forget();

    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $mail = ReaderMailbox::last();
    $secret = (string) ReaderMailbox::secret();

    expect($mail->getHtmlBody())->toBeNull()
        ->and($mail->getAttachments())->toBe([])
        ->and($mail->getFrom()[0]->getAddress())->toBe('readers@kitsunecms.org')
        ->and($mail->getFrom()[0]->getName())->toBe('Tom & Jerry\'s <Golf>')
        ->and($mail->getTo()[0]->getAddress())->toBe('newcomer@kitsune.test')
        ->and($mail->getTo())->toHaveCount(1)
        ->and($mail->getCc())->toBe([])
        ->and($mail->getBcc())->toBe([])
        ->and($mail->getSubject())->toBe('Finish creating your Tom & Jerry\'s <Golf> account')
        ->and($mail->getTextBody())->toBe(implode("\n", [
            'Hello,',
            '',
            'Someone, we hope you, asked to create an account on Tom & Jerry\'s <Golf> with this email address.',
            '',
            'To finish, open this link and choose a password. It works once, for 60 minutes:',
            '',
            'http://localhost/golfdom/account/register/complete?token='.$secret,
            '',
            'If you didn\'t ask, you can ignore this email. No account is made unless the link is used.',
            '',
        ]))
        ->and($mail->toString())->toContain('Content-Type: text/plain; charset=utf-8');
});

it('lets no line break in a site\'s name add a header', function (): void {
    $this->world['sites']['golfdom']->forceFill(['name' => "Golfdom\r\nBcc: thief@elsewhere.test"])->save();
    ReaderFixture::forget();

    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    // The header block alone: the name is the org's own text, and in the body a line break is only a line break.
    $headers = explode("\r\n\r\n", (string) ReaderMailbox::last()?->toString(), 2)[0];

    expect(ReaderMailbox::last()?->getBcc())->toBe([])
        ->and(preg_match('/^Bcc:/mi', $headers))->toBe(0, $headers)
        ->and($headers)->toContain('From: "GolfdomBcc: thief@elsewhere.test" <readers@kitsunecms.org>');
});

it('mails an address with an account where to sign in and recover, and no link that opens anything', function (): void {
    $this->readerPost('/golfdom/account/register', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);

    expect(ReaderMailbox::last()?->getTextBody())->toContain("Sign in here:\nhttp://localhost/golfdom/account/sign-in\n")
        ->toContain("Choose a new one here:\nhttp://localhost/golfdom/account/recover\n")
        ->not->toContain('token=')
        ->and(DB::table('reader_tokens')->count())->toBe(0);
});

// ---- Links ----------------------------------------------------------------------------------------------------------

it('builds a link from the site\'s own address, its port kept and its user name and password never printed', function (): void {
    DB::table('sites')->where('id', $this->world['sites']['shop']->getKey())->update(['base_url' => 'https://operator:secret@rival.test:8443/shop']);
    ReaderFixture::forget();

    $this->readerPost('https://rival.test/shop/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $body = (string) ReaderMailbox::last()?->getTextBody();

    expect($body)->toContain('https://rival.test:8443/shop/account/register/complete?token=')
        ->not->toContain('operator')
        ->not->toContain('secret@');
});

it('drops the port a scheme has by default', function (): void {
    DB::table('sites')->where('id', $this->world['sites']['shop']->getKey())->update(['base_url' => 'https://rival.test:443/shop']);
    ReaderFixture::forget();

    $this->readerPost('https://rival.test/shop/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    expect((string) ReaderMailbox::last()?->getTextBody())->toContain('https://rival.test/shop/account/register/complete?token=');
});

it('never builds a link from the Host a request sent', function (): void {
    $this->readerPost('http://evil.test/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    expect((string) ReaderMailbox::last()?->getTextBody())->toContain('http://localhost/golfdom/account/register/complete?token=')
        ->not->toContain('evil.test');
});

it('takes APP_URL for a host-less site in development only, and only an origin', function (string $appUrl, bool $opens): void {
    config(['app.url' => $appUrl]);

    $this->readerGet('/golfdom/account/register')->assertStatus($opens ? 200 : 503);
})->with([
    'an origin' => ['http://kitsune.test:8000', true],
    'with a trailing slash' => ['https://kitsune.test/', true],
    'with a path' => ['https://kitsune.test/cms', false],
    'with a user' => ['https://me@kitsune.test', false],
    'with a query' => ['https://kitsune.test/?a=b', false],
    'not http' => ['ftp://kitsune.test', false],
    'nothing' => ['', false],
]);

// ---- The floor's mail rule ------------------------------------------------------------------------------------------

it('closes sign-up and recovery in production while mail cannot reach a reader, and says why on the status line', function (array $config, string $why): void {
    DeliveringTransport::install();
    mailProduction(['mail.default' => 'delivering', ...mailMailers(), ...$config]);

    expect(ReaderMail::fault())->toBe($why);

    $this->readerGet('/golfdom/account/register')->assertStatus(503);
    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(503);
    $this->readerGet('/golfdom/account/recover')->assertStatus(503)->assertSeeText('Password recovery isn\'t available here right now, because this site can\'t send email yet.');
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(503);

    // Sign-in never asks.
    $this->readerGet('/golfdom/account/sign-in')->assertOk();

    expect(DB::table('reader_tokens')->count())->toBe(0)
        ->and(DeliveringTransport::$sent)->toBe([]);
})->with([
    'the log mailer' => [['mail.default' => 'log'], 'the mailer [log] uses the [log] transport, which keeps mail instead of sending it'],
    'the array mailer' => [['mail.default' => 'array'], 'the mailer [array] uses the [array] transport, which keeps mail instead of sending it'],
    'the stock failover, ending in log' => [['mail.default' => 'stock-failover'], 'the mailer [log] uses the [log] transport, which keeps mail instead of sending it'],
    'a round robin with log in it' => [['mail.default' => 'robin'], 'the mailer [log] uses the [log] transport, which keeps mail instead of sending it'],
    'a mailer that is not defined' => [['mail.default' => 'nonesuch'], 'no mailer [nonesuch] is defined'],
    'no mailer at all' => [['mail.default' => null], 'no default mailer is configured'],
    'log given as a URL' => [['mail.default' => 'as-url'], 'the mailer [as-url] uses the [log] transport, which keeps mail instead of sending it'],
    'the legacy mail.driver' => [['mail.driver' => 'log'], 'the mailer [log] uses the [log] transport, which keeps mail instead of sending it'],
    'a failover that names itself' => [['mail.default' => 'loop'], 'the mailer [loop] names itself among its own members'],
    'the placeholder sender' => [['mail.from.address' => 'Hello@Example.com'], 'the sender address is still Laravel\'s placeholder, hello@example.com (MAIL_FROM_ADDRESS)'],
    'no sender' => [['mail.from.address' => ''], 'no sender address is configured (MAIL_FROM_ADDRESS)'],
    'the sample sender in .env.example' => [['mail.from.address' => 'hello@your-domain.example'], 'the sender address is at [your-domain.example], a domain reserved for examples and tests, which no mail server delivers for (MAIL_FROM_ADDRESS)'],
    'a sender at example.org' => [['mail.from.address' => 'Readers@Example.ORG'], 'the sender address is at [example.org], a domain reserved for examples and tests, which no mail server delivers for (MAIL_FROM_ADDRESS)'],
    'a sender at a .test host' => [['mail.from.address' => 'readers@kitsune.test'], 'the sender address is at [kitsune.test], a domain reserved for examples and tests, which no mail server delivers for (MAIL_FROM_ADDRESS)'],
]);

it('closes them on a site no mailed link can name, whatever the mailer', function (string $baseUrl): void {
    DeliveringTransport::install();
    mailProduction(['mail.default' => 'delivering']);
    DB::table('sites')->where('id', $this->world['sites']['shop']->getKey())->update(['base_url' => $baseUrl]);
    ReaderFixture::forget();

    expect(ReaderMail::fault())->toBeNull();

    $this->readerGet('/golfdom/account/register')->assertStatus(503);
    $this->readerPost('https://rival.test/shop/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(503);

    expect(DeliveringTransport::$sent)->toBe([]);
})->with([
    'no scheme' => ['//rival.test/shop'],
    'not http' => ['ftp://rival.test/shop'],
]);

it('opens them in production once the mailer sends and the sender is real', function (string $mailer): void {
    DeliveringTransport::install();
    mailProduction(['mail.default' => $mailer, ...mailMailers()]);

    expect(ReaderMail::fault())->toBeNull();

    $this->readerPost('https://rival.test/shop/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    expect(DeliveringTransport::$sent)->toHaveCount(1);
})->with(['a mailer that sends' => ['delivering'], 'a round robin of them' => ['robin-sending']]);

it('reads each mailer as Laravel builds it: what it calls closed keeps mail, and what it calls open sends', function (string $mailer, string $transport): void {
    DeliveringTransport::install();
    mailProduction(['mail.default' => $mailer, ...mailMailers()]);

    $built = app('mail.manager')->mailer()->getSymfonyTransport();
    $members = $built instanceof RoundRobinTransport
        ? array_map(static fn (object $member): string => $member::class, (new ReflectionProperty(RoundRobinTransport::class, 'transports'))->getValue($built))
        : [];

    expect([$built::class, ...$members])->toBe(match ($transport) {
        'log' => [LogTransport::class],
        'array' => [ArrayTransport::class],
        'failover' => [FailoverTransport::class, EsmtpTransport::class, LogTransport::class],
        'robin' => [RoundRobinTransport::class, DeliveringTransport::class, LogTransport::class],
        'sending' => [RoundRobinTransport::class, DeliveringTransport::class, DeliveringTransport::class],
    })->and(ReaderMail::fault() === null)->toBe($transport === 'sending');
})->with([
    'log' => ['log', 'log'],
    'array' => ['array', 'array'],
    'the stock failover' => ['stock-failover', 'failover'],
    'a round robin with log' => ['robin', 'robin'],
    'log as a URL' => ['as-url', 'log'],
    'a round robin that sends' => ['robin-sending', 'sending'],
]);

it('keeps the pages open in local and testing whatever the mailer, so a developer reads the link in the log', function (string $environment): void {
    app()->detectEnvironment(static fn (): string => $environment);
    config(['app.env' => $environment, 'mail.default' => 'log', 'mail.from.address' => 'hello@example.com']);

    expect(ReaderMail::fault())->toBeNull();
    $this->readerGet('/golfdom/account/register')->assertOk();
})->with(['local', 'testing']);

it('reads every environment but local and testing as one where mail must be sent', function (string $environment): void {
    app()->detectEnvironment(static fn (): string => $environment);
    config(['app.env' => $environment, 'mail.default' => 'log']);

    expect(ReaderMail::fault())->toBe('the mailer [log] uses the [log] transport, which keeps mail instead of sending it');

    DeliveringTransport::install();
    config(['mail.default' => 'delivering']);

    // The mailer sends, and a host-less site still has no host to stand a link on.
    expect(ReaderMail::fault())->toBeNull();
    $this->readerGet('/golfdom/account/register')->assertStatus(503);
    $this->readerGet('https://rival.test/shop/account/register')->assertOk();
})->with(['staging', 'qa']);

it('closes them everywhere when the HTTP kernel would never send a deferred mail', function (): void {
    $kernel = app(HttpKernel::class);
    $kernel->setGlobalMiddleware(array_values(array_diff($kernel->getGlobalMiddleware(), [InvokeDeferredCallbacks::class])));

    expect(ReaderMail::fault())->toBe('the HTTP kernel does not run deferred callbacks (InvokeDeferredCallbacks), so a mail would never be sent');

    $this->readerGet('/golfdom/account/register')->assertStatus(503);
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(503);
});

// ---- A mail that cannot be sent -------------------------------------------------------------------------------------

it('swallows a send that fails into one line naming the mailer alone', function (): void {
    Exceptions::fake();
    RefusingTransport::install();
    config(['mail.default' => 'refusing']);
    app('mail.manager')->forgetMailers();

    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = [$message->level, $message->message, $message->context];
    });

    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])
        ->assertStatus(303)
        ->assertHeader('Location', '/golfdom/account/register');

    Exceptions::assertNothingReported();

    expect($logged)->toBe([[
        'warning',
        'A reader email could not be made or sent through the mailer [refusing]; the address, the link and the error are not logged.',
        [],
    ]]);
});

it('writes nothing before the response, on any branch — the link is minted after it, with the mail', function (): void {
    $during = [];
    Event::listen(RequestHandled::class, static function () use (&$during): void {
        $during[] = DB::table('reader_tokens')->count();
    });

    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);

    expect($during)->toBe([0, 1])
        ->and(DB::table('reader_tokens')->count())->toBe(2);
});

it('logs one line naming the mailer when the link cannot be minted after the response', function (): void {
    Exceptions::fake();
    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });
    ReaderToken::creating(static function (): bool {
        throw new RuntimeException('newcomer@kitsune.test could not be written');
    });

    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    Exceptions::assertNothingReported();

    expect($logged)->toBe(['A reader email could not be made or sent through the mailer [array]; the address, the link and the error are not logged.'])
        ->and(ReaderMailbox::messages())->toBe([]);
});

// ---- Failover and round robin ---------------------------------------------------------------------------------------

it('walks a failover itself, so a member that fails logs nothing — its error would name the reader', function (): void {
    Exceptions::fake();
    RefusingTransport::install();
    DeliveringTransport::install();
    config(['mail.default' => 'chain', 'mail.mailers.chain' => ['transport' => 'failover', 'mailers' => ['refusing', 'delivering']]]);
    app('mail.manager')->forgetMailers();

    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.json_encode($message->context);
    });

    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    Exceptions::assertNothingReported();

    expect($logged)->toBe([])
        ->and(DeliveringTransport::$sent)->toHaveCount(1)
        ->and(ReaderMail::chain())->toBe(['refusing', 'delivering']);
});

it('says once, naming the mailer alone, when every member fails', function (): void {
    RefusingTransport::install();
    config(['mail.default' => 'chain', 'mail.mailers.chain' => ['transport' => 'failover', 'mailers' => ['refusing', 'refusing']]]);
    app('mail.manager')->forgetMailers();

    $logged = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message;
    });

    $this->readerPost('/golfdom/account/register', ['email' => 'newcomer@kitsune.test'])->assertStatus(303);

    expect($logged)->toBe(['A reader email could not be made or sent through the mailer [chain]; the address, the link and the error are not logged.']);
});

it('follows members down to the mailers that send, a round robin from any of its members', function (): void {
    config([
        'mail.default' => 'outer',
        'mail.mailers.inner' => ['transport' => 'failover', 'mailers' => ['smtp', 'array']],
        'mail.mailers.outer' => ['transport' => 'roundrobin', 'mailers' => ['inner', 'log']],
    ]);

    $seen = [];

    foreach (range(1, 40) as $draw) {
        $seen[implode(',', ReaderMail::chain())] = true;
    }

    expect(array_keys($seen))->toEqualCanonicalizing(['smtp,array,log', 'log,smtp,array']);

    // A mailer that is no failover, the legacy driver, and none at all are tried as they are.
    config(['mail.default' => 'smtp']);
    expect(ReaderMail::chain())->toBe(['smtp']);

    config(['mail.default' => 'outer', 'mail.driver' => 'smtp']);
    expect(ReaderMail::chain())->toBe(['smtp']);
});
