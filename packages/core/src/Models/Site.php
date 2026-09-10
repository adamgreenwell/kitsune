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
use Illuminate\Support\Facades\DB;
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

    /**
     * The characters one path-prefix segment may hold.
     *
     * ⚠️ NAMED SO THE SKELETON'S ROUTE CAN CITE IT RATHER THAN COPY IT. That route and
     * `canonicalPrefix()` have now been reconciled by hand twice — once when the route's
     * constraint was narrower than the model's vocabulary, so `/.well-known` saved and 404'd, and
     * once when it accepted one segment while the model accepted four. Both were storage admitting
     * an address the front door could not deliver, and both were found by review rather than by a
     * test, because two literals cannot disagree until someone compares them.
     */
    public const PREFIX_SEGMENT_PATTERN = '[A-Za-z0-9._~-]+';

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
     * Serialises the save against every other claimant of the same hostname — issue #61.
     *
     * ⚠️ THE OVERLAP CHECK IS A CHECK-THEN-ACT AND THIS IS WHAT MAKES IT ATOMIC.
     * `refuseOverlappingClaim()` reads the rival claims for a host and compares prefixes in PHP,
     * because "one prefix contains the other" is not an equality any unique index can express — so two
     * orgs creating `example.test/` and `example.test/news` at the same moment could both complete
     * the read before either insert committed. The derived index keys differ, the unique constraint
     * accepted both, and the resolver's longest-prefix rule then served one org's URL from the other.
     *
     * ⚠️ THE TRANSACTION HAS TO BE OPENED HERE, which is why this is an override rather than more
     * work in the `saving` hook. A lock taken in a hook is only meaningful if the CALLER wrapped the
     * save in a transaction, and a hook cannot make that true — `Site::create()` outside one is the
     * ordinary case. A `lockForUpdate()` in the hook would have looked like serialisation while
     * working only sometimes, which is worse than not having it.
     *
     * ⚠️ AND THERE IS NOTHING ON `sites` TO LOCK when both claims are new, which is why
     * `site_host_claims` exists. Locking the existing rows for a host serialises only the case where
     * a rival is already there — the sequential one, already closed. Postgres takes no gap lock, and
     * depending on MySQL's would make correctness engine-specific (invariant 5).
     *
     * ⚠️ A NESTED SAVE RE-LOCKS NOTHING, WHICH IS NOT THE SAME AS BEING SAFE — and this docblock
     * claimed the second on the strength of the first. `DB::transaction()` inside an outer
     * transaction is a savepoint, and re-locking a row this transaction already holds is a no-op, so
     * one host costs one lock however many saves touch it. What does NOT follow, and what review
     * pointed out, is that a caller wrapping SEVERAL sites in one transaction is safe: every mutex
     * is held until the OUTER commit, and the order those saves run in is the caller's. Two batches
     * saving hosts A then B and B then A each hold their first mutex and block on the second, on
     * completely distinct rows and prefixes.
     *
     * ⚠️ THIS METHOD CANNOT FIX THAT, and saying so is the point rather than an excuse. Ordered
     * acquisition needs to cover every resource a transaction will take, and a per-save method
     * cannot see the saves that come after it. So it is a CONTRACT: a caller who wraps several site
     * saves in one transaction must order them by the hostname each will claim — the same order this
     * method uses within one save — or accept that two such batches can deadlock. Documented in
     * ADR-021 rather than left as a surprise, and a batch helper that enforces it is deliberately not
     * added here: a new public API surface is on CONTRIBUTING's won't-merge list before v1.2, and a
     * contract is the smaller half of the remedy until then.
     */
    public function save(array $options = []): bool
    {
        return (bool) DB::transaction(function () use ($options): bool {
            $this->lockHostClaim();

            return parent::save($options);
        });
    }

    /**
     * Takes the durable per-host mutex for every hostname this save contends for.
     *
     * ⚠️ WHICH HOSTS, AND WHY THE ORDER MATTERS, is `contendedHosts()` — it carries the measured
     * deadlock that made this a loop rather than a single lock. This method is the lock itself.
     */
    private function lockHostClaim(): void
    {
        $hosts = $this->contendedHosts();

        foreach ($hosts as $host) {
            /*
             * ⚠️ UPSERT THEN LOCK, in that order, and both are required. The row may not exist — the
             * first claimant of a hostname creates it — and two concurrent first claimants must not
             * both proceed. `insertOrIgnore()` lets exactly one of them create it and the other
             * continue without an error, and the `SELECT … FOR UPDATE` that follows is what they
             * then queue on.
             *
             * ⚠️ `DB::table()`, DELIBERATELY BELOW ELOQUENT. This row is a mutex rather than a
             * record: it has no model, no scope and no events, and giving it any of those would
             * invite somebody to read it as a claim. It is also written on every site save, so the
             * cheapest path is the right one.
             */
            DB::table('site_host_claims')->insertOrIgnore([
                'canonical_host' => $host,
                'created_at' => now(),
            ]);

            /*
             * ⚠️ SQLITE COMPILES `FOR UPDATE` TO NOTHING, and that is not a hole: SQLite serialises
             * writers at the database level, so a second writer waits on the transaction itself. The
             * engines where this lock does the work are Postgres and MySQL, which is why the test
             * for it runs on all three rather than on the default.
             */
            DB::table('site_host_claims')
                ->where('canonical_host', $host)
                ->lockForUpdate()
                ->first();
        }

        $this->refuseStaleOrigin($hosts);
    }

    /**
     * Refuse a save whose row has moved hosts since this instance was loaded.
     *
     * ⚠️ THE ORIGIN CAME FROM THE INSTANCE, AND THAT REOPENED THE CYCLE — review found it, and the
     * proof in `contendedHosts()` is what it broke. That proof rests on "every site row this
     * transaction touches sits at a host whose mutex it holds", and the row's host was read from
     * `getRawOriginal()`: if another transaction moved the row after this instance was loaded, the
     * mutex set is computed for a host the row has left. Two such saves take DISJOINT mutex sets,
     * serialise against nothing, and their rival reads then acquire each other's rows. Staged as two
     * real sessions — row 1 believed at `a.test` but actually at `b.test`, moving to `c.test`, against
     * row 2 believed at `d.test` but actually at `c.test`, moving to `b.test`:
     *
     *   PostgreSQL 17  ERROR: deadlock detected
     *
     * ⚠️ DETECTED RATHER THAN REPAIRED, which is the honest fix and a better one on its own terms.
     * Re-deriving the mutex set from the committed host needs the host read BEFORE the lock that
     * makes it stable, so it can go stale again between the two — a loop with no guaranteed end. And
     * an instance whose row has moved is a save about to overwrite a change it never saw: silently
     * proceeding is a lost update, so the refusal is the correct answer to the question the caller
     * actually asked. Re-measured with this check in place, the same two sessions both stop with
     * their own message and neither deadlocks; the legitimate swap that motivated `contendedHosts()`
     * still commits on both sides.
     *
     * ⚠️ A LOCKING READ, for the reason `refuseOverlappingClaim()` records: under MySQL and
     * MariaDB's REPEATABLE READ a plain read answers from the transaction's snapshot, so a move that
     * committed while this save queued for the mutex would be invisible — and invisible is exactly
     * the state this exists to catch. The lock it takes on the row is one `parent::save()` is about
     * to take anyway, so it adds no lock the transaction did not already need.
     *
     * ⚠️ AND WHEN IT PASSES, THE INVARIANT IS RESTORED: the row demonstrably sits at a host in
     * `$hosts`, which this transaction holds the mutex for — or on no host at all, which nothing
     * else can lock. That is what makes the check part of the
     * proof rather than a guard beside it.
     *
     * @param  list<string>  $hosts
     */
    private function refuseStaleOrigin(array $hosts): void
    {
        if (! $this->exists) {
            // A create has no row yet, so there is no earlier state it could have missed.
            return;
        }

        /*
         * ⚠️ QUERIED WHATEVER THIS INSTANCE BELIEVES, AND THE FIRST VERSION SKIPPED ON NULL — review
         * found the bypass my own fix introduced. It returned early when the loaded `canonical_host`
         * was null, on the reasoning that an admin-only site claims no host and so has no origin to be
         * stale about. True of the INSTANCE and not of the ROW: another transaction can give that row
         * a host, and the stale save then locks only its destination while its row sits somewhere
         * else — which is the same disjoint-mutex cycle, reached through the one path that skipped the
         * check. What this instance believes cannot decide whether the row is worth reading.
         *
         * ⚠️ A MISSING ROW AND A PRESENT ROW WITH A NULL HOST ARE DIFFERENT ANSWERS, so this selects a
         * row rather than a value: `value()` returns null for both, and they need opposite handling.
         */
        $row = DB::table('sites')
            ->where('id', $this->getKey())
            ->lockForUpdate()
            ->first(['canonical_host']);

        // Gone is not stale: the row was hard-deleted, and `parent::save()` will find nothing to
        // update. Refusing here would replace that with a message about the wrong thing.
        if ($row === null) {
            return;
        }

        $believed = $this->getRawOriginal('canonical_host');
        $committed = $row->canonical_host;

        /*
         * ⚠️ A ROW ON NO HOST IS REACHED BY NOTHING, so it needs no mutex and cannot be in a cycle.
         * `refuseOverlappingClaim()` finds rivals by `canonical_host` equality, which a NULL never
         * satisfies — so the only save that ever locks such a row is a save of that row. Returning
         * here is the invariant holding rather than an exemption from it.
         */
        if ($committed === null) {
            return;
        }

        /*
         * ⚠️ THE TEST IS THE INVARIANT ITSELF, not `$committed !== $believed`, and the difference is
         * deliberate. If another save moved the row to the very host this one is moving it TO, the
         * mutex is already held and the lock discipline is intact — so that case is not this
         * method's business even though the instance is stale. Policing lost updates in general is a
         * separate decision about `Site`, not a consequence of the locking proof, and answering it
         * here would smuggle one in.
         */
        if (in_array($committed, $hosts, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to save site [%s]: it was loaded while it answered on [%s] and it now answers '
            .'on [%s], so another save moved it after this one read it. This save holds the host '
            .'mutexes for the addresses it believed, not for the one the row is actually on, and '
            .'proceeding would write a URL claim under the wrong locks (ADR-021). Reload the site '
            .'and try again.',
            $this->getAttribute('handle') ?? $this->getKey(),
            match ($believed) {
                null => 'no host at all', '' => 'any host', default => $believed
            },
            $committed === '' ? 'any host' : $committed,
        ));
    }

    /**
     * Every hostname this save contends for, in one globally agreed order.
     *
     * ⚠️ BOTH HOSTS, NOT ONLY THE DESTINATION, AND THE DEADLOCK IS MEASURED. Review found it: two
     * same-org sites moving across each other's hosts deadlock even with disjoint prefixes, because
     * each locks a mutex the other does not hold and then needs a site row the other does.
     *
     *     site 1 at a.test/x -> b.test/x        site 2 at b.test/y -> a.test/y
     *     TX1 locks mutex(b.test), then site 2  TX2 locks mutex(a.test), then site 1
     *     TX1 UPDATEs site 1 — held by TX2      TX2 UPDATEs site 2 — held by TX1
     *
     *   PostgreSQL 17  ERROR: deadlock detected — while updating tuple in relation "sites"
     *   MySQL 8.4      ERROR 1213 (40001): Deadlock found when trying to get lock
     *
     * `DB::transaction()` takes one attempt, so one otherwise-valid save surfaces as an exception.
     * Measured again with both mutexes held in sorted order: both transactions commit and the sites
     * complete the swap.
     *
     * ⚠️ SORTED, WHICH IS THE WHOLE MECHANISM. Ordered acquisition is what makes a lock cycle
     * impossible, and it only works if EVERY transaction agrees on the order — so the order comes
     * from the hostnames themselves rather than from origin-then-destination, which is exactly the
     * per-transaction order that deadlocked.
     *
     * ⚠️ AND IT IS A PROOF, not two passing runs. With both mutexes held, every site row a
     * transaction touches belongs to a host whose mutex it holds: its own row sits at its ORIGINAL
     * host, and the rival rows `refuseOverlappingClaim()` locks sit at its DESTINATION host. Two
     * transactions whose row sets intersect must therefore intersect in mutexes too, and mutex
     * acquisition is globally ordered.
     *
     * ⚠️ THE ORIGINAL HOST IS LOCKED EVEN WHEN THE SAVE REMOVES THE URL ENTIRELY. Nothing needs
     * checking for a site that claims no address, but the save still UPDATEs a row sitting at the
     * old host — which a rival claiming that host may hold. Dropping the lock there would reopen the
     * cycle for the one case that looks like it does not need it.
     *
     * ⚠️ ONE LOCKING READ PER HOST, rather than one `whereIn(...)->orderBy(...)`. The `IN` form also
     * passed both engines, but row-lock order relative to a sort is the planner's business and not
     * contractual. Issuing a statement per host in sorted order makes the acquisition order this
     * method's.
     *
     * ⚠️ DERIVED HERE RATHER THAN READ, because the lock has to be taken BEFORE the `saving` hook
     * derives anything — that hook runs inside `parent::save()`, by which time it is too late to
     * serialise. `deriveUrlParts()` is the same static the hook calls, so the two cannot disagree,
     * and calling it twice is a string operation rather than a query.
     *
     * ⚠️ REFUSALS ARE LEFT TO THE HOOK. If `base_url` is malformed this derivation throws, and it
     * throws the same message the hook would — the transaction rolls back and the caller sees the
     * refusal it would have seen anyway. Catching it here to "try again later" would swap a clear
     * refusal for a lock nobody needed.
     *
     * ⚠️ A SITE WITH NO PUBLIC URL AND NO PREVIOUS ONE LOCKS NOTHING. `[null, null]` is the
     * representation for an admin-only site, which claims no address and contends with nobody.
     *
     * ⚠️ THE ORIGINAL IS THE LOADED VALUE, so a host changed by another process after this instance
     * was read is not the one locked. That is the same boundary `refuseOverlappingClaim()` already
     * works within — it excludes `$site->getKey()` from its own rival read — and reading the
     * committed host instead would need a query whose own ordering this method exists to establish.
     *
     * @return list<string>
     */
    private function contendedHosts(): array
    {
        [$destination] = self::deriveUrlParts($this->base_url, $this->url_strategy);

        $origin = $this->exists ? $this->getRawOriginal('canonical_host') : null;

        $hosts = array_values(array_unique(array_filter(
            [$destination, is_string($origin) ? $origin : null],
            static fn (?string $host): bool => $host !== null,
        )));

        sort($hosts);

        return $hosts;
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
     * ⚠️ THIS IS A CHECK-THEN-ACT, AND `save()` IS WHAT MAKES IT ATOMIC — issue #61, now closed.
     * The read here happens before the row is written, so two orgs creating `example.test/` and
     * `example.test/news` CONCURRENTLY could both pass it: the derived index keys differ, the
     * unique constraint accepted both, and the theft above was recreated. `Site::save()` now takes
     * a durable per-host mutex inside a transaction before this runs, so the second claimant of a
     * hostname queues behind the first and sees its committed row.
     *
     * ⚠️ A `lockForUpdate()` HERE WOULD NOT HAVE DONE IT, which is why the fix is a schema change
     * and an override rather than a line in this method. A lock taken in a `saving` hook is only
     * meaningful if the CALLER wrapped the save in a transaction, and a hook cannot make that true —
     * `Site::create()` outside one is the ordinary case. And when both claims are new there is
     * nothing on `sites` to lock: Postgres takes no gap lock, and depending on MySQL's would make
     * correctness engine-specific (invariant 5). See `lockHostClaim()` and `site_host_claims`.
     *
     * The exact-match unique index remains the database-level backstop for identical pairs,
     * regardless of timing.
     *
     * @throws RuntimeException when another org already holds an overlapping claim
     */
    private static function refuseOverlappingClaim(self $site): void
    {
        if ($site->canonical_host === null || $site->path_prefix === null) {
            // No public URL at all, so nothing is claimed.
            return;
        }

        /*
         * ⚠️ A LOCKING READ, SO IT CANNOT REUSE A CALLER'S SNAPSHOT. Holding the host mutex makes the
         * rival set stable from here on, and that is worthless if this read answers from an OLDER
         * point in time — which under MySQL and MariaDB's REPEATABLE READ it can. If a caller wrapped
         * this save in a transaction that had already read anything, `lockHostClaim()`'s nested
         * `DB::transaction()` is only a savepoint and the snapshot belongs to the OUTER transaction.
         * A rival committing while this save queued for the mutex would then be invisible to an
         * ordinary read, and `/` and `/news` could coexist across orgs after all.
         *
         * `lockForUpdate()` forces a current read on both MySQL engines, which is the property being
         * bought here rather than the lock itself — the mutex is what serialises. Postgres reads the
         * latest committed row under READ COMMITTED anyway, and SQLite has one writer; the clause
         * costs them nothing and removes an engine-specific hole invariant 5 exists to prevent.
         * Found by review.
         */
        $rivals = self::withoutScopeBecause(
            'cross-org URL claims: the question is whether ANOTHER org holds an overlapping '
            .'claim, which a query scoped to the current org cannot ask',
            fn ($query) => $query
                ->where('canonical_host', $site->canonical_host)
                ->when($site->exists, fn ($q) => $q->whereKeyNot($site->getKey()))
                ->lockForUpdate()
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

        /*
         * ⚠️ A BACKSLASH IS A PATH SEPARATOR TO A BROWSER AND ORDINARY DATA TO `parse_url()`, so
         * the two derive DIFFERENT ADDRESSES from one string. Measured:
         *
         *     https://example.test\@evil.test/x
         *       parse_url  host=evil.test     path=/x
         *       browser    host=example.test  path=/%5C@evil.test/x
         *
         * An operator entering that got a stored claim on `evil.test` — a host they may not own,
         * and one another org could legitimately hold — while their own browser went to
         * `example.test`. The configuration on screen and the address served were different hosts,
         * which is the ADR-021 failure with no framework safety net behind it.
         *
         * Refused rather than rewritten to `/`: the two readings disagree about where the AUTHORITY
         * ends, so "fix it" means choosing which host the operator meant, and there is no basis for
         * that choice. Found by review.
         */
        if (str_contains($baseUrl, '\\')) {
            throw new RuntimeException(sprintf(
                'Refusing the base_url [%s]: it contains a backslash, which a browser reads as a '
                .'path separator and PHP does not — so the address stored and the address visited '
                .'would be different hosts. Use forward slashes.',
                $baseUrl,
            ));
        }

        /*
         * ⚠️ A CONTROL CHARACTER IS SUBSTITUTED BY `parse_url()` AND STRIPPED BY A BROWSER, so the
         * two derive different addresses from one string — and this one is nastier than the
         * backslash because what PHP produces looks entirely plausible. Measured:
         *
         *     https://exa<TAB>mple.test/
         *       parse_url  host=exa_mple.test     ← an underscore, which is a LEGAL host character
         *       browser    host=example.test      ← stripped
         *
         * Tab, newline and carriage return all behave that way; NUL gives `exa_mple.test` to PHP and
         * is rejected outright by a browser. In the path it is the same substitution: a tab in
         * `/news` stores the prefix `/_news` while the request arrives for `/news`.
         *
         * So the operator's claim is on a host or prefix nobody can reach, another org can take the
         * address they meant without colliding, and the uniqueness and overlap checks are guarding
         * a string the browser never sends. Refused rather than stripped, for the backslash's
         * reason: stripping means deciding what they meant, and a control character in a URL is a
         * paste accident or an attack, both of which want the same answer. Found by review.
         */
        if (preg_match('/[\x00-\x1F\x7F]/', $baseUrl) === 1) {
            throw new RuntimeException(sprintf(
                'Refusing the base_url [%s]: it contains a control character. PHP replaces one with '
                .'an underscore while a browser strips it, so the address stored would not be the '
                .'address requested — and an underscore is a legal host character, so the result '
                .'looks valid. Remove it.',
                // Shown with the control character made visible, or the message says nothing.
                addcslashes($baseUrl, "\x00..\x1F\x7F"),
            ));
        }

        /*
         * ⚠️ A SCHEME MUST BE FOLLOWED BY `//`, or it is not naming an authority. `https:/news`
         * has one slash, so `$explicit` below is false and the value went on to be treated as a
         * bare host — storing a site whose claimed HOST was the literal string `https`. A typo
         * became a hostname.
         *
         * ⚠️ A `host:port` value is NOT caught by this, which is why the test is on the parsed
         * scheme rather than on the presence of a colon: `parse_url()` reads `example.test:8080`
         * as host and port with no scheme at all, so a port keeps working.
         */
        $stray = str_contains($baseUrl, '://') ? null : parse_url($baseUrl, PHP_URL_SCHEME);

        if (is_string($stray)) {
            throw new RuntimeException(sprintf(
                'Refusing the base_url [%s]: the scheme [%s] is followed by a single slash, so it '
                .'names no host — and read as a bare address, the scheme itself would become the '
                .'host. Write %s:// or leave the scheme off.',
                $baseUrl,
                $stray,
                $stray,
            ));
        }

        // A leading `//` would be a protocol-relative URL.
        $explicit = str_contains($baseUrl, '://') || str_starts_with($baseUrl, '//');

        /*
         * ⚠️ A PUBLIC ADDRESS IS HTTP, and every other scheme `parse_url()` accepts was being
         * stored as though it were. `file://example.test/news`, `javascript://example.test/news`
         * and `ftp://example.test/` all parsed with a host and saved the HTTP claim
         * `example.test/news` — so the site answered a public URL that the configured `base_url`
         * cannot produce, and the visible configuration described something else entirely.
         *
         * A protocol-relative `//host/path` and a bare host or path carry no scheme and are
         * unaffected: those are the forms this method already normalises. Found by review.
         */
        /*
         * ⚠️ ON THE RAW RETURN VALUE, not on a string cast of it, and only when a scheme actually
         * parsed — otherwise this steals the refusal that belongs to the unparseable guard below.
         * `parse_url('http://')` returns FALSE outright, so there is no scheme to object to, and
         * "this is not a URL" says more than "the scheme [(none)] cannot produce the request".
         *
         * Casting first hid that: `(string) false` is `''`, and a `$scheme !== ''` test on the cast
         * is a comparison static analysis reads as dead because its `parse_url` stub cannot return
         * an empty scheme. `is_string()` asks the question the runtime actually answers.
         */
        $scheme = str_contains($baseUrl, '://') ? parse_url($baseUrl, PHP_URL_SCHEME) : null;

        if (is_string($scheme)) {
            $scheme = mb_strtolower($scheme);

            if ($scheme !== 'http' && $scheme !== 'https') {
                throw new RuntimeException(sprintf(
                    'Refusing the base_url [%s]: a public address is served over HTTP, and the '
                    .'scheme [%s] cannot produce the request this would claim. Use http:// or '
                    .'https://, or leave the scheme off.',
                    $baseUrl,
                    $scheme,
                ));
            }
        }

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

        /*
         * ⚠️ AND IT MUST SURVIVE CANONICALISATION, which the presence check alone does not
         * guarantee. `parse_url('https://./news')` returns the host `'.'` — a string, so the guard
         * above passes — and canonicalising strips the trailing dot, leaving `''`: the host-less
         * WILDCARD. A `domain` site with that address therefore saved and answered at `/news` on
         * every host, while the uniqueness and overlap checks protected a claim the operator never
         * made. Found by review, one step past the presence check.
         */
        if ($namesHost) {
            $canonical = self::canonicalHost($host);

            if ($canonical === '') {
                throw new RuntimeException(sprintf(
                    'Refusing the base_url [%s]: its host reduces to nothing once canonicalised, '
                    .'which is the host-less wildcard rather than a host. The site would answer on '
                    .'every host serving this installation.',
                    $baseUrl,
                ));
            }
        }
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
            return self::reachableHost($host, $host);
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

        return self::reachableHost(rtrim(mb_strtolower($ascii), '.'), $host);
    }

    /**
     * The host from an untrusted `Host` header, normalised as far as it safely can be.
     *
     * ⚠️ IT NEVER THROWS, AND `canonicalHost()` DOES — which is the whole reason this exists.
     * Storage refuses a host it cannot store, and that is right when an operator is SAVING an
     * address. On the request path the header is untrusted input (invariant 6) and a throw is a
     * 500 on a request that should simply not have resolved.
     *
     * ⚠️ I INTRODUCED THAT 500 AND MY OWN MEASUREMENT MISSED IT. An earlier review comment asked
     * for a `catch` here; I refused it on the strength of probing all 9,261 three-character hosts
     * and finding none that Symfony accepts and `canonicalHost()` refuses. That was true of the
     * code as it then stood — and the very next commit added the numeric and IPv6 rules, which
     * refuse seven spellings Symfony is happy to deliver: `2130706433`, `[0:0:0:0:0:0:0:1]`,
     * `[0::1]`, `[::0:1]`, `[::ffff:127.0.0.1]`, `[::ffff:7f00:1]` and `[2001:0db8::1]`. A
     * three-character corpus cannot contain a bracketed IPv6 address, so the probe could not have
     * found them. `HostValidityParityTest` now sweeps a corpus that includes them.
     *
     * ⚠️ IT NORMALISES RATHER THAN REFUSING, which is better than the `catch` that was asked for.
     * `[0:0:0:0:0:0:0:1]` and `[::1]` are one address, so a request carrying the long form SHOULD
     * reach the site that stored the short one — refusing it would have been a wrong answer that
     * merely failed quietly. Anything that cannot be normalised is returned as it came and simply
     * matches no stored row, because storage refuses those spellings; host-less claims are tried
     * separately by `resolve()`, so a path site still answers on any host, which is what a
     * host-less claim means.
     */
    public static function requestHost(string $host): string
    {
        $host = rtrim(mb_strtolower(trim($host)), '.');

        if ($host === '' || ! str_starts_with($host, '[')) {
            return $host;
        }

        $inside = str_ends_with($host, ']') ? substr($host, 1, -1) : '';
        $packed = $inside === '' ? false : @inet_pton($inside);

        if ($packed === false || strlen($packed) !== 16) {
            return $host;
        }

        // The compressed form is what storage holds, so this is what makes the long form resolve.
        return '['.inet_ntop($packed).']';
    }

    /**
     * The host in the one spelling a request can arrive in, or a refusal.
     *
     * ⚠️ A STORED HOST NOBODY CAN REQUEST IS WORSE THAN A REFUSED ONE, and the ASCII fast path
     * above returned any ASCII string verbatim — so this is the check it was missing. Two
     * separate review findings, one cause:
     *
     * - **A numeric host has many spellings and a browser sends exactly one.** Measured through
     *   the WHATWG URL parser, which is the algorithm browsers implement: `127.1`, `010.1`,
     *   `0x7f.0.0.1`, `0177.0.0.1` and `2130706433` ALL become `127.0.0.1`, and
     *   `[0:0:0:0:0:0:0:1]`, `[0::1]` and `[::0:1]` all become `[::1]`. Stored verbatim, each
     *   non-canonical spelling is a claim no request reaches — while another org holding the
     *   canonical form passes the unique index as an unrelated claim and receives that
     *   operator's audience. ADR-021 has no framework safety net for cross-org URL theft, which
     *   is why this is refused at the boundary.
     * - **A host the framework rejects can still be saved.** `x..test` saves, and
     *   `Request::getHost()` answers 400 for it before any middleware runs, so the site is
     *   unreachable at the address its operator configured.
     *
     * ⚠️ REFUSED RATHER THAN NORMALISED, unlike the IDN case above, and the reason is that
     * normalising here would mean reimplementing the WHATWG IPv4 parser — decimal, octal and hex
     * labels, with the last one absorbing the remainder. Getting that subtly wrong would CREATE
     * an alias rather than close one, which is worse than refusing a spelling an operator can
     * fix in one keystroke. The IDN conversion is delegated to `intl`; there is no equivalent to
     * delegate to here.
     */
    private static function reachableHost(string $ascii, string $given): string
    {
        if ($ascii === '') {
            return '';
        }

        if (str_starts_with($ascii, '[')) {
            return self::canonicalIpv6($ascii, $given);
        }

        /*
         * ⚠️ A FINAL LABEL THAT IS ALL DIGITS OR HEX MEANS THIS IS AN IP, not a name — a DNS
         * top-level label cannot be entirely numeric, so there is no legitimate name to refuse
         * here. The WHATWG parser reads such a host as an address, which is why `2130706433`
         * reaches `127.0.0.1` while Symfony is content to treat it as a name.
         */
        if (preg_match('/(?:^|\.)(?:0[xX][0-9a-fA-F]*|[0-9]+)$/D', $ascii) === 1) {
            if (filter_var($ascii, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new RuntimeException(sprintf(
                    'Refusing the host [%s]: its last label is numeric, which makes it an IP '
                    .'address rather than a name, and it is not a valid IPv4 address in the one '
                    .'spelling a browser sends. `127.1`, `010.1`, `0x7f.0.0.1` and `2130706433` '
                    .'are all requested as `127.0.0.1`. Enter the four-part decimal form.',
                    $given,
                ));
            }

            return $ascii;
        }

        /*
         * ⚠️ THE SAME RULE `Request::getHost()` APPLIES, so a saved host is one a request can
         * deliver. Kept as a copy rather than a call because `getHost()` also consults trusted
         * proxies and the trusted-host regexp — runtime configuration that has no business
         * deciding whether an address can be stored. `HostValidityParityTest` asserts this agrees
         * with Symfony across a corpus, so the copy is pinned by a test rather than by hope.
         * Vendor source: `Symfony\Component\HttpFoundation\Request::isHostValid()`.
         */
        if (preg_replace('/[-a-zA-Z0-9_]++\.?/', '', $ascii) !== '') {
            throw new RuntimeException(sprintf(
                'Refusing the host [%s]: it is not a shape a request can carry, so the framework '
                .'answers 400 for it before routing and the site would be unreachable at its own '
                .'address. A label may hold letters, digits, hyphens and underscores.',
                $given,
            ));
        }

        return $ascii;
    }

    /**
     * A bracketed IPv6 host in the spelling a browser sends, or a refusal.
     *
     * ⚠️ PHP AND THE URL PARSER DISAGREE ON ONE RANGE, so that range is refused rather than
     * guessed at. Measured across twelve forms: `inet_ntop(inet_pton(...))` matches the WHATWG
     * serialisation everywhere EXCEPT IPv4-mapped addresses, where PHP prints
     * `::ffff:127.0.0.1` and a browser sends `::ffff:7f00:1`. Requiring PHP's form there would
     * reject the spelling that actually arrives and accept one that never does — precisely
     * backwards — and hand-rolling the spec's serialiser is the mistake this method avoids.
     */
    private static function canonicalIpv6(string $ascii, string $given): string
    {
        $inside = str_ends_with($ascii, ']') ? substr($ascii, 1, -1) : '';
        $packed = $inside === '' ? false : @inet_pton($inside);

        if ($packed === false || strlen($packed) !== 16) {
            throw new RuntimeException(sprintf(
                'Refusing the host [%s]: a bracketed host is an IPv6 address, and this is not '
                .'one. A request carrying it would be refused before routing.',
                $given,
            ));
        }

        $canonical = (string) inet_ntop($packed);

        if (str_contains($canonical, '.')) {
            throw new RuntimeException(sprintf(
                'Refusing the host [%s]: it is an IPv4-mapped IPv6 address, and PHP and browsers '
                .'spell that range differently — PHP writes `::ffff:127.0.0.1` where a browser '
                .'sends `::ffff:7f00:1`. Address the site by its IPv4 form instead.',
                $given,
            ));
        }

        if ($inside !== $canonical) {
            throw new RuntimeException(sprintf(
                'Refusing the host [%s]: an IPv6 address has one spelling a browser sends, and '
                .'this is not it. Enter it as [%s].',
                $given,
                $canonical,
            ));
        }

        return '['.$canonical.']';
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

            if (preg_match('/^'.self::PREFIX_SEGMENT_PATTERN.'$/', $segment) !== 1) {
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
