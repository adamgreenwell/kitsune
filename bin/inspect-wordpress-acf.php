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
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

function acfLiteral(Node\Expr $node): mixed
{
    return match (true) {
        $node instanceof Node\Scalar\String_ => preg_match('//u', $node->value) === 1
            ? $node->value : throw new RuntimeException('A registration includes invalid UTF-8.'),
        $node instanceof Node\Scalar\Int_, $node instanceof Node\Scalar\Float_ => $node->value,
        $node instanceof Node\Expr\Array_ => acfLiteralArray($node),
        $node instanceof Node\Expr\ConstFetch => match (strtolower($node->name->toString())) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => throw new RuntimeException('A registration uses a non-literal value.'),
        },
        default => throw new RuntimeException('A registration uses a non-literal value.'),
    };
}

/** @return array<array-key, mixed> */
function acfLiteralArray(Node\Expr\Array_ $node): array
{
    $values = [];
    foreach ($node->items as $item) {
        if ($item->unpack || $item->byRef) {
            throw new RuntimeException('A registration uses a non-literal value.');
        }
        $value = acfLiteral($item->value);
        if ($item->key === null) {
            $values[] = $value;

            continue;
        }
        $key = acfLiteral($item->key);
        if ((! is_int($key) && ! is_string($key)) || array_key_exists($key, $values)) {
            throw new RuntimeException('A registration has an ambiguous array key.');
        }
        $values[$key] = $value;
    }

    return $values;
}

function acfReadDefinition(string $path): string
{
    if (is_link($path) || ! is_file($path) || filesize($path) > 1_048_576) {
        throw new RuntimeException('A source definition is linked, absent or larger than one MiB.');
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('A source definition could not be read.');
    }

    return $contents;
}

/**
 * json_decode() validates the grammar, then the token scan preserves duplicate
 * members that decoding would otherwise overwrite.
 */
function acfJson(string $bytes): mixed
{
    $value = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    if (preg_match_all('~"(?:[^"\\\\]++|\\\\.)*+"\s*:?|[{}]~s', $bytes, $tokens) === false) {
        throw new RuntimeException('Unable to read the ACF JSON members.');
    }

    $seen = [];
    foreach ($tokens[0] as $token) {
        if ($token === '{') {
            $seen[] = [];

            continue;
        }
        if ($token === '}') {
            array_pop($seen);

            continue;
        }
        if (! str_ends_with($token, ':')) {
            continue;
        }

        $property = json_decode(substr($token, 0, -1), flags: JSON_THROW_ON_ERROR);
        $depth = count($seen) - 1;
        if (! is_string($property) || isset($seen[$depth][$property])) {
            throw new RuntimeException('An ACF JSON object has an ambiguous member.');
        }
        $seen[$depth][$property] = true;
    }

    return $value;
}

function acfCallNamed(mixed $node, string $name, string $argument): bool
{
    return $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
        && strtolower($node->name->toString()) === $name
        && ($node->getArgs()[0]->value ?? null) instanceof Node\Scalar\String_
        && $node->getArgs()[0]->value->value === $argument;
}

function acfKnownRegistrationContext(Node\Expr\FuncCall $call): bool
{
    for ($parent = $call->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
        if ($parent instanceof Node\Stmt\If_ && acfCallNamed($parent->cond, 'function_exists', 'acf_add_local_field_group')) {
            continue;
        }
        if ($parent instanceof Node\Expr\Closure) {
            $argument = $parent->getAttribute('parent');
            $hook = $argument instanceof Node\Arg ? $argument->getAttribute('parent') : null;
            if ($hook instanceof Node\Expr\FuncCall && acfCallNamed($hook, 'add_action', 'acf/init')
                && ($hook->getArgs()[1] ?? null) === $argument) {
                continue;
            }

            return false;
        }
        if (! in_array($parent::class, [
            Node\Stmt\Expression::class, Node\Arg::class, Node\Expr\FuncCall::class, Node\Stmt\Namespace_::class,
        ], true)) {
            return false;
        }
    }

    return true;
}

$arguments = $argv ?? [];
$directory = $arguments[1] ?? null;
$root = $directory === null ? false : realpath($directory);
$checkout = dirname(__DIR__);
$dependencies = realpath($checkout.'/vendor/nikic/php-parser/lib/PhpParser');
if ($root === false || ! is_dir($root) || $dependencies === false
    || $root === $checkout || str_starts_with($checkout, $root.DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Usage: php bin/inspect-wordpress-acf.php SOURCE_DIRECTORY [PHP_FILES...]\n");
    fwrite(STDERR, "Use a separate source directory and install the trusted checkout's Composer dependencies first.\n");
    exit(2);
}
spl_autoload_register(static function (string $class) use ($dependencies): void {
    if (! str_starts_with($class, 'PhpParser\\')) {
        return;
    }
    $file = $dependencies.'/'.str_replace('\\', '/', substr($class, strlen('PhpParser\\'))).'.php';
    if (is_file($file)) {
        require $file;
    }
});

$findings = [];
$inputs = [];
$groups = [];
$groupKeys = [];
$fieldKeys = [];
$targetFields = [];
$problem = static function (string $code, string $origin, array $facts = []) use (&$findings): void {
    $findings[] = ['code' => $code, 'origin' => $origin] + $facts;
};

$register = static function (mixed $raw, string $origin) use (&$groups, &$groupKeys, &$fieldKeys, &$targetFields, $problem): void {
    $key = is_array($raw) ? ($raw['key'] ?? null) : null;
    if (! is_array($raw) || ! is_string($key) || ! str_starts_with($key, 'group_') || ! is_array($raw['fields'] ?? null)) {
        $problem('unsupported-acf-definition', $origin);

        return;
    }
    if (isset($groupKeys[$key])) {
        $problem('duplicate-group-key', $origin, ['group_key' => $key, 'previous_origin' => $groupKeys[$key]]);

        return;
    }
    $groupKeys[$key] = $origin;
    if (! in_array($raw['active'] ?? true, [true, 1], true)) {
        $problem('inactive-or-conditional-group', $origin, ['group_key' => $key]);

        return;
    }

    $types = [];
    $locations = $raw['location'] ?? null;
    if (is_array($locations)) {
        foreach ($locations as $location) {
            $rule = $location[0] ?? null;
            if (! is_array($location) || count($location) !== 1 || ($rule['param'] ?? null) !== 'post_type'
                || ($rule['operator'] ?? null) !== '==' || ! is_string($rule['value'] ?? null)
                || ! preg_match('/^[a-z0-9_-]+$/D', $rule['value'])) {
                $types = [];
                break;
            }
            $types[] = $rule['value'];
        }
    }
    if ($types === []) {
        $problem('unsupported-location-rules', $origin, ['group_key' => $key]);

        return;
    }
    $types = array_values(array_unique($types));
    sort($types, SORT_STRING);

    $fields = [];
    foreach ($raw['fields'] as $field) {
        if (! is_array($field)) {
            $problem('invalid-field-identity', $origin, ['group_key' => $key]);

            continue;
        }
        $fieldKey = $field['key'] ?? null;
        $name = $field['name'] ?? null;
        $type = $field['type'] ?? null;
        if (! is_string($fieldKey) || ! str_starts_with($fieldKey, 'field_')
            || ! is_string($name) || ! preg_match('/^[a-z0-9][a-z0-9_]*$/D', $name) || ! is_string($type)) {
            $problem('invalid-field-identity', $origin, ['group_key' => $key]);

            continue;
        }
        if (isset($fieldKeys[$fieldKey])) {
            $problem('duplicate-field-key', $origin, ['field_key' => $fieldKey, 'previous_origin' => $fieldKeys[$fieldKey]]);

            continue;
        }
        $fieldKeys[$fieldKey] = $origin;
        $conflicts = array_filter($types, static fn (string $postType): bool => isset($targetFields[$postType][$name]));
        if ($conflicts !== []) {
            $problem('duplicate-target-handle', $origin, ['field_key' => $fieldKey, 'name' => $name]);

            continue;
        }
        foreach ($types as $postType) {
            $targetFields[$postType][$name] = true;
        }
        $target = match ($type) {
            'text', 'textarea' => 'text',
            'number' => 'number',
            'true_false' => 'boolean',
            'image' => 'media-reference',
            default => null,
        };
        if ($target === null) {
            $problem('unsupported-field-type', $origin, ['field_key' => $fieldKey, 'type' => $type]);

            continue;
        }

        $required = $field['required'] ?? false;
        $label = $field['label'] ?? $name;
        $returnFormat = $field['return_format'] ?? null;
        if (! in_array($required, [true, false, 0, 1], true) || ! is_string($label)
            || ($returnFormat !== null && ($type !== 'image' || ! in_array($returnFormat, ['array', 'id', 'url'], true)))) {
            $problem('field-settings-need-review', $origin, ['field_key' => $fieldKey]);

            continue;
        }
        $inertDefaults = [
            'default_value' => ['', null],
            'conditional_logic' => [0, false, []],
            'placeholder' => [''],
            'prepend' => [''],
            'append' => [''],
            'maxlength' => [''],
            'wrapper' => [[], ['width' => '', 'class' => '', 'id' => '']],
        ];
        $extra = array_diff(array_keys($field), ['key', 'label', 'name', 'type', 'required', 'return_format']);
        $extra = array_filter($extra, static fn (string $setting): bool => ! in_array($field[$setting], $inertDefaults[$setting] ?? [], true));
        if ($extra !== []) {
            $problem('field-settings-need-review', $origin, ['field_key' => $fieldKey, 'settings' => array_values($extra)]);
        }
        $fields[] = [
            'source_key' => $fieldKey, 'name' => $name, 'label' => $label, 'source_type' => $type,
            'proposed_kind' => $target, 'required' => (bool) $required, 'source_return_format' => $returnFormat,
        ];
    }
    $groups[] = [
        'source_key' => $key, 'title' => is_string($raw['title'] ?? null) ? $raw['title'] : $key,
        'origin' => $origin, 'post_types' => $types, 'fields' => $fields,
    ];
};

$jsonDirectory = $root.'/acf-json';
if (is_link($jsonDirectory)) {
    $problem('linked-json-directory', 'acf-json');
} else {
    $names = is_dir($jsonDirectory) ? scandir($jsonDirectory, SCANDIR_SORT_ASCENDING) : [];
    if ($names === false) {
        $problem('unreadable-json-directory', 'acf-json');
    }
    foreach ($names ?: [] as $name) {
        if (! str_ends_with($name, '.json')) {
            continue;
        }
        $path = $jsonDirectory.'/'.$name;
        $origin = 'acf-json/'.$name;
        try {
            $contents = acfReadDefinition($path);
            $inputs[$origin] = hash('sha256', $contents);
            $register(acfJson($contents), $origin);
        } catch (Throwable) {
            $problem('invalid-json-definition', $origin);
        }
    }
}

$phpFiles = array_slice($arguments, 2);
if ($phpFiles === [] && (is_file($root.'/fields.php') || is_link($root.'/fields.php'))) {
    $phpFiles = ['fields.php'];
}
sort($phpFiles, SORT_STRING);
foreach (array_unique($phpFiles) as $relative) {
    $phpPath = $root.'/'.$relative;
    $resolved = realpath($phpPath);
    if ($resolved === false || $resolved !== $phpPath || ! str_ends_with($relative, '.php')) {
        $problem('unavailable-php-definition', $relative);

        continue;
    }
    try {
        $contents = acfReadDefinition($phpPath);
        $inputs[$relative] = hash('sha256', $contents);
        $tree = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
        $tree = (new NodeTraverser(new NameResolver, new ParentConnectingVisitor))->traverse($tree);
        $finder = new NodeFinder;
        foreach ($finder->find($tree, static fn (Node $node): bool => $node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Eval_) as $include) {
            $problem('external-php-definition-needs-review', $relative.':'.$include->getStartLine());
        }
        foreach ($finder->findInstanceOf($tree, Node\Expr\FuncCall::class) as $call) {
            $origin = $relative.':'.$call->getStartLine().':'.$call->getStartFilePos();
            if (! $call->name instanceof Node\Name) {
                $problem('php-registration-needs-review', $origin);

                continue;
            }
            $name = strtolower($call->name->toString());
            if ($name === 'add_filter' && ($call->getArgs()[0]->value ?? null) instanceof Node\Scalar\String_
                && str_starts_with($call->getArgs()[0]->value->value, 'acf/')) {
                $problem('php-registration-needs-review', $origin);
            }
            if ($name === 'call_user_func' || $name === 'call_user_func_array') {
                $problem('php-registration-needs-review', $origin);
            }
            if (! str_starts_with($name, 'acf_') && $name !== 'register_field_group') {
                continue;
            }
            if (! acfKnownRegistrationContext($call)) {
                $problem('php-registration-needs-review', $origin);

                continue;
            }
            if ($name !== 'acf_add_local_field_group' || count($call->getArgs()) !== 1 || $call->getArgs()[0]->unpack
                || $call->getArgs()[0]->name !== null || ! $call->getArgs()[0]->value instanceof Node\Expr\Array_) {
                $problem('php-registration-needs-review', $origin);

                continue;
            }
            try {
                $register(acfLiteralArray($call->getArgs()[0]->value), $origin);
            } catch (Throwable) {
                $problem('php-registration-needs-review', $origin);
            }
        }
    } catch (Throwable) {
        $problem('invalid-php-definition', $relative);
    }
}

if ($groups === []) {
    $problem('no-reviewed-field-groups', '.');
}
echo json_encode([
    'format' => 1, 'groups' => $groups, 'findings' => $findings, 'inputs' => (object) $inputs,
    'blocked' => $findings !== [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR).PHP_EOL;
exit($findings === [] ? 0 : 1);
