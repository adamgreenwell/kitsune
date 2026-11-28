<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Content;

use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Http\Api\EntryFieldContract;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Relations\EntryRelationWriter;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Validation\Rule;

/** @internal Candidate for saving a reviewed draft and its ordered relations in one Site. */
final readonly class SiteDraftWriter
{
    public function __construct(private SiteDraftAccess $access, private EntryRelationWriter $relations) {}

    /**
     * Draft values use the Site API's field rules and ordered relation choices.
     *
     * @param  array<string, mixed>  $input
     * @return array{id: ?int, revision: ?int, errors: array<string, list<string>>}
     */
    public function save(
        int $siteId,
        string $typeHandle,
        Authenticatable $actor,
        string $fingerprint,
        array $input,
        ?int $entryId = null,
        ?int $expectedRevision = null,
    ): array {
        $current = $this->access->activeActor($actor);

        try {
            return TransactionRecovery::run((new Entry)->getConnection(), function () use (
                $siteId, $typeHandle, $current, $fingerprint, $input, $entryId, $expectedRevision,
            ): array {
                $site = $this->access->site($siteId);
                $type = $this->access->type($site, $typeHandle, $current);
                $entry = $this->access->writeTarget($siteId, $type, $current, $entryId, lock: true);
                if (($entry === null ? null : $this->access->revision($entry)) !== $expectedRevision) {
                    throw new DomainException('The draft revision changed. Review the draft again.');
                }

                $contract = EntryFieldContract::forType($type);
                if (! hash_equals(SiteDraftFields::fingerprint($siteId, $type, $contract), $fingerprint)) {
                    throw new DomainException('The destination field schema changed. Review the fields again.');
                }

                $entry ??= new Entry(['org_id' => $site->org_id, 'site_id' => $siteId, 'entry_type_id' => $type->getKey(), 'status' => 'draft']);
                $changes = self::validate($entry, $site, $contract, $input);
                [$attributes, $links] = $contract->writeChanges($entry, $changes);
                $this->persist($entry, $site, $current, $contract, $attributes, $links);

                return ['id' => (int) $entry->getKey(), 'revision' => $this->access->revision($entry), 'errors' => []];
            });
        } catch (ValidationException $failure) {
            return ['id' => null, 'revision' => null, 'errors' => $failure->errors()];
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('The draft conflicts with a stored value.');
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function validate(Entry $entry, Site $site, EntryFieldContract $contract, array $input): array
    {
        if (array_diff(array_keys($input), ['title', 'slug', 'values']) !== []) {
            throw ValidationException::withMessages(['draft' => 'Supply only draft title, slug and values.']);
        }
        if ($contract->hasPromotedSlug() && array_key_exists('slug', $input)) {
            throw ValidationException::withMessages(['slug' => 'Set the slug through its configured field in values.']);
        }
        if (! $entry->exists) {
            $input += ['values' => []];
        }

        return Validator::make($input, [
            'title' => [...($entry->exists ? ['sometimes'] : []), 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'bail', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::scopedUnique(Entry::class, 'slug', $entry->getKey(),
                    static fn (Builder $query) => $query->where('site_id', $site->id)->where('entry_type_id', $entry->entry_type_id)),
            ],
            ...$contract->rules($entry->exists ? $entry : null, partial: $entry->exists),
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<array{entry: int, pin?: bool, follow?: bool}|array{group: string}>>  $links
     */
    private function persist(Entry $entry, Site $site, Authenticatable $actor, EntryFieldContract $contract, array $attributes, array $links): void
    {
        $creating = ! $entry->exists;
        $revision = $links === [] ? null : app(RecordedRevisions::class);
        $revision?->open($entry->getKey());
        try {
            if (! $entry->fill($attributes)->save() || $entry->refresh()->status !== 'draft') {
                throw new DomainException('Kitsune refused the draft write.');
            }
            if ($links === []) {
                return;
            }

            $definitions = $contract->definitions();
            $canRead = $this->access->relationReader($site, $actor);
            $entry->writeRelationsAndReconcile(function () use ($entry, $actor, $definitions, $links, $canRead, $creating, $site): void {
                foreach ($links as $handle => $choices) {
                    try {
                        $this->relations->replace(
                            $entry,
                            $definitions[$handle]->storage,
                            $choices,
                            static fn (Entry $source): bool => $source->site_id === $site->id && $source->status === 'draft'
                                && ($creating
                                    ? Permissions::allows($actor, Permissions::forEntryType($source->type_handle, 'create'))
                                    : Gate::forUser($actor)->allows('update', $source)),
                            $canRead,
                        );
                    } catch (ValidationException $failure) {
                        throw ValidationException::withMessages(['values.'.$handle => $failure->errors()['relations'] ?? ['Choose an available relation.']]);
                    }
                }
            });
        } finally {
            $revision?->discard();
        }
    }
}
