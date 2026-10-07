<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Auth\ReaderGuardFault;
use Kitsune\Core\Entitlements\EntitlementRecords;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;

/**
 * Whether entitlements can work here, and one reader's export and erasure — ADR-040, and ADR-020's primitives (Adam,
 * 2026-10-06: ship export and forget now).
 *
 * ⚠️ `status` NAMES NOTHING. Its output lands in deploy logs, as `kitsune:credentials status`'s does: the guard's
 * state and counts, never an org, a reader, an entitlement or a source. It exits 1 while a guard is declared but
 * unusable, because the check itself can only answer no, and this is where a misconfiguration becomes loud.
 *
 * ⚠️ NO GRANT AND NO COMP. Access is given by producers and, by hand, by an owner on the admin page. Nobody is signed in
 * here, so `export` and `forget` act as the system; no window is keyed on `runningInConsole()`.
 *
 * ⚠️ THE READER'S IDENTIFIER IS NEVER ECHOED, and the export carries no `reader_id`: the operator already has it.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class EntitlementsCommand extends Command
{
    protected $signature = 'kitsune:entitlements
        {action : status, export or forget}
        {--org= : The organisation\'s slug, for export and forget}
        {--reader= : The reader\'s identifier, for export and forget}
        {--force : Confirm forget, which deletes}';

    protected $description = 'Report whether entitlements can work here, or export or erase one reader\'s (ADR-040)';

    public function handle(ReaderGuard $readers, Context $context): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status($readers),
            'export' => $this->forReader($context, false),
            'forget' => $this->forReader($context, true),
            default => $this->refuse('Refusing: kitsune:entitlements has three actions: status, export and forget.'),
        };
    }

    private function status(ReaderGuard $readers): int
    {
        $fault = $readers->fault();

        $this->line(match ($fault) {
            null => sprintf('Reader guard: declared and usable [%s].', $readers->name()),
            ReaderGuardFault::NotDeclared => 'Reader guard: none declared (kitsune.readers.guard) — every entitlement check answers no.',
            default => sprintf('Reader guard: declared and NOT usable — %s.', $readers->faultSentence()),
        });

        [$grants, $sites, $live, $revoked] = Schema::hasTable('entitlements') ? self::counts() : [0, 0, 0, 0];

        $this->line(sprintf(
            'Entitlements: %d grant%s on %d site%s — %d live, %d lapsed, %d revoked.',
            $grants,
            $grants === 1 ? '' : 's',
            $sites,
            $sites === 1 ? '' : 's',
            $live,
            $grants - $live - $revoked,
            $revoked,
        ));

        return $fault !== null && $fault !== ReaderGuardFault::NotDeclared ? self::FAILURE : self::SUCCESS;
    }

    /** One reader's rows, printed as JSON or erased, in the org the slug names — a soft-deleted one included. */
    private function forReader(Context $context, bool $forget): int
    {
        $slug = $this->option('org');
        $reader = $this->option('reader');
        $act = $forget ? 'forget' : 'export';

        if (! is_string($slug) || $slug === '' || ! is_string($reader) || $reader === '') {
            return $this->refuse("Refusing: {$act} needs --org=<slug> and --reader=<id>.");
        }

        if ($forget && ! $this->option('force')) {
            return $this->refuse('Refusing: forget deletes a reader\'s entitlements, from every source, on every site of the organisation. Run it again with --force.');
        }

        // ⚠️ WITH THE TRASHED: a soft-deleted org keeps its rows for its restore, so its readers' requests stay answerable.
        $org = Org::withTrashed()->where('slug', $slug)->first();

        if ($org === null) {
            // A slug is configuration, not a person, so it is named.
            return $this->refuse("Refusing: no organisation has the slug [{$slug}]. Nothing was read or written.");
        }

        try {
            $context->forget()->setOrg($org);

            if ($forget) {
                $erased = app(EntitlementWriter::class)->forget($reader);
                $this->line(sprintf('Erased %d entitlement grant%s.', $erased, $erased === 1 ? '' : 's'));
            } else {
                $this->line((string) json_encode(EntitlementRecords::forReader($reader), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            }
        } catch (EntitlementRefused $refused) {
            return $this->refuse($refused->getMessage());
        } finally {
            $context->forget();
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} rows, sites holding any, live rows, revoked rows */
    private static function counts(): array
    {
        $now = CarbonImmutable::now('UTC')->startOfSecond();

        return Entitlement::withoutScopeBecause(
            'the installation-wide count of entitlements, which names no organisation, reader or source',
            static fn ($query): array => [
                (clone $query)->count(),
                (clone $query)->toBase()->distinct()->count('site_id'),
                (clone $query)->liveAt($now)->count(),
                (clone $query)->whereNotNull('revoked_at')->count(),
            ],
        );
    }
}
