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
use Kitsune\Core\Tenancy\Context;
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
            /*
             * ⚠️ THE DERIVED COLUMNS THEMSELVES, not only their source, and omitting them was a
             * hole review found: `update(['canonical_host' => 'stolen.example.test'])` was
             * ALLOWED and wrote a hostname the model never declared. Measured. The site then
             * answers on a URL its own `base_url` does not name, and the global uniqueness index
             * guards that stolen value — which is the cross-org URL theft ADR-021 says has no
             * framework safety net, reached through the back door rather than the front.
             */
            'canonical_host' => 'it is derived, never authored. Writing it directly makes the site '
                .'claim a host its base_url does not name, and the unique index then guards that '
                .'value. Set base_url and save the model.',
            'path_prefix' => 'it is derived, never authored. Writing it directly moves the site to '
                .'a path its base_url does not name. Set base_url and save the model.',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $site): void {
            [$site->canonical_host, $site->path_prefix] = self::deriveUrlParts($site->base_url, $site->url_strategy);

            self::refuseOverlappingClaim($site);
        });
    }

    /**
     * Refuses a public URL that OVERLAPS one another org already holds.
     *
     * ⚠️ THE UNIQUE INDEX IS NOT ENOUGH, AND SAYING IT WAS WAS WRONG. It compares the pair
     * exactly, so two orgs cannot hold the same `(canonical_host, path_prefix)` — and can still
     * hold overlapping ones. Demonstrated: org A owning `https://example.test` and org B
     * claiming `https://example.test/news` both satisfy the index, and because the resolver
     * prefers the LONGEST matching prefix, every request to `example.test/news…` on org A's own
     * hostname is served by org B. That is the cross-org URL theft ADR-021 says has no framework
     * safety net, and it was reachable through the front door.
     *
     * ⚠️ WHY THIS CANNOT BE AN INDEX. "One prefix is a path-prefix of the other" is not an
     * equality, so no unique constraint expresses it. The check therefore lives here, and the
     * uniqueness index stays as the exact-match backstop it always was — the two are not
     * alternatives.
     *
     * ⚠️ UNSCOPED ON PURPOSE, and for the same reason the resolver is: the question is whether
     * ANOTHER org holds a conflicting claim, so a query constrained to the current org cannot
     * ask it. Compared in PHP rather than SQL because prefix containment differs across the
     * three drivers and the row count for one hostname is small by construction.
     *
     * ⚠️ A host-less claim (`canonical_host = ''`) is compared only against other host-less
     * claims. `''` means "whatever host serves this installation", which no org can own
     * exclusively — and the resolver already prefers a host-specific claim, so a specific claim
     * shadowing a promiscuous one is the intended precedence rather than theft.
     *
     * ⚠️ NOT SERIALIZED, AND THAT IS A KNOWN GAP RATHER THAN AN OVERSIGHT — issue #61. This
     * reads before the row is written, so two orgs creating `example.test/` and
     * `example.test/news` CONCURRENTLY can both pass it: the derived index keys differ, so the
     * unique constraint accepts both, and the theft above is recreated. Closing it needs a
     * durable per-host claim row to lock plus a transactional save, because there is no existing
     * row to lock when both claims are new — a schema change and a decision, not a patch.
     *
     * What this does close is the case where a rival claim ALREADY EXISTS, which is every
     * sequential path including the one review demonstrated. The exact-match unique index still
     * prevents identical pairs at the database level regardless of timing. A `lockForUpdate()`
     * here would look like serialization without being it: a lock taken in a `saving` hook is
     * only meaningful if the caller wrapped the save in a transaction, and a hook cannot make
     * that true.
     *
     * @throws RuntimeException when another org already holds an overlapping claim
     */
    private static function refuseOverlappingClaim(self $site): void
    {
        if ($site->canonical_host === null || $site->path_prefix === null) {
            // No public URL at all, so nothing is claimed.
            return;
        }

        $rivals = self::withoutScopeBecause(
            'cross-org URL claims: the question is whether ANOTHER org holds an overlapping '
            .'claim, which a query scoped to the current org cannot ask',
            fn ($query) => $query
                ->where('canonical_host', $site->canonical_host)
                ->when($site->exists, fn ($q) => $q->whereKeyNot($site->getKey()))
                ->get(['id', 'org_id', 'base_url', 'path_prefix']),
        );

        /*
         * ⚠️ THE EFFECTIVE ORG, NOT `$site->org_id`, and review found why. `EnforcesScope` stamps
         * `org_id` from Context on `creating`, which Eloquent fires AFTER `saving` — so on a
         * create that does not name the org explicitly, `$site->org_id` is still NULL here.
         * `(int) null` is `0`, which matches no org, so every rival looked like a DIFFERENT org
         * and an org nesting `/fr` under its own `/` was refused as theft.
         *
         * It failed safe rather than open — the cross-org refusal still held — but it refused a
         * documented, legitimate arrangement. Measured: it was allowed with an explicit `org_id`
         * and refused without one, which is why every test here missed it. They all passed the
         * org explicitly.
         */
        $orgId = $site->org_id ?? app(Context::class)->orgId();

        foreach ($rivals as $rival) {
            if ($orgId !== null && (int) $rival->org_id === (int) $orgId) {
                // One org may arrange its own sites however it likes.
                continue;
            }

            if (! self::prefixesOverlap((string) $site->path_prefix, (string) $rival->path_prefix)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Refusing [%s]: another org already holds [%s] on the same host, and the two '
                .'overlap. Site resolution prefers the longest matching prefix, so one of them '
                .'would silently serve requests addressed to the other (ADR-021). A public URL '
                .'is claimed once across every org.',
                (string) $site->base_url,
                (string) $rival->base_url,
            ));
        }
    }

    /**
     * Whether either prefix contains the other, at a segment boundary.
     *
     * `''` contains everything: it is the host root, so it overlaps every prefix on that host.
     * `/news` and `/news/fr` overlap; `/news` and `/newsletter` do NOT — the boundary check is
     * what separates them, and a naive `str_starts_with` would refuse the second pair.
     */
    private static function prefixesOverlap(string $a, string $b): bool
    {
        if ($a === $b || $a === '' || $b === '') {
            return true;
        }

        return str_starts_with($a, $b.'/') || str_starts_with($b, $a.'/');
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

        /*
         * ⚠️ A MALFORMED VALUE IS REFUSED, NOT MAPPED TO "no public URL". `[null, null]` is the
         * representation reserved for a site that deliberately has no public address, and
         * returning it for something unparseable — `http://`, a non-numeric port — meant the save
         * SUCCEEDED while silently withdrawing the site's address: `base_url` still populated and
         * visibly set, resolution excluding it, no uniqueness claim made, and nothing reporting
         * any of that. Found by review.
         */
        if ($parsed === false) {
            throw new RuntimeException(sprintf(
                'Refusing the base_url [%s]: it cannot be parsed as a URL. Refused rather than '
                .'treated as "no public URL", because that would leave the value visibly set '
                .'while the site answered at no address.',
                $baseUrl,
            ));
        }

        /*
         * ⚠️ A HOST-ADDRESSED STRATEGY MUST ACTUALLY YIELD A HOST. `parse_url('file:///news')`
         * SUCCEEDS and returns no host, so the previous line silently turned that into `''` — the
         * host-less wildcard — and a `domain` site configured with an unusable address was
         * published at `/news` on EVERY host instead of being refused. Found by review, one step
         * past the `parse_url() === false` case.
         */
        if ($namesHost && ! is_string($parsed['host'] ?? null)) {
            throw new RuntimeException(sprintf(
                'Refusing the base_url [%s]: url_strategy is [%s], which addresses a site by its '
                .'host, and this value parses with no host at all. Treating it as host-less would '
                .'publish the site on every host serving this installation.',
                $baseUrl,
                $strategy,
            ));
        }

        // The guard above proves a host is present whenever one is wanted, so no second check.
        $host = $namesHost ? (string) $parsed['host'] : '';
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
    public static function canonicalHost(string $host): string
    {
        $host = rtrim(mb_strtolower(trim($host)), '.');

        /*
         * ⚠️ A PERCENT-ESCAPED HOST IS A DIFFERENT SPELLING OF THE SAME HOST, and storing it
         * literally let two orgs claim one address. `parse_url('https://%65xample.test')` keeps
         * the host as `%65xample.test` — measured — while a browser normalises it to
         * `example.test` before sending, so the two passed both the unique index and the overlap
         * check as unrelated claims, and whoever held the encoded form became unreachable the
         * moment the ordinary spelling was taken. Found by review.
         *
         * ⚠️ REFUSED RATHER THAN DECODED, for the same reason a path prefix is: decoding is not
         * neutral. `%2E` becomes a label separator, so a decoded host can gain labels it was not
         * given — and a host is the outermost boundary in this system, where re-segmentation is
         * the last thing to permit.
         */
        if (str_contains($host, '%')) {
            throw new RuntimeException(sprintf(
                'Refusing the host [%s]: it contains a percent-escape, which a browser resolves '
                .'before sending — so this would be requested as a different host than the one '
                .'stored. Enter the host in its ordinary spelling.',
                $host,
            ));
        }

        /*
         * ⚠️ AN INTERNATIONALISED HOST IS STORED IN ITS ASCII (A-LABEL) FORM, because that is
         * the only form a request ever arrives in. Review found the gap: one org configuring
         * `https://bücher.example` and another claiming `https://xn--bcher-kva.example` produced
         * two different `canonical_host` values, so neither the unique index nor the overlap
         * check saw one claim — and since browsers send the A-label in `Host`, the
         * Unicode-configured site was unreachable while the other org answered for its domain.
         *
         * Normalising here means the request side gets it too: `ResolveSiteFromRequest` calls
         * this same method rather than keeping its own copy, which is what let the two spellings
         * diverge in the first place.
         */
        if (mb_check_encoding($host, 'ASCII')) {
            return $host;
        }

        /*
         * ⚠️ FAILS CLOSED WITHOUT `ext-intl`, rather than storing the Unicode form. `intl` is
         * NOT a declared requirement of this package and CI does not install it, so
         * `idn_to_ascii()` cannot be assumed. Storing the U-label would produce exactly the
         * defect above — a site unreachable at its own address, and a claim the index cannot
         * compare — so an internationalised `base_url` is refused with the reason instead.
         */
        if (! function_exists('idn_to_ascii')) {
            throw new RuntimeException(sprintf(
                'Refusing the internationalised host [%s]: it must be stored in its ASCII form, '
                .'because that is the only form a browser sends, and converting it needs the '
                .'intl extension which is not installed. Install ext-intl, or enter the host in '
                .'its punycode form.',
                $host,
            ));
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        if (! is_string($ascii) || $ascii === '') {
            throw new RuntimeException(sprintf(
                'Refusing the host [%s]: it is not ASCII and cannot be converted to an ASCII '
                .'form, so no request could ever match it.',
                $host,
            ));
        }

        return rtrim(mb_strtolower($ascii), '.');
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

        foreach ($segments as $segment) {
            /*
             * ⚠️ REFUSED RATHER THAN ENCODED, and the alternatives are both worse. A browser
             * sends `/caf%C3%A9` for a configured `/café`, and `Request::path()` hands the
             * resolver that ENCODED form — measured — so a literal non-ASCII prefix stores a
             * value no request can ever equal: the site saves and is unreachable.
             *
             * Percent-encoding here instead would have to survive the lowercasing above (hex is
             * conventionally uppercase, so `%C3%A9` would become `%c3%a9` and stop matching), and
             * DECODING both sides would make `%2F` collapse into a path separator — letting a
             * request re-segment itself into a prefix it was never given. That is a boundary this
             * must not blur.
             *
             * So the same posture as an internationalised host: refuse, and say what to enter.
             */
            /*
             * ⚠️ `.` AND `..` ARE REFUSED, and allowing them was cross-org URL theft through a
             * door the overlap check cannot see. A browser NORMALISES the path before sending it,
             * so a site configured as `/a/../b` is requested as `/b` — a different stored key,
             * unrelated under `prefixesOverlap()`, and served by whoever holds `/b`. Review found
             * it after the overlap fix, which is exactly the kind of gap an allowlist of
             * CHARACTERS cannot close: every character in `..` is permitted.
             *
             * Only the complete segments are refused. `.well-known` and `v1.2` are ordinary names
             * and stay legal.
             */
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException(sprintf(
                    'Refusing the path prefix segment [%s]: a browser resolves dot segments away '
                    .'before sending the request, so this prefix would be requested as something '
                    .'else — and served by whichever site holds that other path.',
                    $segment,
                ));
            }

            if (preg_match('/^[A-Za-z0-9._~-]+$/', $segment) !== 1) {
                throw new RuntimeException(sprintf(
                    'Refusing the path prefix segment [%s]: a prefix may use only letters, '
                    .'digits, and - . _ ~ so that it matches the form a browser actually '
                    .'requests. A literal space or non-ASCII character is sent percent-encoded, '
                    .'and the site would save successfully and be reachable at no URL.',
                    $segment,
                ));
            }
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
