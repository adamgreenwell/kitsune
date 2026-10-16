<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialAuthentication;
use Kitsune\Core\Credentials\CredentialReader;
use Kitsune\Core\Credentials\CredentialUnavailability;
use Kitsune\Core\Credentials\CredentialUnavailable;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Credentials\FreshCredentialAuthentication;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\OrgCredentialMode;
use Kitsune\Core\Modules\ModuleDiscovery;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Org-shared owner consent for new live Sessions. Historical payment custody never depends on this row. @internal */
final class LiveStripeAdmission
{
    public const TABLE = 'commerce_stripe_admissions';

    private const COLUMNS = ['org_id', 'version', 'state', 'account_id', 'credential_witness', 'admitted_at', 'revoked_at', 'updated_at'];

    public function __construct(private readonly Context $context, private readonly OrderLocks $locks,
        private readonly FreshCredentialAuthentication $authentication, private readonly CredentialReader $credentials,
        private readonly StripeCheckout $stripe, private readonly Auditor $auditor) {}

    /** @return array{state:string,version:?string,account:?string,admittedAt:?string} */
    public function statusForOwner(): array
    {
        return $this->locks->run(function (): array {
            $proof = $this->authentication->confirm(null, requirePassword: false);
            $row = $this->row();
            $state = $row->state ?? 'not_admitted';
            if ($state === 'admitted') {
                try {
                    $state = hash_equals($row->credential_witness, $this->credentialWitness()) ? 'admitted' : 'stale';
                } catch (Throwable) {
                    $state = 'stale';
                }
            }
            $proof->assertCurrent();
            if ($this->rowDigest($this->row()) !== $this->rowDigest($row)) {
                throw new CheckoutRefused('changed');
            }

            return ['state' => $state, 'version' => $row?->version, 'account' => $row?->account_id,
                'admittedAt' => $row?->admitted_at];
        });
    }

    /** Every Session-create POST, including an unknown-outcome replay, must pass this new-sale gate. */
    public function forNewCheckout(?string $account = null): string
    {
        $row = $this->row();
        if ($row === null || $row->state !== 'admitted' || ! hash_equals($row->credential_witness, $this->credentialWitness())
            || ($account !== null && $row->account_id !== $account)) {
            throw new CheckoutRefused('live');
        }

        return $row->account_id;
    }

    /** Account verification is bounded provider I/O between two committed, independently authenticated phases. */
    public function admit(?string $expectedVersion, #[\SensitiveParameter] string $password): void
    {
        try {
            $this->outside();
            $captured = $this->locks->run(function () use ($expectedVersion, $password): array {
                [$identity, $proof] = $this->authority($password);
                $row = $this->row();
                $this->version($row, $expectedVersion);
                $witness = $this->credentialWitness();
                $secret = $this->credentials->secret(StripeCheckout::SLOT);
                // Both signing slots are validated without retaining plaintext or any provider account data.
                $this->signingSecret(StripeWebhook::SLOT);
                try {
                    $this->signingSecret(StripeWebhook::PREVIOUS_SLOT);
                } catch (CredentialUnavailable $failure) {
                    if ($failure->reason !== CredentialUnavailability::NotSet) {
                        throw new CheckoutRefused;
                    }
                }
                $proof->assertCurrent();
                if ($this->rowDigest($this->row()) !== $this->rowDigest($row) || $witness !== $this->credentialWitness()) {
                    throw new CheckoutRefused('changed');
                }

                return ['identity' => $identity, 'row' => $this->rowDigest($row), 'witness' => $witness,
                    'secret' => $secret, 'connection' => $this->connection(), 'pdo' => $this->connection()->getPdo()];
            });
            $this->outside();
            $account = $this->stripe->liveAccount($captured['secret']);
            if (preg_match('/\Aacct_[A-Za-z0-9]{1,95}\z/D', $account) !== 1) {
                throw new CheckoutRefused;
            }
            $this->locks->run(function () use ($expectedVersion, $password, $captured, $account): void {
                [$identity, $proof] = $this->authority($password);
                $before = $this->row();
                $this->version($before, $expectedVersion);
                if ($identity !== $captured['identity'] || $this->rowDigest($before) !== $captured['row']
                    || $captured['witness'] !== $this->credentialWitness() || $this->connection() !== $captured['connection']
                    || $this->connection()->getPdo() !== $captured['pdo']) {
                    throw new CheckoutRefused('changed');
                }
                $now = self::now();
                $this->write($before, ['org_id' => $identity['org'], 'version' => bin2hex(random_bytes(16)),
                    'state' => 'admitted', 'account_id' => $account, 'credential_witness' => $captured['witness'],
                    'admitted_at' => $now, 'revoked_at' => null, 'updated_at' => $now], $proof, 'commerce.live_stripe_admitted');
            });
        } catch (Throwable) {
            throw new CheckoutRefused('live');
        } finally {
            unset($password, $captured);
        }
    }

    /** A revocation also changes the version when no admission exists, fencing an in-flight account verification. */
    public function revoke(?string $expectedVersion, #[\SensitiveParameter] string $password): void
    {
        try {
            $this->locks->run(function () use ($expectedVersion, $password): void {
                [$identity, $proof] = $this->authority($password);
                $before = $this->row();
                $this->version($before, $expectedVersion);
                $now = self::now();
                $this->write($before, ['org_id' => $identity['org'], 'version' => bin2hex(random_bytes(16)),
                    'state' => 'revoked', 'account_id' => $before?->account_id, 'credential_witness' => $before?->credential_witness,
                    'admitted_at' => $before?->admitted_at, 'revoked_at' => $now, 'updated_at' => $now], $proof, 'commerce.live_stripe_revoked');
            });
        } catch (Throwable) {
            throw new CheckoutRefused('live');
        } finally {
            unset($password);
        }
    }

    /** @return array{array{org:int,site:?int,class:string,id:string,password:string},CredentialAuthentication} */
    private function authority(#[\SensitiveParameter] string $password): array
    {
        $proof = $this->authentication->confirm($password);
        $actor = Permissions::currentUser();
        if (! $actor instanceof Model) {
            throw new CheckoutRefused;
        }
        $column = $actor->getAuthPasswordName();
        $row = $this->connection()->table($actor->getTable())->useWritePdo()->where($actor->getKeyName(), $actor->getKey())
            ->lockForUpdate()->first([$column]);
        $hash = $row->{$column} ?? null;
        if (! is_string($hash)) {
            throw new CheckoutRefused;
        }
        $proof->assertCurrent();

        return [['org' => $this->context->orgId() ?? throw new CheckoutRefused, 'site' => $this->context->siteId(),
            'class' => $actor::class, 'id' => (string) $actor->getKey(), 'password' => hash('sha256', $hash)], $proof];
    }

    private function row(): ?\stdClass
    {
        $connection = $this->connection();
        $org = $this->context->orgId() ?? throw new CheckoutRefused;
        if ($connection->transactionLevel() < 1 || ! $connection->getSchemaBuilder()->hasColumns(self::TABLE, self::COLUMNS)
            || ! $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)
                ->where('version', ModuleDiscovery::version('kitsune/commerce'))->lockForUpdate()->exists()) {
            throw new CheckoutRefused('setup');
        }
        $row = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)->lockForUpdate()->first();
        if ($row === null) {
            return null;
        }
        if ((string) $row->org_id !== (string) $org || preg_match('/\A[a-f0-9]{32}\z/D', $row->version) !== 1
            || ! in_array($row->state, ['admitted', 'revoked'], true)
            || ($row->state === 'admitted' && ($row->account_id === null || $row->revoked_at !== null))
            || ($row->state === 'revoked' && $row->revoked_at === null)
            || (($row->account_id === null) !== ($row->credential_witness === null))
            || (($row->account_id === null) !== ($row->admitted_at === null))
            || ($row->account_id !== null && (preg_match('/\Aacct_[A-Za-z0-9]{1,95}\z/D', $row->account_id) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', $row->credential_witness) !== 1))) {
            throw new CheckoutRefused;
        }
        foreach (['admitted_at', 'revoked_at', 'updated_at'] as $field) {
            if ($row->{$field} !== null) {
                CheckoutAttempts::instant($row->{$field});
            }
        }

        return $row;
    }

    private function credentialWitness(): string
    {
        $connection = $this->connection();
        $org = $this->context->orgId() ?? throw new CheckoutRefused;
        if (StripeMode::current($connection, $org) !== 'live') {
            throw new CheckoutRefused('live');
        }
        $modeRow = $connection->table('org_credential_modes')->useWritePdo()->where('org_id', $org)->lockForUpdate()->first() ?? throw new CheckoutRefused;
        // changed_at has second precision. The existing audited mode transition also changes identity when
        // test→live happens in that same second; unrelated audits and other Orgs never alter this witness.
        $modeAudit = $connection->table(AuditLog::TABLE)->useWritePdo()->where('org_id', $org)
            ->where('target_type', (new OrgCredentialMode)->getMorphClass())->where('target_id', (string) $modeRow->id)
            ->whereIn('action', [CredentialWriter::MODE_LIVE, CredentialWriter::MODE_TEST])->orderByDesc('id')->lockForUpdate()->first(['id']);
        $module = $connection->table('modules')->useWritePdo()->where('handle', 'kitsune/commerce')
            ->where('is_enabled', true)->where('version', ModuleDiscovery::version('kitsune/commerce'))->lockForUpdate()->first();
        $rows = $connection->table('credentials')->useWritePdo()->where('org_id', $org)->where('mode', 'live')
            ->whereIn('slot', [StripeCheckout::SLOT, StripeWebhook::SLOT, StripeWebhook::PREVIOUS_SLOT])->orderBy('slot')->lockForUpdate()->get();
        foreach ([StripeCheckout::SLOT, StripeWebhook::SLOT] as $slot) {
            $row = $rows->firstWhere('slot', $slot);
            if ($row === null || ! is_string($row->ciphertext) || $row->ciphertext === '') {
                throw new CheckoutRefused('setup');
            }
        }
        if ($module === null || $this->context->orgId() !== $org) {
            throw new CheckoutRefused;
        }

        return hash('sha256', json_encode(['version' => 2, 'org' => $org, 'api' => StripeCheckout::API_VERSION,
            'mode' => [(array) $modeRow, $modeAudit === null ? null : (string) $modeAudit->id],
            'module' => [$module->id, $module->version], 'credentials' => $rows->all()], JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $values */
    private function write(?object $before, array $values, CredentialAuthentication $proof, string $action): void
    {
        $connection = $this->connection();
        $pdo = $connection->getPdo();
        $level = $connection->transactionLevel();
        $site = $this->context->siteId();
        $org = $values['org_id'];
        if ($before === null) {
            $connection->table(self::TABLE)->insert($values);
        } elseif ($connection->table(self::TABLE)->where('org_id', $org)->where('version', $before->version)->update($values) !== 1) {
            throw new CheckoutRefused('changed');
        }
        $expected = (object) $values;
        if ($this->rowDigest($this->row()) !== $this->rowDigest($expected)) {
            throw new CheckoutRefused;
        }
        $actor = Permissions::currentUser();
        $target = (new Org)->newFromBuilder(['id' => $org]);
        $expectedAudit = ['org_id' => $org, 'site_id' => $site, 'actor_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
            'actor_id' => $actor?->getAuthIdentifier(), 'action' => $action, 'target_type' => $target->getMorphClass(), 'target_id' => $org];
        $audit = $this->auditor->recordOrFail($action, $target);
        $proof->assertCurrent();
        $saved = $connection->table(AuditLog::TABLE)->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first() ?? throw new CheckoutRefused;
        foreach ($expectedAudit as $field => $value) {
            if (($saved->{$field} === null) !== ($value === null) || (string) $saved->{$field} !== (string) $value) {
                throw new CheckoutRefused;
            }
        }
        if ($this->rowDigest($this->row()) !== $this->rowDigest($expected)
            || ($values['state'] === 'admitted' && $values['credential_witness'] !== $this->credentialWitness())
            || $this->context->orgId() !== $org || $this->context->siteId() !== $site || $this->connection() !== $connection
            || $connection->getPdo() !== $pdo || $connection->transactionLevel() !== $level || ! $pdo->inTransaction()) {
            throw new CheckoutRefused('changed');
        }
    }

    private function signingSecret(string $slot): void
    {
        $value = $this->credentials->secret($slot)->reveal();
        try {
            if (strlen($value) < 16 || strlen($value) > 255 || preg_match('/\Awhsec_[A-Za-z0-9_]+\z/D', $value) !== 1) {
                throw new CheckoutRefused;
            }
        } finally {
            unset($value);
        }
    }

    private function version(?object $row, ?string $expected): void
    {
        if (($expected !== null && preg_match('/\A[a-f0-9]{32}\z/D', $expected) !== 1) || $row?->version !== $expected) {
            throw new CheckoutRefused('changed');
        }
    }

    private function rowDigest(?object $row): string
    {
        return hash('sha256', json_encode($row === null ? null : array_intersect_key((array) $row, array_flip(self::COLUMNS)), JSON_THROW_ON_ERROR));
    }

    private function connection(): Connection
    {
        return (new Order)->getConnection();
    }

    private function outside(): void
    {
        if ($this->connection()->transactionLevel() !== 0 || $this->connection()->getPdo()->inTransaction()) {
            throw new CheckoutRefused;
        }
    }

    private static function now(): string
    {
        return CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s');
    }
}
