<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Media\S3BucketChecks;

it('skips a local installation without loading an S3 adapter', function (): void {
    $this->artisan('kitsune:media-s3-diagnose')
        ->expectsOutput('Skipped. Neither configured media disk uses S3 directly.')
        ->assertSuccessful();
});

it('fails a selected S3 disk without the optional adapter', function (): void {
    if (class_exists('League\Flysystem\AwsS3V3\AwsS3V3Adapter')) {
        $this->markTestSkipped('This case exercises the bare installation.');
    }

    config([
        'kitsune.media.disks.private' => 'absent-adapter',
        'filesystems.disks.absent-adapter' => ['driver' => 's3', 'bucket' => 'unused'],
    ]);

    $this->artisan('kitsune:media-s3-diagnose')
        ->expectsOutput('The configured S3 disks require the optional league/flysystem-aws-s3-v3 package.')
        ->assertFailed();
});

it('requires an enabled cleanup rule covering the complete media key prefix', function (array $rule, string $result): void {
    $rule += ['Status' => 'Enabled', 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 1]];
    $read = static fn (string $operation): array => $operation === 'GetBucketLifecycleConfiguration' ? ['Rules' => [$rule]] : [];

    expect(S3BucketChecks::inspect($read, 'us-east-1', 'data/site/media/', true)['incomplete upload cleanup'])->toBe($result);
})->with([
    'empty filter' => [['Filter' => []], 'pass'],
    'ancestor prefix' => [['Filter' => ['Prefix' => 'data/site/']], 'pass'],
    'media prefix' => [['Filter' => ['Prefix' => 'data/site/media/']], 'pass'],
    'legacy prefix' => [['Prefix' => 'data/site/media/'], 'pass'],
    'sibling prefix' => [['Filter' => ['Prefix' => 'data/other/media/']], 'fail'],
    'narrower prefix' => [['Filter' => ['Prefix' => 'data/site/media/1/']], 'fail'],
    'size-limited prefix' => [['Filter' => ['And' => ['Prefix' => 'data/site/', 'ObjectSizeGreaterThan' => 1]]], 'fail'],
    'tag filter' => [['Filter' => ['Tag' => ['Key' => 'class', 'Value' => 'media']]], 'fail'],
    'disabled' => [['Status' => 'Disabled'], 'fail'],
    'no abort action' => [['AbortIncompleteMultipartUpload' => []], 'fail'],
]);

it('distinguishes suspended versioning and missing or inaccessible public policy data', function (): void {
    $read = static fn (string $operation): array => match ($operation) {
        'GetBucketVersioning' => ['Status' => 'Suspended'],
        'GetBucketPolicyStatus' => throw new RuntimeException('Fixture token must never be a finding'),
        default => [],
    };

    $checks = S3BucketChecks::inspect($read, 'us-east-1', 'media/', true);

    expect($checks['never versioned'])->toBe('fail')
        ->and($checks['bucket policy status'])->toBe('unknown')
        ->and($checks['bucket region'])->toBe('fail');
});
