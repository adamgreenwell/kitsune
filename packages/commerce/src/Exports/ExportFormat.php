<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Exports;

use JsonException;

/** Serializes the checked canonical ledger; it never discovers or authorizes records. @internal */
final class ExportFormat
{
    public const FORMATS = ['jsonl', 'csv'];

    public const TYPES = ['manifest', 'customer', 'order', 'line', 'shipping', 'payment', 'free_completion', 'free_access_correction', 'allocation',
        'automatic_refund', 'refund_attempt', 'refund', 'refund_selection', 'financial_observation',
        'access_claim', 'access', 'access_effect', 'financial_access', 'financial_access_effect', 'privacy', 'billing', 'complete'];

    public const DATA_COLUMNS = ['profile', 'generated_at', 'limit', 'has_more', 'next_cursor', 'count', 'max_id',
        'customer_kind', 'state', 'category', 'origin', 'purpose', 'mode', 'currency', 'tax_mode',
        'completed_at', 'revision', 'unit_number', 'created_at', 'cancelled_at', 'updated_at', 'confirmed_at', 'verified_at', 'received_at', 'processed_at',
        'reviewed_at', 'expires_at', 'observed_at', 'claimed_at', 'minimized_at', 'retained_until', 'raw_expires_at',
        'site_name', 'site_base_url', 'sku', 'title', 'quantity', 'line_reference', 'sellable_reference',
        'listing_reference', 'entry_reference', 'unit_price_minor', 'tax_class', 'tax_rate_parts', 'delivery_kind',
        'net_minor', 'tax_minor', 'gross_minor', 'quoted_item_net_minor', 'quoted_shipping_net_minor',
        'quoted_tax_minor', 'quoted_total_minor', 'confirmed_total_minor', 'carrier', 'service', 'amount_minor',
        'owed_minor', 'item_net_minor', 'item_tax_minor', 'shipping_net_minor', 'shipping_tax_minor', 'awarded',
        'unavailable', 'allocation_state', 'reason', 'attempt_reference', 'kind', 'offer_kind', 'offer_duration_days',
        'entitlement', 'subject_erased', 'invoice_expired', 'policy_version', 'delivery_pending', 'legal_hold',
        'billing_included', 'billing_field', 'billing_value'];

    private const ENVELOPE_COLUMNS = ['schema', 'version', 'type', 'org', 'site', 'order_reference',
        'customer_reference', 'reference', 'parent_reference'];

    public function __construct(private readonly string $format)
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new ExportRefused;
        }
    }

    public function header(): string
    {
        return $this->format === 'csv'
            ? self::csv([...self::ENVELOPE_COLUMNS, ...self::DATA_COLUMNS, 'spreadsheet_escaped_fields']) : '';
    }

    /** @param array<string,mixed> $record */
    public function encode(array $record): string
    {
        $record = $this->checked($record);
        if ($this->format === 'jsonl') {
            // Empty data is still an object; integer-string amounts never pass through a numeric conversion.
            $record['data'] = (object) $record['data'];
            try {
                return json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
            } catch (JsonException) {
                throw new ExportRefused;
            }
        }

        $values = [];
        $escaped = [];
        foreach (self::ENVELOPE_COLUMNS as $column) {
            $values[] = self::cell($record[$column], $column, $escaped);
        }
        foreach (self::DATA_COLUMNS as $column) {
            $values[] = self::cell($record['data'][$column] ?? null, $column, $escaped);
        }
        $values[] = implode(',', $escaped);

        return self::csv($values);
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function checked(array $record): array
    {
        $keys = array_keys($record);
        $expected = [...self::ENVELOPE_COLUMNS, 'data'];
        sort($keys);
        sort($expected);
        if ($keys !== $expected || $record['schema'] !== 'kitsune.commerce.export' || $record['version'] !== 1
            || ! is_string($record['type']) || ! in_array($record['type'], self::TYPES, true)
            || ! self::reference($record['org']) || ! is_array($record['data'])) {
            throw new ExportRefused;
        }
        foreach (['site', 'order_reference', 'customer_reference', 'reference', 'parent_reference'] as $column) {
            if ($record[$column] !== null && ! self::reference($record[$column])) {
                throw new ExportRefused;
            }
        }
        foreach ($record['data'] as $column => $value) {
            if (! is_string($column) || ! in_array($column, self::DATA_COLUMNS, true)
                || ($value !== null && ! is_scalar($value)) || (is_float($value) && ! is_finite($value))
                || (is_string($value) && preg_match('//u', $value) !== 1)
                || ($value !== null && str_ends_with($column, '_minor')
                    && (! is_string($value) || preg_match('/\A-?[0-9]+\z/D', $value) !== 1))) {
                throw new ExportRefused;
            }
        }

        $ordered = [];
        foreach (self::ENVELOPE_COLUMNS as $column) {
            $ordered[$column] = $record[$column];
        }
        $ordered['data'] = [];
        foreach (self::DATA_COLUMNS as $column) {
            if (array_key_exists($column, $record['data'])) {
                $ordered['data'][$column] = $record['data'][$column];
            }
        }

        return $ordered;
    }

    private static function reference(mixed $value): bool
    {
        return is_string($value) && $value !== '' && preg_match('//u', $value) === 1
            && preg_match('/[\p{Cc}]/u', $value) === 0;
    }

    /** @param list<string> $escaped */
    private static function cell(mixed $value, string $column, array &$escaped): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            // Whitespace, byte-order marks and invisible controls must not hide a spreadsheet formula prefix.
            if (preg_match('/\A[\s\p{Z}\p{Cc}\p{Cf}]*[=+\-@]/u', $value) === 1) {
                $escaped[] = $column;

                return "'".$value;
            }

            return $value;
        }

        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @param list<string> $values */
    private static function csv(array $values): string
    {
        // In-memory quoting avoids a temporary plaintext file even for a large record.
        return implode(',', array_map(static fn (string $value): string => '"'.str_replace('"', '""', $value).'"', $values))."\r\n";
    }
}
