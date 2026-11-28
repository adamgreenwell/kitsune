<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Aws\S3\S3Client;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaCustodyFailure;

it('deletes only the verified S3 staging version and leaves ambiguous history intact', function (): void {
    $endpoint = getenv('KITSUNE_IMPORT_S3_TEST_ENDPOINT');
    if ($endpoint === false || ! class_exists(S3Client::class)) {
        $this->markTestSkipped('The optional S3 adapter and a loopback emulator are required.');
    }
    if (preg_match('~^http://127\.0\.0\.1:[0-9]+$~D', $endpoint) !== 1) {
        throw new RuntimeException('The import fixture requires a loopback S3 emulator.');
    }

    $bucket = 'kitsune-import-test-'.bin2hex(random_bytes(8));
    config(['filesystems.disks.import-test-s3' => [
        'driver' => 's3', 'region' => 'us-east-1', 'bucket' => $bucket, 'endpoint' => $endpoint,
        'key' => 'fixture', 'secret' => 'fixture', 'root' => 'prefix',
        'use_path_style_endpoint' => true, 'stream_reads' => true, 'throw' => true,
    ]]);
    $sdk = new S3Client([
        'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $endpoint,
        'credentials' => ['key' => 'fixture', 'secret' => 'fixture'], 'use_path_style_endpoint' => true,
    ]);
    $sdk->createBucket(['Bucket' => $bucket, 'ObjectOwnership' => 'BucketOwnerEnforced']);
    $sdk->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);

    $path = 'imports/staging/1/1/asset';
    $object = ['Bucket' => $bucket, 'Key' => 'prefix/'.$path];
    $versions = fn (): array => array_column($sdk->listObjectVersions(
        ['Bucket' => $bucket, 'Prefix' => $object['Key']],
    )['Versions'] ?? [], 'VersionId');
    $file = tempnam(sys_get_temp_dir(), 'kitsune-import-s3-');
    if ($file === false) {
        throw new RuntimeException('Could not create the test fixture.');
    }

    try {
        file_put_contents($file, 'approved bytes');
        $expected = hash('sha256', 'approved bytes');
        $verified = 0;
        $verifyManaged = static function () use (&$verified): void {
            $verified++;
        };

        MediaBytes::createVerified('import-test-s3', $path, $file, $expected);
        MediaBytes::createVerified('import-test-s3', $path, $file, $expected);
        $original = $versions();
        $version = MediaBytes::reservedObjectVersion('import-test-s3', $path);
        expect($original)->toBe([$version]);

        $foreign = $sdk->putObject($object + ['Body' => 'approved bytes'])['VersionId'];
        expect(fn () => MediaBytes::purgeReserved('import-test-s3', $path, $expected, $version, $verifyManaged))
            ->toThrow(MediaCustodyFailure::class)
            ->and($versions())->toBe([$foreign, ...$original])
            ->and($verified)->toBe(0);
        $sdk->deleteObject($object + ['VersionId' => $foreign]);

        $marker = $sdk->deleteObject($object)['VersionId'];
        expect(fn () => MediaBytes::purgeReserved('import-test-s3', $path, $expected, $version, $verifyManaged))
            ->toThrow(MediaCustodyFailure::class)
            ->and($versions())->toBe($original)
            ->and($verified)->toBe(0);
        $sdk->deleteObject($object + ['VersionId' => $marker]);

        MediaBytes::purgeReserved('import-test-s3', $path, $expected, $version, $verifyManaged);
        MediaBytes::purgeReserved('import-test-s3', $path, $expected, $version, $verifyManaged);
        $history = $sdk->listObjectVersions(['Bucket' => $bucket, 'Prefix' => $object['Key']]);
        expect($history['Versions'] ?? [])->toBe([])
            ->and($history['DeleteMarkers'] ?? [])->toBe([])
            ->and($verified)->toBe(1);

        // A later upload can have exactly the same hash and still belong to a different provider version.
        $replacement = $sdk->putObject($object + ['Body' => 'approved bytes'])['VersionId'];
        expect(fn () => MediaBytes::purgeReserved('import-test-s3', $path, $expected, $version, $verifyManaged))
            ->toThrow(MediaCustodyFailure::class)
            ->and($versions())->toBe([$replacement])
            ->and($verified)->toBe(1);
    } finally {
        unlink($file);
    }
});
