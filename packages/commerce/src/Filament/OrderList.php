<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Pagination\Paginator;
use Kitsune\Commerce\Models\Order;
use Kitsune\Commerce\Orders\OrderReaderData;
use Kitsune\Commerce\Orders\OrderRefused;
use Kitsune\Commerce\Payments\AccessAdjustments;
use Kitsune\Commerce\Payments\AccessFulfillment;
use Kitsune\Commerce\Payments\CheckoutMessages;
use Kitsune\Commerce\Payments\CheckoutRefused;
use Kitsune\Commerce\Payments\CheckoutWriter;
use Kitsune\Commerce\Payments\FinancialReadModel;
use Kitsune\Commerce\Payments\FinancialReconciler;
use Kitsune\Commerce\Payments\FinancialState;
use Kitsune\Commerce\Payments\LiveStripeAdmission;
use Kitsune\Core\Filament\Schemas\SiteTime;
use Kitsune\Core\Tenancy\Context;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use Throwable;

/** Immutable historical projections with checked, owner-only unpaid cancellation and erasure. @internal */
final class OrderList extends Page
{
    use RestrictsFileUploadsToSchemaComponents;
    use WithPagination;

    protected static string $resource = OrderResource::class;

    protected string $view = 'commerce::order-list';

    #[Locked]
    public ?string $reference = null;

    public mixed $recoverySession = '';

    public bool $recoveryExpire = false;

    /** @var array<int|string,mixed> */
    public array $refundQuantities = [];

    public string $refundReason = '';

    public bool $refundConfirmed = false;

    public string $externalRefund = '';

    #[Locked]
    public string $refundRequestKey = '';

    public function mount(?string $reference = null): void
    {
        self::authorizeResourceAccess();
        $this->reference = $reference === null ? null : OrderAdministration::reference($reference);
        $this->refundRequestKey = bin2hex(random_bytes(16));
        if ($this->reference !== null) {
            $this->detail();
        }
    }

    /** @return array{state:string,version:?string,account:?string,admittedAt:?string} */
    public function liveAdmission(): array
    {
        self::authorizeResourceAccess();
        try {
            return app(LiveStripeAdmission::class)->statusForOwner();
        } catch (Throwable) {
            return ['state' => 'unavailable', 'version' => null, 'account' => null, 'admittedAt' => null];
        }
    }

    public function getTitle(): string
    {
        return __('commerce::checkout.orders_title');
    }

    public function instant(string $reference, string $value): Schema
    {
        return Schema::make($this)->components([SiteTime::entry('created_'.OrderAdministration::reference($reference))->state($value)->hiddenLabel()]);
    }

    /** @return Paginator<int,array<'reference'|'state'|'mode'|'currency'|'quoted_total_minor'|'created_at',mixed>> */
    public function orders(): Paginator
    {
        self::authorizeResourceAccess();
        $page = $this->getPage();
        abort_unless((is_int($page) || (is_string($page) && preg_match('/\A[1-9][0-9]{0,4}\z/D', $page) === 1)) && (int) $page >= 1 && (int) $page <= 10000, 404);
        $context = app(Context::class);

        try {
            $connection = (new Order)->getConnection();
            $rows = $connection->table('commerce_orders')->useWritePdo()->where('org_id', $context->orgId())->where('site_id', $context->siteId())
                ->orderByDesc('id')->offset(((int) $page - 1) * 25)->limit(26)->get();
            $records = $rows->map(static function (object $row) use ($connection): array {
                $record = app(OrderReaderData::class)->record($connection, $row);

                return array_intersect_key($record, array_flip(['reference', 'state', 'mode', 'currency', 'quoted_total_minor', 'created_at']));
            });

            return new Paginator($records, 25, (int) $page, ['path' => Paginator::resolveCurrentPath()]);
        } catch (Throwable) {
            abort(503, __('commerce::checkout.order_refused'));
        }
    }

    /** @return array<string,mixed> */
    public function detail(): array
    {
        self::authorizeResourceAccess();
        $reference = OrderAdministration::reference($this->reference);
        $context = app(Context::class);
        $connection = (new Order)->getConnection();
        try {
            $row = app(OrderReaderData::class)->find($connection, $context->orgId(), $context->siteId(), $reference);
        } catch (Throwable) {
            abort(503, __('commerce::checkout.order_refused'));
        }
        abort_if($row === null, 404);

        try {
            return app(OrderReaderData::class)->record($connection, $row);
        } catch (Throwable) {
            abort(503, __('commerce::checkout.order_refused'));
        }
    }

    public function cancelOrder(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('order');
        try {
            app(OrderAdministration::class)->cancel($this->reference);
        } catch (Throwable) {
            $this->addError('order', __('commerce::checkout.order_refused'));

            return;
        }
        Notification::make()->title(__('commerce::checkout.order_cancelled'))->success()->send();
    }

    public function eraseOrder(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('order');
        try {
            app(OrderAdministration::class)->erase($this->reference);
        } catch (Throwable) {
            $this->addError('order', __('commerce::checkout.order_refused'));

            return;
        }
        $this->reference = null;
        Notification::make()->title(__('commerce::checkout.order_erased'))->success()->send();
        $this->redirect(OrderResource::getUrl());
    }

    public function recoverAttempt(mixed $attemptReference): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('order');
        $session = $this->recoverySession;
        $expire = $this->recoveryExpire;
        $this->recoverySession = '';
        $this->recoveryExpire = false;
        try {
            app(CheckoutWriter::class)->operateAsOwner($this->reference, $attemptReference, $session === '' ? null : $session, $expire);
        } catch (CheckoutRefused|OrderRefused $failure) {
            $this->addError('order', __('commerce::payments.'.CheckoutMessages::key($failure->reason)));

            return;
        } catch (Throwable) {
            $this->addError('order', __('commerce::payments.unavailable'));

            return;
        }
        Notification::make()->title(__('commerce::payments.recovered'))->success()->send();
    }

    public function historicalRecoveryUrl(): string
    {
        self::authorizeResourceAccess();

        return OrderResource::getUrl('historical-recovery');
    }

    public function retryAccess(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('order');
        try {
            app(AccessFulfillment::class)->retryAsOwner(OrderAdministration::reference($this->reference));
        } catch (Throwable) {
            $this->addError('order', __('commerce::access.unavailable'));

            return;
        }
        Notification::make()->title(__('commerce::access.checked'))->success()->send();
    }

    /** Owner action inputs come from checked original awarded units, never the current catalogue.
     * @return array<string,mixed>
     */
    public function financialDetail(): array
    {
        self::authorizeResourceAccess();
        $reference = OrderAdministration::reference($this->reference);
        $context = app(Context::class);
        $connection = (new Order)->getConnection();
        try {
            $order = app(OrderReaderData::class)->find($connection, $context->orgId(), $context->siteId(), $reference)
                ?? throw new OrderRefused;

            return app(FinancialReadModel::class)->forOwner($connection, $order);
        } catch (Throwable) {
            abort(503, __('commerce::financial.unavailable'));
        }
    }

    public function requestRefund(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('order');
        $saved = false;
        try {
            if (! $this->refundConfirmed) {
                throw new OrderRefused;
            }
            $reference = OrderAdministration::reference($this->reference);
            $refund = app(FinancialState::class)->requestAsOwner($reference, $this->selectedUnits(), trim($this->refundReason), $this->refundRequestKey);
            $saved = true;
            $this->clearFinancialForm();
            // Suspend committed local contributions before any provider operation can begin.
            app(AccessAdjustments::class)->reconcile($reference);
            app(FinancialReconciler::class)->submit($reference, $refund);
            app(AccessFulfillment::class)->reconcile($reference);
            app(AccessAdjustments::class)->reconcile($reference);
        } catch (Throwable) {
            $this->addError('order', __($saved ? 'commerce::financial.saved_pending' : 'commerce::financial.refused'));

            return;
        }
        Notification::make()->title(__('commerce::financial.saved'))->success()->send();
    }

    public function attributeRefund(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('order');
        $saved = false;
        try {
            if (! $this->refundConfirmed) {
                throw new OrderRefused;
            }
            $reference = OrderAdministration::reference($this->reference);
            app(FinancialState::class)->attributeAsOwner($reference, $this->externalRefund, $this->selectedUnits(), trim($this->refundReason));
            $saved = true;
            $this->clearFinancialForm();
            app(AccessAdjustments::class)->reconcile($reference);
            app(AccessFulfillment::class)->reconcile($reference);
            app(AccessAdjustments::class)->reconcile($reference);
        } catch (Throwable) {
            $this->addError('order', __($saved ? 'commerce::financial.saved_pending' : 'commerce::financial.refused'));

            return;
        }
        Notification::make()->title(__('commerce::financial.attributed'))->success()->send();
    }

    public function requestBalanceRefund(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('order');
        $saved = false;
        try {
            if (! $this->refundConfirmed) {
                throw new OrderRefused;
            }
            $reference = OrderAdministration::reference($this->reference);
            $refund = app(FinancialState::class)->requestBalanceRefundAsOwner($reference, trim($this->refundReason), $this->refundRequestKey);
            $saved = true;
            $this->clearFinancialForm();
            app(AccessAdjustments::class)->reconcile($reference);
            app(FinancialReconciler::class)->submit($reference, $refund);
            app(AccessFulfillment::class)->reconcile($reference);
            app(AccessAdjustments::class)->reconcile($reference);
        } catch (Throwable) {
            $this->addError('order', __($saved ? 'commerce::financial.saved_pending' : 'commerce::financial.refused'));

            return;
        }
        Notification::make()->title(__('commerce::financial.saved'))->success()->send();
    }

    public function refreshFinancial(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('order');
        try {
            $reference = OrderAdministration::reference($this->reference);
            app(AccessAdjustments::class)->reconcile($reference);
            app(FinancialReconciler::class)->reconcile($reference);
            app(AccessFulfillment::class)->reconcile($reference);
            app(AccessAdjustments::class)->reconcile($reference);
        } catch (Throwable) {
            $this->addError('order', __('commerce::financial.unavailable'));

            return;
        }
        Notification::make()->title(__('commerce::financial.checked'))->success()->send();
    }

    /** @return array<int,int> */
    private function selectedUnits(): array
    {
        if (count($this->refundQuantities) > 20) {
            throw new OrderRefused;
        }
        $selected = [];
        foreach ($this->refundQuantities as $id => $quantity) {
            if ((! is_int($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1) || (int) $id < 1
                || (! is_int($quantity) && (! is_string($quantity) || preg_match('/\A(?:0|[1-9][0-9]?)\z/D', $quantity) !== 1))
                || (int) $quantity < 0 || (int) $quantity > 99) {
                throw new OrderRefused;
            }
            if ((int) $quantity > 0) {
                $selected[(int) $id] = (int) $quantity;
            }
        }
        if ($selected === []) {
            throw new OrderRefused;
        }

        return $selected;
    }

    private function clearFinancialForm(): void
    {
        $this->refundQuantities = [];
        $this->refundReason = '';
        $this->refundConfirmed = false;
        $this->externalRefund = '';
        $this->refundRequestKey = bin2hex(random_bytes(16));
    }
}
