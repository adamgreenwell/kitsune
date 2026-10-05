<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;
use Kitsune\Core\Blueprints\FirstParty\MarketingSiteBlueprint;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Schema\SchemaManager;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tests\Fixtures\BlogAtAnotherVersion;
use Kitsune\Core\Tests\Fixtures\Manifests\BeforeMedia;
use Kitsune\Core\Tests\Fixtures\Released\Blog100;
use Kitsune\Core\Tests\Fixtures\Released\MarketingSite100;
use Kitsune\Core\Tests\Fixtures\Released\MarketingSite110;
use Kitsune\Core\Tests\Fixtures\SchemaManagerStandIn;
use Kitsune\Core\Tests\Fixtures\TestUser;

/*
 * A receipt written before the format could declare a media type still merges, finishes and reverses — ADR-039, the DAM
 * as built.
 *
 * ⚠️ THE MANIFESTS ARE CAPTURED, NOT IMAGINED. `BeforeMedia` holds exactly what core wrote for each frozen release before
 * `is_media` joined the type record, so stage and every install tracking `main` hold receipts in this shape. A reader
 * that demanded the new key would refuse their next merge and turn their reverse into one that keeps every row.
 */

beforeEach(function (): void {
    config(['auth.providers.users.model' => TestUser::class]);
    SchemaManagerStandIn::install()->recordOnly();
    $this->org = Org::create(['slug' => 'before', 'name' => 'Before']);
    app(Context::class)->setOrg($this->org);
});

afterEach(function (): void {
    app()->forgetInstance(SchemaManager::class);
    app(Context::class)->forget();
});

/**
 * Apply a frozen release, then put its manifest back as core wrote it before the widening.
 *
 * @param  'blog100'|'marketingSite100'|'marketingSite110'  $release
 */
function beforeMediaApplied(string $release, bool $finished = true): BlueprintDefinition
{
    $definition = match ($release) {
        'blog100' => new Blog100,
        'marketingSite100' => new MarketingSite100,
        'marketingSite110' => new MarketingSite110,
    };

    BlueprintApplier::apply($definition);
    $receipt = Blueprint::receiptFor($definition->handle());
    $ids = [];

    foreach ([...$receipt->manifest['entry_types'], ...$receipt->manifest['roles']] as $row) {
        $ids[$row['handle']] = $row['id'];
    }

    DB::table('blueprints')->where('id', $receipt->getKey())->update([
        'manifest' => json_encode(BeforeMedia::{$release}($ids)),
        ...($finished ? [] : ['applied_at' => null]),
    ]);

    return $definition;
}

/** The manifest less ids and the prose outcome, keys sorted — what a merge and a fresh apply must agree on. */
function beforeMediaOracle(string $handle): array
{
    $manifest = (array) Blueprint::receiptFor($handle)?->manifest;
    unset($manifest['outcome']);

    foreach (['entry_types', 'roles'] as $key) {
        foreach ($manifest[$key] as $i => $row) {
            unset($manifest[$key][$i]['id']);
        }
    }

    $sort = static function (array $value) use (&$sort): array {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $sort($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    };

    return $sort($manifest);
}

/** A definition as it stands, at another version — for a version-only merge over a release. */
function beforeMediaAt(BlueprintDefinition $definition, string $version, ?callable $types = null): BlueprintDefinition
{
    $entryTypes = $types === null ? $definition->entryTypes() : $types($definition->entryTypes());

    return new class($definition->handle(), $version, $entryTypes, $definition->roles()) implements BlueprintDefinition
    {
        public function __construct(private string $handle, private string $version, private array $types, private array $roles) {}

        public function handle(): string
        {
            return $this->handle;
        }

        public function version(): string
        {
            return $this->version;
        }

        public function entryTypes(): array
        {
            return $this->types;
        }

        public function roles(): array
        {
            return $this->roles;
        }
    };
}

/** B1 — the fixture pins its own format, so it cannot drift into the new one unnoticed. */
it('holds manifests in the format before the widening, with no media flag', function (string $release): void {
    $manifest = BeforeMedia::{$release}(['post' => 1, 'tag' => 2, 'page' => 3, 'blog_editor' => 4, 'blog_writer' => 5, 'marketing_editor' => 6, 'marketing_writer' => 7]);

    foreach ($manifest['entry_types'] as $row) {
        expect(array_keys($row))->toBe(['handle', 'id', 'outcome', 'name', 'plural_name', 'icon', 'description', 'ordering', 'on_collision', 'fields']);
    }
})->with(['blog100', 'marketingSite100', 'marketingSite110']);

/** B2 */
it('does nothing at the same version over a manifest from before the widening', function (string $release): void {
    $definition = beforeMediaApplied($release);

    expect(BlueprintApplier::apply($definition)['skipped'])->toBe(['already applied at this version']);
})->with(['blog100', 'marketingSite100', 'marketingSite110']);

/** B3 — including the real upgrade every Marketing Site 1.0.0 org takes. */
it('merges a later version over a manifest from before the widening, ending where a fresh apply ends', function (string $release, string $next): void {
    beforeMediaApplied($release);
    $types = EntryType::query()->where('org_id', $this->org->getKey())->orderBy('id')->get()->toArray();

    $later = match ($next) {
        'blog 1.1.0' => new BlogAtAnotherVersion,
        'marketing-site 1.1.0' => new MarketingSiteBlueprint,
        'marketing-site 1.1.1' => beforeMediaAt(new MarketingSiteBlueprint, '1.1.1'),
    };

    $result = BlueprintApplier::apply($later);
    $merged = beforeMediaOracle($later->handle());

    /* Only what the version adds was written: every type the release created is as it was. */
    expect($result['version'])->toBe($later->version())
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->orderBy('id')->limit(count($types))->get()->toArray())->toBe($types);

    $fresh = Org::create(['slug' => 'fresh', 'name' => 'Fresh']);
    app(Context::class)->setOrg($fresh);
    BlueprintApplier::apply($later);

    expect($merged)->toBe(beforeMediaOracle($later->handle()))
        ->and(collect($merged['entry_types'])->pluck('is_media')->unique()->all())->toBe([false]);
})->with([
    'Blog 1.0.0 to a test\'s 1.1.0' => ['blog100', 'blog 1.1.0'],
    'the Marketing Site 1.0.0 to the shipped 1.1.0' => ['marketingSite100', 'marketing-site 1.1.0'],
    'the Marketing Site 1.1.0 to 1.1.1' => ['marketingSite110', 'marketing-site 1.1.1'],
]);

/** B4 — a full reverse, not one that keeps every row because the manifest lacks a key. */
it('reverses a manifest from before the widening in full', function (string $release): void {
    $definition = beforeMediaApplied($release);

    $result = BlueprintApplier::reverse($definition->handle());

    expect($result['outcome'])->toBe('reversed')
        ->and($result['problems'])->toBe([])
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->exists())->toBeFalse()
        ->and(FieldStorage::query()->where('org_id', $this->org->getKey())->exists())->toBeFalse()
        ->and(Role::query()->exists())->toBeFalse()
        ->and(Blueprint::query()->exists())->toBeFalse();
})->with(['blog100', 'marketingSite100', 'marketingSite110']);

/** B5 */
it('finishes a manifest from before the widening, and words a refused finish as the full reverse it would be', function (): void {
    $definition = beforeMediaApplied('blog100', finished: false);

    expect(BlueprintApplier::apply($definition)['skipped'])->toBe(['rows: written by an earlier run that stopped before it finished; finished now'])
        ->and(Blueprint::receiptFor('blog')->applied_at)->not->toBeNull();

    DB::table('blueprints')->update(['applied_at' => null]);
    $grown = beforeMediaAt($definition, '1.0.0', static fn (array $types): array => [...$types, new EntryTypeDeclaration(handle: 'series', name: 'Series', pluralName: 'Series')]);

    expect(fn () => BlueprintApplier::apply($grown))->toThrow(RuntimeException::class,
        'cannot be finished: entry type series is not recorded. Its manifest is not this organisation\'s record of this '
        .'blueprint\'s rows, so nothing was written and the receipt is left as it is. `kitsune:blueprint reverse blog` '
        .'removes what this receipt records this blueprint created'
    );
});

/** B6 — what the absent key meant is what it compares as: a version making a recorded type media is refused. */
it('refuses a version making a type media over a manifest from before the widening', function (): void {
    $definition = beforeMediaApplied('blog100');
    $before = Blueprint::receiptFor('blog')->manifest;

    $media = beforeMediaAt($definition, '1.1.0', static fn (array $types): array => array_map(
        static fn (EntryTypeDeclaration $type): EntryTypeDeclaration => $type->handle !== 'post' ? $type : new EntryTypeDeclaration(
            handle: $type->handle, name: $type->name, pluralName: $type->pluralName, fields: $type->fields, icon: $type->icon,
            description: $type->description, ordering: $type->ordering, onCollision: $type->onCollision, isMedia: true,
        ),
        $types,
    ));

    expect(fn () => BlueprintApplier::apply($media))->toThrow(RuntimeException::class, 'entry type post changes its is_media');

    expect(Blueprint::receiptFor('blog')->manifest)->toBe($before)
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->where('handle', 'post')->value('is_media'))->toBeFalse();
});

/** B7 — only the key the format gained is filled; a manifest missing any other is still not one any core wrote. */
it('still refuses a manifest from before the widening that lacks a key it always had', function (): void {
    $definition = beforeMediaApplied('blog100');
    $receipt = Blueprint::receiptFor('blog');
    $manifest = $receipt->manifest;
    unset($manifest['entry_types'][0]['name']);
    DB::table('blueprints')->where('id', $receipt->getKey())->update(['manifest' => json_encode($manifest)]);

    expect(fn () => BlueprintApplier::apply(new BlogAtAnotherVersion))->toThrow(RuntimeException::class, 'entry type post has no name');

    $result = BlueprintApplier::reverse('blog');

    expect($result['outcome'])->toBe('receipt-only')
        ->and($result['problems'])->toBe(['entry type post has no name'])
        ->and(EntryType::query()->where('org_id', $this->org->getKey())->count())->toBe(2);
});
