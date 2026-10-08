<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use PDOException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * A site's public address, from the console — ADR-021, amended 2026-10-08. A blueprint's first site has none (an
 * admin-only site, `FirstOrg`), and core registers no admin page for sites, so this is where an operator gives it one,
 * or moves it, without `tinker`.
 *
 * ⚠️ AN ABSOLUTE `http(s)://host` ONLY. A bare `acme.example` is a PATH under the `path` strategy every first site has —
 * the prefix `/acme.example` on every host — and a host-less address gives a mailed link no host to stand on, because a
 * reader link is never built from the request's `Host` (`ReaderLinks`). The host-less forms stay with the seeder and
 * `tinker`.
 *
 * ⚠️ IT WRITES `base_url` ALONE, THROUGH `Site::save()`, so the model's own derivation, host-claim mutex and overlap
 * refusals all apply, and never `url_strategy`: an explicit scheme outranks the strategy (ADR-021). One site per run, so
 * issue #71's batching deadlock cannot arise.
 *
 * ⚠️ MOVING A LIVE ADDRESS NEEDS `--force` (Adam, 2026-10-08): every link to the old one breaks, and another organisation
 * may then claim it, unless another of this organisation's sites still overlaps it. A respelling of the same claim —
 * case, a trailing slash, a port, `http` to `https` — is not a move. The decision is checked again under the save's own
 * locks, so a run that raced another cannot move what it read as unclaimed. Each real change writes one audit row with
 * no URL in it, as the system (Adam, 2026-10-08).
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class SiteCommand extends Command
{
    /** An admin-only site was given an address. */
    public const ADDRESS_SET = 'site.address_set';

    /** A site's address was respelt or moved. */
    public const ADDRESS_REPLACED = 'site.address_replaced';

    private const ACTIONS = ['address'];

    /** All `parse_url()` may find in an address: so no user name, password, query or fragment is stored or echoed. */
    private const PARTS = ['scheme', 'host', 'port', 'path'];

    protected $signature = 'kitsune:site
        {action : address}
        {url? : For address — the public address: https:// or http://, a host and an optional path, e.g. https://acme.example or https://acme.example/blog}
        {--org= : The organisation\'s slug}
        {--site= : The site\'s handle — a blueprint\'s first site has the organisation\'s slug, unless apply was given --site}
        {--force : Confirm moving a site off the address it answers at now}';

    protected $description = 'Give a site its public address, or move it (ADR-021)';

    public function handle(Context $context, Auditor $auditor): int
    {
        if (! in_array($this->argument('action'), self::ACTIONS, true)) {
            // What was typed is not repeated.
            return $this->refuse('Refusing: kitsune:site has one action, address. Nothing was read or written.');
        }

        $slug = $this->option('org');
        $handle = $this->option('site');

        if (! is_string($slug) || $slug === '' || ! is_string($handle) || $handle === '') {
            return $this->refuse('Refusing: address needs --org=<slug> and --site=<handle>. Nothing was read or written.');
        }

        $url = trim((string) $this->argument('url'));

        if ($url === '') {
            return $this->refuse('Refusing: address needs the site\'s public address, e.g. https://acme.example. Nothing was read or written.');
        }

        // ⚠️ ITS FORM IS JUDGED BEFORE ANYTHING IS READ, so these refusals neither depend on nor reveal what exists. The
        // model's refusals at save, below, come after the read, and name what holds the address.
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            // `parse_url()` also fails on a port it cannot read, so the port is named here too.
            return $this->refuse('Refusing: an address starts with https:// or http://, names its host, and gives any port as a number up to 65535 — e.g. https://acme.example or https://acme.example/blog; a bare acme.example would be read as a path on every host. Nothing was read or written.');
        }

        // ⚠️ `base_url` IS STORED AS TYPED, so a password in it would be stored, echoed, and later mailed in a link.
        if (array_diff(array_keys($parts), self::PARTS) !== []) {
            return $this->refuse('Refusing: an address is a host with an optional port and path — no user name, password, ? or #. The value is not repeated. Nothing was read or written.');
        }

        try {
            // An explicit scheme outranks the strategy, so `path` derives what the site's own strategy will on save.
            [$host, $prefix] = Site::deriveUrlParts($url, 'path');
        } catch (RuntimeException $refused) {
            return $this->refuse($refused->getMessage().' Nothing was read or written.');
        }

        try {
            // With the trashed, so a deleted organisation is named as one rather than as a slug nobody holds.
            $org = Org::withTrashed()->where('slug', $slug)->first();

            if ($org === null) {
                // A slug is configuration, not a person, so it is named.
                return $this->refuse("Refusing: no organisation has the slug [{$slug}]. Nothing was read or written.");
            }

            if ($org->trashed()) {
                // Its sites answer no request, so an address there would only keep it from another organisation.
                return $this->refuse("Refusing: [{$slug}] is a deleted organisation, and its sites are given no address. Nothing was written.");
            }

            $context->forget()->setOrg($org);

            // ⚠️ IN THE ORG'S SCOPE, because a handle is unique only within one. No row lock: the save takes the host's first.
            $site = Site::query()->where('handle', $handle)->first();

            if ($site === null) {
                return $this->refuse("Refusing: [{$org->slug}] has no site with the handle [{$handle}]. Nothing was written.");
            }

            $read = [$site->canonical_host, $site->path_prefix];
            $live = $read[0] !== null;
            $before = self::claim(...$read);

            // ⚠️ THE CLAIM, NOT THE STRING: a respelling answers where it did, so it is not a move.
            if ($live && [$host, $prefix] !== $read && ! $this->option('force')) {
                return $this->refuse("Refusing: site [{$site->handle}] answers at {$before} now, and moving it breaks every link to it. Run it again with --force. Nothing was written.");
            }

            self::pinTo($site, $read);

            $changed = $site->getConnection()->transaction(static function () use ($site, $url, $live, $auditor): bool {
                $site->base_url = $url;

                if (! $site->save()) {
                    throw new RuntimeException('Refusing: a listener cancelled the save.');
                }

                // ⚠️ RECORDED FROM THE WRITE'S EFFECT (ADR-020's amendment): the same address again writes nothing to record.
                if (! $site->wasChanged('base_url')) {
                    return false;
                }

                // Inside the transaction, so a change that cannot be recorded is not kept. The console acts from no site.
                $auditor->recordOrFail($live ? self::ADDRESS_REPLACED : self::ADDRESS_SET, $site);

                return true;
            });

            $this->line(OutputFormatter::escape($changed
                ? sprintf('Site [%s] now has the address [%s], and answers at %s. Before: %s.', $site->handle, $url, self::claim($site->canonical_host, $site->path_prefix), $before)
                : sprintf('Site [%s] already has the address [%s]. Nothing was written.', $site->handle, $url)));

            return self::SUCCESS;
        } catch (UniqueConstraintViolationException) {
            // The overlap check refuses every other org's exact claim under the host's lock, so this is a sibling's.
            return $this->refuse("Refusing: another site of [{$slug}] already answers at exactly that address. Nothing was written.");
        } catch (PDOException $failed) {
            // ⚠️ THE SQLSTATE ALONE: the exception's message inlines its bindings, and the address is one of them.
            return $this->refuse(sprintf('Refusing: the database failed (SQLSTATE %s), and its message is not repeated. Running it again is safe.', self::state($failed)));
        } catch (RuntimeException $refused) {
            // The model's own refusals at save: another org's overlapping claim, a stale origin, an unusable isolation.
            return $this->refuse($refused->getMessage().' Nothing was written.');
        } finally {
            $context->forget();
        }
    }

    /**
     * Refuses this run's save if the site no longer sits where it was read — checked under the save's own locks.
     *
     * ⚠️ `Site::save()` DOES NOT POLICE A LOST UPDATE, by design: its stale-origin check guards the lock discipline, and
     * passes a row another run moved to the very host this one locks. So two runs that both read a site as admin-only
     * would each skip `--force`, and the second would move the address the first had just made live, recorded as set
     * and printed as "Before: no address". Measured by review.
     *
     * ⚠️ A `saving` LISTENER, BECAUSE THAT IS WHERE THE LOCKS ARE HELD AND NO PROOF IS ARMED YET. `Site::save()` takes
     * the host mutexes and then the row before `parent::save()` fires `saving`, so the row read here is current and
     * locked in the model's own order — a lock taken before the save would reverse it and deadlock against any save of
     * a site on the same host. And `saving` comes before the `updating` listener that arms the derivation proof, so a
     * refusal here leaves none behind. Only this run's instance is checked; the listener outlives the run, as every
     * model listener does, and passes any other save untouched.
     *
     * @param  array{0: string|null, 1: string|null}  $read
     */
    private static function pinTo(Site $site, array $read): void
    {
        Site::saving(static function (Site $saving) use ($site, $read): void {
            if ($saving !== $site) {
                return;
            }

            $row = $saving->getConnection()->table('sites')->where('id', $saving->getKey())->lockForUpdate()->first(['canonical_host', 'path_prefix']);

            if ($row === null || [$row->canonical_host, $row->path_prefix] !== $read) {
                throw new RuntimeException("Refusing: site [{$saving->handle}] changed while this ran, so what it was about to replace is not what it read. Run it again.");
            }
        });
    }

    /**
     * Where a site answers, from its derived columns — never its stored `base_url`, which a write through `tinker`
     * could have given a password.
     */
    private static function claim(?string $host, ?string $prefix): string
    {
        return match (true) {
            $host === null => 'no address',
            $host === '' => '['.($prefix === '' || $prefix === null ? '/' : $prefix).'] on any host',
            default => '['.$host.$prefix.']',
        };
    }

    /** The SQLSTATE alone, never the message, which may carry bindings. */
    private static function state(PDOException $e): string
    {
        if (isset($e->errorInfo[0]) && is_string($e->errorInfo[0])) {
            return $e->errorInfo[0];
        }

        return preg_match('/SQLSTATE\[(\w{5})\]/', $e->getMessage(), $match) === 1 ? $match[1] : (string) $e->getCode();
    }

    /** Escaped, because the model's refusals repeat what was typed, and a typed `<info>` would be read as a style. */
    private function refuse(string $message): int
    {
        $this->error(OutputFormatter::escape($message));

        return self::FAILURE;
    }
}
