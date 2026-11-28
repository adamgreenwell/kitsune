<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Api;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule as LaravelRule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Relations\EntryRelationResolver;
use Kitsune\Core\Relations\EntryRelationWriter;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Validation\Rule;
use stdClass;

/** @internal Reads authorized entry types through the Site API. */
final class ContentEntriesController
{
    public function index(Request $request, Context $context): JsonResponse
    {
        $definition = SiteApiRoutes::type($request, $context, 'view');
        $limit = self::pageArgument($request, 'limit', 25, 100);
        $after = self::pageArgument($request, 'after', 0, PHP_INT_MAX);

        $entries = self::query($context, $definition)->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
        $hasNext = $entries->count() > $limit;
        $page = $entries->take($limit);
        $contract = EntryFieldContract::forType($definition);
        $relations = self::relations($request, $context, $contract, $page);

        return self::response([
            'data' => $page->map(fn (Entry $entry): array => self::record($entry, $contract, $relations[$entry->id] ?? []))->all(),
            'pagination' => ['limit' => $limit, 'next_after' => $hasNext ? $page->last()?->getKey() : null],
        ]);
    }

    public function show(Request $request, Context $context): JsonResponse
    {
        $definition = SiteApiRoutes::type($request, $context, 'view');
        $key = filter_var($request->route('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($key === false, 404);

        $entry = self::query($context, $definition)->findOrFail($key);
        $actor = $request->user(SiteTokens::GUARD);
        abort_unless($actor instanceof Authenticatable && Gate::forUser($actor)->allows('view', $entry), 404);

        $contract = EntryFieldContract::forType($definition);
        $relations = self::relations($request, $context, $contract, new Collection([$entry]));

        return self::response(['data' => self::record($entry, $contract, $relations[$entry->id] ?? [])]);
    }

    public function store(Request $request, Context $context): JsonResponse
    {
        return $this->write($request, $context, 'create');
    }

    public function update(Request $request, Context $context): JsonResponse
    {
        $id = filter_var($request->route('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return $this->write($request, $context, 'update', $id);
    }

    private function write(Request $request, Context $context, string $action, ?int $id = null): JsonResponse
    {
        $site = SiteApiRoutes::site($request, $context);
        $type = SiteApiRoutes::type($request, $context, $action);
        abort_if($type->is_media, 404);

        $actor = $request->user(SiteTokens::GUARD);
        abort_unless($actor instanceof Authenticatable, 401);
        $input = self::writeInput($request, $id === null);
        $connection = (new Entry)->getConnection();

        try {
            $key = $connection->transaction(function () use ($request, $context, $site, $actor, $input, $action, $id): int {
                Permissions::forget();
                $type = SiteApiRoutes::type($request, $context, $action);
                abort_if($type->is_media, 404);
                $contract = EntryFieldContract::forType($type);
                $entry = $id === null
                    ? new Entry(['org_id' => $site->org_id, 'site_id' => $site->id, 'entry_type_id' => $type->id])
                    : self::query($context, $type)->where('site_id', $site->id)->lockForUpdate()->findOrFail($id);
                if ($id !== null) {
                    abort_unless(Gate::forUser($actor)->allows('update', $entry), 404);
                }

                if ($contract->hasPromotedSlug() && array_key_exists('slug', $input)) {
                    throw ValidationException::withMessages(['slug' => 'Set the slug through its configured field in values.']);
                }

                $changes = Validator::make($input, [
                    'title' => [...($id === null ? [] : ['sometimes']), 'required', 'string', 'max:255'],
                    'slug' => ['sometimes', 'nullable', 'bail', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                        Rule::scopedUnique(Entry::class, 'slug', $id,
                            fn (Builder $query) => $query->where('site_id', $site->id)->where('entry_type_id', $type->id)),
                    ],
                    'status' => ['sometimes', 'required', LaravelRule::in(Entry::STATUSES)],
                    ...$contract->rules($entry, partial: $id !== null),
                ])->validate();

                $definitions = $contract->definitions();
                if (($changes['status'] ?? null) === 'published' && $entry->status !== 'published') {
                    abort_unless(SiteTokens::allows($request, $type->handle, 'publish'), 403);
                }

                [$attributes, $relations] = $contract->writeChanges($entry, $changes);

                $revision = app(RecordedRevisions::class);
                if ($relations !== []) {
                    $revision->open($id);
                }

                // Entry applies its existing field conversions and publication rules to these attributes.
                abort_unless($entry->fill($attributes)->save(), 409);
                if ($relations !== []) {
                    $readable = EntryType::visibleFor($site, $site->org_id)->keyBy('handle');
                    $entry->writeRelationsAndReconcile(function () use ($request, $type, $entry, $id, $actor, $definitions, $relations, $readable): void {
                        foreach ($relations as $handle => $choices) {
                            try {
                                app(EntryRelationWriter::class)->replace(
                                    $entry,
                                    $definitions[$handle]->storage,
                                    $choices,
                                    fn (Entry $source): bool => SiteTokens::allows($request, $type->handle, $id === null ? 'create' : 'update')
                                        && ($id === null || Gate::forUser($actor)->allows('update', $source)),
                                    fn (Entry $target): bool => $readable->get($target->type_handle)?->id === $target->entry_type_id
                                        && SiteTokens::allows($request, $target->type_handle, 'view'),
                                );
                            } catch (ValidationException $failure) {
                                throw ValidationException::withMessages(['values.'.$handle => $failure->errors()['relations'] ?? [$failure->getMessage()]]);
                            }
                        }
                    });
                }

                return $entry->id;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'The entry conflicts with a stored value.');
        }

        return response()->json(['data' => ['id' => $key]], $id === null ? 201 : 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @return array<string, mixed> */
    private static function writeInput(Request $request, bool $creating): array
    {
        abort_unless($request->isJson(), 415);

        try {
            $body = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, 'The request body is not valid JSON.');
        }

        if (! $body instanceof stdClass || array_diff(array_keys(get_object_vars($body)), ['title', 'slug', 'status', 'values']) !== []) {
            throw ValidationException::withMessages(['entry' => 'Send only title, slug, status and values.']);
        }

        if (property_exists($body, 'values') && ! $body->values instanceof stdClass) {
            throw ValidationException::withMessages(['values' => 'Send field values as a JSON object.']);
        }

        $input = $request->json()->all();
        if ($creating) {
            $input['values'] ??= [];
        }

        return $input;
    }

    /** @return Builder<Entry> */
    private static function query(Context $context, EntryType $type): Builder
    {
        return Entry::query()->where('org_id', $context->orgId())
            ->where('entry_type_id', $type->getKey())->ofType($type->handle);
    }

    /**
     * @param  Collection<int, Entry>  $entries
     * @return array<int, array<int, list<array{entry: Entry, group: string|null}>>>
     */
    private static function relations(Request $request, Context $context, EntryFieldContract $contract, Collection $entries): array
    {
        $storages = $contract->relationStorage();
        if ($storages === []) {
            return [];
        }

        $types = EntryType::visibleFor($context->site(), $context->orgId())->keyBy('handle');

        return app(EntryRelationResolver::class)->resolveMany(
            $entries,
            $storages,
            false,
            static fn (Entry $target): bool => (int) $types->get($target->type_handle)?->getKey() === $target->entry_type_id
                && SiteTokens::allows($request, $target->type_handle, 'view'),
        );
    }

    /**
     * @param  array<int, list<array{entry: Entry, group: string|null}>>  $relations
     * @return array{id: int, type: string, title: ?string, slug: ?string, status: string, values: stdClass}
     */
    private static function record(Entry $entry, EntryFieldContract $contract, array $relations): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->type_handle,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->status,
            'values' => $contract->values($entry, $relations),
        ];
    }

    private static function pageArgument(Request $request, string $name, int $default, int $max): int
    {
        $value = $request->query($name);
        if ($value === null) {
            return $default;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if ($number === false) {
            throw ValidationException::withMessages([$name => 'Use a positive integer within the supported range.']);
        }

        return $number;
    }

    /** @param array<string, mixed> $body */
    private static function response(array $body): JsonResponse
    {
        return response()->json($body, headers: ['Cache-Control' => 'private, no-store']);
    }
}
