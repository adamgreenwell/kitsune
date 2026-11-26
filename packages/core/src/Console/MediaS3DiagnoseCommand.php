<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\S3BucketChecks;
use Throwable;

final class MediaS3DiagnoseCommand extends Command
{
    protected $signature = 'kitsune:media-s3-diagnose';

    protected $description = 'Check bucket metadata for media disks using the direct S3 profile (read-only)';

    public function handle(Repository $config): int
    {
        $disks = [];
        foreach (['private', 'public'] as $role) {
            $disk = MediaDisks::configured($config, $role);

            try {
                $resolved = MediaDisks::resolved($config, $disk);
            } catch (Throwable) {
                $this->error("The {$role} media disk configuration could not be resolved.");

                return self::FAILURE;
            }

            if ($resolved['driver'] === 's3') {
                $disks[$role] = ['disk' => $disk, ...$resolved];
            }
        }

        if ($disks === []) {
            $this->info('Skipped. Neither configured media disk uses S3 directly.');

            return self::SUCCESS;
        }

        if (! class_exists('League\Flysystem\AwsS3V3\AwsS3V3Adapter') || ! class_exists('Aws\S3\S3Client')) {
            $this->error('The configured S3 disks require the optional league/flysystem-aws-s3-v3 package.');

            return self::FAILURE;
        }

        if (isset($disks['private'], $disks['public']) && $disks['private']['bucket'] === $disks['public']['bucket']) {
            $this->error('The direct S3 profile requires separate private and public buckets.');

            return self::FAILURE;
        }

        $this->line('Bucket metadata checked at '.now()->toIso8601String());
        $passed = true;

        foreach ($disks as $role => $resolved) {
            $disk = $resolved['disk'];
            $bucket = $resolved['bucket'];
            [$base] = MediaDisks::unscoped($config, $config->get("filesystems.disks.{$disk}"), $disk);
            $region = $base['region'] ?? null;

            if ($bucket === null || $bucket === '' || ! is_string($region) || $region === '') {
                $this->error("The {$role} S3 media disk requires an explicit bucket and region.");
                $passed = false;

                continue;
            }

            // The AWS client can emit raw request details independently of output from this command.
            if (! empty($base['debug']) || ! empty($base['http']['debug'])) {
                $this->error("Disable SDK and HTTP debug output on the {$role} S3 media disk.");
                $passed = false;

                continue;
            }

            try {
                $adapter = Storage::disk($disk);
                $client = $adapter instanceof AwsS3V3Adapter ? $adapter->getClient() : null;
            } catch (Throwable) {
                $client = null;
            }

            if ($client === null) {
                $this->error("The {$role} S3 media disk could not be opened.");
                $passed = false;

                continue;
            }

            $prefix = ($resolved['prefix'] === '' ? '' : $resolved['prefix'].'/').'media/';
            $checks = [
                'write ACL' => ($base['options']['ACL'] ?? null) === 'bucket-owner-full-control' ? 'pass' : 'fail',
            ];
            if ($role === 'public') {
                $checks['public cache-control'] = ($base['options']['CacheControl'] ?? null) === 'no-store' ? 'pass' : 'fail';
            }

            $checks += S3BucketChecks::inspect(
                static fn (string $operation) => $client->{$operation}(['Bucket' => $bucket]),
                $region,
                $prefix,
                $role === 'private',
            );

            $rows = [];
            foreach ($checks as $check => $result) {
                $rows[] = [$check, $result];
            }

            $this->table(["{$role} bucket check", 'Result'], $rows);
            $passed = $passed && array_diff($checks, ['pass']) === [];
        }

        $this->line('Check delivery, effective permissions and retention separately with the provider.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
