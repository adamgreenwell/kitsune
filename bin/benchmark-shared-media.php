<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * What admitting the org's shared media costs the admin, on one engine — ADR-042 decision 2's measurement.
 *
 *     DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=kitsune_bench \
 *         DB_USERNAME=kitsune DB_PASSWORD=kitsune php bin/benchmark-shared-media.php
 *
 * ⚠️ IT DROPS AND REBUILDS THE DATABASE IT IS POINTED AT, and refuses one whose name does not contain `bench`. It boots
 * the skeleton, runs `migrate:fresh`, and seeds below every guard with `DB::table()` — a measurement, not content.
 *
 * ⚠️ A SKEWED CORPUS, ON PURPOSE. 290,000 entries: for the measured site, 50,000 images shared across its org and
 * 10,000 of its own; 100,000 images on a sibling site of the same org, which no statement here may return but the
 * org-leading index holds; 50,000 articles on each of the two sites; 30,000 images another org shares on the same
 * global type. `updated_at` is spread across the populations, and one row in twenty is soft-deleted. The sibling's
 * images are what an org-wide read has to walk and throw away, which is the cost this measures.
 *
 * ⚠️ THE STATEMENTS ARE WRITTEN HERE, IN THE SHAPE THE PANEL SENDS, rather than issued through it: the panel's own
 * composition is pinned by `MediaListPlanTest`, and this is about what each engine does with that shape at volume.
 * `SiteScope` is ANDed on every statement, as the kernel does. Plans are unforced — what the planner chooses — after
 * `ANALYZE` on PostgreSQL, MySQL and MariaDB, and without statistics on SQLite, which is how it runs in production.
 * Each timing is the median and the maximum of seven runs after one warm-up.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * The bootstrap comes after the imports: an import applies only to the code after it, and Pint shortens a fully
 * qualified name to its import, so a bootstrap above them names the class `Kernel` — which does not exist.
 */
$root = dirname(__DIR__).'/skeleton';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$driver = DB::connection()->getDriverName();
$database = (string) DB::connection()->getDatabaseName();

if (! str_contains($database, 'bench')) {
    fwrite(STDERR, "Refusing: [{$database}] is not a bench database, and this drops and rebuilds the one it is pointed at.\n");
    exit(1);
}

$say = static function (string $line): void {
    echo $line, PHP_EOL;
};

$version = (string) (DB::selectOne(match ($driver) {
    'sqlite' => 'select sqlite_version() as v',
    default => 'select version() as v',
})->v ?? '');

$say("# {$driver} {$version}");

Artisan::call('migrate:fresh', ['--force' => true]);

DB::table('orgs')->insert([['id' => 1, 'slug' => 'bench', 'name' => 'Bench'], ['id' => 2, 'slug' => 'other', 'name' => 'Other']]);
DB::table('sites')->insert([
    ['id' => 1, 'org_id' => 1, 'handle' => 'here', 'slug' => 'here', 'name' => 'Here', 'locale' => 'en'],
    ['id' => 2, 'org_id' => 1, 'handle' => 'sibling', 'slug' => 'sibling', 'name' => 'Sibling', 'locale' => 'en'],
    ['id' => 3, 'org_id' => 2, 'handle' => 'other', 'slug' => 'other', 'name' => 'Other', 'locale' => 'en'],
]);
DB::table('entry_types')->insert([
    ['id' => 1, 'org_id' => null, 'handle' => 'image', 'name' => 'Image', 'plural_name' => 'Images', 'is_media' => true],
    ['id' => 2, 'org_id' => 1, 'handle' => 'article', 'name' => 'Article', 'plural_name' => 'Articles', 'is_media' => false],
]);

$now = new DateTimeImmutable('2026-09-23 12:00:00');
$n = 0;

foreach ([
    ['shared image, this org', null, 1, 1, 'image', 50_000],
    ['image, this site', 1, 1, 1, 'image', 10_000],
    ['image, sibling site', 2, 1, 1, 'image', 100_000],
    ['article, this site', 1, 1, 2, 'article', 50_000],
    ['article, sibling site', 2, 1, 2, 'article', 50_000],
    ['shared image, other org', null, 2, 1, 'image', 30_000],
] as [$label, $site, $org, $type, $handle, $count]) {
    $chunk = [];

    for ($i = 0; $i < $count; $i++) {
        $n++;
        $stamp = $now->modify('-'.(($n * 7919) % 400_000).' minutes')->format('Y-m-d H:i:s');

        $chunk[] = [
            'site_id' => $site, 'org_id' => $org, 'entry_type_id' => $type, 'type_handle' => $handle,
            'status' => $i % 3 === 0 ? 'draft' : 'published',
            'slug' => $site === null ? null : "s{$n}",
            'title' => "{$label} {$n}",
            'created_at' => $stamp, 'updated_at' => $stamp,
            'deleted_at' => $i % 20 === 0 ? $stamp : null,
        ];

        if (count($chunk) === 1000) {
            DB::table('entries')->insert($chunk);
            $chunk = [];
        }
    }

    if ($chunk !== []) {
        DB::table('entries')->insert($chunk);
    }
}

match ($driver) {
    'pgsql' => DB::statement('ANALYZE entries'),
    'mysql', 'mariadb' => DB::statement('ANALYZE TABLE entries'),
    default => null,
};

$say("corpus: {$n} entries");

$site = 1;
$org = 1;
$media = [1];

// The kernel's `SiteScope`, and soft deletes, on every statement.
$scoped = static fn (): Builder => DB::table('entries')->whereNull('deleted_at')
    ->where(fn ($q) => $q->where('site_id', $site)->orWhere(fn ($x) => $x->whereNull('site_id')->where('org_id', $org)));

// Filament's rule as built: this site's rows, and the org's shared rows of the media types enabled here.
$widened = static fn (): Builder => $scoped()
    ->where(fn ($q) => $q->where('site_id', $site)->orWhere(fn ($x) => $x->whereNull('site_id')->where('org_id', $org)->whereIn('entry_type_id', $media)));

// Filament's unwidened rule, which the non-media lists, the counts and Recent keep.
$siteOnly = static fn (): Builder => $scoped()->where('site_id', $site);

// The rejected first draft: `org_id` at the top of the widened rule.
$orgAtTop = static fn (): Builder => $scoped()
    ->where('org_id', $org)->where(fn ($q) => $q->where('site_id', $site)->orWhere(fn ($x) => $x->whereNull('site_id')->whereIn('entry_type_id', $media)));

$mediaList = static fn (): Builder => $widened()->where('org_id', $org)->where('entry_type_id', 1)->orderByDesc('updated_at')->orderByDesc('id');
$picker = static fn (callable $rule, string $handle = 'image'): Builder => $rule()->whereIn('type_handle', [$handle])->whereLike('title', '%12345%', caseSensitive: false)->orderBy('title')->limit(50)->select(['id', 'title']);
$counts = static fn (callable $rule): Builder => $rule()->whereIn('entry_type_id', [1, 2])->select(['entry_type_id', 'status'])->selectRaw('count(*) as aggregate')->groupBy(['entry_type_id', 'status']);

$statements = [
    'As built' => [
        'media list, page 1 (simple pagination: 11 rows)' => $mediaList()->limit(11),
        'media list, page 50 by Next' => $mediaList()->offset(490)->limit(11),
        'media list, title search matching one file' => $mediaList()->whereLike('title', '%12345%', caseSensitive: false)->limit(11),
        'media list, sorted by title' => $widened()->where('org_id', $org)->where('entry_type_id', 1)->orderBy('title')->orderByDesc('id')->limit(11),
        'media list, sorted by status' => $widened()->where('org_id', $org)->where('entry_type_id', 1)->orderBy('status')->orderByDesc('id')->limit(11),
        'relation picker, search' => $picker($widened),
        'relation picker for articles, search (this site\'s own: no target is media)' => $picker($siteOnly, 'article'),
        'relation picker, labels' => $widened()->whereIn('type_handle', ['image'])->whereIn('id', [2, 50_002])->select(['id', 'title']),
        'dashboard counts (this site\'s own)' => $counts($siteOnly),
        'recent entries (this site\'s own)' => $siteOnly()->whereIn('entry_type_id', [1, 2])->orderByDesc('updated_at')->orderByDesc('id')->limit(10),
        'article list, page 1 (control)' => $siteOnly()->where('entry_type_id', 2)->orderByDesc('updated_at')->orderByDesc('id')->limit(10),
    ],
    'Before this slice, for comparison: the same statements under the unwidened rule' => [
        'media list, page 1, this site\'s own' => $siteOnly()->where('entry_type_id', 1)->orderByDesc('updated_at')->orderByDesc('id')->limit(10),
        'media list count, this site\'s own' => $siteOnly()->where('entry_type_id', 1)->selectRaw('count(*) as aggregate'),
        'media list, title search matching one file, this site\'s own' => $siteOnly()->where('entry_type_id', 1)->whereLike('title', '%12345%', caseSensitive: false)->orderByDesc('updated_at')->orderByDesc('id')->limit(10),
        'media list, sorted by title, this site\'s own' => $siteOnly()->where('entry_type_id', 1)->orderBy('title')->orderByDesc('id')->limit(10),
        'relation picker, search, this site\'s own' => $picker($siteOnly),
    ],
    'Rejected, for the record' => [
        'media list count, which full pagination runs on every request' => $mediaList()->reorder()->selectRaw('count(*) as aggregate'),
        'media list, last page by offset' => $mediaList()->offset(56_990)->limit(10),
        'media list, page 1 with no org conjunct' => $widened()->where('entry_type_id', 1)->orderByDesc('updated_at')->orderByDesc('id')->limit(11),
        'dashboard counts, widened' => $counts($widened),
        'relation picker for articles, search, widened' => $picker($widened, 'article'),
        'relation picker, search, org at the top' => $picker($orgAtTop),
    ],
];

$plan = static function (Builder $query) use ($driver): string {
    $sql = $query->toSql();
    $bindings = $query->getBindings();

    $rows = match ($driver) {
        'sqlite' => DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings),
        'pgsql' => DB::select('EXPLAIN '.$sql, $bindings),
        'mysql' => DB::select('EXPLAIN FORMAT=TREE '.$sql, $bindings),
        default => DB::select('EXPLAIN '.$sql, $bindings),
    };

    return implode(' | ', array_map(static function (object $row) use ($driver): string {
        $r = (array) $row;

        return match ($driver) {
            'sqlite' => (string) $r['detail'],
            'pgsql' => trim((string) $r['QUERY PLAN']),
            'mysql' => (string) preg_replace('/\s+/', ' ', (string) array_values($r)[0]),
            default => sprintf('%s key=%s rows=%s %s', $r['type'] ?? '', $r['key'] ?? '-', $r['rows'] ?? '', $r['Extra'] ?? ''),
        };
    }, $rows));
};

$time = static function (Builder $query): array {
    $query->get();
    $samples = [];

    for ($i = 0; $i < 7; $i++) {
        $start = hrtime(true);
        $query->get();
        $samples[] = (hrtime(true) - $start) / 1e6;
    }

    sort($samples);

    return [$samples[3], $samples[6]];
};

foreach ($statements as $section => $queries) {
    $say('');
    $say("## {$section}");
    $say('');
    $say('| statement | median ms | max ms | rows |');
    $say('|---|---|---|---|');

    $plans = [];

    foreach ($queries as $name => $query) {
        [$median, $max] = $time($query);
        $say(sprintf('| %s | %.2f | %.2f | %d |', $name, $median, $max, count($query->get())));
        $plans[$name] = $plan($query);
    }

    $say('');

    foreach ($plans as $name => $text) {
        $say("- **{$name}**: `{$text}`");
    }
}
