<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Throwable;

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
 * — a refunded order's replayed payment gives nothing back. An owner's comp gives through its own row. The separate
 * verified-order adjustment doors amend this only for proved remaining original contributions: reinstate requires
 * the exact producer-owned revocation, including a fresh write token, and never recreates an erased row (#201).
 *
 * ⚠️ ORDINARY AUTHORITY, and why nobody signed in is trusted: `EntitlementAuthority`. A public route reaches this writer
 * by design — commerce's webhook — so every caller with nobody signed in must have proved its own authority first;
 * the signature check is commerce's. The narrow `grantVerifiedOrder()` door additionally accepts a buyer's session
 * only behind the producer's exact persisted order/payment/allocation and original-reader or immutable-claim proof.
 * It preserves the reader guard and records the producer as the system; it is never an alternative for a comp.
 *
 * ⚠️ RECORDED INSIDE THE WRITE'S TRANSACTION — the action and the row, never the reader, the name or the source. A
 * write that cannot be recorded is not kept (ADR-020), and a refused write, or one that changes nothing, records
 * nothing.
 *
 * ⚠️ THE CONTRACT A PRODUCER KEEPS (commerce, an import): verify first, then for ordinary grant/revoke with nobody signed
 * in set the site from the ORDER's site; name the cause as the source, never the event (`EntitlementSource`); pass the end as an instant or
 * null, never a duration; branch on `GrantOutcome` and on `EntitlementRefused::$reason`, never on text — `Unchanged` and
 * `StillRevoked` are processed, `Race` and `Database` are retried; a refund is `revoke()` of that order's source alone,
 * and is processed only once that order's grant has been, since a revoke of a source never granted stamps nothing and
 * the later grant would then give access. Never call ordinary grant/revoke from a reader's own request, and never call
 * `comp()`. Commerce's verified-order door permits only its proof-backed original or claimed reader, without clearing
 * authentication; its exact proof must still hold after host callbacks before the surrounding transaction commits.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementWriter
{
    public const GRANTED = 'entitlement.granted';

    public const EXTENDED = 'entitlement.extended';

    public const REINSTATED = 'entitlement.reinstated';

    public const REVOKED = 'entitlement.revoked';

    public const REVISED = 'entitlement.revised';

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
     * A first-party confirmed order's exact effect, even when its buyer remains signed in. The caller owns the
     * transaction and proves the immutable order, captured payment, positive allocation, frozen effect and original
     * reader or durable whole-order claim under the Org/Site locks. The proof runs before the effect and after host
     * audit callbacks; it must throw if any of that custody changed. It does not return grant inputs or authority.
     *
     * No general caller may substitute this for grant(). Ordinary reader and staff authority remains unchanged.
     * The producer audit actor is always the system; no authentication guard is cleared or impersonated.
     *
     * @param  Closure():void  $verify
     *
     * @throws EntitlementRefused
     *
     * @internal First-party commerce order fulfillment only; no host extension contract.
     */
    public function grantVerifiedOrder(
        #[\SensitiveParameter] int|string $reader,
        string $entitlement,
        #[\SensitiveParameter] string $source,
        ?DateTimeInterface $until,
        #[\SensitiveParameter] Closure $verify,
    ): GrantOutcome {
        return EntitlementAuthority::mapped(EntitlementRefused::GRANT, $entitlement, function () use ($reader, $entitlement, $source, $until, $verify): GrantOutcome {
            $connection = (new Entitlement)->getConnection();
            if ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
                || preg_match('/\Acommerce\.order:[1-9][0-9]{0,18}\z/D', $source) !== 1) {
                throw EntitlementRefused::because(EntitlementRefusal::NotASource, EntitlementRefused::GRANT, $entitlement);
            }

            return TransactionRecovery::run($connection, function () use ($reader, $entitlement, $source, $until, $verify): GrantOutcome {
                $verify();
                $siteId = $this->checked(EntitlementRefused::GRANT, $entitlement, producer: true);
                [$now, $end] = $this->ends(EntitlementRefused::GRANT, $entitlement, $until);
                $stored = $this->known(EntitlementRefused::GRANT, $entitlement, $reader);

                return $this->give(EntitlementRefused::GRANT, $siteId, $stored, $entitlement, $source, $now, $end, producer: true, verify: $verify);
            });
        });
    }

    /**
     * Reconcile one existing order source to its proved remaining original end, or suspend it. Never inserts,
     * clears a revocation, or touches another source. An existing revocation is not a producer-owned suspension:
     * only Revoked means this call stamped one. The caller records that exact token-bearing witness atomically.
     * Extending an unrevoked source requires its canonical reader to remain present through the complete write.
     *
     * @param  Closure():void  $verify  Recheck the frozen order/claim, original contributions and financial work.
     *
     * @internal Owner/system commerce recovery only; the signed-in buyer override does not apply.
     */
    public function reviseVerifiedOrder(
        #[\SensitiveParameter] int|string $reader,
        string $entitlement,
        #[\SensitiveParameter] string $source,
        ?DateTimeInterface $until,
        bool $suspend,
        #[\SensitiveParameter] Closure $verify,
    ): VerifiedOrderResult {
        return EntitlementAuthority::mapped(EntitlementRefused::REVISE, $entitlement,
            fn (): VerifiedOrderResult => $this->adjustOrder($reader, $entitlement, $source, $until, $suspend, null, $verify));
    }

    /**
     * Restore only an existing exact commerce-owned revocation to its proved remaining original end. No erased
     * row is recreated; a manual write invalidates the opaque witness even in the same second. An ended offer stays
     * inactive. The producer proves all blockers cleared before and after the explicit system audit, preserving guards.
     *
     * @param  array{id:string,expires_at:?string,revoked_at:string,changed_at:string,change_token:string}  $expectedRevocationWitness
     * @param  Closure():void  $verify
     *
     * @internal Owner/system commerce recovery only; never a general source or buyer reinstatement door.
     */
    public function reinstate(
        #[\SensitiveParameter] int|string $reader,
        string $entitlement,
        #[\SensitiveParameter] string $source,
        ?DateTimeInterface $until,
        #[\SensitiveParameter] array $expectedRevocationWitness,
        #[\SensitiveParameter] Closure $verify,
    ): VerifiedOrderResult {
        return EntitlementAuthority::mapped(EntitlementRefused::REINSTATE, $entitlement,
            fn (): VerifiedOrderResult => $this->adjustOrder($reader, $entitlement, $source, $until, false, $expectedRevocationWitness, $verify));
    }

    /** @param array<string,mixed>|null $witness
     * @param  Closure():void  $verify
     */
    private function adjustOrder(
        #[\SensitiveParameter] int|string $reader,
        string $entitlement,
        #[\SensitiveParameter] string $source,
        ?DateTimeInterface $until,
        bool $suspend,
        #[\SensitiveParameter] ?array $witness,
        #[\SensitiveParameter] Closure $verify,
    ): VerifiedOrderResult {
        $act = $witness === null ? EntitlementRefused::REVISE : EntitlementRefused::REINSTATE;
        $connection = (new Entitlement)->getConnection();
        if ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()
            || preg_match('/\Acommerce\.order:[1-9][0-9]{0,18}\z/D', $source) !== 1) {
            throw EntitlementRefused::because(EntitlementRefusal::NotASource, $act, $entitlement);
        }

        return TransactionRecovery::run($connection, function () use ($act, $reader, $entitlement, $source, $until, $suspend, $witness, $verify): VerifiedOrderResult {
            $authority = $this->adjustmentAuthority($act, $entitlement);
            if ($witness !== null) {
                $this->validWitness($witness, $act, $entitlement);
            }
            $verify();
            if ($this->adjustmentAuthority($act, $entitlement) !== $authority) {
                throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
            }
            $siteId = $this->context->siteId() ?? throw EntitlementRefused::because(EntitlementRefusal::NoSiteContext, $act, $entitlement);
            $stored = $this->authority->key($act, $entitlement, $reader);
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $end = $until === null ? null : CarbonImmutable::instance($until)->utc()->startOfSecond();
            if ($end !== null && (! self::validInstant($end->format(UtcInstant::FORMAT)) || $end->format(UtcInstant::FORMAT) > self::LATEST)) {
                throw EntitlementRefused::because(EntitlementRefusal::TooFar, $act, $entitlement);
            }
            $expired = $end !== null && $end->lessThanOrEqualTo($now);
            $row = $this->lockedRow($act, $siteId, $stored, $entitlement, $source);
            $action = null;
            $outcome = VerifiedOrderOutcome::Missing;
            $freshReader = false;
            if ($row !== null) {
                $this->checkedOrderRow($row, $siteId, $stored, $entitlement, $source, $act);
                $current = $this->orderWitness($row);
                $sameEnd = $current['expires_at'] === $end?->format(UtcInstant::FORMAT);
                $freshReader = $witness !== null || (! $suspend && $current['revoked_at'] === null
                    && $current['expires_at'] !== null
                    && ($end === null || $end->format(UtcInstant::FORMAT) > $current['expires_at']));
                if ($freshReader) {
                    $readerProof = $this->knownForReinstatement($stored, $act, $entitlement);
                    if ($this->adjustmentAuthority($act, $entitlement) !== $authority) {
                        throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
                    }
                    $this->checkRestorationReader($readerProof, $stored, $act, $entitlement);
                    $this->checkedOrderRow($row, $siteId, $stored, $entitlement, $source, $act);
                    if ($this->orderWitness($row) !== $current) {
                        throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
                    }
                }
                if ($witness !== null) {
                    if ($current['revoked_at'] === null) {
                        // A replay of a completed restore never changes the row again.
                        if (! $sameEnd) {
                            throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
                        }
                        $outcome = $expired ? VerifiedOrderOutcome::Expired : VerifiedOrderOutcome::Unchanged;
                    } elseif (array_filter($current, static fn (mixed $value, string $key): bool => $witness[$key] !== $value, ARRAY_FILTER_USE_BOTH) !== []) {
                        $outcome = VerifiedOrderOutcome::StillRevoked;
                    } elseif ($expired) {
                        $outcome = VerifiedOrderOutcome::Expired;
                        if (! $sameEnd) {
                            $row->forceFill(['expires_at' => $end, 'changed_at' => $now]);
                            $action = self::REVISED;
                        }
                    } else {
                        $row->forceFill(['expires_at' => $end, 'revoked_at' => null, 'changed_at' => $now]);
                        [$outcome, $action] = [VerifiedOrderOutcome::Reinstated, self::REINSTATED];
                    }
                } else {
                    $newRevoke = $suspend && $current['revoked_at'] === null;
                    $outcome = match (true) {
                        $newRevoke => VerifiedOrderOutcome::Revoked,
                        $current['revoked_at'] !== null => VerifiedOrderOutcome::StillRevoked,
                        $expired => VerifiedOrderOutcome::Expired,
                        ! $sameEnd => VerifiedOrderOutcome::Revised,
                        default => VerifiedOrderOutcome::Unchanged,
                    };
                    if (! $sameEnd || $newRevoke) {
                        $row->forceFill(['expires_at' => $end, 'changed_at' => $now]);
                        if ($newRevoke) {
                            $row->forceFill(['revoked_at' => $now]);
                        }
                        $action = $newRevoke ? self::REVOKED : self::REVISED;
                    }
                }
            }
            $audit = null;
            $expected = $row?->getAttributes();
            $targetType = $row?->getMorphClass();
            if ($row !== null && $action !== null) {
                $expected = $this->save($row, $act, $entitlement);
                $audit = $this->auditor->recordForProducerOrFail($action, $row);
            }
            $verify();
            if ($this->adjustmentAuthority($act, $entitlement) !== $authority) {
                throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
            }
            if ($freshReader) {
                $readerProof = $this->knownForReinstatement($stored, $act, $entitlement);
                if ($this->adjustmentAuthority($act, $entitlement) !== $authority) {
                    throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
                }
                $this->checkRestorationReader($readerProof, $stored, $act, $entitlement);
            }
            if ($row === null && $this->lockedRow($act, $siteId, $stored, $entitlement, $source) !== null) {
                throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
            }
            $persistedWitness = $this->checkAdjustmentReadback($row, $expected, $audit, $targetType, $action, $act, $entitlement);

            return new VerifiedOrderResult($outcome, $persistedWitness);
        });
    }

    private function adjustmentAuthority(string $act, string $entitlement): string
    {
        Permissions::forget();
        $this->checked($act, $entitlement);
        $actor = Permissions::currentUser();

        return hash('sha256', json_encode([$this->producerContext(), $actor === null ? null : [$actor::class, (string) $actor->getAuthIdentifier()]], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $witness */
    private function validWitness(#[\SensitiveParameter] array $witness, string $act, string $entitlement): void
    {
        $keys = array_keys($witness);
        sort($keys);
        if ($keys !== ['change_token', 'changed_at', 'expires_at', 'id', 'revoked_at']
            || ! is_string($witness['id']) || preg_match('/\A[1-9][0-9]{0,19}\z/D', $witness['id']) !== 1
            || ! is_string($witness['change_token']) || preg_match('/\A[a-f0-9]{64}\z/D', $witness['change_token']) !== 1
            || ! self::validInstant($witness['revoked_at']) || ! self::validInstant($witness['changed_at'])
            || ($witness['expires_at'] !== null && ! self::validInstant($witness['expires_at']))) {
            throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
        }
    }

    private function checkedOrderRow(Entitlement $row, int $siteId, #[\SensitiveParameter] string $stored, string $entitlement, #[\SensitiveParameter] string $source, string $act): void
    {
        $raw = $row->getAttributes();
        if ((string) $raw['org_id'] !== (string) $this->context->orgId() || (string) $raw['site_id'] !== (string) $siteId
            || $raw['reader_id'] !== $stored || $raw['entitlement'] !== $entitlement || $raw['source'] !== $source
            || ! is_string($raw['change_token'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $raw['change_token']) !== 1
            || ! self::validInstant($raw['changed_at']) || ($raw['expires_at'] !== null && ! self::validInstant($raw['expires_at']))
            || ($raw['revoked_at'] !== null && ! self::validInstant($raw['revoked_at']))) {
            throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
        }
        // A host's retrieved observer can rewrite a model without changing its database row. A forged in-memory
        // token must never make a manual revocation look like the producer's old witness.
        $actual = (new Entitlement)->getConnection()->table('entitlements')->useWritePdo()->where('org_id', $this->context->orgId())
            ->where('site_id', $siteId)->where('reader_id', $stored)->where('entitlement', $entitlement)->where('source', $source)->lockForUpdate()->first();
        if ($actual === null) {
            throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
        }
        foreach ($raw as $column => $value) {
            if (($value === null) !== ($actual->{$column} === null) || ($value !== null && (string) $value !== (string) $actual->{$column})) {
                throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
            }
        }
    }

    /** @return array{id:string,expires_at:?string,revoked_at:?string,changed_at:string,change_token:string} */
    private function orderWitness(Entitlement $row): array
    {
        $raw = $row->getAttributes();

        return ['id' => (string) $raw['id'], 'expires_at' => $raw['expires_at'], 'revoked_at' => $raw['revoked_at'],
            'changed_at' => $raw['changed_at'], 'change_token' => $raw['change_token']];
    }

    /** A restoration must not rely on a stale replica, cached guard object or a reader in another database.
     * @return array{connection:Connection,table:string,key:string,org:int}
     */
    private function knownForReinstatement(#[\SensitiveParameter] string $stored, string $act, string $entitlement): array
    {
        $model = $this->readers->model() ?? throw EntitlementRefused::because(EntitlementRefusal::NoReaderGuard, $act, $entitlement);
        $instance = new $model;
        $connection = (new Entitlement)->getConnection();
        $org = $this->context->orgId() ?? throw EntitlementRefused::because(EntitlementRefusal::NoOrgContext, $act, $entitlement);
        if ($instance->getConnection() !== $connection) {
            throw EntitlementRefused::because(EntitlementRefusal::UnknownReader, $act, $entitlement);
        }
        $reader = $instance->newQuery()->useWritePdo()->whereKey($stored)->where('org_id', $org)->lockForUpdate()->first();
        if (! $reader instanceof Authenticatable || $reader->getConnection() !== $connection
            || $reader->getTable() !== $instance->getTable() || $reader->getKeyName() !== $instance->getKeyName()
            || (string) $reader->getAttribute('org_id') !== (string) $org
            || $this->readers->key($reader->getAuthIdentifier()) !== $stored) {
            throw EntitlementRefused::because(EntitlementRefusal::UnknownReader, $act, $entitlement);
        }

        return ['connection' => $connection, 'table' => $instance->getTable(), 'key' => $instance->getKeyName(), 'org' => $org];
    }

    /** Raw primary read after reader/authority callbacks: no observer can leave a deleted or moved reader as proof.
     * @param  array{connection:Connection,table:string,key:string,org:int}  $proof
     */
    private function checkRestorationReader(#[\SensitiveParameter] array $proof, #[\SensitiveParameter] string $stored, string $act, string $entitlement): void
    {
        if ($proof['connection'] !== (new Entitlement)->getConnection() || $proof['org'] !== $this->context->orgId()) {
            throw EntitlementRefused::because(EntitlementRefusal::UnknownReader, $act, $entitlement);
        }
        $actual = $proof['connection']->table($proof['table'])->useWritePdo()->where($proof['key'], $stored)
            ->where('org_id', $proof['org'])->lockForUpdate()->first();
        if ($actual === null || (string) $actual->{$proof['key']} !== $stored) {
            throw EntitlementRefused::because(EntitlementRefusal::UnknownReader, $act, $entitlement);
        }
    }

    /** @param array<string,mixed>|null $expected
     * @return array{id:string,expires_at:?string,revoked_at:?string,changed_at:string,change_token:string}|null
     */
    private function checkAdjustmentReadback(?Entitlement $row, #[\SensitiveParameter] ?array $expected, ?AuditLog $audit, ?string $targetType, ?string $action, string $act, string $entitlement): ?array
    {
        if ($row === null || $expected === null) {
            return null;
        }
        $instance = new Entitlement;
        $connection = $instance->getConnection();
        $key = $expected['id'];
        $actual = $connection->table($instance->getTable())->useWritePdo()->where('id', $key)->lockForUpdate()->first();
        if ($actual === null || (string) $row->getKey() !== (string) $key || $row->getConnection() !== $connection
            || $row->getTable() !== $instance->getTable() || $row->getMorphClass() !== $targetType) {
            throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
        }
        foreach ($expected as $column => $value) {
            if (($value === null) !== ($actual->{$column} === null) || ($value !== null && (string) $value !== (string) $actual->{$column})) {
                throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
            }
        }
        if ($audit !== null) {
            $record = $audit->exists ? $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first() : null;
            if ($record === null || (string) $record->org_id !== (string) $this->context->orgId() || (string) $record->site_id !== (string) $this->context->siteId()
                || $record->action !== $action || $record->target_type !== $targetType || (string) $record->target_id !== (string) $key
                || $record->actor_type !== null || $record->actor_id !== null) {
                throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
            }
        }

        return ['id' => (string) $actual->id, 'expires_at' => $actual->expires_at, 'revoked_at' => $actual->revoked_at,
            'changed_at' => $actual->changed_at, 'change_token' => $actual->change_token];
    }

    /**
     * An owner's grant by hand, under the source `core.comp`. Gives access back only through the comp's own row,
     * with the end given now, exactly. Needs an owner signed in: a person's decision,
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
     * no such row or it already was revoked: "never granted" included, so a caller orders its refunds after their
     * grants. The reader keeps whatever other live sources give.
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
                $siteIds = $this->lockSites(null, EntitlementRefused::FORGET, null);

                // A current read, for `lockedRow()`'s reason: a row committed since a caller's snapshot is erased too.
                $rows = Entitlement::withoutScopeBecause(
                    'erasing one reader\'s entitlements across their organisation\'s sites (ADR-020)',
                    fn ($query) => $query->where('org_id', $orgId)->whereIn('site_id', $siteIds)->where('reader_id', $key)->orderBy('id')->lockForUpdate()->get(),
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
    private function checked(string $act, string $entitlement, bool $producer = false): int
    {
        $siteId = $this->authority->site($act, $entitlement);
        if (! $producer) {
            $this->authority->authorise($act, $entitlement);
        }
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
     * @param  Closure():void|null  $verify
     */
    private function give(
        string $act,
        int $siteId,
        #[\SensitiveParameter] string $stored,
        string $entitlement,
        #[\SensitiveParameter] string $source,
        CarbonImmutable $now,
        ?CarbonImmutable $end,
        bool $producer = false,
        #[\SensitiveParameter] ?Closure $verify = null,
    ): GrantOutcome {
        return TransactionRecovery::run((new Entitlement)->getConnection(), function () use ($act, $siteId, $stored, $entitlement, $source, $now, $end, $producer, $verify): GrantOutcome {
            $orgId = $this->context->orgId();
            $context = $producer ? $this->producerContext() : null;
            $row = $this->lockedRow($act, $siteId, $stored, $entitlement, $source);

            if ($producer && $row !== null) {
                $this->checkedOrderRow($row, $siteId, $stored, $entitlement, $source, $act);
            }

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
                    if (! $producer) {
                        return GrantOutcome::StillRevoked;
                    }
                    [$outcome, $action] = [GrantOutcome::StillRevoked, null];
                } else {
                    // The one place an end moves earlier, and it follows a recorded revoke.
                    $row->forceFill(['revoked_at' => null, 'expires_at' => $end, 'changed_at' => $now]);
                    [$outcome, $action] = [GrantOutcome::Reinstated, self::REINSTATED];
                }
            } elseif ($row->expires_at !== null && ($end === null || $end->greaterThan($row->expires_at))) {
                // A lapsed row of this source too: a same-source renewal, since an end at or before now was refused.
                $row->forceFill(['expires_at' => $end, 'changed_at' => $now]);
                [$outcome, $action] = [GrantOutcome::Extended, self::EXTENDED];
            } else {
                if (! $producer) {
                    return GrantOutcome::Unchanged;
                }
                [$outcome, $action] = [GrantOutcome::Unchanged, null];
            }

            $expected = $action === null ? $row->getAttributes() : $this->save($row, $act, $entitlement);
            if ($producer) {
                if ($verify === null) {
                    throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
                }
                $connection = $row->getConnection();
                $table = $row->getTable();
                $key = $row->getKey();
                $keyName = $row->getKeyName();
                $expected[$keyName] = $key;
                $targetType = $row->getMorphClass();
                $audit = $action === null ? null : $this->auditor->recordForProducerOrFail($action, $row);
                // The final proof loads host readers and can invoke their observers. Fence its current row and
                // producer audit afterwards, including unchanged and still-revoked source replays.
                $verify();
                $record = $audit !== null && $audit->exists ? $connection->table('audit_log')->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first() : null;
                $actual = $connection->table($table)->useWritePdo()->where('id', $key)->lockForUpdate()->first();
                if ($actual === null || ($audit !== null && $record === null)
                    || $row->getConnection() !== $connection || $row->getTable() !== $table
                    || $row->getKeyName() !== $keyName || (string) $row->getKey() !== (string) $key
                    || $row->getMorphClass() !== $targetType
                    || $this->context->orgId() !== $orgId || $this->context->siteId() !== $siteId
                    || $this->producerContext() !== $context) {
                    throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
                }
                if ($record !== null && ((string) $record->org_id !== (string) $orgId || (string) $record->site_id !== (string) $siteId
                    || $record->action !== $action || $record->target_type !== $targetType
                    || (string) $record->target_id !== (string) $key || $record->actor_type !== null || $record->actor_id !== null)) {
                    throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
                }
                foreach ($expected as $column => $value) {
                    if (($value === null) !== ($actual->{$column} === null)
                        || ($value !== null && (string) $value !== (string) $actual->{$column})) {
                        throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
                    }
                }
            } else {
                $this->auditor->recordOrFail($action ?? throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement), $row);
            }

            return $outcome;
        });
    }

    private function producerContext(): string
    {
        return hash('sha256', json_encode([$this->context->org()?->getAttributes(), $this->context->site()?->getAttributes()], JSON_THROW_ON_ERROR));
    }

    private static function validInstant(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value) !== 1) {
            return false;
        }
        try {
            return CarbonImmutable::createFromFormat('!'.UtcInstant::FORMAT, $value, 'UTC')?->format(UtcInstant::FORMAT) === $value;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The org's row shared and the site's locked, then this source's row, read as it is now — or null.
     *
     * ⚠️ THE SITE'S LOCK SERIALISES ONE SITE'S WRITES, so two grants of one source, or of two, take it in turn.
     * `OrgScope` applies, so only the context org's site can be locked. ⚠️ AND THE ORG'S ROW FIRST, SHARED (review):
     * this write's own inserts need the org's row shared for their foreign keys, and a writer that holds the org's
     * row (`CredentialWriter`) then needs the site's for its record's — so taking the site first made a cycle, measured
     * as a deadlock on MariaDB. Org before site is the order every writer takes them in.
     *
     * ⚠️ READ AS IT IS NOW, NOT AS A CALLER'S SNAPSHOT SAW IT (review). Inside a caller's transaction, MySQL and
     * MariaDB at REPEATABLE READ answer a plain read from that transaction's first read, so a revoke missed a grant
     * committed since — measured on MariaDB: false, and the refunded access stayed live. So a row found is read again
     * `FOR UPDATE`, which is a current read on both, and locks that row alone. A row not found: a grant inserts, and
     * the unique index refuses one committed meanwhile (`Race`); a revoke inserts nothing, so it asks again `FOR UPDATE`.
     * That takes a gap lock on MySQL and MariaDB, which only makes another insert wait, because a revoke never inserts
     * — the two-first-inserters deadlock that keeps a grant's own read plain cannot form. PostgreSQL at READ COMMITTED
     * reads each statement fresh; at REPEATABLE READ, and on SQLite in WAL mode after a caller's first read, nothing
     * escapes the snapshot — stated in ADR-040.
     *
     * @param  EntitlementRefused::*  $act
     */
    private function lockedRow(
        string $act,
        int $siteId,
        #[\SensitiveParameter] string $stored,
        string $entitlement,
        #[\SensitiveParameter] string $source,
    ): ?Entitlement {
        $this->lockSites([$siteId], $act, $entitlement);

        $row = fn () => Entitlement::query()
            ->where('site_id', $siteId)
            ->where('reader_id', $stored)
            ->where('entitlement', $entitlement)
            ->where('source', $source);

        $found = $row()->first();

        if ($found !== null) {
            return $row()->whereKey($found->getKey())->lockForUpdate()->first();
        }

        return in_array($act, [EntitlementRefused::REVOKE, EntitlementRefused::REVISE, EntitlementRefused::REINSTATE], true)
            ? $row()->lockForUpdate()->first() : null;
    }

    /**
     * The org's row shared, then these sites' rows locked in ascending order — the order every writer takes them in,
     * so two never wait on each other in a cycle.
     *
     * @param  list<int>|null  $siteIds  null for every site of the org in context
     * @param  EntitlementRefused::*  $act
     * @return list<int> the sites locked
     */
    private function lockSites(?array $siteIds, string $act, ?string $entitlement): array
    {
        Org::query()->withoutGlobalScopes()->whereKey($this->context->orgId())->sharedLock()->value('id');

        $locked = Site::query()
            ->when($siteIds !== null, fn ($query) => $query->whereKey($siteIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($siteIds !== null && $locked === []) {
            throw EntitlementRefused::because(EntitlementRefusal::SiteGone, $act, $entitlement);
        }

        return $locked;
    }

    /** @param EntitlementRefused::* $act
     * @return array<string,mixed> Expected persisted attributes, captured before host callbacks.
     */
    private function save(Entitlement $row, string $act, string $entitlement): array
    {
        $row->forceFill(['change_token' => bin2hex(random_bytes(32))]);
        $expected = $row->getAttributes();
        self::$writing = true;

        try {
            $saved = $row->save();
        } finally {
            self::$writing = false;
        }

        if (! $saved) {
            throw EntitlementRefused::because(EntitlementRefusal::Cancelled, $act, $entitlement);
        }

        return $expected;
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
