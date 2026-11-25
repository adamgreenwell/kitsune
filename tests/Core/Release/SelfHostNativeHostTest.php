<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Release\SelfHostNativeHost;

require_once dirname(__DIR__, 3).'/bin/release/SelfHostNativeHost.php';

it('requires mutually consistent systemd unit status before native maintenance', function (): void {
    $maskedAndStopped = "MainPID=0\nControlPID=0\nLoadState=masked\nActiveState=inactive\nSubState=dead\n";
    expect(fn () => SelfHostNativeHost::requireStoppedService($maskedAndStopped))->not->toThrow(RuntimeException::class);
    foreach ([
        str_replace('LoadState=masked', 'LoadState=loaded', $maskedAndStopped),
        str_replace('ActiveState=inactive', 'ActiveState=active', $maskedAndStopped),
        str_replace('MainPID=0', 'MainPID=44', $maskedAndStopped),
        str_replace('ControlPID=0', 'ControlPID=45', $maskedAndStopped),
        str_replace('SubState=dead', 'SubState=stop-sigterm', $maskedAndStopped),
        $maskedAndStopped."ActiveState=active\n",
    ] as $status) {
        expect(fn () => SelfHostNativeHost::requireStoppedService($status))->toThrow(RuntimeException::class, 'must be masked, inactive');
    }
});

it('requires both loopback listeners to belong to the installed Nginx master', function (): void {
    $ipv4 = 'LISTEN 0 511 127.0.0.1:8080 0.0.0.0:* users:(("nginx",pid=134,fd=5),("nginx",pid=123,fd=5))';
    $ipv6 = 'LISTEN 0 511 [::1]:8080 [::]:* users:(("nginx",pid=123,fd=6))';
    expect(fn () => SelfHostNativeHost::requireOwnedListeners("$ipv6\n$ipv4\n", '123'))->not->toThrow(RuntimeException::class)
        ->and(fn () => SelfHostNativeHost::requireOwnedListeners("$ipv4\n$ipv6", '12'))->toThrow(RuntimeException::class, 'not owned')
        ->and(fn () => SelfHostNativeHost::requireOwnedListeners($ipv4, '123'))->toThrow(RuntimeException::class, 'both native loopback')
        ->and(fn () => SelfHostNativeHost::requireOwnedListeners(str_replace('127.0.0.1', '0.0.0.0', "$ipv4\n$ipv6"), '123'))
        ->toThrow(RuntimeException::class, 'both native loopback');
});

it('keeps the native installer and upgrade host on the same installer lock', function (): void {
    $installer = file_get_contents(dirname(__DIR__, 3).'/deploy/self-host/native/install.sh');
    $lock = (new ReflectionClass(SelfHostNativeHost::class))->getConstant('LOCK');
    expect($installer)->toContain('local lock='.$lock)
        ->toContain('0:600:1')
        ->toContain('flock -n 9');
});
