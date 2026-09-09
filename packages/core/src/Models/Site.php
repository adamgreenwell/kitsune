<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * Anything with its own base URL. Carries the locale (ADR-021), so
 * golfdom.com and golfdom.fr are two sites in one group rather than a site
 * with a locale axis bolted on.
 *
 * Org-scoped, not site-scoped: a Site cannot be scoped to itself, and the
 * site switcher must list every site the current org owns.
 */
/**
 * @property int $id
 * @property int $org_id
 * @property int|null $site_group_id
 * @property string $handle
 * @property string $slug
 * @property string $name
 * @property string $locale
 * @property string $url_strategy
 * @property string|null $base_url
 * @property string|null $canonical_host
 * @property string|null $path_prefix
 * @property bool $is_primary
 * @property array<string, mixed>|null $settings
 */
#[OrgScoped]
class Site extends Model implements RefusesCascadingDeletes, RequiresModelSave
{
    use EnforcesScope;

    protected $guarded = [];

    /**
     * ⚠️ `url_strategy` IS DEFAULTED ON THE MODEL, NOT ONLY IN THE MIGRATION, because it is
     * read in PHP before the row exists. A column default applies at INSERT, so the
     * attribute is still null while the `saving` hook is deriving the URL columns from it —
     * which made every save of a site that did not name a strategy explicitly fail.
     *
     * It must stay equal to the migration's default. Duplicated deliberately: the
     * alternative is reading the schema at runtime to answer a question about a new model.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'url_strategy' => 'path',
    ];

    /**
     * How many path segments a `base_url` prefix may claim.
     *
     * ⚠️ ONE NUMBER FOR TWO PLACES, and that is the point. `ResolveSiteFromRequest` turns a
     * request path into candidate prefixes and asks for them in ONE query, so an unbounded
     * depth would make the query's size a function of a URL a stranger chooses — untrusted
     * input (invariant 6) spending the 1 vCPU / 1 GB floor of ADR-027.
     *
     * A cap on the resolver alone would silently make a deeper configured site unreachable,
     * which is the defect review found in the single-segment version. So the derivation
     * REFUSES a deeper prefix instead: the cap can never be the reason a site that saved
     * successfully cannot be found.
     */
    public const MAX_PREFIX_SEGMENTS = 4;

    /** The URL strategies ADR-021 defines. Anything else is a typo, not a fourth strategy. */
    private const STRATEGIES = ['path', 'subdomain', 'domain'];

    /**
     * Keeps the derived URL columns in step with `base_url`.
     *
     * ⚠️ ON `saving`, NOT IN A SETTER OR THE CALLER, because the whole point is that
     * they cannot disagree. A caller that set `base_url` and forgot the derived pair
     * would make the site publicly unreachable — or worse, leave it answering on a
     * hostname it no longer claims. Derived where the write happens is the only place
     * a mass assignment, a seeder and an admin form all pass through.
     *
     * ⚠️ It sets them even when `base_url` is null, so CLEARING a base URL withdraws
     * the site's public address rather than leaving the last one behind.
     */
    /**
     * The columns a bulk write must not touch, with the reason.
     *
     * ⚠️ THE `saving` HOOK BELOW IS NOT ENOUGH ON ITS OWN, and review found that gap here.
     * `Site::query()->update(['base_url' => ...])` dispatches no model events, so the derived
     * pair keeps the OLD address: the site stays reachable at a URL it no longer declares and
     * cannot be reached at the one it now stores. Worse than a failed write, because nothing
     * reports it.
     *
     * This is the seventh instance of the same defect in this project, which is precisely why
     * `RequiresModelSave` exists — see its docblock. `ScopedBuilder::update()` consults this
     * list and refuses, making the model event the only door rather than the first one.
     * `upsert()` is already refused outright for every scoped model, so it needs nothing here.
     *
     * @return array<string, string>
     */
    public static function columnsRequiringModelSave(): array
    {
        return [
            'base_url' => 'canonical_host and path_prefix are derived from it on save, and a bulk '
                .'write skips that derivation — leaving the site answering on its previous '
                .'address and unreachable at its new one.',
            'url_strategy' => 'it decides whether a bare base_url names a host or a path prefix, '
                .'so changing it in bulk re-points the site without recomputing the columns that '
                .'actually resolve it.',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $site): void {
            [$site->canonical_host, $site->path_prefix] = self::deriveUrlParts($site->base_url, $site->url_strategy);
        });
    }

    /**
     * The canonical host and path prefix a base URL claims.
     *
     * ⚠️ `base_url` HAS A HOST-LESS FORM, and that is what a `path` site is. ADR-021
     * gives `https://example.com/fr` as a path-prefix example, which pins the prefix to
     * one host; `/fr` means the same prefix on whatever host serves the installation,
     * which is what a single-domain multi-language install actually wants and the only
     * form that survives being served from a different address in development.
     *
     * ⚠️ A BARE VALUE IS AMBIGUOUS, AND ONLY `url_strategy` RESOLVES IT — which is why this
     * takes the strategy rather than defaulting it. `x.test` and `fr` are the same shape, so
     * a parser looking only at the string has to guess. It guessed "prefix", and a
     * `domain` site configured as a bare `x.test` was stored as `canonical_host = ''` with
     * `path_prefix = '/x.test'`: unreachable at `https://x.test/`, and claiming
     * `http://any-host/x.test` instead. That also contradicted this project's own written
     * promise that `https://x.test/`, `http://x.test` and a bare `x.test` all name one host.
     *
     * The strategy is REQUIRED rather than defaulted, because a default is how the same
     * guess comes back: a caller that forgets it would silently get the `path` reading.
     *
     * An explicit scheme still wins over the strategy. An operator who wrote
     * `https://example.com/fr` has said where the host ends, whatever the column says.
     *
     * @return array{0: string|null, 1: string|null} host then prefix; null both when
     *                                               the site has no public URL at all
     *
     * @throws RuntimeException on an unknown strategy, or a prefix deeper than
     *                          MAX_PREFIX_SEGMENTS
     */
    public static function deriveUrlParts(?string $baseUrl, string $strategy): array
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw new RuntimeException(sprintf(
                'Unknown url_strategy [%s]. Expected one of: %s. Refused rather than read as a '
                .'path prefix: a typo would otherwise store a host as a prefix and leave the '
                .'site unreachable at its own address.',
                $strategy,
                implode(', ', self::STRATEGIES),
            ));
        }

        if ($baseUrl === null || trim($baseUrl) === '') {
            // No public URL. Both null, and NULLs compare distinct in the unique index,
            // so every admin-only site coexists.
            return [null, null];
        }

        $baseUrl = trim($baseUrl);

        // A leading `//` would be a protocol-relative URL.
        $explicit = str_contains($baseUrl, '://') || str_starts_with($baseUrl, '//');

        // A `domain` or `subdomain` site names a host even when written bare; a `path` site
        // never does.
        $namesHost = $explicit || $strategy === 'domain' || $strategy === 'subdomain';

        // ⚠️ A host-less value is forced to start with `/` before the placeholder host is
        // prepended. Without it, `fr` concatenated to `kitsune://placeholder` parses as the
        // HOST `placeholderfr` with an empty path — so a prefix written without its leading
        // slash silently became "any host, site root", which is the broadest match there is.
        $parsed = parse_url(match (true) {
            $explicit => $baseUrl,
            $namesHost => 'kitsune://'.ltrim($baseUrl, '/'),
            default => 'kitsune://placeholder/'.ltrim($baseUrl, '/'),
        });

        if ($parsed === false) {
            return [null, null];
        }

        $host = $namesHost && is_string($parsed['host'] ?? null) ? $parsed['host'] : '';
        $path = is_string($parsed['path'] ?? null) ? $parsed['path'] : '';

        return [self::canonicalHost($host), self::canonicalPrefix($path)];
    }

    /**
     * A hostname reduced to one spelling.
     *
     * ⚠️ Lowercased and stripped of a trailing dot, because `EXAMPLE.TEST`,
     * `example.test` and `example.test.` are the same host and a unique index cannot
     * know that. Without this, two orgs could each hold what looks like a distinct
     * `base_url` and both answer on one hostname, with row order deciding which — the
     * cross-org failure ADR-021 says has no framework safety net.
     *
     * The PORT is deliberately dropped: a site is not a different site on :8443, and
     * keeping it would make a development address fail to match its own configuration.
     */
    private static function canonicalHost(string $host): string
    {
        return rtrim(mb_strtolower(trim($host)), '.');
    }

    /**
     * A path prefix reduced to one spelling: empty, or `/segment` (up to
     * MAX_PREFIX_SEGMENTS of them).
     *
     * `/fr`, `fr`, `/fr/` and `//fr` all name the same prefix. Empty string means the
     * site root, which is a real value rather than an absent one — it is what a
     * domain-addressed site has.
     *
     * ⚠️ Empty segments are DROPPED rather than preserved, so `news//fr` and `news/fr` are
     * one prefix. A stored prefix carrying an empty segment could never be matched: the
     * resolver rebuilds candidates from a request's own segments, and a browser does not
     * send an empty one.
     *
     * ⚠️ REFUSES a deeper prefix, loudly, rather than storing something unreachable. See
     * MAX_PREFIX_SEGMENTS: the resolver is bounded by the same constant, so accepting a
     * deeper value here would save a site that no request could ever reach.
     */
    private static function canonicalPrefix(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        if ($segments === []) {
            return '';
        }

        if (count($segments) > self::MAX_PREFIX_SEGMENTS) {
            throw new RuntimeException(sprintf(
                'A base_url path prefix may claim at most %d segments, and [%s] claims %d. '
                .'Refused rather than stored: site resolution builds candidate prefixes from '
                .'the request path and is bounded by the same number, so a deeper prefix would '
                .'save a site that no request could reach.',
                self::MAX_PREFIX_SEGMENTS,
                $path,
                count($segments),
            ));
        }

        return '/'.mb_strtolower(implode('/', $segments));
    }

    protected $casts = [
        'settings' => 'array',
        'is_primary' => 'boolean',
    ];

    /**
     * The route key is the globally unique slug, never the org-unique handle.
     *
     * /admin/{site} carries no org segment, so the segment identifying a site
     * must be unique across the installation. handle is unique only within an
     * org, which means two customers may both use "golfdom" — and for a user
     * who belongs to both orgs, that URL would be genuinely ambiguous rather
     * than merely awkward, silently opening the wrong customer's site.
     */
    /**
     * ⚠️ Enforced by the BUILDER, not by a `deleting` listener.
     *
     * There was one, and it was redundant once `ScopedBuilder` gained the
     * check: every deletion — instance, bulk, quiet, or inside
     * `withoutEvents()` — reaches the builder, while only the first reaches the
     * event. Keeping both would have implied the event was load-bearing, which
     * is the belief that produced six bypassed guards in this project.
     *
     * ⚠️ Refuses while entries still reference it, because the database would
     * remove them itself.
     *
     * `entries.site_id` and `entries.entry_type_id` are both
     * `cascadeOnDelete`, so `$site->delete()` removed every entry INSIDE the
     * database: no per-row model event, so no audit row, and a hard DELETE, so
     * Entry's SoftDeletes never applied and the rows were unrecoverable.
     * Verified by probe — three entries gone, zero audit rows, the org still
     * present, so not the documented org-cascade exception.
     *
     * AuditedBuilder's guarantee is "no Eloquent path creates, changes or
     * removes an entry without an audit row or a refusal", and it enumerates
     * where it stops: `toBase()` and `DB::`. This was neither. Refusing here
     * is the refusal that claim allows for, and it forces the removal through
     * the audited path where it belongs.
     *
     * Deleting an ORG still takes everything, which ADR-020 documents as the
     * intended exception — that cascade happens in the database and fires no
     * model event, so this guard correctly never sees it.
     */
    /**
     * ⚠️ Enforced by the BUILDER, not by a `deleting` listener.
     *
     * There was one, and it was redundant once `ScopedBuilder` gained the
     * check: every deletion — instance, bulk, quiet, or inside
     * `withoutEvents()` — reaches the builder, while only the first reaches the
     * event. Keeping both would have implied the event was load-bearing, which
     * is the belief that produced six bypassed guards in this project.
     *
     * ⚠️ Refuses while entries still reference it, because the database would
     * remove them itself.
     *
     * `entries.site_id` is `cascadeOnDelete`, so deleting this removed every
     * referenced entry INSIDE the database: no per-row model event, so no audit
     * row, and a hard DELETE, so Entry's SoftDeletes never applied and the rows
     * were unrecoverable. Verified by probe — three entries gone, zero audit
     * rows, the org still present, so not the org-cascade exception ADR-020
     * documents.
     *
     * Reachable from the BUILDER as well as from `deleting`, through
     * `RefusesCascadingDeletes`. Written only as a model event it covered one
     * path: `query()->delete()`, `deleteQuietly()` and `withoutEvents()` all
     * dispatch straight past it, which is the same shape found on four other
     * guards in this project.
     *
     * Deleting an ORG still takes everything. That cascade happens in the
     * database and fires nothing here, which is what keeps the documented
     * exception working.
     */
    public function guardCascade(): void
    {
        $entries = Entry::withoutScopeBecause(
            'counting entries before their site is deleted, to refuse rather than cascade',
            fn ($query) => $query->withTrashed()->where('site_id', $this->getKey())->count(),
        );

        if ($entries === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Site [%s] still holds %d entr%s, and the database would delete them by cascade — '
            .'permanently, with nothing in the audit trail saying they existed (ADR-020). Delete '
            .'the entries first, which is audited.',
            $this->handle,
            $entries,
            $entries === 1 ? 'y' : 'ies',
        ));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve a site from the URL without its own org scope.
     *
     * This is the bootstrap path and it cannot be scoped by the thing it
     * bootstraps: the current org is derived FROM the resolved site, so
     * applying OrgScope here means the lookup never matches and every admin
     * URL 404s. Found by driving the panel in a browser, not by reasoning.
     *
     * Isolation is NOT weakened. Resolution only turns a URL segment into a
     * candidate; authorisation is the pivot check in canAccessTenant(), and
     * every other Site query keeps the scope. Defence in depth is preserved
     * because the scope is stood down here alone, not on the model.
     *
     * @param  mixed  $value
     * @return $this|null
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        $user = auth()->user();

        return static::withoutScopeBecause(
            'tenant bootstrap: the org context is derived from this lookup, so it cannot constrain it',
            function ($query) use ($value, $field, $user) {
                $query->where($field ?? $this->getRouteKeyName(), $value);

                // Handles are unique per org, not globally — UNIQUE (org_id,
                // handle) — so two customers may both own a site called
                // "golfdom". Without narrowing to the signed-in user's sites,
                // this returns whichever row the engine happens to order
                // first, canAccessTenant() then rejects that wrong candidate,
                // and a legitimate user cannot reach their own admin. Which
                // customer breaks depends on row order.
                //
                // The pivot is the authority here, exactly as it is in
                // canAccessTenant(), so narrowing by it costs no isolation.
                if ($user instanceof HasTenants) {
                    $accessible = $user->getTenants(Filament::getCurrentOrDefaultPanel())
                        ->map(fn ($tenant) => $tenant->getKey())
                        ->all();

                    $query->whereIn($this->getKeyName(), $accessible);
                }

                return $query->first();
            },
        );
    }

    /** @return BelongsTo<Org, $this> */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    /** @return BelongsTo<SiteGroup, $this> */
    public function siteGroup(): BelongsTo
    {
        return $this->belongsTo(SiteGroup::class);
    }
}
