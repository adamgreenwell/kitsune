<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use Dotenv\Dotenv;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** The dedicated native Ubuntu installation's maintenance and process boundaries. */
final class SelfHostNativeHost
{
    public const INSTALLATION = '/opt/kitsune';

    public const STATE = '/var/lib/kitsune';

    public const JOURNAL = '/var/lib/kitsune-upgrades/journal';

    private const BACKUPS = '/var/lib/kitsune-upgrades/backups';

    private const LOCK = '/run/lock/kitsune-native-install.lock';

    private const SYSTEMD_UNITS = '/etc/systemd/system';

    public static function migrate(
        SelfHostUpgradeJournal $journal,
        SelfHostUpgradeAdmission $admission,
        string $checkout,
        string $staging,
        string $backup,
    ): void {
        self::requireBackupPath($backup);
        if (@lstat($backup) !== false) {
            throw new InvalidArgumentException('Use a fresh native backup path under '.self::BACKUPS.'.');
        }
        $host = self::host($admission);
        $php = $host['php'];
        foreach ([$checkout, $checkout.'/vendor'] as $directory) {
            self::requireOwnedDirectoryChain($directory);
        }
        self::requireOwnedPath(self::INSTALLATION, 0040000, 0022);
        self::requireOwnedPath($staging, 0040000, 0022);
        self::requireOwnedPath($checkout.'/vendor/autoload.php', 0100000, 0022);
        require_once $checkout.'/vendor/autoload.php';

        $planModules = static fn (string $database): array => SelfHostModuleUpgrade::plan(
            $database, $admission->origin, $admission->target, self::INSTALLATION.'/app', $staging.'/app',
        );

        SelfHostNativeUpgrade::migrate($journal, $admission, self::LOCK, self::INSTALLATION, $staging, self::STATE, $backup,
            static function () use ($host): void {
                try {
                    $settings = Dotenv::parse(Files::control(self::STATE.'/.env', 262144));
                } catch (Throwable) {
                    throw new RuntimeException('The installed native configuration could not be validated.');
                }
                if (($settings['QUEUE_CONNECTION'] ?? null) !== 'sync') {
                    throw new RuntimeException('Stop and qualify externally managed writers before native migration.');
                }
                self::requireFence($host['php'], $host['uid']);
            },
            static fn () => throw new RuntimeException('The native migration was not prepared from its verified backup.'),
            static function () use ($staging, $php, $planModules): void {
                $planModules(self::STATE.'/database/database.sqlite');
                $cache = $staging.'/app/bootstrap/cache';
                if (realpath($cache) !== $cache || ! is_dir($cache) || ! chown($cache, 'www-data')
                    || ! chgrp($cache, 'www-data') || ! chmod($cache, 0700)) {
                    throw new RuntimeException('The staged native boot cache could not be assigned to the runtime user.');
                }

                self::runAsRuntime($php, $staging.'/app', [$staging.'/app/vendor/composer/platform_check.php'],
                    'The staged release could not load under the installed PHP and runtime account.');
            },
            static function () use ($backup, $planModules, $php, $staging): \Closure {
                $modulePlan = $planModules($backup.'/database.sqlite');

                return static function () use ($php, $staging, $modulePlan): void {
                    self::runAsRuntime($php, $staging.'/app', ['artisan', 'migrate', '--force', '--no-interaction'],
                        'The admitted native migration failed. Keep services fenced and inspect the journal.');
                    foreach ($modulePlan['upgrades'] as $module) {
                        self::runAsRuntime($php, $staging.'/app', ['artisan', 'kitsune:module', 'upgrade', $module, '--no-interaction'],
                            "The admitted module $module did not upgrade. Keep services fenced and inspect the journal.");
                    }
                    SelfHostModuleUpgrade::verify(self::STATE.'/database/database.sqlite', $modulePlan['expected']);
                };
            });
    }

    public static function stage(SelfHostUpgradeJournal $journal, SelfHostUpgradeAdmission $admission, string $staging): void
    {
        $host = self::host($admission);
        self::requireExistingCodeRoots(self::INSTALLATION, $staging, self::INSTALLATION.'.before-'.$admission->plan);
        SelfHostNativeUpgrade::stage($journal, $admission, self::LOCK, self::INSTALLATION, $staging, self::STATE,
            static fn () => self::requireFence($host['php'], $host['uid']));
    }

    public static function confirmPrivate(SelfHostUpgradeJournal $journal, SelfHostUpgradeAdmission $admission): void
    {
        $host = self::host($admission);
        self::requireExistingCodeRoots(self::INSTALLATION);
        SelfHostNativeUpgrade::confirmPrivateService($journal, $admission, self::INSTALLATION, self::STATE,
            static function () use ($host): void {
                self::requireFence($host['php'], $host['uid']);
                SelfHostNativeHealth::verify($host['php'], $host['uid'], $host['gid']);
                self::requireFence($host['php'], $host['uid']);
            }, self::LOCK);
    }

    public static function publish(SelfHostUpgradeJournal $journal, SelfHostUpgradeAdmission $admission): void
    {
        $host = self::host($admission);
        self::requireExistingCodeRoots(self::INSTALLATION);
        $php = $host['php'];
        SelfHostNativeUpgrade::publishTraffic($journal, $admission, self::LOCK, self::INSTALLATION, self::STATE,
            static function () use ($journal, $host, $php): void {
                if ($journal->state()['phase'] === SelfHostUpgradeJournal::ACTIVATED) {
                    self::requireFence($php, $host['uid']);
                }
            },
            static function () use ($php): void {
                self::runSystemctl('unmask', "php$php-fpm.service", 'nginx.service');
                self::runSystemctl('start', "php$php-fpm.service");
                self::runSystemctl('start', 'nginx.service');
            },
            static fn () => self::verifyInstalledService($php));
    }

    private static function verifyInstalledService(string $php): void
    {
        foreach (["php$php-fpm.service", 'nginx.service'] as $unit) {
            self::runSystemctl('is-active', '--quiet', $unit);
        }
        $managed = '/etc/nginx/conf.d/kitsune.conf';
        $template = Files::control(dirname(__DIR__, 2).'/deploy/self-host/native/nginx.conf', 16384);
        $configuration = str_replace('@PHP_VERSION@', $php, $template);
        $expanded = self::readHostCommand(['/usr/sbin/nginx', '-T'], 524288);
        $directives = preg_replace('/#[^\n]*/', '', $expanded) ?? '';
        if (Files::control($managed, 16384) !== $configuration
            || ! str_contains($expanded, "# configuration file $managed:\n".$configuration)
            || preg_match_all('/(?:^|[\s;{}])server\s*\{/', $directives) !== 1) {
            throw new RuntimeException('The installed Nginx configuration does not exclusively serve the managed native application.');
        }

        $pid = trim(self::readHostCommand(['/usr/bin/systemctl', '--system', 'show', '--value', '--property=MainPID', 'nginx.service'], 64));
        $listeners = self::readHostCommand(['/usr/bin/ss', '-H', '-ltnp', '( sport = :8080 )'], 16384);
        if (preg_match('/\A[1-9][0-9]*\z/D', $pid) !== 1) {
            throw new RuntimeException('The installed Nginx master process could not be identified.');
        }
        self::requireOwnedListeners($listeners, $pid);

        foreach (['/up', '/admin/login'] as $route) {
            $process = proc_open(['/usr/bin/curl', '--silent', '--show-error', '--max-time', '5', '--noproxy', '*',
                '-o', '/dev/null', '-w', '%{http_code}', 'http://127.0.0.1:8080'.$route],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
                $pipes, env_vars: ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']);
            if ($process === false) {
                throw new RuntimeException('The installed native listener could not be checked.');
            }
            $response = stream_get_contents($pipes[1], 16);
            fclose($pipes[1]);
            if (proc_close($process) !== 0 || $response !== '200') {
                throw new RuntimeException("The installed native listener did not return HTTP 200 for $route.");
            }
        }
    }

    /** @internal */
    public static function requireOwnedListeners(string $listeners, string $pid): void
    {
        $addresses = [];
        foreach (explode("\n", trim($listeners)) as $line) {
            $fields = preg_split('/\s+/', trim($line), 6);
            if ($fields === false || count($fields) !== 6 || $fields[0] !== 'LISTEN'
                || ! str_contains($fields[5], 'pid='.$pid.',')) {
                throw new RuntimeException('The native loopback listener is not owned by the installed Nginx master.');
            }
            $addresses[] = $fields[3];
        }
        sort($addresses, SORT_STRING);
        if ($addresses !== ['127.0.0.1:8080', '[::1]:8080']) {
            throw new RuntimeException('The installed Nginx master must own both native loopback listeners.');
        }
    }

    /** @param list<string> $arguments */
    private static function readHostCommand(array $arguments, int $limit): string
    {
        $process = proc_open($arguments,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, env_vars: ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']);
        if ($process === false) {
            throw new RuntimeException('The installed native service could not be inspected.');
        }
        $output = stream_get_contents($pipes[1], $limit);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || $output === false || strlen($output) === $limit) {
            throw new RuntimeException('The installed native service could not be inspected.');
        }

        return $output;
    }

    private static function runSystemctl(string ...$arguments): void
    {
        $process = proc_open(['/usr/bin/systemctl', '--system', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR],
            $pipes, env_vars: ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']);
        if ($process === false || proc_close($process) !== 0) {
            throw new RuntimeException("Systemd could not complete the native service {$arguments[0]} operation.");
        }
    }

    public static function recover(SelfHostUpgradeJournal $journal, SelfHostUpgradeAdmission $admission, string $backup): void
    {
        self::requireBackupPath($backup);
        $host = self::host($admission);
        self::requireExistingCodeRoots(self::INSTALLATION, self::INSTALLATION.'.before-'.$admission->plan,
            self::INSTALLATION.'.failed-'.$admission->plan);
        SelfHostNativeUpgrade::recover($journal, $admission, self::LOCK, self::INSTALLATION, self::STATE, $backup,
            $host['uid'], $host['gid'], static fn () => self::requireFence($host['php'], $host['uid']));
    }

    private static function requireExistingCodeRoots(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (@lstat($path) !== false) {
                self::requireOwnedPath($path, 0040000, 0022);
            }
        }
    }

    /** @return array{php:string, uid:int, gid:int} */
    private static function host(SelfHostUpgradeAdmission $admission): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0 || ! is_dir('/run/systemd/system')) {
            throw new RuntimeException('Native migration requires root on the dedicated systemd host.');
        }
        $platform = match (php_uname('m')) {
            'x86_64' => 'linux-amd64',
            'aarch64' => 'linux-arm64',
            default => null,
        };
        if ($admission->platform !== $platform) {
            throw new InvalidArgumentException('The signed upgrade platform does not match this host.');
        }
        foreach (['/opt', '/var/lib', dirname(self::JOURNAL), '/etc/nginx/conf.d'] as $directory) {
            self::requireOwnedDirectoryChain($directory);
        }
        foreach ([self::JOURNAL, self::BACKUPS] as $directory) {
            self::requireOwnedPath($directory, 0040000, 0077);
        }
        $nginx = '/etc/nginx/conf.d/kitsune.conf';
        self::requireOwnedPath($nginx, 0100000, 0022);
        $configuration = Files::control($nginx, 16384);
        if (preg_match_all('~^\s*fastcgi_pass unix:/run/php/php(8\.[45])-fpm\.sock;\s*$~m', $configuration, $versions) !== 1
            || ! str_contains($configuration, 'root '.self::INSTALLATION.'/app/public;')) {
            throw new RuntimeException('The managed Nginx configuration does not identify one supported PHP-FPM service.');
        }
        $php = $versions[1][0];
        if (! is_executable("/usr/bin/php$php")) {
            throw new RuntimeException('The PHP executable matching the native FPM service is unavailable.');
        }
        $account = posix_getpwnam('www-data');
        if ($account === false || $account['uid'] === 0) {
            throw new RuntimeException('The dedicated native runtime account is unavailable.');
        }
        self::requireInstallerLock();

        return ['php' => $php, 'uid' => $account['uid'], 'gid' => $account['gid']];
    }

    private static function requireBackupPath(string $backup): void
    {
        if (dirname($backup) !== self::BACKUPS || $backup !== self::BACKUPS.'/'.basename($backup)
            || in_array(basename($backup), ['.', '..'], true)) {
            throw new InvalidArgumentException('Use the recorded native backup path under '.self::BACKUPS.'.');
        }
    }

    private static function requireFence(string $php, int $uid): void
    {
        foreach (['nginx.service', "php$php-fpm.service"] as $unit) {
            $mask = self::SYSTEMD_UNITS.'/'.$unit;
            if (@readlink($mask) !== '/dev/null') {
                throw new RuntimeException("Persistently mask and stop $unit before native upgrade operations.");
            }
            self::requireStoppedService(self::serviceStatus($unit));
        }
        self::requireRuntimeAccountIdle($uid);
    }

    /** @param list<string> $arguments */
    private static function runAsRuntime(string $php, string $application, array $arguments, string $message): void
    {
        $process = proc_open(
            ['/usr/sbin/runuser', '-u', 'www-data', '--', "/usr/bin/php$php", ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR],
            $pipes,
            $application,
            ['PATH' => '/usr/bin:/bin', 'LANG' => 'C'],
        );
        if ($process === false || proc_close($process) !== 0) {
            throw new RuntimeException($message);
        }
    }

    private static function requireRuntimeAccountIdle(int $uid): void
    {
        $statuses = glob('/proc/[0-9]*/status');
        if ($statuses === false || $statuses === []) {
            throw new RuntimeException('The native runtime processes could not be checked.');
        }
        foreach ($statuses as $path) {
            $status = @file_get_contents($path);
            if ($status === false && ! is_dir(dirname($path))) {
                continue;
            }
            if ($status === false || preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)$/m', $status, $match) !== 1) {
                throw new RuntimeException('The native runtime processes could not be checked.');
            }
            if (in_array((string) $uid, array_slice($match, 1), true)) {
                throw new RuntimeException('Stop all processes owned by the native runtime account before migrating.');
            }
        }
    }

    private static function requireOwnedDirectoryChain(string $directory): void
    {
        do {
            self::requireOwnedPath($directory, 0040000, 0022);
            $directory = dirname($directory);
        } while ($directory !== '/');
    }

    /** @internal */
    public static function requireStoppedService(string $status): void
    {
        $expected = ['ActiveState=inactive', 'ControlPID=0', 'LoadState=masked', 'MainPID=0', 'SubState=dead'];
        $properties = explode("\n", trim($status));
        sort($properties, SORT_STRING);
        if ($properties !== $expected) {
            throw new RuntimeException('The native service must be masked, inactive and have no main or control process.');
        }
    }

    private static function serviceStatus(string $unit): string
    {
        $process = proc_open(['/usr/bin/systemctl', '--system', '--no-pager', 'show',
            '--property=ActiveState,ControlPID,LoadState,MainPID,SubState', $unit],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, env_vars: ['PATH' => '/usr/bin:/bin', 'LANG' => 'C']);
        if ($process === false) {
            throw new RuntimeException('The native service state could not be read.');
        }
        $status = stream_get_contents($pipes[1], 4096);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || $status === false || strlen($status) === 4096) {
            throw new RuntimeException('The native service state could not be read.');
        }

        return $status;
    }

    private static function requireInstallerLock(): void
    {
        if (@lstat(self::LOCK) === false) {
            $mask = umask(0077);
            try {
                $lock = @fopen(self::LOCK, 'x+be');
                if ($lock !== false) {
                    fclose($lock);
                }
            } finally {
                umask($mask);
            }
        }

        self::requireOwnedPath(self::LOCK, 0100000, 0077);
    }

    private static function requireOwnedPath(string $path, int $type, int $disallowedModes): void
    {
        clearstatcache(true, $path);
        $info = @lstat($path);
        if (realpath($path) !== $path || $info === false || $info['uid'] !== 0
            || ($info['mode'] & 0170000) !== $type || ($info['mode'] & $disallowedModes) !== 0
            || ($type === 0100000 && $info['nlink'] !== 1)) {
            throw new RuntimeException('Use the canonical root-owned host path with its installed permissions.');
        }
    }
}
