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
                @if ($order['contact_email'] !== null)<div><dt class="font-medium">{{ __('commerce::checkout.contact_email') }}</dt><dd>{{ $order['contact_email'] }}</dd></div>@endif
                <div><dt class="font-medium">{{ __('commerce::checkout.created') }}</dt><dd>{{ $this->instant($order['reference'], $order['created_at']) }}</dd></div>
            </dl>
        </x-filament::section>
        @foreach (['billing', 'delivery'] as $kind)
            @if ($order[$kind] !== null)
                <x-filament::section :heading="__('commerce::checkout.'.($kind === 'billing' ? 'billing' : 'delivery_address'))">
                    @if (isset($order[$kind]['name']))<p>{{ $order[$kind]['name'] }}</p>@endif
                    @foreach ($order[$kind]['address'] ?? [] as $part) @if ($part !== '')<p>{{ $part }}</p>@endif @endforeach
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
                <div><dt class="font-medium">{{ __('commerce::checkout.payment') }}</dt><dd>{{ __($order['payment'] === null ? 'commerce::checkout.not_charged' : 'commerce::paid.paid') }}</dd></div>
            </dl>
        </x-filament::section>
        @include('commerce::orders.payment-summary')
        @include('commerce::orders.access-summary', ['operator' => true])
        @include('commerce::orders.privacy-summary', ['order' => $order])
        @if ($order['payment'] !== null)
            @include('commerce::orders.financial-actions', ['actions' => $this->financialDetail()])
        @endif
        @if ($order['payment'] !== null && in_array($order['access_fulfillment']['state'] ?? null, ['initializing', 'pending', 'held'], true))
            <x-filament::section :heading="__('commerce::access.recovery')">
                <p>{{ __('commerce::access.recovery_help') }}</p>
                <form wire:submit="retryAccess" class="mt-3">
                    <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::access.check') }}</x-filament::button>
                </form>
            </x-filament::section>
        @endif
        @if ($order['refund'] !== null && $order['refund']['attempts'] !== [])
            @php($latestRefund = $order['refund']['attempts'][array_key_last($order['refund']['attempts'])])
            <x-filament::section :heading="__('commerce::paid.refund_actions')">
                <p>{{ __('commerce::paid.recovery_help') }}</p>
                @foreach (['refund_reconcile' => 'reconcile', 'refund_retry' => 'retry'] as $recoveryKind => $label)
                    @if ($recoveryKind === 'refund_reconcile' || in_array($latestRefund['state'], ['failed', 'canceled'], true))
                        <details class="space-y-3">
                            <summary class="cursor-pointer underline">{{ __('commerce::paid.'.$label) }}</summary>
                            @if ($recoveryKind === 'refund_retry')<p>{{ __('commerce::paid.retry_help') }}</p>@endif
                            <form action="{{ $this->historicalRecoveryUrl() }}" method="post" class="space-y-3" autocomplete="off" data-refund-recovery="{{ $recoveryKind }}">
                                @csrf
                                <input type="hidden" name="recovery_kind" value="{{ $recoveryKind }}">
                                <input type="hidden" name="order_reference" value="{{ $order['reference'] }}">
                                <input type="hidden" name="attempt_reference" value="{{ $latestRefund['reference'] }}">
                                <label for="refund-key-{{ $recoveryKind }}">{{ __('commerce::payments.historical_key') }}</label>
                                <input id="refund-key-{{ $recoveryKind }}" name="password" type="password" required maxlength="255" autocomplete="new-password" class="block w-full rounded-lg border p-2" dir="ltr">
                                <label for="refund-password-{{ $recoveryKind }}">{{ __('commerce::payments.owner_password') }}</label>
                                <input id="refund-password-{{ $recoveryKind }}" name="current_password" type="password" required maxlength="4096" autocomplete="current-password" class="block w-full rounded-lg border p-2">
                                <x-filament::button type="submit">{{ __('commerce::paid.'.$label) }}</x-filament::button>
                            </form>
                        </details>
                    @endif
                @endforeach
            </x-filament::section>
        @endif
        @if ($order['refund'] !== null && $order['refund']['attempts'] === [])<p>{{ __('commerce::paid.unrepresentable_refund') }}</p>@endif
        @if ($order['checkout_attempts'] !== [])
            <x-filament::section :heading="__('commerce::payments.history')">
                <p>{{ __('commerce::payments.test_only') }}</p>
                @foreach ($order['checkout_attempts'] as $attempt)
                    <div class="space-y-3 py-3">
                        <p>{{ __('commerce::payments.attempt') }}: <span dir="ltr">{{ $attempt['reference'] }}</span></p>
                        <p>{{ __('commerce::payments.'.$attempt['state']) }}</p>
                        <p>{{ __('commerce::payments.expires') }}: <span dir="ltr">{{ $attempt['expires_at'] }}</span></p>
                        @if (in_array($attempt['state'], ['creating', 'open', 'uncertain'], true))
                            <p>{{ __('commerce::payments.operator_help') }}</p>
                            <form wire:submit="recoverAttempt('{{ $attempt['reference'] }}')" class="space-y-3">
                                <label for="recovery-session-{{ $attempt['reference'] }}">{{ __('commerce::payments.session_id') }}</label>
                                <input id="recovery-session-{{ $attempt['reference'] }}" type="text" wire:model="recoverySession" maxlength="255" autocomplete="off" class="block w-full rounded-lg border p-2" dir="ltr">
                                <label class="flex gap-2"><input type="checkbox" wire:model="recoveryExpire">{{ __('commerce::payments.expire_after_recovery') }}</label>
                                <x-filament::button type="submit">{{ __('commerce::payments.recover') }}</x-filament::button>
                            </form>
                        @endif
                        @if (in_array($attempt['state'], ['creating', 'open', 'uncertain'], true) || ($attempt['state'] === 'completed' && $order['payment'] === null))
                            <details class="space-y-3">
                                <summary class="cursor-pointer underline">{{ __('commerce::payments.historical_recover') }}</summary>
                                <p>{{ __('commerce::payments.historical_help') }}</p>
                                <form action="{{ $this->historicalRecoveryUrl() }}" method="post" class="space-y-3" autocomplete="off">
                                    @csrf
                                    <input type="hidden" name="order_reference" value="{{ $order['reference'] }}">
                                    <input type="hidden" name="attempt_reference" value="{{ $attempt['reference'] }}">
                                    <label for="historical-session-{{ $attempt['reference'] }}">{{ __('commerce::payments.session_id') }}</label>
                                    <input id="historical-session-{{ $attempt['reference'] }}" name="session_id" type="text" maxlength="255" autocomplete="off" class="block w-full rounded-lg border p-2" dir="ltr">
                                    <label for="historical-key-{{ $attempt['reference'] }}">{{ __('commerce::payments.historical_key') }}</label>
                                    <input id="historical-key-{{ $attempt['reference'] }}" name="password" type="password" required maxlength="255" autocomplete="new-password" class="block w-full rounded-lg border p-2" dir="ltr">
                                    <label for="historical-password-{{ $attempt['reference'] }}">{{ __('commerce::payments.owner_password') }}</label>
                                    <input id="historical-password-{{ $attempt['reference'] }}" name="current_password" type="password" required maxlength="4096" autocomplete="current-password" class="block w-full rounded-lg border p-2">
                                    <x-filament::button type="submit">{{ __('commerce::payments.historical_recover') }}</x-filament::button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endforeach
            </x-filament::section>
        @endif
        @if ($order['stripe_events'] !== [])
            <x-filament::section :heading="__('commerce::payments.updates')">
                @foreach ($order['stripe_events'] as $update)
                    <p>{{ __('commerce::payments.update_'.$update['state']) }} — {{ __('commerce::payments.update_received') }}: <span dir="ltr">{{ $update['received_at'] }}</span></p>
                @endforeach
            </x-filament::section>
        @endif
        <div class="flex flex-wrap gap-3">
            @php($completedCheckout = in_array('completed', array_column($order['checkout_attempts'], 'state'), true))
            @if ($order['state'] === 'prepared' && ! $completedCheckout)<x-filament::button color="warning" wire:click="cancelOrder" wire:confirm="{{ __('commerce::checkout.cancel_confirm') }}">{{ __('commerce::checkout.cancel_order') }}</x-filament::button>@endif
            @if ($order['payment'] === null && ! $completedCheckout)<x-filament::button color="danger" wire:click="eraseOrder" wire:confirm="{{ __('commerce::checkout.erase_confirm') }}">{{ __('commerce::checkout.erase_order') }}</x-filament::button>@endif
        </div>
        @if ($order['payment'] !== null || $completedCheckout)<p>{{ __('commerce::paid.'.($order['payment'] !== null ? 'retained' : 'completed_retained')) }}</p>@endif
    @endif
</x-filament-panels::page>
