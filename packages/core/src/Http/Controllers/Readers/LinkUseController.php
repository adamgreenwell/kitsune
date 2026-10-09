<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Controllers\Readers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Auth\PasswordRefusal;
use Kitsune\Core\Auth\PasswordRules;
use Kitsune\Core\Http\Middleware\ReaderArea;
use Kitsune\Core\Models\ReaderToken;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderExport;
use Kitsune\Core\Readers\ReaderForm;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderLinkUse;
use Kitsune\Core\Readers\ReaderPage;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Readers\ReaderThrottle;
use Kitsune\Core\Readers\ReaderTokens;
use Kitsune\Core\Tenancy\Context;

/**
 * A mailed link, used — to finish a sign-up (`/account/register/complete`, mode `open`) or to choose a new password
 * (`/account/reset`). ADR-037, reader accounts' second part, as built.
 *
 * ⚠️ THE SECRET LEAVES THE ADDRESS AT ONCE. `ReaderArea` takes the query off the request before anything can refuse it;
 * the link's GET puts the secret's hash in the session, under this purpose and site and a new session id, and answers
 * 303 to the same page with no query — so the secret is in no later `Referer`, history entry, page or session, and the
 * 303 carries `ReaderArea`'s no-referrer and no-store headers. Nothing is used up by a GET: a mail scanner that follows
 * the link changes nothing. The password POST uses the link up.
 *
 * The POST, in this order: the body's token (419) → the stashed link, live on this site (410) → the password, twice
 * (422, in place) → one hash → `ReaderLinkUse`, the link's use and the account's write in one transaction → signed in,
 * or 410 when the link was used meanwhile.
 *
 * ⚠️ NOTHING IS AUDITED AND NOTHING IS LOGGED: readers stay out of audit rows (ADR-040).
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class LinkUseController
{
    /** Where a link's hash waits in the session, between its GET and the password's POST; the purpose and site follow. */
    public const STASH = 'kitsune.readers.link';

    public function __construct(
        private readonly Context $context,
        private readonly ReaderTokens $tokens,
        private readonly ReaderExport $readers,
        private readonly ReaderSessions $sessions,
        private readonly ReaderLinkUse $use,
    ) {}

    public function showComplete(Request $request): Response|RedirectResponse
    {
        return $this->show($request, ReaderTokens::REGISTER);
    }

    public function storeComplete(Request $request): Response|RedirectResponse
    {
        return $this->store($request, ReaderTokens::REGISTER);
    }

    public function showReset(Request $request): Response|RedirectResponse
    {
        return $this->show($request, ReaderTokens::RECOVER);
    }

    public function storeReset(Request $request): Response|RedirectResponse
    {
        return $this->store($request, ReaderTokens::RECOVER);
    }

    private function show(Request $request, string $purpose): Response|RedirectResponse
    {
        $site = $this->site();
        $key = $this->stashKey($purpose);

        // The query is already off the request, the secret's hash in its place (`ReaderArea`).
        if ($request->attributes->has(ReaderArea::QUERY_LINK)) {
            $hash = $request->attributes->get(ReaderArea::QUERY_LINK);

            if (is_string($hash)) {
                // ⚠️ A NEW SESSION ID FIRST (review): an id an attacker planted in this browser would otherwise hold the
                // link, and the attacker could choose its password. `migrate()` keeps a staff session riding along.
                $request->session()->migrate(true);
                $request->session()->put($key, $hash);
            } else {
                $request->session()->forget($key);
            }

            return new RedirectResponse(ReaderLinks::path($site, self::page($purpose)), 303);
        }

        [$token, $email] = $this->stashed($request, $purpose);

        if ($token === null || $email === null) {
            return $this->dead($request, $site, $purpose);
        }

        return $this->form($site, $purpose, $email);
    }

    private function store(Request $request, string $purpose): Response|RedirectResponse
    {
        $password = ReaderForm::take($request, 'password');
        $confirmation = ReaderForm::take($request, 'password_confirmation');
        $site = $this->site();

        abort_unless(ReaderForm::tokenMatches($request), 419);

        [$token, $email] = $this->stashed($request, $purpose);

        if ($token === null || $email === null) {
            return $this->dead($request, $site, $purpose);
        }

        if (($problems = self::problems($password, $confirmation, $email)) !== []) {
            return $this->form($site, $purpose, $email, $problems, 422);
        }

        $hash = Hash::make((string) $password);
        $orgId = (int) $this->context->orgId();

        if ($purpose === ReaderTokens::REGISTER) {
            [$outcome, $reader] = $this->use->complete($token, $hash);

            if ($outcome === ReaderLinkUse::EXISTS) {
                $request->session()->forget($this->stashKey($purpose));
                $request->session()->flash(SignInController::STATUS, 'complete.exists');

                return new RedirectResponse(ReaderLinks::path($site, ReaderLinks::SIGN_IN), 303);
            }
        } else {
            $reader = $this->use->reset($token, $hash);
        }

        if ($reader === null) {
            return $this->dead($request, $site, $purpose);
        }

        // The instance written, so this session holds the new password's binding and every other session fails it.
        $this->sessions->signIn($reader);
        // Under the address as sign-in counts it: a host's model may hand it back in another case (review).
        ReaderThrottle::accountCleared($orgId, EmailAddress::normalise($email) ?? $email);
        $request->session()->forget($this->stashKey($purpose));
        $request->session()->flash(SignInController::STATUS, $purpose === ReaderTokens::REGISTER ? 'complete.done' : 'reset.done');
        defer(static fn () => app(ReaderTokens::class)->sweep($orgId));

        return new RedirectResponse(ReaderLinks::path($site), 303);
    }

    /**
     * The stashed link, live on this site, and the address it is for — or nulls.
     *
     * @return array{0: ?ReaderToken, 1: ?string}
     */
    private function stashed(Request $request, string $purpose): array
    {
        $hash = $request->session()->get($this->stashKey($purpose));
        $token = is_string($hash) ? $this->tokens->live($purpose, $hash) : null;

        if ($token === null) {
            return [null, null];
        }

        $email = $purpose === ReaderTokens::REGISTER ? $token->subject : $this->readers->find($token->subject)?->readerEmail();

        return [$token, $email];
    }

    /**
     * What is wrong with the password chosen, as field => [copy key, replacements]; empty when nothing is.
     *
     * @return array<string, array{0: string, 1: array<string, int|string>}>
     */
    private static function problems(#[\SensitiveParameter] ?string $password, #[\SensitiveParameter] ?string $confirmation, #[\SensitiveParameter] string $email): array
    {
        if ($password === null || $password === '') {
            return ['password' => ['password.missing', []]];
        }

        $refusal = PasswordRules::refusal($password, $email);

        if ($refusal !== null) {
            return ['password' => match ($refusal) {
                PasswordRefusal::Short => ['password.short', ['min' => PasswordRules::minCharacters()]],
                PasswordRefusal::Long => ['password.long', ['max' => PasswordRules::MAX_BYTES]],
                PasswordRefusal::Edges => ['password.edges', []],
                PasswordRefusal::Encoding, PasswordRefusal::Control => ['password.unsendable', []],
                PasswordRefusal::IsEmail => ['password.is_email', []],
            }];
        }

        // Typed twice, always (Adam, 2026-10-09).
        if ($confirmation === null || $confirmation === '') {
            return ['password_confirmation' => ['password.confirm_missing', []]];
        }

        return hash_equals($password, $confirmation) ? [] : ['password_confirmation' => ['password.mismatch', []]];
    }

    /** A link that does not work: 410, with a way to ask for another. The stash goes. */
    private function dead(Request $request, Site $site, string $purpose): Response
    {
        $request->session()->forget($this->stashKey($purpose));

        return ReaderPage::render($site, 'notice', $purpose === ReaderTokens::REGISTER ? 'complete.title' : 'reset.title', [
            'message' => 'link.dead',
            'replace' => ['minutes' => ReaderTokens::LIFETIME_MINUTES],
            'next' => [ReaderLinks::path($site, $purpose === ReaderTokens::REGISTER ? ReaderLinks::REGISTER : ReaderLinks::RECOVER), 'link.again'],
        ], 410);
    }

    /** @param  array<string, array{0: string, 1: array<string, int|string>}>  $errors */
    private function form(Site $site, string $purpose, #[\SensitiveParameter] string $email, array $errors = [], int $code = 200): Response
    {
        $register = $purpose === ReaderTokens::REGISTER;

        return ReaderPage::render($site, 'password', $register ? 'complete.title' : 'reset.title', [
            'action' => ReaderLinks::path($site, self::page($purpose)),
            'for' => $register ? 'complete.for' : 'reset.for',
            'button' => $register ? 'complete.button' : 'reset.button',
            'email' => $email,
            'min' => PasswordRules::minCharacters(),
            'problems' => $errors,
        ], $code);
    }

    private function stashKey(string $purpose): string
    {
        return self::STASH.'.'.$purpose.'.'.(int) $this->context->siteId();
    }

    private static function page(string $purpose): string
    {
        return $purpose === ReaderTokens::REGISTER ? ReaderLinks::COMPLETE : ReaderLinks::RESET;
    }

    private function site(): Site
    {
        return $this->context->site() ?? abort(404);
    }
}
