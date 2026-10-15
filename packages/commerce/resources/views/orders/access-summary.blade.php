{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@php($access = $order['access_fulfillment'] ?? null)
@if ($access !== null && ($access['effects'] !== [] || in_array($access['state'], ['initializing', 'held'], true)))
    <section class="space-y-3" aria-label="{{ __('commerce::access.title', [], 'en') }}">
        <h2 class="font-semibold">{{ __('commerce::access.title', [], 'en') }}</h2>
        <p>{{ __('commerce::access.state_'.$access['state'], [], 'en') }}</p>
        @if ($access['category'] !== null)<p>{{ __('commerce::access.category_'.$access['category'], [], 'en') }}</p>@endif
        <ul>@foreach ($access['effects'] as $effect)
            <li class="space-y-1">
                @php($titles = array_values(array_unique(array_column(array_filter($order['lines'], fn (array $line) => $line['entitlement_name'] === $effect['entitlement']), 'title'))))
                <p dir="auto">{{ ($operator ?? false) ? $effect['entitlement'] : (implode(', ', $titles) ?: __('commerce::access.pass', [], 'en')) }} — {{ __('commerce::access.effect_'.$effect['state'], [], 'en') }}</p>
                @if ($effect['category'] === 'unknown_terms')
                    <p>{{ __('commerce::access.category_unknown_terms', [], 'en') }}</p>
                @elseif ($effect['expires_at'] !== null)
                    <p>{{ __('commerce::access.expires', [], 'en') }}: <span dir="ltr">{{ $effect['expires_at'] }}</span></p>
                @else
                    <p>{{ __('commerce::access.permanent', [], 'en') }}</p>
                @endif
            </li>
        @endforeach</ul>
        @if (collect($access['effects'])->contains(fn (array $effect) => $effect['expires_at'] !== null))<p>{{ __('commerce::access.term_help', [], 'en') }}</p>@endif
    </section>
@endif
