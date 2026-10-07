<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Entitlements;

use Carbon\CarbonImmutable;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Site;

/**
 * What a reader's entitlements are, for a subject-access export — Standing Principle #8, and ADR-020's "v1.1 features
 * built on v1.0 primitives".
 *
 * ⚠️ EVERY SOURCE, `core.comp` included: the reader is entitled to know that an order gave them access and an owner gave
 * them another. It takes `EntitlementWriter::forget()`'s org, authority and key steps, inside the same mapping.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementRecords
{
    /** An instant in an export: ISO-8601, UTC. */
    private const ISO = 'Y-m-d\TH:i:s\Z';

    /**
     * Every row this reader has, from every source, on every site of the org in context, ordered by site, entitlement
     * and source, so two exports of the same rows are identical.
     *
     * @return list<array{site: string, entitlement: string, source: string, state: 'live'|'lapsed'|'revoked', expires_at: ?string, revoked_at: ?string, changed_at: string}>
     *
     * @throws EntitlementRefused NoOrgContext, NotAnOwner, ReaderActing, NotAReader, Database
     */
    public static function forReader(#[\SensitiveParameter] int|string $reader): array
    {
        $authority = app(EntitlementAuthority::class);

        return EntitlementAuthority::mapped(EntitlementRefused::EXPORT, null, function () use ($authority, $reader): array {
            $orgId = $authority->org(EntitlementRefused::EXPORT);
            $authority->authorise(EntitlementRefused::EXPORT, null);
            $key = $authority->filedKey(EntitlementRefused::EXPORT, $reader);

            /** @var array<int, string> $handles */
            $handles = Site::query()->pluck('handle', 'id')->all();

            $rows = Entitlement::withoutScopeBecause(
                'one reader\'s entitlements across their organisation\'s sites, for export (SP#8)',
                fn ($query) => $query->where('org_id', $orgId)->whereIn('site_id', array_keys($handles))->where('reader_id', $key)->get(),
            );

            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $records = [];

            foreach ($rows as $row) {
                $records[] = [
                    'site' => $handles[$row->site_id],
                    'entitlement' => $row->entitlement,
                    'source' => $row->source,
                    'state' => match (true) {
                        $row->revoked_at !== null => 'revoked',
                        $row->isLiveAt($now) => 'live',
                        default => 'lapsed',
                    },
                    'expires_at' => $row->expires_at?->format(self::ISO),
                    'revoked_at' => $row->revoked_at?->format(self::ISO),
                    'changed_at' => $row->changed_at->format(self::ISO),
                ];
            }

            // Bytes, never a collation or PHP's numeric-string comparison: the same order on every engine.
            usort($records, static fn (array $a, array $b): int => strcmp($a['site'], $b['site'])
                ?: strcmp($a['entitlement'], $b['entitlement'])
                ?: strcmp($a['source'], $b['source']));

            return $records;
        });
    }
}
