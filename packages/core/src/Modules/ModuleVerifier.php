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
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\ScopeResolver;
use Kitsune\Core\Tenancy\Scopes\OrgMembershipScope;
use Kitsune\Core\Tenancy\Scopes\OrgScope;
use Kitsune\Core\Tenancy\Scopes\SiteScope;
use Kitsune\Core\Tenancy\UndeclaredScopeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;

/**
 * Checks a module's scoping declaration against the models it actually ships (ADR-038).
 *
 * ⚠️ IT ASKS THE MODEL WHAT IT WILL DO, NOT WHAT IT SAYS. Two questions — the attribute and the trait — were
 * the first design, and an attack pass broke it in one line: a model may `use EnforcesScope` and then override
 * `bootEnforcesScope()` with an empty body. A class-body method beats a trait method in PHP, so the attribute
 * is present, `class_uses_recursive()` reports the trait, both questions answer yes, and no global scope is
 * ever registered. Measured at the SQL level: the honest control emitted `… 1 = 0` with no org context, the
 * inert model emitted a bare `select * from …` across every org's rows. So the third and decisive question is
 * asked of a BOOTED INSTANCE — `getGlobalScopes()` — which is the same discipline as the rest of this
 * codebase: ask the database-facing object, not the label.
 *
 * ⚠️ AND IT READS EACH FILE BEFORE IT LOADS IT. The sweep used to resolve a class name from a path and call
 * `class_exists()`, which made four separate holes: a second class declared in the same file was never named
 * or examined (the verifier's own autoload defined it), a file that defines nothing its path promises could
 * not be told from one that defines something else, verifying twice in a process fatalled on a redeclared
 * symbol, and — worst — the module's code RAN before anything had decided to trust it. Tokenising first closes
 * all four: the symbols a file declares are read without executing it, and only a file whose declarations are
 * exactly what PSR-4 promises is loaded at all.
 *
 * ⚠️ A SWEEP THAT EXAMINED NOTHING IS A REFUSAL. `examined` was documented as existing so that a refusal could
 * be told from a walk that never ran — and nothing consulted it, so five manifest shapes passed having looked
 * at zero classes. The floor is enforced here now rather than asserted in a test.
 *
 * **What it does not check.** It cannot tell `unscoped:global` from `unscoped:through(M)` by looking at a
 * model: both are `#[Unscoped]`, and the difference is whether the TABLE carries a scope column, which is only
 * observable once migrations have run. That half belongs to install. Declaring both at once is refused rather
 * than papered over, because the reverse-direction check cannot tell which model satisfies which.
 *
 * Filesystem and reflection only: no database, no Composer runtime call, so it holds on a bare clone
 * (AGENTS.md §11).
 */
final class ModuleVerifier
{
    /** The global scope each declaration must actually produce, or null when the declaration is "none". */
    private const SCOPE_FOR_ATTRIBUTE = [
        SiteScoped::class => SiteScope::class,
        OrgScoped::class => OrgScope::class,
        OrgScopedThroughPivot::class => OrgMembershipScope::class,
        Unscoped::class => null,
    ];

    public static function verify(ModuleManifest $manifest, string $installPath): ModuleVerification
    {
        $base = realpath($installPath);

        if ($base === false) {
            return new ModuleVerification("{$manifest->package} is not installed at a path that exists.", [], []);
        }

        $examined = [];
        $models = [];

        foreach ($manifest->psr4 as $namespace => $directories) {
            foreach ($directories as $directory) {
                $relative = trim($directory, '/');
                $root = realpath(in_array($relative, ['', '.'], true) ? $base : $base.'/'.$relative);

                if ($root === false || ! is_dir($root)) {
                    return new ModuleVerification(
                        "{$manifest->package} maps `{$namespace}` to `{$directory}`, which is not a directory in "
                        .'the installed package. The sweep would have examined nothing, which is not the same as '
                        .'finding nothing wrong.',
                        $examined,
                        $models,
                    );
                }

                /* A symlink may resolve anywhere; the sweep only ever accounts for this package's own files. */
                if ($root !== $base && ! str_starts_with($root.DIRECTORY_SEPARATOR, $base.DIRECTORY_SEPARATOR)) {
                    return new ModuleVerification(
                        "{$manifest->package} maps `{$namespace}` to `{$directory}`, which resolves outside the "
                        .'installed package. A sweep of somebody else\'s classes reports nothing about this one.',
                        $examined,
                        $models,
                    );
                }

                foreach (self::filesIn($root) as $file) {
                    $promised = self::promisedClass($root, $file, (string) $namespace);
                    $declared = self::declaredSymbolsIn($file);

                    /*
                     * A file declaring no named symbol is not a class file — a migration returning an anonymous
                     * class is the ordinary case — so it is skipped rather than refused. PSR-4 promises nothing
                     * about it either, and Composer will never load it by name.
                     */
                    if ($declared === []) {
                        continue;
                    }

                    if ($promised === null || $declared !== [$promised]) {
                        return new ModuleVerification(
                            sprintf(
                                '%s ships `%s`, which declares %s. PSR-4 says that file defines %s and nothing '
                                .'else, and the sweep can only account for what it can name.',
                                $manifest->package,
                                substr($file, strlen($base) + 1),
                                implode(', ', $declared),
                                $promised ?? 'no class reachable by PSR-4',
                            ),
                            $examined,
                            $models,
                        );
                    }

                    $examined[] = $promised;

                    if (! self::isConcreteModel($promised)) {
                        continue;
                    }

                    $models[] = $promised;
                }
            }
        }

        if ($examined === []) {
            return new ModuleVerification(
                "{$manifest->package}'s sweep examined no classes at all. A pass here would say only that "
                .'nothing was looked at.',
                $examined,
                $models,
            );
        }

        return new ModuleVerification(self::refusalFor($manifest, $models), $examined, $models);
    }

    /**
     * @param  list<class-string>  $models
     */
    private static function refusalFor(ModuleManifest $manifest, array $models): ?string
    {
        /*
         * The memo is keyed by class name and filled by reflection, and the sweep has just loaded classes this
         * module wrote. Flushing costs one reflection per model and means the answer read here is the one this
         * process derived, not one that was already sitting there.
         */
        ScopeResolver::flush();

        $present = [];

        foreach ($models as $model) {
            try {
                $attribute = ScopeResolver::for($model);
            } catch (UndeclaredScopeException) {
                return "{$manifest->package} ships the model `{$model}`, which declares no scope. AGENTS.md §2: "
                    .'every model declares exactly one of #[SiteScoped], #[OrgScoped], #[OrgScopedThroughPivot] '
                    .'or #[Unscoped].';
            }

            if (! in_array(EnforcesScope::class, class_uses_recursive($model), true)) {
                return "{$manifest->package}'s model `{$model}` carries a scope attribute and does not "
                    .'`use EnforcesScope`, so the declaration is a comment with syntax and the model is '
                    .'unconstrained. AGENTS.md §2.';
            }

            if ($manifest->scopesFor($attribute) === []) {
                return "{$manifest->package} ships `{$model}`, scoped by `{$attribute}`, which its manifest does "
                    .'not declare. The declaration and the models must agree.';
            }

            $refusal = self::refusalForRegisteredScopes($manifest->package, $model, $attribute);

            if ($refusal !== null) {
                return $refusal;
            }

            $present[$attribute] = true;
        }

        foreach ($manifest->scoping as $scope) {
            $attribute = ModuleManifest::attributeFor($scope);

            if (! isset($present[$attribute])) {
                return "{$manifest->package} declares the scope `{$scope}` and ships no model that uses it. A "
                    .'declaration nothing exercises is a claim rather than a fact.';
            }

            $through = ModuleManifest::throughModel($scope);

            if ($through !== null && ! self::isConcreteModel($through)) {
                return "{$manifest->package} declares `{$scope}`, and `{$through}` is not a model that exists.";
            }
        }

        /*
         * ⚠️ BOTH UNSCOPED FORMS AT ONCE CANNOT BE CHECKED, SO THEY ARE REFUSED. They produce the same
         * attribute, so the reverse-direction check cannot say which shipped model satisfies which declaration
         * — one `#[Unscoped]` model would satisfy both, including the one naming a model it has nothing to do
         * with. Refusing the combination keeps the check honest until install can compare the tables.
         */
        $unscopedForms = array_values(array_filter(
            $manifest->scoping,
            static fn (string $scope): bool => ModuleManifest::attributeFor($scope) === Unscoped::class,
        ));

        if (count($unscopedForms) > 1) {
            return "{$manifest->package} declares more than one unscoped form (".implode(', ', $unscopedForms)
                .'). They resolve to the same attribute, so nothing here can say which model satisfies which.';
        }

        return null;
    }

    /**
     * The decisive question, asked of an instance rather than of the source.
     *
     * @param  class-string  $model
     */
    private static function refusalForRegisteredScopes(string $package, string $model, string $attribute): ?string
    {
        $expected = self::SCOPE_FOR_ATTRIBUTE[$attribute] ?? null;

        try {
            $registered = array_keys((new $model)->getGlobalScopes());
        } catch (Throwable $e) {
            return "{$package}'s model `{$model}` could not be instantiated to check the scopes it registers: "
                .$e->getMessage();
        }

        if ($expected !== null && ! in_array($expected, $registered, true)) {
            return "{$package}'s model `{$model}` declares `{$attribute}` and does not register `{$expected}` "
                .'when it boots, so it is unconstrained whatever its attribute and trait say. Overriding '
                .'`bootEnforcesScope()` does exactly this.';
        }

        /* An unscoped model that enforces a scope is a different lie, and the same question catches it. */
        $kitsuneScopes = array_filter(array_values(self::SCOPE_FOR_ATTRIBUTE));

        if ($expected === null && array_intersect($kitsuneScopes, $registered) !== []) {
            return "{$package}'s model `{$model}` declares `{$attribute}` and registers a scope anyway.";
        }

        return null;
    }

    /** @param class-string|string $class */
    private static function isConcreteModel(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return false;
        }

        /*
         * Abstract bases are skipped rather than refused: PHP attributes are not inherited, so a concrete
         * subclass gets no declaration from its parent and is swept on its own terms.
         */
        return ! (new ReflectionClass($class))->isAbstract();
    }

    /**
     * Every `.php` file under a root, following symlinks without looping.
     *
     * @return list<string>
     */
    private static function filesIn(string $root): array
    {
        $found = [];
        $seen = [];

        /** @var iterable<string, SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(
            /* A symlinked subdirectory is yielded as a leaf and never descended unless this is set — while
             * PSR-4 autoloading resolves straight through it, so the sweep missed classes Composer loads. */
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        );

        foreach ($files as $path => $file) {
            /* `.PHP` is skipped by a case-sensitive compare and loaded by Composer on a case-insensitive disk. */
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $real = realpath($path);

            if ($real === false || isset($seen[$real])) {
                continue;
            }

            $seen[$real] = true;
            $found[] = $path;
        }

        /* Directory order is filesystem order, which differs between machines; a stable sweep reports stably. */
        sort($found);

        return $found;
    }

    /** The class name PSR-4 promises for a file, or null when the path cannot name one. */
    private static function promisedClass(string $root, string $file, string $namespace): ?string
    {
        $relative = substr($file, strlen($root) + 1, -4);
        $segments = explode('/', $relative);

        foreach ($segments as $segment) {
            if (preg_match('/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/', $segment) !== 1) {
                return null;
            }
        }

        return rtrim($namespace, '\\').'\\'.implode('\\', $segments);
    }

    /**
     * The named classes, interfaces, traits and enums a file declares — read, not executed.
     *
     * @return list<string>
     */
    private static function declaredSymbolsIn(string $file): array
    {
        $source = @file_get_contents($file);

        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $symbols = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }

                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $tokens[$j][1];
                    }
                }

                $namespace = trim($namespace);

                continue;
            }

            if (! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            /* `Thing::class` is a T_CLASS too, and `new class` is anonymous: neither declares a name here. */
            $previous = self::previousMeaningful($tokens, $i);

            if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $symbols[] = ($namespace === '' ? '' : $namespace.'\\').$tokens[$j][1];
                    break;
                }

                if (! is_array($tokens[$j]) && in_array($tokens[$j], ['(', '{'], true)) {
                    break;
                }
            }
        }

        return $symbols;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function previousMeaningful(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }
}
