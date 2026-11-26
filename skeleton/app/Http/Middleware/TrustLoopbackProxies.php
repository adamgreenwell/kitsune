<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

final class TrustLoopbackProxies extends TrustProxies
{
    public const PEERS = ['127.0.0.1', '::1'];

    protected function setTrustedProxyIpAddresses(Request $request): void
    {
        // Symfony can match IPv4-mapped IPv6 peers against IPv4 addresses.
        if (in_array($request->server->get('REMOTE_ADDR'), self::PEERS, true)) {
            parent::setTrustedProxyIpAddresses($request);
        }
    }
}
