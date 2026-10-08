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
use Illuminate\Support\Facades\Hash;
use Kitsune\Core\Auth\EmailAddress;
use Kitsune\Core\Http\Middleware\RequireReader;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderForm;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderPage;
use Kitsune\Core\Readers\ReaderSessions;
use Kitsune\Core\Readers\ReaderThrottle;
use Kitsune\Core\Tenancy\Concerns\ReadsWrittenKeys;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * A reader signs in to a site — ADR-037, as built. `GET|POST {prefix}/account/sign-in`.
 *
 * The POST, in this order: the body's token (419) → T1, per IP, counted on every POST (429, nothing checked) → a
 * password at all → an address the grammar accepts (else the generic failure, and no query) → T2, per org and address
 * (422) → the org's reader by that address → one hash check → signed in, or the generic failure.
 *
 * ⚠️ ONE ANSWER FOR AN UNKNOWN ADDRESS, A WRONG PASSWORD AND A READER WITH NO PASSWORD — the same status, body and
 * headers, and the same cost: exactly one hash check on every path, against the reader's hash or against
 * `ReaderSessions::timingHash()`, a real hash at the configured cost. `attempt()` is not used: it skips the hash for an
 * unknown address. ⚠️ A NAMED RESIDUAL: after the operator raises the cost, a reader who has not signed in since still
 * holds a hash at the old cost, so a wrong password for their address answers faster than an unknown one until their
 * next sign-in rehashes it.
 *
 * ⚠️ A REFUSAL IS RENDERED IN PLACE, NOT REDIRECTED. A redirect would flash the typed address into the session store; the
 * form is re-filled from this request alone, and the password is never echoed.
 *
 * ⚠️ NOTHING IS AUDITED AND NOTHING IS LOGGED: readers stay out of audit rows (ADR-040).
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class SignInController
{
    use ReadsWrittenKeys;

    /** The session key a one-time status message (`sign_out.done`) is flashed under. */
    public const STATUS = 'kitsune.readers.status';

    public function __construct(
        private readonly Context $context,
        private readonly ReaderSessions $sessions,
    ) {}

    public function show(Request $request): Response
    {
        $status = $request->session()->get(self::STATUS);

        return $this->form($this->site(), status: is_string($status) && $status === 'sign_out.done' ? $status : null);
    }

    public function store(Request $request): Response|RedirectResponse
    {
        $password = ReaderForm::takePassword($request);
        $typed = ReaderForm::field($request, 'email') ?? '';
        $site = $this->site();

        abort_unless(ReaderForm::tokenMatches($request), 419);

        if (ReaderThrottle::signInFromIpRefused($request->ip())) {
            return ReaderPage::render($site, 'notice', 'sign_in.title', ['message' => 'throttle.ip'], 429);
        }

        if ($password === null || $password === '') {
            return $this->form($site, $typed, errors: ['password' => ['field.password_missing', []]], status: null, code: 422);
        }

        $email = EmailAddress::normalise($typed);

        if ($email === null) {
            return $this->failed($site, $typed);
        }

        $orgId = (int) $this->context->orgId();

        if (($minutes = ReaderThrottle::accountAttempt($orgId, $email)) !== null) {
            return $this->form($site, $typed, errors: ['email' => ['throttle.account', ['count' => $minutes]]], status: null, code: 422);
        }

        $model = app(ReaderAccounts::class)->model() ?? abort(404);
        $found = ReaderAccounts::mapped(static fn () => $model::findByEmail($email));
        // Fenced on the row's own `org_id` too, as `ReaderGuard::current()` is, against a model that strips the scope.
        $reader = $found !== null && self::writtenKey($found->getAttributes()['org_id'] ?? null) === $orgId ? $found : null;

        $stored = $reader?->getAuthPassword();
        $hash = is_string($stored) && $stored !== '' ? $stored : ReaderSessions::timingHash();

        try {
            $matches = Hash::check($password, $hash);
        } catch (RuntimeException) {
            /*
             * ⚠️ A HASH MADE BY ANOTHER DRIVER (review): after a switch from bcrypt to argon2id, Laravel refuses to
             * check a bcrypt hash before hashing anything. That was a 500 for every existing reader and the generic
             * answer for an unknown address — an oracle. Now it is the generic answer too, at the cost of one hash.
             */
            Hash::check($password, ReaderSessions::timingHash());
            $matches = false;
        }

        if (! $matches || $reader === null || $hash !== $stored) {
            return $this->failed($site, $typed);
        }

        if (Hash::needsRehash($stored)) {
            $rehashed = Hash::make($password);

            ReaderAccounts::mapped(static fn () => $reader->forceFill([$reader->getAuthPasswordName() => $rehashed])->save());
        }

        $this->sessions->signIn($reader);
        ReaderThrottle::accountCleared($orgId, $email);

        $intended = $request->session()->pull(RequireReader::INTENDED);

        return new RedirectResponse(
            is_string($intended) && ReaderLinks::isOwn($site, $intended) ? $intended : ReaderLinks::path($site),
            303,
        );
    }

    private function failed(Site $site, #[\SensitiveParameter] string $typed): Response
    {
        return $this->form($site, $typed, errors: ['email' => ['sign_in.failed', []]], status: null, code: 422);
    }

    /** @param  array<string, array{0: string, 1: array<string, int|string>}>  $errors  field => [copy key, replacements] */
    private function form(Site $site, #[\SensitiveParameter] string $email = '', array $errors = [], ?string $status = null, int $code = 200): Response
    {
        return ReaderPage::render($site, 'sign-in', 'sign_in.title', [
            'action' => ReaderLinks::path($site, ReaderLinks::SIGN_IN),
            'email' => $email,
            'problems' => $errors,
            'status' => $status,
        ], $code);
    }

    private function site(): Site
    {
        return $this->context->site() ?? abort(404);
    }
}
