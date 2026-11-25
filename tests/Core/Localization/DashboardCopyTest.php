<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Tables\Table;
use Kitsune\Core\Filament\Widgets\EntryCountsWidget;
use Kitsune\Core\Filament\Widgets\RecentEntriesWidget;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

it('resolves every literal admin catalog reference in core', function (): void {
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $keys = [];
    $root = dirname(__DIR__, 3).'/packages/core/src';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach ($finder->findInstanceOf($parser->parse(file_get_contents($file->getPathname())) ?? [], FuncCall::class) as $call) {
            if ($call->name instanceof Name && $call->name->toString() === '__' && isset($call->args[0]) && $call->args[0]->value instanceof String_ && str_starts_with($call->args[0]->value->value, 'kitsune::admin.')) {
                $keys[$call->args[0]->value->value] = true;
            }
        }
    }

    expect($keys)->not->toBeEmpty();
    foreach (array_keys($keys) as $key) {
        expect(app('translator')->hasForLocale($key, 'en'))->toBeTrue($key);
    }
});

it('takes the dashboard heading and all four column labels from the requested catalog', function (): void {
    app('translator')->addLines([
        'admin.recent_heading' => 'آخر التحديثات',
        'admin.recent_empty' => 'لم يُكتب أي شيء بعد',
        'admin.title' => 'العنوان',
        'admin.type' => 'النوع',
        'admin.status' => 'الحالة',
        'admin.updated' => 'تم التحديث',
    ], 'ar', 'kitsune');
    app()->setLocale('ar');

    try {
        $widget = new RecentEntriesWidget;
        $table = $widget->table(Table::make($widget));
        $columns = $table->getColumns();

        expect($table->getHeading())->toBe('آخر التحديثات')
            ->and($table->getEmptyStateHeading())->toBe('لم يُكتب أي شيء بعد')
            ->and($columns['title']->getLabel())->toBe('العنوان')
            ->and($columns['type_handle']->getLabel())->toBe('النوع')
            ->and($columns['status']->getLabel())->toBe('الحالة')
            ->and($columns['updated_at']->getLabel())->toBe('تم التحديث');
    } finally {
        app()->setLocale('en');
    }
});

it('translates whole counted dashboard sentences and their list separator', function (): void {
    app('translator')->addLines([
        'admin.none_yet' => 'AR-empty',
        'admin.list_separator' => ' / ',
        'admin.counted_status.draft' => 'AR-zero|AR-one|AR-two|AR-few|AR-many|AR-other',
        'admin.counted_status.published' => ':count AR-published',
    ], 'ar', 'kitsune');
    app()->setLocale('ar');

    try {
        expect(EntryCountsWidget::describe([]))->toBe('AR-empty')
            ->and(EntryCountsWidget::describe(['draft' => 2, 'published' => 4]))->toBe('AR-two / 4 AR-published');
    } finally {
        app()->setLocale('en');
    }
});
