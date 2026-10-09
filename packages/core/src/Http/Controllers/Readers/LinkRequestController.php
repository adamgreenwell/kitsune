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

use Illuminate\Support\Timebox;
use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderForm;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderMail;
use Kitsune\Core\Readers\ReaderPage;
use Kitsune\Core\Readers\ReaderThrottle;
use Kitsune\Core\Readers\ReaderTokens;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;

/**
 * Someone asks for a link by email — to create an account (`/account/register`, mode `open`) or to choose a new password
 * (`/account/recover`, mode `sign-in` or `open`). ADR-037, reader accounts' second part, as built.
 *
 * The POST, in this order: the body's token (419) → T3, per connection (429) → the floor's mail rule (503, before
 * anything about the address is read) → an address the grammar accepts (422, in place) → inside a 200 ms timebox: T4,
 * per org and address (silent) → the org's reader by that address → the same 303, with the same flash, whatever
 * happened → after the response, a link minted and the mail sent.
 *
 * ⚠️ ONE ANSWER, WHATEVER THE ADDRESS. A new address, one with an account, one with no password and one over T4 get the
 * same status, `Location`, headers and flash; the link and the mail are made after the response, so no branch writes
 * before it, and the reads before it run inside a `Timebox` that never returns early, so the time the answer takes says
 * nothing either. Sign-up mails an address that
 * already has an account a note saying so; recovery mails an address with no account a note saying so (Adam,
 * 2026-10-09) — so every request that passes T4 sends exactly one mail.
 *
 * ⚠️ NO ACCOUNT IS MADE HERE (Adam, 2026-10-07). An account exists only when its link is used and a password chosen.
 *
 * ⚠️ NOTHING IS AUDITED AND NOTHING IS LOGGED: readers stay out of audit rows (ADR-040).
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class LinkRequestController
{
    use ReadsWrittenKeys;

    /** What the work behind the answer is padded to, in microseconds — longer than a mint and a lookup on the floor. */
    public const TIMEBOX = 200_000;

    public function __construct(
        private readonly Context $context,
        private readonly ReaderAccounts $accounts,
        private readonly ReaderTokens $tokens,
    ) {}

    public function showRegister(Request $request): Response
    {
        return $this->show($request, ReaderTokens::REGISTER);
    }

    public function storeRegister(Request $request): Response|RedirectResponse
    {
        return $this->store($request, ReaderTokens::REGISTER);
    }

    public function showRecover(Request $request): Response
    {
        return $this->show($request, ReaderTokens::RECOVER);
    }

    public function storeRecover(Request $request): Response|RedirectResponse
    {
        return $this->store($request, ReaderTokens::RECOVER);
    }

    private function show(Request $request, string $purpose): Response
    {
        $site = $this->site();

        if (($closed = $this->closed($site, $purpose)) !== null) {
            return $closed;
        }

        if ($request->session()->get(SignInController::STATUS) === 'sent.'.$purpose) {
            return ReaderPage::render($site, 'sent', 'sent.title', [
                'message' => 'sent.'.$purpose,
                'minutes' => ReaderTokens::LIFETIME_MINUTES,
                // T4's gap: asked again sooner, the same address is sent nothing.
                'gap' => intdiv(ReaderThrottle::MAIL_GAP, 60),
                'again' => ReaderLinks::path($site, self::page($purpose)),
            ]);
        }

        return $this->form($site, $purpose);
    }

    private function store(Request $request, string $purpose): Response|RedirectResponse
    {
        $typed = ReaderForm::field($request, 'email') ?? '';
        $site = $this->site();

        abort_unless(ReaderForm::tokenMatches($request), 419);

        if (($refusal = ReaderThrottle::linkRequestRefusal($request->ip())) !== null) {
            [$key, $hours] = $refusal;

            return ReaderPage::render($site, 'notice', $purpose.'.title', [
                'message' => $key,
                'replace' => $hours !== null ? ['count' => $hours] : [],
            ], 429);
        }

        if (($closed = $this->closed($site, $purpose)) !== null) {
            return $closed;
        }

        $email = EmailAddress::normalise($typed);

        if ($email === null) {
            return $this->form($site, $purpose, $typed, ['email' => ['field.email', []]], 422);
        }

        $orgId = (int) $this->context->orgId();

        // ⚠️ NEVER `returnEarly()`: the remainder is slept on every branch, so each takes the same time.
        app(Timebox::class)->call(fn () => $this->send($site, $purpose, $orgId, $email), self::TIMEBOX);

        // On every branch, so the work after the response says nothing either.
        defer(static fn () => app(ReaderTokens::class)->sweep($orgId));

        $request->session()->flash(SignInController::STATUS, 'sent.'.$purpose);

        return new RedirectResponse(ReaderLinks::path($site, self::page($purpose)), 303);
    }

    /**
     * The one mail this request sends — or none, over T4.
     *
     * ⚠️ NOTHING IS WRITTEN BEFORE THE RESPONSE. The mail is made after it, and a link with it, so every branch reads and
     * none writes: measured (M2), a mint that waited on a busy SQLite file made a branch that mints answer slower than one
     * that does not, which would say whether an address has an account. The locale is read now, while it is the site's.
     */
    private function send(Site $site, string $purpose, int $orgId, #[\SensitiveParameter] string $email): void
    {
        if (! ReaderThrottle::mayMail($orgId, $email)) {
            return;
        }

        $model = $this->accounts->model() ?? abort(404);
        $found = ReaderAccounts::mapped(static fn () => $model::findByEmail($email));
        // Fenced on the row's own `org_id` too, as sign-in is, against a model that strips the scope.
        $reader = $found !== null && self::writtenKey($found->getAttributes()['org_id'] ?? null) === $orgId ? $found : null;
        $key = $reader !== null ? app(ReaderGuard::class)->key($reader->getAuthIdentifier()) : null;
        $locale = ReaderPage::copyLocale();

        ReaderMail::defer($email, match (true) {
            $purpose === ReaderTokens::REGISTER && $reader === null => fn (): ?ReaderMail => $this->linkMail($site, $locale, 'finish', ReaderTokens::REGISTER, $email, ReaderLinks::COMPLETE),
            $purpose === ReaderTokens::REGISTER => fn (): ReaderMail => $this->mail($site, $locale, 'already', [
                'sign_in' => (string) ReaderLinks::absolute($site, ReaderLinks::SIGN_IN),
                'recover' => (string) ReaderLinks::absolute($site, ReaderLinks::RECOVER),
            ]),
            // A key the guard cannot spell is no subject to mint for: nothing is sent, rather than a note that no account exists.
            $reader !== null => fn (): ?ReaderMail => $key === null ? null : $this->linkMail($site, $locale, 'reset', ReaderTokens::RECOVER, $key, ReaderLinks::RESET),
            default => fn (): ReaderMail => $this->mail($site, $locale, 'none'),
        });
    }

    /** A mail carrying a new link — or null when a simultaneous request minted this subject's link first. */
    private function linkMail(Site $site, string $locale, string $mail, string $purpose, #[\SensitiveParameter] string $subject, string $page): ?ReaderMail
    {
        $minted = $this->tokens->mint($purpose, $subject);

        if ($minted === null) {
            return null;
        }

        return $this->mail($site, $locale, $mail, [
            'link' => (string) ReaderLinks::absolute($site, $page, $minted),
            'minutes' => ReaderTokens::LIFETIME_MINUTES,
        ]);
    }

    /**
     * One mail, in the copy's locale.
     *
     * @param  array<string, int|string>  $replace
     */
    private function mail(Site $site, string $locale, string $name, #[\SensitiveParameter] array $replace = []): ReaderMail
    {
        $t = ReaderPage::translator($locale);
        $replace = ['site' => $site->name, ...$replace];

        return new ReaderMail($t("mail.{$name}.subject", $replace), $t("mail.{$name}.body", $replace), (string) $site->name);
    }

    /** The 503 of a site that cannot mail a link, or null. Asked before anything about the address is read. */
    private function closed(Site $site, string $purpose): ?Response
    {
        if (ReaderMail::fault() === null && ReaderLinks::origin($site) !== null) {
            return null;
        }

        return ReaderPage::render($site, 'notice', $purpose.'.title', [
            'message' => $purpose.'.closed',
            'next' => [ReaderLinks::path($site, ReaderLinks::SIGN_IN), 'link.sign_in'],
        ], 503);
    }

    /** @param  array<string, array{0: string, 1: array<string, int|string>}>  $errors */
    private function form(Site $site, string $purpose, #[\SensitiveParameter] string $email = '', array $errors = [], int $code = 200): Response
    {
        return ReaderPage::render($site, 'link-request', $purpose.'.title', [
            'action' => ReaderLinks::path($site, self::page($purpose)),
            'intro' => $purpose.'.intro',
            'email' => $email,
            'problems' => $errors,
            'signIn' => ReaderLinks::path($site, ReaderLinks::SIGN_IN),
        ], $code);
    }

    private static function page(string $purpose): string
    {
        return $purpose === ReaderTokens::REGISTER ? ReaderLinks::REGISTER : ReaderLinks::RECOVER;
    }

    private function site(): Site
    {
        return $this->context->site() ?? abort(404);
    }
}
