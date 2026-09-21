<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Modules;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\ScopeResolver;
use Kitsune\Core\Tenancy\UndeclaredScopeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Checks a module's scoping declaration against the models it actually ships (ADR-038).
 *
 * ⚠️ IT ASKS TWO QUESTIONS, BECAUSE ONE HAS ALREADY FAILED IN THIS REPOSITORY. AGENTS.md §2 records `User`
 * carrying `#[Unscoped]` with no `EnforcesScope` for two phases — "labelled correctly and completely
 * unconstrained" — and a later sweep found seven more core models in that state. The attribute is inert until
 * the trait reads it, so a gate that checked only the attribute would pass exactly the shape it exists to
 * catch. Both questions, on every model.
 *
 * ⚠️ AND IT LIVES OUTSIDE `EnforcesScope`, WHICH IS THE WHOLE POINT. The obvious home for a runtime check is
 * `bootEnforcesScope()`, next to where the attribute is already read — and a model that omits the trait never
 * boots it, so the check would never run for the one model it is for. A verification that cannot see its own
 * failure case is decoration.
 *
 * ⚠️ AN UNLOADABLE CLASS IS A REFUSAL, NOT A SKIP. `ScopeDeclarationTest`'s own history is the argument: its
 * earlier version guarded with `class_exists()` and skipped anything it could not load, which "made the
 * skeleton half a silent no-op — the check it claimed to perform never ran". A module whose file does not
 * define the class its path promises is refused, because the sweep cannot otherwise say what it examined.
 *
 * **What it does not check.** It cannot tell `unscoped:global` from `unscoped:through(M)`: both are
 * `#[Unscoped]` on the model, and the difference is a property of the TABLE — whether a scope column exists at
 * all — which is only observable once migrations have run. That half belongs to install, and ADR-038 says so
 * rather than letting this class imply it is covered here. What this class does check about the through form
 * is that the model it names exists and is a model, which is cheap and real.
 *
 * Filesystem and reflection only: no database, no Composer runtime call, so it holds on a bare clone
 * (AGENTS.md §11).
 */
final class ModuleVerifier
{
    public static function verify(ModuleManifest $manifest, string $installPath): ModuleVerification
    {
        $examined = [];
        $models = [];

        foreach ($manifest->psr4 as $namespace => $directories) {
            foreach ($directories as $directory) {
                /* PSR-4 permits the package root itself, written "" or "." — common enough that mishandling it
                 * would refuse ordinary packages for a path-joining bug. */
                $relativeRoot = trim($directory, '/');
                $root = in_array($relativeRoot, ['', '.'], true)
                    ? rtrim($installPath, '/')
                    : rtrim($installPath, '/').'/'.$relativeRoot;

                if (! is_dir($root)) {
                    return new ModuleVerification(
                        "{$manifest->package} maps `{$namespace}` to `{$directory}`, which is not a directory in "
                        .'the installed package. The sweep would have examined nothing, which is not the same as '
                        .'finding nothing wrong.',
                        $examined,
                        $models,
                    );
                }

                foreach (self::classesIn($root, (string) $namespace) as $class) {
                    $examined[] = $class;

                    /*
                     * ⚠️ ONLY THE FIRST CHECK MAY AUTOLOAD, AND THE REST PASS `false`. Composer's autoloader does
                     * not remember a failed lookup: it includes the file again on every `*_exists()` that triggers
                     * autoloading. For the case this sweep is FOR — a file that does not define the class its path
                     * promises — the second call re-includes the file and PHP fatals on the redeclared symbol.
                     * Measured, by writing the naive four-call version first.
                     */
                    if (! class_exists($class) && ! interface_exists($class, false) && ! trait_exists($class, false) && ! enum_exists($class, false)) {
                        return new ModuleVerification(
                            "{$manifest->package} ships a file for `{$class}`, which does not define it. PSR-4 says "
                            .'where that class must live, so the sweep cannot account for what the module loads.',
                            $examined,
                            $models,
                        );
                    }

                    if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                        continue;
                    }

                    /*
                     * Abstract bases are skipped rather than refused, and it costs nothing: PHP attributes are not
                     * inherited, so a concrete subclass gets no declaration from its parent and is swept on its own
                     * terms. Refusing the abstract as well would only ask for a declaration that protects nothing.
                     */
                    if ((new ReflectionClass($class))->isAbstract()) {
                        continue;
                    }

                    $models[] = $class;
                }
            }
        }

        return new ModuleVerification(
            self::refusalFor($manifest, $models),
            $examined,
            $models,
        );
    }

    /**
     * @param  list<class-string>  $models
     */
    private static function refusalFor(ModuleManifest $manifest, array $models): ?string
    {
        $present = [];

        foreach ($models as $model) {
            try {
                $attribute = ScopeResolver::for($model);
            } catch (UndeclaredScopeException) {
                return "{$manifest->package} ships the model `{$model}`, which declares no scope. AGENTS.md §2: "
                    .'every model declares exactly one of #[SiteScoped], #[OrgScoped], #[OrgScopedThroughPivot] '
                    .'or #[Unscoped].';
            }

            /* The second question. The attribute is inert until the trait reads it — see the class docblock. */
            if (! in_array(EnforcesScope::class, class_uses_recursive($model), true)) {
                return "{$manifest->package}'s model `{$model}` carries a scope attribute and does not "
                    .'`use EnforcesScope`, so the declaration is a comment with syntax and the model is '
                    .'unconstrained. AGENTS.md §2.';
            }

            if ($manifest->scopesFor($attribute) === []) {
                return "{$manifest->package} ships `{$model}`, scoped by `{$attribute}`, which its manifest does "
                    .'not declare. The declaration and the models must agree.';
            }

            $present[$attribute] = true;
        }

        /*
         * ⚠️ THE OTHER DIRECTION, AND IT IS NOT SYMMETRY FOR ITS OWN SAKE. Checking only that every model is
         * covered lets a module declare `org` and ship nothing org-scoped — an unproven claim that reads as a
         * checked one, and the shape a later model would quietly slip into. `ScopeDeclarationTest` compares both
         * directions for the same reason.
         */
        foreach ($manifest->scoping as $scope) {
            $attribute = ModuleManifest::attributeFor($scope);

            if (! isset($present[$attribute])) {
                return "{$manifest->package} declares the scope `{$scope}` and ships no model that uses it. A "
                    .'declaration nothing exercises is a claim rather than a fact.';
            }

            $through = ModuleManifest::throughModel($scope);

            if ($through !== null && (! class_exists($through) || ! is_subclass_of($through, Model::class))) {
                return "{$manifest->package} declares `{$scope}`, and `{$through}` is not a model that exists.";
            }
        }

        return null;
    }

    /**
     * Every class name a PSR-4 root implies, from the files on disk.
     *
     * @return list<class-string>
     */
    private static function classesIn(string $root, string $namespace): array
    {
        $found = [];

        /** @var iterable<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $path => $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($path, strlen($root) + 1, -4);

            /** @var class-string $class */
            $class = rtrim($namespace, '\\').'\\'.str_replace('/', '\\', $relative);

            $found[] = $class;
        }

        /* Directory order is filesystem order, which differs between machines; a stable sweep reports stably. */
        sort($found);

        return $found;
    }
}
