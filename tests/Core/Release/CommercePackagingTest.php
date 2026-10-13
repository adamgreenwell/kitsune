<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->packaging = realpath(sys_get_temp_dir()).'/kitsune-commerce-packaging-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->packaging.'/bin');
    File::ensureDirectoryExists($this->packaging.'/skeleton');
    File::copy(dirname(__DIR__, 3).'/bin/install-commerce-skeleton.php', $this->packaging.'/bin/install-commerce-skeleton.php');
    $this->skeletonManifest = (string) File::get(dirname(__DIR__, 3).'/skeleton/composer.json');
    File::put($this->packaging.'/skeleton/composer.json', $this->skeletonManifest);
});

afterEach(function (): void {
    File::deleteDirectory($this->packaging);
});

/** A child Composer stand-in: reads the real temporary manifest, then leaves the child backup on either outcome. */
function commerceSetupComposer(): string
{
    return <<<'PHP'
    <?php

    $manifest = json_decode(file_get_contents('skeleton/composer.json'), true);
    file_put_contents('observed.json', json_encode(['argv' => $argv, 'require' => $manifest['require']]));
    copy('skeleton/composer.json', 'skeleton/composer.json.orig');
    file_put_contents('skeleton/composer.json', '{"interrupted":"child setup"}');
    exit((int) getenv('SETUP_EXIT'));

    PHP;
}

it('restores the exact default skeleton after successful and failed opt-in development setup', function (int $exit): void {
    File::put($this->packaging.'/composer.php', commerceSetupComposer());
    $run = new Process([PHP_BINARY, $this->packaging.'/bin/install-commerce-skeleton.php'], $this->packaging, [
        'COMPOSER_BINARY' => $this->packaging.'/composer.php',
        'SETUP_EXIT' => (string) $exit,
    ]);
    $run->run();

    $observed = json_decode((string) File::get($this->packaging.'/observed.json'), true);

    expect($run->getExitCode())->toBe($exit)
        ->and($observed['require']['kitsune/commerce'])->toBe('@dev')
        ->and(array_slice($observed['argv'], 1))->toBe(['skeleton:install', '--no-interaction'])
        ->and(File::get($this->packaging.'/skeleton/composer.json'))->toBe($this->skeletonManifest)
        ->and(file_exists($this->packaging.'/skeleton/composer.json.orig'))->toBeFalse()
        ->and(json_decode($this->skeletonManifest, true)['require'])->not->toHaveKey('kitsune/commerce');
})->with([0, 42]);

it('does not overwrite a backup from an earlier interrupted skeleton setup', function (): void {
    File::put($this->packaging.'/composer.php', commerceSetupComposer());
    File::put($this->packaging.'/skeleton/composer.json.orig', 'the earlier original');
    $run = new Process([PHP_BINARY, $this->packaging.'/bin/install-commerce-skeleton.php'], $this->packaging, [
        'COMPOSER_BINARY' => $this->packaging.'/composer.php',
    ]);
    $run->run();

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('recover that interrupted setup')
        ->and(File::get($this->packaging.'/skeleton/composer.json'))->toBe($this->skeletonManifest)
        ->and(File::get($this->packaging.'/skeleton/composer.json.orig'))->toBe('the earlier original')
        ->and(file_exists($this->packaging.'/observed.json'))->toBeFalse();
});

it('refuses standalone setup without an identified Composer binary before changing the skeleton', function (): void {
    $run = new Process([PHP_BINARY, $this->packaging.'/bin/install-commerce-skeleton.php'], $this->packaging, ['COMPOSER_BINARY' => false]);
    $run->run();

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('composer skeleton:commerce')
        ->and(File::get($this->packaging.'/skeleton/composer.json'))->toBe($this->skeletonManifest);
});

it('resolves only matched core and commerce versions with the actual commerce manifest, offline', function (bool $mismatch): void {
    $composer = (new ExecutableFinder)->find('composer');
    expect($composer)->not->toBeNull('Composer is required to install this development workspace');

    $root = $this->packaging.'/solver';
    File::ensureDirectoryExists($root.'/packages/core');
    File::ensureDirectoryExists($root.'/packages/commerce');
    File::ensureDirectoryExists($root.'/app');
    File::put($root.'/packages/core/composer.json', json_encode([
        'name' => 'kitsune/core',
        'version' => $mismatch ? '0.4.1' : '0.4.0',
        'license' => 'MPL-2.0',
    ], JSON_THROW_ON_ERROR));
    $commerce = json_decode((string) File::get(dirname(__DIR__, 3).'/packages/commerce/composer.json'), true);
    $commerce['version'] = '0.4.0';
    File::put($root.'/packages/commerce/composer.json', json_encode($commerce, JSON_THROW_ON_ERROR));
    File::put($root.'/app/composer.json', json_encode([
        'name' => 'kitsune/offline-packaging-proof',
        'repositories' => [
            ['type' => 'path', 'url' => '../packages/*', 'options' => ['symlink' => false]],
            ['packagist.org' => false],
        ],
        'require' => ['kitsune/commerce' => '0.4.0'],
    ], JSON_THROW_ON_ERROR));

    $run = new Process([
        PHP_BINARY, $composer, 'update', '--no-interaction', '--no-plugins', '--no-scripts', '--no-audit',
    ], $root.'/app', ['COMPOSER_DISABLE_NETWORK' => '1']);
    $run->setTimeout(30);
    $run->run();

    if ($mismatch) {
        expect($run->isSuccessful())->toBeFalse()
            ->and($run->getErrorOutput())->toContain('kitsune/commerce 0.4.0 requires kitsune/core 0.4.0')
            ->and(is_dir($root.'/app/vendor/kitsune/commerce'))->toBeFalse();

        return;
    }

    expect($run->isSuccessful())->toBeTrue($run->getErrorOutput());

    foreach (['core', 'commerce'] as $name) {
        expect(is_link($root.'/app/vendor/kitsune/'.$name))->toBeFalse()
            ->and(File::get($root.'/app/vendor/kitsune/'.$name.'/composer.json'))
            ->toBe(File::get($root.'/packages/'.$name.'/composer.json'));
    }

    $inventory = json_decode((string) File::get($root.'/app/vendor/composer/installed.json'), true);
    expect(array_column($inventory['packages'], 'version', 'name'))->toBe([
        'kitsune/commerce' => '0.4.0',
        'kitsune/core' => '0.4.0',
    ]);
})->with(['matched' => [false], 'mismatched' => [true]]);
