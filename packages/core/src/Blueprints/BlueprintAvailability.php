<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use InvalidArgumentException;
use Kitsune\Core\Blueprints\Declarations\AvailabilityDeclaration;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteGroup;
use RuntimeException;

/**
 * Receipt projections and ownership checks for ADR-039's sparse availability key.
 * The existing model remains the only store and resolver.
 *
 * @internal
 */
final class BlueprintAvailability
{
    /** @return array<string, AvailabilityDeclaration> */
    public static function declarations(BlueprintDefinition $definition): array
    {
        $rows = $definition instanceof DeclaresAvailability ? $definition->availability() : [];

        return self::checkedDeclarations($rows, array_column($definition->entryTypes(), 'handle'));
    }

    /** @param list<string> $types
     * @return array<string, AvailabilityDeclaration>
     */
    private static function checkedDeclarations(mixed $rows, array $types): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException('Blueprint availability must be a list.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (! $row instanceof AvailabilityDeclaration
                || ! in_array($row->entryType, $types, true)
                || ! self::validScope($row->scope)
                || ($row->scope === 'org' ? $row->target !== null : ! self::validTarget($row->target))) {
                throw new InvalidArgumentException('Blueprint availability must name a declared entry type and an exact target at org, site_group or site scope.');
            }
            $key = self::key($row->entryType, $row->scope, $row->target);
            if (isset($result[$key])) {
                throw new InvalidArgumentException('Blueprint availability declares the same tuple twice.');
            }
            $result[$key] = $row;
        }

        return $result;
    }

    private static function validScope(mixed $scope): bool
    {
        return in_array($scope, ['org', 'site_group', 'site'], true);
    }

    private static function validTarget(?string $target): bool
    {
        return $target !== null && $target !== '' && strlen($target) <= 255
            && trim($target) === $target && preg_match('/[\x00-\x1f\x7f]/', $target) === 0;
    }

    private static function key(string $type, string $scope, ?string $target): string
    {
        return json_encode([$type, $scope, $target], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function declarationRecord(AvailabilityDeclaration $row): array
    {
        return ['entry_type' => $row->entryType, 'scope' => $row->scope, 'target' => $row->target,
            'is_enabled' => $row->isEnabled, 'on_collision' => $row->onCollision->value];
    }

    /**
     * Resolve NEW prospective targets before intent. A numeric string is a handle, never an ID.
     * Recorded declarations are deliberately not rebound after an operator renames/deletes their target.
     *
     * @param  array<string, AvailabilityDeclaration>  $declared
     * @param  array<string, array<string, mixed>>  $recorded
     * @return array<string, array<string, mixed>>
     */
    public static function prepare(array $declared, array $recorded, int $orgId): array
    {
        $prepared = [];
        foreach ($declared as $key => $row) {
            if (isset($recorded[$key])) {
                continue;
            }
            $target = match ($row->scope) {
                'org' => Org::query()->whereKey($orgId)->first(),
                'site' => Site::query()->where('org_id', $orgId)->where('handle', $row->target)->first(),
                'site_group' => SiteGroup::query()->where('org_id', $orgId)->where('handle', $row->target)->first(),
            };
            if ($target === null || ($row->scope !== 'org' && $target->getAttribute('handle') !== $row->target)) {
                throw new RuntimeException("Cannot apply availability: {$row->scope} target is not an exact handle in this organisation.");
            }
            $prepared[$key] = [...self::declarationRecord($row), 'scope_id' => (int) $target->getKey()];
        }

        return $prepared;
    }

    /** Lock the org before types, targets, decisions and roles; native snapshot reads must follow locks. */
    public static function lockOrg(int $orgId): void
    {
        if (Org::query()->withoutGlobalScopes()->whereKey($orgId)->lockForUpdate()->first() === null) {
            throw new RuntimeException('Cannot apply availability: the organisation is gone.');
        }
    }

    /**
     * Lock by captured identity, never a new lookup of a reusable handle.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @return array<string, bool>
     */
    public static function lockTargets(array $records, int $orgId): array
    {
        uasort($records, static fn (array $a, array $b): int => [$a['scope'], $a['scope_id']] <=> [$b['scope'], $b['scope_id']]);
        $holds = [];
        foreach ($records as $key => $row) {
            $model = match ($row['scope']) {
                'org' => Org::class,
                'site' => Site::class,
                'site_group' => SiteGroup::class,
                default => throw new RuntimeException('Cannot lock a malformed availability scope.'),
            };
            $live = $model::withoutScopeBecause(
                'a blueprint checks a captured availability target identity and owner under lock before any write',
                static fn ($query) => $query->whereKey($row['scope_id'])->lockForUpdate()->first(),
            );
            $holds[$key] = $live !== null && ($row['scope'] === 'org'
                ? (int) $live->getKey() === $orgId
                : (int) $live->getAttribute('org_id') === $orgId && $live->getAttribute('handle') === $row['target']);
        }

        return $holds;
    }

    /**
     * @param  array<string, AvailabilityDeclaration>  $declared
     * @param  array<string, array<string, mixed>>  $recorded
     */
    public static function refuseChanges(array $declared, array $recorded): void
    {
        foreach ($recorded as $key => $row) {
            if (! isset($declared[$key])) {
                throw new RuntimeException('Cannot merge availability: a recorded declaration was removed.');
            }
            foreach (self::declarationRecord($declared[$key]) as $name => $value) {
                if ($row[$name] !== $value) {
                    throw new RuntimeException("Cannot merge availability: a recorded declaration changes {$name}.");
                }
            }
        }
    }

    /**
     * Existing records are carried verbatim: edits/deletions are operator decisions, never replayed.
     *
     * @param  array<string, array<string, mixed>>  $prepared
     * @param  array<string, array<string, mixed>>  $types
     * @param  array<string, array<string, mixed>>  $recorded
     * @return array<string, array<string, mixed>>
     */
    public static function write(array $prepared, array $types, array $recorded, int $orgId): array
    {
        foreach ($prepared as $key => $row) {
            $parent = $types[$row['entry_type']] ?? null;
            $type = $parent === null || $parent['outcome'] !== 'created' ? null : EntryType::query()
                ->whereKey($parent['id'])->where('org_id', $orgId)->lockForUpdate()->first();
            if ($type === null || $type->handle !== $row['entry_type']
                || EntryType::query()->whereNull('org_id')->where('handle', $row['entry_type'])->exists()) {
                throw new RuntimeException('Cannot apply availability: its entry type was not created and still held by this blueprint.');
            }
            $live = EntryTypeAvailability::query()->where('entry_type_id', $type->getKey())
                ->where('scope_type', $row['scope'])->where('scope_id', $row['scope_id'])->lockForUpdate()->first();
            if ($live !== null && $row['on_collision'] === OnCollision::Fail->value) {
                throw new RuntimeException('Cannot apply availability: the decision already exists, including when its value agrees.');
            }
            $outcome = $live === null ? 'created' : 'skipped';
            $live ??= EntryTypeAvailability::create(['entry_type_id' => $type->getKey(), 'scope_type' => $row['scope'],
                'scope_id' => $row['scope_id'], 'is_enabled' => $row['is_enabled']]);
            $recorded[$key] = [...$row, 'entry_type_id' => (int) $type->getKey(), 'id' => (int) $live->getKey(), 'outcome' => $outcome];
        }

        return $recorded;
    }

    /**
     * Only absence means an old empty key. Malformed present records grant no reverse ownership.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array<string, mixed>>  $types
     * @param  list<string>  $problems
     * @return array<string, array<string, mixed>>
     */
    public static function read(array $manifest, array $types, int $orgId, array &$problems): array
    {
        if (! array_key_exists('availability', $manifest)) {
            return [];
        }
        if (! is_array($manifest['availability']) || ! array_is_list($manifest['availability'])) {
            $problems[] = 'its availability is not a list';

            return [];
        }
        $result = [];
        $ids = [];
        foreach ($manifest['availability'] as $row) {
            if (! is_array($row) || ! is_string($row['entry_type'] ?? null)
                || ! in_array($row['scope'] ?? null, ['org', 'site_group', 'site'], true)
                || ! array_key_exists('target', $row)
                || ($row['scope'] === 'org' ? $row['target'] !== null : (! is_string($row['target']) || ! self::validTarget($row['target'])))
                || ! is_bool($row['is_enabled'] ?? null)
                || ! in_array($row['on_collision'] ?? null, ['fail', 'skip'], true)
                || ! in_array($row['outcome'] ?? null, ['created', 'skipped'], true)
                || ($row['outcome'] === 'skipped' && $row['on_collision'] !== 'skip')
                || ! self::positiveId($row['entry_type_id'] ?? null) || ! self::positiveId($row['scope_id'] ?? null) || ! self::positiveId($row['id'] ?? null)
                || ($row['scope'] === 'org' && (int) $row['scope_id'] !== $orgId)
                || ($types[$row['entry_type']]['outcome'] ?? null) !== 'created'
                || (int) $types[$row['entry_type']]['id'] !== (int) $row['entry_type_id']) {
                $problems[] = 'its availability record is malformed or names a parent this blueprint did not create';

                continue;
            }
            $key = self::key($row['entry_type'], $row['scope'], $row['target']);
            if (isset($result[$key]) || isset($ids[(int) $row['id']])) {
                $problems[] = 'its availability records the same decision twice';
            }
            $ids[(int) $row['id']] = true;
            $result[$key] = $row;
        }

        return $result;
    }

    private static function positiveId(mixed $id): bool
    {
        return (is_int($id) && $id > 0) || (is_string($id) && ctype_digit($id) && (int) $id > 0 && (string) (int) $id === $id);
    }

    /**
     * A parent cascade must not erase even one unowned/edited/replaced decision.
     * Parent X locks fence FK inserts; locking existing decisions fences value edits. Native tests must verify both.
     *
     * @param  array<string, array<string, mixed>>  $records
     * @param  array<string, array<string, mixed>>  $types
     * @return list<string>
     */
    public static function reverseObstructions(array $records, array $types, int $orgId): array
    {
        $targets = self::lockTargets($records, $orgId);
        $owned = [];
        foreach ($records as $key => $row) {
            if ($row['outcome'] === 'created' && ($targets[$key] ?? false)) {
                $owned[(int) $row['id']] = $row;
            }
        }
        $typeIds = array_column(array_filter($types, static fn (array $row): bool => $row['outcome'] === 'created'), 'id');
        $rows = EntryTypeAvailability::query()->whereIn('entry_type_id', $typeIds)->orderBy('id')->lockForUpdate()->get();
        $problems = [];
        foreach ($rows as $live) {
            $row = $owned[(int) $live->getKey()] ?? null;
            if ($row === null || ! self::matches($live, $row)) {
                $problems[] = "availability decision {$live->getKey()} is not an unchanged decision this blueprint created; its parent cannot be removed";
            }
        }

        return $problems;
    }

    /** @param array<string, mixed> $row */
    private static function matches(EntryTypeAvailability $live, array $row): bool
    {
        return (int) $live->entry_type_id === (int) $row['entry_type_id'] && $live->scope_type === $row['scope']
            && (int) $live->scope_id === (int) $row['scope_id'] && $live->is_enabled === $row['is_enabled'];
    }

    /** @param array<string, array<string, mixed>> $records
     * @return list<string>
     */
    public static function finishNotes(array $records, int $orgId): array
    {
        $targets = self::lockTargets($records, $orgId);
        $notes = [];
        foreach ($records as $key => $row) {
            if ($row['outcome'] !== 'created') {
                continue;
            }
            $live = EntryTypeAvailability::query()->whereKey($row['id'])->lockForUpdate()->first();
            if ($live === null || ! ($targets[$key] ?? false) || ! self::matches($live, $row)) {
                $notes[] = "availability decision {$row['id']}: removed or taken over since this blueprint wrote it; not written again";
            }
        }

        return $notes;
    }
}
