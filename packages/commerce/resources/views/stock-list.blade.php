{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<x-filament-panels::page>
    <p>{{ __('commerce::stock.shared_help') }}</p>
    @if ($reference === null)
        @php($products = $this->products())
        <x-filament::section>
            @forelse ($products as $product)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b py-4">
                    <div><strong dir="ltr">{{ $product['sku'] }}</strong><p>{{ __('commerce::stock.balance') }}: <span dir="ltr">{{ $product['stock'] ?? __('commerce::catalogue.untracked') }}</span></p></div>
                    <x-filament::button tag="a" :href="\Kitsune\Commerce\Filament\StockResource::getUrl('detail', ['reference' => $product['reference']])">{{ __('commerce::stock.open') }}</x-filament::button>
                </div>
            @empty
                <p>{{ __('commerce::stock.empty') }}</p>
            @endforelse
            {{ $products->links() }}
        </x-filament::section>
    @else
        @php($product = $this->detail())
        <x-filament::button tag="a" color="gray" :href="\Kitsune\Commerce\Filament\StockResource::getUrl()">{{ __('commerce::stock.back') }}</x-filament::button>
        <x-filament::section :heading="$product['sku']">
            <p>{{ __('commerce::stock.balance') }}: <span dir="ltr" data-stock-balance>{{ $product['stock'] ?? __('commerce::catalogue.untracked') }}</span></p>
            <form wire:submit="adjustStock" class="mt-6 space-y-4">
                <label for="stock-tracking" class="block font-medium">{{ __('commerce::stock.tracking') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="stock-tracking" wire:model.live="tracking" aria-describedby="stock-error">
                        <option value="untracked">{{ __('commerce::catalogue.untracked') }}</option>
                        <option value="finite">{{ __('commerce::catalogue.finite') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @if ($tracking === 'finite')
                    <label for="stock-quantity" class="block font-medium">{{ __('commerce::stock.quantity') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="stock-quantity" wire:model="quantity" inputmode="numeric" maxlength="19" dir="ltr" required aria-describedby="stock-error" />
                    </x-filament::input.wrapper>
                @endif
                <label for="stock-reason" class="block font-medium">{{ __('commerce::stock.reason') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="stock-reason" wire:model="reason" maxlength="500" required aria-describedby="stock-reason-help stock-error" />
                </x-filament::input.wrapper>
                <p id="stock-reason-help" class="text-sm">{{ __('commerce::stock.reason_help') }}</p>
                <div id="stock-error" role="status">@error('stock')<p>{{ $message }}</p>@enderror</div>
                <div class="flex flex-wrap gap-3">
                    <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::stock.save') }}</x-filament::button>
                    <x-filament::button type="button" color="gray" wire:click="reloadBalance" wire:loading.attr="disabled">{{ __('commerce::stock.reload') }}</x-filament::button>
                </div>
            </form>
        </x-filament::section>
        <x-filament::section :heading="__('commerce::stock.history')">
            <p>{{ __('commerce::stock.history_help') }}</p>
            <div class="overflow-x-auto">
                <table class="mt-4 w-full text-start">
                    <thead><tr>
                        <th scope="col" class="p-2 text-start">{{ __('commerce::stock.time') }}</th>
                        <th scope="col" class="p-2 text-start">{{ __('commerce::stock.kind') }}</th>
                        <th scope="col" class="p-2 text-start">{{ __('commerce::stock.before') }}</th>
                        <th scope="col" class="p-2 text-start">{{ __('commerce::stock.after') }}</th>
                        <th scope="col" class="p-2 text-start">{{ __('commerce::stock.reason') }}</th>
                        <th scope="col" class="p-2 text-start">{{ __('commerce::stock.actor') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($this->history() as $change)
                            <tr>
                                <td class="p-2" dir="ltr">{{ $change['createdAt'] }}</td>
                                <td class="p-2">{{ __('commerce::stock.kind_'.$change['kind']) }}</td>
                                <td class="p-2" dir="ltr">{{ $change['beforeStock'] ?? __('commerce::catalogue.untracked') }}</td>
                                <td class="p-2" dir="ltr">{{ $change['afterStock'] ?? __('commerce::catalogue.untracked') }}</td>
                                <td class="p-2">{{ $change['reason'] }}</td>
                                <td class="p-2" dir="ltr">{{ $change['actorId'] ?? __('commerce::stock.system') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-2">{{ __('commerce::stock.no_history') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
