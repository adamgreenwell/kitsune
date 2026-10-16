<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\OrderClaims;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** The signed-in owner's two unpaid-order actions; raw writes are bounded by checked custody and Org/Site locks. @internal */
final class OrderAdministration
{
    public function __construct(private readonly OrderLocks $locks, private readonly OrderReaderData $privacy, private readonly Auditor $auditor, private readonly Context $context) {}

    public function cancel(mixed $reference): void
    {
        abort_unless($this->ready(), 403);
        app(OrderWriter::class)->cancelAsOwner(self::reference($reference));
    }

    public function erase(mixed $reference): void
    {
        abort_unless($this->ready(), 403);
        $reference = self::reference($reference);

        try {
            $this->locks->run(function () use ($reference): void {
                abort_unless($this->ready(), 403);
                $connection = (new Order)->getConnection();
                $raw = $this->privacy->find($connection, $this->context->orgId(), $this->context->siteId(), $reference, lock: true)
                    ?? throw new OrderRefused;
                $this->privacy->record($connection, $raw);
                if ($this->privacy->eraseRecord($connection, $raw) !== 1) {
                    throw new OrderRefused;
                }
                $audit = $this->record($connection, 'commerce.order_erased', $raw);
                // An audit listener is application code too. It must not restore personal rows behind a successful erase.
                foreach ([AccessFulfillment::WORK, AccessFulfillment::EFFECTS, OrderClaims::TABLE,
                    FinancialState::WORK, FinancialState::REFUNDS, FinancialState::OBSERVATIONS,
                    AccessAdjustments::WORK, AccessAdjustments::EFFECTS] as $table) {
                    if ($connection->getSchemaBuilder()->hasTable($table)
                        && $connection->table($table)->useWritePdo()->where('order_id', $raw->id)->exists()) {
                        throw new OrderRefused;
                    }
                }
                if ($connection->table('commerce_orders')->useWritePdo()->where('id', $raw->id)
                    ->orWhere(static fn ($query) => $query->where('org_id', $raw->org_id)->where('site_id', $raw->site_id)->where('reference', $raw->reference))->exists()
                    || $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $raw->id)->exists()
                    || $connection->table('commerce_order_access_tokens')->useWritePdo()->where('order_id', $raw->id)->exists()
                    || ($connection->getSchemaBuilder()->hasTable(CheckoutAttempts::TABLE)
                        && $connection->table(CheckoutAttempts::TABLE)->useWritePdo()->where('order_id', $raw->id)->exists())
                    || ! $this->ready() || $this->context->orgId() !== (int) $raw->org_id || $this->context->siteId() !== (int) $raw->site_id) {
                    throw new OrderRefused;
                }
                $this->assertRecorded($connection, $audit);
            });
        } catch (Throwable) {
            throw new OrderRefused;
        }
    }

    public static function reference(mixed $reference): string
    {
        abort_unless(is_string($reference) && preg_match('/\A[a-f0-9]{32}\z/D', $reference) === 1, 404);

        return $reference;
    }

    /** @return array{audit:AuditLog,identity:array<string,int|string|null>} */
    private function record(Connection $connection, string $action, #[\SensitiveParameter] object $raw): array
    {
        if ($connection !== (new AuditLog)->getConnection() || $connection->transactionLevel() < 1
            || ! $connection->getPdo()->inTransaction()) {
            throw new OrderRefused;
        }
        $target = new Order;
        $actor = $this->actorIdentity();
        // Freeze the intended identity before hydration or audit listeners can change either model or context.
        $identity = ['org_id' => (int) $raw->org_id, 'site_id' => (int) $raw->site_id,
            'action' => $action, 'target_type' => $target->getMorphClass(), 'target_id' => (string) $raw->id,
            'actor_type' => $actor['type'], 'actor_id' => $actor['id']];
        $audit = $this->auditor->recordOrFail($action, $target->newFromBuilder((array) $raw));
        if ($actor !== $this->actorIdentity()) {
            throw new OrderRefused;
        }

        return ['audit' => $audit, 'identity' => $identity];
    }

    /** The final primary read also detects a post-save listener that rewrites or deletes the stored audit.
     * @param  array{audit:AuditLog,identity:array<string,int|string|null>}  $record
     */
    private function assertRecorded(Connection $connection, array $record): void
    {
        $audit = $record['audit'];
        if (! $audit->exists || $audit->getConnection() !== $connection || $audit->getTable() !== AuditLog::TABLE
            || $audit->getKeyName() !== 'id' || (! is_int($audit->getKey()) && ! is_string($audit->getKey()))) {
            throw new OrderRefused;
        }
        $stored = $connection->table(AuditLog::TABLE)->useWritePdo()->where('id', $audit->getKey())->lockForUpdate()->first();
        if ($stored === null || (string) $stored->id !== (string) $audit->getKey()) {
            throw new OrderRefused;
        }
        foreach ($record['identity'] as $column => $expected) {
            $actual = in_array($column, ['org_id', 'site_id'], true) ? (int) $stored->{$column} : $stored->{$column};
            if ($actual !== $expected) {
                throw new OrderRefused;
            }
        }
    }

    /** @return array{type:string,id:string} */
    private function actorIdentity(): array
    {
        Permissions::forget();
        $actor = Permissions::currentUser() ?? throw new OrderRefused;
        $id = $actor->getAuthIdentifier();
        if (! is_int($id) && ! is_string($id)) {
            throw new OrderRefused;
        }

        return ['type' => $actor instanceof Model ? $actor->getMorphClass() : $actor::class, 'id' => (string) $id];
    }

    /** Authority can change in a host's audit listener and must be read again afterward.
     * @phpstan-impure
     */
    private function ready(): bool
    {
        return OrderResource::canAccess();
    }
}
