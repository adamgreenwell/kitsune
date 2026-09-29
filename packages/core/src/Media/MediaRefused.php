<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use RuntimeException;

/**
 * A file the library refused, in words written for whoever uploaded it — ADR-042 decision 7.
 *
 * ⚠️ THE ONE MEDIA FAILURE AN EDITOR IS SHOWN AS IT WAS WRITTEN. `MediaIntake`'s refusals — the allowlist, the sniffed
 * type, the ceilings, the missing sanitiser and `ext-fileinfo` — and `MediaLibrary`'s refusals of a visibility and of a
 * type that holds no files are thrown as this, and the Upload action shows their message, escaped as text. Every other
 * failure `MediaLibrary` can raise — a path it could not read or write, a sanitiser that vanished, SQL — is a plain
 * `RuntimeException` or whatever interrupted it, and is shown as a generic failure: its message carries server paths,
 * developer-facing class names or a query's bindings.
 *
 * ⚠️ A SUBTYPE, SO EVERY CALLER THAT CATCHES `RuntimeException` STILL DOES — the upload endpoint's rule among them
 * (`MediaStaging::refusalFor()`). A message thrown as this must name no server path, no SQL and no query bindings, since
 * it reaches an editor as written. Two of `MediaIntake`'s texts are written for an operator rather than an editor and
 * are shown anyway, because they name the fix (decision 7): the missing sanitiser, which names the `SanitisesSvg`
 * contract and the module commands, and the missing `ext-fileinfo`.
 */
final class MediaRefused extends RuntimeException {}
