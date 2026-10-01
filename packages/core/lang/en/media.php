<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * ⚠️ ONE STATEMENT FOR BOTH CONFIRMATIONS — ADR-042 decisions 15, 30 and 32. Uploading a file public and making a stored
 * file public ask the same acknowledgement, so its words are written once and each confirmation ends in its own
 * sentence. ⚠️ IT CLAIMS WHAT `MediaLocation::STRIPPED` STRIPS AND NO MORE, and a test holds the formats it names to it.
 */
$publicStatement = 'A public file is served to anyone who has its link. A JPEG loses the GPS coordinates in its EXIF and XMP data as it is made public, and its picture and orientation stay as uploaded. What else a photo holds — a place name, a camera maker\'s own records, a motion photo\'s video — and every other type of file are served as uploaded, and can still say where they were made.';

/*
 * The media admin's words — ADR-042.
 *
 * The first strings core ships under its own `kitsune` namespace, so that the media work does not widen the gap
 * ADR-018's rule 1 leaves across the rest of the admin: `__('kitsune::media.…')`.
 */
return [
    'type' => [
        'holds_media' => 'Holds media',
        'holds_media_help' => 'Entries of this type are uploaded files rather than written in a form. Decide now: it cannot be changed once the type exists.',
        'holds_media_locked' => 'Decided when this type was created, and fixed from then on.',
        'accepts' => 'Accepts',
        'accepts_help' => 'The kinds of file an upload to this type may be. With every one ticked, it accepts any file this site accepts, including kinds added later.',
    ],
    'dashboard' => [
        'site_own' => 'this site\'s own, not the files shared across the organisation',
    ],
    'delete' => [
        'refused' => '":title" was not deleted',
        'refused_line' => '":title" was not deleted: :reason',
        'refused_bulk' => '{1} One entry was not deleted; its file could not be taken off the web|[2,*] :count entries were not deleted; their files could not be taken off the web',
        'withdrawn_bulk' => '{1} The other entry was deleted, and its file taken off the web.|[2,*] The other :count entries were deleted, and their files taken off the web.',
    ],
    'upload' => [
        'action' => 'Upload',
        'heading' => 'Upload files',
        'submit' => 'Upload',
        'files' => 'Files',
        'accepts' => 'Takes :formats files.',
        'visibility' => 'Visibility',
        'private' => 'Private',
        'private_help' => 'Opened only by people signed in here who may view this type.',
        'public' => 'Public',
        'public_help' => 'Opened by anyone who has its link.',
        'public_confirm' => 'Make these files public',
        'public_warning' => $publicStatement.' Unless this is ticked, the files are stored private.',
        'site_only' => 'This site only',
        'site_only_help' => 'Unticked, the files are shared with every site in the organisation.',
        'needs_publish' => 'Uploading needs permission to publish :type (:permission): a file is published as it is stored.',
        'needs_permission' => 'uploading here needs both :create and :publish.',
        'not_staged' => 'Something that was not a file uploaded here was ignored.',
        'incomplete' => 'it did not arrive in full, so nothing was stored. Upload it again.',
        'unnamed' => 'A file',
        'stored_all' => '{1} The file was uploaded|[2,*] All :count files were uploaded',
        'stored_none' => '{1} The file was not uploaded|[2,*] None of the :count files was uploaded',
        'stored_some' => ':stored of :count files were uploaded',
        'line_stored' => ':name — stored',
        'line_kept_private' => ':name — stored private: public was chosen but not confirmed',
        'line_refused' => ':name — not stored: :reason',
        'line_failed' => ':name — not stored: something went wrong, and nothing was kept. Try again, and tell whoever runs this site if it happens again.',
    ],
    'file' => [
        'heading' => 'File',
        'type' => 'Type',
        'size' => 'Size',
        'dimensions' => 'Dimensions',
        'dimensions_value' => ':width × :height pixels',
        'visibility' => 'Visibility',
        'private' => 'Private',
        'public' => 'Public',
        'public_awaiting' => 'Public — not yet published, so opened only through this admin',
        'sharing' => 'Shared with',
        'shared' => 'Every site in the organisation',
        'site_only' => 'This site only',
        'stored' => 'Stored',
        'open' => 'Open file',
        'missing' => 'No file is recorded for this entry.',
    ],
    'visibility' => [
        'make_public' => 'Make public',
        'make_private' => 'Make private',
        'make_public_heading' => 'Make ":title" public',
        'make_private_heading' => 'Make ":title" private',
        'public_confirm' => 'Make this file public',
        'public_warning' => $publicStatement.' Unless this is ticked, the file stays private.',
        'not_confirmed' => 'Tick the box to make this file public. Unticked, it stays private.',
        'shared_public' => 'This file is shared with every site in the organisation, so it becomes public for all of them.',
        'shared_private' => 'This file is shared with every site in the organisation, so it becomes private for all of them.',
        'private_warning' => 'Its link stops opening it for anyone not signed in here who may view this type. A copy a browser, a proxy or a CDN has already kept is not recalled, and can be served until it expires. A JPEG made public earlier stays without the GPS coordinates it lost then.',
        'needs_publish' => 'Making a file public or private needs permission to publish :type (:permission).',
        'made_public' => '":title" is public',
        'made_public_awaiting' => '":title" is public, and not yet published',
        'made_public_awaiting_body' => 'Until it is, its link does not open it, and it opens only through this admin. The log says why; kitsune:media-reconcile --entry=:id --force publishes it.',
        'made_private' => '":title" is private',
        'made_private_body' => 'Its public link no longer opens it. A copy a browser, a proxy or a CDN has already kept can be served until it expires.',
        'already_public' => '":title" was already public. Nothing was changed.',
        'already_private' => '":title" was already private. Nothing was changed.',
        'not_made_public' => '":title" was not made public',
        'not_made_private' => '":title" was not made private',
    ],
    'tile' => [
        'show' => 'Show preview',
        // Begins with the words the button shows, so it is the name a voice user speaks (WCAG 2.5.3, label in name).
        'show_label' => 'Show preview of ":title"',
        'private' => 'Private',
        'missing' => 'No file',
        'trashed' => 'In the trash',
    ],
];
