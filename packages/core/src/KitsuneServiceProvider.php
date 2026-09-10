<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core;

use Illuminate\Support\ServiceProvider;
use Kitsune\Core\Console\BenchmarkFloorCommand;
use Kitsune\Core\Console\BenchmarkStorageCommand;
use Kitsune\Core\Console\SchemaSyncCommand;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Schema\RecordedRevisions;
use Kitsune\Core\Tenancy\Context;

final class KitsuneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

        if ($this->app->runningInConsole()) {
            $this->commands([
                BenchmarkStorageCommand::class,
                BenchmarkFloorCommand::class,
                SchemaSyncCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'kitsune-migrations');
    }
}
