<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use ArrayAccess;
use Throwable;

/** @internal Bucket metadata can disqualify a direct S3 origin. Effective delivery needs a separate test. */
final class S3BucketChecks
{
    /**
     * @param  callable(string): (array<string, mixed>|ArrayAccess<string, mixed>)  $read
     * @return array<string, string>
     */
    public static function inspect(callable $read, string $region, string $prefix, bool $private): array
    {
        return [
            'bucket region' => self::check($read, 'HeadBucket', static fn ($data): bool => ($data['BucketRegion'] ?? $data['@metadata']['headers']['x-amz-bucket-region'] ?? null) === $region),
            'bucket owner enforced' => self::check($read, 'GetBucketOwnershipControls', static fn ($data): bool => ($data['OwnershipControls']['Rules'][0]['ObjectOwnership'] ?? null) === 'BucketOwnerEnforced'),
            'never versioned' => self::check($read, 'GetBucketVersioning', static fn ($data): bool => ! isset($data['Status'])),
            'object lock absent' => self::check($read, 'GetObjectLockConfiguration', static fn (): bool => false, ['ObjectLockConfigurationNotFoundError' => 'pass']),
            'replication absent' => self::check($read, 'GetBucketReplication', static fn (): bool => false, ['ReplicationConfigurationNotFoundError' => 'pass']),
            'incomplete upload cleanup' => self::check($read, 'GetBucketLifecycleConfiguration', static fn ($data): bool => self::hasCleanupRule($data['Rules'] ?? [], $prefix), ['NoSuchLifecycleConfiguration' => 'fail']),
            'bucket public access block' => self::check($read, 'GetPublicAccessBlock', static fn ($data): bool => self::hasAccessBlock($data['PublicAccessBlockConfiguration'] ?? [], $private), ['NoSuchPublicAccessBlockConfiguration' => $private ? 'fail' : 'pass']),
            'bucket policy status' => self::check($read, 'GetBucketPolicyStatus', static fn ($data): bool => ($data['PolicyStatus']['IsPublic'] ?? null) === ! $private, ['NoSuchBucketPolicy' => $private ? 'pass' : 'fail']),
        ];
    }

    /** @param array<string, mixed> $configuration */
    private static function hasAccessBlock(array $configuration, bool $private): bool
    {
        if (! $private) {
            return ($configuration['RestrictPublicBuckets'] ?? null) === false;
        }

        foreach (['BlockPublicAcls', 'IgnorePublicAcls', 'BlockPublicPolicy', 'RestrictPublicBuckets'] as $setting) {
            if (($configuration[$setting] ?? null) !== true) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $rules */
    private static function hasCleanupRule(array $rules, string $mediaPrefix): bool
    {
        foreach ($rules as $rule) {
            if (($rule['Status'] ?? null) !== 'Enabled' || ($rule['AbortIncompleteMultipartUpload']['DaysAfterInitiation'] ?? 0) < 1) {
                continue;
            }

            // S3 also accepts a legacy top-level Prefix. Tag and object-size filters cannot cover every media key.
            $filter = $rule['Filter'] ?? ['Prefix' => $rule['Prefix'] ?? ''];
            if (array_diff_key($filter, ['Prefix' => true]) === [] && str_starts_with($mediaPrefix, (string) ($filter['Prefix'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(string): (array<string, mixed>|ArrayAccess<string, mixed>)  $read
     * @param  callable(array<string, mixed>|ArrayAccess<string, mixed>): bool  $matches
     * @param  array<string, string>  $expectedErrors
     */
    private static function check(callable $read, string $operation, callable $matches, array $expectedErrors = []): string
    {
        try {
            return $matches($read($operation)) ? 'pass' : 'fail';
        } catch (Throwable $error) {
            // The AWS SDK is optional. Its exception message can contain URLs and request or response details.
            $errorCode = [$error, 'getAwsErrorCode'];
            $statusCode = [$error, 'getStatusCode'];

            if (is_a($error, 'Aws\Exception\AwsException') && is_callable($errorCode) && is_callable($statusCode)) {
                $code = $errorCode();

                if (is_string($code) && isset($expectedErrors[$code])) {
                    return $expectedErrors[$code];
                }

                if ($code === 'AccessDenied' || $statusCode() === 403) {
                    return 'denied';
                }
            }

            return 'unknown';
        }
    }
}
