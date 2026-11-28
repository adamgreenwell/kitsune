<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Modules\ModuleManifest;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

$arguments = $argv ?? [];
$strict = ($arguments[1] ?? null) === '--strict';
$directory = $arguments[$strict ? 2 : 1] ?? null;
if ($directory === null || str_starts_with($directory, '--') || count($arguments) !== ($strict ? 3 : 2)) {
    fwrite(STDERR, "Usage: php bin/check-plugin.php [--strict] PACKAGE_DIRECTORY\n");
    exit(2);
}

$root = realpath($directory);
$composerPath = $root === false ? false : realpath($root.'/composer.json');
if ($root === false || $composerPath !== $root.'/composer.json' || ! is_file($composerPath)) {
    fwrite(STDERR, "Expected a local Composer package.\n");
    exit(2);
}

$checkout = dirname(__DIR__);
$parserRoot = realpath($checkout.'/vendor/nikic/php-parser/lib/PhpParser');
if ($parserRoot === false) {
    fwrite(STDERR, "Install Kitsune's development dependencies before scanning a package.\n");
    exit(2);
}
if (containsDirectory($root, $checkout) || containsDirectory($root, $parserRoot)) {
    fwrite(STDERR, "Run from a trusted development checkout separate from the package.\n");
    exit(2);
}

// Load only the trusted parser. A Composer autoloader can run package-level files at startup.
spl_autoload_register(static function (string $class) use ($parserRoot): void {
    $prefix = 'PhpParser\\';
    if (str_starts_with($class, $prefix)) {
        $file = $parserRoot.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
require $checkout.'/packages/core/src/Modules/ModuleManifest.php';

$finder = new NodeFinder;
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$resolveNames = new NodeTraverser(new NameResolver, new ParentConnectingVisitor);
$issues = [];
$review = [];
$classes = [];
$traits = [];
$declaredSymbols = [];
$rawTableCalls = [];
$scopedTables = [];

try {
    $composer = json_decode(file_get_contents($composerPath) ?: '', true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($composer) || ! is_string($composer['name'] ?? null)) {
        throw new RuntimeException('Expected a named Composer package.');
    }

    $manifest = ModuleManifest::from($composer, $composer['name']);
} catch (JsonException|RuntimeException $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(2);
}

$files = [];
try {
    foreach ($manifest->psr4 as $namespace => $locations) {
        foreach ($locations as $directory) {
            $source = realpath($root.'/'.$directory);
            if ($source === false || ! is_dir($source) || ! containsDirectory($root, $source)) {
                throw new RuntimeException('A PSR-4 root lies outside the package or is absent.');
            }

            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($items as $item) {
                $file = $item->getPathname();
                $relative = substr($file, strlen($root) + 1);
                if ($item->isLink()) {
                    $issues[] = ['file' => $relative, 'line' => 0, 'rule' => 'linked-source'];
                } elseif ($item->isFile() && strcasecmp($item->getExtension(), 'php') === 0) {
                    if ($item->getExtension() !== 'php') {
                        $issues[] = ['file' => $relative, 'line' => 0, 'rule' => 'nonportable-source-extension'];
                    }
                    $localName = substr($file, strlen($source) + 1, -4);
                    $files[$file]['relative'] = $relative;
                    $files[$file]['expected'][] = $namespace.str_replace(DIRECTORY_SEPARATOR, '\\', $localName);
                }
            }
        }
    }
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(2);
}
if ($files === []) {
    fwrite(STDERR, "No PHP source found under the declared roots.\n");
    exit(2);
}
ksort($files);

$report = static fn (string $file, Node $node, string $rule): array => ['file' => $file, 'line' => $node->getStartLine(), 'rule' => $rule];
foreach ($files as $file => $mapped) {
    $relative = $mapped['relative'];
    $source = file_get_contents($file);
    if ($source === false) {
        $issues[] = ['file' => $relative, 'line' => 0, 'rule' => 'unreadable-source'];

        continue;
    }

    try {
        $tree = $resolveNames->traverse($parser->parse($source) ?? []);
    } catch (Error $error) {
        $issues[] = ['file' => $relative, 'line' => $error->getStartLine(), 'rule' => 'parse-error'];

        continue;
    }

    $symbols = $finder->findInstanceOf($tree, Node\Stmt\ClassLike::class);
    $named = array_values(array_filter($symbols, static fn (Node\Stmt\ClassLike $symbol): bool => $symbol->name !== null));
    if (count($named) > 1) {
        $issues[] = $report($relative, $named[0], 'multiple-source-symbols');
    }
    foreach ($symbols as $symbol) {
        $qualified = isset($symbol->namespacedName) ? $symbol->namespacedName->toString() : null;
        $name = $qualified === null ? $relative.':'.$symbol->getStartLine() : strtolower($qualified);
        if ($qualified !== null) {
            if (! in_array($qualified, $mapped['expected'], true)) {
                $issues[] = $report($relative, $symbol, 'source-symbol-outside-psr4');
            }
            if (isset($declaredSymbols[$name])) {
                $issues[] = $report($relative, $symbol, 'duplicate-source-symbol');

                continue;
            }
            $declaredSymbols[$name] = true;
        }
        $uses = [];
        foreach ($symbol->getTraitUses() as $use) {
            array_push($uses, ...array_map(static fn (Node\Name $trait): string => strtolower($trait->toString()), $use->traits));
            if ($use->adaptations !== []) {
                $review[] = $report($relative, $use, 'review-trait-adaptation');
            }
        }
        if ($symbol instanceof Node\Stmt\Trait_) {
            $traits[$name] = $uses;
        }
        if ($symbol instanceof Node\Stmt\Class_) {
            $classes[$name] = ['file' => $relative, 'node' => $symbol, 'parent' => strtolower($symbol->extends?->toString() ?? ''), 'traits' => $uses];
        }
    }

    foreach ($finder->findInstanceOf($tree, Node\Expr::class) as $node) {
        $call = $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\NullsafeMethodCall;
        $method = $call && $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : '';
        $target = $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name ? strtolower($node->class->toString()) : '';
        if (in_array($method, ['withoutglobalscope', 'withoutglobalscopes', 'withoutglobalscopesexcept', 'newquerywithoutscope', 'newquerywithoutscopes'], true)) {
            $issues[] = $report($relative, $node, 'raw-scope-removal');
        }
        if ($node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Eval_ || ($call && $method === '')) {
            $review[] = $report($relative, $node, 'review-dynamic-source');
        }
        if (in_array($method, ['withoutscopebecause', 'newmodelquery', 'getquery'], true)
            || in_array($target, ['db', 'illuminate\\support\\facades\\db'], true)) {
            $review[] = $report($relative, $node, 'review-query-boundary');
        }
        if ($node instanceof Node\Expr\MethodCall) {
            $rawTableCalls[] = [$relative, $node];
        }
        $ruleConstructor = $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name
            && in_array(strtolower($node->class->toString()), ['illuminate\\validation\\rules\\unique', 'illuminate\\validation\\rules\\exists'], true);
        $possibleRuleString = $node instanceof Node\Scalar\String_ && preg_match('/(?:^|\|)\s*(?:unique|exists)\s*:/i', $node->value) === 1;
        $bareRuleString = $possibleRuleString && hasValidationRuleContext($node);
        if ($possibleRuleString && ! $bareRuleString && $node->getAttribute('parent') instanceof Node\Expr\Assign) {
            $review[] = $report($relative, $node, 'review-indirect-validation-rule');
        }
        $ruleMethod = in_array($method, ['unique', 'exists'], true);
        $filamentRule = false;
        if ($ruleMethod && $node instanceof Node\Expr\MethodCall) {
            $field = $node->var;
            while ($field instanceof Node\Expr\MethodCall) {
                $field = $field->var;
            }
            $filamentRule = $field instanceof Node\Expr\StaticCall && $field->class instanceof Node\Name
                && str_starts_with(strtolower($field->class->toString()), 'filament\\forms\\components\\');
        }
        if ($ruleConstructor || $bareRuleString || $filamentRule || ($ruleMethod && $target === 'illuminate\\validation\\rule')) {
            $issues[] = $report($relative, $node, 'bare-validation-rule');
        }
    }
}

$knownModels = ['illuminate\\database\\eloquent\\model', 'illuminate\\foundation\\auth\\user', 'illuminate\\database\\eloquent\\relations\\pivot', 'illuminate\\database\\eloquent\\relations\\morphpivot'];
$knownNonModels = ['illuminate\\foundation\\http\\formrequest', 'illuminate\\support\\serviceprovider', 'kitsune\\core\\modules\\moduleserviceprovider'];
$attributeNames = [];
foreach ([SiteScoped::class, OrgScoped::class, OrgScopedThroughPivot::class, Unscoped::class] as $attribute) {
    $attributeNames[strtolower($attribute)] = $attribute;
}
$enforcement = 'kitsune\\core\\tenancy\\concerns\\enforcesscope';

foreach ($classes as $class) {
    $parents = [];
    $parent = $class['parent'];
    while (! in_array($parent, $knownModels, true) && isset($classes[$parent]) && ! isset($parents[$parent])) {
        $parents[$parent] = $classes[$parent];
        $parent = $classes[$parent]['parent'];
    }
    if (! in_array($parent, $knownModels, true)) {
        if ($parent !== '' && ! in_array($parent, $knownNonModels, true)) {
            $review[] = $report($class['file'], $class['node'], 'review-external-parent') + ['symbol' => $parent];
        }

        continue;
    }
    foreach ($class['node']->getMethods() as $method) {
        if (in_array(strtolower($method->name->toString()), ['boot', 'bootenforcesscope', 'initializeenforcesscope', 'newquery', 'newmodelquery', 'neweloquentbuilder'], true)) {
            $review[] = $report($class['file'], $method, 'review-model-boot-or-query') + ['symbol' => $method->name->toString()];
        }
    }
    if ($class['node']->isAbstract()) {
        continue;
    }
    $attributes = [];
    foreach ($class['node']->attrGroups as $group) {
        foreach ($group->attrs as $attribute) {
            if (isset($attributeNames[strtolower($attribute->name->toString())])) {
                $attributes[] = $attribute;
                if (strtolower($attribute->name->toString()) === 'kitsune\\core\\tenancy\\attributes\\unscoped') {
                    $review[] = $report($class['file'], $attribute, 'review-unscoped-model');
                }
            }
        }
    }
    if (count($attributes) === 1) {
        $scope = $attributeNames[strtolower($attributes[0]->name->toString())];
        if ($manifest->scopesFor($scope) === []) {
            $issues[] = $report($class['file'], $attributes[0], 'model-scope-outside-manifest');
        }

        $key = match ($scope) {
            SiteScoped::class => 'site_id',
            OrgScoped::class => 'org_id',
            default => null,
        };
        if ($key !== null) {
            foreach ([$class, ...array_values($parents)] as $definition) {
                $property = $definition['node']->getProperty('table');
                if ($property === null) {
                    continue;
                }
                foreach ($property->props as $declared) {
                    if ($declared->name->toString() === 'table' && $declared->default instanceof Node\Scalar\String_) {
                        $scopedTables[$declared->default->value][$key] = true;
                    }
                }
                break;
            }
        }
    }

    if (count($attributes) !== 1) {
        $issues[] = $report($class['file'], $class['node'], 'one-model-scope') + ['declarations' => count($attributes)];
    }
    $pending = array_merge($class['traits'], ...array_column($parents, 'traits'));
    $found = [];
    while ($pending !== []) {
        $trait = array_pop($pending);
        if (! isset($found[$trait])) {
            $found[$trait] = true;
            array_push($pending, ...($traits[$trait] ?? []));
        }
    }
    if (! isset($found[$enforcement])) {
        $issues[] = $report($class['file'], $class['node'], 'missing-model-enforcement');
    }
}

$terminals = ['count', 'exists', 'first', 'get', 'paginate', 'pluck', 'value'];
foreach ($rawTableCalls as [$file, $call]) {
    if (! $call->name instanceof Node\Identifier || ! in_array(strtolower($call->name->toString()), $terminals, true)) {
        continue;
    }

    $cursor = $call->var;
    $predicates = [];
    while ($cursor instanceof Node\Expr\MethodCall && $cursor->name instanceof Node\Identifier) {
        $method = strtolower($cursor->name->toString());
        if ($method === 'where') {
            $field = $cursor->args[0] ?? null;
            if (! $field instanceof Node\Arg || ! $field->value instanceof Node\Scalar\String_) {
                continue 2;
            }

            $predicates[] = $field->value->value;
        } elseif (! in_array($method, ['select', 'orderby', 'limit', 'offset'], true)) {
            continue 2;
        }

        $cursor = $cursor->var;
    }
    if (! $cursor instanceof Node\Expr\StaticCall || ! $cursor->class instanceof Node\Name
        || ! in_array(strtolower($cursor->class->toString()), ['db', 'illuminate\\support\\facades\\db'], true)
        || ! $cursor->name instanceof Node\Identifier || strtolower($cursor->name->toString()) !== 'table') {
        continue;
    }
    $table = $cursor->args[0] ?? null;
    if (! $table instanceof Node\Arg || ! $table->value instanceof Node\Scalar\String_) {
        continue;
    }

    $name = $table->value->value;
    $keys = array_keys($scopedTables[$name] ?? []);
    $scopeFields = [...$keys, ...array_map(static fn (string $key): string => $name.'.'.$key, $keys)];
    if ($keys !== [] && array_intersect($predicates, $scopeFields) === []) {
        $issues[] = $report($file, $call, 'unscoped-raw-table-read') + ['table' => $name];
    }
}

function containsDirectory(string $directory, string $path): bool
{
    $identity = @stat($directory);
    while ($identity !== false) {
        $candidate = @stat($path);
        if ($candidate !== false && $candidate['dev'] === $identity['dev'] && $candidate['ino'] === $identity['ino']) {
            return true;
        }

        $parent = dirname($path);
        if ($parent === $path) {
            break;
        }
        $path = $parent;
    }

    return false;
}

function hasValidationRuleContext(Node\Scalar\String_ $value): bool
{
    $node = $value;
    $parent = $node->getAttribute('parent');

    // Rule literals can be grouped in field-keyed arrays or nested rule lists.
    while ($parent instanceof Node\Expr\Array_ || ($parent instanceof Node\ArrayItem && $parent->value === $node)) {
        $node = $parent;
        $parent = $node->getAttribute('parent');
    }

    if ($parent instanceof Node\Stmt\Return_) {
        $owner = $parent->getAttribute('parent');
        while ($owner instanceof Node && ! $owner instanceof Node\FunctionLike) {
            $owner = $owner->getAttribute('parent');
        }

        return $owner instanceof Node\Stmt\ClassMethod && strtolower($owner->name->toString()) === 'rules';
    }

    if (! $parent instanceof Node\Arg) {
        return false;
    }

    $call = $parent->getAttribute('parent');
    if ((! $call instanceof Node\Expr\MethodCall && ! $call instanceof Node\Expr\StaticCall)
        || ! $call->name instanceof Node\Identifier) {
        return false;
    }

    $method = strtolower($call->name->toString());
    $firstRuleArgument = match ($method) {
        'rules', 'validate' => 0,
        'validatewithbag' => 1,
        'make' => $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name
            && strtolower($call->class->toString()) === 'illuminate\\support\\facades\\validator' ? 1 : null,
        default => null,
    };

    return $firstRuleArgument !== null
        && ($parent->name === null ? ($call->args[$firstRuleArgument] ?? null) === $parent : $parent->name->toString() === 'rules');
}

echo json_encode(['files' => count($files), 'issues' => $issues, 'review' => $review], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit(match (true) {
    $issues !== [] => 1,
    $strict && $review !== [] => 3,
    default => 0,
});
