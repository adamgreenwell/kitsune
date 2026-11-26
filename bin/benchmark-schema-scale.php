<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Kitsune\Benchmarks\SchemaScaleDatabase;
use Kitsune\Benchmarks\SchemaScaleExperiment;

// Run the in-process workload used to prepare the browser benchmark.
try {
    $native = ($argv[1] ?? null) === '--native';
    if ($native) {
        array_splice($argv, 1, 1);
    }
    if (count($argv) !== 4 || ! in_array($argv[2], ['10000', '100000', '1000000'], true)) {
        throw new InvalidArgumentException('Use: php bin/benchmark-schema-scale.php [--native] APPLICATION 10000|100000|1000000 ABSOLUTE_NEW_OUTPUT');
    }

    $root = dirname(__DIR__);
    $application = realpath($argv[1]);
    $rows = (int) $argv[2];
    $out = $argv[3];
    if ($application === false || ! is_file($application.'/vendor/autoload.php') || ! is_file($application.'/bootstrap/app.php')
        || ! is_file($application.'/composer.lock') || is_file($application.'/bootstrap/cache/config.php')) {
        throw new InvalidArgumentException('Use a built application with its recorded Composer lock and no cached configuration.');
    }
    if (! preg_match('~\A/[A-Za-z0-9/._-]+\z~D', $out) || realpath(dirname($out)) !== dirname($out)
        || str_starts_with($out.'/', $root.'/') || str_starts_with($out.'/', $application.'/') || is_link($out)
        || ($native ? realpath($out) !== $out || ! is_dir($out) : file_exists($out))) {
        throw new InvalidArgumentException('Use a canonical output outside the source and application. Native mode requires a prepared fixture.');
    }
    foreach (array_keys(getenv() + $_ENV + $_SERVER) as $name) {
        if (preg_match('/^(DB_|APP_|KITSUNE_|LARAVEL_|SESSION_|CACHE_|QUEUE_|FILESYSTEM_)/', (string) $name)) {
            throw new InvalidArgumentException('Run from an environment without application overrides, such as env -i PATH="$PATH".');
        }
    }

    umask(0077);
    if ($native) {
        foreach (['.env', 'owned-experiment.json'] as $name) {
            $file = $out.'/'.$name;
            if (is_link($file) || ! is_file($file) || (fileperms($file) & 0077) !== 0) {
                throw new InvalidArgumentException('Use the private files prepared by the native launcher.');
            }
        }
    } else {
        if (! mkdir($out, 0700) || realpath($out) !== $out) {
            throw new RuntimeException('The owned output directory could not be created.');
        }
        foreach ([
            'app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs',
        ] as $subdirectory) {
            if (! mkdir($out.'/storage/'.$subdirectory, 0700, true)) {
                throw new RuntimeException('The private application storage could not be created.');
            }
        }

        touch($out.'/scale.sqlite');
        file_put_contents($out.'/owned-experiment.json', json_encode([
            'purpose' => 'kitsune-schema-scale', 'root' => $root, 'rows' => $rows,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($out.'/.env', implode("\n", [
            'APP_ENV=local', 'APP_DEBUG=false', 'APP_KEY=base64:'.base64_encode(random_bytes(32)), 'APP_URL=http://localhost',
            'DB_CONNECTION=sqlite', 'DB_DATABASE="'.$out.'/scale.sqlite"', 'DB_FOREIGN_KEYS=true',
            'CACHE_STORE=array', 'SESSION_DRIVER=array', 'QUEUE_CONNECTION=sync', 'MAIL_MAILER=log',
            'APP_PACKAGES_CACHE="'.$out.'/packages.php"', 'APP_SERVICES_CACHE="'.$out.'/services.php"',
            '',
        ]));
    }

    require $application.'/vendor/autoload.php';
    require_once __DIR__.'/benchmarks/SchemaScaleExperiment.php';

    $app = require $application.'/bootstrap/app.php';
    $app->useEnvironmentPath($out);
    $app->useStoragePath($out.'/storage');
    $app->make(Kernel::class)->bootstrap();

    if ($native) {
        $marker = json_decode(file_get_contents($out.'/owned-experiment.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($marker)) {
            throw new InvalidArgumentException('Use an owned native fixture marker.');
        }
        SchemaScaleDatabase::nativeConfiguration($marker, DB::connection()->getConfig(), $root, $rows);
    }

    $result = (new SchemaScaleExperiment($root, $out, $rows))->run();
    echo json_encode([
        'complete' => true,
        'rows' => $rows,
        'checks_passed' => $result['checks_passed'],
        'php' => PHP_VERSION,
        'composer_lock_sha256' => hash_file('sha256', $application.'/composer.lock'),
        'result' => $out.'/result.json',
    ], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    // Provider and database exceptions may echo values from the private runtime.
    $reason = $error instanceof InvalidArgumentException ? $error->getMessage() : $error::class;
    fwrite(STDERR, 'Schema benchmark refused: '.$reason."\n");
    exit(1);
}
