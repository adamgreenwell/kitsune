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
use InvalidArgumentException;
use Kitsune\Commerce\Catalogue\OrgCurrency;
use Kitsune\Core\Currency\Currencies;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/** @internal First-party only; no extension contract before v1.2. */
final class CurrencySettings extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = CurrencyResource::class;

    protected string $view = 'commerce::currency-settings';

    public mixed $currency = '';

    public function mount(): void
    {
        self::authorizeResourceAccess();
        $this->currency = $this->org()->currency ?? '';
    }

    public function getTitle(): string
    {
        return __('commerce::catalogue.currency_title');
    }

    /** @return array<string, array{name: string, numeric: string, minor_units: int}> */
    public function currencies(): array
    {
        return Currencies::all();
    }

    public function saveCurrency(): void
    {
        // Recheck for every Livewire action, including a stale page after commerce was disabled.
        self::authorizeResourceAccess();
        $this->resetErrorBag('currency');

        try {
            app(OrgCurrency::class)->set($this->currency === '' ? null : $this->currency);
        } catch (InvalidArgumentException|RuntimeException) {
            // Never put database queries/bindings into the response or a Livewire snapshot.
            $this->addError('currency', __('commerce::catalogue.currency_refused'));

            return;
        }

        Notification::make()->title(__('commerce::catalogue.currency_saved'))->success()->send();
    }

    private function org(): Org
    {
        return Org::query()->findOrFail(app(Context::class)->orgId());
    }
}
