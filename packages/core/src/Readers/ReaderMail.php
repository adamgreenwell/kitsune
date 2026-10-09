<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifications;
use Throwable;

/**
 * One email to a reader — a link to finish signing up or choose a new password, or a note that says why none came —
 * and the floor's mail rule. ADR-037, reader accounts' second part, as built.
 *
 * ⚠️ THE FLOOR'S MAIL RULE (`fault()`). The floor's mailer is `log`, which writes mail into a file nobody reads; a sign-up
 * or recovery page that answered "check your email" there would be a lie. Outside `local` and `testing`, a mailer that
 * keeps mail instead of sending it — `log`, `array`, a failover or round robin with one of them in it, one that is not
 * defined — or the placeholder sender, or one at a domain reserved for examples, closes both pages with a 503 that says
 * so. Sign-in, the account page, a link
 * already mailed, export and erasure never ask. ADR-027's floor is untouched: onboarding and sign-in need no mail.
 *
 * ⚠️ AFTER THE RESPONSE, AND NEVER IN A QUEUE. `defer()` sends once the response has gone, so the time a mail takes is
 * not in the time a request takes — the request's answer is the same for an address with an account and one without.
 * Not `ShouldQueue`: the floor's queue is `sync`, which would send inside the request, and a queue would carry the
 * secret into the jobs table. `defer()` runs only for a response below 400, so no refusal sends anything.
 *
 * ⚠️ ONE LOG LINE, AND IT NAMES ONLY THE MAILER. A mail that cannot be made or sent is caught here — the response has
 * gone, so nobody else could hear — and logged without the address, the link or the error, whose message can carry
 * both.
 *
 * ⚠️ FROM THE SITE'S NAME, ON THE INSTALLATION'S ADDRESS (Adam, 2026-10-09). A named residual: a site's name is its
 * organisation's own text, so on a shared installation one organisation could name its site to look like another's.
 * Symfony's `Address` strips a line break from a name, so a name cannot add a header.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class ReaderMail extends Notification
{
    /** The address Laravel's own `config/mail.php` ships with: mail from it is mail nobody set up. */
    public const PLACEHOLDER_FROM = 'hello@example.com';

    /** RFC 2606's second-level domains for examples; its top-level ones are matched by suffix. */
    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    /** The transports that keep mail instead of sending it. */
    private const KEEPING = ['log', 'array'];

    /** The text, which holds the link: out of every stack trace, so not a promoted property. */
    public readonly string $body;

    /** The one mailer this copy goes through, or null for the default (`through()`). */
    private ?string $through = null;

    public function __construct(
        public readonly string $title,
        #[\SensitiveParameter] string $body,
        public readonly string $fromName,
    ) {
        $this->body = $body;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->mailer($this->through)
            ->from((string) config('mail.from.address'), $this->fromName)
            ->subject($this->title)
            ->text('kitsune::readers.mail', ['body' => $this->body]);
    }

    /** This mail, to go through one mailer by name. */
    public function through(string $mailer): self
    {
        $copy = clone $this;
        $copy->through = $mailer;

        return $copy;
    }

    /**
     * Why no reader mail can be sent here, in words — or null when it can. Asked before a sign-up or recovery page
     * reads anything about an address.
     */
    public static function fault(): ?string
    {
        // Laravel's own kernel in every application since 11, which `bootstrap/app.php` binds.
        if (! app(HttpKernel::class)->hasMiddleware(InvokeDeferredCallbacks::class)) {
            return 'the HTTP kernel does not run deferred callbacks (InvokeDeferredCallbacks), so a mail would never be sent';
        }

        if (app()->environment(['local', 'testing'])) {
            return null;
        }

        $name = self::mailerName();
        $closed = $name === '' ? 'no default mailer is configured' : self::keeps($name, (bool) config('mail.driver'), 0);

        if ($closed !== null) {
            return $closed;
        }

        $from = config('mail.from.address');

        if (! is_string($from) || trim($from) === '') {
            return 'no sender address is configured (MAIL_FROM_ADDRESS)';
        }

        if (strtolower(trim($from)) === self::PLACEHOLDER_FROM) {
            return 'the sender address is still Laravel\'s placeholder, '.self::PLACEHOLDER_FROM.' (MAIL_FROM_ADDRESS)';
        }

        // Any other sample address too (review): no mail server delivers for a domain reserved for examples and tests.
        $domain = strtolower((string) substr((string) strrchr(trim($from), '@'), 1));

        if (in_array($domain, self::RESERVED_DOMAINS, true) || preg_match('/(?:^|\.)(?:example|test|invalid|localhost)$/D', $domain) === 1) {
            return "the sender address is at [{$domain}], a domain reserved for examples and tests, which no mail server delivers for (MAIL_FROM_ADDRESS)";
        }

        return null;
    }

    /** The default mailer's name, as Laravel's `MailManager` reads it: the legacy `mail.driver` first. */
    public static function mailerName(): string
    {
        $name = config('mail.driver') ?? config('mail.default');

        return is_string($name) ? $name : '';
    }

    /**
     * Makes a mail and sends it to this address after the response has gone, and swallows any failure into one line.
     *
     * ⚠️ MADE AFTER THE RESPONSE TOO. `$compose` mints the link a mail carries, so no branch of a request writes before
     * its answer (`LinkRequestController`, M2); it answers null when there is nothing to send.
     *
     * @param  Closure(): ?self  $compose
     */
    public static function defer(#[\SensitiveParameter] string $address, Closure $compose): void
    {
        $mailer = self::mailerName();
        $chain = self::chain();

        \Illuminate\Support\defer(static function () use ($address, $compose, $mailer, $chain): void {
            try {
                $mail = $compose();

                if ($mail === null) {
                    return;
                }

                foreach ($chain as $i => $through) {
                    try {
                        Notifications::route('mail', $address)->notifyNow($mail->through($through));

                        return;
                    } catch (Throwable $failed) {
                        if ($i === array_key_last($chain)) {
                            throw $failed;
                        }
                    }
                }
            } catch (Throwable) {
                Log::warning(sprintf('A reader email could not be made or sent through the mailer [%s]; the address, the link and the error are not logged.', $mailer));
            }
        });
    }

    /**
     * The mailers a reader mail is tried through, in order: the default mailer, or a failover's or round robin's members,
     * followed down to the mailers that send — a round robin's from a random one, as Laravel's starts.
     *
     * ⚠️ NEVER THROUGH LARAVEL'S FAILOVER OR ROUND ROBIN TRANSPORT (review). Laravel builds them with the application's
     * logger, and Symfony logs there each member that fails, with its error — the mail server's reply, the reader's
     * address in it — before anything here could catch it, even when the next member delivers. Walked here, a failure is
     * caught like any other, and only `defer()`'s one line is logged. The legacy `mail.driver` is not walked: Laravel
     * builds every one of its mailers from the same settings.
     *
     * @return list<string>
     */
    public static function chain(): array
    {
        $name = self::mailerName();

        return $name === '' || config('mail.driver') ? [$name] : self::members($name, 0);
    }

    /** @return list<string> */
    private static function members(string $name, int $depth): array
    {
        $read = $depth > 8 ? null : self::read($name, false, $depth);

        if ($read === null || ($read['transport'] !== 'failover' && $read['transport'] !== 'roundrobin') || ! is_array($read['mailers'])) {
            return [$name];
        }

        $members = array_values(array_filter($read['mailers'], is_string(...)));

        if ($members === []) {
            return [$name];
        }

        if ($read['transport'] === 'roundrobin') {
            $from = random_int(0, count($members) - 1);
            $members = [...array_slice($members, $from), ...array_slice($members, 0, $from)];
        }

        return array_merge(...array_map(static fn (string $member): array => self::members($member, $depth + 1), $members));
    }

    /**
     * Why this mailer keeps mail instead of sending it, or null — read as `MailManager::getConfig()` reads it, a `url` and
     * failover and round-robin members included.
     */
    private static function keeps(string $name, bool $legacy, int $depth): ?string
    {
        if ($depth > 8) {
            return "the mailer [{$name}] names itself among its own members";
        }

        $read = self::read($name, $legacy, $depth);

        if ($read === null) {
            return "no mailer [{$name}] is defined";
        }

        $transport = $read['transport'];

        if ($transport === null) {
            return "the mailer [{$name}] names no transport";
        }

        if ($transport === 'failover' || $transport === 'roundrobin') {
            $members = $read['mailers'];

            if (! is_array($members) || $members === []) {
                return "the mailer [{$name}] lists no mailers to try";
            }

            foreach ($members as $member) {
                $closed = is_string($member) ? self::keeps($member, $legacy, $depth + 1) : "the mailer [{$name}] lists a mailer that is not a name";

                if ($closed !== null) {
                    return $closed;
                }
            }

            return null;
        }

        return in_array($transport, self::KEEPING, true)
            ? "the mailer [{$name}] uses the [{$transport}] transport, which keeps mail instead of sending it"
            : null;
    }

    /**
     * One mailer as `MailManager::getConfig()` and `resolve()` read it — its transport, a `url` and the legacy
     * `mail.driver` included, and its members — or null when it is not defined.
     *
     * @return array{transport: ?string, mailers: mixed}|null
     */
    private static function read(string $name, bool $legacy, int $depth): ?array
    {
        $config = $legacy ? config('mail') : config("mail.mailers.{$name}");

        if (! is_array($config)) {
            return null;
        }

        if (isset($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser)->parseConfiguration($config));
            $config['transport'] = $config['driver'] ?? null;
        }

        $transport = $legacy && $depth > 0 ? $name : ($config['transport'] ?? ($legacy ? config('mail.driver') : null));

        return [
            'transport' => is_string($transport) && trim($transport) !== '' ? $transport : null,
            'mailers' => $config['mailers'] ?? null,
        ];
    }
}
