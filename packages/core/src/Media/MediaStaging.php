<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Kitsune\Core\Http\Middleware\GuardUploadStaging;
use RuntimeException;
use Throwable;

/**
 * Livewire's upload staging, owned by core for the whole installation — ADR-042 decision 4.
 *
 * @internal
 *
 * ⚠️ EVERY LIVEWIRE UPLOAD IN THE APPLICATION PASSES THROUGH THIS, THE HOST'S OWN COMPONENTS INCLUDED. The endpoint,
 * its staging disk and its preview route are shared infrastructure, and Livewire offers no per-component seam, so
 * there is nowhere narrower to put the rule. What that costs a host is in ADR-042's *What this costs*.
 *
 * ⚠️ THE ENDPOINT'S RULE IS THE GATE, BECAUSE IT IS THE ONE CHECK EVERY STAGED BYTE MEETS. Any component using
 * `WithFileUploads` mints the signature that reaches the endpoint, and Livewire validates before it writes — the
 * sidecar and the file both come after the rules — so a refusal here means nothing was staged.
 */
final class MediaStaging
{
    /** The endpoint rule's name, registered with `Validator::extend` because config holds strings only. */
    public const RULE = 'kitsune_media_intake';

    /**
     * ⚠️ `bail`, `required` and `file` BEFORE THE RULE, because Laravel only runs its `uploaded` pre-check — the one
     * that refuses a part PHP marked incomplete — alongside a file rule or an implicit one. The rule refuses such a
     * file itself as well; this keeps Laravel's own refusal first.
     *
     * @var list<string>
     */
    public const RULES = ['bail', 'required', 'file', self::RULE];

    /**
     * Livewire's own throttle, restated: a configured middleware list REPLACES `throttle:60,1` rather than adding
     * to it, and the ADR keeps the endpoint under Livewire's throttle.
     */
    public const THROTTLE = 'throttle:60,1';

    /** 24 hours — the window Livewire's own sweep uses, and the ADR's. */
    public const STALE_AFTER_SECONDS = 86_400;

    /**
     * Set Livewire's four staging keys, one at a time.
     *
     * ⚠️ AFTER EVERY PROVIDER HAS BOOTED (`booted()`), NOT IN `register()`. Livewire merges its defaults in its own
     * `register()` with a top-level merge, and core registers first, so a partial `temporary_file_upload` array
     * written earlier would replace Livewire's whole default sub-array. And a host provider booting after core could
     * otherwise overwrite these without a sound. Every reader of the keys runs at request time, and `config:cache`
     * boots the application before it exports, so the cached values are these.
     *
     * ⚠️ KEY BY KEY, so `directory`, `max_upload_time` and `cleanup` keep whatever Livewire or the host set.
     *
     * ⚠️ STRINGS AND ARRAYS OF STRINGS ONLY. `deploy/release.sh` runs `config:cache`, which exports the config and
     * reads it back; a closure or a rule object here fails the deploy.
     */
    public static function pin(Repository $config): void
    {
        $config->set('livewire.temporary_file_upload.disk', MediaDisks::INTAKE);
        $config->set('livewire.temporary_file_upload.rules', self::RULES);

        /*
         * ⚠️ AN EMPTY ARRAY, NOT NULL AND NOT ABSENT. Absent falls back to Livewire's own list, which includes `svg`;
         * null is a `TypeError` in `in_array()`. Empty means `temporaryUrl()` mints nothing for a staged file — which
         * is exactly the file nothing has checked. The preview route itself still answers a signature minted before;
         * none can be minted now.
         */
        $config->set('livewire.temporary_file_upload.preview_mimes', []);
        $config->set('livewire.temporary_file_upload.middleware', [self::THROTTLE, GuardUploadStaging::class]);
    }

    /**
     * Pin the staging keys, and refuse a core disk redefined or overlapped — every promise this slice makes, at once.
     *
     * ⚠️ TWICE, BECAUSE NO BOOT HOOK IS LAST — Codex, #152. Once every provider has booted, so an ordinary
     * misconfiguration stops `config:cache` and with it a deploy; and again at the start of every HTTP request
     * (`HoldMediaStaging`), because a host provider may register its own `booted()` callback, which runs after core's
     * and could restore an `s3` intake that sends Livewire past the gate. By the time a request reaches the global
     * middleware, every provider and every booted callback has run. Host code that changes configuration later still —
     * in a route's middleware or a controller — is host code, which core does not govern.
     */
    public static function enforce(Repository $config): void
    {
        self::pin($config);
        MediaDisks::refuseRedefinition($config);
        MediaDisks::refuseOverlaps($config);
    }

    /** The endpoint rule: does `MediaIntake` accept this file? Reads the file; writes nothing. */
    public static function passes(mixed $value): bool
    {
        return self::refusalFor($value) === null;
    }

    /**
     * What the endpoint tells the uploader, which is `MediaIntake`'s own refusal, word for word.
     *
     * ⚠️ DELIVERED BY A REPLACER, NOT A CUSTOM MESSAGE. A custom message goes through the validator's placeholder
     * replacement, which would rewrite an `:attribute` or `:input` inside the client's filename; a replacer's return
     * is final.
     */
    public static function refusal(mixed $value): string
    {
        return self::refusalFor($value) ?? 'Refusing this upload.';
    }

    private static function refusalFor(mixed $value): ?string
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return 'Refusing this upload: it did not arrive as a complete file.';
        }

        try {
            $path = $value->getRealPath();

            // PHP's own temporary file, stat-ed: the size that arrived, never the size the client declared.
            $size = $value->getSize();

            if (! is_string($path) || ! is_int($size)) {
                return 'Refusing this upload: it did not arrive as a complete file.';
            }

            MediaIntake::accept($value->getClientOriginalName(), $path, $size);
        } catch (RuntimeException $refused) {
            return $refused->getMessage();
        }

        return null;
    }

    /**
     * Remove every staged file and sidecar older than the window.
     *
     * ⚠️ BY AGE, AND ONLY HERE. A staged file never has a row, so `kitsune:media-prune`'s rule — it asks the database,
     * never the filename — cannot apply to it; folding an age rule into that command would break the rule it states.
     *
     * ⚠️ THE WHOLE INTAKE DISK, NOT LIVEWIRE'S DIRECTORY ON IT, so a host that sets `directory` cannot put staged
     * bytes outside the sweep. A stale file's sidecar goes with it whatever the sidecar's own age, and an orphaned
     * sidecar goes by its own.
     *
     * @return array{stale: list<string>, removed: int}
     */
    public static function sweep(bool $delete = true): array
    {
        return self::sweepDisk(Storage::disk(MediaDisks::INTAKE), '', $delete);
    }

    /** @return array{stale: list<string>, removed: int} */
    private static function sweepDisk(Filesystem $disk, string $directory, bool $delete): array
    {
        $cutoff = Carbon::now()->getTimestamp() - self::STALE_AFTER_SECONDS;
        $stale = [];

        foreach ($disk->allFiles($directory) as $path) {
            try {
                // A concurrent sweep may have removed it since the listing.
                if ($disk->lastModified($path) < $cutoff) {
                    $stale[] = $path;
                }
            } catch (Throwable) {
                continue;
            }
        }

        if (! $delete) {
            return ['stale' => $stale, 'removed' => 0];
        }

        $removed = 0;
        $companions = [];

        foreach ($stale as $path) {
            // A sidecar already removed as its file's companion is counted rather than deleted twice.
            if (isset($companions[$path]) || $disk->delete($path)) {
                $removed++;
            }

            if (! str_ends_with($path, '.json') && $disk->delete($path.'.json')) {
                $companions[$path.'.json'] = true;
            }
        }

        return ['stale' => $stale, 'removed' => $removed];
    }
}
