<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Kitsune\Commerce\Filament\OrderList;
use Kitsune\Commerce\Orders\OrderLocks;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\FinancialReadModel;
use Kitsune\Commerce\Payments\FinancialReconciler;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\PaidOrders;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\CredentialFixture as FinancialReadFx;
use Kitsune\Core\Tests\Fixtures\FinancialAccessFixture;
use Kitsune\Core\Tests\Fixtures\PaidOrderFixture;
use Livewire\Mechanisms\DataStore;

beforeEach(function (): void {
    // This focused core host does not boot Livewire's provider; its component data store is singleton in the real panel.
    app()->singleton(DataStore::class);
    FinancialReadFx::boot();
    $this->org = FinancialReadFx::org('financial-read');
    $this->org->update(['currency' => 'USD']);
    $this->site = Site::create(['handle' => 'main', 'slug' => 'financial-read-main', 'name' => 'Main', 'locale' => 'en', 'base_url' => 'https://financial-read.test']);
    app(Context::class)->setSite($this->site);
    $this->owner = FinancialReadFx::member();
    $attempt = PaidOrderFixture::attempt($this->site, '2', 3);
    ModuleKernel::flush();
    ModuleKernel::boot(app());
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(),
        DB::table('commerce_orders')->where('id', $attempt->order_id)->sole(), $attempt, PaidOrderFixture::session($attempt)));
    $this->order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    $this->line = DB::table('commerce_order_lines')->where('order_id', $this->order->id)->sole();
});

afterEach(fn () => FinancialReadFx::tearDown());

it('exports only safe selected-unit money and status while omitting private operator and provider custody', function (): void {
    $reference = app(FinancialState::class)->requestAsOwner($this->order->reference, [$this->line->id => 1],
        'private owner reason never exported', str_repeat('b', 32));
    $record = app(OrderReaderData::class)->record(DB::connection(), $this->order);
    $refund = $record['financial_state']['refunds'][0];
    expect($refund)->toMatchArray(['reference' => $reference, 'origin' => 'operator', 'state' => 'owed', 'amount_minor' => '2100',
        'selection' => [['title' => $this->line->title, 'quantity' => 1]]]);
    $encoded = json_encode($record['financial_state']);
    foreach (['private owner reason', 'request_payload', 'idempotency_key', 'credential_witness', 'basis_hash', 'request_digest',
        'account_id', 'owner_key', 'line_id', 'change_token', 'revocation_witness', 'metadata'] as $private) {
        expect($encoded)->not->toContain($private);
    }
    $owner = app(FinancialReadModel::class)->forOwner(DB::connection(), $this->order);
    expect($owner['lines'][0])->toBe(['line_id' => (int) $this->line->id, 'title' => $this->line->title, 'awarded' => 2, 'available' => 1]);
    expect($owner['history'][0]['reason'])->toBe('private owner reason never exported');
});

it('checks retained financial custody while commerce is disabled or stale and refuses missing parents', function (bool $disabled): void {
    Module::query()->where('handle', 'kitsune/commerce')->firstOrFail()->update($disabled ? ['is_enabled' => false] : ['version' => 'old']);
    $record = app(OrderReaderData::class)->record(DB::connection(), $this->order);
    expect($record['financial_state']['refunds'])->toBe([])
        ->and($record['financial_access'])->toBeNull()
        ->and($record['access_fulfillment'])->not->toBeNull()
        ->and($record['access_fulfillment']['state'])->not->toBe('awaiting_payment');
    DB::table(FinancialState::WORK)->where('order_id', $this->order->id)->delete();
    expect(fn () => app(OrderReaderData::class)->record(DB::connection(), $this->order))->toThrow(RuntimeException::class);
})->with([false, true]);

it('does not expose owner line or external-provider identifiers to nonowners or another selling site', function (string $actor): void {
    match ($actor) {
        'anonymous' => Auth::guard('web')->logout(),
        'member' => FinancialReadFx::member(owner: false, email: 'member@financial-read.test'),
        'sibling' => app(Context::class)->setSite(Site::create(['handle' => 'sibling', 'slug' => 'financial-read-sibling', 'name' => 'Sibling', 'locale' => 'en'])),
    };
    Permissions::forget();
    expect(fn () => app(FinancialReadModel::class)->forOwner(DB::connection(), $this->order))->toThrow(CheckoutRefused::class);
})->with(['anonymous', 'member', 'sibling']);

it('requires server-side explicit confirmation and meaningful reason before saving a money request', function (string $missing): void {
    $page = app(OrderList::class);
    $page->mount($this->order->reference);
    $page->setErrorBag(new MessageBag);
    $page->refundQuantities = [$this->line->id => '1'];
    $page->refundReason = $missing === 'reason' ? '' : 'Requested by the buyer';
    $page->refundConfirmed = $missing !== 'confirmation';
    $page->requestRefund();
    expect(DB::table(FinancialState::REFUNDS)->count())->toBe(0)
        ->and($page->getErrorBag()->has('order'))->toBeTrue();
})->with(['reason', 'confirmation']);

it('commits selected-unit intent and attempts access reconciliation before submitting with the saved reference', function (): void {
    $page = app(OrderList::class);
    $page->mount($this->order->reference);
    $page->setErrorBag(new MessageBag);
    $called = [];
    $fixtureLevel = DB::transactionLevel();
    app()->instance(AccessAdjustments::class, new class($called, $fixtureLevel)
    {
        public function __construct(private array &$called, private int $fixtureLevel) {}

        public function reconcile(string $reference): array
        {
            $this->called[] = DB::transactionLevel() > $this->fixtureLevel ? 'access_in_commit' : 'access_after_commit';
            expect(DB::table(FinancialState::REFUNDS)->count())->toBe(1);

            return ['state' => 'processed', 'category' => null, 'processed' => 0];
        }
    });
    app()->instance(FinancialReconciler::class, new class($called, $fixtureLevel)
    {
        public function __construct(private array &$called, private int $fixtureLevel) {}

        public function submit(string $order, string $reference): never
        {
            expect($this->called)->toBe(['access_in_commit', 'access_after_commit'])
                ->and(DB::transactionLevel())->toBe($this->fixtureLevel)
                ->and(DB::table(FinancialState::REFUNDS)->value('reference'))->toBe($reference);
            $this->called[] = 'submit';
            throw new RuntimeException('Provider deliberately unavailable');
        }
    });
    $page->refundQuantities = [$this->line->id => '1'];
    $page->refundReason = 'Buyer requested one delivered unit';
    $page->refundConfirmed = true;
    $page->requestRefund();
    expect($called)->toBe(['access_in_commit', 'access_after_commit', 'submit'])->and(DB::table(FinancialState::REFUNDS)->count())->toBe(1)
        ->and($page->refundReason)->toBe('')->and($page->refundConfirmed)->toBeFalse()
        ->and($page->getErrorBag()->first('order'))->toBe(__('commerce::financial.saved_pending'));
});

it('shows current financial access with the frozen product title and hides the historical award badge', function (): void {
    $record = app(OrderReaderData::class)->record(DB::connection(), $this->order);
    $record['lines'][0]['entitlement_name'] = 'private.technical-handle';
    $record['financial_access'] = ['state' => 'processed', 'category' => null,
        'effects' => [['entitlement' => 'private.technical-handle', 'expires_at' => '2026-11-15 12:00:00', 'state' => 'revoked']]];
    $html = view('commerce::orders.access-summary', ['order' => $record])->render();
    expect($html)->toContain($this->line->title, 'Pass revoked', 'Current access from this order')
        ->not->toContain('private.technical-handle', 'Pass awarded');
});

it('attributes a verified external refund through the owner action without sending another refund', function (): void {
    $receipt = app(FinancialState::class)->checked(DB::connection(), $this->order)['basis']['receipt'];
    FinancialAccessFixture::observe($this->order, [['id' => 're_ExternalUi', 'chargeId' => $receipt['charge_id'],
        'paymentIntent' => $receipt['payment_intent_id'], 'currency' => 'USD', 'amount' => '2100', 'status' => 'succeeded',
        'created' => 1792065600, 'metadata' => []]]);
    $owner = app(FinancialReadModel::class)->forOwner(DB::connection(), $this->order);
    expect($owner['external'])->toBe([['refund_id' => 're_ExternalUi', 'state' => 'succeeded', 'amount_minor' => '2100']]);
    $page = app(OrderList::class);
    $page->mount($this->order->reference);
    $page->setErrorBag(new MessageBag);
    $page->externalRefund = 're_ExternalUi';
    $page->refundQuantities = [$this->line->id => '1'];
    $page->refundReason = 'Match the buyer dashboard refund';
    $page->refundConfirmed = true;
    app()->instance(FinancialReconciler::class, new class
    {
        public function submit(): never
        {
            throw new RuntimeException('Attribution must not submit money');
        }
    });
    $page->attributeRefund();
    expect($page->getErrorBag()->has('order'))->toBeFalse()
        ->and(DB::table(FinancialState::REFUNDS)->sole()->origin)->toBe('external')
        ->and(app(FinancialReadModel::class)->forOwner(DB::connection(), $this->order)['external'])->toBe([])
        ->and(json_encode(app(FinancialReadModel::class)->projection(DB::connection(), $this->org->id, $this->order)))
        ->not->toContain('re_ExternalUi', 'Match the buyer dashboard refund');
});

it('offers a separate held-balance request with no selected delivered units or invented access', function (): void {
    $attempt = PaidOrderFixture::attempt($this->site, null, 1);
    $proof = PaidOrderFixture::session($attempt);
    $proof['payment']['disputed'] = true;
    $order = DB::table('commerce_orders')->where('id', $attempt->order_id)->sole();
    app(OrderLocks::class)->run(fn () => app(PaidOrders::class)->confirm(DB::connection(), $order, $attempt, $proof));
    $order = DB::table('commerce_orders')->where('id', $order->id)->sole();
    FinancialAccessFixture::observe($order);
    $actions = app(FinancialReadModel::class)->forOwner(DB::connection(), $order);
    expect($actions)->toMatchArray(['can_balance_refund' => true, 'balance_minor' => '2100', 'can_request' => false])
        ->and($actions['lines'][0]['awarded'])->toBe(0);
    $page = app(OrderList::class);
    $page->mount($order->reference);
    $page->setErrorBag(new MessageBag);
    $page->refundReason = 'Refund the held captured balance';
    $page->refundConfirmed = true;
    app()->instance(FinancialReconciler::class, new class
    {
        public function submit(): never
        {
            throw new RuntimeException('Keep this fixture request pending');
        }
    });
    $page->requestBalanceRefund();
    $row = DB::table(FinancialState::REFUNDS)->where('order_id', $order->id)->sole();
    expect($row->purpose)->toBe('held_balance')->and((string) $row->amount_minor)->toBe('2100')
        ->and(json_decode($row->selection, true))->toBe([])
        ->and(DB::table('entitlements')->count())->toBe(0)
        ->and(DB::table(PaidOrders::RECEIPTS)->where('order_id', $order->id)->value('allocation_state'))->toBe('held');
});
