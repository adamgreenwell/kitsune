<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Filament\Concerns\SyncsFieldRelations;
use Kitsune\Core\Filament\Resources\Entries\Pages\CreateEntry;
use Kitsune\Core\Filament\Resources\Entries\Pages\EditEntry;

/*
 * No page may quietly take over a hook `SyncsFieldRelations` owns.
 *
 * ⚠️ THIS GUARDS A DEFECT THAT PRODUCED NO DIAGNOSTIC OF ANY KIND. `CreateEntry` declared
 * `mutateFormDataBeforeCreate()` to stamp `entry_type_id`; the trait declared the same
 * method to strip relation state that is not an entry attribute (ADR-015). PHP resolves a
 * method defined in the class ahead of one supplied by a trait — no error, no warning, no
 * deprecation — so the trait's copy never ran. Every create of a type with a relation field
 * died on `no such column: relations`, while edit worked perfectly.
 *
 * Nothing in the language, PHPStan, Pint or the existing suite could see it. A browser test
 * would have, had one covered create; one does now. This test is the cheaper guard, because
 * it fails for a hook nobody has written a browser test for yet.
 *
 * ⚠️ Pages are DISCOVERED, not listed. A hand-maintained list is a list that a thirteenth
 * page is added without — which is the same failure mode one level up.
 */

/** Every class under packages/core/src that composes the trait, however its `use` is written. */
function pagesComposingRelationSync(): array
{
    $root = dirname(__DIR__, 3).'/packages/core/src';
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
            continue;
        }

        $class = $ns[1].'\\'.$file->getBasename('.php');

        /*
         * ⚠️ ASKED OF THE CLASS, NOT READ FROM ITS SOURCE. Discovery matched the exact text `use SyncsFieldRelations;`,
         * so a page composing the trait any other way — `use SyncsFieldRelations { afterSave as … }` to take a hook
         * over, a comma list, a qualified name — dropped out of the guard it most needed. Found in review of #109,
         * which did exactly that. The trait's own file declares no class, so `class_exists()` passes it by.
         */
        if (class_exists($class) && in_array(SyncsFieldRelations::class, class_uses_recursive($class), true)) {
            $found[] = $class;
        }
    }

    return $found;
}

it('finds the pages that compose the relation-sync trait', function (): void {
    // The guard below is vacuous if discovery returns nothing, which is exactly how a
    // reflection test passes while checking no code at all.
    //
    // ⚠️ AND HALF-VACUOUS IF IT FINDS ONE PAGE OF TWO: "not empty" stayed true while the edit page had fallen out of
    // discovery (#109). These two are a floor, not the list — a new page is still found without being named here.
    expect(pagesComposingRelationSync())->toContain(CreateEntry::class);
    expect(pagesComposingRelationSync())->toContain(EditEntry::class);
});

it('lets no page override a hook the trait owns', function (): void {
    $trait = new ReflectionClass(SyncsFieldRelations::class);
    $traitFile = $trait->getFileName();

    /*
     * The hooks Filament calls and the trait answers. A page that needs to shape the payload
     * overrides `mutateEntryDataBefore{Save,Create}()` instead; one that genuinely must take
     * a hook over has to call `withoutRelationState()` or `syncRelationsFromForm()` itself,
     * and both are `protected` so that it can.
     */
    $owned = [
        'mutateFormDataBeforeFill',
        'mutateFormDataBeforeSave',
        'mutateFormDataBeforeCreate',
        'afterSave',
        'afterCreate',
    ];

    foreach ($owned as $hook) {
        // A typo here would silently check nothing, so the trait must really declare it.
        expect($trait->hasMethod($hook))->toBeTrue("the trait no longer declares [{$hook}] — update this list");
    }

    foreach (pagesComposingRelationSync() as $page) {
        foreach ($owned as $hook) {
            $declaredIn = (new ReflectionMethod($page, $hook))->getFileName();

            /*
             * A trait method flattened into a class still reports the TRAIT's file, so a
             * mismatch means the page redeclared it. That is the only reliable signal: PHP
             * exposes no "this came from a trait" flag on the resolved method.
             */
            expect($declaredIn)->toBe(
                $traitFile,
                "[{$page}] declares [{$hook}], which SyncsFieldRelations owns. PHP prefers the "
                .'class method over the trait method silently, so the relation cleanup or sync '
                .'would stop running with no error. Override mutateEntryDataBeforeSave() or '
                .'mutateEntryDataBeforeCreate() instead, or call withoutRelationState() and '
                .'syncRelationsFromForm() from the override.',
            );
        }
    }
});
