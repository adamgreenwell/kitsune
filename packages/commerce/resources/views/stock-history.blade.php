{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<x-filament-panels::page>
    <p>{{ __('commerce::stock.retained_help') }}</p>
    <x-filament::section>
        <form wire:submit="applyFilters" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['sku', 'product', 'from', 'to'] as $filter)
                    <div class="space-y-2">
                        <label for="history-{{ $filter }}" class="block font-medium">{{ __('commerce::stock.filter_'.$filter) }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input :id="'history-'.$filter" :type="in_array($filter, ['from', 'to'], true) ? 'date' : 'text'"
                                :wire:model="$filter" :maxlength="$filter === 'sku' ? 64 : 19" dir="ltr"
                                aria-describedby="history-filter-help history-filter-error" />
                        </x-filament::input.wrapper>
                    </div>
                @endforeach
            </div>
            <p id="history-filter-help">{{ __('commerce::stock.filter_help') }}</p>
            <div id="history-filter-error" role="status">@error('history')<p>{{ $message }}</p>@enderror</div>
            <div class="flex flex-wrap gap-3">
                <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::stock.apply_filters') }}</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="clearFilters" wire:loading.attr="disabled">{{ __('commerce::stock.clear_filters') }}</x-filament::button>
            </div>
        </form>
    </x-filament::section>
    @php($movements = $this->movements())
    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-start" data-retained-stock-history>
                <caption class="sr-only">{{ __('commerce::stock.retained_title') }}</caption>
                <thead><tr>
                    @foreach (['time', 'filter_sku', 'product_reference', 'kind', 'before', 'after', 'allocated_quantity', 'reason', 'actor', 'source'] as $column)
                        <th scope="col" class="p-2 text-start">{{ __('commerce::stock.'.$column) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse ($movements as $change)
                        <tr>
                            <td class="p-2" dir="ltr">{{ $change['createdAt'] }}</td>
                            <td class="p-2" dir="ltr">{{ $change['sku'] }}</td>
                            <td class="p-2" dir="ltr">{{ $change['productReference'] }}</td>
                            <td class="p-2">{{ __('commerce::stock.kind_'.$change['kind']) }}</td>
                            <td class="p-2" dir="ltr">{{ $change['beforeStock'] ?? __('commerce::catalogue.untracked') }}</td>
                            <td class="p-2" dir="ltr">{{ $change['afterStock'] ?? __('commerce::catalogue.untracked') }}</td>
                            <td class="p-2" dir="ltr">{{ $change['quantity'] ?? __('commerce::stock.not_applicable') }}</td>
                            <td class="p-2">{{ $change['reason'] }}</td>
                            <td class="p-2" dir="ltr">{{ $change['actorId'] ?? __('commerce::stock.system') }}</td>
                            <td class="p-2">
                                <p>{{ __('commerce::stock.movement_reference') }}: <span dir="ltr">{{ $change['reference'] }}</span></p>
                                @if ($change['orderReference'] !== null)
                                    <p>{{ __('commerce::stock.order_reference') }}: <span dir="ltr">{{ $change['orderReference'] }}</span></p>
                                    <p>{{ __('commerce::stock.line_reference') }}: <span dir="ltr">{{ $change['lineReference'] }}</span></p>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="p-2">{{ __('commerce::stock.retained_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $movements->links() }}
    </x-filament::section>
</x-filament-panels::page>
