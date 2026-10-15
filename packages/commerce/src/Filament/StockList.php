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
use Illuminate\Pagination\Paginator;
use Kitsune\Commerce\Inventory\StockWriter;
use Kitsune\Commerce\Money\MinorUnits;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use Throwable;

/** Every action and read rechecks current authority and product custody; form expectations are server-locked. @internal */
final class StockList extends Page
{
    use RestrictsFileUploadsToSchemaComponents;
    use WithPagination;

    protected static string $resource = StockResource::class;

    protected string $view = 'commerce::stock-list';

    #[Locked]
    public ?string $reference = null;

    #[Locked]
    public ?string $expectedStock = null;

    public mixed $tracking = 'untracked';

    public mixed $quantity = '';

    public mixed $reason = '';

    public function mount(?string $reference = null): void
    {
        self::authorizeResourceAccess();
        if ($reference !== null) {
            $this->reference = self::reference($reference);
            $this->reloadBalance();
        }
    }

    public function getTitle(): string
    {
        return __('commerce::stock.title');
    }

    /** @return Paginator<int, covariant array{reference:string,sku:string,stock:string|null}> */
    public function products(): Paginator
    {
        self::authorizeResourceAccess();
        $page = $this->getPage();
        abort_unless((is_int($page) || (is_string($page) && preg_match('/\A[1-9][0-9]{0,4}\z/D', $page) === 1))
            && (int) $page >= 1 && (int) $page <= 10000, 404);

        try {
            $rows = app(StockWriter::class)->products(((int) $page - 1) * 25, 26);

            return new Paginator($rows, 25, (int) $page, ['path' => Paginator::resolveCurrentPath()]);
        } catch (Throwable) {
            abort(503, __('commerce::stock.unavailable'));
        }
    }

    /** @return array{reference:string,sku:string,stock:string|null} */
    public function detail(): array
    {
        self::authorizeResourceAccess();
        $reference = self::reference($this->reference);
        abort_unless(app(StockWriter::class)->canAdjust($reference), 404);

        try {
            return app(StockWriter::class)->current($reference);
        } catch (Throwable) {
            abort(503, __('commerce::stock.unavailable'));
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(): array
    {
        self::authorizeResourceAccess();

        try {
            return app(StockWriter::class)->history(self::reference($this->reference));
        } catch (Throwable) {
            abort(503, __('commerce::stock.unavailable'));
        }
    }

    public function reloadBalance(): void
    {
        self::authorizeResourceAccess();
        $row = $this->detail();
        $this->expectedStock = $row['stock'];
        $this->tracking = $row['stock'] === null ? 'untracked' : 'finite';
        $this->quantity = $row['stock'] ?? '';
        $this->reason = '';
        $this->resetErrorBag('stock');
    }

    public function adjustStock(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('stock');

        try {
            if (! in_array($this->tracking, ['finite', 'untracked'], true) || ! is_string($this->reason)) {
                throw new \InvalidArgumentException;
            }
            $quantity = $this->tracking === 'untracked' ? null : MinorUnits::integer($this->quantity);
            app(StockWriter::class)->adjust(self::reference($this->reference), $this->expectedStock, $quantity, $this->reason);
        } catch (Throwable) {
            $this->addError('stock', __('commerce::stock.refused'));

            return;
        }

        $this->reloadBalance();
        Notification::make()->title(__('commerce::stock.saved'))->success()->send();
    }

    private static function reference(mixed $reference): string
    {
        abort_unless(is_string($reference) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $reference) === 1
            && (strlen($reference) < 19 || strcmp($reference, '9223372036854775807') <= 0), 404);

        return $reference;
    }
}
