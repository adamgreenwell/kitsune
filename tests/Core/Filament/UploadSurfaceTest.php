<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Filament\Resources\Entries\Pages\EditEntry;
use Kitsune\Core\Filament\Resources\EntryTypes\RelationManagers\FieldsRelationManager;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Filament\Widgets\RecentEntriesWidget;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;
use Livewire\Component;

/*
 * The upload paths core leaves open in the admin, and the ones it closes — ADR-042 decision 4.
 *
 * ⚠️ WHAT THESE CANNOT SEE: a request. Whether the restriction refuses an upload, and whether the editor's action can
 * be mounted by a hand-built one, are answered where Livewire runs (`media-staging.spec.js`). These hold the
 * construction those answers rest on, for every class and every call site, which a browser test visiting pages
 * cannot enumerate.
 */

/** The code of a PHP file with its comments removed, so prose about `temporaryUrl()` is not mistaken for a call. */
$withoutComments = static function (string $path): string {
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
};

/**
 * Every PHP file in the packages' `src`, with the class PSR-4 gives it.
 *
 * @return array<string, class-string>
 */
function packageClasses(): array
{
    $classes = [];

    foreach (glob(__DIR__.'/../../../packages/*/composer.json') ?: [] as $manifest) {
        $autoload = json_decode((string) file_get_contents($manifest), true)['autoload']['psr-4'] ?? [];

        foreach ($autoload as $namespace => $directory) {
            $root = realpath(dirname($manifest).'/'.$directory);

            if ($root === false) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $classes[$file->getPathname()] = $namespace.str_replace('/', '\\', $relative);
            }
        }
    }

    return $classes;
}

describe('rich text', function (): void {
    beforeEach(function (): void {
        $org = Org::create(['slug' => 'surface', 'name' => 'Surface']);
        app(Context::class)->setOrg($org);

        $this->editor = FieldValueRenderer::formComponent(new FieldConfig(FieldStorage::create([
            'org_id' => $org->id, 'handle' => 'body', 'type' => 'rich_text', 'pii_class' => 'none', 'cardinality' => 1,
        ])));

        // Inside a schema on the page that renders it, as the panel places it: its actions resolve against both.
        $this->editor->container(Schema::make(app(EditEntry::class)));
    });

    afterEach(fn () => app(Context::class)->forget());

    it('offers no file attachments', function (): void {
        expect($this->editor)->toBeInstanceOf(RichEditor::class)
            ->and($this->editor->hasFileAttachments())->toBeFalse();
    });

    /**
     * ⚠️ AND ITS ATTACH ACTION IS HIDDEN AND EMPTY, because turning attachments off leaves Filament's own action
     * registered, with a `FileUpload` in its modal. An action mounted by a hand-built request has its schema cached
     * without anyone asking whether it is hidden — so this one must have nothing in it to upload to.
     */
    it('replaces its attach action with a hidden one that holds nothing', function (): void {
        $action = $this->editor->getAction('attachFiles');

        expect($action)->not->toBeNull()
            ->and($action->isHidden())->toBeTrue()
            ->and($action->getSchema(Schema::make()))->toBeNull();
    });
});

/**
 * ⚠️ EVERY LIVEWIRE CLASS THE PACKAGES SHIP, NOT A LIST OF THEM — a page added tomorrow without the trait fails here.
 * The restriction is defence in depth rather than the gate: Filament's own dashboard, topbar and sidebar mint the same
 * signature and are not Kitsune's to restrict, which is why the endpoint's rule and middleware exist.
 */
it('restricts uploads to schema fields on every Livewire class the packages ship', function (): void {
    $components = array_filter(
        packageClasses(),
        static fn (string $class): bool => class_exists($class)
            && is_subclass_of($class, Component::class)
            && ! (new ReflectionClass($class))->isAbstract(),
    );

    // Not vacuous: the pages, a relation manager and a widget are all found.
    expect($components)->toContain(EditEntry::class, FieldsRelationManager::class, RecentEntriesWidget::class)
        ->and(count($components))->toBeGreaterThanOrEqual(15);

    foreach ($components as $class) {
        expect(class_uses_recursive($class))->toHaveKey(RestrictsFileUploadsToSchemaComponents::class, message: "{$class} does not restrict uploads");
    }
});

/**
 * ⚠️ NOTHING IN THE PACKAGES MINTS A TEMPORARY URL, OR BUILDS THE COLUMNS THAT DO. A `temporaryUrl()` on a served disk
 * is a bearer URL past `EntryPolicy`, valid until it expires whether or not its row still exists; Filament's image
 * column and entry mint one for any state that is a path on a disk other than `public`. Tiles are Kitsune's own
 * column, built from delivery URLs (decision 6).
 */
const TEMPORARY_URL_CONSTRUCTS = '/\btemporary(?:Url|SignedRoute|UploadUrl)\s*\(|\bImage(?:Column|Entry)\b/';

it('recognises every way to mint a temporary URL, and none that does not', function (): void {
    $caught = [
        "Storage::disk('local')->temporaryUrl(\$path, now()->addMinute())", "URL::temporarySignedRoute('x', now())",
        '$file->temporaryUrl()', '->temporaryUploadUrl($path, now())', "ImageColumn::make('path')",
        'use Filament\\Infolists\\Components\\ImageEntry;',
    ];
    $allowed = ["URL::signedRoute('x')", "Storage::disk('public')->url(\$path)", '$this->temporaryUrlFor', "TextColumn::make('image')"];

    foreach ($caught as $code) {
        expect(preg_match(TEMPORARY_URL_CONSTRUCTS, $code))->toBe(1, $code);
    }

    foreach ($allowed as $code) {
        expect(preg_match(TEMPORARY_URL_CONSTRUCTS, $code))->toBe(0, $code);
    }
});

it('mints no temporary URL anywhere in the packages', function () use ($withoutComments): void {
    $files = array_keys(packageClasses());

    // Views too, recursively — `glob()` does not recurse on `**`.
    foreach (glob(__DIR__.'/../../../packages/*/resources', GLOB_ONLYDIR) ?: [] as $resources) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS)) as $view) {
            if (str_ends_with($view->getFilename(), '.php')) {
                $files[] = $view->getPathname();
            }
        }
    }

    // Not vacuous: the files that deliver and store media are among those read.
    expect(implode("\n", $files))->toContain('Media/MediaDelivery.php', 'Media/MediaDisks.php');

    $offenders = array_values(array_filter($files, static fn (string $file): bool => preg_match(TEMPORARY_URL_CONSTRUCTS, $withoutComments($file)) === 1));

    expect($offenders)->toBe([]);
});
