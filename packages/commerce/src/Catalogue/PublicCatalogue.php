<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Catalogue;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Kitsune\Commerce\Models\Listing;
use Kitsune\Commerce\Models\Sellable;
use Kitsune\Commerce\Money\MinorUnits;
use Kitsune\Core\Currency\Currencies;
use Kitsune\Core\Entitlements\EntitlementCheck;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\Types\RelationType;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryRelation;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * One bounded read of the current site's catalogue, with one batch access check and no public model serialization.
 *
 * Pages address 24 stable candidate positions. Content made invalid by a later schema edit is omitted, so a page can
 * be sparse or empty while still having a next page. Filling it by scanning until enough products pass would make a
 * request unbounded. Native schema and relation reads are page-wide; future custom validation rules may add queries.
 *
 * @internal First-party only; no public extension contract before v1.2.
 *
 * @phpstan-type Product array{title:string,description:?string,currency:string,price:string,stock:?string,imageUrl:?string,held:?bool}
 * @phpstan-type Page array{products:list<Product>,page:int,hasPrevious:bool,hasNext:bool,hasCandidates:bool}
 */
final class PublicCatalogue
{
    public const PAGE_SIZE = 24;

    public const LAST_PAGE = 10000;

    public function __construct(
        private readonly Context $context,
        private readonly PublicContent $content,
        private readonly FieldTypeRegistry $types,
        private readonly EntitlementCheck $entitlements,
    ) {}

    /** @return Page */
    public function page(int $page = 1): array
    {
        if ($page < 1 || $page > self::LAST_PAGE) {
            throw new InvalidArgumentException('Catalogue pages range from 1 through 10000.');
        }

        $empty = ['products' => [], 'page' => $page, 'hasPrevious' => $page > 1, 'hasNext' => false, 'hasCandidates' => false];
        $site = $this->context->site();
        $orgId = $this->context->orgId();

        if ($site === null || $orgId === null || ! Module::isEnabled('kitsune/commerce')) {
            return $empty;
        }

        $type = ProductType::current();
        $currency = Org::query()->useWritePdo()->whereKey($orgId)->value('currency');

        if ($type === null || ! Currencies::supports($currency)) {
            return $empty;
        }

        $candidates = Listing::query()->useWritePdo()
            ->join('commerce_sellables', 'commerce_sellables.id', '=', 'commerce_listings.sellable_id')
            ->join('entries', 'entries.id', '=', 'commerce_listings.entry_id')
            ->where('commerce_listings.is_available', true)
            ->where('commerce_sellables.org_id', $orgId)->where('commerce_sellables.currency', $currency)
            ->where('entries.org_id', $orgId)->where('entries.site_id', $site->getKey())
            ->where('entries.entry_type_id', $type->getKey())->where('entries.status', 'published')->whereNull('entries.deleted_at')
            ->orderBy('commerce_sellables.sku')->orderBy('commerce_listings.id')
            ->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get(['commerce_listings.*']);
        $hasNext = $page < self::LAST_PAGE && $candidates->count() > self::PAGE_SIZE;
        $candidates = $candidates->take(self::PAGE_SIZE);

        if ($candidates->isEmpty()) {
            return $empty;
        }

        $entries = Entry::query()->useWritePdo()->where('org_id', $orgId)->where('site_id', $site->getKey())
            ->where('entry_type_id', $type->getKey())->whereIn('id', $candidates->pluck('entry_id'))->get()->keyBy('id');
        $sellables = Sellable::query()->useWritePdo()->where('org_id', $orgId)->whereIn('id', $candidates->pluck('sellable_id'))->get()->keyBy('id');
        /** @var list<Field> $fields */
        $fields = $type->fields()->with('fieldStorage')->orderBy('ordering')->get()->all();
        $relations = $this->relationCounts($entries->keys()->all(), $fields);
        $images = $this->images($entries->keys()->all(), $fields);
        $products = [];
        $names = [];

        foreach ($candidates as $listing) {
            $entry = $entries->get($listing->entry_id);
            $sellable = $sellables->get($listing->sellable_id);

            if (! $entry instanceof Entry || ! $sellable instanceof Sellable
                || ! $this->content->ready($entry, $fields, $relations[$entry->getKey()] ?? [])) {
                continue;
            }

            $raw = $sellable->getAttributes();
            $name = $raw['entitlement_name'] ?? null;

            if (($name !== null && (! is_string($name) || ! EntitlementName::isName($name))) || $sellable->currency !== $currency) {
                continue;
            }

            try {
                Sellable::skuValue($raw['sku'] ?? null);
                $price = MinorUnits::format($raw['price_minor'] ?? null, $currency);
                $stock = ($raw['stock'] ?? null) === null ? null : MinorUnits::integer($raw['stock']);
            } catch (InvalidArgumentException|RuntimeException) {
                continue;
            }

            $products[] = [
                'title' => (string) $entry->title,
                'description' => $this->content->description($entry, $fields),
                'currency' => $currency,
                'price' => $price,
                'stock' => $stock,
                'imageUrl' => $images[$entry->getKey()] ?? null,
                'held' => null,
            ];
            $names[] = $name;
        }

        $held = $this->entitlements->holdsMany(array_values(array_unique(array_filter($names, is_string(...)))));

        foreach ($products as $index => &$product) {
            $name = $names[$index];
            $product['held'] = $name === null ? null : ($held[$name] ?? false);
        }
        unset($product);

        return ['products' => $products, 'page' => $page, 'hasPrevious' => $page > 1, 'hasNext' => $hasNext, 'hasCandidates' => true];
    }

    /**
     * The links are reached only from this page's scoped entries. Count all retained links, including malformed ones,
     * then count those whose org stamp, scoped target or allowed target type no longer agrees. No target IDs leave SQL.
     *
     * @param  list<int|string>  $entries
     * @param  list<Field>  $fields
     * @return array<int|string, array<int|string, array{count:int,invalid:int}>>
     */
    private function relationCounts(array $entries, array $fields): array
    {
        $relations = array_values(array_filter($fields, fn (Field $field): bool => $field->fieldStorage instanceof FieldStorage
            && $this->types->has($field->fieldStorage->type) && $this->types->get($field->fieldStorage->type) instanceof RelationType));

        if ($entries === [] || $relations === []) {
            return [];
        }

        $query = EntryRelation::query()->useWritePdo()->whereIn('source_entry_id', $entries)
            ->whereIn('field_storage_id', array_map(static fn (Field $field): int => (int) $field->field_storage_id, $relations));
        $all = (clone $query)->selectRaw('source_entry_id, field_storage_id, COUNT(*) AS relation_count')
            ->groupBy('source_entry_id', 'field_storage_id')->get();
        $bad = (clone $query)->where(function ($invalid) use ($relations): void {
            $invalid->where('org_id', '!=', $this->context->orgId());

            foreach ($relations as $field) {
                $targets = (array) (($field->fieldStorage->settings['targetTypes'] ?? []) ?: []);
                $visible = Entry::query()->where('org_id', $this->context->orgId())->select('entries.id');

                if ($targets !== []) {
                    $visible->whereIn('type_handle', $targets);
                }

                $invalid->orWhere(fn ($forField) => $forField->where('field_storage_id', $field->field_storage_id)
                    ->whereNotIn('target_entry_id', $visible));
            }
        })->selectRaw('source_entry_id, field_storage_id, COUNT(*) AS relation_count')
            ->groupBy('source_entry_id', 'field_storage_id')->get();
        $result = [];

        foreach ($all as $row) {
            $result[$row->source_entry_id][$row->field_storage_id] = ['count' => (int) $row->getAttribute('relation_count'), 'invalid' => 0];
        }

        foreach ($bad as $row) {
            $result[$row->source_entry_id][$row->field_storage_id]['invalid'] = (int) $row->getAttribute('relation_count');
        }

        return $result;
    }

    /**
     * One first eligible public image per product, in the author's order. Window functions are supported by the three
     * supported drivers and keep an unlimited image relation from becoming an unlimited PHP result set. Media rows are
     * reached through scoped entries; only the configured public disk is ever handed to the direct-URL helper.
     *
     * @param  list<int|string>  $entries
     * @param  list<Field>  $fields
     * @return array<int|string, string>
     */
    private function images(array $entries, array $fields): array
    {
        $field = collect($fields)->first(fn (Field $field): bool => $field->fieldStorage instanceof FieldStorage
            && $field->fieldStorage->handle === 'product_images' && $field->fieldStorage->pii_class === 'none'
            && $this->types->has($field->fieldStorage->type) && $this->types->get($field->fieldStorage->type) instanceof RelationType);

        if (! $field instanceof Field || $entries === []) {
            return [];
        }

        $site = $this->context->site();
        $targetTypes = (array) (($field->fieldStorage->settings['targetTypes'] ?? []) ?: []);
        $types = EntryType::query()->where('is_media', true)
            ->when($targetTypes !== [], fn ($query) => $query->whereIn('handle', $targetTypes))->pluck('id')->all();
        $enabled = EntryTypeAvailability::enabledMapFor($types, $site?->getKey(), $site?->site_group_id, $this->context->orgId());
        $types = array_values(array_filter($types, static fn (int|string $id): bool => $enabled[$id] ?? true));

        if ($types === []) {
            return [];
        }

        $visible = Entry::query()->where('org_id', $this->context->orgId())->whereIn('entry_type_id', $types)->select('entries.id');
        $grammar = (new EntryRelation)->getConnection()->getQueryGrammar();
        $source = $grammar->wrap('entry_relations.source_entry_id');
        $ordering = $grammar->wrap('entry_relations.ordering');
        $id = $grammar->wrap('entry_relations.id');
        $rank = $grammar->wrap('catalogue_image_rank');
        $ranked = EntryRelation::query()->useWritePdo()->whereIn('source_entry_id', $entries)
            ->where('entry_relations.org_id', $this->context->orgId())->where('field_storage_id', $field->field_storage_id)
            ->whereIn('target_entry_id', $visible)
            ->join('media_files', 'media_files.entry_id', '=', 'entry_relations.target_entry_id')
            ->where('media_files.visibility', 'public')->where('media_files.disk', MediaDisks::configured(app('config'), 'public'))
            ->whereIn('media_files.mime', ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'])
            ->select(['entry_relations.source_entry_id', 'media_files.*'])
            // Raw expressions bypass table-prefix handling; only the window syntax is raw, with every identifier
            // wrapped by this connection's grammar so a prefixed host reads the same scoped rows.
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$source} ORDER BY {$ordering}, {$id}) AS {$rank}");
        /** @var Collection<int, object> $rows */
        $rows = (new EntryRelation)->getConnection()->query()->useWritePdo()->fromSub($ranked->toBase(), 'catalogue_images')
            ->where('catalogue_image_rank', 1)->get();
        $images = [];

        foreach ($rows as $row) {
            $file = (new MediaFile)->newFromBuilder((array) $row);

            if (MediaDelivery::servesDirectly($file)) {
                // This helper's direct-public branch drops only APP_URL's origin. The preceding check means its
                // private/admin branch is unreachable, and no per-product model or media lookup is performed.
                $url = MediaDelivery::adminUrlForFile(new Entry, $file);

                if (is_string($url)) {
                    $images[$row->source_entry_id] = $url;
                }
            }
        }

        return $images;
    }
}
