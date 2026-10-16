<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Privacy;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Throwable;

/** Checked immutable operator configuration; not a buyer/export projection. @internal */
final readonly class RetentionPolicySnapshot
{
    public const SCHEMA_VERSION = 1;

    public const MAX_DAYS = 36500;

    public const MAX_YEARS = 100;

    public const LEGAL_BASIS_MAX_LENGTH = 500;

    public const REASON_MAX_LENGTH = 2000;

    public const CONFIRMATION_DAYS = 'confirmation_days';

    public const PAYMENT_YEAR_END = 'payment_year_end';

    public const BILLING_FIELDS = ['name', 'address.line1', 'address.line2', 'address.city', 'address.region', 'address.postal_code', 'address.country'];

    /** @param list<string> $billingFields */
    private function __construct(public int $id, public int $orgId, public int $version, public array $billingFields,
        public string $legalBasis, public string $reason, public string $clock, public int $duration,
        public ?string $timezone, public string $configHash, public string $createdAt) {}

    /** Input normalization has no legal/default duration or field profile.
     * @param  array<array-key,mixed>  $fields
     * @return array{schema_version:int,billing_fields:list<string>,legal_basis:string,reason:string,clock:string,duration:int,timezone:?string}
     */
    public static function configurationFor(array $fields, #[\SensitiveParameter] string $basis, #[\SensitiveParameter] string $reason, string $clock, int $duration, ?string $timezone): array
    {
        if (! array_is_list($fields) || count($fields) > count(self::BILLING_FIELDS) || count(array_unique($fields, SORT_REGULAR)) !== count($fields)) {
            throw new RetentionRefused;
        }
        foreach ($fields as $field) {
            if (! is_string($field) || ! in_array($field, self::BILLING_FIELDS, true)) {
                throw new RetentionRefused;
            }
        }
        $fields = array_values(array_intersect(self::BILLING_FIELDS, $fields));
        if (! in_array($clock, [self::CONFIRMATION_DAYS, self::PAYMENT_YEAR_END], true)
            || $duration < 0 || $duration > ($clock === self::CONFIRMATION_DAYS ? self::MAX_DAYS : self::MAX_YEARS)
            || ($clock === self::CONFIRMATION_DAYS ? $timezone !== null
                : (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)))) {
            throw new RetentionRefused;
        }

        return ['schema_version' => self::SCHEMA_VERSION, 'billing_fields' => $fields, 'legal_basis' => self::text($basis, self::LEGAL_BASIS_MAX_LENGTH),
            'reason' => self::text($reason, self::REASON_MAX_LENGTH), 'clock' => $clock, 'duration' => $duration, 'timezone' => $timezone];
    }

    /** An exact, independently checked frozen copy; current activation is deliberately irrelevant.
     * @param  array<string,mixed>  $snapshot
     */
    public static function fromSnapshot(#[\SensitiveParameter] array $snapshot): self
    {
        try {
            $keys = ['policy_id', 'org_id', 'version', 'configuration', 'config_hash', 'created_at'];
            if (count($snapshot) !== count($keys) || array_diff(array_keys($snapshot), $keys) !== []
                || ! is_array($snapshot['configuration'] ?? null)) {
                throw new RetentionRefused;
            }
            $config = $snapshot['configuration'];
            $configKeys = ['schema_version', 'billing_fields', 'legal_basis', 'reason', 'clock', 'duration', 'timezone'];
            if (count($config) !== count($configKeys) || array_diff(array_keys($config), $configKeys) !== []
                || ($config['schema_version'] ?? null) !== self::SCHEMA_VERSION || ! is_array($config['billing_fields'] ?? null)
                || ! is_string($config['legal_basis'] ?? null) || ! is_string($config['reason'] ?? null)
                || ! is_string($config['clock'] ?? null) || ! is_int($config['duration'] ?? null)
                || (! is_string($config['timezone']) && $config['timezone'] !== null)) {
                throw new RetentionRefused;
            }
            $normalized = self::configurationFor($config['billing_fields'], $config['legal_basis'], $config['reason'],
                $config['clock'], $config['duration'], $config['timezone']);
            foreach ($normalized as $key => $value) {
                if ($config[$key] !== $value) {
                    throw new RetentionRefused;
                }
            }
            if (! is_string($snapshot['config_hash'])
                || ! hash_equals(CheckoutAttempts::digest($config), $snapshot['config_hash']) || ! is_string($snapshot['created_at'])) {
                throw new RetentionRefused;
            }
            CheckoutAttempts::instant($snapshot['created_at']);

            return new self(self::integer($snapshot['policy_id']), self::integer($snapshot['org_id']), self::integer($snapshot['version']),
                $config['billing_fields'], $config['legal_basis'], $config['reason'], $config['clock'], $config['duration'],
                $config['timezone'], $snapshot['config_hash'], $snapshot['created_at']);
        } catch (Throwable) {
            throw new RetentionRefused;
        }
    }

    /** @return array{schema_version:int,billing_fields:list<string>,legal_basis:string,reason:string,clock:string,duration:int,timezone:?string} */
    public function configuration(): array
    {
        return ['schema_version' => self::SCHEMA_VERSION, 'billing_fields' => $this->billingFields, 'legal_basis' => $this->legalBasis,
            'reason' => $this->reason, 'clock' => $this->clock, 'duration' => $this->duration, 'timezone' => $this->timezone];
    }

    /** @return array{policy_id:int,org_id:int,version:int,configuration:array<string,mixed>,config_hash:string,created_at:string} */
    public function snapshot(): array
    {
        return ['policy_id' => $this->id, 'org_id' => $this->orgId, 'version' => $this->version,
            'configuration' => $this->configuration(), 'config_hash' => $this->configHash, 'created_at' => $this->createdAt];
    }

    /** The exclusive deletion instant. Calendar years use this version's zone, never a current Site setting. */
    public function deadline(DateTimeInterface $firstConfirmedAt): CarbonImmutable
    {
        try {
            $confirmed = CarbonImmutable::instance($firstConfirmedAt)->utc()->startOfSecond();
            $deadline = $this->clock === self::CONFIRMATION_DAYS ? $confirmed->addDays($this->duration)
                : $confirmed->setTimezone($this->timezone)->startOfYear()->addYears($this->duration + 1)->utc();
            if ($confirmed->year < 1 || $confirmed->year > 9999 || $deadline->year < 1 || $deadline->year > 9999) {
                throw new RetentionRefused;
            }

            return $deadline;
        } catch (Throwable) {
            throw new RetentionRefused;
        }
    }

    public static function integer(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string) $value) !== 1
            || strlen((string) $value) > strlen((string) PHP_INT_MAX)
            || (strlen((string) $value) === strlen((string) PHP_INT_MAX) && strcmp((string) $value, (string) PHP_INT_MAX) > 0)) {
            throw new RetentionRefused;
        }

        return (int) $value;
    }

    private static function text(#[\SensitiveParameter] string $value, int $limit): string
    {
        if (strlen($value) > $limit * 4 || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $value) === 1) {
            throw new RetentionRefused;
        }
        $value = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $value);
        if ($value === null || $value === '' || mb_strlen($value) > $limit) {
            throw new RetentionRefused;
        }

        return $value;
    }
}
