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
use Illuminate\Support\Collection;
use Kitsune\Commerce\Carts\CartAmounts;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Commerce\Privacy\PrivacyState;
use stdClass;
use Throwable;

/** Bounded, raw, exact-order custody. A local expiry is never evidence that Stripe stopped accepting payment. @internal */
final class CheckoutAttempts
{
    public const MAX_ATTEMPTS = 20;

    public const TABLE = 'commerce_checkout_attempts';

    private const MIGRATION = '0001_01_01_000206_create_commerce_checkout_attempts';

    private const COLUMNS = ['id', 'org_id', 'site_id', 'order_id', 'reference', 'active_order_id', 'mode', 'currency',
        'account_id', 'api_version', 'credential_witness', 'state', 'request_payload', 'request_hash', 'order_hash', 'session_id',
        'checkout_url', 'reviewed_at', 'expires_at', 'lease_token', 'lease_until', 'created_at', 'updated_at'];

    /** @return list<array<string,mixed>> */
    public function projection(Connection $connection, int $org, object $order, bool $lock = false): array
    {
        return $this->rows($connection, $org, $order, $lock)->map(static fn (stdClass $row): array => [
            'reference' => $row->reference, 'state' => $row->state, 'mode' => $row->mode, 'currency' => $row->currency,
            'total_minor' => MinorUnits::integer($order->quoted_total_minor), 'reviewed_at' => self::instant($row->reviewed_at)->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => self::instant($row->expires_at)->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => self::instant($row->updated_at)->format('Y-m-d\TH:i:s\Z'),
        ])->all();
    }

    public function assertErasable(Connection $connection, int $org, object $order, bool $lock = true): void
    {
        if ($connection->transactionLevel() < 1 || ! $lock) {
            throw new CheckoutRefused;
        }
        foreach ($this->rows($connection, $org, $order, true) as $row) {
            // A legacy completed test attempt may already represent provider-confirmed payment. Retain its graph
            // for the paid confirmation/recovery path instead of treating absence of a local receipt as no payment.
            if (! in_array($row->state, ['expired', 'rejected'], true)) {
                throw new CheckoutRefused('pending');
            }
        }
    }

    public function deleteTerminal(Connection $connection, int $org, object $order): int
    {
        $this->assertErasable($connection, $org, $order);
        app(StripeEventInbox::class)->forgetOrder($connection, $org, $order);
        $rows = $this->rows($connection, $org, $order, true);
        foreach ($rows as $row) {
            if ($connection->table(self::TABLE)->where('id', $row->id)->where('org_id', $org)->where('site_id', $order->site_id)
                ->where('order_id', $order->id)->where('state', $row->state)->delete() !== 1) {
                throw new CheckoutRefused;
            }
        }
        if ($rows->isNotEmpty() && $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->exists()) {
            throw new CheckoutRefused;
        }

        return $rows->count();
    }

    /** Caller has already proved the order, Org and Site. Query by parent first so malformed foreign children cannot hide.
     * @return Collection<int,stdClass>
     */
    public function rows(Connection $connection, int $org, object $order, bool $lock = false): Collection
    {
        try {
            $schema = $connection->getSchemaBuilder();
            if (! $schema->hasTable(self::TABLE)) {
                if ($schema->hasTable('migrations') && $connection->table('migrations')->useWritePdo()->where('migration', self::MIGRATION)->exists()) {
                    throw new CheckoutRefused;
                }

                return collect();
            }
            if (! $schema->hasColumns(self::TABLE, self::COLUMNS)) {
                throw new CheckoutRefused;
            }
            $query = $connection->table(self::TABLE)->useWritePdo()->where('order_id', $order->id)->orderBy('id')->limit(self::MAX_ATTEMPTS + 1);
            $rows = ($lock ? $query->lockForUpdate() : $query)->get();
            if ($rows->count() > self::MAX_ATTEMPTS) {
                throw new CheckoutRefused;
            }
            $active = 0;
            foreach ($rows as $row) {
                $this->validate($row, $org, $order);
                $active += $row->active_order_id === null ? 0 : 1;
            }
            if ($active > 1) {
                throw new CheckoutRefused;
            }

            app(StripeEventInbox::class)->assertOrderCustody($connection, $org, $order, $lock);

            return $rows;
        } catch (Throwable) {
            throw new CheckoutRefused;
        }
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public static function payload(array $record, string $attempt, int $expires, string $base): array
    {
        $items = [];
        foreach ($record['lines'] as $line) {
            // Stripe receives each exact extended gross amount once; multiplying rounded unit taxes would drift.
            $items[] = ['price_data' => ['currency' => strtolower($record['currency']), 'unit_amount' => StripeAmounts::toWire($line['gross_minor'], $record['currency']),
                'product_data' => ['name' => mb_substr($line['title'], 0, 200).' × '.$line['quantity']], 'tax_behavior' => 'inclusive'], 'quantity' => 1];
        }
        if ($record['shipping'] !== null) {
            $items[] = ['price_data' => ['currency' => strtolower($record['currency']), 'unit_amount' => StripeAmounts::toWire($record['shipping']['gross'], $record['currency']),
                'product_data' => ['name' => mb_substr('Shipping — '.$record['shipping']['carrier'].' '.$record['shipping']['service'], 0, 255)], 'tax_behavior' => 'inclusive'], 'quantity' => 1];
        }

        return ['mode' => 'payment', 'ui_mode' => 'hosted_page', 'allowed_payment_method_types' => ['card'],
            'customer_creation' => 'if_required', 'automatic_tax' => ['enabled' => false], 'adaptive_pricing' => ['enabled' => false],
            'line_items' => $items, 'client_reference_id' => $record['reference'],
            'metadata' => ['kitsune_attempt' => $attempt, 'kitsune_order' => $record['reference']], 'expires_at' => $expires,
            'success_url' => $base.'/cart/orders/'.$record['reference'].'/payment/return',
            'cancel_url' => $base.'/cart/orders/'.$record['reference'].'/payment/cancel'];
    }

    /** Object key order is not preserved by MySQL JSON; list order and scalar types remain significant.
     * @param  array<string,mixed>  $value
     */
    public static function digest(#[\SensitiveParameter] array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    public static function request(object $row): array
    {
        if (! is_string($row->request_payload ?? null) || strlen($row->request_payload) > 65536) {
            throw new CheckoutRefused;
        }
        $payload = json_decode($row->request_payload, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($payload) || array_is_list($payload) || ! is_string($row->request_hash) || ! hash_equals(self::digest($payload), $row->request_hash)) {
            throw new CheckoutRefused;
        }

        return $payload;
    }

    public static function instant(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value) !== 1) {
            throw new CheckoutRefused;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d H:i:s') !== $value) {
            throw new CheckoutRefused;
        }

        return $date;
    }

    private function validate(object $row, int $org, object $order): void
    {
        if ((string) $row->org_id !== (string) $org || (string) $row->site_id !== (string) $order->site_id
            || (string) $row->order_id !== (string) $order->id || ! in_array($order->mode, ['test', 'live'], true) || $row->mode !== $order->mode
            || $row->currency !== $order->currency || ! is_string($row->api_version)
            || preg_match('/\A20[0-9]{2}-[0-9]{2}-[0-9]{2}\.[a-z]{1,16}\z/D', $row->api_version) !== 1
            || ! self::hex($row->reference, 32) || ! self::hex($row->credential_witness, 64)
            || ! self::hex($row->request_hash, 64) || ! self::hex($row->order_hash, 64)
            || ! is_string($row->account_id) || preg_match('/\Aacct_[A-Za-z0-9]{1,95}\z/D', $row->account_id) !== 1
            || ! in_array($row->state, ['creating', 'open', 'uncertain', 'expired', 'completed', 'rejected'], true)) {
            throw new CheckoutRefused;
        }
        $pending = in_array($row->state, ['creating', 'open', 'uncertain'], true);
        if ($pending ? (string) $row->active_order_id !== (string) $order->id : $row->active_order_id !== null) {
            throw new CheckoutRefused;
        }
        $reviewed = self::instant($row->reviewed_at);
        $expires = self::instant($row->expires_at);
        if ($expires->timestamp - $reviewed->timestamp < 1800 || $expires->timestamp - $reviewed->timestamp > 1860
            || self::instant($row->created_at)->timestamp !== $reviewed->timestamp || self::instant($row->updated_at)->lessThan($reviewed)
            || ($row->lease_token === null) !== ($row->lease_until === null)
            || ($row->lease_token !== null && (! self::hex($row->lease_token, 64) || self::instant($row->lease_until)->lessThan($reviewed)))) {
            throw new CheckoutRefused;
        }
        if (($row->session_id !== null && (! is_string($row->session_id) || ! StripeMode::session($row->session_id, $row->mode)))
            || (in_array($row->state, ['open', 'expired', 'completed'], true) && $row->session_id === null)
            || ($row->state === 'rejected' && $row->session_id !== null)
            || ($row->checkout_url !== null && ! self::url($row->checkout_url, stripe: true))
            || ($row->state === 'open' && $row->checkout_url === null && ! (app(PrivacyState::class)->checked((new Order)->getConnection(), $order)['subject_erased'] ?? false) && ! (app(PrivacyState::class)->checked((new Order)->getConnection(), $order)['invoice_expired'] ?? false))) {
            throw new CheckoutRefused;
        }
        $payload = self::request($row);
        $keys = ['mode', 'ui_mode', 'allowed_payment_method_types', 'customer_creation', 'automatic_tax', 'adaptive_pricing',
            'line_items', 'client_reference_id', 'metadata', 'expires_at', 'success_url', 'cancel_url'];
        if (count($payload) !== count($keys) || array_diff(array_keys($payload), $keys) !== []
            || $payload['mode'] !== 'payment' || $payload['ui_mode'] !== 'hosted_page' || $payload['allowed_payment_method_types'] !== ['card']
            || $payload['customer_creation'] !== 'if_required' || $payload['automatic_tax'] !== ['enabled' => false]
            || $payload['adaptive_pricing'] !== ['enabled' => false] || $payload['client_reference_id'] !== $order->reference
            || ! is_array($payload['metadata']) || count($payload['metadata']) !== 2
            || ($payload['metadata']['kitsune_attempt'] ?? null) !== $row->reference || ($payload['metadata']['kitsune_order'] ?? null) !== $order->reference
            || $payload['expires_at'] !== $expires->timestamp || ! is_array($payload['line_items']) || ! array_is_list($payload['line_items'])
            || count($payload['line_items']) < 1 || count($payload['line_items']) > 21) {
            throw new CheckoutRefused;
        }
        foreach (['success_url' => 'return', 'cancel_url' => 'cancel'] as $key => $suffix) {
            if (! self::url($payload[$key]) || ! str_ends_with($payload[$key], '/cart/orders/'.$order->reference.'/payment/'.$suffix)) {
                throw new CheckoutRefused;
            }
        }
        $total = '0';
        foreach ($payload['line_items'] as $item) {
            if (! is_array($item) || count($item) !== 2 || ($item['quantity'] ?? null) !== 1
                || ! is_array($price = $item['price_data'] ?? null) || count($price) !== 4 || ($price['currency'] ?? null) !== strtolower($order->currency)
                || ($price['tax_behavior'] ?? null) !== 'inclusive' || ! is_array($name = $price['product_data'] ?? null)
                || count($name) !== 1 || ! is_string($name['name'] ?? null) || ! mb_check_encoding($name['name'], 'UTF-8')
                || mb_strlen($name['name']) < 1 || mb_strlen($name['name']) > 255 || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $name['name']) === 1) {
                throw new CheckoutRefused;
            }
            $total = CartAmounts::add($total, MinorUnits::integer($price['unit_amount'] ?? null));
        }
        if ($total === '0' || $total !== StripeAmounts::toWire($order->quoted_total_minor, $order->currency)) {
            throw new CheckoutRefused;
        }
    }

    private static function hex(mixed $value, int $length): bool
    {
        return is_string($value) && strlen($value) === $length && preg_match('/\A[a-f0-9]+\z/D', $value) === 1;
    }

    private static function url(mixed $value, bool $stripe = false): bool
    {
        if (! is_string($value) || strlen($value) > ($stripe ? 4096 : 2048) || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return false;
        }
        $url = parse_url($value);

        return is_array($url) && isset($url['host'], $url['scheme']) && ! isset($url['user']) && ! isset($url['pass'])
            && (! isset($url['query']) || $stripe) && (! isset($url['fragment']) || $stripe)
            && ($stripe ? ($url['scheme'] === 'https' && $url['host'] === 'checkout.stripe.com' && ! isset($url['port']))
                : in_array($url['scheme'], ['http', 'https'], true));
    }

    private static function canonical(#[\SensitiveParameter] mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            $item = self::canonical($item);
        }
        unset($item);

        return $value;
    }
}
