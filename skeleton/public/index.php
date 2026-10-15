<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use Illuminate\Foundation\Application;
use Kitsune\Core\Http\BoundedWebhookRequest;
use Symfony\Component\HttpFoundation\Response;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Before Laravel capture: the signed commerce leaf must not eagerly decode an unbounded JSON body.
// The reserved suffix includes root, nested Site prefixes and a front-controller mount. It is bounded even without
// a signature header; unrelated host routes and provider callback paths retain ordinary request capture.
$request = BoundedWebhookRequest::capture('/cart/stripe/webhook', 'Stripe-Signature');
if ($request instanceof Response) {
    $request->send();

    exit;
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest($request);
