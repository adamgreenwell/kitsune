<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Commerce\Orders\BillingDetails;
use Kitsune\Commerce\Orders\PostalAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure input tests: no Testbench application, migrations, database or external service. */
final class OrderDetailsTest extends TestCase
{
    public function test_it_normalizes_only_the_declared_address_fields(): void
    {
        self::assertSame([
            'line1' => '10 Ocean Road', 'line2' => 'Suite 2', 'city' => 'Montréal', 'region' => 'QC',
            'postal_code' => 'H2Y 1C6', 'country' => 'CA',
        ], PostalAddress::from([
            'country' => ' CA ', 'postal_code' => ' H2Y 1C6 ', 'region' => ' QC ', 'city' => ' Montréal ',
            'line2' => ' Suite 2 ', 'line1' => ' 10 Ocean Road ',
        ]));
    }

    public function test_optional_address_parts_do_not_invent_country_specific_requirements(): void
    {
        self::assertSame([
            'line1' => 'Harbour', 'line2' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'country' => 'HK',
        ], PostalAddress::from(['line1' => 'Harbour', 'country' => 'HK']));
    }

    public function test_unicode_outer_spacing_is_trimmed_without_rewriting_internal_text(): void
    {
        $billing = BillingDetails::from([
            'name' => "\u{00A0}Zoë  李\u{2003}",
            'address' => ['line1' => "\u{2003}東京都  千代田区\u{00A0}", 'city' => '東京', 'country' => 'JP'],
        ]);

        self::assertSame('Zoë  李', $billing['name']);
        self::assertSame('東京都  千代田区', $billing['address']['line1']);
        self::assertSame('東京', $billing['address']['city']);
    }

    #[DataProvider('addressLimits')]
    public function test_address_limits_count_unicode_characters_after_trimming(string $field, int $maximum): void
    {
        $value = ['line1' => 'Harbour', 'country' => 'JP', $field => ' '.str_repeat('界', $maximum).' '];
        self::assertSame(str_repeat('界', $maximum), PostalAddress::from($value)[$field]);

        $value[$field] = str_repeat('界', $maximum + 1);
        $this->expectException(InvalidArgumentException::class);
        PostalAddress::from($value);
    }

    /** @return iterable<string,array{string,int}> */
    public static function addressLimits(): iterable
    {
        yield 'line1' => ['line1', 200];
        yield 'line2' => ['line2', 200];
        yield 'city' => ['city', 100];
        yield 'region' => ['region', 100];
        yield 'postal code' => ['postal_code', 20];
    }

    public function test_billing_name_has_its_own_unicode_character_bound(): void
    {
        $value = ['name' => ' '.str_repeat('é', 200).' ', 'address' => ['line1' => 'Harbour', 'country' => 'FR']];
        self::assertSame(str_repeat('é', 200), BillingDetails::from($value)['name']);

        $value['name'] = str_repeat('é', 201);
        $this->expectException(InvalidArgumentException::class);
        BillingDetails::from($value);
    }

    #[DataProvider('malformedAddresses')]
    public function test_it_refuses_malformed_addresses(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        PostalAddress::from($value);
    }

    /** @return iterable<string,array{mixed}> */
    public static function malformedAddresses(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['Harbour'];
        yield 'object' => [(object) ['line1' => 'Harbour', 'country' => 'US']];
        yield 'empty' => [[]];
        yield 'missing line1' => [['country' => 'US']];
        yield 'missing country' => [['line1' => 'Harbour']];
        yield 'blank line1' => [['line1' => " \u{2003} ", 'country' => 'US']];
        yield 'lowercase country' => [['line1' => 'Harbour', 'country' => 'us']];
        yield 'long country' => [['line1' => 'Harbour', 'country' => 'USA']];
        yield 'nonascii country' => [['line1' => 'Harbour', 'country' => 'ＵＳ']];
        yield 'unknown field' => [['line1' => 'Harbour', 'country' => 'US', 'tax_id' => 'private']];
        yield 'mis-cased field' => [['line1' => 'Harbour', 'country' => 'US', 'City' => 'Town']];
        yield 'numeric field' => [['line1' => 'Harbour', 'country' => 'US', 0 => 'private']];

        foreach (['line1', 'line2', 'city', 'region', 'postal_code', 'country'] as $field) {
            foreach ([null, 12, 1.5, true, [], new stdClass] as $i => $bad) {
                yield "{$field} invalid type {$i}" => [['line1' => 'Harbour', 'country' => 'US', $field => $bad]];
            }
        }
    }

    #[DataProvider('forbiddenText')]
    public function test_controls_and_malformed_utf8_are_refused_in_every_text_input(string $bad): void
    {
        foreach (['line1', 'line2', 'city', 'region', 'postal_code', 'country'] as $field) {
            $this->assertRefused(static fn () => PostalAddress::from(['line1' => 'Harbour', 'country' => 'US', $field => $bad]));
        }

        $this->assertRefused(static fn () => BillingDetails::from([
            'name' => $bad, 'address' => ['line1' => 'Harbour', 'country' => 'US'],
        ]));
    }

    /** @return iterable<string,array{string}> */
    public static function forbiddenText(): iterable
    {
        yield 'newline padding' => ["\nHarbour\n"];
        yield 'carriage return' => ["Harbour\rRoad"];
        yield 'tab padding' => ["\tHarbour\t"];
        yield 'nul' => ["Harbour\0Road"];
        yield 'delete control' => ["Harbour\x7fRoad"];
        yield 'unicode next line' => ["Harbour\u{0085}Road"];
        yield 'unicode line separator' => ["Harbour\u{2028}Road"];
        yield 'unicode paragraph separator' => ["Harbour\u{2029}Road"];
        yield 'malformed utf8' => ["Harbour\xc3\x28"];
        yield 'raw read bound' => [str_repeat(' ', 4097).'Harbour'];
    }

    #[DataProvider('malformedBillingDetails')]
    public function test_it_refuses_malformed_or_profile_shaped_billing_input(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        BillingDetails::from($value);
    }

    /** @return iterable<string,array{mixed}> */
    public static function malformedBillingDetails(): iterable
    {
        $address = ['line1' => 'Harbour', 'country' => 'US'];
        yield 'null' => [null];
        yield 'string' => ['Buyer'];
        yield 'object' => [(object) ['name' => 'Buyer', 'address' => $address]];
        yield 'missing name' => [['address' => $address]];
        yield 'missing address' => [['name' => 'Buyer']];
        yield 'blank name' => [['name' => " \u{00A0} ", 'address' => $address]];
        yield 'profile email' => [['name' => 'Buyer', 'address' => $address, 'email' => 'private@example.test']];
        yield 'reader key' => [['name' => 'Buyer', 'address' => $address, 'reader_id' => 'private']];
        yield 'shipping address' => [['name' => 'Buyer', 'address' => $address, 'shipping' => $address]];
        yield 'nested profile' => [['name' => 'Buyer', 'address' => [...$address, 'profile' => ['secret' => 'private']]]];

        foreach ([null, 12, 1.5, true, [], new stdClass] as $i => $bad) {
            yield "invalid name type {$i}" => [['name' => $bad, 'address' => $address]];
        }
    }

    public function test_refusals_never_repeat_the_supplied_personal_value(): void
    {
        foreach ([
            static fn () => PostalAddress::from(['line1' => "Private address\n", 'country' => 'US']),
            static fn () => BillingDetails::from(['name' => "Private name\n", 'address' => []]),
        ] as $call) {
            try {
                $call();
                self::fail('The personal value should be refused.');
            } catch (InvalidArgumentException $failure) {
                self::assertStringNotContainsString('Private', $failure->getMessage());
            }
        }
    }

    /** @param Closure():mixed $call */
    private function assertRefused(Closure $call): void
    {
        try {
            $call();
            self::fail('The input should be refused.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }
}
