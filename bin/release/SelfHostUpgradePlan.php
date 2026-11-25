<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;

final class SelfHostUpgradePlan
{
    /**
     * @param  array{mode:string, state:string, version?:string, source?:string, archive_sha256?:string, manifest_sha256?:string, reason?:string}  $installation
     * @return array{
     *     installation:array{mode:string,state:string,version?:string,source?:string,archive_sha256?:string,manifest_sha256?:string,reason?:string},
     *     origin:array{version:string,source:string,manifest_sha256:string},
     *     target:array{version:string,source:string,manifest_sha256:string,package_count:int,platform:string,
     *         archive:array{name:string,role:string,platform:string,size:int,sha256:string}},
     *     upgrade_admitted:false,decision:string,required_evidence?:list<string>
     * }
     */
    public static function build(array $installation, Manifest $origin, Manifest $target, string $archivePath, string $platform): array
    {
        if (! in_array($platform, ['linux-amd64', 'linux-arm64'], true)) {
            throw new InvalidArgumentException('Choose the deployment platform explicitly.');
        }

        $targetArtifact = self::targetArchive($target, $archivePath, $platform);
        $result = [
            'installation' => $installation,
            'origin' => [
                'version' => $origin->version,
                'source' => $origin->source,
                'manifest_sha256' => hash('sha256', $origin->bytes()),
            ],
            'target' => [
                'version' => $target->version,
                'source' => $target->source,
                'manifest_sha256' => hash('sha256', $target->bytes()),
                'package_count' => count($target->packages),
                'platform' => $platform,
                'archive' => $targetArtifact,
            ],
            'upgrade_admitted' => false,
        ];

        if ($installation['state'] !== 'installed') {
            return $result + ['decision' => 'installation_needs_inspection'];
        }
        if (($installation['source'] ?? null) !== $origin->source
            || (isset($installation['version']) && $installation['version'] !== $origin->version)
            || (isset($installation['manifest_sha256']) && $installation['manifest_sha256'] !== $result['origin']['manifest_sha256'])
            || (isset($installation['archive_sha256'])
                && ! self::listsArchive($origin, $installation['archive_sha256'], $platform))) {
            return $result + ['decision' => 'origin_receipt_mismatch'];
        }
        if ($installation['mode'] === 'docker') {
            // A legacy volume records the commit alone. A precise receipt also binds the archive and manifest,
            // but neither receipt proves which local image actually ran against the volume.
            return $result + ['decision' => 'docker_image_identity_required'];
        }
        if ($origin->bytes() === $target->bytes() && $installation['archive_sha256'] === $targetArtifact['sha256']) {
            return $result + ['decision' => 'already_at_target'];
        }

        return $result + [
            'decision' => 'transition_requires_admission',
            'required_evidence' => [
                'Exact installed dependencies, host changes, module receipts, schema and migration ledger.',
                'Consistent backup, isolated restore and rehearsal against the same origin and target.',
                'A release-specific procedure for maintenance, migration, activation, readback and recovery.',
            ],
        ];
    }

    /** @return array{name:string, role:string, platform:string, size:int, sha256:string} */
    private static function targetArchive(Manifest $target, string $path, string $platform): array
    {
        $name = basename($path);
        foreach ($target->artifacts as $artifact) {
            if ($artifact['name'] !== $name || ! self::applicationArchive($artifact, $platform)) {
                continue;
            }
            $actual = Files::digest($path, $artifact['size']);
            if (! hash_equals($artifact['sha256'], $actual['sha256'])) {
                throw new InvalidArgumentException('Target application archive checksum refused.');
            }

            return $artifact;
        }

        throw new InvalidArgumentException('Target application archive name, role or platform is absent from the signed manifest.');
    }

    private static function listsArchive(Manifest $origin, string $sha256, string $platform): bool
    {
        foreach ($origin->artifacts as $artifact) {
            if ($artifact['sha256'] === $sha256 && self::applicationArchive($artifact, $platform)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{name:string, role:string, platform:string} $artifact */
    private static function applicationArchive(array $artifact, string $platform): bool
    {
        // Both installers consume the signed native application archive, including Docker's image builder.
        return $artifact['role'] === 'native' && str_ends_with($artifact['name'], '.tar.gz')
            && in_array($artifact['platform'], ['any', $platform], true);
    }
}
