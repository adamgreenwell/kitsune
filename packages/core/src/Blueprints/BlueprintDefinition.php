<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Kitsune\Core\Blueprints\Declarations\EntryTypeDeclaration;

/**
 * What an author writes — ADR-039's format, which is a PHP class rather than a document.
 *
 * ⚠️ NOT NAMED `Blueprint`, DELIBERATELY. `Kitsune\Core\Models\Blueprint` is the receipt and
 * `Illuminate\Database\Schema\Blueprint` is in every migration in the tree; a third `Blueprint` would make
 * every import in this subsystem a question. This project has already paid for a name collision once — a test
 * helper called `join()` that Pint rewrote into `implode()` on save — and for an unhinted `Builder` docblock
 * that resolved to the wrong class. A definition is what the author writes; a blueprint is what the org got.
 *
 * ⚠️ A PHP CLASS, AND ADR-039 RECORDS WHY RATHER THAN LEAVING IT TO TASTE. Zero new dependencies against
 * ADR-027's floor, where YAML would add a parser and `symfony/yaml` is `packages-dev` only; analysable by the
 * PHPStan run CI already gates on; and it is what `PersonServiceProvider::install()` demonstrably already is.
 * The cost is stated in the ADR too: a non-developer cannot author one, and it is not portable to non-PHP
 * tooling. A declarative format and the export direction are v1.1, where they arrive with the API rather than
 * ahead of the freeze.
 *
 * ⚠️ ONE OF THE FOUR KEYS IS HERE. ADR-039 cut the roadmap's six to four — entry types with their fields,
 * roles with their grants, entry type availability, and content — and this interface carries the first. The
 * other three arrive as further methods on this interface rather than as further interfaces, because
 * everything still standing at v1.2 is a permanent obligation (Standing Principle #2) and one seam is cheaper
 * to keep than four. A definition that declares no types is legitimate and applies as a no-op.
 */
interface BlueprintDefinition
{
    /**
     * The blueprint's own name — `blog`, `marketing-site`. Unique per org in `blueprints`.
     *
     * Not a Composer package name: ADR-039 keeps the mechanism in core and lets the payload live anywhere, so
     * a handle names a blueprint rather than the thing that shipped it.
     */
    public function handle(): string;

    /**
     * What version this definition is, as the author declares it.
     *
     * The receipt records which version an org got, so a later apply can tell an upgrade from a re-run of the
     * same thing. Any string an author can compare; nothing here parses it as semver.
     */
    public function version(): string;

    /**
     * The entry types this blueprint installs, each with its fields.
     *
     * @return list<EntryTypeDeclaration>
     */
    public function entryTypes(): array;
}
