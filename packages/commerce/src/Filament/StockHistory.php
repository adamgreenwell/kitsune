<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament;

use Filament\Resources\Pages\Page;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Illuminate\Pagination\Paginator;
use Kitsune\Commerce\Inventory\StockWriter;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use Throwable;

/** Read-only owner history; every hydration/action rechecks current Org custody and owner authority. @internal */
final class StockHistory extends Page
{
    use RestrictsFileUploadsToSchemaComponents;
    use WithPagination;

    protected static string $resource = StockResource::class;

    protected string $view = 'commerce::stock-history';

    public mixed $sku = '';

    public mixed $product = '';

    public mixed $from = '';

    public mixed $to = '';

    /** @var array<string,string> */
    #[Locked]
    public array $filters = [];

    public function mount(): void
    {
        $this->authorizeHistory();
    }

    public function getTitle(): string
    {
        return __('commerce::stock.retained_title');
    }

    public function applyFilters(): void
    {
        $this->authorizeHistory();
        $this->resetErrorBag('history');
        try {
            $this->filters = StockWriter::historyFilters(['sku' => $this->sku, 'product' => $this->product, 'from' => $this->from, 'to' => $this->to]);
            $this->resetPage();
        } catch (Throwable) {
            $this->addError('history', __('commerce::stock.invalid_filters'));
        }
    }

    public function clearFilters(): void
    {
        $this->authorizeHistory();
        $this->sku = $this->product = $this->from = $this->to = '';
        $this->filters = [];
        $this->resetErrorBag('history');
        $this->resetPage();
    }

    /** @return Paginator<int, covariant array<string,mixed>> */
    public function movements(): Paginator
    {
        $this->authorizeHistory();
        $page = $this->getPage();
        abort_unless((is_int($page) || (is_string($page) && preg_match('/\A[1-9][0-9]{0,4}\z/D', $page) === 1))
            && (int) $page >= 1 && (int) $page <= 10000, 404);
        try {
            $rows = app(StockWriter::class)->retainedHistory($this->filters, ((int) $page - 1) * 25);

            return new Paginator($rows, 25, (int) $page, ['path' => Paginator::resolveCurrentPath()]);
        } catch (Throwable) {
            abort(503, __('commerce::stock.unavailable'));
        }
    }

    private function authorizeHistory(): void
    {
        self::authorizeResourceAccess();
        abort_unless(app(StockWriter::class)->canBrowseHistory(), 403);
    }
}
