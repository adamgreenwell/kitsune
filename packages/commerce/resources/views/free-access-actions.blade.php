{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<x-filament::section :heading="__('commerce::free.corrections')">
    <p>{{ __('commerce::free.correction_help') }}</p>
    <fieldset class="space-y-3">
        <legend>{{ __('commerce::paid.allocations') }}</legend>
        @foreach ($order['allocations'] as $allocation)
            @for ($unit = 1; $unit <= $allocation['awarded']; $unit++)
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model="freeUnits" value="{{ $allocation['line_ref'] }}:{{ $unit }}">
                    <span>{{ $allocation['sku'] }} — {{ __('commerce::free.unit') }} {{ $unit }}</span>
                </label>
            @endfor
        @endforeach
    </fieldset>
    <div class="space-y-2">
        <label for="free-access-reason">{{ __('commerce::free.reason') }}</label>
        <x-filament::input.wrapper><x-filament::input id="free-access-reason" wire:model="freeReason" maxlength="500" /></x-filament::input.wrapper>
    </div>
    <label class="flex items-center gap-3"><input type="checkbox" wire:model="freeConfirmed"><span>{{ __('commerce::free.confirm') }}</span></label>
    <div class="flex flex-wrap gap-3">
        <x-filament::button wire:click="correctFreeAccess('remove')" wire:loading.attr="disabled">{{ __('commerce::free.remove') }}</x-filament::button>
        <x-filament::button color="gray" wire:click="correctFreeAccess('restore')" wire:loading.attr="disabled">{{ __('commerce::free.restore') }}</x-filament::button>
    </div>
    @if ($order['free_access_corrections']['history'] !== [])
        <ul>@foreach ($order['free_access_corrections']['history'] as $correction)<li>{{ __('commerce::free.'.$correction['action']) }} — <span dir="ltr">{{ $correction['created_at'] }}</span></li>@endforeach</ul>
    @endif
</x-filament::section>
