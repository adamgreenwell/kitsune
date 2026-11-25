<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use RuntimeException;
use Throwable;

/** Serve the staged application on private Unix sockets while the public service units are masked. */
final class SelfHostNativeHealth
{
    public static function verify(string $version, int $user, int $group): void
    {
        $token = bin2hex(random_bytes(8));
        $root = '/run/kitsune-probe-'.$token;
        $sockets = $root.'/sockets';
        $httpSocket = $root.'/control/http.sock';
        if (! mkdir($root, 0700)) {
            throw new RuntimeException('The private native probe directory could not be created.');
        }

        $units = [];
        $failure = null;
        try {
            if (! chgrp($root, $group) || ! chmod($root, 0750) || ! mkdir($sockets, 0700)
                || ! chown($sockets, $user) || ! chgrp($sockets, $group) || ! mkdir(dirname($httpSocket), 0700)) {
                throw new RuntimeException('The private native probe sockets could not be prepared.');
            }
            self::write($root.'/fpm.conf', $group, <<<CONF
                [global]
                error_log = $sockets/fpm.log
                [probe]
                listen = $sockets/php.sock
                listen.mode = 0600
                pm = static
                pm.max_children = 1
                clear_env = yes
                CONF);
            $application = SelfHostNativeHost::INSTALLATION.'/app/public';
            self::write($root.'/nginx.conf', $group, <<<CONF
                daemon off;
                user www-data;
                pid $sockets/nginx.pid;
                error_log stderr;
                events {}
                http {
                    access_log off;
                    client_body_temp_path $sockets/body;
                    proxy_temp_path $sockets/proxy;
                    fastcgi_temp_path $sockets/fastcgi;
                    uwsgi_temp_path $sockets/uwsgi;
                    scgi_temp_path $sockets/scgi;
                    server {
                        listen unix:$httpSocket;
                        root $application;
                        location / {
                            include /etc/nginx/fastcgi_params;
                            fastcgi_param SCRIPT_FILENAME $application/index.php;
                            fastcgi_pass unix:$sockets/php.sock;
                        }
                    }
                }
                CONF);
            foreach ([
                'fpm' => ['--uid=www-data', '--gid=www-data', "/usr/sbin/php-fpm$version", '-F', '-y', $root.'/fpm.conf'],
                'nginx' => ['/usr/sbin/nginx', '-p', $sockets.'/', '-c', $root.'/nginx.conf'],
            ] as $name => $command) {
                $unit = 'kitsune-probe-'.$token.'-'.$name.'.service';
                $units[] = $unit;
                [$status] = self::command(['/usr/bin/systemd-run', '--quiet', '--collect', '--unit='.$unit,
                    '--service-type=exec', '--property=RuntimeMaxSec=45s', '--property=TimeoutStopSec=3s',
                    '--property=KillMode=control-group', ...$command]);
                if ($status !== 0) {
                    throw new RuntimeException("The private native $name probe could not start.");
                }
            }

            foreach (['/up', '/admin/login'] as $route) {
                for ($attempt = 1; $attempt <= 8; $attempt++) {
                    [$status, $http] = self::command(['/usr/bin/curl', '--silent', '--max-time', '2', '--noproxy', '*',
                        '--unix-socket', $httpSocket, '-o', '/dev/null', '-w', '%{http_code}', 'http://localhost:8080'.$route]);
                    if ($status === 0 && $http === '200') {
                        continue 2;
                    }
                    usleep(250000);
                }
                throw new RuntimeException("The staged native release did not return HTTP 200 for $route.");
            }
        } catch (Throwable $error) {
            $failure = $error;
        }

        $stopped = true;
        foreach (array_reverse($units) as $unit) {
            try {
                self::command(['/usr/bin/systemctl', '--system', 'stop', $unit]);
                [$query, $active] = self::command(['/usr/bin/systemctl', '--system', 'is-active', $unit]);
                $ended = in_array($query, [3, 4], true) && in_array(trim($active), ['inactive', 'failed', 'unknown'], true);
                $stopped = $ended && $stopped;
            } catch (Throwable) {
                $stopped = false;
            }
        }
        if (! $stopped) {
            throw new RuntimeException("A private native probe did not stop. Inspect the transient units before removing $root.", previous: $failure);
        }
        try {
            self::removeProbe($root, $sockets, $httpSocket);
        } catch (Throwable $cleanup) {
            throw new RuntimeException($cleanup->getMessage(), previous: $failure ?? $cleanup);
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    private static function removeProbe(string $root, string $sockets, string $httpSocket): void
    {
        // Never descend into a directory that the application runtime could have populated.
        foreach ([$httpSocket, $root.'/nginx.conf', $root.'/fpm.conf', $sockets.'/php.sock', $sockets.'/nginx.pid', $sockets.'/fpm.log'] as $file) {
            if (@lstat($file) !== false && ! @unlink($file)) {
                throw new RuntimeException("The private native probe files could not be removed from $root.");
            }
        }
        foreach (['body', 'proxy', 'fastcgi', 'uwsgi', 'scgi'] as $directory) {
            if (@lstat($sockets.'/'.$directory) !== false && ! @rmdir($sockets.'/'.$directory)) {
                throw new RuntimeException("The private native probe directory has unexpected contents at $root.");
            }
        }
        foreach ([$sockets, dirname($httpSocket), $root] as $directory) {
            if (@lstat($directory) !== false && ! @rmdir($directory)) {
                throw new RuntimeException("The private native probe directory could not be removed at $root.");
            }
        }
    }

    private static function write(string $path, int $group, string $configuration): void
    {
        if (file_put_contents($path, $configuration."\n") === false || ! chgrp($path, $group) || ! chmod($path, 0640)) {
            throw new RuntimeException('The private native probe configuration could not be written.');
        }
    }

    /** @param list<string> $arguments
     * @return array{int,string}
     */
    private static function command(array $arguments): array
    {
        $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
            $pipes, env_vars: ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']);
        if ($process === false) {
            throw new RuntimeException('The private native probe command could not be run.');
        }
        $result = stream_get_contents($pipes[1], 4096);
        fclose($pipes[1]);

        return [proc_close($process), $result === false ? '' : $result];
    }
}
