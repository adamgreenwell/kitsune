<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/**
 * List, apply and report on blueprints — ADR-039.
 *
 * ⚠️ ONE COMMAND, NOT THREE, for the reason `kitsune:module` gives: the split in this repo is by blast radius
 * rather than by verb, and three names is three things frozen at v1.2 for one subject. The work lives in
 * `BlueprintApplier`, so it is testable without a console and this class is argument handling.
 *
 * ⚠️ `--org` IS REQUIRED FOR `apply`, AND THAT IS THIS SLICE'S HONEST LIMIT. ADR-039's *done when* is one
 * command on a fresh install, which means this command eventually creates the first org and site when none
 * exists — the thing that makes ADR-030's "no manual step outside the apply flow" satisfiable. It does not do
 * that yet. Until it does, an operator names an org that already exists, and a fresh install is not yet a
 * single command. Said here rather than left to be discovered.
 */
final class BlueprintCommand extends Command
{
    private const ACTIONS = ['list', 'status', 'apply'];

    protected $signature = 'kitsune:blueprint {action=list : list, status or apply} {handle? : the blueprint, e.g. blog} {--org= : the org slug to apply into}';

    protected $description = 'List, apply and report on Kitsune blueprints (ADR-039)';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, self::ACTIONS, true)) {
            $this->error("`{$action}` is not a blueprint action. Use: ".implode(', ', self::ACTIONS).'.');

            return self::FAILURE;
        }

        return match ($action) {
            'list' => $this->list(),
            'status' => $this->status(),
            default => $this->apply(),
        };
    }

    /** What this installation knows about, which is what some PHP has registered — never a remote index. */
    private function list(): int
    {
        $rows = [];

        foreach (app(BlueprintRegistry::class)->all() as $handle => $definition) {
            $rows[] = [$handle, $definition->version(), $definition::class];
        }

        if ($rows === []) {
            $this->info('No blueprints are registered.');

            return self::SUCCESS;
        }

        $this->table(['Handle', 'Version', 'Defined by'], $rows);

        return self::SUCCESS;
    }

    /** What each org has had applied, including an apply that started and did not finish. */
    private function status(): int
    {
        $rows = [];

        /*
         * Past the org scope on purpose: this is an operator's question about the whole installation, asked
         * from a console with no org in context, where a scoped read returns nothing whatever is in the table.
         */
        $receipts = Blueprint::query()->withoutGlobalScopes()->orderBy('org_id')->orderBy('handle')->get();

        foreach ($receipts as $receipt) {
            $rows[] = [
                (string) $receipt->org_id,
                (string) $receipt->handle,
                (string) $receipt->version,
                $receipt->applied_at?->toDateTimeString() ?? 'INTERRUPTED — started, did not finish',
            ];
        }

        if ($rows === []) {
            $this->info('No blueprint has been applied in any organisation.');

            return self::SUCCESS;
        }

        $this->table(['Org', 'Handle', 'Version', 'Applied'], $rows);

        return self::SUCCESS;
    }

    private function apply(): int
    {
        $handle = $this->argument('handle');

        if (! is_string($handle) || $handle === '') {
            $this->error('`kitsune:blueprint apply` needs a blueprint, e.g. `kitsune:blueprint apply blog --org=acme`.');

            return self::FAILURE;
        }

        $definition = app(BlueprintRegistry::class)->get($handle);

        if ($definition === null) {
            $this->error("No blueprint is registered under [{$handle}]. `kitsune:blueprint list` shows what is.");

            return self::FAILURE;
        }

        $slug = $this->option('org');

        if (! is_string($slug) || $slug === '') {
            $this->error('`kitsune:blueprint apply` needs an organisation, e.g. `--org=acme`. A blueprint is applied into one (ADR-039).');

            return self::FAILURE;
        }

        $org = Org::query()->where('slug', $slug)->first();

        if ($org === null) {
            $this->error("No organisation has the slug [{$slug}].");

            return self::FAILURE;
        }

        $context = app(Context::class);

        try {
            $context->setOrg($org);

            $result = BlueprintApplier::apply($definition);
        } catch (Throwable $e) {
            /*
             * The reason, not a bare failure. An apply that stops part-way leaves a receipt with no
             * `applied_at`, and the message is the only thing that tells an operator which step it was.
             */
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            /* Given back however this ends, and cleared rather than left pointing at the applied org. */
            $context->forget();
        }

        foreach (['created', 'adopted', 'skipped'] as $kind) {
            foreach ($result[$kind] as $what) {
                $this->line(sprintf('  %-8s %s', $kind, $what));
            }
        }

        $this->info(sprintf(
            'Applied %s %s into %s. %d indexed.',
            $result['handle'],
            $result['version'],
            $slug,
            $result['indexed'],
        ));

        return self::SUCCESS;
    }
}
