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
use Kitsune\Commerce\Privacy\PrivacyState;
use Kitsune\Core\Tenancy\Context;

/** Durable signature-verified hints, including the interval before a payment receipt exists. @internal */
final class FinancialSignals
{
    public const TABLE = 'commerce_financial_signals';

    public const TYPES = ['refund.created', 'refund.updated', 'refund.failed', 'charge.refunded',
        'charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed',
        'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'];

    private const COLUMNS = ['id', 'org_id', 'mode', 'event_id', 'event_type', 'charge_id', 'subject_id',
        'payload_hash', 'state', 'verified_at', 'received_at', 'resolved_at'];

    /** Called only after signature verification and inside the Org-first ingress transaction.
     * @param  array<string,mixed>  $event
     * @return array{id:int,duplicate:bool,state:string}
     */
    public function capture(Connection $connection, int $org, array $event, #[\SensitiveParameter] string $raw, CarbonImmutable $verifiedAt, ?string $verifiedAccountId = null): array
    {
        self::inside($connection, $org);
        $expected = ['org_id' => $org, 'mode' => 'test', 'event_id' => $event['id'], 'event_type' => $event['type'],
            'charge_id' => $event['financialChargeId'], 'subject_id' => $event['financialSubjectId'], 'payload_hash' => hash('sha256', $raw)];
        $rows = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)->where('mode', 'test')
            ->where('event_id', $event['id'])->limit(2)->lockForUpdate()->get();
        if ($rows->count() > 1) {
            throw new CheckoutRefused;
        }
        if ($rows->isNotEmpty()) {
            $row = $rows->sole();
            self::validate($row, $org);
            foreach ($expected as $column => $value) {
                if ((string) $row->{$column} !== (string) $value) {
                    throw new CheckoutRefused('changed');
                }
            }
            if (app(PrivacyState::class)->replayState($connection, $org, 'test', $expected['charge_id'], $verifiedAccountId)['purged'] ?? false) {
                return ['id' => 0, 'duplicate' => true, 'state' => 'erased'];
            }

            return ['id' => (int) $row->id, 'duplicate' => true, 'state' => $row->state];
        }
        if (app(PrivacyState::class)->replayState($connection, $org, 'test', $expected['charge_id'], $verifiedAccountId)['purged'] ?? false) {
            return ['id' => 0, 'duplicate' => false, 'state' => 'erased'];
        }
        if (count(self::rows($connection, $org, $expected['charge_id'], true)) >= StripeEventInbox::MAX_EVENTS_PER_ORDER) {
            throw new CheckoutRefused('limit');
        }
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $id = $connection->table(self::TABLE)->insertGetId([...$expected, 'state' => 'unresolved',
            'verified_at' => $verifiedAt->utc()->startOfSecond()->format('Y-m-d H:i:s'),
            'received_at' => $now->format('Y-m-d H:i:s'), 'resolved_at' => null]);
        $stored = $connection->table(self::TABLE)->useWritePdo()->where('id', $id)->lockForUpdate()->first() ?? throw new CheckoutRefused;
        self::validate($stored, $org);
        foreach ($expected as $column => $value) {
            if ((string) $stored->{$column} !== (string) $value) {
                throw new CheckoutRefused('changed');
            }
        }
        if ($stored->state !== 'unresolved' || $stored->resolved_at !== null) {
            throw new CheckoutRefused('changed');
        }

        return ['id' => (int) $id, 'duplicate' => false, 'state' => 'unresolved'];
    }

    /** All matching minimal receipts belong in the ledger custody digest, including previously resolved ones.
     * @return list<object>
     */
    public static function rows(Connection $connection, int $org, string $chargeId, bool $lock = false): array
    {
        if (app(Context::class)->orgId() !== $org || preg_match('/\Ach_[A-Za-z0-9_]{1,95}\z/D', $chargeId) !== 1
            || ! $connection->getSchemaBuilder()->hasColumns(self::TABLE, self::COLUMNS)) {
            throw new CheckoutRefused('setup');
        }
        $query = $connection->table(self::TABLE)->useWritePdo()->where('org_id', $org)->where('mode', 'test')
            ->where('charge_id', $chargeId)->orderBy('id')->limit(StripeEventInbox::MAX_EVENTS_PER_ORDER + 1);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->all();
        if (count($rows) > StripeEventInbox::MAX_EVENTS_PER_ORDER) {
            throw new CheckoutRefused;
        }
        foreach ($rows as $row) {
            self::validate($row, $org);
        }

        return $rows;
    }

    /** The financial ledger calls this only after its fenced complete original-account observation proof.
     * @param  array<string,mixed>  $graph
     */
    public static function resolve(Connection $connection, int $org, string $chargeId, array $graph): void
    {
        self::inside($connection, $org);
        foreach (self::rows($connection, $org, $chargeId, true) as $row) {
            $kind = str_starts_with($row->event_type, 'refund.') ? 'refunds' : 'disputes';
            $present = $row->event_type === 'charge.refunded'
                ? ($graph['charge']['id'] ?? null) === $chargeId
                : in_array($row->subject_id, array_column($graph[$kind] ?? [], 'id'), true);
            if ($row->state === 'unresolved' && $present) {
                if ($connection->table(self::TABLE)->where('id', $row->id)->where('org_id', $org)->where('state', 'unresolved')
                    ->update(['state' => 'resolved', 'resolved_at' => CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s')]) !== 1) {
                    throw new CheckoutRefused;
                }
            }
        }
        self::rows($connection, $org, $chargeId, true);
    }

    private static function inside(Connection $connection, int $org): void
    {
        if ($connection->transactionLevel() < 1 || app(Context::class)->orgId() !== $org) {
            throw new CheckoutRefused;
        }
    }

    private static function validate(object $row, int $org): void
    {
        if ((string) $row->org_id !== (string) $org || $row->mode !== 'test' || ! in_array($row->event_type, self::TYPES, true)
            || preg_match('/\Aevt_[A-Za-z0-9_]{1,251}\z/D', $row->event_id) !== 1
            || preg_match('/\Ach_[A-Za-z0-9_]{1,95}\z/D', $row->charge_id) !== 1
            || preg_match('/\A'.(str_starts_with($row->event_type, 'refund.') ? 're_'
                : (str_starts_with($row->event_type, 'charge.dispute.') ? 'du_' : 'ch_')).'[A-Za-z0-9_]{1,95}\z/D', $row->subject_id) !== 1
            || ($row->event_type === 'charge.refunded' && $row->subject_id !== $row->charge_id)
            || preg_match('/\A[a-f0-9]{64}\z/D', $row->payload_hash) !== 1
            || ! in_array($row->state, ['unresolved', 'resolved'], true)
            || ($row->state === 'resolved') !== ($row->resolved_at !== null)
            || CheckoutAttempts::instant($row->received_at)->diffInSeconds(CheckoutAttempts::instant($row->verified_at), true) > 300) {
            throw new CheckoutRefused;
        }
        if ($row->resolved_at !== null && CheckoutAttempts::instant($row->resolved_at)->lessThan(CheckoutAttempts::instant($row->received_at))) {
            throw new CheckoutRefused;
        }
    }
}
