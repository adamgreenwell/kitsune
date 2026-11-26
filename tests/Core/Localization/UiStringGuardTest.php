<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

require_once dirname(__DIR__, 3).'/bin/support/UiStringGuard.php';

it('rejects bare UI copy through composed expressions and callbacks', function (string $expression): void {
    $source = '<?php $field->label('.$expression.');';

    expect(UiStringGuard::literals($source))->not->toBeEmpty();
})->with([
    "'Bare label'",
    "'Hello '.\$record->name",
    "\$empty ? 'None yet' : 'Updated'",
    "fn () => 'Bare callback'",
    "function () { return 'Bare callback'; }",
    "e('Bare escaped label')",
    "new \\Illuminate\\Support\\HtmlString('Bare HTML label')",
]);

it('accepts translated copy and runtime labels without translating stored content', function (string $expression): void {
    expect(UiStringGuard::literals('<?php $field->label('.$expression.');'))->toBe([]);
})->with([
    "__('kitsune::admin.label')",
    "e(__('kitsune::admin.label'))",
    "fn () => __('kitsune::admin.label')",
    '$field->label()',
    '$record->name',
]);

it('does not confuse translation keys and branch selectors with rendered copy', function (): void {
    expect(UiStringGuard::literals(<<<'PHP'
<?php
$field->label(fn () => $record->state === 'draft' ? __('kitsune::admin.draft') : $record->name);
PHP))->toBe([]);
});

it('checks every UI method from issue 106 including nullsafe calls', function (): void {
    foreach (UiStringGuard::METHODS as $method) {
        expect(UiStringGuard::literals("<?php \$field?->{$method}('Bare');"))->toHaveCount(1);
    }
});

it('keeps the core UI entry points free of unapproved bare copy', function (): void {
    $root = dirname(__DIR__, 3);
    $violations = UiStringGuard::scan($root.'/packages/core/src');

    expect($violations)->toBe([], json_encode($violations, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});

it('allows only explained nonverbal exceptions', function (): void {
    $exceptions = require dirname(__DIR__, 3).'/bin/support/ui-string-exemptions.php';

    foreach ($exceptions as $key => $reason) {
        $value = explode('|', $key, 3)[2];
        expect(preg_match('/[\p{L}\p{N}]/u', $value))->toBe(0)
            ->and(trim($reason))->not->toBe('');
    }
});

it('routes all counted core messages through the integer boundary', function (): void {
    $root = dirname(__DIR__, 3).'/packages/core/src';
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $violations = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getPathname() === $root.'/Localization/CountMessage.php') {
            continue;
        }

        foreach ($finder->findInstanceOf($parser->parse(file_get_contents($file->getPathname())) ?? [], FuncCall::class) as $call) {
            if ($call->name instanceof Name && strtolower($call->name->toString()) === 'trans_choice') {
                $violations[] = $file->getPathname().':'.$call->getStartLine();
            }
        }
    }

    expect($violations)->toBe([]);
});
