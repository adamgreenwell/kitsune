{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@php($financial = $order['financial_state'] ?? null)
@if ($financial !== null && ($financial['refunds'] !== [] || $financial['observations'] !== []))
    <section class="space-y-3" aria-label="{{ __('commerce::financial.title', [], 'en') }}">
        <h2 class="font-semibold">{{ __('commerce::financial.title', [], 'en') }}</h2>
        <p>{{ __('commerce::financial.history_help', [], 'en') }}</p>
        @foreach ($financial['refunds'] as $refund)
            <div class="space-y-1">
                <p>{{ __('commerce::financial.'.($refund['origin'] === 'operator' ? 'operator' : 'external_origin'), [], 'en') }} — {{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($refund['amount_minor'], $order['currency']) }}</p>
                <p>{{ __('commerce::financial.'.$refund['state'], [], 'en') }}</p>
                <ul>@foreach ($refund['selection'] as $selected)<li dir="auto">{{ __('commerce::financial.selection', $selected, 'en') }}</li>@endforeach</ul>
            </div>
        @endforeach
        @foreach ($financial['observations'] as $observation)
            <p>{{ __('commerce::financial.'.$observation['kind'], [], 'en') }} — {{ __('commerce::financial.'.$observation['state'], [], 'en') }} — {{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($observation['amount_minor'], $order['currency']) }}</p>
        @endforeach
    </section>
@endif
