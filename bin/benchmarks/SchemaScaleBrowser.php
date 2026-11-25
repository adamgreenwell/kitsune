<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

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
$app->handleRequest(Request::capture());
