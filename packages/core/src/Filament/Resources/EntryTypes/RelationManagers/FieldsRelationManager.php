<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\EntryTypes\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Fields\FieldType;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Filament\Resources\EntryTypes\EntryTypeResource;
use Kitsune\Core\Filament\Schemas\SettingsSchemaRenderer;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;
use Throwable;

/**
 * Adding a field to an entity type, through the admin.
 *
 * Two records, one form. ADR-006 keeps Drupal's split — `field_storage`
 * defines the shape once and `fields` carries the per-type presentation —
 * and an author should not have to know that. The form writes both.
 *
 * Spike #10 cleared relation managers under a route parameter, but this one
 * sits under an ordinary resource, so none of that applies here.
 */
class FieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'fields';

    protected static ?string $title = 'Fields';

    /**
     * The unlimited cardinality, as `field_storage` stores it.
     *
     * Named rather than written as -1 in three places, because the form, the
     * resolution below and the reuse check all have to agree on it.
     */
    private const UNLIMITED = -1;

    /**
     * The form-only choice meaning "many, up to a number I will give you".
     *
     * ⚠️ A STRING, so it cannot collide with a cardinality. `0` was the obvious
     * sentinel and is unusable: Filament returns select state as a string and an
     * unset control reads as null, so `(int) $get(...)` makes both null and
     * "0" indistinguishable from the sentinel — the maximum input would appear
     * before a field type had been chosen. It never reaches storage.
     */
    private const LIMITED = 'max';

    /**
     * ⚠️ The relation manager authorizes itself, because it is its own route.
     *
     * A relation manager is a Livewire component with its own mount, so
     * gating the parent page is gating the parent page. `CanAuthorizeAccess`
     * calls this and aborts 403, which closes the direct-component path as
     * well as the rendered one — the same lesson as every bulk-write guard in
     * this project: one door is one door.
     *
     * Global schema (`org_id IS NULL`) is shared by every org, so no single
     * org may add fields to it.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof EntryType && EntryTypeResource::ownsRecord($ownerRecord);
    }

    /**
     * The selected field type, or null before one is chosen.
     *
     * ⚠️ Every registry lookup goes through this. `get('')` fails closed by
     * design — the registry refuses an unknown handle rather than falling back
     * to text — so calling it with the form's empty initial state 500s the
     * modal the moment it opens. Found by opening one; the PHP suite was
     * green, which is the whole argument in ADR-024.
     */
    private static function type(Get $get): ?FieldType
    {
        $handle = $get('storage_type');

        return is_string($handle) && $handle !== '' && app(FieldTypeRegistry::class)->has($handle)
            ? app(FieldTypeRegistry::class)->get($handle)
            : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shape')
                ->description('Locked once entries hold data: converting a field in place is how content gets destroyed quietly.')
                ->schema([
                    TextInput::make('storage_handle')
                        ->label('Handle')
                        ->required()
                        ->maxLength(FieldStorage::MAX_HANDLE_LENGTH)
                        ->rules(['regex:'.FieldStorage::HANDLE_PATTERN])
                        ->helperText('Lowercase snake_case. Becomes the JSON key and, if indexed, part of a column name.')
                        ->disabled(fn (?Field $record): bool => $record !== null)
                        ->dehydrated(),
                    Select::make('storage_type')
                        ->label('Field type')
                        ->required()
                        ->options(fn (): array => app(FieldTypeRegistry::class)->options())
                        ->live()
                        // Seed the settings state with the type's declared
                        // defaults. The Settings section is rebuilt when this
                        // changes, and a component inserted afterwards never
                        // applies its own default() — see the renderer.
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $type = self::type($get);

                            $set('storage_settings', $type === null ? [] : SettingsSchemaRenderer::defaultsFor($type));
                            $set('storage_pii_class', $type?->suggestedPiiClass() ?? 'none');

                            if ($type?->supportsCardinality() !== true) {
                                $set('storage_cardinality', 1);
                            }

                            if ($type?->isIndexable() !== true) {
                                $set('storage_is_indexed', false);
                            }
                        })
                        ->disabled(fn (?Field $record): bool => $record !== null)
                        ->dehydrated()
                        ->helperText('Cannot change once the field exists — create a new field, convert, verify, then drop the old one.'),
                    Select::make('storage_cardinality')
                        ->label('Values')
                        // ⚠️ Three options, not two. The stored column is an
                        // integer and every layer below already honours a finite
                        // bound — `max:{n}` in validation, `maxItems` in the
                        // published API schema, and the cardinality count the
                        // relation writer serialises against. Offering only One
                        // and Many meant the flagship builder could not express
                        // a capability the rest of the system enforces, so
                        // "at most three authors" had to be built by hand.
                        ->options([
                            1 => 'One',
                            self::UNLIMITED => 'Many — no limit',
                            self::LIMITED => 'Many — up to a maximum',
                        ])
                        ->default(1)
                        ->required()
                        ->live()
                        // A type intrinsically single-valued says so, rather
                        // than being configured wrong and failing later.
                        ->disabled(fn (Get $get, ?Field $record): bool => $record !== null
                            || self::type($get)?->supportsCardinality() !== true)
                        ->dehydrated(),
                    TextInput::make('storage_cardinality_max')
                        ->label('Maximum values')
                        ->numeric()
                        // Two, because a maximum of one IS cardinality one and a
                        // second way to say it would store a different integer
                        // for the same meaning.
                        ->minValue(2)
                        ->default(2)
                        ->required(fn (Get $get): bool => $get('storage_cardinality') === self::LIMITED)
                        ->visible(fn (Get $get): bool => $get('storage_cardinality') === self::LIMITED)
                        ->disabled(fn (?Field $record): bool => $record !== null)
                        ->helperText('Stored as the cardinality, so it is part of the locked shape.')
                        ->dehydrated(),
                ])
                ->columns(3),

            Section::make('Privacy')
                ->description('ADR-020: an unclassified field does not save. Only you know whether "Customer notes" holds personal data.')
                ->schema([
                    Select::make('storage_pii_class')
                        ->label('Personal data')
                        ->required()
                        ->options([
                            'none' => 'None — holds no personal data',
                            'personal' => 'Personal — identifies or describes a person',
                            'sensitive' => 'Sensitive — GDPR Article 9 special category',
                        ])
                        ->default(fn (Get $get): string => self::type($get)?->suggestedPiiClass() ?? 'none')
                        // Shared storage is refused on save (see
                        // refuseSharedStorageChange). Disabled here so the author
                        // finds that out before typing rather than after.
                        ->disabled(fn (?Field $record): bool => $this->editsSharedStorage($record))
                        ->dehydrated()
                        ->helperText(fn (?Field $record): string => $this->editsSharedStorage($record)
                            ? 'Shared with every entity type using this storage, so it is not editable here.'
                            : 'Suggested by the field type; confirm it, because the platform cannot know.'),
                ]),

            Section::make('Presentation')
                ->schema([
                    TextInput::make('label')->required()->maxLength(255),
                    TextInput::make('help_text')->maxLength(255),
                    Toggle::make('is_required'),
                    TextInput::make('group')->maxLength(255)->helperText('Optional grouping in the entry form.'),
                ])
                ->columns(2),

            Section::make('Querying')
                ->description('Every indexed field costs write throughput on every entry save.')
                ->schema([
                    Toggle::make('storage_is_indexed')
                        ->label('Index this field')
                        ->helperText('Adds a generated column and an index so this field can be filtered and sorted efficiently.')
                        ->disabled(fn (Get $get, ?Field $record): bool => self::type($get)?->isIndexable() !== true
                            || $this->editsSharedStorage($record))
                        ->dehydrated(),
                ]),

            // Rendered from the field type's own settingsSchema() data, so
            // core stays headless-capable and a new type needs no admin code.
            Section::make('Settings')
                ->schema(fn (Get $get): array => ($type = self::type($get)) === null
                    ? []
                    : SettingsSchemaRenderer::for($type, 'storage_settings'))
                ->visible(fn (Get $get): bool => (self::type($get)?->settingsSchema() ?? []) !== [])
                // Cascades to every rendered setting, which is why the whole
                // section carries it rather than each component the renderer
                // produced — the renderer returns data and knows nothing about
                // ownership (ADR-002).
                ->disabled(fn (?Field $record): bool => $this->editsSharedStorage($record))
                ->columns(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label')->searchable(),
                TextColumn::make('fieldStorage.handle')->label('Handle')->badge(),
                TextColumn::make('fieldStorage.type')->label('Type')->badge(),
                TextColumn::make('fieldStorage.pii_class')
                    ->label('Personal data')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'sensitive' => 'danger',
                        'personal' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_required')->boolean(),
                IconColumn::make('fieldStorage.is_indexed')->boolean()->label('Indexed'),
                IconColumn::make('fieldStorage.is_locked')
                    ->boolean()
                    ->label('Locked')
                    ->tooltip('Entries hold data for this field, so its shape can no longer change.'),
            ])
            ->reorderable('ordering')
            ->defaultSort('ordering')
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->reporting(
                        fn (): array => $this->writeStorage($data),
                    ))
                    ->after($this->syncSchema(...)),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm($this->readStorage(...))
                    // ⚠️ NOT writeStorage(). Editing this field's own storage
                    // and adopting somebody else's are different intents that
                    // happen to submit the same form — see updateStorage().
                    ->mutateDataUsing(fn (array $data, Field $record): array => $this->reporting(
                        fn (): array => $this->updateStorage($data, $record),
                    ))
                    ->after($this->syncSchema(...)),
                DeleteAction::make(),
            ]);
    }

    /**
     * Fill the edit modal from BOTH halves of the split.
     *
     * Storage attributes are prefixed in the form, so they can share it with
     * the presentation record without colliding on `settings`.
     *
     * ⚠️ It starts from the RECORD, and taking `array $data` was the bug.
     *
     * `fillForm()` replaces `EditAction`'s own filling — which is what called
     * `$record->attributesToArray()` — and evaluates its callback with the
     * ACTION's data, which at mount time is `[]`. So the storage half filled
     * and the presentation half did not: `label` opened empty, and because it
     * is `required()` every save failed validation with the modal left open.
     * The field edit modal had never worked, in any form.
     *
     * Nothing in the PHP suite could see it — there is no Livewire harness
     * here — and no browser test had opened the modal. ADR-024's mandatory
     * browser layer is the only reason it was found.
     *
     * @return array<string, mixed>
     */
    public function readStorage(Field $record): array
    {
        // The same source EditAction uses by default, so the presentation
        // half behaves exactly as an unmodified Filament form would.
        $data = $record->attributesToArray();

        $storage = $record->fieldStorage;

        if ($storage === null) {
            return $data;
        }

        $cardinality = (int) $storage->cardinality;

        return [
            ...$data,
            'storage_handle' => $storage->handle,
            'storage_type' => $storage->type,
            // A stored bound above one is the LIMITED choice plus its number;
            // one and unlimited are the choices themselves.
            'storage_cardinality' => $cardinality > 1 ? self::LIMITED : $cardinality,
            'storage_cardinality_max' => $cardinality > 1 ? $cardinality : null,
            'storage_pii_class' => $storage->pii_class,
            'storage_is_indexed' => $storage->is_indexed,
            'storage_settings' => $storage->settings ?? [],
        ];
    }

    /**
     * Write the storage record, then hand the presentation record back.
     *
     * `field_storage` is reused across entity types by design (ADR-006), so a
     * handle already defined in this org is ADOPTED rather than duplicated —
     * that reuse is the point of the split, and creating a second row would
     * quietly fork the shape.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function writeStorage(array $data): array
    {
        $orgId = app(Context::class)->orgId();

        $existing = FieldStorage::query()
            ->where('org_id', $orgId)
            ->where('handle', $data['storage_handle'])
            ->first();

        // ⚠️ Reuse ADOPTS the existing definition; it does not rewrite it.
        //
        // Keeping the old type and cardinality while overwriting `pii_class`,
        // `settings` and `is_indexed` from the new form was the worst of both:
        // every other field sharing that storage changed behaviour, and the
        // field just created was not even the type its author selected. The
        // shared row is shared — one form cannot speak for all of it.
        if ($existing !== null) {
            $this->refuseIncompatibleReuse($existing, $data);
            $this->refuseDivergentReuse($existing, $data);
            $this->refuseSecondFieldOnThisType($existing);

            $this->pendingStorage = $existing;

            return $this->presentation($data, $existing);
        }

        $storage = new FieldStorage(['org_id' => $orgId, 'handle' => $data['storage_handle']]);

        $storage->type = $data['storage_type'];
        $storage->cardinality = self::resolveCardinality($data);
        $storage->pii_class = $data['storage_pii_class'];
        $storage->is_indexed = (bool) ($data['storage_is_indexed'] ?? false);
        $storage->setAttribute('settings', $data['storage_settings'] ?? []);
        $storage->save();

        $this->pendingStorage = $storage;

        return $this->presentation($data, $storage);
    }

    /**
     * Apply an edit to the storage row this field already points at.
     *
     * ⚠️ `writeStorage()` was bound to BOTH actions, and on edit its lookup
     * always found this field's OWN storage — so it took the adoption branch
     * and returned without applying anything. `storage_handle` is `disabled()`
     * on edit, so that was not an edge case: EVERY storage edit was silently
     * discarded while the save reported success.
     *
     * The worst of it is `pii_class`. It drives erasure and revision
     * redaction (ADR-020), so an author who correctly reclassified a field as
     * `personal` was told it saved, and a later erasure request would not
     * reach it. Adoption is the right rule for a handle somebody else defined;
     * it is the wrong rule for the row you are editing.
     *
     * Shape lives elsewhere on purpose: `type` and `cardinality` are
     * `disabled()` in the form and locked by `FieldStorage::guardShape()` once
     * entries hold data, so this writes only what the modal leaves enabled and
     * lets the model refuse the rest.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateStorage(array $data, Field $record): array
    {
        $storage = $record->fieldStorage;

        // A field with no storage row is a broken split, not something this
        // form can repair by guessing. The create path says adopt-or-create
        // explicitly, so defer to it rather than inventing a third rule.
        if ($storage === null) {
            return $this->writeStorage($data);
        }

        // ⚠️ SHARED storage is not this org's to change, and making the edit
        // path write at all is what opened this.
        //
        // `Field::guardStorageOwnership()` deliberately permits an org-owned
        // type to attach GLOBAL storage (`org_id IS NULL`), the same way it
        // permits a global entry type. So the row this form just loaded may be
        // one every org depends on — and writing the submitted classification,
        // indexing and settings to it would let one customer decide every
        // customer's behaviour. That is a wider blast radius than the cross-org
        // case, not a narrower one.
        //
        // Refused for the STORAGE half only. The `Field` row is this type's own,
        // so relabelling a global field, or making it required here, stays
        // allowed — which is the point of ADR-006's split.
        if (! $this->ownsStorage($storage)) {
            $this->refuseSharedStorageChange($storage, $data);

            // Nothing saved, so nothing to sync — and leaving a pending row set
            // would have `syncSchema()` act on attributes this refused to write.
            $this->pendingStorage = null;

            return $this->presentation($data, $storage);
        }

        $this->applyStorageAttributes($storage, $data);

        // Through the model, so `guardShape()` runs: locked shape, projection
        // settings on a locked row, and the pii_class fail-closed check.
        $storage->save();

        $this->pendingStorage = $storage;

        return $this->presentation($data, $storage);
    }

    /**
     * Whether the form is editing a field whose STORAGE is shared.
     *
     * Null record means create, where storage is either adopted as it stands or
     * defined fresh — neither of which edits somebody else's row.
     */
    private function editsSharedStorage(?Field $record): bool
    {
        $storage = $record?->fieldStorage;

        return $storage !== null && ! $this->ownsStorage($storage);
    }

    /**
     * The integer cardinality a submitted form means.
     *
     * The form has two controls for one column: a choice, and a number that
     * only exists for one of the choices. Resolving it in one place is what
     * keeps the write and the reuse check from disagreeing.
     *
     * @param  array<string, mixed>  $data
     */
    private static function resolveCardinality(array $data): int
    {
        $choice = $data['storage_cardinality'] ?? 1;

        if ($choice !== self::LIMITED) {
            return (int) $choice;
        }

        // Floored at two rather than trusted: `minValue(2)` is a client-side
        // and validation concern, and this is the value that gets stored.
        return max(2, (int) ($data['storage_cardinality_max'] ?? 2));
    }

    /** Whether this storage row belongs to the org currently signed in. */
    private function ownsStorage(FieldStorage $storage): bool
    {
        return $storage->org_id !== null
            && (int) $storage->org_id === app(Context::class)->orgId();
    }

    /**
     * Assign the storage attributes the edit modal leaves enabled.
     *
     * ⚠️ Present-key tests, not `?? false` / `?? []` as on create.
     *
     * The two paths differ in what an ABSENT key means. On create it means "not
     * requested", and false is right. Here it would mean "destroy the index" or
     * "erase the settings" — so a key the form did not submit leaves the stored
     * value alone. Every one of these is `dehydrated()`, so absence is a
     * form-shape bug; it should not also be data loss.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyStorageAttributes(FieldStorage $storage, array $data): void
    {
        if (array_key_exists('storage_pii_class', $data)) {
            $storage->pii_class = $data['storage_pii_class'];
        }

        if (array_key_exists('storage_is_indexed', $data)) {
            $storage->is_indexed = (bool) $data['storage_is_indexed'];
        }

        if (array_key_exists('storage_settings', $data)) {
            $storage->setAttribute('settings', $data['storage_settings']);
        }
    }

    /**
     * Refuse a change to storage this org does not own — and only a CHANGE.
     *
     * ⚠️ NOT `isDirty()`, which was the first attempt and was wrong.
     *
     * `isDirty()` encodes a cast JSON attribute on both sides and compares the
     * strings, and Filament's numeric inputs submit STRINGS — so an untouched
     * form returns `['maxLength' => '320']` for a row holding
     * `['maxLength' => 320]` and the check called that a change. Every
     * presentation-only edit of a shared field would then have been refused,
     * which is the opposite of the intent: the `Field` row IS this type's own,
     * and refusing to relabel a global field makes it unusable rather than
     * merely uneditable. Caught by a test written for exactly this.
     *
     * So each attribute is compared on its own terms, and the reasoning is per
     * attribute rather than delegated to a helper that cannot know it.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseSharedStorageChange(FieldStorage $storage, array $data): void
    {
        $changes = $this->storageDifferences($storage, $data);

        if ($changes === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Field storage [%s] is %s, so its %s %s not this organisation\'s to change '
            .'(ADR-006, ADR-021) — a change there would reach every entity type and every '
            .'organisation using it. The label, help text and whether it is required on THIS '
            .'type are yours to edit.',
            $storage->handle,
            $storage->org_id === null ? 'shared by every organisation' : 'owned by another organisation',
            implode(' and ', $changes),
            count($changes) === 1 ? 'is' : 'are',
        ));
    }

    /**
     * The storage attributes this submission would change, named for a human.
     *
     * ⚠️ ONE comparison, because two callers need the same answer for opposite
     * reasons: adoption must not silently discard a submitted classification,
     * and shared storage must not be rewritten at all. Written twice they drift,
     * which is the failure invariant 14 exists for — and this PR has already had
     * that four times over.
     *
     * ⚠️ NOT `isDirty()`, which is what I reached for first. It encodes a cast
     * JSON attribute on both sides and compares the strings, and Filament's
     * numeric inputs submit STRINGS — so an untouched form returns
     * `['maxLength' => '320']` for a row holding `['maxLength' => 320]` and the
     * check called that a change. Every presentation-only edit of a shared field
     * would have been refused. Each attribute is compared on its own terms
     * instead, with the reasoning stated per attribute.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function storageDifferences(FieldStorage $storage, array $data): array
    {
        $differences = [];

        // Both sides are one of three known strings.
        if (array_key_exists('storage_pii_class', $data)
            && (string) $data['storage_pii_class'] !== (string) $storage->pii_class) {
            $differences[] = 'privacy classification';
        }

        // A checkbox arrives as "1"/"0"/true/false depending on the transport.
        if (array_key_exists('storage_is_indexed', $data)
            && (bool) $data['storage_is_indexed'] !== (bool) $storage->is_indexed) {
            $differences[] = 'indexing';
        }

        // ⚠️ Loose comparison, and only here. It is what lets `'320'` equal
        // `320` while still catching a real edit — PHP 8 compares a non-numeric
        // string AS a string, so it does not collapse distinct values the way
        // PHP 7's would have.
        if (array_key_exists('storage_settings', $data)
            && ($data['storage_settings'] ?? []) != ($storage->settings ?? [])) {
            $differences[] = 'settings';
        }

        return $differences;
    }

    /**
     * Refuse an adoption that would silently discard what the author submitted.
     *
     * ⚠️ Adoption keeps the existing definition — that was the previous round's
     * fix and it is right — but it did so SILENTLY for the classification and the
     * settings as well as the shape. Selecting `personal` for a handle whose
     * stored row says `none` reported success and attached `none`, so an erasure
     * would never reach that field; choosing `decimal` for a handle stored as
     * `integer` gave the author a field that truncates.
     *
     * The shape check already refuses a mismatched `type` or `cardinality` for
     * exactly this reason. Classification and settings define observable
     * behaviour just as much, so they get the same treatment rather than being
     * quietly overruled.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseDivergentReuse(FieldStorage $storage, array $data): void
    {
        $differences = $this->storageDifferences($storage, $data);

        if ($differences === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Handle [%s] already describes a field in this organisation, and storage is shared '
            .'across entity types (ADR-006) — so adding it here ADOPTS that definition rather than '
            .'creating a second one. Its %s %s from what you submitted, and adopting it would give '
            .'you the stored behaviour without saying so. Match the existing definition, or choose '
            .'a different handle.',
            $storage->handle,
            implode(' and ', $differences),
            count($differences) === 1 ? 'differs' : 'differ',
        ));
    }

    /**
     * Turn a refusal into something the author can read.
     *
     * ⚠️ Every guard in this form threw a RuntimeException out of
     * `mutateDataUsing`, which Livewire answers with a 500. The author saw the
     * modal stay open with NO message at all — no validation error, no
     * notification, nothing — while the log recorded the real reason. Measured:
     * submitting a duplicate handle left the dialog sitting there and wrote
     * `UNIQUE constraint failed: fields.entry_type_id, fields.field_storage_id`
     * to the log. Silence is a worse outcome than the exception it was hiding.
     *
     * `Halt` is Filament's own way to abort an action without an error page, so
     * the notification carries the reason and nothing 500s. One seam, so every
     * refusal here becomes legible rather than each one having to remember.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    private function reporting(callable $work): mixed
    {
        try {
            return $work();
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('That field could not be saved')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }
    }

    /**
     * Refuse a second field on THIS type backed by the same storage.
     *
     * ⚠️ `fields` is `UNIQUE (entry_type_id, field_storage_id)`, and adoption
     * happily returned the existing storage id for a handle already used on this
     * very type — so the insert violated the constraint. Reuse across DIFFERENT
     * types is the whole point of ADR-006's split; reuse twice on one type is an
     * author repeating themselves, and it has a name they can act on.
     */
    private function refuseSecondFieldOnThisType(FieldStorage $storage): void
    {
        // ⚠️ `isset` first. `getOwnerRecord()` returns a typed property with no
        // default, so calling it before Livewire has mounted the component
        // raises "must not be accessed before initialization" — which is what a
        // unit test constructing the manager directly does.
        $type = isset($this->ownerRecord) ? $this->getOwnerRecord() : null;

        if (! $type instanceof EntryType) {
            return;
        }

        $existing = Field::query()
            ->where('entry_type_id', $type->getKey())
            ->where('field_storage_id', $storage->getKey())
            ->first();

        if ($existing === null) {
            return;
        }

        throw new RuntimeException(sprintf(
            'This entry type already has a field using storage [%s] — it is labelled "%s". Storage '
            .'is shared across entity types by design (ADR-006), but a type can only use a given '
            .'definition once. Edit that field, or choose a different handle.',
            $storage->handle,
            $existing->label,
        ));
    }

    /**
     * Refuse a reuse the author almost certainly did not mean.
     *
     * A handle already defined in this org is adopted (ADR-006), and adoption
     * only makes sense when the SHAPE matches. Submitting a different type or
     * cardinality means the author was describing a different field and
     * happened to pick a taken handle — silently giving them the old shape
     * creates a field that is not what they selected.
     *
     * @param  array<string, mixed>  $data
     */
    private function refuseIncompatibleReuse(FieldStorage $existing, array $data): void
    {
        $type = $data['storage_type'] ?? null;
        // ⚠️ Through the same resolution the write uses. Casting the raw value
        // would read the `max` sentinel as 0, so adopting a storage row with a
        // finite bound would look like a shape mismatch and be refused.
        $cardinality = self::resolveCardinality($data);

        if ($existing->type === $type && (int) $existing->cardinality === $cardinality) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Handle [%s] already describes a %s field holding %s in this organisation, and storage '
            .'is shared across entity types (ADR-006) — so reusing it here would give you that '
            .'field, not the %s you selected. Choose a different handle, or add the existing field '
            .'as it is.',
            $existing->handle,
            $existing->type,
            (int) $existing->cardinality === 1 ? 'one value' : 'many values',
            is_string($type) ? $type : 'field',
        ));
    }

    /**
     * The presentation half of the split, plus the storage it points at.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function presentation(array $data, FieldStorage $storage): array
    {
        return [
            'field_storage_id' => $storage->getKey(),
            'label' => $data['label'],
            'help_text' => $data['help_text'] ?? null,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'group' => $data['group'] ?? null,
        ];
    }

    private ?FieldStorage $pendingStorage = null;

    /**
     * Apply the schema change AFTER the row is committed.
     *
     * DDL implicitly commits on MySQL, so a row write and a schema change
     * cannot be one transaction there whatever we do — the row is therefore
     * the source of truth and the schema follows it. A failure here leaves
     * drift that `kitsune:schema-sync` repairs, and the author is told rather
     * than left with a silently unindexed field.
     */
    public function syncSchema(): void
    {
        if ($this->pendingStorage === null) {
            return;
        }

        try {
            app(SchemaManager::class)->sync($this->pendingStorage);
        } catch (Throwable $e) {
            $this->pendingStorage->update(['is_indexed' => false]);

            Notification::make()
                ->title('The field was saved, but it could not be indexed')
                ->body($e->getMessage())
                ->warning()
                ->persistent()
                ->send();
        } finally {
            $this->pendingStorage = null;
        }
    }
}
