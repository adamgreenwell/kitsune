<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Catalogue;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as ValidationRule;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRevision;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Validation\Rule;
use RuntimeException;

/**
 * One owner/system save for shared commerce and this site's adaptable product content. @internal
 * Null staff actor is the trusted system; browser pages must require a signed-in owner themselves.
 */
final class ProductWriter
{
    public function __construct(
        private readonly Context $context,
        private readonly CatalogueWriter $catalogue,
        private readonly Auditor $auditor,
        private readonly RecordedRevisions $revisions,
        private readonly FieldTypeRegistry $types,
    ) {}

    /**
     * @param  array<string, mixed>  $commerceData
     * @param  array<string, mixed>  $contentData
     * @param  array<string, mixed>  $relations
     */
    public function createProduct(#[\SensitiveParameter] array $commerceData, mixed $currencyQuote, array $contentData, array $relations = [], mixed $available = false): Sellable
    {
        return $this->write(null, $commerceData, $currencyQuote, $contentData, $relations, $available);
    }

    /**
     * An org's existing product gets a new content entry when first listed on a sibling site.
     *
     * @param  array<string, mixed>  $commerceData
     * @param  array<string, mixed>  $contentData
     * @param  array<string, mixed>  $relations
     */
    public function updateProduct(mixed $sellableId, #[\SensitiveParameter] array $commerceData, mixed $currencyQuote, array $contentData, array $relations = [], mixed $available = false): Sellable
    {
        return $this->write(Sellable::catalogueKey($sellableId), $commerceData, $currencyQuote, $contentData, $relations, $available);
    }

    /**
     * @param  array<string, mixed>  $commerce
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $relations
     */
    private function write(?int $id, #[\SensitiveParameter] array $commerce, mixed $quote, array $content, array $relations, mixed $available): Sellable
    {
        $this->refuseWithoutAuthority();
        $this->onlyKeys($commerce, ['sku', 'price_minor', 'stock', 'entitlement_name', 'offer_terms']);
        $this->onlyKeys($content, ['title', 'slug', 'status', 'values']);

        if (! is_bool($available)) {
            throw new RuntimeException('Product availability must be a boolean.');
        }

        return TransactionRecovery::run((new Sellable)->getConnection(), function () use ($id, $commerce, $quote, $content, $relations, $available): Sellable {
            // Agree with CatalogueWriter and OrgCurrency before taking any content or relation lock.
            Org::query()->useWritePdo()->whereKey($this->context->orgId())->lockForUpdate()->first()
                ?? throw new RuntimeException('The product org is no longer available.');
            $type = ProductType::current() ?? throw new RuntimeException('Apply and enable Storefront for this site before editing products.');
            // Ordinary product edits never resubmit a stale stock balance. Adjustments have their own reason/history
            // writer; read the current value beneath the same Org lock used by payment and inventory writes.
            $current = null;
            if ($id !== null) {
                $current = Sellable::query()->useWritePdo()->whereKey($id)->lockForUpdate()->first()
                    ?? throw new RuntimeException('The product is not available in this org.');
                $commerce['stock'] = $current->stock;
            }
            $listing = $id === null ? null : $this->listing($id, $type);
            $entry = $listing?->entry()->useWritePdo()->lockForUpdate()->first() ?? new Entry;

            if (! $entry->exists) {
                $entry->forceFill(['entry_type_id' => $type->getKey()]);
            }

            $content = $this->validateContent($type, $entry, $content, $relations, $available);
            // Older first-party payloads omit the mapping; do not turn their content edit into a shared offer change.
            $offer = array_key_exists('entitlement_name', $commerce) ? [$commerce['entitlement_name']] : [];
            if (array_key_exists('offer_terms', $commerce)) {
                $offer = [array_key_exists('entitlement_name', $commerce) ? $commerce['entitlement_name'] : $current?->entitlement_name, $commerce['offer_terms']];
            }
            $sellable = $id === null
                ? $this->catalogue->createSellable($commerce['sku'] ?? null, $commerce['price_minor'] ?? null, $commerce['stock'] ?? null, $quote, ...$offer)
                : $this->catalogue->updateSellable($id, $commerce['sku'] ?? null, $commerce['price_minor'] ?? null, $commerce['stock'] ?? null, $quote, ...$offer);

            $this->revisions->open($entry->exists ? (int) $entry->getKey() : null);

            try {
                $entry->forceFill($content);

                if (! $entry->save()) {
                    throw new RuntimeException('The product content save was cancelled.');
                }

                $entry->writeRelationsAndReconcile(function () use ($entry, $type, $relations): void {
                    foreach ($this->fields($type) as $field) {
                        $storage = $field->fieldStorage;

                        if ($this->types->get($storage->type)->strategy() === StorageStrategy::Relational
                            && array_key_exists($storage->handle, $relations)) {
                            $entry->syncFieldRelations($storage, $this->relationIds($relations[$storage->handle]));
                        }
                    }
                });

                $entry = $entry->fresh() ?? throw new RuntimeException('The product content is no longer available.');

                if ((string) $entry->entry_type_id !== (string) $type->getKey()
                    || (string) $entry->org_id !== (string) $this->context->orgId()
                    || $entry->site_id === null || (string) $entry->site_id !== (string) $this->context->siteId()) {
                    throw new RuntimeException('The saved content no longer belongs to this site\'s Storefront product type.');
                }

                $this->validateContent($type, $entry, [], [], $available);
                $this->refuseMissingRevision($entry);

                if (! $this->auditor->recordOrFail('commerce.product_content_saved', $entry)->exists) {
                    throw new RuntimeException('The product content audit save was cancelled.');
                }

                $listing === null
                    ? $this->catalogue->createListing($sellable->getKey(), $entry->getKey(), $available)
                    : $this->catalogue->setAvailability($listing->getKey(), $available);

                return $sellable;
            } finally {
                $this->revisions->discard();
            }
        });
    }

    private function refuseWithoutAuthority(): void
    {
        $actor = Permissions::currentUser();

        if ($this->context->orgId() === null || $this->context->siteId() === null
            || ($actor !== null && ! Permissions::isOwner($actor)) || ! Module::isEnabled('kitsune/commerce')) {
            throw new RuntimeException('Product writes require an enabled commerce module and the current site\'s org owner or system.');
        }
    }

    private function listing(int $sellable, EntryType $type): ?Listing
    {
        // Do not mistake an incoherent retained row hidden by the read guard for a missing listing.
        $retained = Listing::query()->withoutGlobalScope('catalogue_coherence')->useWritePdo()
            ->where('org_id', $this->context->orgId())->where('site_id', $this->context->siteId())
            ->where('sellable_id', $sellable)->lockForUpdate()->first();

        if ($retained === null) {
            return null;
        }

        $listing = Listing::query()->useWritePdo()->whereKey($retained->getKey())->first();
        $entry = $listing?->entry()->useWritePdo()->first();

        if ($entry === null || (int) $entry->entry_type_id !== (int) $type->getKey()) {
            throw new RuntimeException('The retained listing no longer points to this site\'s Storefront product content.');
        }

        return $listing;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $relations
     * @return array<string, mixed>
     */
    private function validateContent(EntryType $type, Entry $entry, array $content, array $relations, bool $available): array
    {
        $content = [...['title' => $entry->title, 'slug' => $entry->slug, 'status' => $entry->status, 'values' => $entry->values ?? []], ...$content];
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::scopedUnique(Entry::class, 'slug', $entry->getKey(),
                fn ($query) => $query->where('entry_type_id', $type->getKey())->where('site_id', $this->context->siteId()))],
            'status' => ['required', 'string', ValidationRule::in(Entry::STATUSES)],
            'values' => ['array'], 'relations' => ['array'],
        ];
        $fields = $this->fields($type);
        $valueKeys = [];
        $relationKeys = [];

        foreach ($fields as $field) {
            $storage = $field->fieldStorage;
            $implementation = $this->types->get($storage->type);

            if ($content['status'] === 'draft' && ! $available) {
                $field = clone $field;
                $field->is_required = false;
            }

            // A prospective record carries the resolved type too; validation must not depend on a page binding.
            $config = new FieldConfig($storage, $field, $entry);
            $prefix = match ($implementation->strategy()) {
                StorageStrategy::Relational => 'relations.',
                StorageStrategy::Promoted => '',
                default => 'values.',
            };
            $handle = $implementation->promotedColumn() ?? $storage->handle;
            $key = $prefix.$handle;
            $rules[$key] = $implementation->validationRules($config);

            if (($elements = $implementation->elementValidationRules($config)) !== []) {
                $rules[$key.'.*'] = $elements;
            }

            if ($prefix === 'relations.') {
                $relationKeys[] = $storage->handle;
                $relations[$storage->handle] = array_key_exists($storage->handle, $relations)
                    ? $this->relationIds($relations[$storage->handle])
                    : ($entry->exists ? $entry->linkedIdsForField($storage) : []);
            } elseif ($prefix === 'values.') {
                $valueKeys[] = $storage->handle;
            }
        }

        if (! is_array($content['values'])) {
            throw new RuntimeException('Product values must be an array.');
        }

        $this->onlyKeys($content['values'], $valueKeys);
        $this->onlyKeys($relations, $relationKeys);
        Validator::make([...$content, 'relations' => $relations], $rules)->validate();

        if ($available && $content['status'] !== 'published') {
            throw new RuntimeException('Only published product content can be available on this site.');
        }

        return $content;
    }

    /** A silently cancelled revision insert/update must roll back the combined product save too. */
    private function refuseMissingRevision(Entry $entry): void
    {
        $revision = $entry->revisions()->useWritePdo()->latest('id')->first();

        if ($revision === null) {
            throw new RuntimeException('The product content revision was not saved.');
        }

        foreach (EntryRevision::SNAPSHOT_ATTRIBUTES as $column) {
            if ($revision->getRawOriginal($column) !== $entry->getRawOriginal($column)) {
                throw new RuntimeException('The product content revision does not match the saved content.');
            }
        }

        if ($this->orderedKeys($revision->values ?? []) !== $this->orderedKeys($entry->values ?? [])
            || $this->orderedKeys($revision->relation_state ?? []) !== $this->orderedKeys($entry->relationState())) {
            throw new RuntimeException('The product content revision does not match the saved fields and relations.');
        }
    }

    /**
     * JSON object key order is not data; list order still is.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function orderedKeys(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->orderedKeys($nested);
            }
        }

        return $value;
    }

    /** @return list<Field> */
    private function fields(EntryType $type): array
    {
        $fields = $type->fields()->with('fieldStorage')->orderBy('ordering')->get()
            ->filter(static fn (Field $field): bool => $field->fieldStorage instanceof FieldStorage)
            ->values()->all();

        foreach ($fields as $field) {
            if ($field->fieldStorage->org_id !== null && $field->fieldStorage->org_id !== $type->org_id) {
                throw new RuntimeException('The product schema references another org\'s field storage.');
            }
        }

        return $fields;
    }

    /** @return list<int> */
    private function relationIds(mixed $ids): array
    {
        if ($ids === null || $ids === '') {
            return [];
        }

        $ids = is_array($ids) ? $ids : [$ids];

        if (! array_is_list($ids)) {
            throw new RuntimeException('Product relation choices must be a list of whole entry identifiers.');
        }

        return array_values(array_unique(array_map(Listing::catalogueKey(...), $ids)));
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $allowed
     */
    private function onlyKeys(#[\SensitiveParameter] array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new RuntimeException('The product payload contains fields outside its current schema.');
        }
    }
}
