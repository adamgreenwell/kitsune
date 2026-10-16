<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kitsune\Commerce\Catalogue\CatalogueWriter;
use Kitsune\Commerce\Exports\ExportCursor;
use Kitsune\Commerce\Exports\ExportPage;
use Kitsune\Commerce\Exports\ExportRefused;
use Kitsune\Commerce\Exports\OrderHistoryExport;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutAttempts;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Readers\ReaderErasure;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture;
use Kitsune\Core\Tests\Fixtures\EntitlementFixture;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture;
use Kitsune\Core\Tests\Fixtures\OrderFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Kitsune\Core\Tests\Fixtures\ReaderFixture;
use Kitsune\Core\Tests\Fixtures\StripeInboxFixture;

beforeEach(function (): void {
    EntitlementFixture::boot();
    config(AppServiceProvider::readerConfig());
    config(['hashing.bcrypt.rounds' => 4, 'app.key' => 'base64:'.base64_encode(str_repeat('h', 32))]);
    CarbonImmutable::setTestNow('2026-10-16 12:00:00 UTC');
    Http::preventStrayRequests();
    $this->world = ReaderFixture::world();
    DB::table('orgs')->update(['currency' => 'USD']);
    $this->org = $this->world['golfdom'];
    ReaderFixture::mode($this->org, 'sign-in');
    foreach ($this->world['sites'] as $site) {
        app(Context::class)->setSite($site);
        $site->update(['base_url' => 'https://'.$site->handle.'.history-export.example.test']);
    }
    $this->site = $this->world['sites']['golfdom'];
    $this->reader = ReaderFixture::reader($this->org, 'history-reader@example.test');
    app(Context::class)->setSite($this->site);
    $this->owner = CredentialFixture::member(email: 'history-owner@example.test');
    StripeInboxFixture::enable();
    $this->exports = app(OrderHistoryExport::class);
});

afterEach(function (): void {
    Http::assertNothingSent();
    CarbonImmutable::setTestNow();
    Cookie::flushQueuedCookies();
    ReaderFixture::forget();
    EntitlementFixture::tearDown();
});

/** @return list<array<string,mixed>> */
function historyExportRows(ExportPage $page): array
{
    if ($page->format === 'jsonl') {
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            explode("\n", rtrim($page->body, "\n")));
    }
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $page->body);
    rewind($stream);
    $header = fgetcsv($stream, escape: '');
    $rows = [];
    while (($row = fgetcsv($stream, escape: '')) !== false) {
        $rows[] = array_combine($header, $row);
    }
    fclose($stream);

    return $rows;
}

/** @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function historyExportKind(array $rows, string $type): array
{
    return array_values(array_filter($rows, static fn (array $row): bool => $row['type'] === $type));
}

function historyExportPolicy(object $order, int $days = 1): void
{
    $policy = app(RetentionPolicies::class)->createAsOwner(['name', 'address.country'], 'Explicit invoice obligation',
        'Private policy explanation', RetentionPolicySnapshot::CONFIRMATION_DAYS, $days);
    app(OrderPrivacy::class)->adoptAsOwner($order->reference, $policy->version, 'Explicit history adoption', true);
}

it('returns a complete versioned empty artifact for an installed empty history', function (string $format): void {
    $page = $this->exports->forOwner($format);
    $rows = historyExportRows($page);
    expect($page->count)->toBe(0)->and($page->nextCursor)->toBeNull()
        ->and(array_column($rows, 'type'))->toBe(['manifest', 'complete'])
        ->and($rows[0]['schema'])->toBe('kitsune.commerce.export')
        ->and((int) $rows[0]['version'])->toBe(1)
        ->and($rows[0]['org'])->toBe($this->org->slug)->and($rows[0]['site'])->toBe($this->site->handle)
        ->and($page->filename())->toBe('commerce-history-20261016T120000Z.'.$format)
        ->and($page->contentType())->toBe($format === 'jsonl' ? 'application/x-ndjson; charset=UTF-8' : 'text/csv; charset=UTF-8');
})->with(['jsonl', 'csv']);

it('keeps bounded continuation within its original high-water mark while allowing format and page-size changes', function (): void {
    $first = OrderFixture::plant($this->site);
    $second = OrderFixture::plant($this->site);
    $page = $this->exports->forOwner('jsonl', limit: 1);
    $rows = historyExportRows($page);
    expect($page->count)->toBe(1)->and($page->nextCursor)->toBeString()
        ->and(array_column(historyExportKind($rows, 'order'), 'order_reference'))->toBe([$first->reference])
        ->and($rows[0]['data']['max_id'])->toBe($second->id)
        ->and(ExportCursor::decode($page->nextCursor, $this->org->id, $this->site->id, false))
        ->toBe(['after' => $first->id, 'upper' => $second->id]);
    $appended = OrderFixture::plant($this->site);
    $next = $this->exports->forOwner('csv', limit: 100, cursor: $page->nextCursor);
    expect($next->count)->toBe(1)->and($next->nextCursor)->toBeNull()
        ->and(array_column(historyExportKind(historyExportRows($next), 'order'), 'order_reference'))->toBe([$second->reference])
        ->and($next->body)->not->toContain($first->reference, $appended->reference)
        ->and($this->exports->forOwner('jsonl')->count)->toBe(3);
});

it('limits owner pages to the selected Site and permits explicit Org-wide cold console history only within that Org', function (string $receipt): void {
    $main = OrderFixture::plant($this->site);
    $sibling = OrderFixture::plant($this->world['sites']['golfdom-fr']);
    $foreign = OrderFixture::plant($this->world['sites']['rival']);
    $owner = $this->exports->forOwner('jsonl');
    expect($owner->count)->toBe(1)->and($owner->body)->toContain($main->reference)->not->toContain($sibling->reference, $foreign->reference);
    DB::table('modules')->where('handle', 'kitsune/commerce')->update($receipt === 'disabled'
        ? ['is_enabled' => false] : ['version' => 'old-history-version']);
    Auth::guard('web')->logout();
    app(Context::class)->forget()->setOrg($this->org);
    $console = $this->exports->forConsole('jsonl');
    expect($console->count)->toBe(2)->and($console->body)->toContain($main->reference, $sibling->reference)->not->toContain($foreign->reference)
        ->and(array_column(historyExportKind(historyExportRows($console), 'order'), 'site'))->toBe(['golfdom', 'golfdom-fr'])
        ->and(fn () => $this->exports->forOwner('jsonl'))->toThrow(ExportRefused::class);
})->with(['disabled', 'stale']);

it('refuses invalid scope profile version types and noncanonical cursor input', function (string $invalid): void {
    $values = ['version' => 1, 'org' => $this->org->id, 'site' => $this->site->id, 'billing' => false, 'after' => 0, 'upper' => 1];
    $values = match ($invalid) {
        'foreign Org' => [...$values, 'org' => $this->world['rival']->id],
        'sibling Site' => [...$values, 'site' => $this->world['sites']['golfdom-fr']->id],
        'Org-wide scope' => [...$values, 'site' => null],
        'billing profile' => [...$values, 'billing' => true],
        'future version' => [...$values, 'version' => 2],
        'string version' => [...$values, 'version' => '1'],
        'string index' => [...$values, 'after' => '0'],
        'float index' => [...$values, 'after' => 0.0],
        'negative index' => [...$values, 'after' => -1],
        'past upper' => [...$values, 'after' => 2],
        'extra field' => [...$values, 'private' => 'private-cursor-value'],
        'key order' => array_reverse($values, true),
        default => $values,
    };
    $cursor = rtrim(strtr(base64_encode(json_encode($values, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), '+/', '-_'), '=');
    $cursor = match ($invalid) {
        'empty' => '', 'bad encoding' => '%%private-cursor-value%%', 'too long' => str_repeat('a', ExportCursor::MAX_BYTES + 1),
        default => $cursor,
    };
    expect(fn () => $this->exports->forOwner('jsonl', cursor: $cursor))->toThrow(ExportRefused::class);
})->with(['foreign Org', 'sibling Site', 'Org-wide scope', 'billing profile', 'future version', 'string version',
    'string index', 'float index', 'negative index', 'past upper', 'extra field', 'key order', 'empty', 'bad encoding', 'too long']);

it('revalidates current owner rows despite a cached permission answer', function (string $denial): void {
    OrderFixture::plant($this->site);
    expect(Permissions::isOwner($this->owner))->toBeTrue();
    match ($denial) {
        'anonymous' => Auth::guard('web')->logout(),
        'editor' => CredentialFixture::member(owner: false, email: 'history-editor@example.test'),
        'revoked role' => DB::table('role_user')->where('user_id', $this->owner->id)->delete(),
        'removed membership' => DB::table('org_user')->where('org_id', $this->org->id)->where('user_id', $this->owner->id)->delete(),
        'removed owner account' => (function (): void {
            DB::table('role_user')->where('user_id', $this->owner->id)->delete();
            DB::table('org_user')->where('user_id', $this->owner->id)->delete();
            DB::table('users')->where('id', $this->owner->id)->delete();
        })(),
    };
    expect(fn () => $this->exports->forOwner('jsonl'))->toThrow(ExportRefused::class);
    if ($denial !== 'anonymous') {
        expect(fn () => $this->exports->forConsole('jsonl'))->toThrow(ExportRefused::class);
    }
})->with(['anonymous', 'editor', 'revoked role', 'removed membership', 'removed owner account']);

it('does not treat a signed-in reader as the anonymous console system', function (): void {
    OrderFixture::plant($this->site);
    OrderFixture::request(app('session.store'));
    Auth::guard('web')->logout();
    Auth::guard(ReaderFixture::GUARD)->login($this->reader);
    app(Context::class)->setSite($this->site);
    expect(fn () => $this->exports->forConsole('jsonl'))->toThrow(ExportRefused::class)
        ->and(fn () => $this->exports->forOwner('jsonl'))->toThrow(ExportRefused::class);
});

it('links same-title refund selections to stable original lines in both formats', function (): void {
    $order = FinancialAccessFixture::purchase($this->site, (string) $this->reader->id,
        [['days' => 7, 'quantity' => 1], ['days' => 30, 'quantity' => 1]]);
    $lines = DB::table('commerce_order_lines')->where('order_id', $order->id)->orderBy('id')->get();
    expect($lines[0]->title)->toBe($lines[1]->title)->and($lines[0]->sku)->toBe($lines[1]->sku);
    $refund = app(FinancialState::class)->requestAsOwner($order->reference, [$lines[1]->id => 1], 'Private selected second line reason', str_repeat('c', 32));
    foreach (['jsonl', 'csv'] as $format) {
        $rows = historyExportRows($this->exports->forOwner($format));
        $selection = historyExportKind($rows, 'refund_selection')[0];
        $data = $format === 'jsonl' ? $selection['data'] : $selection;
        expect(array_column(historyExportKind($rows, 'line'), 'reference'))->toBe(['line:'.$lines[0]->id, 'line:'.$lines[1]->id])
            ->and($selection['parent_reference'])->toBe('refund:'.$refund)
            ->and($data['line_reference'])->toBe('line:'.$lines[1]->id)
            ->and((int) $data['quantity'])->toBe(1)
            ->and($data['item_net_minor'])->toBe('2000')->and($data['item_tax_minor'])->toBe('100')
            ->and($data['shipping_net_minor'])->toBe('0')->and($data['shipping_tax_minor'])->toBe('0');
    }
});

it('links observed automatic refunds to their original attempt even when an obligation and selected refund share a reference', function (): void {
    $checkout = PaidOrderFixture::attempt($this->site, stock: '2', quantity: 3);
    $order = DB::table('commerce_orders')->where('id', $checkout->order_id)->sole();
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $checkout, PaidOrderFixture::session($checkout)));
    $order = DB::table('commerce_orders')->where('id', $order->id)->sole();
    $line = DB::table('commerce_order_lines')->where('order_id', $order->id)->sole();
    $selected = app(FinancialState::class)->requestAsOwner($order->reference, [$line->id => 1], 'One delivered unit returned', str_repeat('d', 32));
    $attempt = DB::table(PaidOrders::ATTEMPTS)->where('order_id', $order->id)->sole();
    $receipt = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();
    $accepted = app(PaidOrders::class)->refundRequest($attempt);
    // These independent tables may legally share a reference. The original accepted request/attempt stays intact.
    DB::table(PaidOrders::OBLIGATIONS)->where('order_id', $order->id)->update(['reference' => $selected]);
    DB::table(PaidOrders::ATTEMPTS)->where('id', $attempt->id)->update(['state' => 'pending', 'refund_id' => 're_AutomaticExport',
        'first_submitted_at' => OrderFixture::now()]);
    $automatic = ['id' => 're_AutomaticExport', 'chargeId' => $receipt->charge_id, 'paymentIntent' => $receipt->payment_intent_id,
        'currency' => $receipt->currency, 'amount' => $accepted['amount'], 'status' => 'succeeded',
        'created' => CarbonImmutable::now('UTC')->getTimestamp(), 'metadata' => $accepted['metadata']];
    FinancialAccessFixture::observe($order, [$automatic, FinancialAccessFixture::refund($order, $selected, 'succeeded', 're_SelectedExport')]);
    expect(DB::table(FinancialState::OBSERVATIONS)->where('order_id', $order->id)->where('origin', 'unavailable')->value('local_reference'))
        ->toBe($attempt->reference)->not->toBe($selected);

    foreach (['jsonl', 'csv'] as $format) {
        $rows = historyExportRows($this->exports->forOwner($format));
        $observations = historyExportKind($rows, 'financial_observation');
        $byOrigin = [];
        foreach ($observations as $observation) {
            $byOrigin[$format === 'jsonl' ? $observation['data']['origin'] : $observation['origin']] = $observation;
        }
        $attemptReference = historyExportKind($rows, 'refund_attempt')[0]['reference'];
        expect($attemptReference)->toBe('refund-attempt:'.$attempt->reference)
            ->and($byOrigin['unavailable']['parent_reference'])->toBe($attemptReference)
            ->and($byOrigin['operator']['parent_reference'])->toBe('refund:'.$selected)
            ->and(historyExportKind($rows, 'automatic_refund')[0]['reference'])->toBe('automatic-refund:'.$selected)
            ->and(historyExportKind($rows, 'refund')[0]['reference'])->toBe('refund:'.$selected);
    }
});

it('uses frozen financial custody after catalogue removal and without reading a missing host reader account', function (): void {
    $order = FinancialAccessFixture::purchase($this->site, (string) $this->reader->id);
    $line = DB::table('commerce_order_lines')->where('order_id', $order->id)->sole();
    app(CatalogueWriter::class)->removeListing($line->listing_ref);
    app(CatalogueWriter::class)->removeSellable($line->sellable_ref);
    DB::table('readers')->where('id', $this->reader->id)->delete();
    $readerQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$readerQueries): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select') && preg_match('/(?:from|join)\s+["`]?readers["`]?\b/i', $query->sql) === 1) {
            $readerQueries[] = $query->sql;
        }
    });
    $rows = historyExportRows($this->exports->forOwner('jsonl'));
    expect(historyExportKind($rows, 'line')[0]['data'])->toMatchArray(['title' => $line->title, 'sku' => $line->sku, 'unit_price_minor' => '2000'])
        ->and(historyExportKind($rows, 'customer')[0]['data']['customer_kind'])->toBe('reader')
        ->and(historyExportKind($rows, 'payment')[0]['data']['amount_minor'])->toBe('2100')
        ->and($readerQueries)->toBe([]);
});

it('omits personal data by default and includes only explicitly permitted current invoice billing fields', function (): void {
    $order = FinancialAccessFixture::purchase($this->site, (string) $this->reader->id);
    historyExportPolicy($order);
    $unknown = FinancialAccessFixture::purchase($this->site, (string) $this->reader->id);
    $default = $this->exports->forOwner('jsonl');
    expect(historyExportKind(historyExportRows($default), 'billing'))->toBe([]);
    $billing = historyExportKind(historyExportRows($this->exports->forOwner('jsonl', includeBilling: true)), 'billing');
    expect(array_column($billing, 'order_reference'))->toBe([$order->reference, $order->reference])
        ->and(array_column(array_column($billing, 'data'), 'billing_field'))->toBe(['name', 'address.country'])
        ->and(array_column(array_column($billing, 'data'), 'billing_value'))->toBe(['Historical Buyer', 'US']);
    $attempt = DB::table(CheckoutAttempts::TABLE)->where('order_id', $order->id)->sole();
    $payment = DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->sole();
    foreach (['Historical Buyer', 'historical-buyer@example.test', '1 Saved Street', '10001', 'Private policy explanation',
        'owner_key', 'reader_id', 'request_payload', 'idempotency_key', 'credential_witness', 'checkout_url',
        $attempt->session_id, $payment->charge_id, $payment->payment_intent_id] as $private) {
        expect($default->body)->not->toContain($private);
    }
    expect($default->body)->toContain($unknown->reference);
});

it('exports policy-retained erased invoices without restoring reader linkage or noninvoice personal fields', function (): void {
    $order = FinancialAccessFixture::purchase($this->site, (string) $this->reader->id, [['days' => null, 'quantity' => 1]]);
    historyExportPolicy($order);
    app(AccessFulfillment::class)->reconcile($order->reference);
    app(ReaderErasure::class)->erase((string) $this->reader->id);
    $default = $this->exports->forOwner('jsonl');
    $rows = historyExportRows($this->exports->forOwner('jsonl', includeBilling: true));
    expect(historyExportKind($rows, 'customer')[0]['data']['customer_kind'])->toBe('erased')
        ->and(historyExportKind($rows, 'privacy')[0]['data']['subject_erased'])->toBeTrue()
        ->and(historyExportKind($rows, 'access')[0]['data']['state'])->toBe('suppressed')
        ->and(array_column(array_column(historyExportKind($rows, 'billing'), 'data'), 'billing_field'))->toBe(['name', 'address.country'])
        ->and($default->body)->not->toContain('Historical Buyer', 'historical-buyer@example.test', '1 Saved Street', 'reader_id', 'subject_key')
        ->and(DB::table('entitlements')->where('source', 'commerce.order:'.$order->id)->count())->toBe(0);
});

it('excludes overdue unswept billing unless an explicit live legal hold retains it and never resurrects cleared fields', function (): void {
    $order = FinancialAccessFixture::purchase($this->site, (string) $this->reader->id, [['days' => null, 'quantity' => 1]]);
    historyExportPolicy($order);
    CarbonImmutable::setTestNow('2026-10-18 12:00:00 UTC');
    expect(DB::table('commerce_orders')->where('id', $order->id)->value('billing'))->not->toBeNull()
        ->and(historyExportKind(historyExportRows($this->exports->forOwner('jsonl', includeBilling: true)), 'billing'))->toBe([]);
    $hold = app(OrderPrivacy::class)->holdAsOwner($order->reference, 'Private active legal hold reason', '2026-10-20', true);
    $held = $this->exports->forOwner('jsonl', includeBilling: true);
    expect(historyExportKind(historyExportRows($held), 'billing'))->toHaveCount(2)
        ->and($held->body)->not->toContain('Private active legal hold reason');
    app(OrderPrivacy::class)->releaseHoldAsOwner($order->reference, $hold, 'Explicit hold resolution', true);
    app(OrderPrivacy::class)->maintainOrder($order->reference);
    app(OrderPrivacy::class)->holdAsOwner($order->reference, 'Late hold cannot restore cleared fields', '2026-10-20', true);
    expect(DB::table('commerce_orders')->where('id', $order->id)->value('billing'))->toBeNull()
        ->and(historyExportKind(historyExportRows($this->exports->forOwner('jsonl', includeBilling: true)), 'billing'))->toBe([]);
});

it('returns no artifact when a later order has lost or corrupted mandatory children', function (string $corruption): void {
    OrderFixture::plant($this->site);
    $later = OrderFixture::plant($this->site);
    $corruption === 'missing' ? DB::table(FinancialState::WORK)->where('order_id', $later->id)->delete()
        : DB::table('commerce_order_lines')->where('order_id', $later->id)->update(['net_minor' => '1']);
    $artifact = null;
    expect(function () use (&$artifact): void {
        $artifact = $this->exports->forOwner('jsonl');
    })->toThrow(ExportRefused::class);
    expect($artifact)->toBeNull()->and(DB::table('commerce_orders')->count())->toBe(2);
})->with(['missing', 'corrupt']);

it('refuses context or owner changes made by a read callback before returning any bytes', function (string $mutation): void {
    OrderFixture::plant($this->site);
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed, $mutation): void {
        if (! $armed || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, 'commerce_order_lines')) {
            return;
        }
        $armed = false;
        if ($mutation === 'context') {
            app(Context::class)->setSite($this->world['sites']['golfdom-fr']);
        } else {
            DB::table('role_user')->where('user_id', $this->owner->id)->delete();
        }
    });
    $artifact = null;
    expect(function () use (&$artifact): void {
        $artifact = $this->exports->forOwner('jsonl');
    })->toThrow(ExportRefused::class);
    expect($armed)->toBeFalse()->and($artifact)->toBeNull();
    app(Context::class)->setSite($this->site);
    expect(DB::table('role_user')->where('user_id', $this->owner->id)->count())->toBe(1);
})->with(['context', 'owner']);

it('refuses unsupported producer formats and page limits', function (string $format, int $limit): void {
    expect(fn () => $this->exports->forOwner($format, limit: $limit))->toThrow(ExportRefused::class);
})->with([['json', 1], ['JSONL', 1], ['jsonl', 0], ['jsonl', -1], ['csv', 101]]);

it('bounds the complete in-memory DTO without requiring a large database or temporary plaintext file', function (): void {
    $body = str_repeat('x', ExportPage::MAX_BYTES);
    expect(strlen((new ExportPage($body, 'jsonl', null, 100))->body))->toBe(ExportPage::MAX_BYTES)
        ->and(fn () => new ExportPage($body.'x', 'jsonl', null, 1))->toThrow(ExportRefused::class)
        ->and(fn () => new ExportPage('', 'jsonl', null, 101))->toThrow(ExportRefused::class)
        ->and(fn () => new ExportPage('', 'csv', null, -1))->toThrow(ExportRefused::class)
        ->and(fn () => new ExportPage('', 'json', null, 0))->toThrow(ExportRefused::class);
});
