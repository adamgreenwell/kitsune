<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->plugin = sys_get_temp_dir().'/kitsune-source-scan-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->plugin.'/src');
    File::put($this->plugin.'/composer.json', json_encode([
        'name' => 'fixture/content',
        'autoload' => ['psr-4' => ['Fixture\\Content\\' => 'src/']],
        'extra' => ['kitsune' => ['provider' => 'Fixture\\Content\\Provider', 'scoping' => ['site']]],
    ], JSON_THROW_ON_ERROR));
});

afterEach(function (): void {
    File::deleteDirectory($this->plugin);
});

it('scans models and query syntax without executing module source', function (): void {
    File::put($this->plugin.'/src/BasePage.php', <<<'SOURCE'
        <?php
        namespace Fixture\Content;

        use Illuminate\Database\Eloquent\Model;
        use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

        abstract class BasePage extends Model
        {
            use EnforcesScope;

            protected $table = 'fixture_pages';
        }
        SOURCE);
    File::put($this->plugin.'/src/Page.php', <<<'SOURCE'
        <?php
        namespace Fixture\Content;

        use Illuminate\Support\Facades\DB as Database;
        use Illuminate\Validation\Rule;
        use Kitsune\Core\Tenancy\Attributes\SiteScoped;

        throw new \RuntimeException('Package source was executed.');

        #[SiteScoped]
        final class Page extends BasePage
        {
            public function draftCount(): int
            {
                return Database::table('fixture_pages')->where('state', 'draft')->count();
            }

            public function rules(): array
            {
                return ['code' => ['required|exists:fixture_pages,code', Rule::unique('fixture_pages')]];
            }

            public function textLabel(): string
            {
                return 'exists: support availability';
            }
        }
        SOURCE);

    $run = fn (bool $strict = false): Process => new Process([
        PHP_BINARY, dirname(__DIR__, 3).'/bin/check-plugin.php', ...($strict ? ['--strict'] : []), $this->plugin,
    ]);
    $result = $run();
    expect($result->run())->toBe(1)->and($result->getErrorOutput())->toBe('');
    $report = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($report['issues'], 'rule'))->toBe(['bare-validation-rule', 'bare-validation-rule', 'unscoped-raw-table-read']);
    expect($run(true)->run())->toBe(1);

    $file = $this->plugin.'/src/Page.php';
    File::put($file, str_replace(
        ["Database::table('fixture_pages')->where('state', 'draft')->count()", "Rule::unique('fixture_pages')", "'required|exists:fixture_pages,code'"],
        ["Database::table('fixture_pages')->where('fixture_pages.site_id', 1)->where('state', 'draft')->count()", "'string'", "'required'"],
        File::get($file),
    ));
    $result = $run();
    expect($result->run())->toBe(0);
    $report = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['issues'])->toBe([])
        ->and(array_column($report['review'], 'rule'))->toContain('review-query-boundary');
    $strictResult = $run(true);
    expect($strictResult->run())->toBe(3)->and($strictResult->getOutput())->toBe($result->getOutput());

    $compliant = File::get($file);
    File::put($file, str_replace('#[SiteScoped]', '', $compliant));
    $result = $run();
    expect($result->run())->toBe(1);
    $report = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($report['issues'], 'rule'))->toBe(['one-model-scope']);

    File::put($file, str_replace('SiteScoped', 'OrgScoped', $compliant));
    $result = $run();
    expect($result->run())->toBe(1);
    $report = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($report['issues'], 'rule'))->toContain('model-scope-outside-manifest');
});

it('accepts a known framework service provider in strict mode', function (): void {
    File::put($this->plugin.'/src/HealthProvider.php', <<<'SOURCE'
        <?php
        namespace Fixture\Content;

        final class HealthProvider extends \Illuminate\Support\ServiceProvider {}
        SOURCE);

    $result = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/check-plugin.php', '--strict', $this->plugin]);
    expect($result->run())->toBe(0);
    expect(json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['issues' => [], 'review' => []]);
});

it('refuses a module that registers itself through Composer file autoload', function (): void {
    $path = $this->plugin.'/composer.json';
    $manifest = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    $manifest['autoload']['files'] = ['bootstrap.php'];
    File::put($path, json_encode($manifest, JSON_THROW_ON_ERROR));

    $result = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/check-plugin.php', $this->plugin]);
    expect($result->run())->toBe(2)
        ->and($result->getErrorOutput())->toContain('autoloads through `files`');
});

it('requires every named declaration to match the package source path', function (): void {
    File::put($this->plugin.'/src/Provider.php', '<?php namespace Fixture\Content; final class Provider {}');
    File::put($this->plugin.'/src/Other.php', '<?php namespace Fixture\Content; final class Provider {}');
    File::put($this->plugin.'/src/Note.PHP', '<?php namespace Fixture\Content; final class Note {}');

    $result = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/check-plugin.php', $this->plugin]);
    expect($result->run())->toBe(1)->and($result->getErrorOutput())->toBe('');
    $report = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($report['issues'], 'rule'))->toBe([
        'nonportable-source-extension', 'source-symbol-outside-psr4', 'duplicate-source-symbol',
    ]);
});
