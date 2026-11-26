<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use JsonException;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Privacy\ErasureJournalCipher;
use RuntimeException;

/** @internal The authenticated backup companion for Org-wide reader erasures. */
final class ReaderErasureJournal
{
    private const FORMAT = 'kitsune.reader-erasure.3';

    private const KINDS = [ReaderErasureReceipts::SIGNUP, ReaderErasureReceipts::GRANT, ReaderErasureReceipts::ACCOUNT];

    public function __construct(
        private readonly EntitlementAuthority $authority,
        private readonly ErasureJournalCipher $journalCipher,
        private readonly ReaderErasureReceipts $receipts,
        private readonly ReaderErasure $erasure,
        private readonly ReaderAccounts $accounts,
        private readonly ReaderTokens $tokens,
        private readonly EntitlementWriter $entitlements,
    ) {}

    public function export(): string
    {
        $org = $this->org();
        $receipts = (new Entitlement)->getConnection()->table('reader_erasure_receipts')->useWritePdo()->where('org_id', $org);
        $groups = [];
        foreach (self::KINDS as $kind) {
            $anchors = (clone $receipts)->where('kind', $kind)->orderBy('anchor')->pluck('anchor')->all();
            if (! self::validAnchors($anchors)) {
                throw new ReaderErasureReplayUnavailable('A stored reader erasure receipt is invalid.');
            }
            $groups[$kind] = $anchors;
        }

        return $this->journalCipher->seal(json_encode(['format' => self::FORMAT, 'org' => $org, 'receipts' => $groups], JSON_THROW_ON_ERROR));
    }

    /** @return array{replayed: int, already_erased: int, unavailable: int, module_unavailable: int, failed: int} */
    public function replay(#[\SensitiveParameter] string $journal, ?string $restoreHost = null): array
    {
        $org = $this->org();
        if (! app()->isDownForMaintenance()) {
            throw new ReaderErasureReplayUnavailable('Reader replay requires maintenance mode.');
        }

        try {
            $payload = json_decode($this->journalCipher->unseal($journal), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new ReaderErasureReplayUnavailable('The reader erasure journal could not be authenticated.');
        }

        $groups = $payload['receipts'] ?? null;
        if (($payload['format'] ?? null) !== self::FORMAT || ($payload['org'] ?? null) !== $org
            || ! is_array($groups) || count($groups) !== count(self::KINDS)) {
            throw new ReaderErasureReplayUnavailable('The reader erasure journal does not match this Org.');
        }
        $verified = [];
        foreach (self::KINDS as $kind) {
            $anchors = $groups[$kind] ?? null;
            if (! is_array($anchors) || ! self::validAnchors($anchors)) {
                throw new ReaderErasureReplayUnavailable('The reader erasure journal contains invalid receipts.');
            }
            $verified[$kind] = $anchors;
        }

        $connection = (new Entitlement)->getConnection();
        $host = null;
        if ($verified[ReaderErasureReceipts::ACCOUNT] !== []) {
            $model = $this->accounts->model();
            $host = $model === null ? null : (new $model)->getConnection();
            if ($host !== null && $host !== $connection && ($restoreHost === null || $host->getName() !== $restoreHost)) {
                throw new ReaderErasureReplayUnavailable('Confirm the restored reader connection with --restore-host='.$host->getName().'.');
            }
        }
        if ($restoreHost !== null && ($host === null || $host === $connection)) {
            throw new ReaderErasureReplayUnavailable('--restore-host is only for account receipts on a separate reader connection.');
        }

        // A restore may predate some accounts or have a temporarily unavailable store. Keep the authentic
        // historical receipts for subsequent backups without stamping them as erased on this restored copy.
        TransactionRecovery::run($connection, function () use ($connection, $org, $verified): void {
            foreach ($verified as $kind => $anchors) {
                $this->receipts->remember($connection, $org, $anchors, $kind);
            }
        });
        $counts = ['replayed' => 0, 'already_erased' => 0, 'unavailable' => 0, 'module_unavailable' => 0, 'failed' => 0];
        foreach ($verified as $kind => $anchors) {
            foreach ($anchors as $anchor) {
                try {
                    $removed = match ($kind) {
                        ReaderErasureReceipts::SIGNUP => $this->tokens->replaySignupErasure($anchor, $this->receipts),
                        ReaderErasureReceipts::GRANT => $this->entitlements->replayErasure($anchor),
                        ReaderErasureReceipts::ACCOUNT => $this->replayAccount($anchor, $org, $connection, $host),
                    };
                    if ($removed) {
                        $counts['replayed']++;
                    } elseif ($this->receipts->completed($connection, $org, $anchor, $kind)) {
                        $counts['already_erased']++;
                    } else {
                        $counts['unavailable']++;
                    }
                } catch (ReaderModuleDataUnavailable) {
                    $counts['module_unavailable']++;
                } catch (ReaderErasureReplayUnavailable) {
                    $counts['unavailable']++;
                } catch (RuntimeException) {
                    $counts['failed']++;
                }
            }
        }

        return $counts;
    }

    private function replayAccount(string $anchor, int $org, Connection $connection, ?Connection $host): bool
    {
        if ($host === null) {
            throw new ReaderErasureReplayUnavailable('No reader store was confirmed for account replay.');
        }
        $reader = $this->receipts->readerFor($anchor, $org, $this->accounts, expectedStore: $host);
        if ($reader === null) {
            $this->receipts->finishHost($connection, $org, $anchor);

            return false;
        }

        $this->erasure->erase($reader, expectedAnchor: $anchor, expectedStore: $host);

        return true;
    }

    private function org(): int
    {
        $org = $this->authority->org(EntitlementRefused::FORGET);
        $this->authority->authorise(EntitlementRefused::FORGET, null);

        return $org;
    }

    /**
     * @param  array<mixed>  $anchors
     *
     * @phpstan-assert-if-true list<string> $anchors
     */
    private static function validAnchors(array $anchors): bool
    {
        if (! array_is_list($anchors)) {
            return false;
        }

        foreach ($anchors as $anchor) {
            if (! ReaderErasureReceipts::validAnchor($anchor)) {
                return false;
            }
        }

        return count(array_unique($anchors)) === count($anchors);
    }
}
