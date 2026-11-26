<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Kitsune\Benchmarks\SchemaScaleExperiment;
use Kitsune\Core\Http\Middleware\IdentifyEntryType;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 3).'/bin/benchmarks/SchemaScaleExperiment.php';

it('refuses a different scale before creating or migrating anything', function (): void {
    expect(fn () => new SchemaScaleExperiment(dirname(__DIR__, 3), '/not-an-owned-experiment', 9999))
        ->toThrow(InvalidArgumentException::class);
});

it('makes each org own its benchmark type and lets the actual URL middleware resolve that same type', function (): void {
    $context = app(Context::class);
    $planned = [];
    foreach (['alpha', 'beta'] as $handle) {
        $org = Org::create(['slug' => 'scale-types-'.$handle, 'name' => $handle]);
        $context->forget()->setOrg($org);
        $site = Site::create(['handle' => $handle, 'slug' => 'scale-types-'.$handle, 'name' => $handle]);
        $types = SchemaScaleExperiment::entryTypes($org);
        expect($types['article']->org_id)->toBe($org->id)->and($types['asset']->org_id)->toBe($org->id);
        $planned[] = [$org, $site, $types['article']];
    }
    foreach ($planned as [$org, $site, $type]) {
        $context->forget()->setOrg($org)->setSite($site);
        $request = Request::create('/probe/article');
        $route = (new Route('GET', 'probe/{type}', fn () => new Response))->bind($request);
        $request->setRouteResolver(fn () => $route);
        (new IdentifyEntryType)->handle($request, fn () => new Response);
        expect(app(EntryType::class)->id)->toBe($type->id)->and(app(EntryType::class)->org_id)->toBe($org->id);
    }
    expect(EntryType::query()->whereNull('org_id')->whereIn('handle', ['article', 'asset'])->count())->toBe(0);
});

it('refuses an ambient host database and storage even when a new output has an ownership marker', function (): void {
    $requested = sys_get_temp_dir().'/kitsune-scale-guard-'.bin2hex(random_bytes(8));
    mkdir($requested, 0700);
    $out = realpath($requested);
    $root = dirname(__DIR__, 3);
    touch($out.'/scale.sqlite');
    file_put_contents($out.'/owned-experiment.json', json_encode(['purpose' => 'kitsune-schema-scale', 'root' => $root, 'rows' => 10_000]));
    $before = DB::table('orgs')->count();
    try {
        expect(fn () => (new SchemaScaleExperiment($root, $out, 10_000))->run())
            ->toThrow(RuntimeException::class, 'The running host must use only the owned database and storage.');
        expect(DB::table('orgs')->count())->toBe($before)
            ->and(filesize($out.'/scale.sqlite'))->toBe(0);
    } finally {
        File::deleteDirectory($out);
    }
});

it('requires a private native fixture before loading the supplied application', function (): void {
    $temporary = sys_get_temp_dir().'/kitsune-scale-native-launch-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    $temporary = realpath($temporary);
    $application = $temporary.'/application';
    $out = $temporary.'/experiment';
    mkdir($application.'/vendor', 0700, true);
    mkdir($application.'/bootstrap', 0700, true);
    mkdir($out, 0700);

    file_put_contents($application.'/vendor/autoload.php', '<?php exit(22);');
    touch($application.'/bootstrap/app.php');
    touch($application.'/composer.lock');
    file_put_contents($out.'/.env', 'DB_CONNECTION=mysql');
    chmod($out.'/.env', 0600);
    file_put_contents($out.'/owned-experiment.json', '{}');
    chmod($out.'/owned-experiment.json', 0644);

    try {
        $process = new Process(['env', '-i', 'PATH='.getenv('PATH'), PHP_BINARY,
            dirname(__DIR__, 3).'/bin/benchmark-schema-scale.php', '--native', $application, '10000', $out]);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Use the private files prepared by the native launcher.')
            ->and(is_file($out.'/scale.sqlite'))->toBeFalse();
    } finally {
        File::deleteDirectory($temporary);
    }
});

it('refuses to replay benchmark :dataset plans once panel writes have started', function (string $stage): void {
    $temporary = sys_get_temp_dir().'/kitsune-scale-read-replay-'.bin2hex(random_bytes(8));
    mkdir($temporary, 0700);
    $fixture = realpath($temporary);
    mkdir($fixture.'/application/vendor', 0700, true);
    file_put_contents($fixture.'/application/vendor/autoload.php', '<?php exit(22);');
    touch($fixture.'/panel-writes.started');

    try {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/bin/benchmarks/schema-scale-'.$stage.'-plans.php',
            $fixture.'/application', $fixture]);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain('plans refused: RuntimeException');
    } finally {
        File::deleteDirectory($fixture);
    }
})->with(['read', 'picker']);
