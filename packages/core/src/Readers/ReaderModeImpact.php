<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

use Illuminate\Database\Eloquent\Collection;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Scopes\OrgMembershipScope;
use Kitsune\Core\Tenancy\Scopes\OrgScope;
use Kitsune\Core\Tenancy\Scopes\SiteScope;
use Kitsune\Core\Tenancy\ScopeWrites;
use RuntimeException;

/**
 * Read-only warning before the operator closes reader pages (ADR-041, #251).
 *
 * Counts recorded offers, not readers or presently downloadable bytes. No file
 * title, entitlement name, disk or grant is read. This is an advisory snapshot,
 * not a lock on concurrent offering or settings changes.
 *
 * @internal First-party command implementation, not an extension API.
 */
final class ReaderModeImpact
{
    public function __construct(
        private readonly Context $context,
        private readonly SettingsResolver $settings,
    ) {}

    /** @return array{files: int, sites: int} */
    public function count(Org|Site $scope, string $value): array
    {
        $orgId = $this->context->orgId();
        $owner = $scope instanceof Org ? $scope->getKey() : $scope->org_id;

        if ($orgId === null || (string) $owner !== (string) $orgId) {
            throw new RuntimeException('Refusing to count reader offers outside the current organisation. Nothing was read or written.');
        }

        if (! in_array($value, [...array_column(ReaderMode::cases(), 'value'), 'inherit'], true)) {
            throw new RuntimeException('Refusing to preview an unknown reader account mode. Nothing was read or written.');
        }

        $total = ['files' => 0, 'sites' => 0];

        if (in_array($value, [ReaderMode::Open->value, ReaderMode::SignIn->value], true)) {
            return $total;
        }

        $query = Site::on($scope->getConnectionName())->where('org_id', $orgId);

        if ($scope instanceof Site) {
            $query->whereKey($scope->getKey());
        }

        // Both the site rows and each grouped offer count are bounded. A large
        // org never becomes one unbounded IN list or a collection of its files.
        $query->chunkById(100, function (Collection $sites) use ($scope, $value, $orgId, &$total): void {
            $closing = [];

            foreach ($sites as $site) {
                $this->settings->forget($site);

                try {
                    $before = self::mode($this->settings->get($site, SettingsGuard::READER_ACCOUNTS));

                    if ($before->signsIn() && $this->after($site, $scope, $value) === ReaderMode::Off) {
                        $closing[] = (int) $site->getKey();
                    }
                } finally {
                    $this->settings->forget($site);
                }
            }

            if ($closing !== []) {
                $counts = self::offers($closing, $orgId, $scope->getConnectionName());
                $total['files'] += array_sum($counts);
                $total['sites'] += count($counts);
            }
        });

        return $total;
    }

    private function after(Site $site, Org|Site $scope, string $value): ReaderMode
    {
        if ($scope instanceof Org) {
            $resolved = $this->settings->resolve($site, SettingsGuard::READER_ACCOUNTS);

            // A lower-level override survives an org write or revert.
            if ($resolved !== null && in_array($resolved->origin, ['site', 'site_group'], true)) {
                return self::mode($resolved->value);
            }

            return $value === 'inherit'
                ? self::mode($this->settings->get(null, SettingsGuard::READER_ACCOUNTS))
                : ReaderMode::Off;
        }

        if ($value !== 'inherit') {
            return ReaderMode::Off;
        }

        // SettingsResolver explicitly resolves an unsaved Site from its supplied
        // attributes and the stored, org-fenced parent rows. A keyless copy avoids
        // both re-reading this site's override and hitting its saved-id memo.
        // Nothing is saved, even temporarily, to preview a revert.
        $preview = clone $site;
        $preview->exists = false;
        $preview->setAttribute($preview->getKeyName(), null);
        $settings = $site->settings ?? [];
        unset($settings[SettingsGuard::READER_ACCOUNTS]);
        $preview->setAttribute('settings', $settings);

        return self::mode($this->settings->get($preview, SettingsGuard::READER_ACCOUNTS));
    }

    private static function mode(mixed $value): ReaderMode
    {
        return is_string($value) ? (ReaderMode::tryFrom($value) ?? ReaderMode::Off) : ReaderMode::Off;
    }

    /**
     * The command has an Org but no Site context. Cross the Site scope only for
     * these explicitly selected site keys AND their owning Org. As in
     * SettingsResolver, build the crossing on the scope's own connection: the
     * static withoutScopeBecause() hatch cannot take a connection (ADR-022).
     * The soft-delete scope stays, so trashed offers do not enter this count.
     *
     * @param  list<int>  $sites
     * @return array<int, int>
     */
    private static function offers(array $sites, int $orgId, ?string $connection): array
    {
        $rows = ScopeWrites::suspend(static fn () => Entry::on($connection)
            ->withoutGlobalScopes([SiteScope::class, OrgScope::class, OrgMembershipScope::class])
            ->where('entries.org_id', $orgId)
            ->whereIn('entries.site_id', $sites)
            ->join(MediaFile::TABLE, 'media_files.entry_id', '=', 'entries.id')
            ->where('media_files.visibility', 'private')
            ->whereNotNull('media_files.reader_entitlement')
            ->where('media_files.reader_entitlement', '<>', '')
            ->select('entries.site_id')
            ->selectRaw('count(*) as offered')
            ->groupBy('entries.site_id')
            ->get());
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->site_id] = (int) $row->getAttribute('offered');
        }

        return $counts;
    }
}
