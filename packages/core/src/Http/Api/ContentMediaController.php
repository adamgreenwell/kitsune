<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Media\MediaFormats;
use Kitsune\Core\Media\MediaIntake;
use Kitsune\Core\Media\MediaLibrary;
use Kitsune\Core\Media\MediaRefused;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Tenancy\Context;
use Throwable;

/** @internal Site token uploads enter the same intake and custody path as admin media. */
final class ContentMediaController
{
    public function __invoke(Request $request, Context $context): JsonResponse
    {
        $type = SiteApiRoutes::type($request, $context, 'create');
        abort_unless($type->is_media, 404);
        abort_unless(SiteTokens::allows($request, $type->handle, 'publish'), 403);

        abort_unless(preg_match('/^multipart\/form-data(?:\s*;|\s*$)/i', (string) $request->header('Content-Type')), 415);
        if (array_diff(array_keys($request->all()), ['file', 'title']) !== []) {
            throw ValidationException::withMessages(['media' => 'Send only a file and an optional title.']);
        }

        $data = $request->validate([
            'file' => ['required', 'file'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);
        $file = $request->file('file');
        $path = $file instanceof UploadedFile && $file->isValid() ? $file->getRealPath() : false;
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The complete file did not arrive.']);
        }

        try {
            $entry = MediaLibrary::store($path, $file->getClientOriginalName(), $type, 'private', $data['title'] ?? null, siteOnly: true);
        } catch (MediaRefused $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        } catch (Throwable $failure) {
            report($failure);
            abort(500, 'The file could not be stored.');
        }

        return response()->json(['data' => ['id' => $entry->id]], 201, ['Cache-Control' => 'private, no-store']);
    }

    /** @return array{extensions: list<string>, max_bytes: int, guarded_max_bytes: int} */
    public static function uploadSchema(EntryType $type): array
    {
        $formats = MediaFormats::namedBy($type->settings, $type->handle);
        $extensions = array_keys(MediaIntake::acceptedTypes());

        return [
            'extensions' => $formats === null ? $extensions : array_values(array_filter(
                $extensions, fn (string $extension): bool => in_array(MediaFormats::of($extension), $formats, true),
            )),
            'max_bytes' => MediaIntake::MAX_BYTES,
            'guarded_max_bytes' => MediaIntake::GUARDED_MAX_BYTES,
        ];
    }
}
