<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Kitsune\Core\Filament\Resources\Entries\Pages\EditEntry;

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
$readTag = $request->headers->get('X-Kitsune-Scale-Read');
$actionTag = $request->headers->get('X-Kitsune-Scale-Action');
$refererPath = parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);
$captureRead = getenv('KITSUNE_SCALE_CAPTURE_READ_SQL') === '1'
    && $request->isMethod('GET') && $request->headers->get('Sec-Fetch-Dest') === 'document'
    && is_string($readTag) && preg_match('/\Ap[0-9]{5}\z/D', $readTag) === 1
    && preg_match('~\A/admin/scale-site-[1-5](?:/|\z)~D', $request->getPathInfo()) === 1;
$captureAction = getenv('KITSUNE_SCALE_CAPTURE_ACTION_SQL') === '1' && $request->isMethod('POST')
    && $request->headers->has('X-Livewire') && is_file($fixture.'/panel-writes.started')
    && is_string($actionTag) && preg_match('/\Aa[0-9]{5}\z/D', $actionTag) === 1
    && preg_match('~\A/livewire(?:-[a-f0-9]{8})?/update\z~D', $request->getPathInfo()) === 1
    && is_string($refererPath) && preg_match('~\A/admin/scale-site-1/c/article/(?:1|3|11)/edit\z~D', $refererPath) === 1;

$pickerTag = $request->headers->get('X-Kitsune-Scale-Picker');
$capturePicker = getenv('KITSUNE_SCALE_CAPTURE_PICKER_SQL') === '1' && $request->isMethod('POST')
    && $request->headers->has('X-Livewire') && ! file_exists($fixture.'/panel-writes.started')
    && is_string($pickerTag) && preg_match('/\Ak[0-9]{5}\z/D', $pickerTag) === 1
    && preg_match('~\A/livewire(?:-[a-f0-9]{8})?/update\z~D', $request->getPathInfo()) === 1
    && is_string($refererPath) && preg_match('~\A/admin/scale-site-[123]/c/article/[1-9][0-9]*/edit\z~D', $refererPath) === 1;

$component = null;
if ($captureAction || $capturePicker) {
    $components = $request->json('components');
    $update = is_array($components) && count($components) === 1 ? $components[0] : null;
    $calls = is_array($update) ? ($update['calls'] ?? null) : null;
    $call = is_array($calls) && count($calls) === 1 ? $calls[0] : null;
    $method = $captureAction ? 'save' : 'callSchemaComponentMethod';
    $snapshot = is_string($update['snapshot'] ?? null) ? json_decode($update['snapshot'], true) : null;
    $memo = $snapshot['memo'] ?? null;
    if (($call['method'] ?? null) !== $method || ($capturePicker && ($call['params'][1] ?? null) !== 'getSearchResultsForJs')
        || ($memo['name'] ?? null) !== EditEntry::class
        || ! is_string($memo['path'] ?? null) || '/'.ltrim($memo['path'], '/') !== $refererPath) {
        http_response_code(422);

        return;
    }
    $component = ['name' => $memo['name'], 'method' => $method];
}

if ($captureRead || $captureAction || $capturePicker) {
    [$tag, $directory] = match (true) {
        $captureRead => [$readTag, 'read-sql'],
        $captureAction => [$actionTag, 'action-sql'],
        $capturePicker => [$pickerTag, 'picker-sql'],
    };
    $folder = $fixture.'/'.$directory;
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
            ...($component === null ? [] : ['component' => $component]),
            'path' => $captureRead ? $request->getPathInfo() : $refererPath,
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
