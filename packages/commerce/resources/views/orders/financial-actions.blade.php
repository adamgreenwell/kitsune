{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<x-filament::section :heading="__('commerce::financial.title')">
    @if ($actions['history'] !== [])
        <details class="space-y-3 py-3" data-owner-financial-history>
            <summary class="cursor-pointer underline">{{ __('commerce::financial.owner_history') }}</summary>
            @foreach ($actions['history'] as $entry)
                <div class="space-y-1 py-2">
                    <p>{{ __('commerce::financial.'.($entry['purpose'] === 'held_balance' ? 'balance' : ($entry['origin'] === 'operator' ? 'operator' : 'external_origin'))) }} — {{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($entry['amount_minor'], $order['currency']) }} — {{ __('commerce::financial.'.$entry['state']) }}</p>
                    <p dir="auto">{{ $entry['reason'] }}</p>
                </div>
            @endforeach
        </details>
    @endif
    <p>{{ __('commerce::financial.refresh_help') }}</p>
    <form wire:submit="refreshFinancial" class="my-3">
        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::financial.refresh') }}</x-filament::button>
    </form>
    @foreach (['request' => 'requestRefund', 'external' => 'attributeRefund'] as $kind => $method)
        @if (($kind === 'request' && $actions['can_request']) || ($kind === 'external' && $actions['external'] !== []))
            <details class="space-y-3 py-3" data-financial-action="{{ $kind }}">
                <summary class="cursor-pointer underline">{{ __('commerce::financial.'.$kind) }}</summary>
                <p>{{ __('commerce::financial.'.($kind === 'request' ? 'request_help' : 'external_help')) }}</p>
                <form wire:submit="{{ $method }}" wire:confirm="{{ __('commerce::financial.'.($kind === 'request' ? 'request_confirm' : 'attribute_confirm')) }}" class="space-y-3">
                    @if ($kind === 'external')
                        <label for="external-refund">{{ __('commerce::financial.external_select') }}</label>
                        <select id="external-refund" wire:model="externalRefund" required class="block w-full rounded-lg border p-2">
                            <option value="">{{ __('commerce::financial.choose') }}</option>
                            @foreach ($actions['external'] as $external)
                                <option value="{{ $external['refund_id'] }}">{{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($external['amount_minor'], $order['currency']) }} — {{ __('commerce::financial.'.$external['state']) }} — {{ $external['refund_id'] }}</option>
                            @endforeach
                        </select>
                    @endif
                    <div class="overflow-x-auto"><table class="w-full text-start">
                        <caption class="sr-only">{{ __('commerce::financial.'.$kind) }}</caption>
                        <thead><tr>@foreach (['product', 'awarded', 'available', 'quantity'] as $field)<th scope="col" class="p-3 text-start">{{ __('commerce::financial.'.$field) }}</th>@endforeach</tr></thead>
                        <tbody>@foreach ($actions['lines'] as $line)
                            <tr><th scope="row" class="p-3 text-start" dir="auto">{{ $line['title'] }}</th><td class="p-3">{{ $line['awarded'] }}</td><td class="p-3">{{ $line['available'] }}</td>
                                <td class="p-3"><label for="{{ $kind }}-units-{{ $line['line_id'] }}" class="sr-only">{{ __('commerce::financial.quantity') }} — {{ $line['title'] }}</label>
                                    <input id="{{ $kind }}-units-{{ $line['line_id'] }}" type="number" min="0" max="{{ $line['available'] }}" step="1" wire:model="refundQuantities.{{ $line['line_id'] }}" @disabled($line['available'] === 0) class="w-24 rounded-lg border p-2"></td></tr>
                        @endforeach</tbody>
                    </table></div>
                    <label for="{{ $kind }}-refund-reason">{{ __('commerce::financial.reason') }}</label>
                    <input id="{{ $kind }}-refund-reason" wire:model="refundReason" required minlength="3" maxlength="500" class="block w-full rounded-lg border p-2" aria-describedby="{{ $kind }}-reason-help">
                    <p id="{{ $kind }}-reason-help">{{ __('commerce::financial.reason_help') }}</p>
                    <label class="flex gap-2"><input type="checkbox" wire:model="refundConfirmed" required>{{ __('commerce::financial.confirm') }}</label>
                    <x-filament::button type="submit" color="warning" wire:loading.attr="disabled">{{ __('commerce::financial.'.($kind === 'request' ? 'submit' : 'attribute')) }}</x-filament::button>
                </form>
            </details>
        @endif
    @endforeach
    @if ($actions['can_balance_refund'])
        <details class="space-y-3 py-3" data-financial-action="balance">
            <summary class="cursor-pointer underline">{{ __('commerce::financial.balance') }}</summary>
            <p>{{ __('commerce::financial.balance_help') }}</p>
            <p>{{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($actions['balance_minor'], $order['currency']) }}</p>
            <form wire:submit="requestBalanceRefund" wire:confirm="{{ __('commerce::financial.balance_prompt') }}" class="space-y-3">
                <label for="balance-refund-reason">{{ __('commerce::financial.reason') }}</label>
                <input id="balance-refund-reason" wire:model="refundReason" required minlength="3" maxlength="500" class="block w-full rounded-lg border p-2">
                <label class="flex gap-2"><input type="checkbox" wire:model="refundConfirmed" required>{{ __('commerce::financial.balance_confirm') }}</label>
                <x-filament::button type="submit" color="warning" wire:loading.attr="disabled">{{ __('commerce::financial.balance') }}</x-filament::button>
            </form>
        </details>
    @endif
    <details class="space-y-3 py-3">
        <summary class="cursor-pointer underline">{{ __('commerce::financial.recovery') }}</summary>
        <p>{{ __('commerce::financial.recovery_help') }}</p>
        @php($recoveries = [['kind' => 'financial_graph', 'reference' => '', 'label' => __('commerce::financial.refresh')]])
        @foreach (($order['financial_state']['refunds'] ?? []) as $refund)
            @if ($refund['origin'] === 'operator')
                @php($recoveries[] = ['kind' => 'financial_refund', 'reference' => $refund['reference'], 'label' => __('commerce::financial.recover_refund').' — '.$order['currency'].' '.\Kitsune\Commerce\Money\MinorUnits::format($refund['amount_minor'], $order['currency'])])
            @endif
        @endforeach
        @foreach ($recoveries as $index => $recovery)
            <form action="{{ $this->historicalRecoveryUrl() }}" method="post" class="space-y-3 py-3" autocomplete="off" data-financial-recovery="{{ $recovery['kind'] }}">
                @csrf
                <input type="hidden" name="recovery_kind" value="{{ $recovery['kind'] }}">
                <input type="hidden" name="order_reference" value="{{ $order['reference'] }}">
                <input type="hidden" name="attempt_reference" value="{{ $recovery['reference'] }}">
                <p>{{ $recovery['label'] }}</p>
                <label for="financial-key-{{ $index }}">{{ __('commerce::payments.historical_key') }}</label>
                <input id="financial-key-{{ $index }}" name="password" type="password" required maxlength="255" autocomplete="new-password" class="block w-full rounded-lg border p-2" dir="ltr">
                <label for="financial-password-{{ $index }}">{{ __('commerce::payments.owner_password') }}</label>
                <input id="financial-password-{{ $index }}" name="current_password" type="password" required maxlength="4096" autocomplete="current-password" class="block w-full rounded-lg border p-2">
                <x-filament::button type="submit">{{ $recovery['label'] }}</x-filament::button>
            </form>
        @endforeach
    </details>
</x-filament::section>
