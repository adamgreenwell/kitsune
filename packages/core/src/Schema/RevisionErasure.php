<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Illuminate\Database\Connection;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * @internal Selective revision redaction and replay of trusted receipts from the same installation.
 *
 * @phpstan-type ReceiptIdentity array{org_id: int, site_id: int, entry_id: int, entry_type_id: int, entry_revision_id: int, revision_witness: string}
 * @phpstan-type Receipt array{org_id: int, site_id: int, entry_id: int, entry_type_id: int, entry_revision_id: int, revision_witness: string,
 *     field_storage_id: int, field_handle: string, representation: string, record_target: 'revision'|'entry'}
 * @phpstan-type ReplayCounts array{replayed: int, already_erased: int, unavailable: int}
 */
final class RevisionErasure
{
    public function __construct(
        private readonly EntitlementAuthority $authority,
        private readonly LiveEntryRedaction $live,
    ) {}

    /**
     * @return list<Receipt>
     */
    public function erase(int $entryId, int $revisionId, int $subjectStorage, #[\SensitiveParameter] bool|float|int|string $identifier, bool $includeLive = false): array
    {
        return $this->authorized(function (Site $site, Connection $connection) use ($entryId, $revisionId, $subjectStorage, $identifier, $includeLive): array {
            $entry = $this->lockedEntry($site, $entryId);
            if ($entry === null) {
                throw new RevisionErasureRefused('The revision cannot be attributed to the requested subject in this Site.');
            }
            $revision = $entry->revisions()->lockForUpdate()->find($revisionId);
            if ($revision === null || RevisionSubject::matches($revision, $site, $subjectStorage, $identifier) !== true) {
                throw new RevisionErasureRefused('The revision cannot be attributed to the requested subject in this Site.');
            }

            $schema = $revision->subject_schema;
            if (($schema['strategy'] ?? null) === StorageStrategy::Relational->value
                && (! is_int($identifier) || ! Entry::query()->withTrashed()->where('org_id', $site->org_id)->whereKey($identifier)->exists())) {
                throw new RevisionErasureRefused('The revision subject is unavailable in this Site.');
            }
            $token = $schema['witness'] ?? null;
            $fields = $schema['fields'] ?? null;
            if (! is_string($token) || ! is_array($fields)) {
                throw new RevisionErasureRefused('The revision has no replayable attribution witness.');
            }

            if ($includeLive && (! $this->live->hasVersion($entry, $revision)
                || ! is_string($schema['strategy'] ?? null) || ! is_string($schema['key'] ?? null)
                || ! $this->live->agrees($entry, $revision, $schema['strategy'], $schema['key']))) {
                throw new RevisionLiveStateMismatch('The live entry does not match the witnessed revision.');
            }

            foreach ($fields as $handle => $spec) {
                if (! is_string($handle) || ! is_array($spec) || ! is_int($spec['storage'] ?? null)
                    || ! is_string($spec['strategy'] ?? null) || ! is_string($spec['key'] ?? null)) {
                    throw new RevisionErasureRefused('The captured classification cannot be used for erasure.');
                }
                if ($includeLive && ! $this->live->agrees($entry, $revision, $spec['strategy'], $spec['key'])) {
                    throw new RevisionLiveStateMismatch('A live field differs from the witnessed revision.');
                }
            }

            $receipts = [];
            foreach ($fields as $handle => $spec) {
                $receipt = [
                    'org_id' => (int) $site->org_id, 'site_id' => (int) $site->id, 'entry_id' => $entryId,
                    'entry_type_id' => (int) $revision->entry_type_id, 'entry_revision_id' => $revisionId,
                    'field_storage_id' => $spec['storage'], 'revision_witness' => $token,
                    'field_handle' => $handle, 'representation' => $spec['strategy'],
                ];
                if ($includeLive) {
                    if (! $this->live->agrees($entry, $revision, $spec['strategy'], $spec['key'])) {
                        throw new RevisionErasureRefused('A live field differs from the witnessed revision.');
                    }
                    if ($this->live->redact($entry, $spec['strategy'], $spec['key'])) {
                        $liveReceipt = $receipt + ['record_target' => 'entry'];
                        $receipts[$this->recordReceipt($connection, $liveReceipt)] = $liveReceipt;
                    }
                }
                if ($this->redact($revision, $spec['strategy'], $spec['key'])) {
                    $revisionReceipt = $receipt + ['record_target' => 'revision'];
                    $receipts[$this->recordReceipt($connection, $revisionReceipt)] = $revisionReceipt;
                }
            }

            $revision->refresh();
            if ($revision->subject_schema !== $schema || ($includeLive && ! $this->live->hasVersion($entry->refresh(), $revision))) {
                throw new RevisionErasureRefused('The entry or revision attribution changed during erasure.');
            }

            foreach ($receipts as $receiptId => $receipt) {
                $spec = $fields[$receipt['field_handle']];
                $retained = $receipt['record_target'] === 'entry'
                    ? $this->live->retainsValue($entry->refresh(), $receipt['representation'], $spec['key'])
                    : $this->retainsValue($revision, $receipt['representation'], $spec['key']);
                if ($retained) {
                    throw new RevisionErasureRefused('The revision retained data after the erasure write.');
                }

                $query = $connection->table('erasure_log')->useWritePdo()->where('id', $receiptId)->whereNull('replacement')->where('rows_rewritten', 1);
                if (! $query->where($receipt)->exists()) {
                    throw new RevisionErasureRefused('The revision erasure receipt could not be verified.');
                }
            }

            return array_values($receipts);
        });
    }

    /**
     * The restore coordinator authenticates the journal before replay. Prove the live subject before writes.
     * If the live version has changed, its receipts remain unavailable while the witnessed history can be erased.
     *
     * @param  non-empty-list<Receipt>  $receipts
     * @return ReplayCounts
     */
    public function replay(array $receipts): array
    {
        return $this->authorized(function (Site $site, Connection $connection) use ($receipts): array {
            $identity = self::identity($receipts[0]);
            $unavailable = ['replayed' => 0, 'already_erased' => 0, 'unavailable' => count($receipts)];
            if ($identity['org_id'] !== (int) $site->org_id || $identity['site_id'] !== (int) $site->id) {
                return $unavailable;
            }
            $entry = $this->lockedEntry($site, $identity['entry_id']);
            if ($entry === null) {
                return $unavailable;
            }
            $revision = $entry->revisions()->lockForUpdate()->find($identity['entry_revision_id']);
            $schema = $revision?->subject_schema;
            if ($revision === null || (int) $revision->entry_type_id !== $identity['entry_type_id']
                || ($schema['org'] ?? null) !== $identity['org_id']
                || (($schema['site'] ?? null) !== null && $schema['site'] !== $identity['site_id'])
                || ($schema['witness'] ?? null) !== $identity['revision_witness']) {
                return $unavailable;
            }

            $keys = [];
            $hasLive = false;
            foreach ($receipts as $index => $receipt) {
                $spec = $schema['fields'][$receipt['field_handle']] ?? null;
                if (self::identity($receipt) !== $identity || ! is_array($spec)
                    || ($spec['storage'] ?? null) !== $receipt['field_storage_id']
                    || ($spec['strategy'] ?? null) !== $receipt['representation'] || ! is_string($spec['key'] ?? null)) {
                    return $unavailable;
                }
                $keys[$index] = $spec['key'];
                $hasLive = $hasLive || $receipt['record_target'] === 'entry';
            }

            $liveMatches = $hasLive && $this->live->hasVersion($entry, $revision)
                && is_string($schema['strategy'] ?? null) && is_string($schema['key'] ?? null)
                && $this->live->agrees($entry, $revision, $schema['strategy'], $schema['key']);
            if ($liveMatches) {
                foreach ($receipts as $index => $receipt) {
                    if ($receipt['record_target'] === 'entry' && ! $this->live->agrees($entry, $revision, $receipt['representation'], $keys[$index])) {
                        $liveMatches = false;
                        break;
                    }
                }
            }

            $counts = ['replayed' => 0, 'already_erased' => 0, 'unavailable' => 0];
            $applied = [];
            foreach ($receipts as $index => $receipt) {
                if ($receipt['record_target'] === 'entry' && ! $liveMatches) {
                    $counts['unavailable']++;

                    continue;
                }
                $applied[$index] = $receipt;
                $changed = $receipt['record_target'] === 'entry'
                    ? $this->live->redact($entry, $receipt['representation'], $keys[$index])
                    : $this->redact($revision, $receipt['representation'], $keys[$index]);
                $counts[$changed ? 'replayed' : 'already_erased']++;

                $stored = $connection->table('erasure_log')->useWritePdo()->where($receipt)->whereNull('replacement')->where('rows_rewritten', 1);
                if (! $stored->exists()) {
                    $receiptId = $this->recordReceipt($connection, $receipt);
                    if (! (clone $stored)->where('id', $receiptId)->exists()) {
                        throw new RevisionErasureRefused('The replay receipt could not be verified.');
                    }
                }
            }

            $revision->refresh();
            $entry->refresh();
            if ($revision->subject_schema !== $schema || ($liveMatches && ! $this->live->hasVersion($entry, $revision))) {
                throw new RevisionErasureRefused('The entry or revision attribution changed after replay.');
            }
            foreach ($applied as $index => $receipt) {
                $retains = $receipt['record_target'] === 'entry'
                    ? $this->live->retainsValue($entry, $receipt['representation'], $keys[$index])
                    : $this->retainsValue($revision, $receipt['representation'], $keys[$index]);
                if ($retains) {
                    throw new RevisionErasureRefused('The entry or revision retained data after replay.');
                }
            }

            return $counts;
        });
    }

    /**
     * @param  Receipt  $receipt
     * @return ReceiptIdentity
     */
    public static function identity(array $receipt): array
    {
        return [
            'org_id' => $receipt['org_id'], 'site_id' => $receipt['site_id'],
            'entry_id' => $receipt['entry_id'], 'entry_type_id' => $receipt['entry_type_id'],
            'entry_revision_id' => $receipt['entry_revision_id'], 'revision_witness' => $receipt['revision_witness'],
        ];
    }

    private function lockedEntry(Site $site, int $entryId): ?Entry
    {
        return Entry::query()->withTrashed()->where('org_id', $site->org_id)->lockForUpdate()->find($entryId);
    }

    /** @param array<string,int|string> $receipt */
    private function recordReceipt(Connection $connection, array $receipt): int
    {
        return (int) $connection->table('erasure_log')->insertGetId($receipt + [
            'replacement' => null, 'rows_rewritten' => 1, 'erased_at' => now('UTC'),
        ]);
    }

    private function redact(EntryRevision $revision, string $strategy, string $key): bool
    {
        $before = $this->retainsValue($revision, $strategy, $key);
        if (! $before) {
            return false;
        }

        match ($strategy) {
            StorageStrategy::Inline->value => $revision->redactValue($key),
            StorageStrategy::Promoted->value => $key !== 'status' && in_array($key, EntryRevision::REDACTABLE_COLUMNS, true)
                ? $revision->redactColumn($key) : throw new RevisionErasureRefused('A non-nullable revision field needs an explicit erasure policy.'),
            StorageStrategy::Relational->value => $this->redactRelation($revision, $key),
            default => throw new RevisionErasureRefused('The revision representation cannot be redacted.'),
        };

        $revision->refresh();
        if ($this->retainsValue($revision, $strategy, $key)) {
            throw new RevisionErasureRefused('The revision retained data after the erasure write.');
        }

        return true;
    }

    private function retainsValue(EntryRevision $revision, string $strategy, string $key): bool
    {
        return match ($strategy) {
            StorageStrategy::Inline->value => ($revision->values[$key] ?? null) !== null
                || array_key_exists($key, $revision->unsanitized_values ?? []),
            StorageStrategy::Promoted->value => $revision->getAttribute($key) !== null,
            StorageStrategy::Relational->value => ($revision->relation_state[$key] ?? []) !== [],
            default => throw new RevisionErasureRefused('The revision representation cannot be redacted.'),
        };
    }

    private function redactRelation(EntryRevision $revision, string $key): bool
    {
        $state = $revision->relation_state ?? [];
        $state[$key] = null;
        $revision->relation_state = $state;

        return $revision->save();
    }

    /**
     * @template T
     *
     * @param  callable(Site, Connection): T  $work
     * @return T
     */
    private function authorized(callable $work): mixed
    {
        return EntitlementAuthority::mapped(EntitlementRefused::FORGET, null, function () use ($work): mixed {
            $orgId = $this->authority->org(EntitlementRefused::FORGET);
            $siteId = $this->authority->site(EntitlementRefused::FORGET, null);
            $this->authority->authorise(EntitlementRefused::FORGET, null);
            $connection = (new Entry)->getConnection();

            return TransactionRecovery::run($connection, function () use ($orgId, $siteId, $connection, $work): mixed {
                $site = Site::query()->where('org_id', $orgId)->lockForUpdate()->find($siteId)
                    ?? throw new RevisionErasureRefused('The selected Site is unavailable for erasure.');

                $native = $connection->getPdo();
                $level = $connection->transactionLevel();
                $result = $work($site, $connection);
                $context = app(Context::class);
                if ($context->orgId() !== $orgId || $context->siteId() !== $siteId
                    || $connection->getPdo() !== $native || $connection->transactionLevel() !== $level || ! $native->inTransaction()) {
                    throw new RevisionErasureRefused('The revision erasure lost its Site or transaction.');
                }

                return $result;
            });
        });
    }
}
