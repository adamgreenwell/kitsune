<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Privacy;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Models\RetentionPolicy;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Schema\RequiredColumns;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** Versioned Org-wide choices. Activation is prospective; this service never touches an order. @internal */
final class RetentionPolicies
{
    public const TABLE = 'commerce_retention_policies';

    public const ACTIVATIONS = 'commerce_retention_policy_activations';

    private const MIGRATION = '0001_01_01_000217_create_commerce_retention_policies';

    public function __construct(private readonly Context $context, private readonly Auditor $auditor) {}

    /** @param list<string> $billingFields */
    public function createAsOwner(array $billingFields, #[\SensitiveParameter] string $legalBasis, #[\SensitiveParameter] string $reason, string $clock, int $duration, ?string $timezone = null): RetentionPolicySnapshot
    {
        return $this->safe(function () use ($billingFields, $legalBasis, $reason, $clock, $duration, $timezone): RetentionPolicySnapshot {
            $config = RetentionPolicySnapshot::configurationFor($billingFields, $legalBasis, $reason, $clock, $duration, $timezone);

            return $this->write(function (Connection $connection, array $authority) use ($config): RetentionPolicySnapshot {
                $org = $authority['org'];
                $pointer = $this->pointer($connection, $org, true);
                $latest = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)->orderByDesc('version')->lockForUpdate()->first();
                $version = $latest === null ? 1 : $this->row($latest)->version + 1;
                if ($version > 2147483647) {
                    throw new RetentionRefused;
                }
                $data = [...$config, 'org_id' => $org, 'version' => $version,
                    'billing_fields' => json_encode($config['billing_fields'], JSON_THROW_ON_ERROR),
                    'config_hash' => CheckoutAttempts::digest($config), 'created_at' => CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s')];
                $id = RetentionPolicySnapshot::integer($connection->table(self::TABLE)->insertGetId($data));
                $expected = $this->row((object) [...$data, 'id' => $id]);
                $audit = $this->audit('commerce.retention_policy_created', $expected);
                $this->assertAuthority($authority);
                if ($this->version($connection, $org, $version, true)->snapshot() !== $expected->snapshot()
                    || $this->pointer($connection, $org, true) !== $pointer) {
                    throw new RetentionRefused;
                }
                $this->assertAudit($connection, $audit, 'commerce.retention_policy_created', $expected, $authority);

                return $expected;
            });
        });
    }

    public function activateAsOwner(int $version): RetentionPolicySnapshot
    {
        return $this->safe(fn (): RetentionPolicySnapshot => $this->write(function (Connection $connection, array $authority) use ($version): RetentionPolicySnapshot {
            $org = $authority['org'];
            $policy = $this->version($connection, $org, $version, true);
            $before = $this->pointer($connection, $org, true);
            if ($before !== null && $before['policy_id'] === $policy->id) {
                $this->assertAuthority($authority);
                if ($this->version($connection, $org, $version, true)->snapshot() !== $policy->snapshot()
                    || $this->pointer($connection, $org, true) !== $before) {
                    throw new RetentionRefused;
                }

                return $policy;
            }
            $data = ['org_id' => $org, 'policy_id' => $policy->id, 'policy_version' => $policy->version,
                'config_hash' => $policy->configHash, 'activated_at' => CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s')];
            if ($before === null) {
                $id = RetentionPolicySnapshot::integer($connection->table(self::ACTIVATIONS)->insertGetId($data));
            } else {
                $id = $before['id'];
                if ($connection->table(self::ACTIVATIONS)->where('id', $id)->where('org_id', $org)->update($data) !== 1) {
                    throw new RetentionRefused;
                }
            }
            $expectedPointer = ['id' => $id, ...$data];
            $audit = $this->audit('commerce.retention_policy_activated', $policy);
            $this->assertAuthority($authority);
            if ($this->version($connection, $org, $version, true)->snapshot() !== $policy->snapshot()
                || $this->pointer($connection, $org, true) !== $expectedPointer) {
                throw new RetentionRefused;
            }
            $this->assertAudit($connection, $audit, 'commerce.retention_policy_activated', $policy, $authority);

            return $policy;
        }));
    }

    public function current(Connection $connection, int $org, bool $lock = false): ?RetentionPolicySnapshot
    {
        return $this->safe(function () use ($connection, $org, $lock): ?RetentionPolicySnapshot {
            $this->context($connection, $org, $lock);
            if (! $this->schemaAvailable($connection)) {
                return null;
            }
            $pointer = $this->pointer($connection, $org, $lock);

            return $pointer === null ? null : $this->version($connection, $org, $pointer['policy_version'], $lock);
        });
    }

    public function version(Connection $connection, int $org, int $version, bool $lock = false): RetentionPolicySnapshot
    {
        return $this->safe(function () use ($connection, $org, $version, $lock): RetentionPolicySnapshot {
            $this->context($connection, $org, $lock);
            if (! $this->schemaAvailable($connection) || $version < 1) {
                throw new RetentionRefused;
            }
            $query = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)->where('version', $version)->limit(2);
            $rows = ($lock ? $query->lockForUpdate() : $query)->get();
            if ($rows->count() !== 1) {
                throw new RetentionRefused;
            }

            return $this->row($rows->sole());
        });
    }

    /** Explicit bounded owner history; reasons never enter buyer/export projections.
     * @return list<RetentionPolicySnapshot>
     */
    public function versionsAsOwner(int $limit = 100): array
    {
        return $this->versions((new RetentionPolicy)->getConnection(), $this->context->orgId() ?? throw new RetentionRefused, limit: $limit);
    }

    /** @return list<RetentionPolicySnapshot> */
    public function versions(Connection $connection, int $org, bool $lock = false, int $limit = 100): array
    {
        return $this->safe(function () use ($connection, $org, $lock, $limit): array {
            $this->context($connection, $org, $lock);
            $authority = $this->authority();
            if ($limit < 1 || $limit > 1000 || ! $this->schemaAvailable($connection)) {
                throw new RetentionRefused;
            }
            $query = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)->orderByDesc('version')->limit($limit);
            $rows = ($lock ? $query->lockForUpdate() : $query)->get();
            $result = $rows->map(fn (object $row): RetentionPolicySnapshot => $this->row($row))->all();
            $this->assertAuthority($authority);

            return $result;
        });
    }

    public function schemaAvailable(Connection $connection): bool
    {
        return $this->safe(function () use ($connection): bool {
            if ($connection !== (new RetentionPolicy)->getConnection()) {
                throw new RetentionRefused;
            }
            $schema = $connection->getSchemaBuilder();
            if (! $schema->hasTable(self::TABLE) && ! $schema->hasTable(self::ACTIVATIONS)) {
                if ($schema->hasTable('migrations') && $connection->table('migrations')->useWritePdo()->where('migration', self::MIGRATION)->exists()) {
                    throw new RetentionRefused;
                }

                return false;
            }
            foreach ([self::TABLE => ['id', 'org_id', 'version', 'schema_version', 'billing_fields', 'legal_basis', 'reason', 'clock', 'duration', 'timezone', 'config_hash', 'created_at'],
                self::ACTIVATIONS => ['id', 'org_id', 'policy_id', 'policy_version', 'config_hash', 'activated_at']] as $table => $columns) {
                if (! $schema->hasTable($table) || ! RequiredColumns::present($connection, $table, $columns)) {
                    throw new RetentionRefused;
                }
            }

            return true;
        });
    }

    /** @return array{id:int,org_id:int,policy_id:int,policy_version:int,config_hash:string,activated_at:string}|null */
    private function pointer(Connection $connection, int $org, bool $lock): ?array
    {
        $query = $connection->table(self::ACTIVATIONS)->useWritePdo()->where('org_id', $org)->limit(2);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->count() > 1) {
            throw new RetentionRefused;
        }
        if ($rows->isEmpty()) {
            return null;
        }
        $row = $rows->sole();
        $policyId = RetentionPolicySnapshot::integer($row->policy_id);
        $query = $connection->table(self::TABLE)->useWritePdo()->where('id', $policyId);
        $policy = $this->row(($lock ? $query->lockForUpdate() : $query)->first() ?? throw new RetentionRefused);
        if ($policy->orgId !== $org || $policy->version !== RetentionPolicySnapshot::integer($row->policy_version)
            || $policy->configHash !== $row->config_hash || ! is_string($row->activated_at)) {
            throw new RetentionRefused;
        }
        CheckoutAttempts::instant($row->activated_at);

        return ['id' => RetentionPolicySnapshot::integer($row->id), 'org_id' => $org, 'policy_id' => $policyId,
            'policy_version' => $policy->version, 'config_hash' => $policy->configHash, 'activated_at' => $row->activated_at];
    }

    private function row(#[\SensitiveParameter] object $row): RetentionPolicySnapshot
    {
        if (! is_string($row->billing_fields) || strlen($row->billing_fields) > 1024
            || (! is_int($row->duration) && ! is_string($row->duration)) || preg_match('/\A(?:0|[1-9][0-9]{0,5})\z/D', (string) $row->duration) !== 1) {
            throw new RetentionRefused;
        }

        return RetentionPolicySnapshot::fromSnapshot(['policy_id' => $row->id, 'org_id' => $row->org_id, 'version' => $row->version,
            'configuration' => ['schema_version' => RetentionPolicySnapshot::integer($row->schema_version),
                'billing_fields' => json_decode($row->billing_fields, true, 4, JSON_THROW_ON_ERROR), 'legal_basis' => $row->legal_basis,
                'reason' => $row->reason, 'clock' => $row->clock, 'duration' => (int) $row->duration, 'timezone' => $row->timezone],
            'config_hash' => $row->config_hash, 'created_at' => $row->created_at]);
    }

    private function context(Connection $connection, int $org, bool $lock): void
    {
        if ($connection !== (new RetentionPolicy)->getConnection() || $org < 1 || $this->context->orgId() !== $org
            || ($lock && ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()))) {
            throw new RetentionRefused;
        }
    }

    /** @template T
     * @param  Closure(Connection,array{org:int,site:?int,type:string,id:string}):T  $work
     * @return T
     */
    private function write(Closure $work): mixed
    {
        $connection = (new RetentionPolicy)->getConnection();
        $authority = $this->authority();

        return TransactionRecovery::run($connection, function () use ($connection, $authority, $work) {
            $this->context($connection, $authority['org'], true);
            if (! $this->schemaAvailable($connection)) {
                throw new RetentionRefused;
            }
            $org = $authority['org'];
            if ($connection->getDriverName() === 'sqlite') {
                $connection->table('orgs')->where('id', $org)->update(['id' => $org]);
            }
            if ($connection->table('orgs')->useWritePdo()->where('id', $org)->lockForUpdate()->first() === null) {
                throw new RetentionRefused;
            }
            $this->assertAuthority($authority);

            return $work($connection, $authority);
        });
    }

    /** @return array{org:int,site:?int,type:string,id:string} */
    private function authority(): array
    {
        Permissions::forget();
        $org = $this->context->orgId();
        $actor = Permissions::currentUser();
        if ($org === null || $actor === null || ! Permissions::isOwner($actor)) {
            throw new RetentionRefused;
        }
        $id = $actor->getAuthIdentifier();
        if (! is_int($id) && ! is_string($id)) {
            throw new RetentionRefused;
        }

        return ['org' => $org, 'site' => $this->context->siteId(),
            'type' => $actor instanceof Model ? $actor->getMorphClass() : $actor::class, 'id' => (string) $id];
    }

    /** @param array{org:int,site:?int,type:string,id:string} $expected */
    private function assertAuthority(array $expected): void
    {
        if ($this->authority() !== $expected) {
            throw new RetentionRefused;
        }
    }

    /** @return array{audit:AuditLog,target_type:string} */
    private function audit(string $action, RetentionPolicySnapshot $policy): array
    {
        if ((new AuditLog)->getConnection() !== (new RetentionPolicy)->getConnection()) {
            throw new RetentionRefused;
        }
        $target = new RetentionPolicy;
        $type = $target->getMorphClass();
        $model = $target->newFromBuilder(['id' => $policy->id, 'org_id' => $policy->orgId]);

        return ['audit' => $this->auditor->recordOrFail($action, $model), 'target_type' => $type];
    }

    /** Called last, after all callback-capable authority checks and checked primary payload/pointer reads.
     * @param  array{org:int,site:?int,type:string,id:string}  $authority
     * @param  array{audit:AuditLog,target_type:string}  $record
     */
    private function assertAudit(Connection $connection, array $record, string $action, RetentionPolicySnapshot $policy, array $authority): void
    {
        $audit = $record['audit'];
        if (! $audit->exists || $audit->getConnection() !== $connection || $audit->getTable() !== AuditLog::TABLE || $audit->getKeyName() !== 'id'
            || (! is_int($audit->getKey()) && ! is_string($audit->getKey()))) {
            throw new RetentionRefused;
        }
        $row = $connection->table(AuditLog::TABLE)->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first();
        if ($row === null || (string) $row->id !== (string) $audit->getKey() || (int) $row->org_id !== $authority['org']
            || ($row->site_id === null ? null : (int) $row->site_id) !== $authority['site'] || $row->action !== $action
            || $row->target_type !== $record['target_type'] || $row->target_id !== (string) $policy->id
            || $row->actor_type !== $authority['type'] || $row->actor_id !== $authority['id']) {
            throw new RetentionRefused;
        }
    }

    /** @template T
     * @param  Closure():T  $work
     * @return T
     */
    private function safe(Closure $work): mixed
    {
        try {
            return $work();
        } catch (Throwable) {
            throw new RetentionRefused;
        }
    }
}
