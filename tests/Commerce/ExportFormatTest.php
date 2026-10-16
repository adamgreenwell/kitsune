<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Commerce\Exports\ExportFormat;
use Kitsune\Commerce\Exports\ExportRefused;

/** @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function exportFormatRecord(array $data = []): array
{
    return ['schema' => 'kitsune.commerce.export', 'version' => 1, 'type' => 'line', 'org' => 'publisher',
        'site' => 'golfdom', 'order_reference' => str_repeat('a', 32), 'customer_reference' => 'customer-1',
        'reference' => 'line-1', 'parent_reference' => null, 'data' => $data];
}

/** @return array<string,string> */
function exportFormatCsvRow(ExportFormat $format, array $record): array
{
    $header = str_getcsv($format->header(), escape: '');
    $row = str_getcsv($format->encode($record), escape: '');

    return array_combine($header, $row);
}

it('preserves typed JSONL values and original text without numeric coercion or embedded record breaks', function (): void {
    $format = new ExportFormat('jsonl');
    $data = ['title' => " \xEF\xBB\xBF=HYPERLINK(\"https://example.test\")\nSecond line, Café 🦊",
        'amount_minor' => '000123456789012345678901234567890', 'quantity' => 3, 'has_more' => false,
        'next_cursor' => null, 'tax_rate_parts' => 1.0];
    $encoded = $format->encode(exportFormatRecord($data));
    $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

    expect($format->header())->toBe('')->and(substr_count($encoded, "\n"))->toBe(1)
        ->and($decoded['data']['title'])->toBe($data['title'])
        ->and($decoded['data']['amount_minor'])->toBe($data['amount_minor'])
        ->and($decoded['data']['quantity'])->toBe(3)->and($decoded['data']['has_more'])->toBeFalse()
        ->and($decoded['data']['next_cursor'])->toBeNull()->and($decoded['data']['tax_rate_parts'])->toBe(1.0)
        ->and($decoded)->not->toHaveKey('spreadsheet_escaped_fields');
});

it('keeps empty data an object and accepts the documented record kinds', function (string $type): void {
    $record = exportFormatRecord();
    $record['type'] = $type;
    $decoded = json_decode((new ExportFormat('jsonl'))->encode($record), flags: JSON_THROW_ON_ERROR);
    expect($decoded->type)->toBe($type)->and($decoded->data)->toBeInstanceOf(stdClass::class);
})->with(ExportFormat::TYPES);

it('uses one stable wide CSV header and safely round-trips quotes separators newlines and lexical amounts', function (): void {
    $format = new ExportFormat('csv');
    $text = "Course, \"A\"\r\nRésumé 🦊";
    $record = exportFormatRecord(['title' => $text, 'amount_minor' => '000123456789012345678901234567890',
        'quantity' => 3, 'has_more' => false, 'billing_included' => true, 'next_cursor' => null, 'billing_value' => '']);
    $row = exportFormatCsvRow($format, $record);

    expect(count($row))->toBe(10 + count(ExportFormat::DATA_COLUMNS))
        ->and($row['title'])->toBe($text)->and($row['amount_minor'])->toBe($record['data']['amount_minor'])
        ->and($row['quantity'])->toBe('3')->and($row['has_more'])->toBe('false')
        ->and($row['billing_included'])->toBe('true')->and($row['next_cursor'])->toBe('')
        ->and($row['billing_value'])->toBe('')->and($row['spreadsheet_escaped_fields'])->toBe('')
        ->and($format->header())->toEndWith("\r\n")->not->toStartWith("\xEF\xBB\xBF")
        ->and($format->encode($record))->toEndWith("\r\n");
});

it('guards formula-like strings including hidden prefixes and names every reversible transformation', function (string $text): void {
    $format = new ExportFormat('csv');
    $record = exportFormatRecord(['title' => $text, 'sku' => $text, 'billing_value' => $text]);
    $row = exportFormatCsvRow($format, $record);
    foreach (['title', 'sku', 'billing_value'] as $column) {
        expect($row[$column])->toBe("'".$text)->and(substr($row[$column], 1))->toBe($text);
    }
    expect($row['spreadsheet_escaped_fields'])->toBe('sku,title,billing_value')
        ->and(json_decode((new ExportFormat('jsonl'))->encode($record), true, flags: JSON_THROW_ON_ERROR)['data']['title'])->toBe($text);
})->with(['equals' => '=1+1', 'plus' => '+SUM(A1:A2)', 'minus' => '-42', 'at' => '@SUM(A1:A2)',
    'spaces' => '   =1', 'tab' => "\t@SUM(A1:A2)", 'newlines' => "\r\n+1", 'BOM' => "\xEF\xBB\xBF=1",
    'mixed BOM and whitespace' => " \xEF\xBB\xBF\t-1", 'NUL and escape' => "\0\x1B=1",
    'Unicode space and invisible control' => "\u{2003}\u{200B}@SUM(A1:A2)"]);

it('guards envelope text and does not over-escape ordinary text or numeric scalars', function (): void {
    $format = new ExportFormat('csv');
    $record = exportFormatRecord(['title' => "'=already quoted", 'sku' => 'Course - A', 'quantity' => -1, 'amount_minor' => '-00042']);
    $record['org'] = '=Org';
    $record['reference'] = '@record';
    $row = exportFormatCsvRow($format, $record);
    expect($row['org'])->toBe("'=Org")->and($row['reference'])->toBe("'@record")
        ->and($row['title'])->toBe("'=already quoted")->and($row['sku'])->toBe('Course - A')
        ->and($row['quantity'])->toBe('-1')->and($row['amount_minor'])->toBe("'-00042")
        ->and($row['spreadsheet_escaped_fields'])->toBe('org,reference,amount_minor');
});

it('orders data keys consistently independently of caller insertion order', function (): void {
    $record = exportFormatRecord(['billing_value' => 'A', 'amount_minor' => '20', 'title' => 'Course', 'quantity' => 1]);
    $other = $record;
    $other['data'] = array_reverse($record['data'], true);
    foreach (ExportFormat::FORMATS as $kind) {
        $format = new ExportFormat($kind);
        expect($format->encode($record))->toBe($format->encode($other));
    }
});

it('refuses unsupported formats without exposing the supplied value', function (string $format): void {
    expect(fn () => new ExportFormat($format))->toThrow(ExportRefused::class);
})->with(['JSONL', 'json', 'xlsx', '', 'embedded NUL' => "csv\0secret"]);

it('refuses malformed or widened envelopes in either format', function (string $column, mixed $value): void {
    $record = exportFormatRecord();
    $record[$column] = $value;
    foreach (ExportFormat::FORMATS as $kind) {
        expect(fn () => (new ExportFormat($kind))->encode($record))->toThrow(ExportRefused::class);
    }
})->with([
    ['schema', 'other'], ['version', '1'], ['version', 2], ['type', 'raw_payload'], ['type', null],
    ['org', ''], ['org', 4], ['org', "wrong\nOrg"], ['site', ''], ['site', 1], 'invalid UTF8 reference' => ['reference', "\xFF"],
    ['data', 'raw'], ['data', new stdClass], ['raw_payload', 'private'], ['spreadsheet_escaped_fields', 'title'],
]);

it('requires each nullable envelope key and accepts null identities', function (): void {
    $record = exportFormatRecord();
    foreach (['site', 'order_reference', 'customer_reference', 'reference', 'parent_reference'] as $column) {
        $record[$column] = null;
    }
    expect(json_decode((new ExportFormat('jsonl'))->encode($record), true, flags: JSON_THROW_ON_ERROR)['site'])->toBeNull();
    foreach (array_keys($record) as $column) {
        $missing = $record;
        unset($missing[$column]);
        expect(fn () => (new ExportFormat('jsonl'))->encode($missing))->toThrow(ExportRefused::class);
    }
});

it('refuses unknown nested nonfinite invalid UTF8 and coerced monetary data', function (array $data): void {
    foreach (ExportFormat::FORMATS as $kind) {
        expect(fn () => (new ExportFormat($kind))->encode(exportFormatRecord($data)))->toThrow(ExportRefused::class);
    }
})->with([
    [['raw_payload' => 'private']], [['reader_id' => '123']], [['title' => ['nested']]], [['title' => new stdClass]],
    'invalid UTF8 text' => [['title' => "\xFF"]], [['tax_rate_parts' => INF]], [['tax_rate_parts' => NAN]], [['amount_minor' => 123]],
    [['amount_minor' => 1.23]], [['amount_minor' => true]], [['amount_minor' => '1.23']], [['amount_minor' => '1e4']],
    [['amount_minor' => '+42']], [['amount_minor' => '42 ']], [['amount_minor' => '']], [['title']],
]);

it('never serializes a resource or mutates the caller record', function (): void {
    $resource = fopen('php://memory', 'w+b');
    try {
        expect(fn () => (new ExportFormat('csv'))->encode(exportFormatRecord(['title' => $resource])))->toThrow(ExportRefused::class);
    } finally {
        fclose($resource);
    }
    $record = exportFormatRecord(['title' => '=1', 'amount_minor' => '42']);
    $original = $record;
    (new ExportFormat('csv'))->encode($record);
    expect($record)->toBe($original);
});
