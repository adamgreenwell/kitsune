<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderMailbox;

/*
 * How a reader page renders — ADR-037, as built: the site's language on `<html>`, the copy's on `<main>`, forms a
 * keyboard and a screen reader can use, nothing off-host, no script, and nothing stored rendered as markup.
 */

beforeEach(function (): void {
    $this->world = ReaderFixture::world();
    ReaderFixture::mode($this->world['golfdom'], 'sign-in');
    ReaderFixture::mode($this->world['rival'], 'sign-in');
});

/** The page, as a DOM to ask. */
function pagesDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML($html);

    return new DOMXPath($document);
}

it('puts the site\'s language on the document and the copy\'s on the page\'s words', function (): void {
    ReaderFixture::site($this->world['golfdom'], 'golfdom-ar', 'Golfdom AR', '/golfdom-ar', 'ar');

    $fr = $this->readerGet('/golfdom-fr/account/sign-in')->assertOk()->getContent();
    $ar = $this->readerGet('/golfdom-ar/account/sign-in')->assertOk()->getContent();

    expect($fr)->toContain('<html lang="fr" dir="ltr">')->toContain('<main lang="en" dir="ltr">')
        ->and($ar)->toContain('<html lang="ar" dir="rtl">')->toContain('<main lang="en" dir="ltr">');
});

it('follows the site\'s language once core\'s copy exists in it', function (): void {
    ReaderFixture::site($this->world['golfdom'], 'golfdom-ar', 'Golfdom AR', '/golfdom-ar', 'ar');
    app('translator')->addLines(['readers.sign_in.title' => 'تسجيل الدخول'], 'ar', 'kitsune');

    $this->readerGet('/golfdom-ar/account/sign-in')
        ->assertOk()
        ->assertSee('<main lang="ar" dir="rtl">', false)
        ->assertSee('<title>تسجيل الدخول — Golfdom AR</title>', false);
});

it('labels every field, and says what each one is for to a password manager', function (): void {
    $dom = pagesDom((string) $this->readerGet('/golfdom/account/sign-in')->getContent());

    $email = $dom->query('//input[@id="email"]')->item(0);
    $password = $dom->query('//input[@id="password"]')->item(0);

    expect($dom->query('//label[@for="email"]')->item(0)?->textContent)->toBe('Email address')
        ->and($dom->query('//label[@for="password"]')->item(0)?->textContent)->toBe('Password')
        ->and($email?->getAttribute('type'))->toBe('email')
        ->and($email?->getAttribute('autocomplete'))->toBe('email')
        ->and($email?->getAttribute('inputmode'))->toBe('email')
        ->and($email?->getAttribute('dir'))->toBe('ltr')
        ->and($email?->getAttribute('autocapitalize'))->toBe('none')
        ->and($email?->getAttribute('spellcheck'))->toBe('false')
        ->and($email?->hasAttribute('maxlength'))->toBeFalse()
        ->and($password?->getAttribute('type'))->toBe('password')
        ->and($password?->getAttribute('autocomplete'))->toBe('current-password')
        ->and($dom->query('//form[@method="post"][@action="/golfdom/account/sign-in"][@novalidate]')->length)->toBe(1)
        ->and($dom->query('//form//input[@type="hidden"][@name="_token"]')->length)->toBe(1)
        ->and($dom->query('//main')->length)->toBe(1)
        ->and($dom->query('//h1')->length)->toBe(1)
        ->and($dom->query('//h1')->item(0)?->textContent)->toBe('Sign in')
        ->and($dom->query('//title')->item(0)?->textContent)->toBe('Sign in — Golfdom');
});

it('names a refusal in the title, in a summary that takes focus, and on the field it is about', function (): void {
    $dom = pagesDom((string) $this->readerPost('/golfdom/account/sign-in', ['email' => 'nobody@kitsune.test', 'password' => 'not-the-password-at-all'])->getContent());

    $summary = $dom->query('//div[@class="summary"]')->item(0);
    $email = $dom->query('//input[@id="email"]')->item(0);

    expect($dom->query('//title')->item(0)?->textContent)->toBe('Error: Sign in — Golfdom')
        ->and($summary?->getAttribute('role'))->toBe('alert')
        ->and($summary?->getAttribute('tabindex'))->toBe('-1')
        ->and($summary?->hasAttribute('autofocus'))->toBeTrue()
        ->and($dom->query('//div[@class="summary"]/h2')->item(0)?->textContent)->toBe('There is a problem')
        ->and($dom->query('//div[@class="summary"]//a[@href="#email"]')->item(0)?->textContent)
        ->toBe('That email address and password don\'t match an account here. Check both and try again.')
        ->and($email?->getAttribute('aria-invalid'))->toBe('true')
        ->and($email?->getAttribute('aria-describedby'))->toBe('email-error')
        ->and(trim((string) $dom->query('//p[@id="email-error"]')->item(0)?->textContent))
        ->toBe('Error: That email address and password don\'t match an account here. Check both and try again.')
        ->and($dom->query('//input[@id="password"][@aria-invalid]')->length)->toBe(0);
});

it('puts a missing password on the password field', function (): void {
    $dom = pagesDom((string) $this->readerPost('/golfdom/account/sign-in', ['email' => 'nobody@kitsune.test'])->getContent());

    expect($dom->query('//div[@class="summary"]//a[@href="#password"]')->item(0)?->textContent)->toBe('Enter your password.')
        ->and($dom->query('//input[@id="password"]')->item(0)?->getAttribute('aria-describedby'))->toBe('password-error')
        ->and($dom->query('//input[@id="email"][@aria-invalid]')->length)->toBe(0);
});

it('loads nothing from anywhere and runs no script', function (): void {
    ReaderFixture::signIn(ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test'));
    $account = (string) $this->readerGet('/golfdom/account')->assertOk()->getContent();
    ReaderFixture::forget();
    app('session.store')->flush();
    $signIn = (string) $this->readerGet('/golfdom/account/sign-in')->assertOk()->getContent();

    foreach ([$account, $signIn] as $html) {
        expect($html)->not->toContain('<script')
            ->and($html)->not->toContain('<link')
            ->and($html)->not->toContain('<img')
            ->and($html)->not->toContain('http://')
            ->and($html)->not->toContain('https://')
            ->and($html)->not->toContain('src=');
    }
});

it('shows the account page\'s address as text, and offers a way out', function (): void {
    ReaderFixture::signIn(ReaderFixture::reader($this->world['golfdom'], "o'brien+golf@kitsune.test"));

    $response = $this->readerGet('/golfdom/account')->assertOk();
    $dom = pagesDom((string) $response->getContent());

    $response->assertSee('Signed in as o&#039;brien+golf@kitsune.test.', false);
    expect($dom->query('//h1')->item(0)?->textContent)->toBe('Your account')
        ->and($dom->query('//form[@method="post"][@action="/golfdom/account/sign-out"]//button')->item(0)?->textContent)->toBe('Sign out')
        ->and($dom->query('//form[@action="/golfdom/account/sign-out"]//input[@name="_token"]')->length)->toBe(1);
});

it('renders a site\'s name as text wherever it appears', function (): void {
    $site = $this->world['sites']['golfdom'];
    $site->name = '<b onmouseover="x()">Golfdom</b>';
    $site->save();

    $html = (string) $this->readerGet('/golfdom/account/sign-in')->assertOk()->getContent();

    expect($html)->not->toContain('<b onmouseover')
        ->and($html)->toContain('&lt;b onmouseover=&quot;x()&quot;&gt;Golfdom&lt;/b&gt;')
        ->and(substr_count($html, '&lt;b onmouseover'))->toBe(3);
});

it('links back to the site, by its own path', function (): void {
    $this->readerGet('/golfdom/account/sign-in')->assertSee('<p class="back"><a href="/golfdom">Back to Golfdom</a></p>', false);
    $this->readerGet('https://rival.test/shop/account/sign-in')->assertSee('<a href="/shop">Back to Rival Shop</a>', false);
});

it('renders a refused connection as a notice, in the layout, with nothing to submit', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->readerPost('/golfdom/account/sign-in', ['email' => 'nobody@kitsune.test', 'password' => 'not-the-password-at-all']);
    }

    $dom = pagesDom((string) $this->readerPost('/golfdom/account/sign-in', ['email' => 'nobody@kitsune.test', 'password' => 'x'])->assertStatus(429)->getContent());

    expect($dom->query('//h1')->item(0)?->textContent)->toBe('Sign in')
        ->and($dom->query('//main/p[@role="alert"]')->item(0)?->textContent)
        ->toBe('Too many attempts from your connection. Please wait a minute, then try again. Nothing was checked or sent.')
        ->and($dom->query('//form')->length)->toBe(0);
});

// ---- Sign-up and recovery -------------------------------------------------------------------------------------------

it('offers recovery on the sign-in page, and sign-up only where accounts are open — after the button', function (): void {
    $signIn = pagesDom((string) $this->readerGet('/golfdom/account/sign-in')->getContent());

    expect(array_map(static fn (DOMElement $a): array => [$a->getAttribute('href'), $a->textContent], iterator_to_array($signIn->query('//form/following-sibling::ul[@class="links"]//a'))))
        ->toBe([['/golfdom/account/recover', 'Forgotten your password?']]);

    ReaderFixture::mode($this->world['golfdom'], 'open');
    $open = pagesDom((string) $this->readerGet('/golfdom/account/sign-in')->getContent());

    expect(array_map(static fn (DOMElement $a): array => [$a->getAttribute('href'), $a->textContent], iterator_to_array($open->query('//form/following-sibling::ul[@class="links"]//a'))))
        ->toBe([['/golfdom/account/recover', 'Forgotten your password?'], ['/golfdom/account/register', 'Create an account']])
        ->and($open->query('//form//a')->length)->toBe(0);
});

it('asks for an address on the sign-up and recovery pages as sign-in does', function (string $path, string $title): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    $dom = pagesDom((string) $this->readerGet($path)->assertOk()->getContent());
    $email = $dom->query('//input[@id="email"]')->item(0);

    expect($dom->query('//title')->item(0)?->textContent)->toBe($title.' — Golfdom')
        ->and($dom->query('//h1')->item(0)?->textContent)->toBe($title)
        ->and($dom->query('//label[@for="email"]')->item(0)?->textContent)->toBe('Email address')
        ->and($email?->getAttribute('type'))->toBe('email')
        ->and($email?->getAttribute('autocomplete'))->toBe('email')
        ->and($email?->getAttribute('dir'))->toBe('ltr')
        ->and($dom->query('//input[@type="password"]')->length)->toBe(0)
        ->and($dom->query('//form[@method="post"][@action="'.$path.'"][@novalidate]')->length)->toBe(1)
        ->and($dom->query('//form//input[@type="hidden"][@name="_token"]')->length)->toBe(1)
        ->and($dom->query('//button[@type="submit"]')->item(0)?->textContent)->toBe('Send me a link')
        ->and($dom->query('//a[@href="/golfdom/account/sign-in"]')->item(0)?->textContent)->toBe('Go to sign in');
})->with([
    'sign-up' => ['/golfdom/account/register', 'Create an account'],
    'recovery' => ['/golfdom/account/recover', 'Forgotten your password?'],
]);

it('re-fills a refused address as text, never as markup', function (): void {
    $typed = '"><script>alert(1)</script>';
    $html = (string) $this->readerPost('/golfdom/account/recover', ['email' => $typed])->assertStatus(422)->getContent();
    $dom = pagesDom($html);

    expect($html)->not->toContain('<script>')
        ->and($dom->query('//input[@id="email"]')->item(0)?->getAttribute('value'))->toBe($typed)
        ->and($dom->query('//input[@id="email"]')->item(0)?->getAttribute('aria-describedby'))->toBe('email-error')
        ->and($dom->query('//div[@class="summary"]//a[@href="#email"]')->item(0)?->textContent)->toBe('Enter an email address, like name@example.com.');
});

it('asks for the password twice, under the address a password manager saves it for', function (): void {
    ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $this->readerGet((string) ReaderMailbox::link())->assertStatus(303);

    $dom = pagesDom((string) $this->readerGet('/golfdom/account/reset')->assertOk()->getContent());
    $username = $dom->query('//input[@id="username"]')->item(0);
    $password = $dom->query('//input[@id="password"]')->item(0);
    $again = $dom->query('//input[@id="password_confirmation"]')->item(0);

    expect($username?->getAttribute('autocomplete'))->toBe('username')
        ->and($username?->getAttribute('value'))->toBe('subscriber@kitsune.test')
        ->and($username?->hasAttribute('readonly'))->toBeTrue()
        ->and($dom->query('//label[@for="username"]')->item(0)?->textContent)->toBe('Account')
        ->and($dom->query('//label[@for="password"]')->item(0)?->textContent)->toBe('New password')
        ->and($dom->query('//label[@for="password_confirmation"]')->item(0)?->textContent)->toBe('Type the same password again')
        ->and($password?->getAttribute('type'))->toBe('password')
        ->and($password?->getAttribute('autocomplete'))->toBe('new-password')
        ->and($password?->getAttribute('aria-describedby'))->toBe('password-hint')
        ->and($dom->query('//p[@id="password-hint"]')->item(0)?->textContent)->toBe('At least 15 characters. A few words in a row is easy to remember and hard to guess.')
        ->and($again?->getAttribute('type'))->toBe('password')
        ->and($again?->getAttribute('autocomplete'))->toBe('new-password')
        ->and($again?->hasAttribute('aria-describedby'))->toBeFalse()
        ->and($dom->query('//form[@method="post"][@action="/golfdom/account/reset"]')->length)->toBe(1)
        ->and($dom->query('//input[@type="password"][@value]')->length)->toBe(0)
        ->and($dom->query('//button[@type="submit"]')->item(0)?->textContent)->toBe('Change my password');

    $refused = pagesDom((string) $this->readerPost('/golfdom/account/reset', ['password' => 'a different long password', 'password_confirmation' => 'something else'])->assertStatus(422)->getContent());

    expect($refused->query('//input[@id="password"]')->item(0)?->getAttribute('aria-describedby'))->toBe('password-hint')
        ->and($refused->query('//input[@id="password_confirmation"]')->item(0)?->getAttribute('aria-describedby'))->toBe('password_confirmation-error')
        ->and($refused->query('//input[@id="password_confirmation"]')->item(0)?->getAttribute('aria-invalid'))->toBe('true')
        ->and($refused->query('//input[@type="password"][@value]')->length)->toBe(0)
        ->and($refused->query('//div[@class="summary"]//a[@href="#password_confirmation"]')->item(0)?->textContent)->toBe('The two passwords don\'t match.');
});

it('says the minimum configured in the hint', function (): void {
    config(['kitsune.passwords.min_characters' => 20]);
    ReaderFixture::reader($this->world['golfdom'], 'subscriber@kitsune.test');
    $this->readerPost('/golfdom/account/recover', ['email' => 'subscriber@kitsune.test'])->assertStatus(303);
    $this->readerGet((string) ReaderMailbox::link())->assertStatus(303);

    $this->readerGet('/golfdom/account/reset')->assertOk()->assertSeeText('At least 20 characters.');
});

it('shows the address a link is for as text, never as markup', function (): void {
    ReaderFixture::mode($this->world['golfdom'], 'open');
    // The grammar admits an apostrophe and an ampersand; nothing else that markup cares about.
    $this->readerPost('/golfdom/account/register', ['email' => "o'neil&co@kitsune.test"])->assertStatus(303);
    $this->readerGet((string) ReaderMailbox::link())->assertStatus(303);

    $html = (string) $this->readerGet('/golfdom/account/register/complete')->assertOk()->getContent();

    expect($html)->toContain('o&#039;neil&amp;co@kitsune.test')->not->toContain("o'neil&co");
});
