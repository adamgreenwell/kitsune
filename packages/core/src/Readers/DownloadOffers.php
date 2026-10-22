<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementCheck;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Entitlements\EntitlementRead;
use Kitsune\Core\Entitlements\EntitlementUnavailable;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Tenancy\Context;

/**
 * A bounded page of current private offers. The offset sees only this reader's eligible rows: confidential offers
 * never displace a row or advertise another page. A final batch rechecks the page AND lookahead before rendering.
 * This lists offers, not storage: only the existing landing URL is returned, and that door checks access/bytes anew.
 * No totals, disk reads, grant sources, paths or direct-byte URLs. ReaderAccount stays unchanged.
 *
 * @internal First-party modules only; no public extension contract.
 */
final class DownloadOffers
{
    public const PAGE_SIZE = 24;

    public const LAST_PAGE = 10000;

    public function __construct(
        private readonly Context $context,
        private readonly EntitlementRead $read,
        private readonly EntitlementCheck $check,
        private readonly ReaderGuard $readers,
    ) {}

    /** @return array{offers: list<array{title: string, href: string}>, next: bool} */
    public function page(int $page): array
    {
        $site = $this->context->site();
        $orgId = $this->context->orgId();

        if ($site === null || $orgId === null || $page < 1 || $page > self::LAST_PAGE) {
            return ['offers' => [], 'next' => false];
        }

        $siteId = (int) $site->getKey();
        $identity = $this->readers->current();
        $held = $this->read->current();

        if ($held === null || $identity === null) {
            return ['offers' => [], 'next' => false];
        }

        $this->assertContext($siteId, $orgId, $identity);

        $held->select('entitlements.id')->whereColumn('entitlements.entitlement', 'media_files.reader_entitlement');

        // The ordinary equality keeps the indexed read. MySQL/MariaDB's collation also equates malformed uppercase,
        // accented and padded spellings: an unheld malformed offer must not enter this private page even as an error.
        // These are fixed internal columns, wrapped by the connection grammar (including configured table prefixes).
        $grammar = $held->getQuery()->getGrammar();

        if ($grammar instanceof MySqlGrammar) {
            $held->whereRaw('CAST('.$grammar->wrap('entitlements.entitlement').' AS BINARY) = CAST('
                .$grammar->wrap('media_files.reader_entitlement').' AS BINARY)');
        }

        $query = Entry::query()
            ->where('entries.site_id', $site->getKey())
            ->where('entries.org_id', $orgId)
            ->join('media_files', 'media_files.entry_id', '=', 'entries.id')
            ->where('media_files.visibility', 'private')
            ->whereNotNull('media_files.reader_entitlement')
            ->whereHas('entryType', fn (Builder $type) => $type->where('is_media', true)
                ->where(fn (Builder $owner) => $owner->whereNull('org_id')->orWhere('org_id', $orgId)))
            ->whereExists($held->toBase());

        $candidates = EntryTypeAvailability::constrainEnabledEntries($query, $site)
            ->orderBy('entries.id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)
            ->get(['entries.id', 'entries.title', 'entries.entry_type_id', 'media_files.reader_entitlement as kitsune_reader_gate']);

        $names = [];

        foreach ($candidates as $entry) {
            $gate = $entry->getAttribute('kitsune_reader_gate');

            if (! is_string($gate) || ! EntitlementName::isName($gate)) {
                // A matching malformed persisted grant is corruption, never a partial page with metadata or Next.
                throw EntitlementUnavailable::forBatch('HY000');
            }

            $names[$gate] = $gate;
        }

        $this->assertContext($siteId, $orgId, $identity);
        $answers = $this->check->holdsMany(array_values($names));
        $enabled = EntryTypeAvailability::enabledMapFor($candidates->pluck('entry_type_id')->unique()->all(),
            $site->getKey(), $site->site_group_id, $site->org_id);
        $this->assertContext($siteId, $orgId, $identity);
        $offers = [];

        foreach ($candidates as $entry) {
            // Only the exact validated requested key can answer. Additional Check answer keys convey no authority.
            if (($answers[$entry->getAttribute('kitsune_reader_gate')] ?? false) !== true
                || ($enabled[$entry->entry_type_id] ?? false) !== true) {
                continue;
            }

            $offers[] = ['title' => (string) $entry->title, 'href' => ReaderLinks::download($site, (int) $entry->getKey())];
        }

        $next = count($offers) > self::PAGE_SIZE && $page < self::LAST_PAGE;

        return ['offers' => array_slice($offers, 0, self::PAGE_SIZE), 'next' => $next];
    }

    /** A changed request cannot borrow a same-named grant from another reader/Site and render the captured offers. */
    private function assertContext(int $siteId, int $orgId, #[\SensitiveParameter] string $identity): void
    {
        if ($this->context->siteId() !== $siteId || $this->context->orgId() !== $orgId
            || $this->readers->current() !== $identity) {
            throw EntitlementUnavailable::forBatch('HY000');
        }
    }
}
