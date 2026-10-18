{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<x-filament-panels::page>
    <x-filament::section :heading="__('commerce::checkout.settings_section')">
        <p>{{ __('commerce::checkout.settings_help') }}</p>
        <form wire:submit="saveSettings" class="mt-4 space-y-4">
            @foreach (['tax_mode', 'shipping_tax_mode'] as $field)
                <div>
                    <label for="checkout-{{ $field }}" class="block font-medium">{{ __('commerce::checkout.'.$field) }}</label>
                    <x-filament::input.wrapper><x-filament::input.select id="checkout-{{ $field }}" wire:model="settings.{{ $field }}" required>
                        <option value="">{{ __('commerce::checkout.choose') }}</option>
                        @foreach (['inclusive', 'exclusive'] as $mode)<option value="{{ $mode }}">{{ __('commerce::checkout.'.$mode) }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </div>
            @endforeach
            <div>
                <label for="checkout-shipping-class" class="block font-medium">{{ __('commerce::checkout.shipping_tax_class') }}</label>
                <x-filament::input.wrapper><x-filament::input id="checkout-shipping-class" wire:model="settings.shipping_tax_class" type="text" maxlength="64" required /></x-filament::input.wrapper>
                <p class="text-sm">{{ __('commerce::checkout.tax_class_help') }}</p>
            </div>
            <div>
                <label for="checkout-dispatch-name" class="block font-medium">{{ __('commerce::checkout.ship_from_name') }}</label>
                <x-filament::input.wrapper><x-filament::input id="checkout-dispatch-name" wire:model="settings.ship_from_name" type="text" maxlength="200" /></x-filament::input.wrapper>
            </div>
            <fieldset class="space-y-3">
                <legend class="font-medium">{{ __('commerce::checkout.origin') }}</legend>
                <p>{{ __('commerce::checkout.origin_help') }}</p>
                @foreach (['line1', 'line2', 'city', 'region', 'postal_code', 'country'] as $field)
                    <div><label for="checkout-address-{{ $field }}" class="block">{{ __('commerce::checkout.'.$field) }}</label>
                    <x-filament::input.wrapper><x-filament::input id="checkout-address-{{ $field }}" wire:model="settings.ship_from_address.{{ $field }}" type="text" /></x-filament::input.wrapper></div>
                @endforeach
            </fieldset>
            <fieldset class="space-y-3">
                <legend class="font-medium">{{ __('commerce::checkout.international_title') }}</legend>
                <p>{{ __('commerce::checkout.international_help') }}</p>
                <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="international.enabled"> {{ __('commerce::checkout.international_enabled') }}</label>
                @if ($this->international['enabled'] ?? false)
                    @foreach (['destinations', 'test_accounts', 'live_accounts'] as $field)
                        <div><label for="checkout-international-{{ $field }}" class="block font-medium">{{ __('commerce::checkout.international_'.$field) }}</label>
                            <textarea id="checkout-international-{{ $field }}" wire:model="international.{{ $field }}" rows="3" maxlength="4096" class="w-full rounded-lg border border-gray-300 bg-white p-2 text-gray-950 dark:border-gray-700 dark:bg-gray-900 dark:text-white"></textarea></div>
                    @endforeach
                    <div><label for="checkout-certifier" class="block font-medium">{{ __('commerce::checkout.certify_signer') }}</label>
                        <x-filament::input.wrapper><x-filament::input id="checkout-certifier" wire:model="international.certify_signer" type="text" maxlength="200" required /></x-filament::input.wrapper></div>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model="international.certified"> {{ __('commerce::checkout.certified') }}</label>
                @endif
            </fieldset>
            <div role="alert">@error('settings') <p>{{ $message }}</p> @enderror</div>
            <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::checkout.save_settings') }}</x-filament::button>
        </form>
    </x-filament::section>
    <x-filament::section :heading="__('commerce::checkout.rates_section')">
        <p>{{ __('commerce::checkout.rates_help') }}</p>
        <form wire:submit="saveTaxRate" class="mt-4 space-y-4">
            @foreach (['country', 'region', 'tax_class', 'percentage'] as $field)
                <div><label for="checkout-tax-{{ $field }}" class="block font-medium">{{ __('commerce::checkout.'.($field === 'region' ? 'tax_region' : $field)) }}</label>
                <x-filament::input.wrapper><x-filament::input id="checkout-tax-{{ $field }}" wire:model="taxRate.{{ $field }}" type="text" required /></x-filament::input.wrapper></div>
            @endforeach
            <div role="alert">@error('taxRate') <p>{{ $message }}</p> @enderror</div>
            <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::checkout.save_tax') }}</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="newTaxRate">{{ __('commerce::checkout.new_tax') }}</x-filament::button>
        </form>
        @php($rates = $this->taxRates())
        <ul class="mt-6 space-y-3">
            @forelse ($rates as $rate)
                <li wire:key="tax-rule-{{ $rate->id }}-{{ $rate->version }}" class="flex flex-wrap items-center gap-3">
                    <span>{{ $rate->country }} / {{ $rate->region }} — {{ $rate->tax_class }} — {{ \Kitsune\Commerce\Filament\CheckoutSettings::percentage($rate->rate_parts) }}%</span>
                    <x-filament::button size="sm" color="gray" wire:click="editTaxRate({{ $rate->id }})">{{ __('commerce::checkout.edit_tax') }}</x-filament::button>
                    <x-filament::button size="sm" color="danger" wire:click="removeTaxRate({{ $rate->id }}, {{ $rate->version }})" wire:confirm="{{ __('commerce::checkout.remove_tax_confirm') }}">{{ __('commerce::checkout.remove_tax') }}</x-filament::button>
                </li>
            @empty <li>{{ __('commerce::checkout.empty_rates') }}</li> @endforelse
        </ul>
        {{ $rates->links() }}
    </x-filament::section>
    <x-filament::section :heading="__('commerce::checkout.products_section')">
        <p>{{ __('commerce::checkout.products_help') }}</p>
        @php($products = $this->products())
        <ul class="mt-4 space-y-3">
            @forelse ($products as $product)
                <li wire:key="delivery-product-{{ $product->id }}" class="flex items-center gap-3"><span>{{ $product->sku }}</span>
                    <x-filament::button size="sm" color="gray" wire:click="editPolicy({{ $product->id }})">{{ __('commerce::checkout.edit_policy') }}</x-filament::button>
                </li>
            @empty <li>{{ __('commerce::checkout.empty_products') }}</li> @endforelse
        </ul>
        {{ $products->links() }}
        @if ($this->sellableId !== null)
            <form wire:submit="savePolicy" class="mt-6 space-y-4">
                <h3 class="font-medium">{{ __('commerce::checkout.policy_heading', ['sku' => $this->sku]) }}</h3>
                <div><label for="checkout-policy-class" class="block font-medium">{{ __('commerce::checkout.tax_class') }}</label>
                    <x-filament::input.wrapper><x-filament::input id="checkout-policy-class" wire:model="policy.tax_class" type="text" maxlength="64" required /></x-filament::input.wrapper></div>
                <div><label for="checkout-delivery-kind" class="block font-medium">{{ __('commerce::checkout.delivery_kind') }}</label>
                    <x-filament::input.wrapper><x-filament::input.select id="checkout-delivery-kind" wire:model.live="policy.delivery_kind" required>
                        <option value="">{{ __('commerce::checkout.choose') }}</option>
                        @foreach (['digital', 'physical'] as $kind)<option value="{{ $kind }}">{{ __('commerce::checkout.'.$kind) }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper></div>
                @if (($this->policy['delivery_kind'] ?? '') === 'physical')
                    <p>{{ __('commerce::checkout.packing_help') }}</p>
                    @foreach (\Kitsune\Commerce\Models\SalesPolicy::PACKING_FIELDS as $field)
                        <div><label for="checkout-pack-{{ $field }}" class="block font-medium">{{ __('commerce::checkout.'.$field) }}</label>
                            <x-filament::input.wrapper><x-filament::input id="checkout-pack-{{ $field }}" wire:model="policy.{{ $field }}" type="text" inputmode="numeric" pattern="[0-9]+" required /></x-filament::input.wrapper></div>
                    @endforeach
                    <p>{{ __('commerce::checkout.customs_help') }}</p>
                    @foreach (\Kitsune\Commerce\Shipping\InternationalShipping::SKU_FIELDS as $field)
                        <div><label for="checkout-{{ $field }}" class="block font-medium">{{ __('commerce::checkout.'.$field) }}</label>
                            <x-filament::input.wrapper><x-filament::input id="checkout-{{ $field }}" wire:model="policy.{{ $field }}" type="text" :maxlength="match($field) {'customs_description' => 50, 'customs_origin_country' => 2, default => 12}" /></x-filament::input.wrapper></div>
                    @endforeach
                @endif
                <div role="alert">@error('policy') <p>{{ $message }}</p> @enderror</div>
                <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('commerce::checkout.save_policy') }}</x-filament::button>
                @if ($this->policyVersion !== null)<x-filament::button type="button" color="danger" wire:click="removePolicy" wire:confirm="{{ __('commerce::checkout.remove_policy_confirm') }}">{{ __('commerce::checkout.remove_policy') }}</x-filament::button>@endif
            </form>
        @endif
    </x-filament::section>
</x-filament-panels::page>
