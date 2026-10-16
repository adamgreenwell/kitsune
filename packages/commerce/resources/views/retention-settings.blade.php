{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<x-filament-panels::page>
    <style>
        .commerce-retention { display: grid; gap: 1.5rem; min-width: 0; overflow-wrap: anywhere; }
        .commerce-retention .retention-stack { display: grid; gap: 1rem; min-width: 0; }
        .commerce-retention .retention-form { margin-top: 1rem; }
        .commerce-retention .retention-form > .fi-btn { justify-self: start; }
        .commerce-retention fieldset { min-width: 0; }
        .commerce-retention legend,
        .commerce-retention h3 { font-weight: 600; }
        .commerce-retention legend { margin-bottom: .5rem; }
        .commerce-retention .retention-options { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr)); gap: .75rem 1.5rem; }
        .commerce-retention .retention-checkbox { display: flex; align-items: flex-start; gap: .625rem; line-height: 1.5; }
        .commerce-retention .retention-checkbox input { flex: 0 0 auto; width: 1rem; height: 1rem; margin-top: .25rem; }
        .commerce-retention .retention-field { min-width: 0; }
        .commerce-retention .retention-field > label { display: block; margin-bottom: .375rem; font-weight: 500; }
        .commerce-retention .retention-help { margin-top: .375rem; font-size: .875rem; line-height: 1.5; }
        .commerce-retention .retention-notice { margin-top: .75rem; }
        .commerce-retention .retention-versions { display: grid; gap: 1.25rem; padding: 0; margin-top: 1rem; list-style: none; }
        .commerce-retention .retention-version { display: grid; gap: .625rem; padding-top: 1.25rem; border-top: 1px solid color-mix(in srgb, currentColor 18%, transparent); }
        .commerce-retention .retention-version:first-child { padding-top: 0; border-top: 0; }
        .commerce-retention .retention-version > .fi-btn { justify-self: start; }
        .commerce-retention .retention-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .commerce-retention .retention-details { display: grid; gap: .625rem; padding: 1rem; border: 1px solid color-mix(in srgb, currentColor 18%, transparent); border-radius: .5rem; overflow-wrap: anywhere; }
        .commerce-retention .retention-holds { display: grid; gap: 1rem; }
        .commerce-retention .retention-hold { display: grid; gap: .5rem; }
        .commerce-retention .retention-hold > .fi-btn { justify-self: start; }
    </style>
    <div class="commerce-retention">
    <p>{{ __('commerce::retention.scope') }}</p>
    <x-filament::section :heading="__('commerce::retention.policy_heading')">
        <p>{{ __('commerce::retention.policy_help') }}</p>
        <form wire:submit="createPolicy" class="retention-stack retention-form">
            <fieldset class="retention-stack">
                <legend>{{ __('commerce::retention.fields') }}</legend>
                <p>{{ __('commerce::retention.fields_help') }}</p>
                <div class="retention-options">
                    @foreach ($this->billingFields() as $field)
                        <label class="retention-checkbox"><input type="checkbox" wire:model="policy.billing_fields" value="{{ $field }}">{{ __('commerce::retention.'.$field) }}</label>
                    @endforeach
                </div>
            </fieldset>
            @foreach (['legal_basis', 'reason'] as $field)
                <div class="retention-field"><label for="retention-{{ $field }}">{{ __('commerce::retention.'.$field) }}</label>
                    <x-filament::input.wrapper><x-filament::input id="retention-{{ $field }}" wire:model="policy.{{ $field }}" type="text" :maxlength="$field === 'legal_basis' ? 500 : 2000" required /></x-filament::input.wrapper>
                </div>
            @endforeach
            <div class="retention-field"><label for="retention-clock">{{ __('commerce::retention.clock') }}</label>
                <x-filament::input.wrapper><x-filament::input.select id="retention-clock" wire:model.live="policy.clock" required>
                    <option value="">{{ __('commerce::retention.choose') }}</option>
                    @foreach (['confirmation_days', 'payment_year_end'] as $clock)<option value="{{ $clock }}">{{ __('commerce::retention.'.$clock) }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </div>
            <div class="retention-field"><label for="retention-duration">{{ __('commerce::retention.duration') }}</label>
                <x-filament::input.wrapper><x-filament::input id="retention-duration" wire:model="policy.duration" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="5" required /></x-filament::input.wrapper>
                <p class="retention-help">{{ __('commerce::retention.duration_help') }}</p>
            </div>
            @if (($this->policy['clock'] ?? '') === 'payment_year_end')
                <div class="retention-field"><label for="retention-timezone">{{ __('commerce::retention.timezone') }}</label>
                    <x-filament::input.wrapper><x-filament::input.select id="retention-timezone" wire:model="policy.timezone" required>
                        <option value="">{{ __('commerce::retention.choose') }}</option>
                        @foreach ($this->timezones() as $timezone)<option value="{{ $timezone }}">{{ $timezone }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                    <p class="retention-help">{{ __('commerce::retention.timezone_help') }}</p>
                </div>
            @endif
            <div role="alert">@error('policy') <p>{{ $message }}</p> @enderror</div>
            <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::retention.create') }}</x-filament::button>
        </form>
    </x-filament::section>
    <x-filament::section :heading="__('commerce::retention.versions')">
        <p>{{ __('commerce::retention.versions_help') }}</p>
        @php($current = $this->currentVersion())
        @if ($current === null && ! $errors->has('retention'))<p class="retention-notice">{{ __('commerce::retention.no_current') }}</p>@endif
        <div role="alert">@error('retention') <p>{{ $message }}</p> @enderror</div>
        <ul class="retention-versions">
            @forelse ($this->versions() as $version)
                <li wire:key="retention-version-{{ $version->version }}" class="retention-version">
                    <h3>{{ __('commerce::retention.version', ['version' => $version->version]) }} — {{ __('commerce::retention.'.($version->version === $current ? 'current' : 'inactive')) }}</h3>
                    <p>{{ __('commerce::retention.'.$version->clock) }}: {{ $version->duration }}@if ($version->timezone !== null) ({{ $version->timezone }})@endif</p>
                    <p>{{ __('commerce::retention.legal_basis') }}: {{ $version->legalBasis }}</p>
                    <p>{{ __('commerce::retention.reason') }}: {{ $version->reason }}</p>
                    <p>{{ __('commerce::retention.fields') }}: @if ($version->billingFields === []){{ __('commerce::retention.no_fields') }}@else{{ implode(', ', array_map(static fn (string $field): string => __('commerce::retention.'.$field), $version->billingFields)) }}@endif</p>
                    @if ($version->version !== $current)<x-filament::button type="button" size="sm" wire:click="activatePolicy({{ $version->version }})" wire:confirm="{{ __('commerce::retention.activate_confirm') }}">{{ __('commerce::retention.activate') }}</x-filament::button>@endif
                </li>
            @empty @if (! $errors->has('retention'))<li>{{ __('commerce::retention.none') }}</li>@endif @endforelse
        </ul>
    </x-filament::section>
    <x-filament::section :heading="__('commerce::retention.order_heading')">
        <p>{{ __('commerce::retention.order_help') }}</p>
        <div class="retention-stack retention-form">
            <div class="retention-field"><label for="retention-order">{{ __('commerce::retention.order_reference') }}</label>
                <x-filament::input.wrapper><x-filament::input id="retention-order" wire:model="orderReference" type="text" pattern="[a-f0-9]{32}" maxlength="32" /></x-filament::input.wrapper></div>
            <div class="retention-field"><label for="retention-order-policy">{{ __('commerce::retention.order_policy') }}</label>
                <x-filament::input.wrapper><x-filament::input.select id="retention-order-policy" wire:model="orderPolicyVersion">
                    <option value="">{{ __('commerce::retention.choose') }}</option>
                    @foreach ($this->versions() as $version)<option value="{{ $version->version }}">{{ __('commerce::retention.version', ['version' => $version->version]) }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper></div>
            <div class="retention-field"><label for="retention-order-reason">{{ __('commerce::retention.order_reason') }}</label>
                <x-filament::input.wrapper><x-filament::input id="retention-order-reason" wire:model="orderReason" type="text" maxlength="2000" /></x-filament::input.wrapper></div>
            <div class="retention-field"><label for="retention-review">{{ __('commerce::retention.review_date') }}</label>
                <x-filament::input.wrapper><x-filament::input id="retention-review" wire:model="reviewDate" type="date" /></x-filament::input.wrapper></div>
            <div class="retention-field"><label for="retention-hold">{{ __('commerce::retention.hold_reference') }}</label>
                <x-filament::input.wrapper><x-filament::input id="retention-hold" wire:model="holdReference" type="text" maxlength="64" /></x-filament::input.wrapper></div>
            <label class="retention-checkbox"><input type="checkbox" wire:model="confirmed">{{ __('commerce::retention.confirmed') }}</label>
            <div role="alert">@error('order') <p>{{ $message }}</p> @enderror</div>
            <div class="retention-actions">
                @foreach (['adoptPolicy' => 'adopt', 'placeHold' => 'hold', 'releaseHold' => 'release_hold', 'completeDelivery' => 'complete_delivery'] as $action => $label)
                    <x-filament::button type="button" color="gray" wire:click="{{ $action }}" wire:loading.attr="disabled">{{ __('commerce::retention.'.$label) }}</x-filament::button>
                @endforeach
            </div>
            <p>{{ __('commerce::retention.delivery_help') }}</p>
            <x-filament::button type="button" color="gray" wire:click="loadHolds" wire:loading.attr="disabled">{{ __('commerce::retention.load_holds') }}</x-filament::button>
            @if ($this->holdsRequested)
                <section class="retention-details" aria-label="{{ __('commerce::retention.open_holds') }}">
                    <h3>{{ __('commerce::retention.open_holds') }}</h3>
                    <ul class="retention-holds">@foreach ($this->holds() as $hold)<li class="retention-hold">
                        <p dir="ltr">{{ $hold['reference'] }}</p><p>{{ $hold['reason'] }}</p>
                        <p>{{ __('commerce::retention.hold_review', ['instant' => $hold['review_at']]) }}</p>
                        <x-filament::button type="button" size="sm" color="gray" wire:click="selectHold('{{ $hold['reference'] }}')">{{ __('commerce::retention.select_hold') }}</x-filament::button>
                    </li>@endforeach</ul>
                </section>
            @endif
            <x-filament::button type="button" color="gray" wire:click="loadDelivery" wire:loading.attr="disabled">{{ __('commerce::retention.load_delivery') }}</x-filament::button>
            @php($essentialDelivery = $this->deliveryDetails())
            @if ($essentialDelivery !== null)
                <section class="retention-details" aria-label="{{ __('commerce::retention.essential_delivery') }}" data-essential-delivery>
                    <h3>{{ __('commerce::retention.essential_delivery') }}</h3>
                    <p>{{ $essentialDelivery['contact_email'] }}</p>
                    <p>{{ $essentialDelivery['delivery']['name'] }}</p>
                    @foreach ($essentialDelivery['delivery']['address'] as $part)@if ($part !== '')<p>{{ $part }}</p>@endif @endforeach
                    <p>{{ __('commerce::retention.delivery_review', ['instant' => $essentialDelivery['review_at']]) }}</p>
                </section>
            @endif
        </div>
    </x-filament::section>
    <x-filament::section :heading="__('commerce::retention.maintenance_heading')">
        <p>{{ __('commerce::retention.maintenance_help') }}</p>
        <div class="retention-stack retention-form">
            <div class="retention-field"><label for="retention-limit">{{ __('commerce::retention.batch_limit') }}</label>
                <x-filament::input.wrapper><x-filament::input id="retention-limit" wire:model="batchLimit" type="text" inputmode="numeric" pattern="[0-9]+" maxlength="4" /></x-filament::input.wrapper></div>
            <div class="retention-actions">
                @foreach (['previewOrder' => 'preview_order', 'applyOrder' => 'apply_order', 'previewBatch' => 'preview_batch', 'applyBatch' => 'apply_batch'] as $action => $label)
                    <x-filament::button type="button" color="gray" wire:click="{{ $action }}" wire:loading.attr="disabled">{{ __('commerce::retention.'.$label) }}</x-filament::button>
                @endforeach
            </div>
            @if ($this->maintenanceResult !== null)<p role="status">{{ __('commerce::retention.'.($this->previewOnly ? 'preview_result' : 'applied_result')) }}: {{ __('commerce::retention.result', $this->maintenanceResult) }}</p>@endif
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
