<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Illuminate\Contracts\Encryption\DecryptException;
use JsonException;
use Kitsune\Core\Entitlements\EntitlementAuthority;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Privacy\ErasureJournalCipher;

/**
 * @internal An authenticated copy of the revision receipts, held independently of a database backup.
 *
 * @phpstan-import-type Receipt from RevisionErasure
 * @phpstan-import-type ReplayCounts from RevisionErasure
 */
final class RevisionErasureJournal
{
    private const FORMAT = 'kitsune.revision-erasure.2';

    private const IDS = ['org_id', 'site_id', 'entry_id', 'entry_type_id', 'entry_revision_id', 'field_storage_id'];

    private const TEXT = ['field_handle', 'representation', 'revision_witness', 'record_target'];

    public function __construct(
        private readonly EntitlementAuthority $authority,
        private readonly ErasureJournalCipher $journalCipher,
        private readonly RevisionErasure $erasures,
    ) {}

    public function export(): string
    {
        [$org, $site] = $this->scope();
        $query = (new Entry)->getConnection()->table('erasure_log')->useWritePdo()->where('org_id', $org)->where('site_id', $site)
            ->whereNotNull('entry_revision_id');
        if ((clone $query)->where(fn ($invalid) => $invalid->whereNotNull('replacement')->orWhere('rows_rewritten', '<>', 1))->exists()) {
            throw new RevisionErasureRefused('A stored revision erasure receipt is invalid.');
        }

        $items = $query->orderBy('id')->get([...self::IDS, ...self::TEXT])
            ->map(static function (object $item): array {
                $row = (array) $item;
                foreach (self::IDS as $column) {
                    $row[$column] = (int) $row[$column];
                }

                return self::receipt($row);
            })->all();

        return $this->journalCipher->seal(json_encode([
            'format' => self::FORMAT, 'org' => $org, 'site' => $site, 'receipts' => $items,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return ReplayCounts */
    public function replay(#[\SensitiveParameter] string $journal): array
    {
        [$org, $site] = $this->scope();
        if (! app()->isDownForMaintenance()) {
            throw new RevisionErasureRefused('Revision replay requires maintenance mode.');
        }

        try {
            $payload = json_decode($this->journalCipher->unseal($journal), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new RevisionErasureRefused('The revision erasure journal could not be authenticated.');
        }

        if (! is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT
            || ($payload['org'] ?? null) !== $org || ($payload['site'] ?? null) !== $site
            || ! is_array($payload['receipts'] ?? null) || ! array_is_list($payload['receipts'])) {
            throw new RevisionErasureRefused('The revision erasure journal does not match this Org and Site.');
        }

        $receipts = array_map(self::receipt(...), $payload['receipts']);
        $groups = [];
        foreach ($receipts as $receipt) {
            if ($receipt['org_id'] !== $org || $receipt['site_id'] !== $site) {
                throw new RevisionErasureRefused('A revision receipt is outside the journal scope.');
            }
            $groups[implode(':', RevisionErasure::identity($receipt))][] = $receipt;
        }

        $counts = ['replayed' => 0, 'already_erased' => 0, 'unavailable' => 0];
        foreach ($groups as $group) {
            $result = $this->erasures->replay($group);
            foreach ($result as $status => $count) {
                $counts[$status] += $count;
            }
        }

        return $counts;
    }

    /** @return array{int, int} */
    private function scope(): array
    {
        $org = $this->authority->org(EntitlementRefused::FORGET);
        $site = $this->authority->site(EntitlementRefused::FORGET, null);
        $this->authority->authorise(EntitlementRefused::FORGET, null);

        return [$org, $site];
    }

    /**
     * @return Receipt
     */
    private static function receipt(mixed $record): array
    {
        if (! is_array($record)
            || ! is_int($record['org_id'] ?? null) || $record['org_id'] < 1
            || ! is_int($record['site_id'] ?? null) || $record['site_id'] < 1
            || ! is_int($record['entry_id'] ?? null) || $record['entry_id'] < 1
            || ! is_int($record['entry_type_id'] ?? null) || $record['entry_type_id'] < 1
            || ! is_int($record['entry_revision_id'] ?? null) || $record['entry_revision_id'] < 1
            || ! is_int($record['field_storage_id'] ?? null) || $record['field_storage_id'] < 1
            || ! is_string($record['field_handle'] ?? null) || $record['field_handle'] === ''
            || ! is_string($record['representation'] ?? null) || StorageStrategy::tryFrom($record['representation']) === null
            || ! is_string($record['revision_witness'] ?? null)
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $record['revision_witness']) !== 1
            || ! in_array($record['record_target'] ?? null, ['revision', 'entry'], true) || count($record) !== 10) {
            throw new RevisionErasureRefused('A revision erasure receipt has an invalid format.');
        }

        return $record;
    }
}
