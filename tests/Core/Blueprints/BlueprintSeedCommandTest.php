<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Blueprints\Declarations\SeedEntryDeclaration;
use Kitsune\Core\Models\BlueprintSeed;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\SeedBlueprint;

beforeEach(function (): void {
    $this->definition = new SeedBlueprint([new SeedEntryDeclaration('welcome', 'seed_article', 'Welcome', 'welcome')]);
    app(BlueprintRegistry::class)->register($this->definition);
    $this->org = Org::create(['name' => 'Seed console', 'slug' => 'seed-console']);
    app(Context::class)->setOrg($this->org);
    $this->site = Site::create(['org_id' => $this->org->id, 'name' => 'Main', 'slug' => 'console-main', 'handle' => '123', 'locale' => 'de']);
    BlueprintApplier::apply($this->definition);
    app(Context::class)->setSite($this->site);
});

afterEach(fn () => app(Context::class)->forget());

it('seeds an exact numeric-looking site handle with an explicit locale and restores context', function (): void {
    expect(Artisan::call('kitsune:blueprint', ['action' => 'seed', 'handle' => 'seed_test', '--org' => 'seed-console', '--site' => '123', '--locale' => 'de']))->toBe(0)
        ->and(Entry::query()->sole()->site_id)->toBe($this->site->id)
        ->and(app(Context::class)->siteId())->toBe($this->site->id);
});

it('refuses a non-identical site handle matched by a case-insensitive database', function (): void {
    $target = Site::create([
        'org_id' => $this->org->id,
        'name' => 'Seed target',
        'slug' => 'console-target',
        'handle' => 'target',
        'locale' => 'de',
    ]);
    $alias = 'TARGET';
    if (Site::query()->where('handle', $alias)->value('id') !== $target->id) {
        $this->markTestSkipped('The site handle column uses a case-sensitive collation.');
    }

    $before = [];
    foreach (['blueprints', 'blueprint_seeds', 'entries', 'entry_revisions', 'audit_log', 'field_storage'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->all();
    }
    $args = ['action' => 'seed', 'handle' => 'seed_test', '--org' => 'seed-console', '--locale' => 'de'];
    expect(Artisan::call('kitsune:blueprint', [...$args, '--site' => $alias]))->toBe(1)
        ->and(Artisan::output())->toContain('Nothing was written')
        ->and(app(Context::class)->siteId())->toBe($this->site->id);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->all())->toEqual($rows);
    }

    expect(Artisan::call('kitsune:blueprint', [...$args, '--site' => $target->handle]))->toBe(0)
        ->and(app(Context::class)->siteId())->toBe($this->site->id);
    app(Context::class)->setSite($target);
    expect(Entry::query()->sole()->site_id)->toBe($target->id);
});

it('refuses missing explicit selection wrong locale and bootstrap options before writing', function (): void {
    $base = ['action' => 'seed', 'handle' => 'seed_test', '--org' => 'seed-console', '--site' => '123', '--locale' => 'de'];
    $cases = [];
    foreach (['--org', '--site', '--locale'] as $missing) {
        $args = $base;
        unset($args[$missing]);
        $cases[] = $args;
    }
    $cases[] = [...$base, '--site' => (string) $this->site->id];
    $cases[] = [...$base, '--locale' => 'en'];
    $cases[] = [...$base, '--org' => 'does-not-exist'];
    foreach (['--owner' => 'nobody@example.test', '--owner-password-stdin' => true, '--org-name' => 'Bootstrap'] as $option => $value) {
        $cases[] = [...$base, $option => $value];
    }
    foreach ($cases as $args) {
        expect(Artisan::call('kitsune:blueprint', $args))->toBe(1)
            ->and(BlueprintSeed::count())->toBe(0)->and(Entry::count())->toBe(0)
            ->and(Org::count())->toBe(1)->and(Site::count())->toBe(1)
            ->and(app(Context::class)->siteId())->toBe($this->site->id);
    }
});

it('reports separate committed seed facts without showing field values', function (): void {
    Artisan::call('kitsune:blueprint', ['action' => 'seed', 'handle' => 'seed_test', '--org' => 'seed-console', '--site' => '123', '--locale' => 'de']);
    expect(Artisan::call('kitsune:blueprint', ['action' => 'status']))->toBe(0)
        ->and(Artisan::output())->toContain('Content facts')->toContain('1 created / 0 skipped')->not->toContain('Welcome')
        ->and(app(Context::class)->siteId())->toBe($this->site->id);
});
