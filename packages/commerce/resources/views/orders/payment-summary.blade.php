{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@if (isset($order['free_completion']))
    <section class="space-y-3" aria-label="{{ __('commerce::free.completed', [], 'en') }}" data-free-completion-summary>
        <h2 class="font-semibold">{{ __('commerce::free.completed', [], 'en') }}</h2>
        <p>{{ __('commerce::free.help', [], 'en') }}</p>
        <p>{{ __('commerce::free.completed_at', [], 'en') }}: <span dir="ltr">{{ $order['free_completion']['completed_at'] }}</span></p>
        <ul>@foreach ($order['allocations'] as $allocation)
            <li><strong dir="ltr">{{ $allocation['sku'] }}</strong> — {{ __('commerce::paid.awarded', [], 'en') }}: {{ $allocation['awarded'] }} — {{ __('commerce::paid.unavailable', [], 'en') }}: {{ $allocation['unavailable'] }}</li>
        @endforeach</ul>
    </section>
@endif
@if ($order['payment'] !== null)
    <section class="space-y-3" aria-label="{{ __('commerce::paid.paid', [], 'en') }}" data-payment-summary>
        <h2 class="font-semibold">{{ __('commerce::paid.paid', [], 'en') }}</h2>
        <p>{{ __('commerce::paid.test_help', [], 'en') }}</p>
        <p>{{ __('commerce::paid.amount', [], 'en') }}: <span dir="ltr">{{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($order['payment']['amount_minor'], $order['currency']) }}</span></p>
        <p>{{ __('commerce::paid.verified', [], 'en') }}: <span dir="ltr">{{ $order['payment']['verified_at'] }}</span></p>
        <h3 class="font-semibold">{{ __('commerce::paid.allocations', [], 'en') }}</h3>
        <p>{{ __('commerce::paid.'.$order['payment']['allocation_state'], [], 'en') }}</p>
        @if ($order['payment']['category'] !== null)<p>{{ __('commerce::paid.held_'.$order['payment']['category'], [], 'en') }}</p>@endif
        <ul class="space-y-3">
            @foreach ($order['allocations'] as $allocation)
                <li>
                    <strong dir="ltr">{{ $allocation['sku'] }}</strong>
                    <p>{{ __('commerce::paid.awarded', [], 'en') }}: {{ $allocation['awarded'] }} — {{ __('commerce::paid.unavailable', [], 'en') }}: {{ $allocation['unavailable'] }}</p>
                    @if ($allocation['unavailable'] > 0)
                        <dl>
                            @foreach ($allocation['refund_components'] as $part => $amount)
                                <div><dt>{{ __('commerce::paid.'.$part, [], 'en') }}</dt><dd dir="ltr">{{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($amount, $order['currency']) }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($order['refund'] !== null)
            <h3 class="font-semibold">{{ __('commerce::paid.refund', [], 'en') }}</h3>
            <p>{{ __('commerce::paid.reason_'.$order['refund']['reason'], [], 'en') }}</p>
            <p>{{ __('commerce::paid.state_'.$order['refund']['state'], [], 'en') }}</p>
            <p>{{ __('commerce::paid.amount_refund', [], 'en') }}: <span dir="ltr">{{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($order['refund']['amount_minor'], $order['currency']) }}</span></p>
            <p>{{ __('commerce::paid.owed', [], 'en') }}: <span dir="ltr" data-refund-owed>{{ $order['currency'] }} {{ \Kitsune\Commerce\Money\MinorUnits::format($order['refund']['owed_minor'], $order['currency']) }}</span></p>
            <details>
                <summary>{{ __('commerce::paid.history', [], 'en') }}</summary>
                <ul>@foreach ($order['refund']['attempts'] as $refundAttempt)<li>{{ __('commerce::paid.state_'.$refundAttempt['state'], [], 'en') }} — <span dir="ltr">{{ $refundAttempt['updated_at'] }}</span></li>@endforeach</ul>
            </details>
        @endif
    </section>
@endif
