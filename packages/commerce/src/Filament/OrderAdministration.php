<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament;

use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Orders\OrderWriter;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Core\Audit\Auditor;
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
                $this->record('commerce.order_erased', $raw);
                // An audit listener is application code too. It must not restore personal rows behind a successful erase.
                if ($connection->table('commerce_orders')->useWritePdo()->where('id', $raw->id)
                    ->orWhere(static fn ($query) => $query->where('org_id', $raw->org_id)->where('site_id', $raw->site_id)->where('reference', $raw->reference))->exists()
                    || $connection->table('commerce_order_lines')->useWritePdo()->where('order_id', $raw->id)->exists()
                    || $connection->table('commerce_order_access_tokens')->useWritePdo()->where('order_id', $raw->id)->exists()
                    || ($connection->getSchemaBuilder()->hasTable(CheckoutAttempts::TABLE)
                        && $connection->table(CheckoutAttempts::TABLE)->useWritePdo()->where('order_id', $raw->id)->exists())
                    || ! $this->ready() || $this->context->orgId() !== (int) $raw->org_id || $this->context->siteId() !== (int) $raw->site_id) {
                    throw new OrderRefused;
                }
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

    private function record(string $action, #[\SensitiveParameter] object $raw): void
    {
        $model = (new Order)->newFromBuilder((array) $raw);
        if (! $this->auditor->recordOrFail($action, $model)->exists) {
            throw new OrderRefused;
        }
    }

    /** Authority can change in a host's audit listener and must be read again afterward.
     * @phpstan-impure
     */
    private function ready(): bool
    {
        return OrderResource::canAccess();
    }
}
