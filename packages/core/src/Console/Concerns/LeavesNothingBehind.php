<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console\Concerns;

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What a benchmark changes for the length of one run, and gives back when the run ends — for all three
 * benchmarks, which each had their own copy of the removal and got it wrong in different ways.
 *
 * ⚠️ ITS ROWS BY A TOKEN THE RUN GENERATED, NOT BY A PATTERN OR AN ID RANGE. A bare slug prefix is a guess about
 * somebody else's data: review found cleanup matching one force-deleting a customer's own entry on a run that
 * inserted nothing, and deleting the corpus an earlier run had kept with `--keep`. The id mark that replaced it
 * was ownership only while nothing else wrote — two runs overlapping on one site both insert above the mark the
 * first took, so whichever cleaned up first removed the other's rows with its own. Every slug a run inserts now
 * carries a token nobody else holds, and removal names that token and nothing wider.
 *
 * ⚠️ OUT THE WAY THEY WENT IN. The rows are inserted through the query builder, beneath the audit trail,
 * because a benchmark is not an edit. Removing them through `Entry` did not mirror that: `AuditedBuilder` records
 * a force-delete for every key, so a run in an org it did not create — the admin benchmark always borrows a
 * real site — left one `entry.force_deleted` row per benchmark entry in that org's audit log, up to a hundred
 * thousand, for content no one ever wrote.
 *
 * ⚠️ AND THE TENANCY CONTEXT IT FOUND. Each fixture establishes the benchmark's org and site on the application's
 * one `Context`, and nothing gave it back: review found a caller running more than one command in an application
 * left scoped to the benchmark's org — one a failed fixture had rolled back, or cleanup had just removed.
 */
trait LeavesNothingBehind
{
    /** This invocation's token, or null outside an invocation. */
    private ?string $runToken = null;

    /**
     * ⚠️ PER INVOCATION, HERE RATHER THAN IN EACH `handle()`. Artisan resolves a command once and keeps the
     * object, so state on it outlives the run that set it: review found a `--keep` run's mark surviving into the
     * next run, whose volume was already met, and that run removing the kept rows. A fresh token for every
     * invocation, and the context given back however the invocation ends, sit beside the state they protect —
     * so no command using this trait can leave either out.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = app(Context::class);

        // The site too, because `setOrg()` clears a site that belongs to another org.
        $restoreOrg = $context->org();
        $restoreSite = $context->site();

        $this->runToken = bin2hex(random_bytes(8));

        try {
            return parent::execute($input, $output);
        } finally {
            $this->runToken = null;

            if ($restoreSite !== null) {
                $context->setSite($restoreSite);
            } else {
                $context->setOrg($restoreOrg);
            }
        }
    }

    /** The prefix every slug this run inserts starts with: the command's own prefix, then this run's token. */
    private function runPrefix(string $prefix): string
    {
        if ($this->runToken === null) {
            throw new LogicException('A benchmark run token exists only while the command is executing.');
        }

        return $prefix.$this->runToken.'-';
    }

    /** Remove this run's rows: in this site, and carrying this run's token. A run that inserted none removes none. */
    private function removeInserted(Site $site, string $prefix): void
    {
        DB::table('entries')
            ->where('site_id', $site->getKey())
            ->where('slug', 'like', $this->runPrefix($prefix).'%')
            ->delete();
    }

    /**
     * Force-delete an org this run created — unless another run has joined it, in which case it stays.
     *
     * ⚠️ CREATING THE ORG DID NOT MAKE IT THIS RUN'S ALONE — review found the whole-org delete going around the
     * per-run tokens. A second run started while this one was going finds the org through `firstOrCreate()` and
     * inserts its own rows under it, and a force-delete cascades through every one of them. So the org goes only
     * once this run's own rows are gone and nothing else is left in it, decided under a lock on the org row: a run
     * that joins after the decision blocks on that lock for its first insert's foreign key, and then fails on the
     * missing org rather than losing rows it already had.
     *
     * A fixture two overlapping runs shared therefore outlives both. That is residue, and it is the only
     * alternative to deleting rows a running benchmark is still using.
     */
    private function removeCreatedOrgUnlessJoined(Org $org): void
    {
        DB::transaction(static function () use ($org): void {
            Org::query()->withoutGlobalScopes()->whereKey($org->getKey())->lockForUpdate()->value('id');

            if (DB::table('entries')->where('org_id', $org->getKey())->exists()) {
                return;
            }

            $org->forceDelete();
        });
    }
}
