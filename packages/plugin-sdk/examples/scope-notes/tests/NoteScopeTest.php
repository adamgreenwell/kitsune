<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Kitsune\Example\ScopeNotes\Note;
use Kitsune\PluginSdk\Testing\PluginTestCase;
use Kitsune\PluginSdk\Testing\ScopeFixture;

use function Orchestra\Testbench\load_migration_paths;

final class NoteScopeTest extends PluginTestCase
{
    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, __DIR__.'/../database/migrations');
    }

    public function test_it_scopes_a_note_to_the_owning_site(): void
    {
        $fixture = ScopeFixture::create();
        $note = $fixture->inSite($fixture->site, fn () => Note::create(['slug' => 'handout']));

        $fixture->assertSiteIsolation(fn (): bool => Note::whereKey($note->getKey())->exists());
        $copy = $fixture->inSite($fixture->sibling, fn () => Note::create(['slug' => 'handout']));
        self::assertNotSame($note->getKey(), $copy->getKey());
    }

    public function test_it_enforces_sqlite_foreign_keys(): void
    {
        ScopeFixture::create();
        self::assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->expectException(QueryException::class);

        DB::table('scope_notes')->insert(['site_id' => PHP_INT_MAX, 'org_id' => PHP_INT_MAX, 'slug' => 'missing']);
    }
}
