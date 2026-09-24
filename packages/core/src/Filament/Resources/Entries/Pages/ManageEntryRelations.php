<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries\Pages;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Concerns\InteractsWithEntryType;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;

/**
 * Spike #10: a ManageRelatedRecords PAGE under the {type} route parameter.
 *
 * The 2026-09-07 relation-manager spike cleared RelationManager COMPONENTS,
 * which register no routes of their own. This is the other construct — a
 * resource page, which does register a route — and was the last untested
 * corner of ADR-012's URL contract.
 */
class ManageEntryRelations extends ManageRelatedRecords
{
    use InteractsWithEntryType;

    protected static string $resource = EntryResource::class;

    protected static string $relationship = 'related';

    protected static ?string $title = 'Related entries';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                // ⚠️ Its OWN `dir="auto"`, because this is its own column definition.
                // Adding it to EntryResource's table did nothing for this page — a
                // related Arabic-titled record still inherited the panel's direction.
                // A screen is only as complete as the enumeration behind it.
                TextColumn::make('title')->searchable()
                    ->extraAttributes(['dir' => 'auto']),
                TextColumn::make('type_handle')->badge()->label('Type'),
            ])
            /*
             * ⚠️ THE LISTED ROWS AND THE ATTACH DIALOG BOTH QUERY `Entry`, AND A POLICY GOVERNS NEITHER.
             * Review found it: `EntryPolicy::view()` is asked about a record somebody already has, while
             * Eloquent never consults one while BUILDING a query — so this page named the titles of types
             * the same user is refused at the URL, and the attach dialog offered them for selection.
             *
             * ⚠️ IT HIDES RELATIONS THAT EXIST, and that cost is real and deliberate. An editor may see
             * fewer related entries than the entry has, because the alternative is disclosing a title from a
             * type they may not view — and a title is the whole of what this page shows. Failing closed is
             * the direction ADR-020 takes everywhere else that a disclosure is the failure.
             */
            ->modifyQueryUsing(fn (Builder $query): Builder => Permissions::constrainToViewable(
                $query, Permissions::currentUser(),
            ))
            /*
             * ⚠️ BOTH NAMED, OR THE ATTACH DIALOG OFFERS NOTHING — found by ADR-042's browser test, the first to search
             * in it. Filament guesses the inverse as `entries`, which `Entry` does not have, so every search threw; and
             * with no title attribute it ignores the term. `admin.spec.js` only ever opened the dialog.
             */
            ->recordTitleAttribute('title')
            ->inverseRelationship('referencedBy')
            ->headerActions([
                AttachAction::make()
                    ->authorize(fn (): bool => $this->mayEditOwner())
                    ->recordSelectOptionsQuery(
                        fn (Builder $query): Builder => Permissions::constrainToViewable($query, Permissions::currentUser()),
                    ),
            ])
            ->recordActions([DetachAction::make()->authorize(fn (): bool => $this->mayEditOwner())]);
    }

    /**
     * May the acting user change the entry whose relations these are?
     *
     * ⚠️ ATTACH AND DETACH ARE EDITS, AND THIS PAGE IS REACHABLE BY SOMEBODY WHO MAY ONLY VIEW. `canAccess()`
     * asks `viewAny`, and Filament's default action authorization on this page covers create, edit, delete and
     * view — not attach or detach — so both ran for anybody who could open it, and changed the entry's relations
     * for a user refused every ordinary edit. Review found it, one page along from the History's restore.
     *
     * `EntryResource::canEdit()` is the question the edit page asks, the same answer the restore action uses —
     * one rule, not a third copy. Filament treats an unauthorized action as hidden and refuses to mount or call
     * a hidden one, so a hand-built Livewire request meets the same answer as the missing button.
     */
    private function mayEditOwner(): bool
    {
        return EntryResource::canEdit($this->getRecord());
    }
}
