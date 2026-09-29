<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Media\MediaBytes;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Models\EntryType;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * The media list's Upload action, and what it does with the files — ADR-042 decisions 3, 4 and 7.
 *
 * @internal
 *
 * ⚠️ EACH FILE IS ITS OWN UPLOAD. Several files are stored one by one through `MediaLibrary::store()`, each with its own
 * result: one refused leaves the rest stored, and the notification names every file and what became of it.
 *
 * ⚠️ `create` AND `publish`, ASKED OF THE SAME PREDICATE THE STAGING GATE ASKS (`Permissions::mayUpload()`). `store()`
 * creates media entries published, so an uploader without `publish` would be refused after the bytes were written.
 * The action is hidden from a user without `create`, for whom nothing on the page is theirs to do, and shown disabled
 * — naming the missing permission — to one who holds `create` but not `publish` (ADR-033's line). Filament refuses to
 * call a hidden or disabled action, and the handler asks again before it touches a file.
 */
final class MediaUpload
{
    /** Stored as asked. */
    public const STORED = 'stored';

    /** Stored private because *public* was chosen without its confirmation (ADR-042 decision 3). */
    public const KEPT_PRIVATE = 'kept_private';

    /** Refused, in words Kitsune wrote for the uploader. */
    public const REFUSED = 'refused';

    /** Failed for a reason that is not the uploader's to read: shown as a generic failure (decision 7). */
    public const FAILED = 'failed';

    /** The Upload header action for a media type's list. */
    public static function action(): Action
    {
        return Action::make('upload')
            ->label(__('kitsune::media.upload.action'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(__('kitsune::media.upload.heading'))
            ->modalSubmitActionLabel(__('kitsune::media.upload.submit'))
            // Hidden without `create`; Filament treats an unauthorised action as hidden and refuses to call it.
            ->authorize(static fn (): bool => self::may('create'))
            ->disabled(static fn (): bool => ! self::may('publish'))
            ->tooltip(static fn (): ?string => self::may('publish') ? null : self::needsPublish())
            /*
             * ⚠️ NO FIELD UNLESS THE USER MAY UPLOAD HERE, whatever hides or disables the action. A hand-built request
             * mounts an action by writing `mountedActions`, and Filament caches its schema without asking whether it
             * is hidden — the `attachFiles` gap decision 4 closed — so a hidden or disabled Upload holding a
             * `FileUpload` would be an upload field for anybody who can reach the page.
             */
            ->schema(static fn (): ?array => self::may('create') && self::may('publish') ? self::fields() : null)
            ->action(static function (array $data): void {
                $type = app(EntryType::class);

                self::notify(self::store(
                    Permissions::currentUser(),
                    $type,
                    is_array($data['files'] ?? null) ? $data['files'] : [$data['files'] ?? null],
                    (string) ($data['visibility'] ?? 'private'),
                    (bool) ($data['public_confirmed'] ?? false),
                    (bool) ($data['site_only'] ?? false),
                ));
            });
    }

    /**
     * Store each staged file as an entry of this type, and remove every staged file and its sidecar, whatever happens.
     *
     * ⚠️ AUTHORISED FIRST, AND ONLY `TemporaryUploadedFile` VALUES ARE READ (decision 4). Anything else in the field's
     * state — a path a browser sent back — is never opened: `preventFilePathTampering()` refuses one before the action
     * runs, and this refuses it again.
     *
     * ⚠️ NO STAGED FILE OR SIDECAR THIS RECEIVES SURVIVES IT, stored, refused or unauthorised (decision 4) — an upload
     * Filament stops before calling this is left to the intake sweep (decision 3's defaults), and so is one the intake
     * disk refuses to delete, which is reported. The sidecar holds the client's original filename, and
     * `TemporaryUploadedFile::delete()` removes only the file.
     *
     * @param  array<array-key, mixed>  $files
     * @return list<array{name: ?string, outcome: string, reason: ?string}>
     */
    public static function store(
        ?Authenticatable $user,
        EntryType $type,
        array $files,
        string $visibility,
        bool $publicConfirmed,
        bool $siteOnly,
    ): array {
        $staged = array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof TemporaryUploadedFile));
        // Each file `storeOne()` has not removed yet — so a delete the disk refuses is reported once, not twice.
        $pending = $staged;
        $results = [];

        try {
            if (! Permissions::mayUpload($user, $type->handle)) {
                foreach ($staged as $file) {
                    $results[] = ['name' => self::nameOf($file), 'outcome' => self::REFUSED, 'reason' => self::needsPermission($type)];
                }

                return $results;
            }

            /*
             * ⚠️ PRIVATE UNLESS PUBLIC WAS CHOSEN AND CONFIRMED. ADR-041 makes public an explicit act, and decision 3 an
             * acknowledged one: without the confirmation the file is stored private, never refused — and its line says so.
             */
            $public = $visibility === 'public';
            $stored = $public && $publicConfirmed ? 'public' : 'private';

            foreach ($staged as $index => $file) {
                $results[] = self::storeOne($file, $type, $stored, $siteOnly, $public && ! $publicConfirmed);
                unset($pending[$index]);
            }

            // A value that was not a file uploaded here names nothing, and is refused without being opened.
            if (count($staged) < count(array_filter($files, static fn (mixed $file): bool => $file !== null && $file !== ''))) {
                $results[] = ['name' => null, 'outcome' => self::REFUSED, 'reason' => __('kitsune::media.upload.not_staged')];
            }

            return $results;
        } finally {
            foreach ($pending as $file) {
                self::discard($file);
            }
        }
    }

    /**
     * One staged file, stored — or refused, or failed — and removed.
     *
     * @return array{name: ?string, outcome: string, reason: ?string}
     */
    private static function storeOne(TemporaryUploadedFile $file, EntryType $type, string $visibility, bool $siteOnly, bool $unconfirmed): array
    {
        $name = self::nameOf($file);

        try {
            /*
             * ⚠️ A STAGED FILE THAT IS NOT THERE IS REFUSED, NEVER BY PATH — `store()`'s own refusal of an unreadable
             * file names the absolute path. Livewire does not check its own writes: on a full disk the file's write
             * answers false and the endpoint signs the empty path (decision 4's *What this costs*), so the upload
             * arrives named for Livewire's staging directory, with no sidecar there, and is refused unnamed; the
             * sidecar Livewire wrote first, and any bytes that fitted, are not that file, and are left to the intake
             * sweep. Without a sidecar that names it — gone, empty or unreadable — an upload has no name at all, and
             * Livewire would make one up from its staging directory's; a file whose bytes went while its sidecar
             * stayed is refused by that name.
             */
            if ($name === null || ! is_file($file->getRealPath())) {
                return ['name' => $name, 'outcome' => self::REFUSED, 'reason' => __('kitsune::media.upload.incomplete')];
            }

            MediaLibrary::store($file->getRealPath(), $name, $type, $visibility, null, $siteOnly);

            return ['name' => $name, 'outcome' => $unconfirmed ? self::KEPT_PRIVATE : self::STORED, 'reason' => null];
        } catch (MediaRefused $refused) {
            return ['name' => $name, 'outcome' => self::REFUSED, 'reason' => $refused->getMessage()];
        } catch (Throwable $failure) {
            /*
             * ⚠️ ANYTHING ELSE IS A GENERIC FAILURE, AND REPORTED (decision 7). Its message may carry a server path, a
             * class name or — `MediaLibrary` rethrows whatever interrupted the row write — a query and its bindings.
             */
            report($failure);

            return ['name' => $name, 'outcome' => self::FAILED, 'reason' => null];
        } finally {
            self::discard($file);
        }
    }

    /**
     * The client's name for a staged file, from its sidecar — or null when the sidecar is gone or names nothing.
     *
     * ⚠️ READ FROM THE SIDECAR ITSELF, NEVER THROUGH `getClientOriginalName()`, whose fallback makes a name up from the
     * staged path when the sidecar has none — which Livewire writes empty for a name `json_encode()` refuses.
     */
    private static function nameOf(TemporaryUploadedFile $file): ?string
    {
        try {
            $sidecar = Storage::disk(FileUploadConfiguration::disk())->get(self::stagedPath($file).'.json');
        } catch (Throwable) {
            return null;
        }

        $meta = is_string($sidecar) ? json_decode($sidecar, true) : null;
        $name = is_array($meta) ? ($meta['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Remove a staged file and its sidecar. Best effort: the intake sweep removes what this cannot, by age.
     *
     * ⚠️ A REFUSED DELETE IS REPORTED, never shown. Core's intake disk neither throws nor reports (`MediaDisks`), so a
     * plain `delete()` answers a refusal with a `false` nobody reads; `MediaBytes::delete()` reads it, and asks
     * whether the file is still there. A file already gone is not a failure.
     */
    private static function discard(TemporaryUploadedFile $file): void
    {
        $path = self::stagedPath($file);

        foreach ([$path, $path.'.json'] as $staged) {
            try {
                MediaBytes::delete(FileUploadConfiguration::disk(), $staged);
            } catch (Throwable $failure) {
                report($failure);
            }
        }
    }

    /** Where Livewire staged the file, relative to its temporary-upload disk. */
    private static function stagedPath(TemporaryUploadedFile $file): string
    {
        return FileUploadConfiguration::path($file->getFilename(), false);
    }

    /**
     * One notification naming every file and what became of it — escaped as text, since Filament keeps markup (decision 7).
     *
     * @param  list<array{name: ?string, outcome: string, reason: ?string}>  $results
     */
    public static function notify(array $results): void
    {
        if ($results === []) {
            return;
        }

        $count = count($results);
        $stored = count(array_filter($results, static fn (array $r): bool => in_array($r['outcome'], [self::STORED, self::KEPT_PRIVATE], true)));
        $asked = $stored === count(array_filter($results, static fn (array $r): bool => $r['outcome'] === self::STORED));

        $title = match (true) {
            $stored === $count => trans_choice('kitsune::media.upload.stored_all', $count, ['count' => $count]),
            $stored === 0 => trans_choice('kitsune::media.upload.stored_none', $count, ['count' => $count]),
            default => __('kitsune::media.upload.stored_some', ['stored' => $stored, 'count' => $count]),
        };

        $notification = Notification::make()
            ->title(e($title))
            ->body(implode('<br>', array_map(self::line(...), $results)));

        match (true) {
            $stored === $count && $asked => $notification->success(),
            $stored === 0 => $notification->danger()->persistent(),
            default => $notification->warning()->persistent(),
        };

        $notification->send();
    }

    /** @param  array{name: ?string, outcome: string, reason: ?string}  $result */
    private static function line(array $result): string
    {
        $name = $result['name'] ?? __('kitsune::media.upload.unnamed');

        return e(match ($result['outcome']) {
            self::STORED => __('kitsune::media.upload.line_stored', ['name' => $name]),
            self::KEPT_PRIVATE => __('kitsune::media.upload.line_kept_private', ['name' => $name]),
            self::REFUSED => __('kitsune::media.upload.line_refused', ['name' => $name, 'reason' => $result['reason'] ?? '']),
            default => __('kitsune::media.upload.line_failed', ['name' => $name]),
        });
    }

    /**
     * The modal's fields: the files, their visibility — private every time it opens — and whether they are this site's
     * alone, shared across the organisation otherwise (decisions 2 and 3; Adam, 2026-09-29: chosen once for the upload).
     *
     * @return array<int, mixed>
     */
    private static function fields(): array
    {
        return [
            FileUpload::make('files')
                ->label(__('kitsune::media.upload.files'))
                ->multiple()
                ->required()
                /*
                 * ⚠️ NEVER STORED BY FILAMENT, AND NO URL MINTED FOR A STRING THE BROWSER SENDS BACK (decision 4). The
                 * handler hands each staged file to `MediaLibrary::store()`, which is the only way bytes enter the
                 * library, and removes it.
                 */
                ->storeFiles(false)
                ->getUploadedFileUsing(static fn (): ?array => null)
                /*
                 * ⚠️ AND NO `maxSize()`, which is not a hint to the browser: Filament adds a `max:` rule the submit
                 * validates, which asks the intake disk for the staged file's size — and for a staged file that is not
                 * there, as on a full disk, that threw before the handler could refuse it. The endpoint's rule is the
                 * ceiling, and refuses a larger file before anything is staged (decision 4).
                 */
                ->preventFilePathTampering(),
            Radio::make('visibility')
                ->label(__('kitsune::media.upload.visibility'))
                ->options([
                    'private' => __('kitsune::media.upload.private'),
                    'public' => __('kitsune::media.upload.public'),
                ])
                ->descriptions([
                    'private' => __('kitsune::media.upload.private_help'),
                    'public' => __('kitsune::media.upload.public_help'),
                ])
                ->default('private')
                ->required()
                ->live(),
            /*
             * ⚠️ THE CONFIRMATION IS AN ACKNOWLEDGEMENT, AND WITHOUT IT THE FILES ARE STORED PRIVATE (decision 3; Adam,
             * 2026-09-29). It says the two things plainly: a public file is served to anyone with its link, and a photo
             * may carry the place it was taken — nothing strips a camera's location from a public file (*What this
             * costs*).
             */
            Checkbox::make('public_confirmed')
                ->label(__('kitsune::media.upload.public_confirm'))
                ->helperText(__('kitsune::media.upload.public_warning'))
                ->visible(static fn (Get $get): bool => $get('visibility') === 'public'),
            Checkbox::make('site_only')
                ->label(__('kitsune::media.upload.site_only'))
                ->helperText(__('kitsune::media.upload.site_only_help'))
                ->default(false),
        ];
    }

    /** Whether the current user holds this action on the list's type. */
    private static function may(string $action): bool
    {
        if (! app()->bound(EntryType::class)) {
            return false;
        }

        return Permissions::allows(Permissions::currentUser(), Permissions::forEntryType(app(EntryType::class)->handle, $action));
    }

    /** The disabled action's reason, naming the permission it lacks. */
    private static function needsPublish(): string
    {
        $type = app(EntryType::class);

        return __('kitsune::media.upload.needs_publish', [
            'type' => $type->plural_name ?: $type->handle,
            'permission' => Permissions::forEntryType($type->handle, 'publish'),
        ]);
    }

    /** Why a user who may not upload here stored nothing, naming both permissions. */
    private static function needsPermission(EntryType $type): string
    {
        return __('kitsune::media.upload.needs_permission', [
            'create' => Permissions::forEntryType($type->handle, 'create'),
            'publish' => Permissions::forEntryType($type->handle, 'publish'),
        ]);
    }
}
