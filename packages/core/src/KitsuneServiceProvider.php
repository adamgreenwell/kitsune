<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core;

use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Kitsune\Core\Auth\EntryPolicy;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Console\AuditPatternsCommand;
use Kitsune\Core\Console\BenchmarkAdminCommand;
use Kitsune\Core\Console\BenchmarkFloorCommand;
use Kitsune\Core\Console\BenchmarkStorageCommand;
use Kitsune\Core\Console\BlueprintCommand;
use Kitsune\Core\Console\MediaIntakeSweepCommand;
use Kitsune\Core\Console\MediaPruneCommand;
use Kitsune\Core\Console\MediaTypesCommand;
use Kitsune\Core\Console\ModuleCommand;
use Kitsune\Core\Console\SchemaSyncCommand;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Filament\RichText\BlockDirectionPlugin;
use Kitsune\Core\Http\Middleware\HoldMediaStaging;
use Kitsune\Core\Media\MediaDisks;
use Kitsune\Core\Media\MediaStaging;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Modules\AdminSurface;
use Kitsune\Core\Modules\ModuleKernel;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Settings\SettingsGuard;
use Kitsune\Core\Settings\SettingsResolver;
use Kitsune\Core\Tenancy\Context;

final class KitsuneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Beneath the host's own `config/kitsune.php`, so a host overrides a key by declaring it there.
        $this->mergeConfigFrom(__DIR__.'/../config/kitsune.php', 'kitsune');

        // Core's never-served disks, for private media and the upload intake (ADR-042 decisions 4 and 4a). Here,
        // before any provider boots, because `FilesystemServiceProvider::boot()` reads each disk's `serve` flag to
        // decide which get a route, and a definition written after that read would not be the one it acted on.
        MediaDisks::define($this->app->make('config'));

        $this->app->singleton(Kitsune::class, static fn (): Kitsune => new Kitsune);

        // One context per request. Scoped rather than singleton so a queued
        // job or a console command cannot inherit a web request's org.
        $this->app->scoped(Context::class, static fn (): Context => new Context);

        /*
         * One register per request, for the reason `Context` is scoped rather than singleton. It
         * holds state ACROSS a form save's hooks by design, so a request that opens its window and
         * dies before reconciling would otherwise carry that window into the next job on a
         * long-lived worker — which is the unbounded-map finding one step further on.
         */
        $this->app->scoped(RecordedRevisions::class, static fn (): RecordedRevisions => new RecordedRevisions);

        /*
         * Settings resolution (ADR-022), scoped for the reason `Context` is: its memo is per request, and a
         * long-lived worker must not carry one request's resolved settings into the next job.
         *
         * ⚠️ THE DEFAULTS ARE CHECKED HERE, by the rules a stored override meets, because configuration is the one
         * source of a resolvable value that no model-layer path could check: a stored override passes the model's
         * `saving` hook unless it was written past Eloquent's guards (`HoldsSettings` names those paths). A host
         * that configures `Mars/Olympus` is refused when the resolver is first built, with a message naming the
         * key, rather than resolved as though it were a timezone.
         */
        $this->app->scoped(SettingsResolver::class, static function (): SettingsResolver {
            $defaults = config('kitsune.settings');

            SettingsGuard::check($defaults, 'the configured defaults (config/kitsune.php)');

            return new SettingsResolver(is_array($defaults) ? $defaults : []);
        });

        // One registry per application. Modules register their own types
        // against it during boot, which is the extension point ADR-001
        // promises developers.
        $this->app->singleton(FieldTypeRegistry::class, static fn (): FieldTypeRegistry => new FieldTypeRegistry);

        /*
         * What enabled modules add to the admin — ADR-038's `@internal` seam. A singleton because the module
         * that fills it and the panel that reads it must be looking at the same object, and bound in
         * `register()` so it exists before the kernel registers anything in `booted()`.
         */
        $this->app->singleton(AdminSurface::class, static fn (): AdminSurface => new AdminSurface);

        /*
         * Where blueprint definitions are collected — ADR-039's `@internal` seam, and the same shape as the
         * one above for the same reason: whoever registers a definition and the command that applies it must
         * be looking at the same object, and it has to exist before the kernel registers a module in
         * `booted()`, because `registerModule()` is where a module's blueprints are declared.
         */
        $this->app->singleton(BlueprintRegistry::class, static fn (): BlueprintRegistry => new BlueprintRegistry);

        /*
         * ⚠️ THE APPLICATION'S DEFAULT LOCALE, CAPTURED BEFORE ANYTHING CAN MOVE IT.
         *
         * `Application::setLocale()` does `config->set('app.locale', ...)`, so `config('app.locale')`
         * is RUNTIME STATE rather than a default — a site request that sets Arabic overwrites it
         * for the process. Under Octane or any long-lived worker, the next site-less request then
         * "fell back to the application default" and got Arabic, because the default it read had
         * already been replaced. Found by review; measured.
         *
         * Bound in `register()`, which runs once per worker before any request is handled, so this
         * holds the value the operator configured. `LocaleResolver` falls back to this rather than
         * to live config.
         */
        /*
         * ⚠️ `instance()`, NOT `singleton()`. A singleton registers a LAZY factory, so the value
         * would be captured on first resolution — which in a worker is during the first request
         * that needs a fallback, by which time `setLocale()` has already overwritten
         * `config('app.locale')`. Measured: with a singleton the leak persisted unchanged, because
         * the "default" was read after the pollution rather than before it.
         *
         * `instance()` evaluates now, in `register()`, which runs once per worker before any
         * request is handled.
         */
        $this->app->instance('kitsune.default_locale', (string) config('app.locale', 'en'));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // `__('kitsune::media.…')` — core's own strings, which begin with the media admin (ADR-042).
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'kitsune');

        /*
         * ⚠️ A ROLLBACK UNDOES THE GRANT AND NOT THE MEMO, which review found. `Role::grant()` flushes the
         * permission memo when its own transaction commits — but inside a CALLER's transaction that commit
         * is a savepoint release, and the outer transaction can still roll back. A check made in between
         * memoises the uncommitted grant, nothing flushes it again, and code that catches the rollback and
         * carries on in the same request keeps authorising against a grant that no longer exists.
         *
         * Laravel announces the rollback, so the memo is dropped when it happens. This is cheaper than
         * refusing to memoise inside a transaction — that would cost a read per check on every write path,
         * for a window that only opens when somebody rolls back and then continues.
         */
        Event::listen(TransactionRolledBack::class, static function (): void {
            Permissions::forget();

            /*
             * ⚠️ AND THE RESOLVED SETTINGS, for the same reason. A settings write inside a transaction drops the
             * memo when it saves, a lookup before the rollback memoises the uncommitted value, and nothing drops
             * it again — so the rest of the request resolved a setting that no longer exists. Every resolver
             * alive, and none built: see `SettingsResolver::forgetEverywhere()`.
             */
            SettingsResolver::forgetEverywhere();
        });

        /*
         * The upload endpoint's rule — ADR-042 decision 4. By NAME, because `deploy/release.sh` runs `config:cache` and
         * a closure or rule object in Livewire's config fails the deploy; registered when the validator is first
         * built rather than resolving it here, and again by the gate on the endpoint (`MediaStaging::extend()` says
         * why). For every request, because the endpoint serves the whole installation.
         */
        $this->callAfterResolving('validator', static function (ValidationFactory $validator): void {
            MediaStaging::extend($validator);
        });

        /*
         * Livewire's staging keys, and the refusal of a core disk redefined after core or overlapped by a host's: once
         * every provider has booted, so a deploy stops on a misconfiguration, and again at the start of every request,
         * which a later `booted()` callback cannot outlast — `MediaStaging::enforce()` says why both.
         */
        $this->app->booted(function (): void {
            MediaStaging::enforce($this->app->make('config'));
        });

        $this->callAfterResolving(HttpKernel::class, static function (HttpKernel $kernel): void {
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(HoldMediaStaging::class);
            }
        });

        if ($this->app->runningInConsole()) {
            /*
             * For an installation that runs a scheduler; none needs one, because the same sweep follows every
             * accepted upload. Hourly bounds a staged file's life at a day and an hour. No lock: the sweep deletes
             * by age and two running at once remove the same files.
             */
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('kitsune:media-intake-sweep --force')->hourly();
            });

            $this->commands([
                AuditPatternsCommand::class,
                BlueprintCommand::class,
                MediaIntakeSweepCommand::class,
                MediaPruneCommand::class,
                MediaTypesCommand::class,
                BenchmarkStorageCommand::class,
                BenchmarkFloorCommand::class,
                BenchmarkAdminCommand::class,
                ModuleCommand::class,
                SchemaSyncCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'kitsune-migrations');

        /*
         * Per-type authorization — ADR-033, and Phase 4's last unchecked line.
         *
         * ⚠️ A POLICY AND DELIBERATELY NOT A `Gate::before` HOOK, which is a correction to ADR-033's own
         * first draft. A before-hook granting everything to an org owner reaches EVERY ability in the
         * application, including policies the host application wrote for its own models — so core would be
         * deciding that an org owner may do anything in somebody else's code. The bypass belongs inside
         * `Permissions::allows()`, where its blast radius is the permissions Kitsune defines.
         */
        Gate::policy(Entry::class, EntryPolicy::class);

        /*
         * ⚠️ REGISTERED FOR EVERY REQUEST, NOT ONLY THE ADMIN'S, because `FilamentAsset` is a registry
         * rather than a renderer: `filament:assets` publishes what is registered at that moment, so an
         * asset registered behind a panel check is an asset the publish command cannot see. It costs an
         * array entry per worker.
         *
         * The editor drops any attribute its document model does not declare, so this script is what
         * keeps a block's `dir` alive while it is being edited — issue #67, and `BlockDirectionPlugin`
         * carries the reasoning.
         */
        FilamentAsset::register([
            /*
             * ⚠️ `loadedOnRequest()`, AND THE FIRST VERSION WITHOUT IT THREW IN THE BROWSER. Filament's
             * editor loads an extension by `import(url)` — the URL comes from the plugin below — so this
             * file is an ES MODULE. Registered as an ordinary script it is ALSO injected into the page as
             * a classic `<script src>`, and the browser then reads `export default` as a syntax error:
             * measured as `Unexpected token 'export'` twice per page load, with the extension silently
             * not applied. Marked loaded-on-request, the asset is published and addressable and nothing
             * injects it, which is how Filament ships its own dynamically imported components.
             */
            Js::make(BlockDirectionPlugin::ASSET, __DIR__.'/../resources/js/rich-editor-direction.js')
                ->loadedOnRequest(),
        ], BlockDirectionPlugin::PACKAGE);

        /*
         * ⚠️ IN `boot()` RATHER THAN `register()`. ADR-038: core touches no database in `register()`, and a
         * registry read there turns a transient connection failure into an admin that has quietly lost every
         * module's features.
         *
         * ⚠️ AND INSIDE `booted()` RATHER THAN INLINE, WHICH IS NOT A STYLE CHOICE. `Application::boot()` walks
         * its provider list with `array_walk`, so whether a provider APPENDED during that walk is itself booted
         * depends on how the walk behaves while the array grows underneath it — and AGENTS.md §15 exists for
         * exactly this kind of question. Rather than measure a framework internal and then depend on the answer,
         * the registration is moved to where the behaviour is documented and unambiguous: `booted()` fires after
         * the walk, `$this->booted` is true by then, and `Application::register()` boots a provider immediately
         * when it is. A module is therefore registered AND booted, on a path that does not rest on an
         * undocumented ordering. It also means a module's own bindings land with every core binding in place.
         */
        $this->app->booted(function (): void {
            ModuleKernel::boot($this->app);
        });
    }
}
