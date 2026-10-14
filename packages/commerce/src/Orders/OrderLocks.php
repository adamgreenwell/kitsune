<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Orders;

use Closure;
use Kitsune\Commerce\Models\Order;
use Kitsune\Core\Database\TransactionRecovery;
use Kitsune\Core\Models\Module;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

/** One lock order shared with catalogue/cart writes; no external I/O belongs inside. @internal */
final class OrderLocks
{
    public function __construct(private readonly Context $context) {}

    /** @template T
     * @param  Closure(Org,Site):T  $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        $orgId = $this->context->orgId() ?? throw new OrderRefused;
        $siteId = $this->context->siteId() ?? throw new OrderRefused;
        $connection = (new Order)->getConnection();

        return TransactionRecovery::run($connection, function () use ($connection, $orgId, $siteId, $work) {
            if ($connection->getDriverName() === 'sqlite') {
                $connection->table('orgs')->where('id', $orgId)->update(['id' => $orgId]);
            }
            $org = Org::query()->useWritePdo()->whereKey($orgId)->lockForUpdate()->first();
            $site = Site::query()->useWritePdo()->whereKey($siteId)->where('org_id', $orgId)->lockForUpdate()->first();
            $receipt = Module::query()->useWritePdo()->where('handle', 'kitsune/commerce')->where('is_enabled', true)->lockForUpdate()->first();
            if ($org === null || $site === null || $receipt === null) {
                throw new OrderRefused;
            }
            $result = $work($org, $site);
            if ($this->context->orgId() !== $orgId || $this->context->siteId() !== $siteId) {
                throw new OrderRefused;
            }

            return $result;
        });
    }
}
