<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use RuntimeException;

/**
 * A module's `extra.kitsune` block, and the grammar that refuses it (ADR-038).
 *
 * The manifest lives in the package's own `composer.json` rather than a `kitsune.yaml`: discovery reads what
 * Composer installed anyway, a YAML parser on the boot path is a floor cost (ADR-027), and two files describing
 * one package is two files to disagree.
 *
 * ⚠️ ONE ENCODING OF THE GRAMMAR, ASKED BY BOTH THE INSTALL PATH AND THE TESTS. `Permissions::refusalFor()`
 * records why: when the refusal and the assertion are separate copies, the permissive one wins and nobody
 * notices. So the question is `refusalFor()` — it returns the reason a manifest is refused, or null — and
 * `from()` is that question plus a throw.
 *
 * ⚠️ THE KEY IS `scoping`, NOT `tenancy`. AGENTS.md §1 reserves "tenant" for the Filament API boundary, and a
 * key core's own reader parses is not that boundary. ADR-009 published `tenancy: aware | agnostic` and ADR-038
 * amended it — `aware | agnostic` could not be checked against anything, where these values are the ones
 * `ScopeResolver` already reads off a model.
 */
final readonly class ModuleManifest
{
    /**
     * The scopes a module may declare, less `unscoped:through(…)`, which carries a class name.
     *
     * ⚠️ `unscoped` IS NOT ON ITS OWN A DECLARATION, and ADR-038 records why at length. A scope derived from a
     * table's columns — `site_id` ⇒ site, else `org_id` ⇒ org, else unscoped — reads "org" from core's own
     * commonest shape: `entry_types` and `field_storage` both carry a NULLABLE `org_id` while declaring
     * `#[Unscoped]`. A module reproducing that shape would list both, and once the word `unscoped` is in the
     * list the table check is satisfied by construction. Split, the claim is machine-checkable: `unscoped:global`
     * promises no scope column at all, and `unscoped:through(M)` names the model it reaches its org through.
     */
    public const SCOPES = ['site', 'org', 'org-through-pivot', 'unscoped:global'];

    /**
     * `unscoped:through(App\Models\Thing)`.
     *
     * ⚠️ `\A` AND `\z`, NOT `^` AND `$`. PHP's `$` also matches before a trailing newline, so an earlier
     * version accepted `"unscoped:through(M)\n"` as a scope while every other value in `SCOPES` was compared
     * with `in_array()` and tolerated nothing of the kind. A grammar that accepts one spelling of a value and
     * not another is the same class of defect as a guard that compares a column name the database does not.
     *
     * ⚠️ AND THE CAPTURE IS A CLASS NAME, NOT `[^()]+`. That older form accepted `unscoped:through( )` —
     * a scope naming no model — which then satisfied the reverse-direction check by naming nothing at all.
     */
    public const THROUGH_PATTERN = '/\Aunscoped:through\((\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*)\)\z/';

    /** The same grammar, for a bare class name such as a `provider`. */
    public const CLASS_NAME_PATTERN = '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    /**
     * Autoload keys a module may not use.
     *
     * ⚠️ THE SWEEP WALKS `psr-4`, SO ANYTHING ELSE IS A HOLE, AND THIS WAS MEASURED AS ONE. A model reached by
     * `classmap` was never examined and its module was accepted — the same unconstrained class, with only the
     * autoload key changed, flipped from refused to accepted. `files` is worse still: Composer includes those
     * eagerly at boot, before any Kitsune code runs, which is the argument this class already makes for
     * refusing `extra.laravel.providers`. Refusing is narrower than teaching the sweep four more layouts, and
     * a first-party module has no reason to want them.
     */
    public const REFUSED_AUTOLOAD_KEYS = ['classmap', 'files', 'psr-0'];

    /**
     * @param  list<string>  $scoping  every scope this module's models may declare
     * @param  array<string, list<string>>  $psr4  the package's own PSR-4 roots, namespace => directories,
     *                                             relative to its install path — where the sweep looks
     */
    private function __construct(
        public string $package,
        public string $provider,
        public array $scoping,
        public array $psr4,
    ) {}

    /**
     * Why this package is not a loadable module, or null if it is one.
     *
     * @param  array<string, mixed>  $composerJson  the package's decoded composer.json
     */
    public static function refusalFor(array $composerJson, string $package): ?string
    {
        $read = self::read($composerJson, $package);

        return is_string($read) ? $read : null;
    }

    /**
     * @param  array<string, mixed>  $composerJson
     *
     * @throws RuntimeException when the manifest is refused
     */
    public static function from(array $composerJson, string $package): self
    {
        $read = self::read($composerJson, $package);

        return $read instanceof self ? $read : throw new RuntimeException($read);
    }

    /**
     * The manifest, or the reason there is not one. The single encoding of the grammar.
     *
     * ⚠️ ONE WALK, NOT TWO. An earlier version had `refusalFor()` and `from()` each walk the array, with
     * `from()` asserting the shape afterwards through an inline `@var` — which phpstan.neon forbids by name
     * ("Do not use assert() or inline @var PHPDoc tag to override PHPStan's inferred type"), and rightly: the
     * assertion was a second, weaker copy of the checks above it, and a divergence between the two would have
     * been invisible. Narrowing as it goes means the constructor is reached only along a path that proved
     * every value, and the analyser can see that.
     *
     * @param  array<string, mixed>  $composerJson
     */
    private static function read(array $composerJson, string $package): self|string
    {
        $extra = $composerJson['extra'] ?? null;

        if (! is_array($extra) || ! array_key_exists('kitsune', $extra)) {
            return "{$package} declares no `extra.kitsune` block, so it is not a module.";
        }

        $manifest = $extra['kitsune'];

        if (! is_array($manifest)) {
            return "{$package}'s `extra.kitsune` is not an object.";
        }

        /*
         * ⚠️ A PACKAGE LARAVEL BOOTS ITSELF IS REFUSED, and this is the one refusal that is about the gate
         * rather than about the declaration. Laravel registers `extra.laravel.providers` from
         * bootstrap/cache/packages.php before any Kitsune code runs, so such a package would be live whatever
         * the kernel decided — which is precisely the thing the kernel exists to prevent.
         */
        if (is_array($extra['laravel'] ?? null) && ($extra['laravel']['providers'] ?? []) !== []) {
            return "{$package} declares `extra.laravel.providers`, which Laravel registers before the kernel "
                .'can refuse it. A module is registered by the kernel or not at all.';
        }

        if (! array_key_exists('provider', $manifest) || ! is_string($manifest['provider']) || $manifest['provider'] === '') {
            return "{$package}'s manifest names no `provider`.";
        }

        /* A non-empty string is not a class name: `' '`, `'<script>'` and a string holding a NUL all passed. */
        if (preg_match(self::CLASS_NAME_PATTERN, $manifest['provider']) !== 1) {
            return "{$package}'s `provider` is not a class name.";
        }

        foreach (self::REFUSED_AUTOLOAD_KEYS as $key) {
            if (($composerJson['autoload'][$key] ?? null) !== null) {
                return "{$package} autoloads through `{$key}`, which the scoping sweep cannot enumerate. A "
                    .'module exposes its classes through `autoload.psr-4` so that every one of them can be '
                    .'checked; a class reached another way is one the kernel cannot account for.';
            }
        }

        /* array_key_exists, never `?? []`: an EMPTY list is a declaration ("this module ships no scoped models"), absence is not. */
        if (! array_key_exists('scoping', $manifest)) {
            return "{$package}'s manifest declares no `scoping`. ADR-038: the kernel refuses to load a module "
                .'that has not said what its models scope to.';
        }

        $scoping = $manifest['scoping'];

        if (! is_array($scoping) || array_is_list($scoping) === false) {
            return "{$package}'s `scoping` must be a list.";
        }

        $scopes = [];

        foreach ($scoping as $scope) {
            if (! is_string($scope)) {
                return "{$package}'s `scoping` holds a value that is not a string.";
            }

            if (! self::isScope($scope)) {
                return "{$package} declares the scope `{$scope}`, which is not one of: "
                    .implode(', ', self::SCOPES).', unscoped:through(Model).';
            }

            /* A repeated scope says nothing twice; refusing it keeps the list a set, which is what the verifier compares against. */
            if (in_array($scope, $scopes, true)) {
                return "{$package}'s `scoping` repeats a scope.";
            }

            $scopes[] = $scope;
        }

        /*
         * Derived from the package's own autoload block, never declared in the manifest: a module repeating its
         * PSR-4 roots would be two places to drift, and the sweep must look where Composer actually loads from
         * or it is checking a different set of classes than the one that runs.
         */
        /*
         * ⚠️ EVERY MALFORMED PSR-4 ENTRY IS A REFUSAL, BECAUSE DROPPING ONE IS A PASS. An earlier version
         * skipped a path that was not a string and kept walking — and a manifest whose only root was, say,
         * `['Acme\\' => 123]` therefore produced an empty root list, swept nothing, and was accepted. Five
         * different manifest shapes reached that outcome. Absence of a finding is not a finding.
         */
        $roots = [];
        $psr4 = $composerJson['autoload']['psr-4'] ?? null;

        if (! is_array($psr4) || $psr4 === []) {
            return "{$package} declares no `autoload.psr-4`, so there is nothing for the scoping sweep to "
                .'walk and nothing it could report. A module exposes its classes through PSR-4.';
        }

        foreach ($psr4 as $namespace => $paths) {
            if (! is_string($namespace) || $namespace === '') {
                return "{$package} has a PSR-4 namespace key that is not a namespace.";
            }

            /* PSR-4 permits a string or a list of them, and a module using the list form is not exotic. */
            $directories = [];

            foreach (is_array($paths) ? $paths : [$paths] as $path) {
                if (! is_string($path)) {
                    return "{$package} maps `{$namespace}` to a path that is not a string.";
                }

                /*
                 * ⚠️ `..` AND ABSOLUTE PATHS WALK OUT OF THE PACKAGE, and one measurably did: a root of
                 * `../../` produced a non-empty `examined` made entirely of ANOTHER package's classes, so the
                 * sweep reported having checked things while checking nothing of this module's. The verifier
                 * confines roots to the install path as well; this refuses the spelling outright so the two
                 * do not have to agree about what a traversal means.
                 */
                if (str_starts_with($path, '/') || in_array('..', explode('/', trim($path, '/')), true)) {
                    return "{$package} maps `{$namespace}` to `{$path}`, which leaves the package.";
                }

                $directories[] = $path;
            }

            if ($directories === []) {
                return "{$package} maps `{$namespace}` to no directory at all.";
            }

            $roots[$namespace] = $directories;
        }

        return new self(
            package: $package,
            provider: $manifest['provider'],
            scoping: $scopes,
            psr4: $roots,
        );
    }

    /** Whether `$scope` is a scope a module may declare. */
    public static function isScope(string $scope): bool
    {
        return in_array($scope, self::SCOPES, true)
            || preg_match(self::THROUGH_PATTERN, $scope) === 1;
    }

    /**
     * The attribute a model must carry to satisfy `$scope`.
     *
     * @return class-string<SiteScoped|OrgScoped|OrgScopedThroughPivot|Unscoped>
     */
    public static function attributeFor(string $scope): string
    {
        return match (true) {
            $scope === 'site' => SiteScoped::class,
            $scope === 'org' => OrgScoped::class,
            $scope === 'org-through-pivot' => OrgScopedThroughPivot::class,
            $scope === 'unscoped:global' => Unscoped::class,
            preg_match(self::THROUGH_PATTERN, $scope) === 1 => Unscoped::class,
            default => throw new RuntimeException("`{$scope}` is not a scope."),
        };
    }

    /** The model named by `unscoped:through(M)`, or null for every other scope. */
    public static function throughModel(string $scope): ?string
    {
        return preg_match(self::THROUGH_PATTERN, $scope, $matches) === 1 ? trim($matches[1]) : null;
    }

    /**
     * The scopes in this manifest that `$attribute` could satisfy.
     *
     * @return list<string>
     */
    public function scopesFor(string $attribute): array
    {
        return array_values(array_filter(
            $this->scoping,
            static fn (string $scope): bool => self::attributeFor($scope) === $attribute,
        ));
    }
}
