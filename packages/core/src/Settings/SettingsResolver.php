<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Settings;

use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use WeakMap;

/**
 * Resolves a setting through default → org → site group → site (ADR-022).
 *
 * Sparse overrides: a level stores a key only if it overrides it, and absent
 * means inherit. That is what makes an intentional override distinguishable
 * from a stale duplicate — the failure mode of copying full config to every
 * level, where nobody can safely change anything at the top.
 *
 * Shallow merge on top-level keys only. A setting is atomic: you override the
 * whole key, never part of it. Half-inherited nested objects are the single
 * most confusing thing a config system can produce, and deep merging is
 * exactly the pain Magento's debugging extension exists to relieve.
 *
 * Bound `scoped` by `KitsuneServiceProvider`, so the memo below lives for one
 * request or one job and a long-lived worker cannot carry it into the next.
 * The defaults are `config('kitsune.settings')`.
 */
final class SettingsResolver
{
    /**
     * What each site resolved to, keyed by site id, with the org and site group that resolution read.
     *
     * ⚠️ THE TWO IDS ARE WHAT `forget()` NARROWS BY. Forgetting a site group drops the sites whose resolution
     * read that group and nothing else, so a write to one brand does not throw away every other brand's work —
     * and a write to org A cannot drop a site of org B.
     *
     * @var array<array-key, array{org: ?string, group: ?string, resolved: array<string, Resolved>}>
     */
    private array $memo = [];

    /**
     * Every resolver still alive in this process, held weakly.
     *
     * ⚠️ WEAKLY, SO "ALIVE" MEANS WHAT IT SAYS. The invalidation hook asked the container
     * `resolved(SettingsResolver::class)`, which answers whether one was EVER built in the process:
     * `forgetScopedInstances()` — what a queue worker runs before each job, and Octane between requests — drops
     * the instance and not that memory. So once any job had resolved a setting, every later job's org, site
     * group or site write built a fresh resolver only to empty it, reading the configured defaults to do so and
     * failing the write if they had become invalid since. Measured in the worker's own reset. The container is
     * what holds a scoped resolver; when it lets go and nothing else holds it, it leaves this map.
     *
     * @var WeakMap<self, true>|null
     */
    private static ?WeakMap $alive = null;

    /**
     * @param  array<string, mixed>  $defaults
     */
    public function __construct(private readonly array $defaults = [])
    {
        self::$alive ??= new WeakMap;
        self::$alive[$this] = true;
    }

    /**
     * Drop what was resolved from a level that was just written, from every resolver alive — and build none.
     *
     * The entry point for invalidation: `ScopedBuilder` calls it after every write to an org, site group or site,
     * and `KitsuneServiceProvider` after a rollback. A write that nothing has resolved against has nothing to
     * drop, and building a resolver to empty it would read the configured defaults, and fail the write if they
     * are invalid.
     *
     * Every one alive rather than the container's alone, because a resolver somebody constructed or kept holds a
     * memo that describes the rows as they were just as surely.
     */
    public static function forgetEverywhere(Org|SiteGroup|Site|null $scope = null): void
    {
        foreach (self::$alive ?? [] as $resolver => $ignored) {
            $resolver->forget($scope);
        }
    }

    /** A site's value for a key, or the platform default when no site is given. */
    public function get(?Site $site, string $key, mixed $fallback = null): mixed
    {
        $resolved = $this->resolve($site, $key);

        return $resolved === null ? $fallback : $resolved->value;
    }

    /** The same lookup, carrying where the value came from. */
    public function resolve(?Site $site, string $key): ?Resolved
    {
        return $this->all($site)[$key] ?? null;
    }

    /**
     * Every resolved key for a site, each with provenance.
     *
     * ⚠️ NO SITE MEANS THE DEFAULTS, NOT AN ERROR. An org-level page, a console command and a queued job have
     * no site in context, and each of them still has to render a date. They get the platform default, marked
     * as one.
     *
     * Memoised per request: resolution reads at most three rows and is cheap,
     * but it is on every request and must not be repeated per lookup.
     *
     * ⚠️ THE ROWS, NOT THE CALLER'S RELATIONS. This read `$site->org->settings` and
     * `$site->siteGroup->settings`, and a relation loads once per model instance — so after an org's settings
     * changed through a DIFFERENT instance, dropping the memo was not enough: resolving again through the same
     * `$site` read the org as that instance first loaded it. The same is true of the site's own attributes,
     * which is why the site row is read too. It is also why an unsaved change on the caller's instance is not
     * resolved: resolution describes what is stored.
     *
     * @return array<string, Resolved>
     */
    public function all(?Site $site): array
    {
        if ($site === null) {
            return $this->defaults();
        }

        $siteId = self::id($site->getKey());

        if ($siteId !== null && isset($this->memo[$siteId])) {
            return $this->memo[$siteId]['resolved'];
        }

        $row = $this->siteRow($site);
        $orgId = self::id($row->org_id);
        $groupId = self::id($row->site_group_id);

        $resolved = $this->defaults();

        $org = $orgId === null ? null : Org::query()->find($orgId, ['id', 'settings']);

        foreach ((array) ($org->settings ?? []) as $key => $value) {
            $resolved[$key] = new Resolved($key, $value, 'org');
        }

        $group = $groupId === null || $orgId === null ? null : $this->groupRow($groupId, $orgId);

        foreach ((array) ($group->settings ?? []) as $key => $value) {
            $resolved[$key] = new Resolved($key, $value, 'site_group', $group?->name);
        }

        foreach ((array) ($row->settings ?? []) as $key => $value) {
            $resolved[$key] = new Resolved($key, $value, 'site', $row->name);
        }

        if ($siteId !== null) {
            $this->memo[$siteId] = ['org' => $orgId, 'group' => $groupId, 'resolved' => $resolved];
        }

        return $resolved;
    }

    /**
     * Drop what was resolved from a scope and from everything beneath it — no more, no less.
     *
     * "I changed the setting and nothing happened" is a well-known support burden in scope-based config systems,
     * caused by caching (ADR-022). A write through Eloquent does not need to call this by hand: `ScopedBuilder`
     * calls `forgetEverywhere()` after every update, delete and arithmetic write to an org, site group or site —
     * the written level when the write is that model's own save, and everything when it is a bulk or relation
     * write, a delete, or one inside `withoutScopeBecause()`, whose rows it cannot name — and
     * `KitsuneServiceProvider` drops everything when a transaction rolls back. A write below Eloquent —
     * `DB::table()`, `toBase()`, raw SQL — is not seen, and needs this call.
     *
     * An org reaches every site that resolved through it, a site group every site that resolved through that
     * group, and a site only itself. Null drops everything.
     */
    public function forget(Org|SiteGroup|Site|null $scope = null): void
    {
        if ($scope === null) {
            $this->memo = [];

            return;
        }

        $id = self::id($scope->getKey());

        if ($id === null) {
            return;
        }

        if ($scope instanceof Site) {
            unset($this->memo[$id]);

            return;
        }

        $level = $scope instanceof Org ? 'org' : 'group';

        foreach ($this->memo as $siteId => $entry) {
            if ($entry[$level] === $id) {
                unset($this->memo[$siteId]);
            }
        }
    }

    /** @return array<string, Resolved> */
    private function defaults(): array
    {
        $resolved = [];

        foreach ($this->defaults as $key => $value) {
            $resolved[$key] = new Resolved($key, $value, 'default');
        }

        return $resolved;
    }

    /**
     * The site as stored, or the instance itself when it has never been.
     *
     * ⚠️ READ PAST THE ORG SCOPE, and constrained to the site's own key instead. The caller already holds this
     * site; what it does not necessarily hold is an org context — a console command or a queued job — and with
     * none, `OrgScope` matches nothing at all.
     */
    private function siteRow(Site $site): Site
    {
        if (! $site->exists) {
            return $site;
        }

        $row = Site::withoutScopeBecause(
            'settings resolution re-reads the site it was handed, which may be outside any org context',
            fn ($query) => $query->whereKey($site->getKey())->first(['id', 'org_id', 'site_group_id', 'name', 'settings']),
        );

        return $row instanceof Site ? $row : $site;
    }

    /**
     * ⚠️ READ PAST THE ORG SCOPE, AND PINNED TO THE SITE'S OWN ORG, for the reason `siteRow()` gives. Through the
     * `siteGroup` relation, a site resolved with no org context found no group at all and silently skipped the
     * brand level — returning the org's value, labelled as the org's, where the group overrides it.
     */
    private function groupRow(string $groupId, string $orgId): ?SiteGroup
    {
        $group = SiteGroup::withoutScopeBecause(
            'settings resolution reads the site group of a site it was handed, within that site\'s own org',
            fn ($query) => $query->whereKey($groupId)->where('org_id', $orgId)->first(['id', 'org_id', 'name', 'settings']),
        );

        return $group instanceof SiteGroup ? $group : null;
    }

    /** A key as the memo compares it: a string, or null when there is none. */
    private static function id(mixed $key): ?string
    {
        return is_int($key) || is_string($key) ? (string) $key : null;
    }
}
