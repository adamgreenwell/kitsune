{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<x-filament::section :heading="__('commerce::historical.title')">
    <p>{{ __('commerce::historical.help') }}</p>
    @if ($order['privacy']['invoice_expired'] ?? false)<p>{{ __('commerce::historical.expired_notes') }}</p>@endif
    <p>{{ __('commerce::historical.confirmed') }}: <span dir="ltr">{{ $order['access_fulfillment']['confirmed_at'] ?? '—' }}</span></p>
    <form wire:submit="reviewHistoricalAccess" class="space-y-4">
        <div>
            <label for="historical-access-line">{{ __('commerce::historical.line') }}</label>
            <x-filament::input.wrapper><x-filament::input.select id="historical-access-line" wire:model="reviewLine">
                <option value="">{{ __('commerce::historical.choose') }}</option>
                @php($originalSkus = array_column($order['allocations'], 'sku', 'line_ref'))
                @foreach ($order['historical_access']['candidates'] as $line => $candidate)
                    <option value="{{ $line }}">{{ $originalSkus[$line] }} — {{ $candidate['name'] }} ({{ $candidate['awarded'] }})</option>
                @endforeach
            </x-filament::input.select></x-filament::input.wrapper>
        </div>
        <div>
            <label for="historical-access-decision">{{ __('commerce::historical.decision') }}</label>
            <x-filament::input.wrapper><x-filament::input.select id="historical-access-decision" wire:model.live="reviewDecision">
                @foreach (['hold', 'none', 'award'] as $decision)<option value="{{ $decision }}">{{ __('commerce::historical.'.$decision) }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper>
        </div>
        @if ($this->reviewDecision === 'award')
            <div>
                <label for="historical-access-kind">{{ __('commerce::historical.kind') }}</label>
                <x-filament::input.wrapper><x-filament::input.select id="historical-access-kind" wire:model.live="reviewKind">
                    @foreach (['fixed', 'permanent'] as $kind)<option value="{{ $kind }}">{{ __('commerce::historical.'.$kind) }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </div>
            @if ($this->reviewKind === 'fixed')
                <div><label for="historical-access-days">{{ __('commerce::historical.days') }}</label>
                <x-filament::input.wrapper><x-filament::input id="historical-access-days" wire:model.live.debounce.300ms="reviewDays" type="number" min="1" max="36500" /></x-filament::input.wrapper></div>
                <p>{{ __('commerce::historical.expires') }}: <span dir="ltr">{{ $this->reviewPreview() ?? '—' }}</span></p>
                <p>{{ __('commerce::historical.expired_help') }}</p>
            @endif
        @endif
        <div><label for="historical-access-reason">{{ __('commerce::historical.reason') }}</label>
        <x-filament::input.wrapper><x-filament::input id="historical-access-reason" wire:model="reviewReason" maxlength="500" /></x-filament::input.wrapper></div>
        <div><label for="historical-access-evidence">{{ __('commerce::historical.evidence') }}</label>
        <x-filament::input.wrapper><x-filament::input id="historical-access-evidence" wire:model="reviewEvidence" maxlength="255" /></x-filament::input.wrapper>
        <p>{{ __('commerce::historical.evidence_help') }}</p></div>
        <label class="flex items-center gap-3"><input type="checkbox" wire:model="reviewConfirmed"><span>{{ __('commerce::historical.confirm') }}</span></label>
        <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::historical.save') }}</x-filament::button>
    </form>
    <ul>@foreach ($this->historicalReviews() as $review)
        <li>{{ $review['revision'] }} — {{ __('commerce::historical.'.$review['decision']) }} — {{ $review['reason'] }} — {{ $review['evidence'] }} — <span dir="ltr">{{ $review['created_at'] }}</span></li>
    @endforeach</ul>
</x-filament::section>
