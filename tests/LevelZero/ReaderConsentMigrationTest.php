<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

it('adds the consent schema to an existing installation and preserves retained records on rollback', function (): void {
    $migration = require __DIR__.'/../../packages/core/database/migrations/0001_01_01_000023_create_reader_consent_events.php';
    $migration->down();

    $org = Org::create(['name' => 'North', 'slug' => 'north']);
    app(Context::class)->setOrg($org);
    $site = Site::create(['handle' => 'north', 'slug' => 'north', 'name' => 'North']);
    $link = DB::table('reader_tokens')->insertGetId([
        'org_id' => $org->id,
        'site_id' => $site->id,
        'purpose' => 'recover',
        'subject' => '41',
        'token_hash' => str_repeat('d', 64),
        'expires_at' => '2026-12-01 00:00:00',
    ]);

    expect(Schema::hasTable('reader_consent_events'))->toBeFalse();
    $migration->up();
    DB::table('reader_consent_events')->insert([
        'org_id' => $org->id,
        'site_id' => $site->id,
        'reader_key' => '41',
        'purpose' => 'profiling',
        'notice_version' => 'first',
        'granted' => true,
        'recorded_at' => '2026-11-26 00:00:00',
        'expires_at' => '2026-12-01 00:00:00',
        'retain_until' => '2026-12-01 00:00:00',
    ]);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Remove or preserve retained reader consent records')
        ->and(DB::table('reader_consent_events')->where('reader_key', '41')->exists())->toBeTrue()
        ->and(DB::table('reader_tokens')->where('id', $link)->exists())->toBeTrue();
});
