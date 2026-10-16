<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Console\RetentionCommand;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;

beforeEach(function (): void {
    CredentialFixture::boot();
    $this->world = ReaderFixture::world();
    $this->org = $this->world['golfdom'];
    app(Context::class)->setSite($this->world['sites']['golfdom']);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'old-installed-version', 'is_enabled' => false, 'installed_at' => now()]);
    Artisan::registerCommand(app(RetentionCommand::class));
});

afterEach(fn () => CredentialFixture::tearDown());

it('defaults to preview and restores the exact previous Org and Site after explicit Org maintenance', function (): void {
    $before = app(Context::class)->site();
    $this->artisan('kitsune:commerce-retention', ['--org' => $this->world['rival']->slug, '--limit' => '1'])
        ->expectsOutput('Preview only: 0 orders examined; 0 retained orders minimized; 0 order parents deleted; 0 held. Minimized parents are not counted as deleted.')
        ->assertSuccessful();
    expect(app(Context::class)->site())->toBe($before)->and(app(Context::class)->orgId())->toBe($this->org->id);
    $this->artisan('kitsune:commerce-retention', ['--org' => $this->org->slug, '--apply' => true])
        ->expectsOutput('Retention applied: 0 orders examined; 0 retained orders minimized; 0 order parents deleted; 0 held. Minimized parents are not counted as deleted.')
        ->assertSuccessful();
});

it('rejects missing or malformed scope and bounds without losing previous request context', function (array $arguments): void {
    $before = app(Context::class)->site();
    $this->artisan('kitsune:commerce-retention', $arguments)->assertFailed();
    expect(app(Context::class)->site())->toBe($before)->and(app(Context::class)->orgId())->toBe($this->org->id);
})->with([
    'missing Org' => [[]],
    'zero bound' => [['--org' => 'golfdom', '--limit' => '0']],
    'excess bound' => [['--org' => 'golfdom', '--limit' => '1001']],
    'negative bound' => [['--org' => 'golfdom', '--limit' => '-1']],
    'unknown Org' => [['--org' => 'private-unknown-org']],
    'control in Org' => [['--org' => "golfdom\nprivate"]],
]);

it('discovers installed commerce retention maintenance without booting disabled or stale runtime', function (): void {
    // A separate console has no access to the command manually registered in this application's test harness.
    ConsoleApplication::forgetBootstrappers();
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    try {
        $console = new ConsoleApplication(app(), app('events'), 'test');
        expect(app()->getProviders(CommerceServiceProvider::class))->toBe([])
            ->and($console->has('kitsune:commerce-retention'))->toBeTrue()
            ->and($console->call('kitsune:commerce-retention', ['--org' => $this->org->slug]))->toBe(Command::SUCCESS)
            ->and($console->output())->toContain('Preview only:');
    } finally {
        ConsoleApplication::forgetBootstrappers();
    }
});
