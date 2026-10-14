{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<x-filament-panels::page>
    <p>{{ __('commerce::checkout.orders_help') }}</p>
    <div role="alert">@error('order') <p>{{ $message }}</p> @enderror</div>
    @if ($this->reference === null)
        @php($orders = $this->orders())
        <x-filament::section :heading="__('commerce::checkout.orders_title')">
            <div class="overflow-x-auto"><table class="w-full text-start">
                <caption class="sr-only">{{ __('commerce::checkout.orders_title') }}</caption>
                <thead><tr>@foreach (['reference', 'state', 'mode', 'total', 'created'] as $field)<th scope="col" class="p-3 text-start">{{ __('commerce::checkout.'.$field) }}</th>@endforeach</tr></thead>
                <tbody>@forelse ($orders as $order)
                    <tr><td class="p-3"><a class="underline" href="{{ \Kitsune\Commerce\Filament\OrderResource::getUrl('detail', ['reference' => $order['reference']]) }}">{{ $order['reference'] }}</a></td>
                        <td class="p-3">{{ __('commerce::checkout.'.$order['state']) }}</td>
                        <td class="p-3">{{ __('commerce::checkout.'.$order['mode']) }}</td>
                        <td class="p-3">{{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($order['quoted_total_minor'], $order['currency']) }}</td>
                        <td class="p-3">{{ $this->instant($order['reference'], $order['created_at']) }}</td></tr>
                @empty <tr><td colspan="5" class="p-3">{{ __('commerce::checkout.empty_orders') }}</td></tr> @endforelse</tbody>
            </table></div>
            {{ $orders->links() }}
        </x-filament::section>
    @else
        @php($order = $this->detail())
        <a class="underline" href="{{ \Kitsune\Commerce\Filament\OrderResource::getUrl() }}">{{ __('commerce::checkout.back_orders') }}</a>
        <x-filament::section :heading="$order['reference']">
            <dl class="space-y-2">
                <div><dt class="font-medium">{{ __('commerce::checkout.state') }}</dt><dd>{{ __('commerce::checkout.'.$order['state']) }}</dd></div>
                <div><dt class="font-medium">{{ __('commerce::checkout.mode') }}</dt><dd>{{ __('commerce::checkout.'.$order['mode']) }}</dd></div>
                <div><dt class="font-medium">{{ __('commerce::checkout.contact_email') }}</dt><dd>{{ $order['contact_email'] }}</dd></div>
                <div><dt class="font-medium">{{ __('commerce::checkout.created') }}</dt><dd>{{ $this->instant($order['reference'], $order['created_at']) }}</dd></div>
            </dl>
        </x-filament::section>
        @foreach (['billing', 'delivery'] as $kind)
            @if ($order[$kind] !== null)
                <x-filament::section :heading="__('commerce::checkout.'.($kind === 'billing' ? 'billing' : 'delivery_address'))">
                    <p>{{ $order[$kind]['name'] }}</p>
                    @foreach ($order[$kind]['address'] as $part) @if ($part !== '')<p>{{ $part }}</p>@endif @endforeach
                </x-filament::section>
            @endif
        @endforeach
        <x-filament::section :heading="__('commerce::checkout.items')">
            <div class="overflow-x-auto"><table class="w-full text-start">
                <caption class="sr-only">{{ __('commerce::checkout.items') }}</caption>
                <thead><tr>@foreach (['sku', 'product', 'quantity', 'net', 'tax', 'total'] as $field)<th scope="col" class="p-3 text-start">{{ __('commerce::checkout.'.$field) }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($order['lines'] as $line)
                    <tr><td class="p-3">{{ $line['sku'] }}</td><td class="p-3">{{ $line['title'] }}</td><td class="p-3">{{ $line['quantity'] }}</td>
                        @foreach (['net_minor', 'tax_minor', 'gross_minor'] as $field)<td class="p-3">{{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($line[$field], $order['currency']) }}</td>@endforeach</tr>
                @endforeach</tbody>
            </table></div>
        </x-filament::section>
        <x-filament::section :heading="__('commerce::checkout.total')">
            <dl class="space-y-2">
                @foreach (['quoted_item_net_minor' => 'net', 'quoted_shipping_net_minor' => 'shipping', 'quoted_tax_minor' => 'tax', 'quoted_total_minor' => 'total'] as $field => $label)
                    <div><dt class="font-medium">{{ __('commerce::checkout.'.$label) }}</dt><dd>{{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($order[$field], $order['currency']) }}</dd></div>
                @endforeach
                <div><dt class="font-medium">{{ __('commerce::checkout.payment') }}</dt><dd>{{ __('commerce::checkout.not_charged') }}</dd></div>
            </dl>
        </x-filament::section>
        <div class="flex flex-wrap gap-3">
            @if ($order['state'] === 'prepared')<x-filament::button color="warning" wire:click="cancelOrder" wire:confirm="{{ __('commerce::checkout.cancel_confirm') }}">{{ __('commerce::checkout.cancel_order') }}</x-filament::button>@endif
            <x-filament::button color="danger" wire:click="eraseOrder" wire:confirm="{{ __('commerce::checkout.erase_confirm') }}">{{ __('commerce::checkout.erase_order') }}</x-filament::button>
        </div>
    @endif
</x-filament-panels::page>
