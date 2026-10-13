<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament\Products;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Kitsune\Commerce\Catalogue\ProductType;
use Kitsune\Commerce\Filament\ProductResource;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Livewire\Attributes\Locked;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** @internal Native form state; authority and persisted values are re-read by ProductWriter. */
trait InteractsWithProductForm
{
    #[Locked]
    public string $currencyQuote = '';

    private ?Entry $productContent = null;

    private bool $productRollbackRequested = false;

    private bool $productActionRunning = false;

    /** @var array{url:mixed,navigate:mixed}|null */
    private ?array $productRedirect = null;

    public function bootInteractsWithProductForm(): void
    {
        if (($type = ProductType::current()) !== null) {
            app()->instance(EntryType::class, $type);
        }
    }

    private function captureCurrencyQuote(): void
    {
        $this->currencyQuote = Org::query()->whereKey(app(Context::class)->orgId())->value('currency') ?? '';
    }

    /** Native manual transactions are off because the entire action is enclosed by TransactionRecovery below. */
    public function hasDatabaseTransactions(): bool
    {
        return false;
    }

    protected function rollBackDatabaseTransaction(): void
    {
        // Native Filament swallows rollback-requesting Halt; carry its intent to our outer owner.
        $this->productRollbackRequested = true;
    }

    public function redirect(mixed $url, mixed $navigate = false): void
    {
        if ($this->productActionRunning) {
            $this->productRedirect = ['url' => $url, 'navigate' => $navigate];

            return;
        }

        parent::redirect($url, $navigate);
    }

    /** @param Closure(): void $action */
    private function productAction(Closure $action): void
    {
        abort_unless(ProductResource::canAccess(), 403);
        $this->productRollbackRequested = false;
        $this->productActionRunning = true;
        $this->productRedirect = null;
        $recordBefore = $this->record;
        $committed = false;

        try {
            TransactionRecovery::run((new Sellable)->getConnection(), function () use ($action): void {
                $action();

                if ($this->productRollbackRequested) {
                    throw new RuntimeException('The product save was cancelled.');
                }
            });
            $committed = true;
        } catch (ValidationException $failure) {
            $errors = [];

            foreach ($failure->errors() as $key => $messages) {
                $errors[str_starts_with($key, 'data.') ? $key : 'data.content.'.$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        } catch (QueryException) {
            throw ValidationException::withMessages(['data.sku' => __('commerce::catalogue.save_refused')]);
        } catch (RuntimeException $failure) {
            if ($failure instanceof HttpExceptionInterface) {
                throw $failure;
            }

            // No SQL, bindings or user-supplied exception strings enter a Livewire snapshot.
            throw ValidationException::withMessages(['data.price' => __('commerce::catalogue.save_refused')]);
        } finally {
            if (! $committed) {
                $this->record = $recordBefore;

                if ($this->hasCachedSchema('form')) {
                    $this->form->model($recordBefore ?? Sellable::class);
                }

                if ($this instanceof CreateProduct) {
                    $this->isCreating = false;
                }
            }

            $this->productContent = null;
            $this->productRollbackRequested = false;
            $this->productActionRunning = false;

            if (! $committed) {
                $this->productRedirect = null;
            }
        }

        $this->completeProductRedirect();
    }

    /** Native page callbacks may have queued a redirect; only the committed action reaches this method. */
    private function completeProductRedirect(): void
    {
        if ($this->productRedirect !== null) {
            $redirect = $this->productRedirect;
            $this->productRedirect = null;
            parent::redirect($redirect['url'], $redirect['navigate']);
        }
    }

    public function siteListing(): ?Listing
    {
        $record = $this->getRecord();

        if (! $record instanceof Sellable || ! $record->exists) {
            return null;
        }

        // An incoherent retained listing still has an owner removal path; no foreign content is read here.
        return Listing::query()->withoutGlobalScope('catalogue_coherence')
            ->where('org_id', app(Context::class)->orgId())->where('site_id', app(Context::class)->siteId())
            ->where('sellable_id', $record->getKey())->first();
    }

    public function contentRecord(): Entry
    {
        if ($this->productContent !== null) {
            return $this->productContent;
        }

        $type = ProductType::current();
        abort_unless($type !== null, 404);
        $listing = $this->siteListing();

        $entry = $listing === null ? null : Entry::query()->whereKey($listing->entry_id)
            ->where('org_id', app(Context::class)->orgId())->where('site_id', app(Context::class)->siteId())
            ->where('entry_type_id', $type->getKey())->first();

        return $this->productContent = $entry ?? (new Entry)->forceFill(['entry_type_id' => $type->getKey()]);
    }

    /** @return array<string, mixed> */
    private function contentState(): array
    {
        $entry = $this->contentRecord();
        $state = ['title' => $entry->title ?? '', 'slug' => $entry->slug,
            'status' => $entry->status, 'values' => $entry->values ?? [], 'relations' => []];
        $registry = app(FieldTypeRegistry::class);

        foreach (ProductType::current()?->fields()->with('fieldStorage')->get() ?? [] as $field) {
            $storage = $field->fieldStorage;

            if ($storage === null || $registry->get($storage->type)->control() !== Control::EntryPicker) {
                continue;
            }

            $ids = $entry->exists ? $entry->linkedIdsForField($storage) : [];
            $state['relations'][$storage->handle] = (new FieldConfig($storage, $field))->isMultiValue()
                ? $ids : ($ids[0] ?? null);
        }

        return $state;
    }

    /** @param array<string, mixed> $data
     * @return array{sku:mixed,price_minor:string,stock:string|null}
     */
    private function commerceData(array $data): array
    {
        try {
            $price = MinorUnits::fromDecimal($data['price'] ?? null, $this->currencyQuote);
        } catch (\InvalidArgumentException|RuntimeException) {
            throw ValidationException::withMessages(['data.price' => __('commerce::catalogue.invalid_price')]);
        }

        $mode = $data['stock_mode'] ?? null;

        if (! in_array($mode, ['finite', 'untracked'], true)) {
            throw ValidationException::withMessages(['data.stock_mode' => __('commerce::catalogue.invalid_stock')]);
        }

        try {
            $stock = $mode === 'untracked' ? null : MinorUnits::integer($data['stock'] ?? null);
        } catch (\InvalidArgumentException|RuntimeException) {
            throw ValidationException::withMessages(['data.stock' => __('commerce::catalogue.invalid_stock')]);
        }

        return ['sku' => $data['sku'] ?? null, 'price_minor' => $price, 'stock' => $stock];
    }

    /** @return array<string, mixed> */
    private function relationData(): array
    {
        $state = data_get($this->form->getRawState(), 'content.relations', []);

        if (! is_array($state)) {
            throw ValidationException::withMessages(['data.content' => __('commerce::catalogue.save_refused')]);
        }

        return $state;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function contentData(array $data): array
    {
        $content = $data['content'] ?? [];

        if (! is_array($content)) {
            throw ValidationException::withMessages(['data.content' => __('commerce::catalogue.save_refused')]);
        }

        unset($content['relations']);

        return $content;
    }
}
