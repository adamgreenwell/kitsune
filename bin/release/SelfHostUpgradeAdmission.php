<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use InvalidArgumentException;
use JsonException;

/** One reviewed, signed transition, bound to this installation and one operator-generated plan nonce. */
final readonly class SelfHostUpgradeAdmission
{
    private const FIELDS = [
        'format', 'mode', 'platform', 'origin_manifest_sha256', 'target_manifest_sha256',
        'origin_runtime_sha256', 'target_runtime_sha256', 'schema_sha256', 'migrations_sha256', 'modules_sha256',
    ];

    /**
     * @param  array{schema_sha256:string,migrations_sha256:string,modules_sha256:string}  $sqlite
     */
    private function __construct(
        public string $plan,
        public string $mode,
        public array $sqlite,
        public Manifest $origin,
        public Manifest $target,
        public string $platform,
        public string $originRuntime,
        public string $targetRuntime,
    ) {}

    /**
     * The adapter authenticates both releases and obtains both runtimes from the actual installation and staged target.
     * A release signature alone does not attest that its origin-to-target transition was rehearsed.
     */
    public static function verify(
        string $raw,
        string $signature,
        string $publicKey,
        string $fingerprint,
        Manifest $origin,
        Manifest $target,
        string $mode,
        string $platform,
        string $originRuntime,
        string $targetRuntime,
        string $deployment,
        string $nonce,
    ): self {
        Signature::verifyDetached($raw, $signature, $publicKey, $fingerprint);

        try {
            $record = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The signed upgrade admission is not valid JSON.');
        }
        if (! is_array($record) || array_keys($record) !== self::FIELDS
            || json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n" !== $raw
            || array_filter($record, 'is_string') !== $record) {
            throw new InvalidArgumentException('The signed upgrade admission has invalid, extra or noncanonical fields.');
        }

        foreach ([$deployment, $nonce, ...array_slice(array_values($record), 3)] as $digest) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                throw new InvalidArgumentException('The upgrade admission requires exact SHA-256 digests.');
            }
        }
        if ($record['format'] !== 'kitsune-self-host-admission-v1'
            || ! in_array($mode, ['native', 'docker'], true) || $record['mode'] !== $mode
            || ! in_array($platform, ['linux-amd64', 'linux-arm64'], true) || $record['platform'] !== $platform
            || $origin->channel !== $target->channel || $origin->bytes() === $target->bytes()
            || $record['origin_manifest_sha256'] !== hash('sha256', $origin->bytes())
            || $record['target_manifest_sha256'] !== hash('sha256', $target->bytes())
            || $record['origin_runtime_sha256'] !== $originRuntime || $record['target_runtime_sha256'] !== $targetRuntime) {
            throw new InvalidArgumentException('The signed upgrade admission does not match the selected release and runtimes.');
        }

        $current = explode('+', $origin->version, 2)[0];
        $next = explode('+', $target->version, 2)[0];
        if ($origin->channel === 'stable' && version_compare(substr($next, 1), substr($current, 1), '<=')) {
            throw new InvalidArgumentException('A stable transition requires a newer target version.');
        }

        $plan = hash('sha256', "kitsune-self-host-plan-v1\n".$deployment."\n".$nonce."\n".hash('sha256', $raw));

        return new self($plan, $mode, [
            'schema_sha256' => $record['schema_sha256'],
            'migrations_sha256' => $record['migrations_sha256'],
            'modules_sha256' => $record['modules_sha256'],
        ], $origin, $target, $platform, $originRuntime, $targetRuntime);
    }

    public function requireMatchingBackup(string $sqlite): void
    {
        if (SelfHostSqliteWitness::inspect($sqlite) !== $this->sqlite) {
            throw new InvalidArgumentException('The stopped installation differs from the admitted schema, migration ledger or module receipts.');
        }
    }
}
