<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Illuminate\Console\Command;
use Kitsune\Core\Fields\Pattern;
use Kitsune\Core\Fields\Types\TextType;
use Kitsune\Core\Models\FieldStorage;

/**
 * Report every stored `pattern` setting the published grammar would refuse.
 *
 * ⚠️ ADR-030 CALLS THIS MIGRATION MANDATORY AND IT DID NOT EXIST, which review found by looking
 * for it. The document said *"Migration is not optional. Patterns already authored were accepted
 * by the screen, not by the grammar, so any outside it must be found before this lands"* — and
 * there was no command, no migration and nothing that ran `unpublishable()` over stored rows. A
 * published requirement with no implementation is the same failure as a published rule with no
 * enforcement, which this branch has now made twice.
 *
 * ⚠️ WHAT AN UPGRADED INSTALLATION ACTUALLY SUFFERS, which is worse than "some patterns are now
 * invalid". A stored `^(a|aa)+$` keeps being published in the API schema and keeps being enforced
 * server-side, because nothing revalidates a row that is not saved. Then the first unrelated edit
 * to that field — a label, a help string — fails `guardSettingsAreUsable()`, and the author is told
 * their pattern is invalid while looking at a screen where they changed something else. The
 * refusal is correct and the moment is incomprehensible.
 *
 * ⚠️ READ-ONLY, WITH NO `--force`, and deliberately unlike `kitsune:schema-sync`. There is no
 * mechanical repair: a refused pattern has to be rewritten by whoever knows what the field is for,
 * and this cannot guess. `--strict` makes it a gate rather than a report, so a deployment can
 * refuse to proceed while any row is unpublishable.
 */
final class AuditPatternsCommand extends Command
{
    protected $signature = 'kitsune:audit-patterns
        {--strict : Exit non-zero when any stored pattern is unpublishable, for use as a deployment gate}';

    protected $description = 'Report stored field patterns the published grammar refuses (ADR-030)';

    public function handle(): int
    {
        /*
         * ⚠️ COUNTED, NOT COLLECTED, which review found. The first version appended every failing
         * `FieldStorage` MODEL to an array and printed after the walk, so memory grew with the number
         * of failures rather than with the chunk — and `chunkById()` bounds only the database batch.
         * An audit whose whole purpose is to run before an upgrade on a large installation could
         * therefore exhaust the 1 GB floor (ADR-027) before printing anything, which is the worst
         * possible moment to run out of memory: the operator learns nothing and cannot tell whether
         * the audit found nothing or died.
         *
         * Rows are emitted inside the chunk and only the count is carried.
         */
        $unpublishable = 0;
        $overLong = 0;
        $examined = 0;

        /*
         * ⚠️ NO SCOPE ESCAPE HATCH IS NEEDED HERE, and I reached for one before checking:
         * `FieldStorage` is `#[Unscoped]`, so a plain query already sees every org. Calling
         * `withoutScopeBecause()` would have been a greppable admission of a boundary crossing that
         * is not being crossed — which makes the real ones harder to audit.
         *
         * ⚠️ CHUNKED, because this reads every field in the installation and ADR-027's floor is
         * 1 vCPU and 1 GB. An audit that ran out of memory on a large installation would be useless
         * on exactly the installations that most need it.
         */
        FieldStorage::query()
            ->whereNotNull('settings')
            ->chunkById(200, function ($rows) use (&$unpublishable, &$overLong, &$examined): void {
                foreach ($rows as $storage) {
                    /*
                     * ⚠️ THE LENGTH CEILING IS AN UPGRADE HAZARD TOO, which review found: this command
                     * asked `Pattern::unpublishable()` and nothing else, so an installation carrying a
                     * text field configured above `TextType::MAX_CONFIGURABLE_LENGTH` passed the audit
                     * and then had the next save of that field refused — a field the author had not
                     * touched. That is exactly the failure the command exists to prevent, one setting
                     * along, and §4 says a pattern that saved yesterday and is refused today is a
                     * broken install rather than a fixed one.
                     *
                     * ⚠️ REPORTED SEPARATELY, because the remedies differ. An unpublishable pattern has
                     * to be rewritten by somebody who knows what the field should accept; an over-long
                     * `maxLength` is lowered, and the operator needs to know it may truncate what
                     * authors have already stored. Folding them into one count would name one remedy
                     * for two problems.
                     */
                    $maxLength = $storage->settings['maxLength'] ?? null;

                    if (is_numeric($maxLength) && (int) $maxLength > TextType::MAX_CONFIGURABLE_LENGTH) {
                        $overLong++;

                        $this->line("  <comment>field_storage #{$storage->getKey()}</comment> <info>{$storage->handle}</info> (org {$storage->org_id})");
                        $this->line('    maxLength: '.(int) $maxLength.' — the limit is '.TextType::MAX_CONFIGURABLE_LENGTH);
                        $this->newLine();
                    }

                    $pattern = $storage->settings['pattern'] ?? null;

                    if (! is_string($pattern) || $pattern === '') {
                        continue;
                    }

                    $examined++;

                    if (($reason = Pattern::unpublishable($pattern)) === null) {
                        continue;
                    }

                    $unpublishable++;

                    $this->line("  <comment>field_storage #{$storage->getKey()}</comment> <info>{$storage->handle}</info> (org {$storage->org_id})");
                    $this->line("    pattern: {$pattern}");
                    $this->line("    refused: {$reason}");
                    $this->newLine();
                }
            });

        /*
         * ⚠️ THE SUMMARY COMES LAST, AFTER the rows, which is a consequence of streaming them. An
         * operator reading a long report wants the count at the end rather than a header they have
         * scrolled past — and a report that dies halfway has still printed everything it found.
         */
        $this->line("examined <info>{$examined}</info> stored pattern".($examined === 1 ? '' : 's'));

        if ($unpublishable === 0 && $overLong === 0) {
            $this->info('Every stored pattern satisfies the published grammar, and every configured length is within its limit.');

            return self::SUCCESS;
        }

        if ($unpublishable > 0) {
            $this->warn("{$unpublishable} stored pattern".($unpublishable === 1 ? ' is' : 's are').' unpublishable.');
            $this->line('Each must be rewritten before the next save of its field, which will otherwise');
            $this->line('be refused for a pattern the author did not touch. There is no automatic repair:');
            $this->line('a pattern says what a field accepts, and only its owner knows what that should be.');
        }

        if ($overLong > 0) {
            $this->warn("{$overLong} field".($overLong === 1 ? ' is' : 's are').' configured longer than the limit allows.');
            $this->line('Each must be lowered before the next save of its field, which will otherwise be');
            $this->line('refused for a setting the author did not touch. Lowering it may truncate values');
            $this->line('authors have already stored, so check the longest before you choose the number.');
        }

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
