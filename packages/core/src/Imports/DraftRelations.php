<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use Closure;
use DomainException;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Http\Api\EntryFieldContract;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;

/**
 * Check relation fields and source target types before doing any writes.
 *
 * @internal
 *
 * @phpstan-import-type LinkTargets from ImportPlan
 */
final class DraftRelations
{
    public static function identity(string $type, string $id): string
    {
        return $type.':'.hash('sha256', $id);
    }

    /**
     * @param  LinkTargets  $links
     * @param  Closure(string,string): ?string  $targetType
     * @return array<string, FieldStorage>
     */
    public static function validate(EntryType $type, array $links, bool $creating, Closure $targetType): array
    {
        $definitions = EntryFieldContract::forType($type)->definitions();
        $fields = [];
        foreach ($definitions as $handle => $config) {
            if ($config->storage->strategy() !== StorageStrategy::Relational) {
                continue;
            }

            if ($config->isRequired() && ($creating || array_key_exists($handle, $links)) && ($links[$handle] ?? []) === []) {
                throw new DomainException('required-relation-missing');
            }

            $fields[$handle] = $config->storage;
        }

        foreach ($links as $handle => $targets) {
            $storage = $fields[$handle] ?? throw new DomainException('unavailable-relation-field');
            if ($storage->cardinality > 0 && count($targets) > $storage->cardinality) {
                throw new DomainException('relation-cardinality');
            }

            $allowed = (array) ($storage->settings['targetTypes'] ?? []);
            foreach ($targets as $ref) {
                $kind = $targetType($ref['source_type'], $ref['source_id']);
                if ($kind === null || ($allowed !== [] && ! in_array($kind, $allowed, true))) {
                    throw new DomainException('unavailable-relation-target');
                }
            }
        }

        return $fields;
    }
}
