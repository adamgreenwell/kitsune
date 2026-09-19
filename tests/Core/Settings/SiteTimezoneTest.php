<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
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
use Kitsune\Core\Models\EntryRevision;
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

    $field = fn (string $handle, string $type, int $cardinality = 1): FieldConfig => new FieldConfig(
        $storage = FieldStorage::create([
            'org_id' => $this->org->id, 'handle' => $handle, 'type' => $type, 'pii_class' => 'none',
            'cardinality' => $cardinality,
        ]),
        Field::create(['entry_type_id' => $this->type->id, 'field_storage_id' => $storage->id, 'label' => ucfirst($handle)]),
    );

    $this->startsAt = $field('starts_at', 'datetime');
    $this->runsOn = $field('runs_on', 'date');
    $this->sessions = $field('sessions', 'datetime', -1);
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

/** An entry's `values` exactly as stored, decoded. */
function storedValuesOf(Entry $entry): array
{
    return json_decode((string) DB::table('entries')->where('id', $entry->id)->value('values'), true);
}

/**
 * Open an entry's form the way the edit page does — filled from the entry's own attributes — and save it untouched.
 *
 * @return array<string, mixed> what the form showed, by state path under `values`
 */
function openAndSaveUntouched(Entry $entry, FieldConfig $config): array
{
    $host = timezoneHost();
    $form = timezoneForm($host, FieldValueRenderer::formComponent($config));

    $form->fill($entry->attributesToArray());
    $shown = $host->data['values'];

    $entry->update(['values' => $form->getState()['values']]);

    return $shown;
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

    it('refuses a stored value that is not one, whatever it is, and says where it is', function (): void {
        /*
         * ⚠️ A VALUE WRITTEN PAST THE CHECK WAS HANDLED TWO WAYS. A string such as Mars/Olympus reached Carbon, which
         * threw "Unknown or bad timezone" from every list cell and picker; anything else — a number, a null — was
         * silently read as UTC, hiding the org's valid value, so authors entered instants in the wrong zone. Both now
         * fail the same way, closed, naming the level that holds the value.
         */
        $this->org->update(['settings' => ['timezone' => 'Europe/London']]);

        foreach ([5, null, '', 'Mars/Olympus'] as $bad) {
            // Below Eloquent — one of the paths the `saving` check cannot see.
            DB::table('sites')->where('id', $this->site->id)->update(['settings' => json_encode(['timezone' => $bad])]);
            app(SettingsResolver::class)->forget();

            expect(fn () => SiteTimezone::current())
                ->toThrow(RuntimeException::class, 'set on this site', 'a stored '.get_debug_type($bad).' was not refused');
        }
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

    it('survives an entry opened and saved untouched', function (): void {
        // Filled from the entry, as the edit page fills it, rather than from a hand-written state array.
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Tee time', 'values' => ['starts_at' => '2026-09-18 13:00:00']]);
        $stored = storedValuesOf($entry)['starts_at'];

        $shown = openAndSaveUntouched($entry, $this->startsAt);

        expect($shown['starts_at'])->toBe('2026-09-18 09:00:00')
            ->and(storedValuesOf($entry)['starts_at'])->toBe($stored);
    });

    it('is shown and kept in the site\'s timezone when a field holds several', function (): void {
        /*
         * ⚠️ A MULTI-VALUE FIELD MOVED FORWARD BY THE SITE'S OFFSET ON EVERY UNTOUCHED SAVE. Filament's simple
         * repeater hands each stored value to its item raw and never runs the inner picker's hydrating cast, while
         * the dehydrating one does run — and with the site's timezone on the picker, it read the stored UTC
         * `13:00` as 13:00 in New York. Measured before the fix: 13:00Z stored 17:00Z, then 21:00Z; and the item
         * held the raw ISO string, which a datetime input cannot show.
         */
        $entry = Entry::create([
            'entry_type_id' => $this->type->id, 'title' => 'Sessions',
            'values' => ['sessions' => ['2026-09-18 13:00:00', '2026-12-18 14:00:00']],
        ]);
        $stored = storedValuesOf($entry)['sessions'];

        expect($stored)->toBe(['2026-09-18T13:00:00.000000+00:00', '2026-12-18T14:00:00.000000+00:00']);

        foreach ([1, 2] as $round) {
            $shown = openAndSaveUntouched($entry->fresh(), $this->sessions);

            expect(array_values(array_column($shown['sessions'], 'value')))
                ->toBe(['2026-09-18 09:00:00', '2026-12-18 09:00:00'], "round {$round} showed")
                ->and(storedValuesOf($entry)['sessions'])->toBe($stored, "round {$round} moved the instants");
        }
    });

    it('takes a new value in a multi-value field in the site\'s timezone', function (): void {
        $host = timezoneHost();
        $form = timezoneForm($host, FieldValueRenderer::formComponent($this->sessions));

        $form->fill(['values' => ['sessions' => []]]);
        $host->data['values']['sessions'] = ['new' => ['value' => '2026-09-18 09:00:00']];

        expect($form->getState()['values']['sessions'])->toBe(['2026-09-18 13:00:00']);
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

    it('would move if a picker were handed a timezone, which is why none is', function (): void {
        /*
         * ⚠️ FILAMENT CONVERTS A DATE-ONLY PICKER THAT IS GIVEN A TIMEZONE — having a time decides only the default.
         * So a date stays put because nothing hands it the site's zone, and this is the measurement that says so.
         */
        Carbon::setTestNow(Carbon::parse('2026-09-18 02:00:00', 'UTC'));

        $host = timezoneHost();
        timezoneForm($host, DatePicker::make('runs_on')->timezone('America/New_York'))->fill(['runs_on' => '2026-09-18']);

        expect($host->data['runs_on'])->toBe('2026-09-17');
    });

    it('is not resolved through the site at all', function (): void {
        // The resolver would be asked for the timezone only by a component that converts.
        app()->offsetUnset(SettingsResolver::class);
        app()->scoped(SettingsResolver::class, fn () => throw new RuntimeException('a date asked for a timezone'));

        expect(cellShows(FieldValueRenderer::tableColumn($this->runsOn), '2026-09-18'))->toBe('Sep 18, 2026');
    });
});

describe('what the picker does not resolve yet — stated in ADR-022\'s amendment as open', function (): void {
    /*
     * ⚠️ THESE PIN DEFECTS, NOT DESIGN. The picker holds a wall-clock time with no offset, and what to do about a time
     * that names two instants or none is not decided. Each test measures what happens today so the statement in
     * `SiteTime::picker()` and the ADR stays true; the day one is fixed, its test fails and the statement goes.
     */
    it('saves a wall-clock time in the repeated hour as its first occurrence, even untouched', function (): void {
        /*
         * ⚠️ THIS PINS A KNOWN DEFECT, NOT A DESIRED BEHAVIOUR. An untouched save must not change what is stored —
         * the multi-value case above is that rule, fixed — and here it does: the second 01:30 of the night is
         * rewritten as the first, and a revision is recorded for a change nobody made. It is pinned so it is
         * seen rather than discovered, and so a fix shows up as this assertion failing ON PURPOSE. When it
         * does, change the expectation to the stored instant, do not restore the old one.
         *
         * Unreachable today: every site resolves to the default, UTC, which repeats no hour, and nothing but code
         * can set another zone. ADR-022 makes fixing this a precondition of any admin screen that can.
         */
        // 06:30 UTC on 2026-11-01 is 01:30 EST, the second 01:30 in New York that night.
        $entry = Entry::create(['entry_type_id' => $this->type->id, 'title' => 'Late', 'values' => ['starts_at' => '2026-11-01 06:30:00']]);
        $revisions = EntryRevision::query()->where('entry_id', $entry->id)->count();

        $shown = openAndSaveUntouched($entry, $this->startsAt);

        expect($shown['starts_at'])->toBe('2026-11-01 01:30:00')
            ->and(storedValuesOf($entry)['starts_at'])->toBe('2026-11-01T05:30:00.000000+00:00')
            ->and(EntryRevision::query()->where('entry_id', $entry->id)->count())->toBe($revisions + 1);
    });

    it('moves a wall-clock time in the skipped hour forward, and says nothing', function (): void {
        $host = timezoneHost();
        $form = timezoneForm($host, FieldValueRenderer::formComponent($this->startsAt));

        $form->fill(['values' => ['starts_at' => null]]);
        $host->data['values']['starts_at'] = '2026-03-08 02:30:00';
        $entered = $form->getState()['values']['starts_at'];

        $reopened = timezoneHost();
        timezoneForm($reopened, FieldValueRenderer::formComponent($this->startsAt))
            ->fill(['values' => ['starts_at' => $entered]]);

        expect($entered)->toBe('2026-03-08 07:30:00')
            ->and($reopened->data['values']['starts_at'])->toBe('2026-03-08 03:30:00');
    });

    it('reads an open form\'s untouched instant in the zone the site has when it is saved', function (): void {
        $host = timezoneHost();
        timezoneForm($host, FieldValueRenderer::formComponent($this->startsAt))
            ->fill(['values' => ['starts_at' => '2026-09-18T13:00:00.000000+00:00']]);

        // The next request: the site's zone changed while the form was open, and the form posts back what it showed.
        app(SettingsWriter::class)->set($this->site, 'timezone', 'Asia/Tokyo');
        $posted = timezoneHost();
        $posted->data = $host->data;

        expect($host->data['values']['starts_at'])->toBe('2026-09-18 09:00:00')
            ->and(timezoneForm($posted, FieldValueRenderer::formComponent($this->startsAt))->getState()['values']['starts_at'])
            ->toBe('2026-09-18 00:00:00');
    });
});
