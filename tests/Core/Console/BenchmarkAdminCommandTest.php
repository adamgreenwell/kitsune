<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Console\BenchmarkAdminCommand;

/*
 * ⚠️ THE COMMAND ITSELF IS NOT EXERCISED HERE, AND THAT IS A LIMIT RATHER THAN A CHOICE. It issues real
 * requests to a Filament panel, and the package test harness stands up no panel — which is ADR-024's
 * division of labour: this layer cannot see anything that only exists once a page renders. What IS here is
 * the piece with logic worth guarding, and it is here because that logic was wrong first.
 *
 * The first version calculated the deep page as `seeded / 25`. Both halves were wrong — the list is scoped
 * to one entry type, so the corpus is smaller than the seed, and Filament's table pages at TEN. It
 * measured page 8 of 20, labelled it "last page", and reported the number without hesitating.
 */

it('reads the page size and the corpus out of the table summary', function (): void {
    // The line Filament renders, reduced to what survives strip_tags.
    $summary = BenchmarkAdminCommand::paginationSummary(
        '<div>Showing 1 to 10 of 99,998 results</div><nav>5 10 25 50</nav>',
    );

    expect($summary)->toBe([10, 99998]);
});

it('reads a page that is not the first', function (): void {
    // Page size comes from the range rather than from a constant, so a deep page reports it too.
    expect(BenchmarkAdminCommand::paginationSummary('Showing 51 to 75 of 400 results'))->toBe([25, 400]);
});

it('reports nothing rather than a guess when the summary is absent', function (): void {
    /*
     * ⚠️ THE POINT OF THE NULL. A failed parse omits the deep-page row and says so; a parser that fell
     * back to a default page size would put the measurement at the wrong depth and print it in the same
     * table as the ones that are right, which is how a number becomes a false claim.
     */
    expect(BenchmarkAdminCommand::paginationSummary('<p>No entries yet.</p>'))->toBeNull()
        ->and(BenchmarkAdminCommand::paginationSummary(''))->toBeNull()
        ->and(BenchmarkAdminCommand::paginationSummary('Showing some of many results'))->toBeNull();
});

it('reports nothing when the range is impossible', function (): void {
    // `to` before `from` yields a page size of zero or less, which would make the last page infinite.
    expect(BenchmarkAdminCommand::paginationSummary('Showing 10 to 1 of 400 results'))->toBeNull();
});
