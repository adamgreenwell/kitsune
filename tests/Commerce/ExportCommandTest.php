<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Support\Facades\DB;
use Kitsune\Commerce\CommerceServiceProvider;
use Kitsune\Commerce\Console\ExportCommand;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as ExportConsoleFx;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    ExportConsoleFx::boot();
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    $this->site = $this->world['sites']['golfdom'];
    app(Context::class)->setSite($this->site);
    Module::create(['handle' => 'kitsune/commerce', 'version' => 'old-installed-version', 'is_enabled' => false, 'installed_at' => now()]);
    $this->order = OrderFixture::plant($this->site);
    $this->sibling = OrderFixture::plant($this->world['sites']['golfdom-fr']);
    $this->foreign = OrderFixture::plant($this->world['sites']['rival']);
});

afterEach(fn () => ExportConsoleFx::tearDown());

/** @return array{int,string,string} */
function commerceExportCommand(array $arguments): array
{
    $command = app(ExportCommand::class);
    $command->setLaravel(app());
    $test = new CommandTester($command);
    $status = $test->execute($arguments, ['interactive' => false, 'capture_stderr_separately' => true]);

    return [$status, $test->getDisplay(), $test->getErrorOutput()];
}

it('writes only complete raw financial data to stdout across the explicit Org or exact Site', function (string $format, bool $site): void {
    $before = app(Context::class)->site();
    [$status, $data, $diagnostic] = commerceExportCommand(['--org' => $this->org->slug, '--format' => $format] + ($site ? ['--site' => $this->site->handle] : []));
    expect($status)->toBe(0)->and($data)->toContain($this->order->reference)->not->toContain($this->foreign->reference, 'Historical Buyer', 'historical-buyer@example.test')
        ->and(str_contains($data, $this->sibling->reference))->toBe(! $site)->and($diagnostic)->toBe('')
        ->and(app(Context::class)->site())->toBe($before)->and(app(Context::class)->orgId())->toBe($this->org->id);
})->with([['jsonl', false], ['jsonl', true], ['csv', false], ['csv', true]]);

it('keeps cursor diagnostics on stderr and refuses scope or profile changes without partial stdout', function (): void {
    [$status, $data, $diagnostic] = commerceExportCommand(['--org' => $this->org->slug, '--limit' => '1']);
    expect($status)->toBe(0)->and($data)->toContain($this->order->reference)->and($diagnostic)->toStartWith('Next cursor: ');
    $cursor = trim(substr($diagnostic, strlen('Next cursor: ')));
    [$status, $next] = commerceExportCommand(['--org' => $this->org->slug, '--limit' => '1', '--cursor' => $cursor]);
    expect($status)->toBe(0)->and($next)->toContain($this->sibling->reference)->not->toContain($this->order->reference);
    [$status, $csv] = commerceExportCommand(['--org' => $this->org->slug, '--format' => 'csv', '--cursor' => $cursor]);
    expect($status)->toBe(0)->and($csv)->toContain($this->sibling->reference)->not->toContain($this->order->reference);
    foreach ([['--include-billing' => true], ['--site' => $this->site->handle], ['--org' => $this->world['rival']->slug]] as $profile) {
        [$status, $data, $error] = commerceExportCommand($profile + ['--org' => $this->org->slug, '--cursor' => $cursor]);
        expect($status)->toBe(1)->and($data)->toBe('')->and($error)->toContain('Commerce export could not complete.');
    }
});

it('refuses malformed options or a foreign Site handle and restores prior context', function (array $arguments): void {
    $before = app(Context::class)->site();
    [$status, $data, $error] = commerceExportCommand($arguments);
    expect($status)->toBe(1)->and($data)->toBe('')->and($error)->toContain('Commerce export could not complete.')
        ->not->toContain('private-bad-value')->and(app(Context::class)->site())->toBe($before);
})->with([
    'missing Org' => [[]], 'unknown Org' => [['--org' => 'private-bad-value']], 'format' => [['--org' => 'golfdom', '--format' => 'private-bad-value']],
    'zero limit' => [['--org' => 'golfdom', '--limit' => '0']], 'excess limit' => [['--org' => 'golfdom', '--limit' => '101']],
    'foreign Site' => [['--org' => 'golfdom', '--site' => 'rival']], 'empty cursor' => [['--org' => 'golfdom', '--cursor' => '']],
]);

it('never treats a signed-in nonowner as console system authority', function (): void {
    ExportConsoleFx::member(owner: false, email: 'unprivileged-export@example.test');
    [$status, $data, $error] = commerceExportCommand(['--org' => $this->org->slug]);
    expect($status)->toBe(1)->and($data)->toBe('')->and($error)->toContain('Commerce export could not complete.');
});

it('preserves literal formatter tags in the complete raw stdout artifact', function (): void {
    DB::table('commerce_order_lines')->where('order_id', $this->order->id)->update(['title' => '<info>literal frozen title</info>']);
    [$status, $data, $error] = commerceExportCommand(['--org' => $this->org->slug, '--site' => $this->site->handle]);
    expect($status)->toBe(0)->and($data)->toContain('<info>literal frozen title</info>')->and($error)->toBe('');
});

it('refuses retrieval callbacks substituting the explicit Org or Site bootstrap identity', function (string $attack): void {
    $class = $attack === 'Org' ? Org::class : Site::class;
    $dispatcher = $class::getEventDispatcher();
    $class::setEventDispatcher(clone $dispatcher);
    $target = $attack === 'Org' ? $this->world['rival']->id : $this->world['sites']['golfdom-fr']->id;
    $class::retrieved(static function ($model) use ($target): void {
        $model->setAttribute($model->getKeyName(), $target);
    });
    try {
        [$status, $data, $error] = commerceExportCommand(['--org' => $this->org->slug, '--site' => $this->site->handle]);
        expect($status)->toBe(1)->and($data)->toBe('')->and($error)->toContain('Commerce export could not complete.');
    } finally {
        $class::setEventDispatcher($dispatcher);
    }
})->with(['Org', 'Site']);

it('discovers cold export without runtime boot and rechecks a removed receipt on its cached instance', function (): void {
    ConsoleApplication::forgetBootstrappers();
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    try {
        $console = new ConsoleApplication(app(), app('events'), 'test');
        expect($console->has('kitsune:commerce-export'))->toBeTrue()->and(app()->getProviders(CommerceServiceProvider::class))->toBe([]);
        $command = $console->find('kitsune:commerce-export');
        $test = new CommandTester($command);
        expect($test->execute(['--org' => $this->org->slug], ['interactive' => false, 'capture_stderr_separately' => true]))->toBe(0)
            ->and($test->getDisplay())->toContain($this->order->reference);
        DB::table('modules')->where('handle', 'kitsune/commerce')->delete();
        expect($test->execute(['--org' => $this->org->slug], ['interactive' => false, 'capture_stderr_separately' => true]))->toBe(1)
            ->and($test->getDisplay())->toBe('')->and($test->getErrorOutput())->toContain('Commerce export could not complete.');
    } finally {
        ConsoleApplication::forgetBootstrappers();
    }
});
