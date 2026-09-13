<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Filament\Facades\Filament;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/**
 * Phase 4's "done when" says **through the admin**, and nothing measured the admin.
 *
 * `kitsune:benchmark-storage` measures queries at scale and `kitsune:benchmark-floor` measures memory at
 * the ADR-027 floor. Neither issues a request. A list page is not a `SELECT`: it is a count, a page query,
 * an eager-load set, a navigation build and whatever Livewire does on top — and the defects that only
 * appear at volume live in that gap. The project's own history is the argument: three defects in the
 * entity-type builder were invisible to the PHP suite and appeared the moment a browser opened the page.
 *
 * ⚠️ IT ISSUES REAL REQUESTS THROUGH THE HTTP KERNEL, not `Route::dispatch` and not a query rehearsal.
 * Middleware runs, `IdentifyEntryType` validates `{type}`, the panel builds its navigation, and Livewire
 * renders. That is the cost the criterion is about.
 *
 * ⚠️ QUERY COUNT IS REPORTED BESIDE WALL-CLOCK, because a fast page issuing 400 queries is a defect that
 * timing alone passes — on a developer's SSD with a warm page cache, an N+1 over 100k rows can still come
 * in under the bar and then fall over on a network round trip per query.
 *
 * ⚠️ AND IT USES A SITE THE OPERATOR ALREADY HAS RATHER THAN MAKING ONE. Standing up a benchmark org
 * means standing up a benchmark USER to sign in as, and an installation must never be left carrying a
 * credentialed account it did not ask for — the same rule ADR-026 puts on the installer. So this borrows
 * an existing user's own site, adds rows under a `bench-` slug prefix, and removes exactly those.
 */
final class BenchmarkAdminCommand extends Command
{
    /**
     * The Phase 4 bar.
     *
     * ⚠️ THE CRITERION NAMES QUERIES — "no query over 200ms" — so that is what `slowest query` is measured
     * against. Page time is reported beside it against the same number because a page can be slow with
     * every query fast, and a reader who only saw the query column would call that passing.
     */
    private const BUDGET_MS = 200.0;

    /** Rows this command created, and the only rows it will remove. */
    private const SLUG_PREFIX = 'bench-admin-';

    /**
     * The highest entry id that existed before this run inserted anything, or null if it inserted nothing.
     *
     * ⚠️ IDENTITY RATHER THAN PATTERN, because a pattern is a guess about somebody else's data. Review found
     * that cleanup matching `bench-admin-%` would force-delete a customer's own entry if they happened to
     * name one that way — on a run that created nothing.
     */
    private ?int $inserted = null;

    protected $signature = 'kitsune:benchmark-admin
        {--rows=100000 : Entries to have in scope for the measured site}
        {--as= : Email of the user to sign in as; defaults to the first on the installation}
        {--keep : Leave the generated rows in place}';

    protected $description = 'Measure admin page cost at scale against Phase 4\'s 200ms bar';

    public function handle(): int
    {
        $rows = max(1, (int) $this->option('rows'));

        $user = $this->user();

        if ($user === null) {
            $this->error('No user to sign in as. Create one, or pass --as=email.');

            return self::FAILURE;
        }

        [$site, $type] = $this->fixture($user);

        if ($site === null || $type === null) {
            $this->error('That user reaches no site with an enabled entry type, so there is no admin to measure.');

            return self::FAILURE;
        }

        $this->line('engine: <info>'.DB::connection()->getDriverName().'</info>'
            .'  as: <info>'.$this->describe($user).'</info>'
            .'  site: <info>'.$site->slug.'</info>'
            .'  type: <info>'.$type->handle.'</info>');

        try {
            $seeded = $this->ensureVolume($site, $type, $rows);
            $this->line("  entries in scope: <info>{$seeded}</info>");
            $this->newLine();

            Auth::guard('web')->setUser($user);

            return $this->report($this->measureAll($site, $type, $seeded));
        } finally {
            if (! $this->option('keep')) {
                $this->cleanUp($site);
            }
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: float, 4: int, 5: float, 6: string}>  $results
     */
    private function report(array $results): int
    {
        $this->line(sprintf('  %-24s %-42s %5s %9s %8s %11s', 'page', 'url', 'code', 'page ms', 'queries', 'slowest ms'));
        $this->line('  '.str_repeat('─', 104));

        $over = 0;
        $worst = null;

        foreach ($results as [$label, $url, $status, $ms, $queries, $slowest, $sql]) {
            if ($worst === null || $slowest > $worst[1]) {
                $worst = [$label, $slowest, $sql];
            }

            /*
             * ⚠️ A NON-200 IS A FAILED MEASUREMENT, NOT A FAST ONE, and it has to be louder than the
             * timings beside it: a 404 from `IdentifyEntryType` renders in two milliseconds and would sit
             * at the top of this table looking like the best result on the page.
             */
            $flag = match (true) {
                $status !== 200 => ' ✗ not a rendered page',
                $slowest > self::BUDGET_MS => ' ⚠️ query over the 200ms bar',
                $ms > self::BUDGET_MS => ' ⚠️ page over 200ms, every query inside it',
                default => '',
            };

            if ($flag !== '') {
                $over++;
            }

            $this->line(sprintf('  %-24s %-42s %5d %9.1f %8d %11.2f%s', $label, $url, $status, $ms, $queries, $slowest, $flag));
        }

        /*
         * ⚠️ THE SLOWEST QUERY'S SQL, because a number is not a finding. "55 ms on the list page" sends a
         * reader looking for an N+1; the statement says it is one `ORDER BY` with no index under it, which
         * is a different fix entirely.
         */
        if ($worst !== null && $worst[1] > 1.0) {
            $this->newLine();
            $this->line(sprintf('  slowest single query, on <info>%s</info> at <info>%.2f ms</info>:', $worst[0], $worst[1]));
            $this->line('    '.$worst[2]);
        }

        $this->newLine();

        if ($over > 0) {
            $this->warn("  {$over} of ".count($results).' page shapes miss the bar.');

            return self::FAILURE;
        }

        $this->info('  Every page shape rendered inside the 200ms bar.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: string, 2: int, 3: float, 4: int, 5: float, 6: string}>
     */
    private function measureAll(Site $site, EntryType $type, int $seeded): array
    {
        $slug = $site->slug;
        $handle = $type->handle;

        /*
         * ⚠️ AN ENTRY FROM THE MIDDLE OF THE CORPUS, not the first. The first row is the one every index
         * finds instantly and the one a cache is most likely to hold; measuring it answers a question
         * nobody asked.
         */
        $entry = Entry::withoutGlobalScopes()
            ->where('site_id', $site->getKey())
            // ⚠️ Of the selected TYPE, which review found missing. `EntryResource` filters records by the
            // resolved type, so a midpoint record belonging to another type on the same site produced an
            // id that 404s under this `{type}` — three page shapes silently unmeasured, reported as a
            // failure of the command rather than of the fixture.
            ->where('entry_type_id', $type->getKey())
            ->orderBy('id')
            ->skip(intdiv($seeded, 2))
            ->first();

        $id = $entry?->getKey();

        $list = "/admin/{$slug}/c/{$handle}";

        $pages = [
            ['dashboard', "/admin/{$slug}"],
            ['entry list, page 1', $list],
            ['entry create', "{$list}/create"],
            ['entry type builder', "/admin/{$slug}/entry-types"],
        ];

        /*
         * ⚠️ THE LAST PAGE IS READ OFF THE RENDERED PAGE, NOT CALCULATED, and calculating it was wrong
         * twice over in one line. It divided the SEEDED row count — while the list is scoped to one entry
         * type, so the corpus it pages through is smaller — by a page size of 25, which Filament's default
         * is not: measured, the table pages at TEN. The result was page 8 of 20, labelled "last page" and
         * reported as a deep-pagination measurement it never performed.
         *
         * Filament renders its pagination with `wire:click` rather than links, so there is no `?page=`
         * href to read a maximum from; the summary line is the only thing on the page that states both
         * numbers. When it cannot be read the row is OMITTED and said so, because a deep page measured at
         * the wrong depth is worse than an acknowledged gap.
         */
        $summary = self::paginationSummary($this->body($list));

        if ($summary === null) {
            $this->warn('  deep pagination not measured: the list page states no "showing … of …" summary.');
        } else {
            [$perPage, $total] = $summary;
            $lastPage = max(1, (int) ceil($total / max(1, $perPage)));

            $this->line("  list corpus: <info>{$total}</info> entries at <info>{$perPage}</info> per page — last page is <info>{$lastPage}</info>");
            $this->newLine();

            $pages[] = ['entry list, last page', "{$list}?page={$lastPage}"];
        }

        if ($id !== null) {
            $pages[] = ['entry edit', "/admin/{$slug}/c/{$handle}/{$id}/edit"];
            $pages[] = ['entry view', "/admin/{$slug}/c/{$handle}/{$id}"];
            $pages[] = ['related records', "/admin/{$slug}/c/{$handle}/{$id}/related"];
        }

        $results = [];

        foreach ($pages as [$label, $url]) {
            $results[] = $this->measure($label, $url);
        }

        return $results;
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: float, 4: int, 5: float, 6: string}
     */
    private function measure(string $label, string $url): array
    {
        $queries = 0;
        $slowest = 0.0;
        $statement = '';
        $connection = DB::connection()->getName();

        /*
         * ⚠️ `DB::listen()` REGISTERS ON THE APPLICATION EVENT DISPATCHER, not on a connection, so a
         * listener is told about queries on every connection there is. The filter is what makes the count
         * belong to the engine named in the header.
         *
         * ⚠️ AND THE COUNTERS ARE RESET RATHER THAN THE LISTENER RE-REGISTERED. A listener added per page
         * accumulates: the second page is counted twice, the third three times, and the numbers rise
         * smoothly enough to read as a real trend. Measured, on the first version of this command.
         */
        DB::listen(function (QueryExecuted $query) use (&$queries, &$slowest, &$statement, $connection): void {
            if ($query->connectionName !== $connection) {
                return;
            }

            $queries++;

            if ($query->time > $slowest) {
                $slowest = $query->time;
                $statement = (string) preg_replace('/\s+/', ' ', $query->sql);
            }
        });

        // Warm first, and measure second: the first request through the kernel pays for view compilation
        // and container resolution that no later request repeats, which is not what a page costs.
        $this->request($url);

        $queries = 0;
        $slowest = 0.0;
        $statement = '';

        $start = hrtime(true);
        $status = $this->request($url);
        $ms = (hrtime(true) - $start) / 1_000_000;

        return [$label, $url, $status, $ms, $queries, $slowest, $statement];
    }

    private function request(string $url): int
    {
        return app(HttpKernel::class)->handle(Request::create($url, 'GET'))->getStatusCode();
    }

    private function body(string $url): string
    {
        return (string) app(HttpKernel::class)->handle(Request::create($url, 'GET'))->getContent();
    }

    /**
     * The page size and the corpus size, read out of the table's own summary line.
     *
     * ⚠️ IT IS THE ENGLISH SUMMARY, and that is a stated limit rather than an oversight. The line is a
     * translated string, so this reads it only under a locale that renders it in this shape — which is
     * why a failed parse omits the deep-page row instead of guessing at a depth.
     *
     * ⚠️ PUBLIC AND STATIC SO IT CAN BE TESTED DIRECTLY, which is not a habit and is earned here: this is
     * the piece that decides how deep the deep-page measurement goes, and it replaced a calculation that
     * got that wrong by a factor of two and a half while reporting a confident number.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function paginationSummary(string $html): ?array
    {
        $plain = (string) preg_replace('/\s+/', ' ', strip_tags($html));

        if (preg_match('/Showing (\d[\d,]*) to (\d[\d,]*) of (\d[\d,]*)/', $plain, $found) !== 1) {
            return null;
        }

        $number = static fn (string $raw): int => (int) str_replace(',', '', $raw);

        $perPage = $number($found[2]) - $number($found[1]) + 1;

        return $perPage > 0 ? [$perPage, $number($found[3])] : null;
    }

    /** The signed-in user's email when the provider model carries one, and its identifier otherwise. */
    private function describe(Authenticatable $user): string
    {
        $email = $user instanceof Model ? $user->getAttribute('email') : null;

        return is_string($email) && $email !== '' ? $email : (string) $user->getAuthIdentifier();
    }

    private function user(): ?Authenticatable
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }

        /*
         * ⚠️ WITHOUT GLOBAL SCOPES, because `User` is `#[OrgScopedThroughPivot]` and a console process has
         * no tenancy context — so the scope compiles to a condition nothing satisfies and the query
         * reports an installation with no users on it. The floor benchmark records the same trap for
         * `Entry`, and it is the reason this file resolves its fixture before signing anyone in.
         */
        $query = $model::withoutGlobalScopes();
        $email = $this->option('as');

        if (is_string($email) && $email !== '') {
            $query->where('email', $email);
        }

        $user = $query->orderBy('id')->first();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * @return array{0: ?Site, 1: ?EntryType}
     */
    private function fixture(Authenticatable $user): array
    {
        $site = $this->siteFor($user);

        if ($site === null) {
            return [null, null];
        }

        app(Context::class)->setSite($site);

        $org = $site->org;

        if ($org !== null) {
            app(Context::class)->setOrg($org);
        }

        /*
         * ⚠️ AN ENABLED TYPE, NOT MERELY AN EXISTING ONE. `IdentifyEntryType` 404s a `{type}` that is not
         * available for the current site (ADR-022), so a benchmark that picked any row would measure the
         * cost of a 404 and report it as a page.
         */
        $type = EntryType::visibleFor($site)->first();

        return [$site, $type instanceof EntryType ? $type : null];
    }

    /**
     * A site this user can actually reach, asked of the panel rather than of the table.
     *
     * ⚠️ THE FIRST SITE ON THE INSTALLATION IS NOT THE RIGHT ANSWER, and review found what that costs. On a
     * box with more than one customer it is very likely ANOTHER customer's site — and `ensureVolume()` would
     * write a hundred thousand rows into it before the first request discovered the mismatch and returned a
     * redirect. With `--keep` those rows would stay there.
     *
     * ⚠️ ASKED THROUGH `HasTenants`, which is the panel's own contract for "the sites this user may enter".
     * Reading `site_user` here would work and would also put a table name core does not own into a second
     * place; and this command exists to measure the PANEL, so the panel's answer is the one it should use.
     */
    private function siteFor(Authenticatable $user): ?Site
    {
        if (! $user instanceof HasTenants) {
            $this->error('That user model does not implement Filament\Models\Contracts\HasTenants, so '
                .'there is no way to ask which sites it may enter — and guessing is how a benchmark writes '
                .'rows into another org.');

            return null;
        }

        /*
         * ⚠️ THE DEFAULT PANEL, NOT A PANEL NAMED `admin`. `KitsunePanel::apply()` configures a panel the
         * HOST application declares, so its id is the host's to choose — a literal here would work on the
         * skeleton and fail on any install that named theirs something else.
         */
        $panel = Filament::getDefaultPanel();

        foreach ($user->getTenants($panel) as $tenant) {
            if ($tenant instanceof Site) {
                return $tenant;
            }
        }

        return null;
    }

    private function ensureVolume(Site $site, EntryType $type, int $rows): int
    {
        /*
         * ⚠️ THE TYPE'S ROWS, NOT THE SITE'S, and review found what counting the site cost. The entry list
         * is scoped to one entry type, so on a site holding 99,000 products and one article a `--rows=100000`
         * run would add a thousand articles, benchmark a list of about a thousand, and report success
         * against the 100k criterion. The corpus the page pages through is the one that has to be sized.
         */
        $existing = $this->countOfType($site, $type);

        if ($existing >= $rows) {
            return $existing;
        }

        /*
         * ⚠️ THE HIGH-WATER MARK IS TAKEN BEFORE THE FIRST INSERT, so cleanup can delete by identity rather
         * than by pattern. Review found the alternative: a legitimate entry whose slug happens to start with
         * this prefix would have been force-deleted by a run that inserted nothing at all. Slugs do not
         * reserve a namespace, so the prefix is a hint and the id range is the proof — and `$this->inserted`
         * staying null is what makes a no-op run delete nothing.
         */
        $this->inserted = (int) Entry::withoutGlobalScopes()->max('id');

        $this->line('  seeding <info>'.($rows - $existing).'</info> entries…');

        $now = now();
        $chunk = [];

        for ($i = $existing; $i < $rows; $i++) {
            /*
             * ⚠️ EVERY ROW GETS ITS OWN TIMESTAMP, and giving them all `now()` made this corpus lie about
             * the thing it exists to measure. The entry list sorts by `updated_at desc, id desc`; with one
             * shared value that column discriminates nothing, so the ordering falls entirely to `id` and
             * an index is judged on a tiebreak rather than on the sort. Real content is spread over time,
             * and the choice between index shapes is different on each corpus.
             */
            $stamp = $now->copy()->subMinutes($i);

            $chunk[] = [
                'site_id' => $site->getKey(),
                'org_id' => $site->org_id,
                'entry_type_id' => $type->getKey(),
                'type_handle' => $type->handle,
                'status' => $i % 3 === 0 ? 'draft' : 'published',
                'slug' => self::SLUG_PREFIX.$i,
                'title' => "Benchmark entry {$i}",
                'values' => json_encode(['summary' => str_repeat('x', 120)]),
                'published_at' => $stamp,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ];

            if (count($chunk) >= 500) {
                DB::table('entries')->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            DB::table('entries')->insert($chunk);
        }

        return $this->countOfType($site, $type);
    }

    /** Entries of this type on this site — the corpus the entry list actually pages through. */
    private function countOfType(Site $site, EntryType $type): int
    {
        return Entry::withoutGlobalScopes()
            ->where('site_id', $site->getKey())
            ->where('entry_type_id', $type->getKey())
            ->count();
    }

    /**
     * Remove only what this command created.
     *
     * ⚠️ SCOPED TO THE SITE AND TO THIS COMMAND'S OWN PREFIX, and that is not defensive style. The storage
     * benchmark's first version force-deleted every row whose slug matched a pattern across every customer
     * on the installation — on a box with real content that is data loss rather than cleanup. This one
     * borrows a real site, so the same mistake here would delete a customer's content.
     */
    private function cleanUp(Site $site): void
    {
        if ($this->inserted === null) {
            return;
        }

        Entry::withoutGlobalScopes()
            ->where('site_id', $site->getKey())
            ->where('id', '>', $this->inserted)
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->forceDelete();
    }
}
