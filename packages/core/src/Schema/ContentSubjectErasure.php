<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Schema;

use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * @internal Applies verified subject matches across selected Sites.
 *
 * @phpstan-type Plan array{entries:list<int>, revisions:list<array{id:int,entry:int}>, history_indeterminate:int, live_covered:bool}
 * @phpstan-type Coverage array{entry_fields_erased:int, revision_fields_erased:int, live_identifier_covered:bool,
 *     unavailable_live_entries:int, indeterminate_revisions:int, refused_revisions:int}
 */
final class ContentSubjectErasure
{
    public function __construct(
        private readonly SubjectDiscovery $discovery,
        private readonly RevisionErasure $erasure,
        private readonly Context $context,
    ) {}

    /**
     * Capture every plan before any write so a shared revision redacted for one Site remains in scope
     * for the other Site plans. Each verified record is applied once.
     *
     * @param  iterable<Site>  $sites
     * @return array<string,Coverage>
     */
    public function redactInSites(iterable $sites, int $type, int $storage, #[\SensitiveParameter] bool|float|int|string $identifier): array
    {
        $org = $this->context->orgId();
        $plans = [];
        foreach ($sites as $site) {
            if ($site->org_id !== $org) {
                throw new RevisionErasureRefused('The selected Site is outside the requested Org.');
            }
            $this->context->setSite($site);
            $plans[] = [$site, $this->discovery->forSite($type, $storage, $identifier)];
        }
        if ($plans === []) {
            throw new RevisionErasureRefused('The selected Sites are unavailable.');
        }

        $results = [];
        $appliedRevisions = [];
        $appliedLive = [];
        foreach ($plans as [$site, $plan]) {
            $this->context->setSite($site);
            $results[$site->handle] = $this->apply($plan, $storage, $identifier, $appliedRevisions, $appliedLive);
        }

        return $results;
    }

    /**
     * @param  Plan  $plan
     * @param  array<int,true>  $appliedRevisions
     * @param  array<int,true>  $appliedLive
     * @return Coverage
     */
    private function apply(array $plan, int $storage, #[\SensitiveParameter] bool|float|int|string $identifier, array &$appliedRevisions, array &$appliedLive): array
    {
        $org = $this->context->orgId();
        $site = $this->context->siteId();
        $live = array_diff_key(array_fill_keys($plan['entries'], true), $appliedLive);
        $latest = [];
        foreach (array_chunk(array_keys($live), 200) as $ids) {
            $rows = EntryRevision::query()->whereIn('entry_id', $ids)->groupBy('entry_id')
                ->selectRaw('entry_id, max(id) as latest_id')->get()->toBase();
            foreach ($rows as $row) {
                $latest[(int) $row->entry_id] = (int) $row->getAttribute('latest_id');
            }
        }

        $counts = ['entry_fields_erased' => 0, 'revision_fields_erased' => 0, 'live_identifier_covered' => $plan['live_covered'],
            'unavailable_live_entries' => 0, 'indeterminate_revisions' => $plan['history_indeterminate'], 'refused_revisions' => 0];
        foreach ($plan['revisions'] as $revision) {
            if (isset($appliedRevisions[$revision['id']])) {
                continue;
            }
            $entry = $revision['entry'];
            $includeLive = ($latest[$entry] ?? null) === $revision['id'];
            try {
                try {
                    $receipts = $this->erasure->erase($entry, $revision['id'], $storage, $identifier, includeLive: $includeLive);
                } catch (RevisionLiveStateMismatch $error) {
                    if ($this->context->orgId() !== $org || $this->context->siteId() !== $site) {
                        throw $error;
                    }
                    $includeLive = false;
                    $receipts = $this->erasure->erase($entry, $revision['id'], $storage, $identifier);
                }
            } catch (RevisionErasureRefused $error) {
                if ($this->context->orgId() !== $org || $this->context->siteId() !== $site) {
                    throw $error;
                }

                $counts['refused_revisions']++;

                continue;
            }

            $appliedRevisions[$revision['id']] = true;
            foreach ($receipts as $receipt) {
                $result = $receipt['record_target'] === 'entry' ? 'entry_fields_erased' : 'revision_fields_erased';
                $counts[$result]++;
            }
            if ($includeLive) {
                $appliedLive[$entry] = true;
                unset($live[$entry]);
            }
        }
        $counts['unavailable_live_entries'] = count($live);

        return $counts;
    }
}
