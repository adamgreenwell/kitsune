<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use Kitsune\PluginSdk\Testing\ScopeFixture;
use PHPUnit\Framework\AssertionFailedError;

it('runs independent Site and Org isolation probes and restores the outer scope', function (): void {
    $context = app(Context::class);
    $outer = ScopeFixture::create();
    $context->setSite($outer->foreign);
    $fixture = ScopeFixture::create();

    expect($fixture->site->org_id)->toBe($fixture->sibling->org_id)
        ->and($fixture->foreign->org_id)->not->toBe($fixture->site->org_id)
        ->and($context->site())->toBe($outer->foreign);

    $entry = $fixture->inSite($fixture->site, function (): Entry {
        $type = EntryType::create(['handle' => 'training', 'name' => 'Training', 'plural_name' => 'Trainings']);

        return Entry::create(['entry_type_id' => $type->id, 'title' => 'Test note']);
    });

    $fixture->assertSiteIsolation(fn (): bool => Entry::whereKey($entry->id)->exists());
    $fixture->assertOrgIsolation(fn (): bool => Site::whereKey($fixture->site->id)->exists());
    expect($context->site())->toBe($outer->foreign);
});

it('fails when the same probe observes a sibling Site or another Org', function (string $boundary): void {
    $fixture = ScopeFixture::create();
    $leaking = $fixture->{$boundary};
    $context = app(Context::class);
    $context->setOrg($fixture->site->org);

    expect(fn () => $fixture->assertSiteIsolation(
        fn (): bool => in_array($context->siteId(), [$fixture->site->id, $leaking->id], true),
    ))->toThrow(AssertionFailedError::class, $boundary === 'sibling' ? 'A sibling Site' : 'Another Org')
        ->and($context->site())->toBeNull()
        ->and($context->orgId())->toBe($fixture->site->org_id);
})->with(['sibling', 'foreign']);

it('preserves nested scope and propagates probe errors', function (): void {
    $fixture = ScopeFixture::create();
    $context = app(Context::class);

    $fixture->inSite($fixture->site, function () use ($fixture, $context): void {
        expect(fn () => $fixture->inSite($fixture->foreign, function (): never {
            throw new RuntimeException('Probe error');
        }))->toThrow(RuntimeException::class, 'Probe error')
            ->and($context->site())->toBe($fixture->site);

        expect(fn () => $fixture->assertSiteIsolation(function (): never {
            throw new RuntimeException('Read error');
        }))->toThrow(RuntimeException::class, 'Read error')
            ->and($context->site())->toBe($fixture->site);
    });
    expect($context->site())->toBeNull()->and($context->org())->toBeNull();
});
