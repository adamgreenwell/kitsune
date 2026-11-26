<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tests\Fixtures;

use Illuminate\Database\Connection;
use Kitsune\Core\Models\Module;
use LogicException;
use RuntimeException;

/** Owns only the installation receipt this fixture successfully inserts. */
final class CommittedModuleReceipt
{
    /** @var array<string, int|string>|null */
    private ?array $identity = null;

    public function __construct(private readonly Connection $connection) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Module
    {
        if ($this->identity !== null) {
            throw new LogicException('The fixture already owns a module receipt.');
        }

        $module = Module::on($this->connection->getName())->create($attributes);
        $this->identity = [
            'id' => $module->id,
            'handle' => $module->handle,
            'version' => $module->version,
            'installed_at' => $module->getRawOriginal('installed_at'),
        ];

        return $module;
    }

    public function remove(): void
    {
        if ($this->identity === null) {
            return;
        }

        if ($this->connection->table('modules')->where($this->identity)->delete() === 0
            && $this->connection->table('modules')->useWritePdo()->where('id', $this->identity['id'])->exists()) {
            throw new RuntimeException('The committed module receipt changed identity before fixture cleanup.');
        }

        $this->identity = null;
    }
}
