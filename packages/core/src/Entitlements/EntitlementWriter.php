<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * The one door in: grants, comps, revokes and erases a reader's entitlements, one source at a time — ADR-040, as
 * amended by Adam on 2026-10-06: a grant remembers where it came from, so a refund removes only what its own source
 * gave.
 *
 * ⚠️ NO SITE, ORG OR ACTOR PARAMETER ANYWHERE. It writes for the site in context and records the actor the request has,
 * so a wrong site cannot be passed in.
 *
 * ⚠️ PER SOURCE. Each decision reads its own source's row and no other, so two sources granted at once give the same two
 * rows in either order, and no source's time is ever carried into another's. A grant never shortens its source, a
 * repeat writes nothing, and a revoked source STAYS revoked: a grant of it answers `StillRevoked`, however late its end
 * — a refunded order's replayed payment gives nothing back. Only `comp()`, an owner deciding now, gives access back, and
 * only through the comp's own row.
 *
 * ⚠️ AUTHORITY, and why nobody signed in is trusted: `EntitlementAuthority`. A public route reaches this writer by design
 * — commerce's webhook — so every caller with nobody signed in must have proved its own authority first; the signature
 * check is commerce's.
 *
 * ⚠️ RECORDED INSIDE THE WRITE'S TRANSACTION — the action and the row, never the reader, the name or the source. A
 * write that cannot be recorded is not kept (ADR-020), and a refused write, or one that changes nothing, records
 * nothing.
 *
 * ⚠️ THE CONTRACT A PRODUCER KEEPS (commerce, an import): verify first, then with nobody signed in set the site from the
 * ORDER's site; name the cause as the source, never the event (`EntitlementSource`); pass the end as an instant or
 * null, never a duration; branch on `GrantOutcome` and on `EntitlementRefused::$reason`, never on text — `Unchanged` and
 * `StillRevoked` are processed, `Race` and `Database` are retried; a refund is `revoke()` of that order's source alone.
 * Never grant from a reader's own request, and never call `comp()`.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementWriter
{
    public const GRANTED = 'entitlement.granted';

    public const EXTENDED = 'entitlement.extended';

    public const REINSTATED = 'entitlement.reinstated';

    public const REVOKED = 'entitlement.revoked';

    public const ERASED = 'entitlement.erased';

    /** The last instant every supported database stores: MySQL's `DATETIME` ends here. */
    private const LATEST = '9999-12-31 23:59:59';

    /**
     * The window `GuardedEntitlementBuilder` reads for an insert or an update. Armed only in the private `save()`, around
     * one save, and cleared in a `finally`; there is no opener.
     */
    private static bool $writing = false;

    /** The window for a delete. Armed only in the private `erase()`, around one statement, and cleared in a `finally`. */
    private static bool $erasing = false;

    private readonly EntitlementAuthority $authority;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly Context $context,
        private readonly ReaderGuard $readers,
    ) {
        $this->authority = new EntitlementAuthority($context, $readers);
    }

    /** Whether the writer is saving right now — for `GuardedEntitlementBuilder`, which cannot open it. */
    public static function isWriting(): bool
    {
        return self::$writing;
    }

    /** Whether the writer is erasing right now — for `GuardedEntitlementBuilder`, which cannot open it. */
    public static function isErasing(): bool
    {
        return self::$erasing;
    }

    /**
     * Give a reader an entitlement on the site in context, from this source, until `$until` (exclusive), or with no end
     * when `$until` is null — always passed, never defaulted, so "for life" is never the reading of a forgotten
     * argument. Never shortens this source, and never brings it back once revoked. Other sources are neither read nor
     * touched.
     *
     * @throws EntitlementRefused
     */
    public function grant(
        #[\SensitiveParameter] int|string $reader,
        string $entitlement,
        #[\SensitiveParameter] string $source,
        ?DateTimeInterface $until,
    ): GrantOutcome {
        return EntitlementAuthority::mapped(EntitlementRefused::GRANT, $entitlement, function () use ($reader, $entitlement, $source, $until): GrantOutcome {
            $siteId = $this->checked(EntitlementRefused::GRANT, $entitlement);

            if (! EntitlementSource::isSource($source)) {
                throw EntitlementRefused::because(EntitlementRefusal::NotASource, EntitlementRefused::GRANT, $entitlement);
            }

            if (EntitlementSource::isReserved($source)) {
                throw EntitlementRefused::because(EntitlementRefusal::ReservedSource, EntitlementRefused::GRANT, $entitlement);
            }

            [$now, $end] = $this->ends(EntitlementRefused::GRANT, $entitlement, $until);
            $stored = $this->known(EntitlementRefused::GRANT, $entitlement, $reader);

            return $this->give(EntitlementRefused::GRANT, $siteId, $stored, $entitlement, $source, $now, $end);
        });
    }

    /**
     * An owner's grant by hand, under the source `core.comp`. The one door that gives access back after a revocation, and
     * only through the comp's own row, with the end given now, exactly. Needs an owner signed in: a person's decision,
     * never a caller's.
     *
     * @throws EntitlementRefused
     */
    public function comp(#[\SensitiveParameter] int|string $reader, string $entitlement, ?DateTimeInterface $until): GrantOutcome
    {
        return EntitlementAuthority::mapped(EntitlementRefused::COMP, $entitlement, function () use ($reader, $entitlement, $until): GrantOutcome {
            $siteId = $this->checked(EntitlementRefused::COMP, $entitlement);
            [$now, $end] = $this->ends(EntitlementRefused::COMP, $entitlement, $until);
            $stored = $this->known(EntitlementRefused::COMP, $entitlement, $reader);

            return $this->give(EntitlementRefused::COMP, $siteId, $stored, $entitlement, EntitlementSource::COMP, $now, $end);
        });
    }

    /**
     * Stamp this one source revoked now, whether it is live or has lapsed: a refund that arrives after a pass ended must
     * still stop a later grant of that source extending it. False — nothing written, nothing recorded — when there is
     * no such row or it already was revoked. The reader keeps whatever other live sources give.
     *
     * A reader the host has already deleted can still lose access: the key is used as given when nobody has it.
     *
     * @throws EntitlementRefused
     */
    public function revoke(#[\SensitiveParameter] int|string $reader, string $entitlement, #[\SensitiveParameter] string $source): bool
    {
        return EntitlementAuthority::mapped(EntitlementRefused::REVOKE, $entitlement, function () use ($reader, $entitlement, $source): bool {
            $siteId = $this->checked(EntitlementRefused::REVOKE, $entitlement);

            // `core.comp` is a source like any other here: an owner revokes a comp as they revoke an order.
            if (! EntitlementSource::isSource($source)) {
                throw EntitlementRefused::because(EntitlementRefusal::NotASource, EntitlementRefused::REVOKE, $entitlement);
            }

            $key = $this->authority->key(EntitlementRefused::REVOKE, $entitlement, $reader);
            $stored = $this->readers->canonical($reader) ?? $key;
            $now = CarbonImmutable::now('UTC')->startOfSecond();

            return TransactionRecovery::run((new Entitlement)->getConnection(), function () use ($siteId, $stored, $entitlement, $source, $now): bool {
                $row = $this->lockedRow(EntitlementRefused::REVOKE, $siteId, $stored, $entitlement, $source);

                if ($row === null || $row->revoked_at !== null) {
                    return false;
                }

                $row->forceFill(['revoked_at' => $now, 'changed_at' => $now]);

                $this->save($row, EntitlementRefused::REVOKE, $entitlement);
                $this->auditor->recordOrFail(self::REVOKED, $row);

                return true;
            });
        });
    }

    /**
     * Delete every row this reader has, from every source, on every site of the org in context, recording each — the
     * erasure primitive ADR-020's v1.1 tooling builds on. Returns how many; zero writes and records nothing.
     *
     * Works after the host has deleted the reader, and with no usable reader guard, taking the identifier as given.
     *
     * @throws EntitlementRefused
     */
    public function forget(#[\SensitiveParameter] int|string $reader): int
    {
        return EntitlementAuthority::mapped(EntitlementRefused::FORGET, null, function () use ($reader): int {
            $orgId = $this->authority->org(EntitlementRefused::FORGET);
            $this->authority->authorise(EntitlementRefused::FORGET, null);
            $key = $this->authority->filedKey(EntitlementRefused::FORGET, $reader);

            return TransactionRecovery::run((new Entitlement)->getConnection(), function () use ($orgId, $key): int {
                // Ascending, so two erasures never wait on each other in a cycle; a grant locks one site.
                $siteIds = Site::query()->orderBy('id')->lockForUpdate()->pluck('id')->all();

                $rows = Entitlement::withoutScopeBecause(
                    'erasing one reader\'s entitlements across their organisation\'s sites (ADR-020)',
                    fn ($query) => $query->where('org_id', $orgId)->whereIn('site_id', $siteIds)->where('reader_id', $key)->orderBy('id')->get(),
                );

                if ($rows->isEmpty()) {
                    return 0;
                }

                $this->erase($rows->modelKeys());

                // The deleted model keeps its key, so "the system erased entitlement 812" survives the row.
                foreach ($rows as $row) {
                    $this->auditor->recordOrFail(self::ERASED, $row);
                }

                return $rows->count();
            });
        });
    }

    /**
     * Steps 1 to 4 of a grant, a comp and a revoke, which run no query but the reader guard's own session read: the
     * site, who is acting, the guard, the name.
     *
     * @param  EntitlementRefused::*  $act
     */
    private function checked(string $act, string $entitlement): int
    {
        $siteId = $this->authority->site($act, $entitlement);
        $this->authority->authorise($act, $entitlement);
        $this->authority->guard($act, $entitlement);

        if (! EntitlementName::isName($entitlement)) {
            throw EntitlementRefused::because(EntitlementRefusal::NotAName, $act);
        }

        return $siteId;
    }

    /**
     * Now, and the end, both UTC in whole seconds — or a refusal for an end nobody would ever hold, or one no supported
     * database can store.
     *
     * @param  EntitlementRefused::*  $act
     * @return array{0: CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function ends(string $act, string $entitlement, ?DateTimeInterface $until): array
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $end = $until === null ? null : CarbonImmutable::instance($until)->utc()->startOfSecond();

        if ($end !== null && $end->lessThanOrEqualTo($now)) {
            throw EntitlementRefused::because(EntitlementRefusal::AlreadyEnded, $act, $entitlement);
        }

        if ($end !== null && $end->greaterThan(CarbonImmutable::createFromFormat(UtcInstant::FORMAT, self::LATEST, 'UTC'))) {
            throw EntitlementRefused::because(EntitlementRefusal::TooFar, $act, $entitlement);
        }

        return [$now, $end];
    }

    /**
     * The reader, as the host spells them, belonging to the org in context — looked up before the transaction opens,
     * so the site's lock is never held across a query on the host's table. Under another org's context, a reader does
     * not exist.
     *
     * @param  EntitlementRefused::*  $act
     */
    private function known(string $act, string $entitlement, #[\SensitiveParameter] int|string $reader): string
    {
        $this->authority->key($act, $entitlement, $reader);

        return $this->readers->canonical($reader) ?? throw EntitlementRefused::because(EntitlementRefusal::UnknownReader, $act, $entitlement);
    }

    /**
     * A grant's or a comp's decision, on this source's row alone.
     *
     * @param  EntitlementRefused::GRANT|EntitlementRefused::COMP  $act
     */
    private function give(string $act, int $siteId, string $stored, string $entitlement, string $source, CarbonImmutable $now, ?CarbonImmutable $end): GrantOutcome
    {
        return TransactionRecovery::run((new Entitlement)->getConnection(), function () use ($act, $siteId, $stored, $entitlement, $source, $now, $end): GrantOutcome {
            $row = $this->lockedRow($act, $siteId, $stored, $entitlement, $source);

            if ($row === null) {
                $row = (new Entitlement)->forceFill([
                    'org_id' => $this->context->orgId(),
                    'site_id' => $siteId,
                    'reader_id' => $stored,
                    'entitlement' => $entitlement,
                    'source' => $source,
                    'expires_at' => $end,
                    'revoked_at' => null,
                    'changed_at' => $now,
                ]);
                [$outcome, $action] = [GrantOutcome::Granted, self::GRANTED];
            } elseif ($row->revoked_at !== null) {
                // A producer repeating itself carries no new information; an owner's comp is a fresh decision.
                if ($act !== EntitlementRefused::COMP) {
                    return GrantOutcome::StillRevoked;
                }

                // The one place an end moves earlier, and it follows a recorded revoke.
                $row->forceFill(['revoked_at' => null, 'expires_at' => $end, 'changed_at' => $now]);
                [$outcome, $action] = [GrantOutcome::Reinstated, self::REINSTATED];
            } elseif ($row->expires_at !== null && ($end === null || $end->greaterThan($row->expires_at))) {
                // A lapsed row of this source too: a same-source renewal, since an end at or before now was refused.
                $row->forceFill(['expires_at' => $end, 'changed_at' => $now]);
                [$outcome, $action] = [GrantOutcome::Extended, self::EXTENDED];
            } else {
                return GrantOutcome::Unchanged;
            }

            $this->save($row, $act, $entitlement);
            $this->auditor->recordOrFail($action, $row);

            return $outcome;
        });
    }

    /**
     * The site's row locked, then this source's row read plainly by all four key columns — or null.
     *
     * ⚠️ THE SITE'S LOCK AND THAT LOCK ALONE. It serialises one site's writes, so two grants of one source, or of two,
     * take it in turn. A `FOR UPDATE` on an entitlement that does not exist yet takes a gap lock on MySQL and MariaDB,
     * and two first grants would deadlock on each other's gap. `OrgScope` applies, so only the context org's site can
     * be locked.
     *
     * @param  EntitlementRefused::*  $act
     */
    private function lockedRow(string $act, int $siteId, string $stored, string $entitlement, string $source): ?Entitlement
    {
        Site::query()->whereKey($siteId)->lockForUpdate()->value('id')
            ?? throw EntitlementRefused::because(EntitlementRefusal::SiteGone, $act, $entitlement);

        return Entitlement::query()
            ->where('site_id', $siteId)
            ->where('reader_id', $stored)
            ->where('entitlement', $entitlement)
            ->where('source', $source)
            ->first();
    }

    /** @param  EntitlementRefused::*  $act */
    private function save(Entitlement $row, string $act, string $entitlement): void
    {
        self::$writing = true;

        try {
            $saved = $row->save();
        } finally {
            self::$writing = false;
        }

        if (! $saved) {
            throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
        }
    }

    /** @param  list<int>  $ids */
    private function erase(array $ids): void
    {
        self::$erasing = true;

        try {
            Entitlement::withoutScopeBecause(
                'erasing one reader\'s entitlements across their organisation\'s sites (ADR-020)',
                fn ($query) => $query->whereKey($ids)->delete(),
            );
        } finally {
            self::$erasing = false;
        }
    }
}
