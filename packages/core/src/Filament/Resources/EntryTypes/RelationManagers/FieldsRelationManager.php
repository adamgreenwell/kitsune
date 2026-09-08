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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Kitsune\Core\Fields\FieldType;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Filament\Schemas\SettingsSchemaRenderer;
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
                        ->options([1 => 'One', -1 => 'Many'])
                        ->default(1)
                        ->required()
                        // A type intrinsically single-valued says so, rather
                        // than being configured wrong and failing later.
                        ->disabled(fn (Get $get, ?Field $record): bool => $record !== null
                            || self::type($get)?->supportsCardinality() !== true)
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
                        ->helperText('Suggested by the field type; confirm it, because the platform cannot know.'),
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
                        ->disabled(fn (Get $get): bool => self::type($get)?->isIndexable() !== true)
                        ->dehydrated(),
                ]),

            // Rendered from the field type's own settingsSchema() data, so
            // core stays headless-capable and a new type needs no admin code.
            Section::make('Settings')
                ->schema(fn (Get $get): array => ($type = self::type($get)) === null
                    ? []
                    : SettingsSchemaRenderer::for($type, 'storage_settings'))
                ->visible(fn (Get $get): bool => (self::type($get)?->settingsSchema() ?? []) !== [])
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
                    ->mutateDataUsing($this->writeStorage(...))
                    ->after($this->syncSchema(...)),
            ])
            ->recordActions([
                EditAction::make()
                    ->fillForm($this->readStorage(...))
                    ->mutateDataUsing($this->writeStorage(...))
                    ->after($this->syncSchema(...)),
                DeleteAction::make(),
            ]);
    }

    /**
     * Storage attributes are prefixed in the form, so they can share it with
     * the presentation record without colliding on `settings`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function readStorage(array $data, Field $record): array
    {
        $storage = $record->fieldStorage;

        if ($storage === null) {
            return $data;
        }

        return [
            ...$data,
            'storage_handle' => $storage->handle,
            'storage_type' => $storage->type,
            'storage_cardinality' => $storage->cardinality,
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

            $this->pendingStorage = $existing;

            return $this->presentation($data, $existing);
        }

        $storage = new FieldStorage(['org_id' => $orgId, 'handle' => $data['storage_handle']]);

        $storage->type = $data['storage_type'];
        $storage->cardinality = (int) ($data['storage_cardinality'] ?? 1);
        $storage->pii_class = $data['storage_pii_class'];
        $storage->is_indexed = (bool) ($data['storage_is_indexed'] ?? false);
        $storage->setAttribute('settings', $data['storage_settings'] ?? []);
        $storage->save();

        $this->pendingStorage = $storage;

        return $this->presentation($data, $storage);
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
        $cardinality = (int) ($data['storage_cardinality'] ?? 1);

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
