<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $root = dirname(__DIR__);
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $resolve = new NodeTraverser(new NameResolver);
    $paths = extensionPhpPaths(glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: []);

    $symbols = [];
    $commands = [];
    foreach ($paths as $path) {
        $relative = substr($path, strlen($root) + 1);
        $tree = $resolve->traverse($parser->parse(extensionSource($path)) ?? []);
        foreach ($finder->findInstanceOf($tree, Node\Stmt\ClassLike::class) as $class) {
            if ($class->namespacedName === null) {
                continue;
            }

            $name = $class->namespacedName->toString();
            $members = [];
            $internal = extensionInternal($class);
            foreach ($class->stmts as $statement) {
                if ($statement instanceof Node\Stmt\ClassMethod && ! $statement->isPrivate()) {
                    $members[] = extensionMethod($statement, $internal);
                } elseif ($statement instanceof Node\Stmt\Property) {
                    foreach ($statement->props as $property) {
                        if ($property->name->toString() === 'signature' && $property->default instanceof Node\Scalar\String_) {
                            $commands[] = ['owner' => $name, 'path' => $relative, 'signature' => $property->default->value];
                        }
                        if (! $statement->isPrivate()) {
                            $members[] = extensionMember('property', $property->name->toString(), $statement, $statement->isProtected(), $internal);
                        }
                    }
                } elseif ($statement instanceof Node\Stmt\ClassConst && ! $statement->isPrivate()) {
                    foreach ($statement->consts as $constant) {
                        $members[] = extensionMember('constant', $constant->name->toString(), $statement, $statement->isProtected(), $internal);
                    }
                } elseif ($statement instanceof Node\Stmt\EnumCase) {
                    $members[] = extensionMember('enum_case', $statement->name->toString(), $statement, false, $internal);
                }
            }

            $symbols[$name] = [
                'path' => $relative,
                'line' => $class->getStartLine(),
                'kind' => match (true) {
                    $class instanceof Node\Stmt\Interface_ => 'interface',
                    $class instanceof Node\Stmt\Trait_ => 'trait',
                    $class instanceof Node\Stmt\Enum_ => 'enum',
                    default => 'class',
                },
                'classification' => $internal ? 'internal' : 'unclassified',
                'members' => $members,
            ];
        }
    }

    $configuration = [];
    foreach (glob($root.'/packages/*/config/*.php') ?: [] as $path) {
        foreach ($parser->parse(extensionSource($path)) ?? [] as $statement) {
            if ($statement instanceof Node\Stmt\Return_ && $statement->expr instanceof Node\Expr\Array_) {
                extensionConfigKeys($statement->expr, pathinfo($path, PATHINFO_FILENAME), $configuration);
            }
        }
    }

    $imports = [];
    $hostPaths = extensionPhpPaths([$root.'/skeleton/app', $root.'/skeleton/bootstrap', $root.'/skeleton/routes', $root.'/skeleton/public']);
    foreach ([...$paths, ...$hostPaths] as $path) {
        $relative = substr($path, strlen($root) + 1);
        if (str_starts_with($relative, 'packages/core/')) {
            continue;
        }

        $source = $parser->parse(extensionSource($path)) ?? [];
        $declarations = [
            ...$finder->findInstanceOf($source, Node\Stmt\Use_::class),
            ...$finder->findInstanceOf($source, Node\Stmt\GroupUse::class),
        ];
        foreach ($declarations as $statement) {
            $prefix = $statement instanceof Node\Stmt\GroupUse ? $statement->prefix->toString().'\\' : '';
            foreach ($statement->uses as $use) {
                $symbol = $prefix.$use->name->toString();
                if (str_starts_with($symbol, 'Kitsune\\Core\\')) {
                    $imports[] = [
                        'symbol' => $symbol, 'path' => $relative, 'line' => $use->getStartLine(),
                        'classification' => $symbols[$symbol]['classification'] ?? 'unclassified',
                    ];
                }
            }
        }
    }

    ksort($symbols);
    sort($configuration);
    echo json_encode([
        'symbols' => $symbols, 'commands' => $commands, 'configuration' => $configuration,
        'core_imports' => $imports,
    ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Extension inventory refused: '.$error->getMessage().PHP_EOL);
    exit(1);
}

/**
 * @param  list<string>  $directories
 * @return list<string>
 */
function extensionPhpPaths(array $directories): array
{
    $paths = [];
    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
    }

    sort($paths);

    return $paths;
}

function extensionSource(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Could not read '.$path.'.');
    }

    return $source;
}

function extensionInternal(Node $node): bool
{
    return preg_match('/(?:^\/\*\*|^\h*\*)\h*@internal(?=\s|\*\/|$)/m', $node->getDocComment()?->getText() ?? '') === 1;
}

/** @return array{kind:string, name:string, line:int, visibility:string, classification:string} */
function extensionMember(string $kind, string $name, Node $node, bool $protected, bool $internal): array
{
    return ['kind' => $kind, 'name' => $name, 'line' => $node->getStartLine(),
        'visibility' => $protected ? 'protected' : 'public',
        'classification' => $internal || extensionInternal($node) ? 'internal' : 'unclassified'];
}

/**
 * @return array{kind:string, name:string, line:int, visibility:string, classification:string, parameters:list<string>, promoted:list<array{kind:string, name:string, line:int, visibility:string, classification:string}>}
 */
function extensionMethod(Node\Stmt\ClassMethod $method, bool $internal): array
{
    $parameters = [];
    $promoted = [];
    foreach ($method->params as $param) {
        if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
            $parameters[] = $param->var->name;
            if ($param->isPromoted() && ! $param->isPrivate()) {
                $promoted[] = extensionMember('property', $param->var->name, $param, $param->isProtected(), $internal);
            }
        }
    }

    return extensionMember('method', $method->name->toString(), $method, $method->isProtected(), $internal)
        + ['parameters' => $parameters, 'promoted' => $promoted];
}

/** @param list<string> $keys */
function extensionConfigKeys(Node\Expr\Array_ $array, string $prefix, array &$keys): void
{
    foreach ($array->items as $item) {
        if (! $item->key instanceof Node\Scalar\String_) {
            continue;
        }

        $key = $prefix.'.'.$item->key->value;
        $keys[] = $key;
        if ($item->value instanceof Node\Expr\Array_) {
            extensionConfigKeys($item->value, $key, $keys);
        }
    }
}
