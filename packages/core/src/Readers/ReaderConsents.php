<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\UtcInstant;
use Kitsune\Core\Models\ReaderConsentEvent;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use PDO;
use RuntimeException;

/** @internal Per-Site consent. Read at the moment optional processing begins. */
final class ReaderConsents
{
    public function __construct(
        private readonly Context $context,
        private readonly ReaderSessions $sessions,
        private readonly ReaderExport $readers,
    ) {}

    /** @return array{notice_version: string, retention_days: int}|null */
    public static function policy(Site $site, ConsentPurpose $purpose): ?array
    {
        return self::parsePolicy($site->settings[$purpose->setting()] ?? null);
    }

    /** @return array{notice_version: string, retention_days: int}|null */
    public static function parsePolicy(mixed $policy): ?array
    {
        if (! is_array($policy)) {
            return null;
        }

        $version = $policy['notice_version'] ?? null;
        $days = $policy['retention_days'] ?? null;

        if (! is_string($version) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1
            || ! is_int($days) || $days < 1 || $days > 3650) {
            return null;
        }

        return ['notice_version' => $version, 'retention_days' => $days];
    }

    public function allows(Request $request, ConsentPurpose $purpose): bool
    {
        if (GlobalPrivacyControl::requested($request)) {
            return false;
        }

        $site = $this->context->site();
        $key = ReaderGuard::stored($this->sessions->current()?->getKey());
        $policy = $site === null ? null : self::policy($site, $purpose);

        if ($site === null || $key === null || $policy === null) {
            return false;
        }

        $latest = $this->latest($site, $key, $purpose);
        $now = CarbonImmutable::now('UTC');

        return $latest !== null && $latest->granted && $latest->notice_version === $policy['notice_version']
            && $latest->expires_at !== null && $latest->expires_at->isAfter($now)
            && $latest->recorded_at->addDays($policy['retention_days'])->isAfter($now);
    }

    public function hasSavedGrant(ConsentPurpose $purpose): bool
    {
        $site = $this->context->site();
        $key = ReaderGuard::stored($this->sessions->current()?->getKey());
        if ($site === null || $key === null) {
            return false;
        }

        $latest = $this->latest($site, $key, $purpose);

        return $latest !== null && $latest->granted && $latest->expires_at !== null
            && $latest->expires_at->isAfter(now());
    }

    /** The notice version comes from the rendered form so a stale grant cannot authorize a newer notice. */
    public function record(Request $request, ConsentPurpose $purpose, bool $grant, ?string $notice): bool
    {
        $site = $this->context->site();
        $reader = $this->sessions->current();
        $key = ReaderGuard::stored($reader?->getKey());
        $connection = (new ReaderConsentEvent)->getConnection();
        if ($site === null || $reader === null || $key === null
            || $reader->getConnection() !== $connection || $site->getConnection() !== $connection) {
            return false;
        }

        return ReaderAccounts::mapped(fn (): bool => TransactionRecovery::run($connection, function () use ($request, $site, $key, $purpose, $grant, $notice): bool {
            // Erasure locks Sites before readers. The consent insert's Site foreign key takes a lock too.
            $currentSite = Site::query()->where('org_id', $site->org_id)->lockForUpdate()->find($site->id);
            if ($currentSite === null) {
                return false;
            }

            $account = $this->readers->find($key, lock: true);
            if ($account === null || $account->getConnection() !== $currentSite->getConnection()) {
                return false;
            }

            $policy = self::policy($currentSite, $purpose);
            if ($grant && ($policy === null || $notice !== $policy['notice_version'] || GlobalPrivacyControl::requested($request))) {
                return false;
            }

            $latest = $this->latest($site, $key, $purpose);

            if (! $grant && ($latest === null || ! $latest->granted)) {
                return true;
            }

            $now = CarbonImmutable::now('UTC')->startOfSecond();
            if ($grant && $latest !== null && $latest->granted && $latest->notice_version === $notice
                && $latest->expires_at !== null && $latest->expires_at->isAfter($now)
                && $latest->recorded_at->addDays($policy['retention_days'])->isAfter($now)) {
                return true;
            }

            $expires = $grant ? $now->addDays($policy['retention_days']) : null;
            $retain = $policy === null ? $now : $now->addDays($policy['retention_days']);
            if ($latest !== null && $latest->retain_until->isAfter($retain)) {
                // A shorter replacement must outlive old choices so pruning cannot reactivate an older grant.
                $retain = $latest->retain_until;
            }

            $written = ReaderConsentEvent::query()->forceCreate([
                'org_id' => $site->org_id,
                'site_id' => $site->id,
                'reader_key' => $key,
                'purpose' => $purpose->value,
                'notice_version' => $grant ? $policy['notice_version'] : $latest->notice_version,
                'granted' => $grant,
                'recorded_at' => $now,
                'expires_at' => $expires,
                'retain_until' => $retain,
            ]);
            if (! $written->exists) {
                throw new RuntimeException('The reader consent choice could not be recorded.');
            }

            return true;
        }));
    }

    /** @return list<array{site: int, purpose: string, notice_version: string, granted: bool, recorded_at: string, expires_at: ?string, retain_until: string}> */
    public function export(#[\SensitiveParameter] string $key): array
    {
        return ReaderAccounts::mapped(fn (): array => $this->subject($key)->oldest('id')->get()->map(
            static fn (ReaderConsentEvent $event): array => [
                'site' => $event->site_id,
                'purpose' => $event->purpose,
                'notice_version' => $event->notice_version,
                'granted' => $event->granted,
                'recorded_at' => $event->recorded_at->toIso8601String(),
                'expires_at' => $event->expires_at?->toIso8601String(),
                'retain_until' => $event->retain_until->toIso8601String(),
            ],
        )->values()->all());
    }

    /** Called under the subject-erasure transaction after locking the host account. @return Closure(): void */
    public function forgetWithWitness(Connection $connection, #[\SensitiveParameter] string $key, ?Connection $accountConnection): Closure
    {
        $org = $this->context->orgId() ?? throw new ReaderModuleDataUnavailable('erase');
        $native = $connection->getPdo();
        $level = $connection->transactionLevel();
        if ((new ReaderConsentEvent)->getConnection() !== $connection || $level < 1 || ! self::sameTransaction($connection, $native, $level)) {
            throw new ReaderModuleDataUnavailable('erase');
        }

        $ids = ReaderAccounts::mapped(fn (): array => $this->subject($key)->where('org_id', $org)->lockForUpdate()->pluck('id')->all());
        if ($ids !== [] && $accountConnection !== null && $accountConnection !== $connection) {
            throw new ReaderModuleDataUnavailable('erase');
        }

        foreach (array_chunk($ids, 100) as $chunk) {
            $removed = ReaderAccounts::mapped(fn (): int => $this->subject($key)->where('org_id', $org)->whereIn('id', $chunk)->delete());
            if ($removed !== count($chunk)) {
                throw new ReaderModuleDataUnavailable('erase');
            }
        }

        return function () use ($org, $key, $ids, $connection, $native, $level): void {
            ReaderAccounts::mapped(function () use ($org, $key, $ids, $connection, $native, $level): void {
                if ($this->context->orgId() !== $org || ! self::sameTransaction($connection, $native, $level)
                    || $connection->table('reader_consent_events')->useWritePdo()
                        ->where('org_id', $org)->where('reader_key', $key)->exists()) {
                    throw new ReaderModuleDataUnavailable('erase');
                }

                foreach (array_chunk($ids, 100) as $chunk) {
                    if ($connection->table('reader_consent_events')->useWritePdo()->whereIn('id', $chunk)->exists()) {
                        throw new ReaderModuleDataUnavailable('erase');
                    }
                }
            });
        };
    }

    private static function sameTransaction(Connection $connection, PDO $native, int $level): bool
    {
        return $connection->getPdo() === $native && $connection->transactionLevel() === $level && $native->inTransaction();
    }

    public function sweep(int $org): int
    {
        if ($this->context->orgId() !== $org) {
            return 0;
        }

        return ReaderAccounts::mapped(static fn (): int => ReaderConsentEvent::query()
            ->where('org_id', $org)->where('retain_until', '<=', UtcInstant::stored(now()))->delete());
    }

    private function latest(Site $site, #[\SensitiveParameter] string $key, ConsentPurpose $purpose): ?ReaderConsentEvent
    {
        return ReaderAccounts::mapped(fn (): ?ReaderConsentEvent => $this->subject($key)->useWritePdo()
            ->where('org_id', $site->org_id)->where('site_id', $site->id)
            ->where('purpose', $purpose->value)->latest('id')->first());
    }

    /** @return Builder<ReaderConsentEvent> */
    private function subject(#[\SensitiveParameter] string $key): Builder
    {
        return ReaderConsentEvent::query()->where('reader_key', $key);
    }
}
