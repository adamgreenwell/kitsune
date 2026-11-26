<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;

if (PHP_SAPI !== 'cli-server') {
    exit(1);
}

$application = getenv('KITSUNE_SCALE_APPLICATION');
$fixture = getenv('KITSUNE_SCALE_FIXTURE');
if ($application === false || $fixture === false) {
    http_response_code(500);

    return;
}

$public = $application.'/public';
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = is_string($requestPath) ? realpath($public.rawurldecode($requestPath)) : false;
if ($file !== false && str_starts_with($file, $public.'/') && is_file($file)) {
    return false;
}

require $application.'/vendor/autoload.php';
$app = require $application.'/bootstrap/app.php';
$app->useEnvironmentPath($fixture);
$app->useStoragePath($fixture.'/storage');
$request = Request::capture();
$tag = $request->headers->get('X-Kitsune-Scale-Read');
if (getenv('KITSUNE_SCALE_CAPTURE_READ_SQL') === '1' && $request->isMethod('GET') && $request->headers->get('Sec-Fetch-Dest') === 'document'
    && is_string($tag) && preg_match('/\Ap[0-9]{5}\z/D', $tag) === 1
    && preg_match('~\A/admin/scale-site-[1-5](?:/|\z)~D', $request->getPathInfo()) === 1) {
    $folder = $fixture.'/read-sql';
    if (is_link($folder) || ! is_dir($folder) || realpath($folder) !== $folder
        || (fileperms($folder) & 0077) !== 0) {
        http_response_code(500);

        return;
    }

    $queries = [];
    $overflow = false;
    $app->booted(function () use ($app, &$queries, &$overflow): void {
        $manager = $app->make('db');
        $name = $manager->getDefaultConnection();
        $manager->listen(static function (QueryExecuted $query) use ($name, &$queries, &$overflow): void {
            if ($query->connectionName !== $name || preg_match('/\A\s*select\b/i', $query->sql) !== 1) {
                return;
            }
            if (count($queries) >= 200) {
                $overflow = true;

                return;
            }

            $queries[] = [
                'sql' => $query->sql,
                'bindings' => $query->connection->prepareBindings($query->bindings),
                'ms' => $query->time,
            ];
        });
    });
    $app->handleRequest($request);
    umask(0077);
    $destination = $folder.'/'.$tag.'.json';
    $temporary = tempnam($folder, '.profile-');
    if ($temporary === false || dirname($temporary) !== $folder) {
        if ($temporary !== false) {
            unlink($temporary);
        }

        throw new RuntimeException('Could not create a private read profile.');
    }

    try {
        $profile = json_encode([
            'version' => 1,
            'status' => http_response_code(),
            'path' => $request->getPathInfo(),
            'tag' => $tag,
            'overflow' => $overflow,
            'selects' => $queries,
        ], JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($temporary, $profile) !== strlen($profile) || ! link($temporary, $destination)) {
            throw new RuntimeException('Could not publish the read profile.');
        }
    } finally {
        unlink($temporary);
    }

    return;
}

$app->handleRequest($request);
