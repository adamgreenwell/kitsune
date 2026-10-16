{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@if (($order['privacy'] ?? null) !== null)
    <section class="space-y-2" aria-label="{{ __('commerce::retention.summary', [], 'en') }}" data-privacy-summary>
        <h2 class="font-semibold">{{ __('commerce::retention.summary', [], 'en') }}</h2>
        @if ($order['privacy']['subject_erased'])<p>{{ __('commerce::retention.subject_erased', [], 'en') }}</p>@endif
        @if ($order['privacy']['invoice_expired'])<p>{{ __('commerce::retention.invoice_expired', [], 'en') }}</p>@endif
        @if ($order['privacy']['retained_until'] !== null)<p>{{ __('commerce::retention.retained_until', ['instant' => $order['privacy']['retained_until']], 'en') }}</p>@endif
        @if ($order['privacy']['policy_version'] !== null)<p>{{ __('commerce::retention.recorded_version', ['version' => $order['privacy']['policy_version']], 'en') }}</p>@endif
        @if ($order['privacy']['delivery_pending'])<p>{{ __('commerce::retention.delivery_pending', [], 'en') }}</p>@endif
        @if ($order['privacy']['legal_hold'])<p>{{ __('commerce::retention.legal_hold', [], 'en') }}</p>@endif
        @if ($order['privacy']['minimized_at'] !== null)<p>{{ __('commerce::retention.minimized_at', ['instant' => $order['privacy']['minimized_at']], 'en') }}</p>@endif
    </section>
@endif
