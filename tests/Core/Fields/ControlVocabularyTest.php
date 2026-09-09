<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Fields\Cell;
use Kitsune\Core\Fields\Control;
use Kitsune\Core\Fields\FieldType;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\ValueDirection;

/**
 * The control vocabulary, and the guarantee it exists to make (ADR-029, issue #39).
 *
 * ⚠️ The point of these tests is NOT that direction is currently right. It is that a
 * field type has no way to make it wrong, and that a new control kind cannot be added
 * without deciding it. Issue #39 shipped `dir="auto"` three times and was short of
 * complete twice — the second time missing two of three table columns — every time
 * because the reach of a correct rule depended on somebody enumerating call sites.
 */
describe('every control decides how its text runs', function (): void {
    it('gives every case a direction and a cell', function (): void {
        /*
         * ⚠️ THE EXHAUSTIVENESS TEST, and it is what makes a 12th type safe.
         *
         * `direction()` and `cell()` are `match` with no `default`, so an unhandled case
         * is an UnhandledMatchError at runtime and a PHPStan failure before that. This
         * asserts the enum is actually complete today; PHPStan asserts it stays so.
         */
        foreach (Control::cases() as $control) {
            expect($control->direction())->toBeInstanceOf(ValueDirection::class)
                ->and($control->cell())->toBeInstanceOf(Cell::class);
        }

        expect(Control::cases())->not->toBeEmpty();
    });

    it('gives every cell a direction', function (): void {
        foreach (Cell::cases() as $cell) {
            expect($cell->direction())->toBeInstanceOf(ValueDirection::class);
        }
    });

    /*
     * ⚠️ THE CASES THAT LOOK LIKE CHROME AND ARE NOT. Each of these is a value somebody
     * AUTHORED, so each needs `dir="auto"` — and each is the kind of control a reasonable
     * person marks neutral because its values are constrained or generated.
     *
     * A select holds option labels, which an org may write in Arabic. An entry picker
     * holds entry titles — the exact content the seeded Arabic row exists to test, and the
     * related-records table was one of the two places #39 originally missed. A slug is
     * generated from a title and carries its script. A JSON editor holds whatever was put
     * in it, keys included.
     */
    it('treats constrained and generated values as authored text', function (): void {
        foreach ([Control::Choice, Control::Choices, Control::EntryPicker, Control::KeyValue, Control::Line] as $control) {
            expect($control->direction())
                ->toBe(ValueDirection::Auto, "[{$control->value}] would render in the direction of the chrome");
        }
    });

    it('treats app-formatted values as neutral', function (): void {
        // The other side. Marking everything Auto would pass the test above and be
        // wrong — `dir="auto"` on a bare numeral resolves to the surrounding direction
        // anyway, and on a formatted date it can key off a separator.
        foreach ([Control::Number, Control::Toggle, Control::Date, Control::DateTime] as $control) {
            expect($control->direction())->toBe(ValueDirection::Neutral, "[{$control->value}]");
        }
    });

    it('makes rich text per-block rather than auto', function (): void {
        /*
         * ⚠️ NOT `Auto`, and the difference is the whole reason there are three cases
         * rather than a boolean. One `dir="auto"` on a rich-text wrapper resolves once
         * from the first block and imposes it on every other — so an Arabic paragraph
         * followed by an English one renders the second backwards, while the attribute
         * makes it look handled.
         */
        expect(Control::RichText->direction())->toBe(ValueDirection::PerBlock)
            ->and(Control::RichText->needsAutoDirection())->toBeFalse()
            ->and(Control::RichText->cell())->toBe(Cell::None);
    });
});

describe('a field type cannot express an opinion about direction', function (): void {
    /*
     * ⚠️ THIS IS THE GUARANTEE, asserted structurally rather than by inspection.
     *
     * The whole design rests on direction being underivable from anything a field type
     * says. If some type grew a `direction()`, a `dir` setting, or a `ValueDirection`
     * anywhere in its signature, the vocabulary would stop being the single decider and
     * #39's failure mode would be reachable again — quietly, because the twelve current
     * types would still be right.
     */
    it('declares no direction method anywhere on the contract', function (): void {
        $methods = array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(FieldType::class))->getMethods(),
        );

        expect($methods)->not->toContain('direction')
            ->and($methods)->not->toContain('textDirection')
            ->and($methods)->not->toContain('valueDirection');
    });

    it('never returns or accepts a ValueDirection', function (): void {
        // Reflection over the real registry rather than a hard-coded list, so a type
        // added later is covered without anyone remembering to add it here.
        foreach (app(FieldTypeRegistry::class)->all() as $type) {
            foreach ((new ReflectionClass($type))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $signature = [(string) $method->getReturnType()];

                foreach ($method->getParameters() as $parameter) {
                    $signature[] = (string) $parameter->getType();
                }

                expect(implode(' ', $signature))
                    ->not->toContain(ValueDirection::class, $type::handle().'::'.$method->getName().'() touches ValueDirection');
            }
        }
    });
});

describe('every registered type answers with a control', function (): void {
    it('returns a Control from the vocabulary, not a component', function (): void {
        /*
         * ⚠️ Iterated from the REGISTRY, which is what makes this cover a type added by a
         * module rather than only the twelve in this package. `FieldTypeRegistry::register()`
         * accepts the INTERFACE, so a type need not extend `BaseFieldType` — a check that
         * assumed the base class would miss exactly the extension point ADR-001 promises.
         */
        $registered = app(FieldTypeRegistry::class)->all();

        expect($registered)->not->toBeEmpty();

        foreach ($registered as $type) {
            expect($type->control())
                ->toBeInstanceOf(Control::class, $type::handle().'::control() did not return a Control');
        }
    });

    it('covers all twelve v1.0 types', function (): void {
        // The count is asserted because the loop above passes vacuously on an empty
        // registry, and a registry that fails to boot is a plausible way to get one.
        expect(app(FieldTypeRegistry::class)->all())->toHaveCount(12);
    });

    it('maps the two text-shaped types to the same control', function (): void {
        // `text` and `slug` differ in storage strategy — one is Inline, the other
        // Promoted — and not at all in how they are edited. Different controls would be
        // two things to keep in step for no reason.
        $registry = app(FieldTypeRegistry::class);

        expect($registry->get('slug')->control())->toBe($registry->get('text')->control());
    });
});
