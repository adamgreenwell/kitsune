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
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Kitsune\Core\Auth\EntryPolicy;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Console\AuditPatternsCommand;
use Kitsune\Core\Console\BenchmarkAdminCommand;
use Kitsune\Core\Console\BenchmarkFloorCommand;
use Kitsune\Core\Console\BenchmarkStorageCommand;
use Kitsune\Core\Console\SchemaSyncCommand;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Filament\RichText\BlockDirectionPlugin;
use Kitsune\Core\Models\Entry;
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                AuditPatternsCommand::class,
                BenchmarkStorageCommand::class,
                BenchmarkFloorCommand::class,
                BenchmarkAdminCommand::class,
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
    }
}
