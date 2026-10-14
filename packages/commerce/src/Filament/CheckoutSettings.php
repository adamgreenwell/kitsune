<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Illuminate\Contracts\Pagination\Paginator;
use Kitsune\Commerce\Checkout\CommerceSetupWriter;
use Kitsune\Commerce\Models\CommerceSettings;
use Kitsune\Commerce\Models\SalesPolicy;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Models\TaxRate;
use Kitsune\Commerce\Taxes\TaxAmounts;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use Throwable;

/** Every action rechecks owner/module authority. Existing edits carry server-locked compare-and-swap versions. @internal */
final class CheckoutSettings extends Page
{
    use RestrictsFileUploadsToSchemaComponents;
    use WithPagination;

    protected static string $resource = CheckoutResource::class;

    protected string $view = 'commerce::checkout-settings';

    public mixed $settings = [];

    public mixed $taxRate = [];

    public mixed $policy = [];

    #[Locked]
    public ?int $settingsVersion = null;

    #[Locked]
    public ?int $taxRateId = null;

    #[Locked]
    public ?int $taxVersion = null;

    #[Locked]
    public ?int $sellableId = null;

    #[Locked]
    public ?int $policyVersion = null;

    #[Locked]
    public ?string $sku = null;

    public function mount(): void
    {
        self::authorizeResourceAccess();
        $this->loadSettings();
        $this->newTaxRate();
    }

    public function getTitle(): string
    {
        return __('commerce::checkout.title');
    }

    public function saveSettings(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('settings');

        try {
            if (! is_array($this->settings)) {
                throw new \InvalidArgumentException;
            }

            $values = $this->settings;
            $address = $values['ship_from_address'] ?? null;

            if (($values['ship_from_name'] ?? null) === '' && is_array($address) && array_filter($address, static fn ($part) => $part !== '') === []) {
                $values['ship_from_name'] = $values['ship_from_address'] = null;
            }

            $row = app(CommerceSetupWriter::class)->setSettings($values, $this->settingsVersion);
            $this->settingsVersion = $row->version;
        } catch (Throwable) {
            $this->addError('settings', __('commerce::checkout.refused'));

            return;
        }

        $this->saved('settings_saved');
    }

    public function newTaxRate(): void
    {
        self::authorizeResourceAccess();
        $this->taxRateId = $this->taxVersion = null;
        $this->taxRate = ['country' => '', 'region' => '*', 'tax_class' => '', 'percentage' => ''];
        $this->resetErrorBag('taxRate');
    }

    public function editTaxRate(mixed $id): void
    {
        self::authorizeResourceAccess();
        $row = TaxRate::query()->useWritePdo()->whereKey(TaxRate::setupKey($id))->firstOrFail();
        $this->taxRateId = (int) $row->getKey();
        $this->taxVersion = $row->version;
        $this->taxRate = ['country' => $row->country, 'region' => $row->region, 'tax_class' => $row->tax_class, 'percentage' => self::percentage($row->rate_parts)];
        $this->resetErrorBag('taxRate');
    }

    public function saveTaxRate(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('taxRate');

        try {
            if (! is_array($this->taxRate) || array_diff(array_keys($this->taxRate), ['country', 'region', 'tax_class', 'percentage']) !== []) {
                throw new \InvalidArgumentException;
            }

            $values = $this->taxRate;
            $values['rate_parts'] = TaxAmounts::rate($values['percentage'] ?? null);
            unset($values['percentage']);
            app(CommerceSetupWriter::class)->saveTaxRate($this->taxRateId, $values, $this->taxVersion);
            $this->newTaxRate();
        } catch (Throwable) {
            $this->addError('taxRate', __('commerce::checkout.refused'));

            return;
        }

        $this->saved('tax_saved');
    }

    public function removeTaxRate(mixed $id, mixed $version): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('taxRate');

        try {
            app(CommerceSetupWriter::class)->removeTaxRate($id, $version);
            if ((string) $this->taxRateId === (string) $id) {
                $this->newTaxRate();
            }
        } catch (Throwable) {
            $this->addError('taxRate', __('commerce::checkout.refused'));

            return;
        }

        $this->saved('tax_removed');
    }

    public function editPolicy(mixed $sellable): void
    {
        self::authorizeResourceAccess();
        $row = Sellable::query()->useWritePdo()->whereKey(SalesPolicy::setupKey($sellable))->firstOrFail();
        $policy = SalesPolicy::query()->useWritePdo()->where('sellable_id', $row->getKey())->first();
        $this->sellableId = (int) $row->getKey();
        $this->sku = $row->sku;
        $this->policyVersion = $policy?->version;
        $this->policy = ['tax_class' => $policy->tax_class ?? '', 'delivery_kind' => $policy->delivery_kind ?? ''];
        foreach (SalesPolicy::PACKING_FIELDS as $field) {
            $this->policy[$field] = $policy?->getAttribute($field) === null ? '' : (string) $policy->getAttribute($field);
        }
        $this->resetErrorBag('policy');
    }

    public function savePolicy(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('policy');

        try {
            if ($this->sellableId === null || ! is_array($this->policy)) {
                throw new \InvalidArgumentException;
            }
            $values = $this->policy;
            if (($values['delivery_kind'] ?? null) === 'digital') {
                foreach (SalesPolicy::PACKING_FIELDS as $field) {
                    $values[$field] = null;
                }
            }
            $row = app(CommerceSetupWriter::class)->setSalesPolicy($this->sellableId, $values, $this->policyVersion);
            $this->policyVersion = $row->version;
        } catch (Throwable) {
            $this->addError('policy', __('commerce::checkout.refused'));

            return;
        }

        $this->saved('policy_saved');
    }

    public function removePolicy(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('policy');

        try {
            app(CommerceSetupWriter::class)->removeSalesPolicy($this->sellableId, $this->policyVersion);
            $this->policyVersion = null;
        } catch (Throwable) {
            $this->addError('policy', __('commerce::checkout.refused'));

            return;
        }

        $this->saved('policy_removed');
    }

    /** @return Paginator<int,TaxRate> */
    public function taxRates(): Paginator
    {
        self::authorizeResourceAccess();

        return TaxRate::query()->useWritePdo()->orderBy('id')->simplePaginate(25, ['*'], 'taxPage', $this->boundedPage('taxPage'));
    }

    /** @return Paginator<int,Sellable> */
    public function products(): Paginator
    {
        self::authorizeResourceAccess();

        return Sellable::query()->useWritePdo()->orderBy('id')->simplePaginate(25, ['id', 'sku'], 'productPage', $this->boundedPage('productPage'));
    }

    public static function percentage(int $parts): string
    {
        $padded = str_pad((string) $parts, 5, '0', STR_PAD_LEFT);

        return rtrim(rtrim(substr($padded, 0, -4).'.'.substr($padded, -4), '0'), '.');
    }

    private function boundedPage(string $name): int
    {
        $page = $this->getPage($name);
        abort_unless((is_int($page) || (is_string($page) && preg_match('/\A[1-9][0-9]{0,4}\z/D', $page) === 1)) && (int) $page >= 1 && (int) $page <= 10000, 404);

        return (int) $page;
    }

    private function loadSettings(): void
    {
        $row = CommerceSettings::query()->useWritePdo()->first();
        $this->settingsVersion = $row?->version;
        $this->settings = ['tax_mode' => $row->tax_mode ?? '', 'shipping_tax_mode' => $row->shipping_tax_mode ?? '',
            'shipping_tax_class' => $row->shipping_tax_class ?? '', 'ship_from_name' => $row->ship_from_name ?? '',
            'ship_from_address' => $row->ship_from_address ?? array_fill_keys(['line1', 'line2', 'city', 'region', 'postal_code', 'country'], '')];
    }

    private function saved(string $key): void
    {
        Notification::make()->title(__('commerce::checkout.'.$key))->success()->send();
    }
}
