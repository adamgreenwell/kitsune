<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Release;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** One reviewed, signed transition, bound to this installation and one operator-generated plan nonce. */
final readonly class SelfHostUpgradeAdmission
{
    private const FIELDS = [
        'format', 'mode', 'platform', 'installation_id', 'attempt_nonce',
        'origin_manifest_sha256', 'target_manifest_sha256', 'target_archive_sha256',
        'origin_runtime_sha256', 'target_runtime_sha256', 'schema_sha256', 'migrations_sha256', 'modules_sha256',
    ];

    /**
     * @param  array{schema_sha256:string,migrations_sha256:string,modules_sha256:string}  $sqlite
     */
    private function __construct(
        public string $plan,
        public string $mode,
        public string $deployment,
        public array $sqlite,
        public Manifest $origin,
        public Manifest $target,
        public string $platform,
        public string $originRuntime,
        public string $targetRuntime,
        public string $targetArchive,
    ) {}

    /**
     * Prepare the payload using the isolated rehearsal's pre-migration database snapshot.
     *
     * @param  string|Closure():string  $deployment
     */
    public static function request(
        Manifest $origin,
        Manifest $target,
        string $mode,
        string $platform,
        string $originRuntime,
        string $targetRuntime,
        string $targetArchive,
        string|Closure $deployment,
        string $nonce,
        string $preMigrationSnapshot,
    ): string {
        self::requireTransition($origin, $target, $mode, $platform);
        self::requireDigests([$originRuntime, $targetRuntime, $targetArchive, $nonce]);
        self::requireTargetArchive($target, $targetArchive, $platform);
        $sqlite = SelfHostSqliteWitness::inspect($preMigrationSnapshot);
        $deployment = $deployment instanceof Closure ? $deployment() : $deployment;
        self::requireDigests([$deployment]);

        return json_encode([
            'format' => 'kitsune-self-host-admission-v1',
            'mode' => $mode,
            'platform' => $platform,
            'installation_id' => $deployment,
            'attempt_nonce' => $nonce,
            'origin_manifest_sha256' => hash('sha256', $origin->bytes()),
            'target_manifest_sha256' => hash('sha256', $target->bytes()),
            'target_archive_sha256' => $targetArchive,
            'origin_runtime_sha256' => $originRuntime,
            'target_runtime_sha256' => $targetRuntime,
        ] + $sqlite, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * The adapter authenticates both releases and obtains both runtimes from the actual installation and staged target.
     * The deployment identity comes from the installation's own control state. The signed attempt nonce
     * must be reused when resuming that operation. A new attempt requires a separately signed admission.
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
        string $targetArchive,
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

        self::requireDigests([$deployment, $nonce, ...array_slice(array_values($record), 3)]);
        if (! hash_equals($record['installation_id'], $deployment) || ! hash_equals($record['attempt_nonce'], $nonce)) {
            throw new InvalidArgumentException('The signed upgrade admission does not authorize this installation and attempt.');
        }

        self::requireTransition($origin, $target, $mode, $platform);
        self::requireTargetArchive($target, $targetArchive, $platform);
        if ($record['format'] !== 'kitsune-self-host-admission-v1' || $record['mode'] !== $mode || $record['platform'] !== $platform
            || $record['origin_manifest_sha256'] !== hash('sha256', $origin->bytes())
            || $record['target_manifest_sha256'] !== hash('sha256', $target->bytes())
            || $record['target_archive_sha256'] !== $targetArchive
            || $record['origin_runtime_sha256'] !== $originRuntime || $record['target_runtime_sha256'] !== $targetRuntime) {
            throw new InvalidArgumentException('The signed upgrade admission does not match the selected release and runtimes.');
        }

        $plan = hash('sha256', "kitsune-self-host-plan-v1\n".$raw);

        return new self($plan, $mode, $deployment, [
            'schema_sha256' => $record['schema_sha256'],
            'migrations_sha256' => $record['migrations_sha256'],
            'modules_sha256' => $record['modules_sha256'],
        ], $origin, $target, $platform, $originRuntime, $targetRuntime, $targetArchive);
    }

    /**
     * Reopen exactly the signed native plan in the durable journal. The adapter checks the surviving
     * runtime trees under its installer lock because a cutover may have moved either one already.
     */
    public static function reopenNative(
        string $raw,
        string $signature,
        string $publicKey,
        string $fingerprint,
        Manifest $origin,
        Manifest $target,
        string $platform,
        ?string $targetArchive,
        SelfHostUpgradeJournal $journal,
        string $state,
        string $nonce,
    ): self {
        $record = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        $originRuntime = $record['origin_runtime_sha256'] ?? null;
        $targetRuntime = $record['target_runtime_sha256'] ?? null;
        $targetArchive ??= $record['target_archive_sha256'] ?? null;
        if (! is_string($originRuntime) || ! is_string($targetRuntime) || ! is_string($targetArchive)) {
            throw new InvalidArgumentException('The signed upgrade admission lacks its runtime identities.');
        }
        $admission = self::verify($raw, $signature, $publicKey, $fingerprint, $origin, $target, 'native', $platform,
            $originRuntime, $targetRuntime, $targetArchive, $journal->deploymentIdentity($state), $nonce);
        $current = $journal->state();
        if ($current === null || ! hash_equals($admission->plan, $current['plan'])) {
            throw new RuntimeException('The signed approval does not match the recorded native upgrade.');
        }

        return $admission;
    }

    private static function requireTransition(Manifest $origin, Manifest $target, string $mode, string $platform): void
    {
        if (! in_array($mode, ['native', 'docker'], true) || ! in_array($platform, ['linux-amd64', 'linux-arm64'], true)
            || $origin->channel !== $target->channel || $origin->bytes() === $target->bytes()) {
            throw new InvalidArgumentException('Choose distinct releases in one channel and a supported mode and platform.');
        }

        $current = explode('+', $origin->version, 2)[0];
        $next = explode('+', $target->version, 2)[0];
        if ($origin->channel === 'stable' && version_compare(substr($next, 1), substr($current, 1), '<=')) {
            throw new InvalidArgumentException('A stable transition requires a newer target version.');
        }
    }

    private static function requireTargetArchive(Manifest $target, string $digest, string $platform): void
    {
        foreach ($target->artifacts as $archive) {
            if ($archive['role'] === 'native' && str_ends_with($archive['name'], '.tar.gz')
                && in_array($archive['platform'], ['any', $platform], true) && hash_equals($archive['sha256'], $digest)) {
                return;
            }
        }

        throw new InvalidArgumentException('The target archive was not verified for this platform and release.');
    }

    /** @param list<string> $digests */
    private static function requireDigests(array $digests): void
    {
        foreach ($digests as $digest) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
                throw new InvalidArgumentException('The upgrade admission requires exact SHA-256 digests or 32-byte identifiers.');
            }
        }
    }

    public function requireMatchingBackup(string $sqlite): void
    {
        if (SelfHostSqliteWitness::inspect($sqlite) !== $this->sqlite) {
            throw new InvalidArgumentException('The stopped installation differs from the admitted schema, migration ledger or module receipts.');
        }
    }
}
