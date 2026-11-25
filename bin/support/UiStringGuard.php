<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Build-only guard for the UI entry points named in #106.
 *
 * Follows returned expressions, not selector conditions or translation keys.
 * Runtime labels remain Org/Site content. This is not whole-program taint
 * analysis: a helper that returns bare copy needs its own translation test.
 */
final class UiStringGuard
{
    public const METHODS = [
        'label', 'heading', 'description', 'placeholder', 'helperText',
        'title', 'modalHeading', 'emptyStateHeading',
    ];

    /** @return list<array{line: int, method: string, value: string}> */
    public static function literals(string $source): array
    {
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $calls = (new NodeFinder)->find($nodes, static fn (Node $n): bool => $n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall);
        $found = [];

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier || ! in_array($call->name->toString(), self::METHODS, true) || ! isset($call->args[0]) || ! $call->args[0] instanceof Node\Arg) {
                continue;
            }

            foreach (self::rendered($call->args[0]->value) as $literal) {
                $found[] = ['line' => $literal->getStartLine(), 'method' => $call->name->toString(), 'value' => $literal->value];
            }
        }

        return $found;
    }

    /** @return list<Scalar\String_> */
    private static function rendered(Expr $value): array
    {
        if ($value instanceof Scalar\String_) {
            return [$value];
        }

        if ($value instanceof Scalar\InterpolatedString) {
            $copy = implode('', array_map(static fn (Node $part): string => $part instanceof Node\InterpolatedStringPart ? $part->value : '', $value->parts));

            return $copy === '' ? [] : [new Scalar\String_($copy, $value->getAttributes())];
        }

        if ($value instanceof Expr\ArrowFunction) {
            return self::rendered($value->expr);
        }

        if ($value instanceof Expr\Closure) {
            $result = [];
            foreach ((new NodeFinder)->findInstanceOf($value->stmts, Node\Stmt\Return_::class) as $return) {
                if ($return->expr !== null) {
                    array_push($result, ...self::rendered($return->expr));
                }
            }

            return $result;
        }

        if ($value instanceof Expr\Ternary) {
            return [...self::rendered($value->if ?? $value->cond), ...self::rendered($value->else)];
        }

        if ($value instanceof Expr\Match_) {
            return array_merge([], ...array_map(static fn (Node\MatchArm $arm): array => self::rendered($arm->body), $value->arms));
        }

        if ($value instanceof Expr\BinaryOp\Concat || $value instanceof Expr\BinaryOp\Coalesce) {
            return [...self::rendered($value->left), ...self::rendered($value->right)];
        }

        if ($value instanceof Expr\Cast\String_) {
            return self::rendered($value->expr);
        }

        if ($value instanceof Expr\New_ && $value->class instanceof Node\Name && in_array($value->class->toString(), ['HtmlString', 'Illuminate\\Support\\HtmlString'], true) && isset($value->args[0]) && $value->args[0] instanceof Node\Arg) {
            return self::rendered($value->args[0]->value);
        }

        // These wrappers preserve rendered text. Translation calls deliberately
        // stop traversal: their arguments are keys, replacement names and enums.
        if ($value instanceof Expr\FuncCall && $value->name instanceof Node\Name && in_array(strtolower($value->name->toString()), ['e', 'sprintf', 'trim', 'ucfirst', 'strtoupper', 'strtolower'], true) && isset($value->args[0]) && $value->args[0] instanceof Node\Arg) {
            return self::rendered($value->args[0]->value);
        }

        return [];
    }

    /** @return list<array{file: string, line: int, method: string, value: string}> */
    public static function scan(string $directory): array
    {
        $root = dirname(__DIR__, 2);
        $exemptions = require __DIR__.'/ui-string-exemptions.php';
        $used = [];
        $violations = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink() || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            foreach (self::literals(file_get_contents($file->getPathname())) as $literal) {
                $key = $relative.'|'.$literal['method'].'|'.$literal['value'];
                if (isset($exemptions[$key])) {
                    $used[$key] = true;

                    continue;
                }

                $violations[] = ['file' => $relative, ...$literal];
            }
        }

        foreach ($exemptions as $key => $reason) {
            if (! is_string($reason) || trim($reason) === '' || ! isset($used[$key])) {
                throw new LogicException('Unused or unexplained UI string exemption: '.$key);
            }
        }

        return $violations;
    }
}
