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

    public function mount(?string $reference = null): void
    {
        self::authorizeResourceAccess();
        $this->reference = $reference === null ? null : OrderAdministration::reference($reference);
        if ($this->reference !== null) {
            $this->detail();
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
}
