{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<x-filament-panels::page>
    <x-filament::section :heading="__('commerce::catalogue.currency_section')">
        <p>{{ __('commerce::catalogue.currency_help') }}</p>
        <form wire:submit="saveCurrency" class="mt-6 space-y-4">
            <label for="org-currency" class="block font-medium">{{ __('commerce::catalogue.currency_label') }}</label>
            <x-filament::input.wrapper :valid="! $errors->has('currency')">
                <x-filament::input.select id="org-currency" wire:model="currency" aria-invalid="{{ $errors->has('currency') ? 'true' : 'false' }}" aria-describedby="currency-policy currency-error">
                    <option value="">{{ __('commerce::catalogue.currency_unset') }}</option>
                    @foreach ($this->currencies() as $code => $metadata)
                        <option value="{{ $code }}">{{ $code }} — {{ $metadata['name'] }} ({{ trans_choice('commerce::catalogue.decimal_places', $metadata['minor_units'], ['count' => $metadata['minor_units']]) }})</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
            <p id="currency-policy" class="text-sm">{{ __('commerce::catalogue.currency_policy') }}</p>
            <div id="currency-error" role="status">
                @error('currency') <p>{{ $message }}</p> @enderror
            </div>
            <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::catalogue.currency_save') }}</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
