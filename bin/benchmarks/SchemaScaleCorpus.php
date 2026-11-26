<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Benchmarks;

use Generator;
use InvalidArgumentException;
use Kitsune\Core\Models\Entry;
use LogicException;
use OutOfBoundsException;

/**
 * Reproducible synthetic workload for issue #261, not an observed customer distribution.
 *
 * Org 1 has a skewed shared-media population, org 2 has equal shared/site media, and org 3 has none.
 * Site 2's large sibling population exercises the work a widened scoped read must discard. Bodies,
 * histories and soft deletes use different cycles so large bodies are not all deleted or all in the
 * longest history. Rows are generated one at a time; the million-row corpus is never held in PHP.
 *
 * This describes values, not validation or persistence. The measurement must run the real field rules
 * and separately label bulk fixture import, audited model writes, revisions and actual panel requests.
 * Nothing under bin/benchmarks is a runtime extension contract.
 *
 * @internal
 */
final class SchemaScaleCorpus
{
    /** @var list<array{key: string, org: int, site: ?int, kind: string, rows: int}> */
    private array $groups;

    /** @var array<int, string> */
    private array $bodies = [];

    /** @var array<int, list<int>> */
    private array $targets = [];

    public function __construct(public readonly int $size)
    {
        if (! in_array($size, [10_000, 100_000, 1_000_000], true)) {
            throw new InvalidArgumentException('The comparable scales are exactly 10000, 100000 and 1000000.');
        }

        $this->groups = [];
        foreach ([
            ['skew-site-articles', 1, 1, 'article', 30],
            ['skew-sibling-articles', 1, 2, 'article', 15],
            ['skew-shared-assets', 1, null, 'asset', 15],
            ['skew-site-assets', 1, 1, 'asset', 5],
            ['typical-site-articles', 2, 3, 'article', 15],
            ['typical-sibling-articles', 2, 4, 'article', 5],
            ['typical-shared-assets', 2, null, 'asset', 5],
            ['typical-site-assets', 2, 3, 'asset', 5],
            ['empty-shared-site-articles', 3, 5, 'article', 5],
        ] as [$key, $org, $site, $kind, $percent]) {
            $this->groups[] = compact('key', 'org', 'site', 'kind') + ['rows' => intdiv($size * $percent, 100)];
        }

        foreach ([768, 4096, 32768] as $bytes) {
            // Already in the form Entry's per-block direction pass persists; verified against real writes.
            $open = '<p dir="auto">';
            $this->bodies[$bytes] = $open.substr(str_repeat('Synthetic editorial body. ', (int) ceil($bytes / 26)), 0, $bytes - strlen($open) - 4).'</p>';
        }

        $before = 0;
        foreach ($this->groups as $group) {
            if ($group['site'] === null || $group['org'] === 3) {
                // Ordinals 1 and 2 are live, and shared assets are visible from both sibling sites.
                $this->targets[$group['org']] = [$before + 2, $before + 3];
            }
            $before += $group['rows'];
        }
    }

    /** @return list<array{key: string, org: int, site: ?int, kind: string, rows: int}> */
    public function populations(): array
    {
        return $this->groups;
    }

    /** @return list<array{site: int, owner: int, article: int, selective_article: int}> */
    public function panelReadTargets(): array
    {
        $targets = [];
        $before = 0;
        foreach ($this->groups as $group) {
            $last = $before + $group['rows'];
            if ($group['kind'] === 'article' && $group['site'] !== null) {
                $first = $before + 1;
                while ($first <= $last && $this->row($first)['deleted_at'] !== null) {
                    $first++;
                }
                if ($first > $last) {
                    throw new LogicException('A browser target Site has no live article.');
                }
                $selective = $last;
                while ($this->row($selective)['deleted_at'] !== null) {
                    $selective--;
                }
                $targets[] = [
                    'site' => $group['site'],
                    'owner' => $group['org'],
                    'article' => $first,
                    'selective_article' => $selective,
                ];
            }
            $before = $last;
        }

        return $targets;
    }

    /** @return array{shared: int, other_org: int, site_only: int} */
    public function panelMediaTargets(): array
    {
        $before = 0;
        foreach ($this->groups as $group) {
            if ($group['org'] === 1 && $group['site'] === 1 && $group['kind'] === 'asset') {
                // The first row of each population is live at every corpus size.
                return ['shared' => $this->targets[1][0], 'other_org' => $this->targets[2][0], 'site_only' => $before + 1];
            }
            $before += $group['rows'];
        }

        throw new LogicException('The corpus has no assets specific to the first Site.');
    }

    /** @return Generator<int, array<string, mixed>> */
    public function rows(): Generator
    {
        for ($id = 1; $id <= $this->size; $id++) {
            yield $id => $this->row($id);
        }
    }

    /** @return array<string, mixed> */
    public function row(int $id): array
    {
        if ($id < 1 || $id > $this->size) {
            throw new OutOfBoundsException('The row must belong to this corpus.');
        }

        $before = 0;
        foreach ($this->groups as $group) {
            if ($id > $before + $group['rows']) {
                $before += $group['rows'];

                continue;
            }

            $ordinal = $id - $before - 1;
            $bodyBand = $ordinal % 100;
            $bytes = $bodyBand === 0 ? 32768 : ($bodyBand < 10 ? 4096 : 768);
            // Independent deterministic cycles, with one explicit live large/long-history control per population.
            $historyBand = $ordinal === 0 ? 0 : (int) sprintf('%u', crc32('history-'.$id)) % 100;
            $edits = $historyBand === 0 ? 75 : ($historyBand < 10 ? 10 : ($historyBand < 30 ? 3 : 0));
            $stamp = gmdate('Y-m-d H:i:s', 1_790_000_000 - (($id * 7919) % 400_000) * 60);

            return [
                'id' => $id, 'population' => $group['key'], 'org' => $group['org'],
                'site' => $group['site'], 'kind' => $group['kind'],
                'title' => 'Synthetic '.$group['kind'].' '.$id,
                // Shared rows have no URL claim, matching the current Entry writer.
                'slug' => $group['site'] === null ? null : 'scale-'.$id,
                'status' => $ordinal % 3 === 0 ? 'draft' : 'published',
                'created_at' => $stamp, 'updated_at' => $stamp,
                'deleted_at' => $ordinal !== 0 && (int) sprintf('%u', crc32('deleted-'.$id)) % 20 === 0 ? $stamp : null,
                'authored_edits' => $edits,
                'retained_revisions' => min(Entry::KEEP_REVISIONS, $edits + 1),
                'relation_targets' => $group['kind'] === 'article' && $ordinal % 10 === 0
                    ? ($ordinal % 20 === 0 ? array_reverse($this->targets[$group['org']]) : $this->targets[$group['org']])
                    : [],
                'values' => [
                    'body' => $this->bodies[$bytes], 'score' => ((int) sprintf('%u', crc32('score-'.$id)) % 10001) / 100,
                    'category' => ['news', 'guide', 'opinion', 'feature'][$ordinal % 4],
                    'tags' => ['topic-'.($ordinal % 8), 'bench'],
                ],
            ];
        }

        throw new OutOfBoundsException('The population does not contain this row.');
    }
}
