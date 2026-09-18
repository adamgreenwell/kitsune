<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Filament\Resources\Entries\EntryResource;
use Kitsune\Core\Filament\Resources\Entries\RelationManagers\RevisionsRelationManager;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Settings\SettingsWriter;
use Kitsune\Core\Settings\SiteTimezone;
use Kitsune\Core\Tenancy\Context;
use Livewire\Component;

/*
 * The settings store's first consumer: an instant is shown and entered in the current site's timezone, and a date is
 * not converted at all.
 *
 * ⚠️ FILAMENT'S OWN PIPELINE, NOT A RESTATEMENT OF IT. The form is a real `Schema` filled and read back through
 * Filament's state casts, and the cells are real columns formatted by Filament's own formatter, each bound to a bare
 * Livewire component — so these measure what Filament does with the timezone, rather than what it is assumed to do.
 */

beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom Media', 'slug' => 'golfdom-media']);
    app(Context::class)->setOrg($this->org);

    $this->site = Site::create([
        'org_id' => $this->org->id, 'handle' => 'golfdom-us', 'slug' => 'golfdom-us', 'name' => 'Golfdom US',
        'settings' => ['timezone' => 'America/New_York'],
    ]);
    app(Context::class)->setSite($this->site);

    $this->type = EntryType::create([
        'org_id' => $this->org->id, 'handle' => 'event', 'name' => 'Event', 'plural_name' => 'Events',
    ]);

    $field = fn (string $handle, string $type): FieldConfig => new FieldConfig(
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => $handle, 'type' => $type, 'pii_class' => 'none',
        ]),
        Field::create(['entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => ucfirst($handle)]),
    );

    $this->startsAt = $field('starts_at', 'datetime');
    $this->runsOn = $field('runs_on', 'date');
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(Context::class)->forget();
});

/** A bare Livewire component that can host a Filament schema and a table, which is all the pipeline needs. */
function timezoneHost(): Component&HasSchemas&HasTable
{
    return new class extends Component implements HasActions, HasSchemas, HasTable
    {
        use InteractsWithActions;
        use InteractsWithSchemas;
        use InteractsWithTable;

        /** @var array<string, mixed> */
        public ?array $data = [];

        public function render(): string
        {
            return '<div></div>';
        }
    };
}

/** A form holding one component, the way the entry pages hold theirs. */
function timezoneForm(Component&HasSchemas $host, SchemaComponent $component): Schema
{
    return Schema::make($host)->statePath('data')->components([$component]);
}

/** What a list cell shows for a stored value. */
function cellShows(Column $column, mixed $stored): ?string
{
    return $column->table(Table::make(timezoneHost()))->formatState($stored);
}

describe('the timezone', function (): void {
    it('is the site\'s resolved setting', function (): void {
        expect(SiteTimezone::current())->toBe('America/New_York');
    });

    it('is the platform default when there is no site in context, not an error', function (): void {
        config(['kitsune.settings' => ['timezone' => 'Europe/London']]);
        app()->forgetScopedInstances();

        // An org-level page: an org, and no site.
        app(Context::class)->setOrg($this->org);
        expect(SiteTimezone::current())->toBe('Europe/London');

        // A console command or a queued job: nothing at all.
        app(Context::class)->forget();
        expect(SiteTimezone::current())->toBe('Europe/London');
    });

    it('is UTC when nothing configures one', function (): void {
        // A host whose own `settings` map omits the key replaces the package's whole map (mergeConfigFrom is shallow).
        config(['kitsune.settings' => []]);
        app()->forgetScopedInstances();

        expect(SiteTimezone::current())->toBe(SiteTimezone::FALLBACK)
            ->and(SiteTimezone::FALLBACK)->toBe('UTC');
    });

    it('follows a change made during the request', function (): void {
        // Asked per call and invalidated on write, so a page that saves the setting renders with the new one.
        expect(SiteTimezone::current())->toBe('America/New_York');

        app(SettingsWriter::class)->set($this->site, 'timezone', 'Asia/Tokyo');

        expect(SiteTimezone::current())->toBe('Asia/Tokyo');
    });
});

describe('an instant', function (): void {
    it('round-trips through the picker: 09:00 entered in New York stores 13:00 UTC and shows 09:00 again', function (): void {
        /*
         * ⚠️ THE MEASUREMENT THE SLICE RESTS ON. With `->timezone()` on the picker, Filament's `DateTimeStateCast`
         * is supposed to read the entered wall-clock time in that zone and hand back the application's (UTC) — and
         * to show a stored UTC value in that zone. Asserted end to end, through a real entry save.
         */
        $host = timezoneHost();
        $form = timezoneForm($host, FieldValueRenderer::formComponent($this->startsAt));

        $form->fill(['values' => ['starts_at' => null]]);
        $host->data['values']['starts_at'] = '2026-09-18 09:00:00';
        $entered = $form->getState()['values']['starts_at'];

        expect($entered)->toBe('2026-09-18 13:00:00');

        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Tee time', 'values' => ['starts_at' => $entered]]);
        $stored = json_decode((string) DB::table('entries')->where('id', $entry->id)->value('values'), true)['starts_at'];

        expect($stored)->toBe('2026-09-18T13:00:00.000000+00:00');

        $reopened = timezoneHost();
        timezoneForm($reopened, FieldValueRenderer::formComponent($this->startsAt))
            ->fill(['values' => ['starts_at' => $stored]]);

        expect($reopened->data['values']['starts_at'])->toBe('2026-09-18 09:00:00');
    });

    it('follows daylight saving, which an offset could not', function (): void {
        // December in New York is EST, UTC-5: the same 09:00 is 14:00 UTC.
        $host = timezoneHost();
        $form = timezoneForm($host, FieldValueRenderer::formComponent($this->startsAt));

        $form->fill(['values' => ['starts_at' => null]]);
        $host->data['values']['starts_at'] = '2026-12-18 09:00:00';

        expect($form->getState()['values']['starts_at'])->toBe('2026-12-18 14:00:00');
    });

    it('is listed in the site\'s timezone', function (): void {
        expect(cellShows(FieldValueRenderer::tableColumn($this->startsAt), '2026-09-18T13:00:00.000000+00:00'))
            ->toBe('Sep 18, 2026 09:00:00');
    });

    it('is listed in the site\'s timezone in the entries table and the history', function (): void {
        $updated = EntryResource::table(Table::make(timezoneHost()))->getColumn('updated_at');
        $saved = (new RevisionsRelationManager)->table(Table::make(timezoneHost()))->getColumn('created_at');

        expect($updated?->formatState('2026-09-18 13:00:00'))->toBe('Sep 18, 2026 09:00:00')
            ->and($saved?->formatState('2026-09-18 13:00:00'))->toBe('Sep 18, 2026 09:00:00');
    });

    it('is shown in the platform default with no site in context', function (): void {
        app(Context::class)->forget();

        expect(cellShows(FieldValueRenderer::tableColumn($this->startsAt), '2026-09-18T13:00:00.000000+00:00'))
            ->toBe('Sep 18, 2026 13:00:00');
    });
});

describe('a date', function (): void {
    /*
     * ⚠️ A DATE IS NOT AN INSTANT. `Control::Date` and `Control::DateTime` both listed in `Cell::Timestamp`, so the
     * moment that cell learned the site's timezone it would have read `2026-09-18` as UTC midnight and shown
     * America/New_York the seventeenth. A date must come out as the date that went in, whatever the site's zone.
     */
    it('is listed as the same day in every timezone', function (): void {
        foreach (['America/New_York', 'Pacific/Pago_Pago', 'Pacific/Kiritimati', 'UTC'] as $timezone) {
            app(SettingsWriter::class)->set($this->site, 'timezone', $timezone);

            expect(cellShows(FieldValueRenderer::tableColumn($this->runsOn), '2026-09-18'))
                ->toBe('Sep 18, 2026', "a date moved in {$timezone}");
        }
    });

    it('round-trips through the picker as the same day, at an hour when UTC and New York disagree', function (): void {
        // 02:00 UTC on the 18th is 22:00 on the 17th in New York — the hour a converted date lands on the wrong day.
        Carbon::setTestNow(Carbon::parse('2026-09-18 02:00:00', 'UTC'));

        $host = timezoneHost();
        $form = timezoneForm($host, FieldValueRenderer::formComponent($this->runsOn));

        $form->fill(['values' => ['runs_on' => '2026-09-18']]);

        expect($host->data['values']['runs_on'])->toBe('2026-09-18')
            ->and($form->getState()['values']['runs_on'])->toBe('2026-09-18');
    });

    it('is not resolved through the site at all', function (): void {
        // The resolver would be asked for the timezone only by a component that converts.
        app()->offsetUnset(SettingsResolver::class);
        app()->scoped(SettingsResolver::class, fn () => throw new RuntimeException('a date asked for a timezone'));

        expect(cellShows(FieldValueRenderer::tableColumn($this->runsOn), '2026-09-18'))->toBe('Sep 18, 2026');
    });
});
