<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('checks the reviewed module and the Composer-installed copy before loading module code', function (): void {
    $fixture = sys_get_temp_dir().'/kitsune-plugin-delivery-'.bin2hex(random_bytes(8));
    $source = $fixture.'/package';
    $consumer = $fixture.'/consumer';
    $installed = $consumer.'/vendor/fixture/notes';
    $metadata = $consumer.'/vendor/composer/installed.json';
    $receipt = $fixture.'/review.json';
    $tools = dirname(__DIR__, 3).'/bin';
    $fs = new Filesystem;
    $fs->ensureDirectoryExists($source.'/src');
    $fs->ensureDirectoryExists($source.'/resources');
    $fs->ensureDirectoryExists($consumer);
    $manifest = [
        'name' => 'fixture/notes',
        'autoload' => ['psr-4' => ['Fixture\\Notes\\' => 'src/']],
        'extra' => ['kitsune' => ['provider' => 'Fixture\\Notes\\Provider', 'scoping' => ['site']]],
    ];

    try {
        $fs->put($source.'/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $fs->put($source.'/resources/guide.txt', 'Reviewed operator help.');
        $fs->put($source.'/src/Provider.php', <<<'SOURCE'
            <?php
            namespace Fixture\Notes;

            final class Provider extends \Kitsune\Core\Modules\ModuleServiceProvider {}
            throw new \LogicException('Delivery verification must not execute module source.');
            SOURCE);
        $fs->put($consumer.'/composer.json', json_encode([
            'name' => 'fixture/consumer',
            'require' => ['fixture/notes' => '1.2.0'],
            'repositories' => [
                ['type' => 'path', 'url' => '../package', 'options' => ['symlink' => false, 'versions' => ['fixture/notes' => '1.2.0']]],
                ['packagist.org' => false],
            ],
        ], JSON_THROW_ON_ERROR));

        $command = static fn (string $tool, string ...$args): Process => new Process([PHP_BINARY, $tools.'/'.$tool, ...$args]);
        $scan = static fn (string $path): Process => $command('check-plugin.php', '--strict', $path)->mustRun();
        $sourceScan = $scan($source);
        $review = $command('check-plugin-distribution.php', 'record', $source, '1.2.0')->mustRun();
        $fs->put($receipt, $review->getOutput());
        (new Process(
            ['composer', 'install', '--prefer-dist', '--no-plugins', '--no-scripts', '--no-interaction', '--no-progress'],
            $consumer, [
                'COMPOSER_DISABLE_NETWORK' => '1', 'COMPOSER_ALLOW_SUPERUSER' => '1',
                'COMPOSER_HOME' => $fixture.'/composer-home', 'COMPOSER_CACHE_DIR' => $fixture.'/composer-cache',
            ],
        ))->mustRun();

        $verify = static fn (): Process => $command('check-plugin-distribution.php', 'verify', $installed, $receipt, $metadata);
        $readback = $verify()->mustRun();
        expect(json_decode($readback->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'package' => 'fixture/notes', 'version' => '1.2.0', 'verified_files' => 3,
        ])->and($scan($installed)->getOutput())->toBe($sourceScan->getOutput());

        $fs->put($installed.'/resources/guide.txt', 'Changed after review.');
        $badFile = $verify();
        expect($badFile->run())->toBe(1)->and($badFile->getErrorOutput())->toContain('files or metadata differ');
        $fs->put($installed.'/resources/guide.txt', 'Reviewed operator help.');
        expect($verify()->run())->toBe(0);

        $resolved = json_decode($fs->get($metadata), true, flags: JSON_THROW_ON_ERROR);
        $resolved['packages'][0]['replace'] = ['fixture/other' => '*'];
        $fs->put($metadata, json_encode($resolved, JSON_THROW_ON_ERROR));
        $badResolver = $verify();
        expect($badResolver->run())->toBe(1)->and($badResolver->getErrorOutput())->toContain('resolved package metadata');
        expect($command('check-plugin-distribution.php', 'record', '/')->run())->toBe(2);
    } finally {
        $fs->deleteDirectory($fixture);
    }
});
