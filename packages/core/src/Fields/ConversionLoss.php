<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields;

use Kitsune\Core\Fields\Types\RichTextType;

/**
 * Whether converting a value LOST something worth keeping beside its revision.
 *
 * ⚠️ SEPARATE FROM "the bytes changed", and it exists because those stopped being the same question.
 * The revision recorder compared the stored value with the submitted one, which is right while every
 * conversion is a cast or a strip — and wrong the moment one ADDS something. `RichTextType` stamps
 * `dir="auto"` on each block (issue #39), so every rich text save changed its bytes and every
 * revision retained an original identical to its input but for an attribute the type had just added.
 * That column is swept by erasure (ADR-020) and exists to show an author what a sanitiser REMOVED.
 *
 * ⚠️ A CLASS RATHER THAN A METHOD ON THE TYPE, AND THAT IS THE API FREEZE TALKING. This began as a
 * method on `FieldType`, which review correctly rejected: that interface is the extension API, and
 * `CONTRIBUTING.md` lists "a new public API surface before v1.2" among the things that will not
 * merge. Moving it to `BaseFieldType` with an `@internal` tag was rejected for a sharper reason —
 * `@internal` is a docblock, not a visibility, and a plugin subclassing `BaseFieldType` that already
 * has a same-named method becomes signature-incompatible whatever the tag says.
 *
 * ⚠️ SO THIS TESTS A TYPE BY IDENTITY, which `FieldType`'s own docblock argues against: *"the model
 * testing for `rich_text` by name puts a field-type concern in every layer that touches a value"*.
 * That argument is about a concern spread across layers, and this is one place — the whole point of
 * the class. It is the deliberate cost of freezing the contract before v1.2, and it is the shape to
 * undo first when the contract opens: at v1.2 this becomes a method on `FieldType` and this class
 * goes away.
 */
final class ConversionLoss
{
    /**
     * ⚠️ THE DEFAULT ANSWERS THE OLD QUESTION, so every type but one behaves exactly as it did
     * before this class existed: a cast or a strip changes the bytes only when it takes something.
     */
    public static function occurred(FieldType $type, mixed $submitted, mixed $stored): bool
    {
        if ($type instanceof RichTextType && is_string($submitted)) {
            /*
             * ⚠️ COMPARED AGAINST THE SANITISED FORM, not the stored one, because the stored form
             * also carries the per-block direction this class exists to ignore. §6 asks whether the
             * author can see what the sanitiser took, and that is the only part of the conversion
             * that takes anything.
             */
            return $type->sanitize($submitted) !== $submitted;
        }

        return $stored !== $submitted;
    }
}
