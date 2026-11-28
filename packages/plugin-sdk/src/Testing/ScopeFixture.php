<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\PluginSdk\Testing;

use Closure;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use PHPUnit\Framework\Assert;

final readonly class ScopeFixture
{
    private function __construct(public Site $site, public Site $sibling, public Site $foreign) {}

    public static function create(): self
    {
        return self::withContext(null, null, static function (): self {
            $id = bin2hex(random_bytes(8));
            $org = Org::create(['name' => 'Fixture Org', 'slug' => 'fixture-'.$id]);
            $foreignOrg = Org::create(['name' => 'Foreign Fixture Org', 'slug' => 'foreign-'.$id]);
            $context = app(Context::class);
            $context->setOrg($org);
            $site = self::createSite('local-'.$id);
            $sibling = self::createSite('sibling-'.$id);
            $context->setOrg($foreignOrg);

            return new self($site, $sibling, self::createSite('other-'.$id));
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function inSite(Site $site, Closure $work): mixed
    {
        return self::withContext($site, $site->org, $work);
    }

    /** @param Closure(): bool $visible A fresh query or request made under the active scope. */
    public function assertSiteIsolation(Closure $visible): void
    {
        Assert::assertTrue($this->inSite($this->site, $visible), 'The owning Site must see the record.');
        Assert::assertFalse($this->inSite($this->sibling, $visible), 'A sibling Site must be refused.');
        Assert::assertFalse($this->inSite($this->foreign, $visible), 'Another Org must be refused.');
        Assert::assertFalse(self::withContext(null, $this->site->org, $visible), 'An Org context alone must be refused.');
        Assert::assertFalse(self::withContext(null, null, $visible), 'Missing scope must be refused.');
    }

    /** @param Closure(): bool $visible A fresh query or request made under the active scope. */
    public function assertOrgIsolation(Closure $visible): void
    {
        Assert::assertTrue($this->inSite($this->site, $visible), 'The owning Org must see the record.');
        Assert::assertTrue($this->inSite($this->sibling, $visible), 'Sites in the owning Org must see the record.');
        Assert::assertTrue(self::withContext(null, $this->site->org, $visible), 'The owning Org may read without a Site.');
        Assert::assertFalse($this->inSite($this->foreign, $visible), 'Another Org must be refused.');
        Assert::assertFalse(self::withContext(null, null, $visible), 'Missing scope must be refused.');
    }

    private static function createSite(string $slug): Site
    {
        return Site::create(['name' => $slug, 'handle' => $slug, 'slug' => $slug, 'locale' => 'en']);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private static function withContext(?Site $site, ?Org $org, Closure $work): mixed
    {
        $context = app(Context::class);
        $previousOrg = $context->org();
        $previousSite = $context->site();
        try {
            $context->setSite($site)->setOrg($org);

            return $work();
        } finally {
            $context->setSite($previousSite)->setOrg($previousOrg);
        }
    }
}
