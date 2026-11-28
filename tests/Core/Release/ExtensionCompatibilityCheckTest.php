<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function extensionCompatibilityInput(string $directory): array
{
    foreach (['published', 'candidate', 'cache'] as $name) {
        mkdir($directory.'/'.$name, 0700);
    }

    $vendor = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
    file_put_contents($directory.'/autoload.php', '<?php require '.$vendor.'; require __DIR__."/contract.php";');
    $source = <<<'PHP'
        <?php
        namespace Kitsune\CompatibilityFixture;

        class Document
        {
            public function title(string $locale): string
            {
                trigger_error('Use headline() in v1.3.0.', E_USER_DEPRECATED);

                return $this->line($locale);
            }

            public function headline(string $locale): string
            {
                return $this->line($locale);
            }

            protected function line(string $locale): string
            {
                return $locale === 'ar' ? 'Arabic' : 'English';
            }

            public function viewData(): array
            {
                return ['heading' => 'News & Notes', 'menu' => ['children' => ['archive']]];
            }
        }
        PHP;
    file_put_contents($directory.'/contract.php', $source);

    $phpProbe = <<<'PHP'
        <?php
        require $argv[1];

        $plugin = new class extends Kitsune\CompatibilityFixture\Document
        {
            protected function line(string $locale): string
            {
                return parent::line($locale).' from the existing plugin';
            }
        };
        set_error_handler(static fn (int $error, string $message): bool => $error === E_USER_DEPRECATED);
        if ($plugin->title(locale: 'ar') !== 'Arabic from the existing plugin') {
            throw new RuntimeException('Retained plugin changed.');
        }
        echo "compatible\n";
        PHP;
    $bladeProbe = <<<'PHP'
        <?php
        require $argv[1];
        set_error_handler(static function (int $error, string $message): never {
            throw new ErrorException($message, 0, $error);
        });
        $fs = new Illuminate\Filesystem\Filesystem;
        $compiler = new Illuminate\View\Compilers\BladeCompiler($fs, __DIR__.'/../cache');
        $template = $compiler->compileString('<h1>{{ $heading }}</h1><p>{{ $menu["children"][0] }}</p>');
        extract((new Kitsune\CompatibilityFixture\Document)->viewData());
        ob_start();
        try {
            eval('?>'.$template);
        } finally {
            $rendered = ob_get_clean();
        }
        if ($rendered !== '<h1>News &amp; Notes</h1><p>archive</p>') {
            throw new RuntimeException('Retained theme changed.');
        }
        echo "compatible\n";
        PHP;
    $newProbe = <<<'PHP'
        <?php
        require $argv[1];
        if ((new Kitsune\CompatibilityFixture\Document)->headline(locale: 'ar') !== 'Arabic') {
            throw new RuntimeException('Replacement consumer changed.');
        }
        echo "compatible\n";
        PHP;
    foreach (['published', 'candidate'] as $release) {
        file_put_contents($directory.'/'.$release.'/php.php', $phpProbe);
        file_put_contents($directory.'/'.$release.'/theme.php', $bladeProbe);
    }
    file_put_contents($directory.'/candidate/replacement.php', $newProbe);

    $old = [
        'release' => '1.2.0',
        'exports' => [
            ['name' => 'Kitsune\CompatibilityFixture\Document::title', 'kind' => 'method',
                'probe' => 'php', 'probe_sha256' => hash('sha256', $phpProbe), 'status' => 'stable'],
            ['name' => 'Kitsune\CompatibilityFixture\Document::line', 'kind' => 'protected_method',
                'probe' => 'php', 'probe_sha256' => hash('sha256', $phpProbe), 'status' => 'stable'],
            ['name' => 'blade.menu-children', 'kind' => 'behavior',
                'probe' => 'theme', 'probe_sha256' => hash('sha256', $bladeProbe), 'status' => 'stable'],
        ],
    ];
    $new = $old;
    $new['release'] = '1.3.0';
    $new['exports'][0] = array_replace($new['exports'][0], [
        'status' => 'deprecated', 'since' => '1.3.0', 'replacement' => 'headline', 'migration_guide' => 'upgrade.md#headline',
    ]);
    $new['exports'][] = ['name' => 'Kitsune\CompatibilityFixture\Document::headline', 'kind' => 'method',
        'probe' => 'replacement', 'probe_sha256' => hash('sha256', $newProbe), 'status' => 'stable'];

    return [$source, $old, $new];
}

function runExtensionCompatibilityFixture(string $directory, array $old, array $new): Process
{
    foreach (['published' => $old, 'candidate' => $new] as $name => $inventory) {
        file_put_contents($directory.'/'.$name.'.json', json_encode($inventory, JSON_THROW_ON_ERROR));
    }

    $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/check-extension-compatibility.php',
        $directory.'/published.json', $directory.'/candidate.json', $directory.'/autoload.php',
        $directory.'/published', $directory.'/candidate']);
    $process->run();

    return $process;
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/kit-ext-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0700);
    [$this->source, $this->published, $this->candidate] = extensionCompatibilityInput($this->dir);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->dir);
});

it('runs the unchanged plugin and theme plus the replacement consumer on the candidate', function (): void {
    $report = runExtensionCompatibilityFixture($this->dir, $this->published, $this->candidate);
    expect($report->getExitCode())->toBe(0)
        ->and(json_decode($report->getOutput(), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['published' => '1.2.0', 'candidate' => '1.3.0', 'exports' => 4, 'probes' => 3]);
});

it('refuses runtime visibility or invocation regressions while the inventory is unchanged', function (string $from, string $to, string $diagnostic): void {
    file_put_contents($this->dir.'/contract.php', str_replace($from, $to, $this->source));
    $report = runExtensionCompatibilityFixture($this->dir, $this->published, $this->candidate);
    expect($report->getExitCode())->toBe(1)->and($report->getErrorOutput())->toContain($diagnostic);
})->with([
    ['public function title', 'public function removed', 'does not expose'],
    ['public function title', 'protected function title', 'does not expose'],
    ['public function title', '/** @internal */ public function title', 'does not expose'],
    ['public function title(string $locale)', 'public function title(string $language)', 'Failed php'],
    ['protected function line(string $locale)', 'protected function line(string $locale, int $scale = 1)', 'Failed php'],
    ["'children' => ['archive']", "'children' => ['renamed']", 'Failed theme'],
    ["'heading' =>", "'title' =>", 'Failed theme'],
]);

it('refuses forgetting a deprecated export or changing its retained consumer', function (): void {
    $published = $this->candidate;
    $next = $published;
    $next['release'] = '1.4.0';
    array_shift($next['exports']);
    $report = runExtensionCompatibilityFixture($this->dir, $published, $next);
    expect($report->getExitCode())->toBe(1)->and($report->getErrorOutput())->toContain('Same-major removal');

    $next = $published;
    $next['release'] = '1.4.0';
    $next['exports'][0]['probe_sha256'] = str_repeat('1', 64);
    $report = runExtensionCompatibilityFixture($this->dir, $published, $next);
    expect($report->getExitCode())->toBe(1)->and($report->getErrorOutput())->toContain('replacement of the retained probe');

    // Rehearse an edit to a retained fixture without touching either inventory.
    file_put_contents($this->dir.'/published/php.php', "<?php echo \"compatible\\n\";\n");
    $report = runExtensionCompatibilityFixture($this->dir, $this->published, $this->candidate);
    expect($report->getExitCode())->toBe(1)->and($report->getErrorOutput())->toContain('Missing or changed published consumer probe php');
});

it('refuses a candidate that cannot carry the retained consumers forward', function (bool $omit): void {
    $path = $this->dir.'/candidate/theme.php';
    if ($omit) {
        unlink($path);
    } else {
        file_put_contents($path, '<?php echo "compatible\n";');
    }

    $report = runExtensionCompatibilityFixture($this->dir, $this->published, $this->candidate);
    expect($report->getExitCode())->toBe(1)
        ->and($report->getErrorOutput())->toContain('Missing or changed candidate consumer probe theme.');
})->with([true, false]);

it('allows a new major to retire an old export and requires its own consumer', function (): void {
    file_put_contents($this->dir.'/contract.php', str_replace('public function title', 'private function previousTitle', $this->source));
    $new = ['release' => '2.0.0', 'exports' => [$this->candidate['exports'][3]]];
    unlink($this->dir.'/candidate/php.php');
    unlink($this->dir.'/candidate/theme.php');
    $report = runExtensionCompatibilityFixture($this->dir, $this->candidate, $new);
    expect($report->getExitCode())->toBe(0)->and($report->getOutput())->toContain('"probes":1');
});

it('refuses an incomplete deprecation or an edit to an already published inventory', function (): void {
    $new = $this->candidate;
    unset($new['exports'][0]['migration_guide']);
    $report = runExtensionCompatibilityFixture($this->dir, $this->published, $new);
    expect($report->getExitCode())->toBe(1)->and($report->getErrorOutput())->toContain('migration guide');

    $new = $this->published;
    $new['exports'][0]['probe_sha256'] = str_repeat('1', 64);
    $report = runExtensionCompatibilityFixture($this->dir, $this->published, $new);
    expect($report->getExitCode())->toBe(1)->and($report->getErrorOutput())->toContain('A published release cannot identify');
});

it('refuses use before the stable extension freeze', function (): void {
    $published = array_replace($this->published, ['release' => '1.1.9']);
    $report = runExtensionCompatibilityFixture($this->dir, $published, $this->candidate);
    expect($report->getExitCode())->toBe(1)->and($report->getErrorOutput())->toContain('from v1.2.0 onward');
});
