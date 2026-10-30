<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Media\MediaVisibilityRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** @internal The bounded media-list action; MediaVisibility remains the only writer (ADR-041, #252). */
final class MediaReaderBulk
{
    public const CONFIRMATION = 'confirmation';

    /** @return list<BulkAction> */
    public static function bulk(): array
    {
        return EntryResource::listsMedia() ? [self::offerSelected()] : [];
    }

    public static function offerSelected(): BulkAction
    {
        return BulkSelection::oneByOne(BulkAction::make('offerSelectedToReaders'))
            ->label(__('kitsune::media.readers.bulk.offer'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->authorize(static fn (): bool => self::mayOnType('update') || self::mayOnType('publish'))
            ->disabled(static fn (): bool => ! self::mayOnType('publish'))
            ->tooltip(static fn (): ?string => self::mayOnType('publish') ? null : __('kitsune::media.readers.bulk.needs_publish'))
            ->hidden(static function (HasTable $livewire): bool {
                $state = $livewire->getTableFilterState(TrashedFilter::class) ?? [];

                return array_key_exists('value', $state) && ! $state['value'] && filled($state['value']);
            })
            ->modalHeading(__('kitsune::media.readers.bulk.offer'))
            ->modalDescription(__('kitsune::media.readers.bulk.warning', ['max' => BulkSelection::MOST_AT_ONCE]).' '.__('kitsune::media.readers.change_warning').' '.__('kitsune::media.readers.as_stored'))
            ->modalSubmitActionLabel(__('kitsune::media.readers.offer_submit'))
            ->schema(static fn (): ?array => self::mayOnType('publish') ? [
                TextInput::make(MediaReaderActions::FIELD)
                    ->label(__('kitsune::media.readers.name'))
                    ->helperText(__('kitsune::media.readers.name_help'))
                    ->required()
                    ->maxLength(EntitlementName::LONGEST)
                    ->extraInputAttributes(['dir' => 'ltr', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'autocomplete' => 'off', 'autocorrect' => 'off'])
                    ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_string($value) || ! EntitlementName::isName($value)) {
                            $fail(__('kitsune::media.readers.not_a_name'));
                        }
                    }),
                TextInput::make(self::CONFIRMATION)
                    ->label(__('kitsune::media.readers.bulk.confirm_name'))
                    ->helperText(__('kitsune::media.readers.bulk.confirm_help'))
                    ->required()
                    ->maxLength(EntitlementName::LONGEST)
                    ->same(MediaReaderActions::FIELD)
                    ->validationMessages(['same' => __('kitsune::media.readers.bulk.not_confirmed')])
                    ->extraInputAttributes(['dir' => 'ltr', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'autocomplete' => 'off', 'autocorrect' => 'off']),
            ] : null)
            ->action(static function (BulkAction $action, Builder $selectedRecordsQuery, HasTable $livewire, array $data): void {
                $keys = BulkSelection::keysSelected($livewire);
                self::selected($action, $selectedRecordsQuery, $data, $keys === null ? null : count($keys));
            });
    }

    /**
     * @param  Builder<Entry>  $selected
     * @param  array<string, mixed>  $data
     */
    public static function selected(BulkAction $action, Builder $selected, array $data, ?int $keys = null): void
    {
        $until = BulkSelection::deadline();
        if (self::name($data) === null) {
            self::refuseName($action);

            return;
        }
        if ($keys !== null && $keys > BulkSelection::MOST_AT_ONCE) {
            self::refuseTooMany($action);

            return;
        }
        $records = BulkSelection::upTo($selected);
        $records === null ? self::refuseTooMany($action) : self::offerEach($action, $records, $data, $until, $keys);
    }

    /**
     * @param  iterable<Model>  $records
     * @param  array<string, mixed>  $data
     */
    public static function offerEach(BulkAction $action, iterable $records, array $data, ?CarbonInterface $until = null, ?int $keys = null): void
    {
        $name = self::name($data);
        if ($name === null) {
            self::refuseName($action);

            return;
        }
        $batch = [];
        foreach ($records as $record) {
            $batch[] = $record;
            if (count($batch) > BulkSelection::MOST_AT_ONCE) {
                self::refuseTooMany($action);

                return;
            }
        }
        if ($keys !== null && $keys > BulkSelection::MOST_AT_ONCE) {
            self::refuseTooMany($action);

            return;
        }
        if ($batch === []) {
            BulkSelection::refuse($action, __('kitsune::media.readers.bulk.not_started'), __('kitsune::media.readers.bulk.empty'));

            return;
        }
        $budget = BulkSelection::within($until);
        $offered = $unchanged = $refused = $failed = 0;
        $lines = [];
        $reported = false;
        foreach ($batch as $index => $record) {
            $label = __('kitsune::media.readers.bulk.selected_file', ['position' => $index + 1]);
            if (! $budget->starts()) {
                $lines[] = __('kitsune::media.readers.bulk.not_tried', ['title' => $label]);

                continue;
            }
            try {
                $entry = self::scopedRecord($record);
                if ($entry === null) {
                    $refused++;
                    $lines[] = __('kitsune::media.readers.bulk.unavailable', ['title' => $label]);

                    continue;
                }
                if (EntryResource::can('view', $entry)) {
                    $title = $entry->getAttribute('title');
                    $label = is_string($title) && $title !== '' ? $title : $label;
                }
                if (MediaVisibility::offer($entry, $name) === MediaVisibility::UNCHANGED) {
                    $unchanged++;
                    $lines[] = __('kitsune::media.readers.bulk.unchanged', ['title' => $label]);
                } else {
                    $offered++;
                    $lines[] = __('kitsune::media.readers.bulk.offered', ['title' => $label]);
                }
            } catch (MediaVisibilityRefused $failure) {
                $refused++;
                $reason = match ($failure->reason) {
                    MediaVisibilityRefused::PUBLIC_FILE => __('kitsune::media.readers.public'),
                    MediaVisibilityRefused::SHARED => __('kitsune::media.readers.shared'),
                    MediaVisibilityRefused::TRASHED => __('kitsune::media.readers.bulk.in_trash'),
                    MediaVisibilityRefused::NO_FILE => __('kitsune::media.readers.bulk.no_file'),
                    default => __('kitsune::media.readers.bulk.unavailable_reason'),
                };
                $lines[] = __('kitsune::media.readers.bulk.refused', ['title' => $label, 'reason' => $reason]);
            } catch (Throwable $failure) {
                if (! $reported) {
                    report($failure);
                    $reported = true;
                }
                // A commit may have landed before it reported failure. State is confirmed, never guessed from an error.
                if (self::nowOffered($record, $name)) {
                    $offered++;
                    $lines[] = __('kitsune::media.readers.bulk.offered', ['title' => $label]);
                } else {
                    $failed++;
                    $lines[] = __('kitsune::media.readers.bulk.unconfirmed', ['title' => $label]);
                }
            }
        }
        $gone = $keys === null ? 0 : max(0, $keys - count($batch));
        if ($gone > 0) {
            $lines[] = __('kitsune::media.readers.bulk.gone', ['count' => $gone]);
        }
        $notTried = $budget->notTried();
        $complete = $refused + $failed + $gone + $notTried === 0;
        $complete ? $action->success() : $action->failure();
        $summary = __('kitsune::media.readers.bulk.summary', ['offered' => $offered, 'unchanged' => $unchanged, 'refused' => $refused + $gone, 'failed' => $failed, 'not_tried' => $notTried]);
        $notice = Notification::make()->persistent()->title(__('kitsune::media.readers.bulk.results'))
            // Keep every result reachable without letting a fifty-file notice exceed a narrow viewport.
            // Filament sanitizes notification HTML; title supplies the region's accessible name after that pass.
            ->body('<p>'.e($summary).'</p><div role="region" tabindex="0" title="'.e(__('kitsune::media.readers.bulk.per_file_results')).'" style="max-height:40vh;overflow:auto;overscroll-behavior:contain"><ul><li>'
                .implode('</li><li>', array_map(e(...), $lines)).'</li></ul></div>');
        $complete ? $notice->success() : $notice->warning();
        $notice->send();
    }

    /** Reload inside the current list boundary before reading a feedback title or asking the writer. */
    private static function scopedRecord(Model $record): ?Entry
    {
        $context = app(Context::class);
        $user = Permissions::currentUser();
        if (! $record instanceof Entry || $user === null || $context->orgId() === null || $context->siteId() === null || ! app()->bound(EntryType::class)) {
            return null;
        }
        $type = app(EntryType::class);
        $org = $context->orgId();
        $site = $context->siteId();
        $connection = $type->getConnectionName();
        if (! Site::on($connection)->where('org_id', $org)->whereKey($site)->exists()) {
            return null;
        }
        $storedType = EntryType::on($connection)->whereKey($type->getKey())->where('is_media', true)
            ->where(static fn (Builder $query) => $query->whereNull('org_id')->orWhere('org_id', $org))->first();
        if ($storedType === null || $storedType->handle !== $type->handle) {
            return null;
        }
        $entry = Entry::on($connection)->withTrashed()->whereKey($record->getKey())
            ->where('org_id', $org)->where('type_handle', $storedType->handle)
            ->where(static fn (Builder $query) => $query->where('site_id', $site)->orWhereNull('site_id'))->first();

        return $entry !== null && $entry->type_handle === $storedType->handle && EntryResource::can('publish', $entry) ? $entry : null;
    }

    private static function nowOffered(Model $record, string $name): bool
    {
        try {
            $entry = self::scopedRecord($record);
            $file = $entry === null ? null : MediaDelivery::fileFor($entry);

            return $entry !== null && ! $entry->trashed() && $entry->site_id !== null && $file !== null
                && $file->getAttribute('visibility') === 'private' && $file->getAttribute('reader_entitlement') === $name;
        } catch (Throwable) {
            return false;
        }
    }

    private static function mayOnType(string $action): bool
    {
        return app()->bound(EntryType::class) && Permissions::currentUser() !== null
            && Permissions::allows(Permissions::currentUser(), Permissions::forEntryType(app(EntryType::class)->handle, $action));
    }

    /** @param array<string, mixed> $data */
    private static function name(array $data): ?string
    {
        $name = $data[MediaReaderActions::FIELD] ?? null;

        return is_string($name) && EntitlementName::isName($name) && ($data[self::CONFIRMATION] ?? null) === $name ? $name : null;
    }

    private static function refuseName(BulkAction $action): void
    {
        BulkSelection::refuse($action, __('kitsune::media.readers.bulk.not_started'), __('kitsune::media.readers.bulk.not_confirmed'));
    }

    private static function refuseTooMany(BulkAction $action): void
    {
        BulkSelection::refuse($action, __('kitsune::media.readers.bulk.not_started'), __('kitsune::media.readers.bulk.too_many', ['max' => BulkSelection::MOST_AT_ONCE]));
    }
}
