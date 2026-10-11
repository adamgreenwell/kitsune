<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Media\MediaDelivery;
use Kitsune\Core\Media\MediaVisibility;
use Kitsune\Core\Media\MediaVisibilityRefused;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\MediaFile;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Readers\ReaderAccounts;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Tenancy\Context;

/**
 * *Offer to readers*, *Change offer* and *Stop offering* on a media entry's View and Edit pages, and the lines the File
 * section shows about them — ADR-041 decisions 1–4 (Adam, 2026-10-11).
 *
 * @internal
 *
 * ⚠️ FOR WHOEVER MAY PUBLISH THE TYPE, AND HIDDEN FROM EVERYONE ELSE. Asked of the policy, which reads the stored row —
 * the same question `MediaVisibility::mayChange()` asks again under custody's lock, so a page held open across a revoked
 * grant changes nothing. Unlike *Make public*, which shows itself disabled to an editor who may only update, these three
 * are hidden from them: an offer decides who may download a file, as making it public does, and nobody else is asked.
 *
 * ⚠️ THE NAME AS TYPED, NEVER RESHAPED. The field refuses anything `EntitlementName::isName()` refuses, and the writer
 * asks again before its lock, so a hand-built request is refused in the writer's own words. Nothing trims, lower-cases or
 * otherwise mends what was typed: a name that reads as another is a near miss that fails closed and invisibly
 * (`EntitlementName`). The field is left to right, with no spelling check and no capitals added, whatever the panel's
 * language — a name is an identifier, not a word.
 *
 * ⚠️ AS STORED HERE, WITH ANY LOCATION IT HOLDS — decision 4. A file offered to readers is served as it is stored, and
 * a private file is never opened (ADR-042 decision 30), so a photo keeps any location it still holds — which is not
 * always what was uploaded: a JPEG made public at some point lost its GPS coordinates then. The dialog says so before
 * the offer, and the File section says so after it.
 *
 * ⚠️ SHOWN, DISABLED, WHERE IT CANNOT BE OFFERED — decision 2. A file shared with every site, or a public one, is
 * refused by the writer; the action says why before anyone types a name, as *Make public* names the permission it lacks.
 * *Stop offering* is never disabled: it clears an offer however it came to be there.
 *
 * ⚠️ NO TRANSACTION AROUND ANY OF THEM, as *Make public*'s: the writer commits under its own lock and audits inside it,
 * and nothing else here writes.
 *
 * ⚠️ THE FORM'S FIELD IS NOT THE COLUMN'S NAME. `MediaFileImmutabilityTest` reads core's source for any array keyed by
 * a column only `writeRow()` writes, and an array filling this form is not a writer.
 */
final class MediaReaderActions
{
    /** The field the name is typed into. */
    public const FIELD = 'entitlement';

    /**
     * The header actions for a media type's page; none on any other.
     *
     * @return list<Action>
     */
    public static function all(): array
    {
        return EntryResource::listsMedia() ? [self::offer(), self::change(), self::stop()] : [];
    }

    public static function offer(): Action
    {
        return self::named(Action::make('offerToReaders'))
            ->label(__('kitsune::media.readers.offer'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->visible(static fn (Entry $record): bool => self::liveFile($record) !== null && ! self::isOffered($record))
            ->disabled(static fn (Entry $record): bool => self::whyNot($record) !== null)
            ->tooltip(static fn (Entry $record): ?string => self::whyNot($record))
            ->modalHeading(static fn (Entry $record): string => __('kitsune::media.readers.offer_heading', ['title' => self::titleOf($record)]))
            ->modalDescription(__('kitsune::media.readers.as_stored'))
            ->modalSubmitActionLabel(__('kitsune::media.readers.offer_submit'))
            ->action(static fn (Entry $record, array $data) => self::offerOne($record, $data));
    }

    public static function change(): Action
    {
        return self::named(Action::make('changeReaderOffer'))
            ->label(__('kitsune::media.readers.change'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(static fn (Entry $record): bool => self::liveFile($record) !== null && self::isOffered($record))
            ->disabled(static fn (Entry $record): bool => self::whyNot($record) !== null)
            ->tooltip(static fn (Entry $record): ?string => self::whyNot($record))
            ->modalHeading(static fn (Entry $record): string => __('kitsune::media.readers.change_heading', ['title' => self::titleOf($record)]))
            ->modalDescription(__('kitsune::media.readers.change_warning').' '.__('kitsune::media.readers.as_stored'))
            ->modalSubmitActionLabel(__('kitsune::media.readers.change_submit'))
            // The name it is offered for now, where it is one; a stored value that is not a name is not repeated.
            ->fillForm(static fn (Entry $record): array => [self::FIELD => MediaDelivery::fileFor($record)?->readerEntitlement() ?? ''])
            ->action(static fn (Entry $record, array $data) => self::changeOne($record, $data));
    }

    public static function stop(): Action
    {
        return Action::make('stopOfferingToReaders')
            ->label(__('kitsune::media.readers.stop'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->databaseTransaction(false)
            ->visible(static fn (Entry $record): bool => self::liveFile($record) !== null && self::isOffered($record))
            ->authorize(static fn (Entry $record): bool => self::mayOffer($record))
            ->requiresConfirmation()
            ->modalHeading(static fn (Entry $record): string => __('kitsune::media.readers.stop_heading', ['title' => self::titleOf($record)]))
            ->modalDescription(__('kitsune::media.readers.stop_warning'))
            ->modalSubmitActionLabel(__('kitsune::media.readers.stop_submit'))
            ->action(static fn (Entry $record) => self::stopOne($record));
    }

    /**
     * Whether an offer stands on the entry's file — as its row stores it, a value that is not a name included, since the
     * writer refuses *Make public* for any stored value (decision 2). *Make public* asks it to say why it is disabled.
     */
    public static function isOffered(Entry $record): bool
    {
        return MediaDelivery::fileFor($record)?->getAttribute('reader_entitlement') !== null;
    }

    /**
     * The handler of *Offer to readers*, callable from tests: a notification for every outcome but a failure that is not
     * the editor's to read, which Filament shows as its own (ADR-042 decision 7).
     *
     * @param  array<string, mixed>  $data
     */
    public static function offerOne(Entry $record, array $data): void
    {
        self::offerAs($record, $data, 'offered', 'not_offered');
    }

    /**
     * The handler of *Change offer*, callable from tests.
     *
     * @param  array<string, mixed>  $data
     */
    public static function changeOne(Entry $record, array $data): void
    {
        self::offerAs($record, $data, 'changed', 'not_changed');
    }

    /** The handler of *Stop offering*, callable from tests. */
    public static function stopOne(Entry $record): void
    {
        $title = self::titleOf($record);

        try {
            $outcome = MediaVisibility::stopOffering($record);
        } catch (MediaVisibilityRefused $refused) {
            Notification::make()->danger()->persistent()
                ->title(e(__('kitsune::media.readers.not_stopped', ['title' => $title])))
                ->body(e($refused->getMessage()))
                ->send();

            return;
        }

        if ($outcome === MediaVisibility::UNCHANGED) {
            Notification::make()->info()->title(e(__('kitsune::media.readers.already_stopped', ['title' => $title])))->send();

            return;
        }

        Notification::make()->success()
            ->title(e(__('kitsune::media.readers.stopped', ['title' => $title])))
            ->body(e(__('kitsune::media.readers.stopped_body')))
            ->send();
    }

    /**
     * The File section's lines about readers, from the row it has already read — ADR-041 decision 3.
     *
     * ⚠️ THE LINK ONLY WHERE THE ROUTE COULD SERVE IT: a name the writer would store, on a private file kept to this site.
     * A value planted below the writer is shown for what it is, and no link is offered for a file no reader is served.
     * Absolute where the site has an address of its own (`kitsune:site address`), and in `local` and `testing` for a
     * host-less site too, whose origin is then `APP_URL`'s (`ReaderLinks::origin()`); anywhere else it is the page's path,
     * and a note says so and how to give the site an address. Never built from the request, whose Host is whatever the
     * admin was reached through (`ReaderLinks`).
     *
     * ⚠️ THE LINK IS TEXT, AND ITS COPY CONTROL A BUTTON. Filament's `copyable()` makes the text itself a click-only span,
     * with no name and nothing a keyboard can reach, and confirms in a tooltip a screen reader never hears. The link
     * stays text anyone can select; beside it is a real `<button>` named *Copy reader link*, which copies in the browser
     * (no request) and confirms through Filament's notifications, whose container is `role="status"`, so the
     * confirmation is announced — and says so when the browser refuses, as it does outside a secure context.
     *
     * ⚠️ THE NOTES SAY WHAT AN OWNER WOULD OTHERWISE FIND OUT FROM A READER: that nobody can sign in while reader accounts
     * are off here; that their own admin account is not a reader, so the link asks them to sign in as one; and that the
     * file goes out as it is stored, with any location it holds (decision 4). Each is text, not an entry: an entry with
     * its label hidden still gives a screen reader a term made from its name. Each takes its own direction (`dir=auto`),
     * as core's words are English under an Arabic admin until a translation exists.
     *
     * ⚠️ "STOP OFFERING" IS SAID ONLY TO WHOEVER HAS IT. The section shows to whoever may view the entry; a line that asks
     * for *Stop offering* names who may publish the type to anyone else.
     *
     * @return list<Component>
     */
    public static function fileLines(Entry $record, MediaFile $file): array
    {
        $stored = $file->getAttribute('reader_entitlement');
        $name = $file->readerEntitlement();
        $site = self::siteOf($record);
        $served = $name !== null && ! $file->isPublic() && $site !== null;
        $absolute = $served ? ReaderLinks::absoluteDownload($site, (int) $record->getKey()) : null;
        $link = $served ? $absolute ?? ReaderLinks::download($site, (int) $record->getKey()) : null;
        $notServed = $name !== null && ! $served;
        // Asked only for a line that would ask for *Stop offering*: the words for whoever has it, or for anyone else.
        $stop = static fn (string $words): string => self::mayOffer($record) ? $words : $words.'_others';

        return [
            TextEntry::make('media_file_readers')->label(__('kitsune::media.readers.file.readers'))
                ->state(match (true) {
                    $stored === null => __('kitsune::media.readers.file.not_offered'),
                    $name === null => __('kitsune::media.readers.file.'.$stop('malformed')),
                    default => $name,
                })
                // A name is an identifier, left to right; anything else here is core's words, in their own direction.
                ->extraAttributes(['dir' => $name !== null ? 'ltr' : 'auto']),
            TextEntry::make('media_file_reader_link')->label(__('kitsune::media.readers.file.link'))
                ->state($link)
                ->suffixAction(self::copyLink((string) $link))
                ->extraAttributes(['dir' => 'ltr'])
                ->hidden($link === null),
            self::note('media_file_reader_path_only', 'path_only', $served && $absolute === null),
            self::note('media_file_reader_not_served', $notServed ? $stop('not_served') : 'not_served', $notServed),
            self::note('media_file_reader_accounts_off', 'accounts_off', $served && ! self::signsIn($site)),
            self::note('media_file_reader_not_a_reader', 'not_a_reader', $served),
            self::note('media_file_reader_as_stored', 'as_stored', $served),
        ];
    }

    /**
     * The reader link's copy button: copied in the browser, and confirmed — or refused — in a notification.
     *
     * ⚠️ NOTHING TYPED REACHES THE SCRIPT. The link is built from the site's derived address and the entry's id, and the
     * words are core's; each is a `Js::from()` literal, so none can close the string it sits in.
     */
    private static function copyLink(string $link): Action
    {
        $notify = static fn (string $key, string $status): string => 'new FilamentNotification().title('
            .Js::from(__("kitsune::media.readers.file.{$key}")).").{$status}().send()";
        $refused = $notify('not_copied', 'danger');

        return Action::make('copyReaderLink')
            ->label(__('kitsune::media.readers.file.copy'))
            ->icon(Heroicon::OutlinedClipboardDocument)
            // No clipboard at all — an insecure context — is refused as a write the browser turned down is.
            ->alpineClickHandler('window.navigator.clipboard?.writeText('.Js::from($link).')'
                .'.then(() => '.$notify('copied', 'success').', () => '.$refused.') ?? '.$refused);
    }

    /** What the three header actions that take a name share. */
    private static function named(Action $action): Action
    {
        return $action
            ->databaseTransaction(false)
            ->authorize(static fn (Entry $record): bool => self::mayOffer($record))
            /*
             * ⚠️ NO FIELD UNLESS THE USER MAY ACT ON THIS FILE, as *Make public*'s: Filament caches an action's schema
             * without asking whether it is disabled. The handler asks the writer, which asks again.
             */
            ->schema(static fn (Entry $record): ?array => self::mayOffer($record) && self::whyNot($record) === null ? [
                TextInput::make(self::FIELD)
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
            ] : null);
    }

    /**
     * The file offered for the name typed, or for another: one notification for every outcome.
     *
     * @param  array<string, mixed>  $data
     */
    private static function offerAs(Entry $record, array $data, string $done, string $notDone): void
    {
        $title = self::titleOf($record);
        // Anything a request carries that is not a string is no name, and the writer says so in its own words.
        $name = is_string($data[self::FIELD] ?? null) ? $data[self::FIELD] : '';

        try {
            $outcome = MediaVisibility::offer($record, $name);
        } catch (MediaVisibilityRefused $refused) {
            Notification::make()->danger()->persistent()
                ->title(e(__("kitsune::media.readers.{$notDone}", ['title' => $title])))
                ->body(e($refused->getMessage()))
                ->send();

            return;
        }

        if ($outcome === MediaVisibility::UNCHANGED) {
            Notification::make()->info()->title(e(__('kitsune::media.readers.already_offered', ['title' => $title])))->send();

            return;
        }

        Notification::make()->success()
            ->title(e(__("kitsune::media.readers.{$done}", ['title' => $title])))
            ->body(e(__('kitsune::media.readers.offered_body', ['name' => $name])))
            ->send();
    }

    /** Whoever may publish the entry's stored type, asked of the policy; nobody signed in may not. */
    private static function mayOffer(Entry $record): bool
    {
        return Permissions::currentUser() !== null && EntryResource::can('publish', $record);
    }

    /**
     * Why the file cannot be offered, or null when it can: shared with every site, or public — each asked of what is
     * stored, never of the instance (decision 2).
     */
    private static function whyNot(Entry $record): ?string
    {
        if ($record->isStoredAsShared()) {
            return __('kitsune::media.readers.shared');
        }

        return MediaDelivery::fileFor($record)?->isPublic() === true ? __('kitsune::media.readers.public') : null;
    }

    /** The entry's file, or null in the trash or with none, where no reader action is shown — the writer refuses both. */
    private static function liveFile(Entry $record): ?MediaFile
    {
        return $record->trashed() ? null : MediaDelivery::fileFor($record);
    }

    /** The site whose readers a file of this entry is served to: the one in context, and only for an entry kept to it. */
    private static function siteOf(Entry $record): ?Site
    {
        $site = app(Context::class)->site();

        return $site !== null && $record->site_id !== null && (int) $record->site_id === (int) $site->getKey() ? $site : null;
    }

    /** Whether a reader can sign in on this site at all — what the download route's door asks first (`ReaderArea`). */
    private static function signsIn(?Site $site): bool
    {
        $accounts = app(ReaderAccounts::class);

        return $site !== null && $accounts->fault() === null && $accounts->mode($site)->signsIn();
    }

    /** A line of core's words in the section, keyed so a test can find it: text, with no label of any kind. */
    private static function note(string $key, string $words, bool $shown): Text
    {
        return Text::make(__("kitsune::media.readers.file.{$words}"))->key($key)
            ->extraAttributes(['dir' => 'auto'])
            ->columnSpanFull()
            ->hidden(! $shown);
    }

    private static function titleOf(Entry $record): string
    {
        $title = $record->getAttribute('title');

        return is_string($title) && $title !== '' ? $title : '#'.$record->getKey();
    }
}
