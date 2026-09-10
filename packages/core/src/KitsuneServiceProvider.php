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
