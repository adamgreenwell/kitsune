<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * ⚠️ ONE STATEMENT FOR EVERY CONFIRMATION — ADR-042 decisions 15, 30, 32 and 34. Uploading a file public, making a
 * stored file public and making a selection public ask the same acknowledgement, so its words are written once and each
 * confirmation ends in its own sentence. ⚠️ IT CLAIMS WHAT `MediaLocation::STRIPPED` STRIPS AND NO MORE, and a test holds the formats it names to it.
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
        // A selection on a media list — decision 34.
        'bulk' => [
            'make_public' => 'Make selected public',
            'make_private' => 'Make selected private',
            'make_public_heading' => '[0,1] Make the selected file public|[2,*] Make the :count selected files public',
            'make_private_heading' => '[0,1] Make the selected file private|[2,*] Make the :count selected files private',
            'public_confirm' => 'Make these files public',
            'public_warning' => $publicStatement.' Unless this is ticked, the files stay private.',
            'not_confirmed' => 'Tick the box to make these files public. Unticked, they stay private.',
            'not_confirmed_title' => 'No file was made public',
            'private_warning' => 'Their links stop opening them for anyone not signed in here who may view this type. A copy a browser, a proxy or a CDN has already kept is not recalled, and can be served until it expires. A JPEG made public earlier stays without the GPS coordinates it lost then.',
            'shared_public_some' => '{1} One of them is shared with every site in the organisation, so it becomes public for all of those sites.|[2,*] :count of them are shared with every site in the organisation, so they become public for all of those sites.',
            'shared_public_all' => 'Each of them is shared with every site in the organisation, so each becomes public for all of those sites.',
            'shared_private_some' => '{1} One of them is shared with every site in the organisation, so it becomes private for all of those sites.|[2,*] :count of them are shared with every site in the organisation, so they become private for all of those sites.',
            'shared_private_all' => 'Each of them is shared with every site in the organisation, so each becomes private for all of those sites.',
            'too_many_note' => 'At most :max files are switched at a time, and :count are selected, so as it is nothing will be changed. Select fewer first.',
            'too_many_title' => 'Too many files are selected',
            'too_many' => 'More than :max files are selected, and at most :max are switched at a time, so nothing was changed. Select :max or fewer, and run it again.',
            'none' => 'None of the selected files is on this list any more. Nothing was changed.',
            'gone_line' => '{1} One of the selected files is no longer on this list, and was left as it was.|[2,*] :count of the selected files are no longer on this list, and were left as they were.',
            'made_public' => '{1} One file was made public|[2,*] :count files were made public',
            'made_public_awaiting' => '{1} One file is public, and not yet published|[2,*] :count files are public, and not yet published',
            'already_public' => '{1} The file was already public. Nothing was changed.|[2,*] All :count files were already public. Nothing was changed.',
            'not_made_public' => '{1} One file was not made public|[2,*] :count files were not made public',
            'made_private' => '{1} One file was made private|[2,*] :count files were made private',
            'made_private_body' => '{1} Its public link no longer opens it. A copy a browser, a proxy or a CDN has already kept can be served until it expires.|[2,*] Their public links no longer open them. A copy a browser, a proxy or a CDN has already kept can be served until it expires.',
            'already_private' => '{1} The file was already private. Nothing was changed.|[2,*] All :count files were already private. Nothing was changed.',
            'not_made_private' => '{1} One file was not made private|[2,*] :count files were not made private',
            'refused_public_line' => '":title" was not made public: :reason',
            'refused_private_line' => '":title" was not made private: :reason',
            'trashed_private_line' => '":title" is in the trash, so it was not made private. Its file is off the web while it is there, but it is set public, and a restore publishes it again.',
            'failed_public_line' => '{1} :titles may not have been made public: something went wrong. Its page shows what it is now; tell whoever runs this site if it happens again.|[2,*] :titles may not have been made public: something went wrong. Their pages show what each is now; tell whoever runs this site if it happens again.',
            'failed_private_line' => '{1} :titles may not have been made private: something went wrong. Its page shows what it is now; tell whoever runs this site if it happens again.|[2,*] :titles may not have been made private: something went wrong. Their pages show what each is now; tell whoever runs this site if it happens again.',
            'not_tried' => '{1} One file was not tried: one request may take only so long. It is still selected; run it again on the same selection to go on.|[2,*] :count files were not tried: one request may take only so long. They are still selected; run it again on the same selection to go on.',
            'made_public_count' => '{1} One file was made public.|[2,*] :count files were made public.',
            'made_private_count' => '{1} One file was made private.|[2,*] :count files were made private.',
            'already_public_count' => '{1} One was already public, and was left as it was.|[2,*] :count were already public, and were left as they were.',
            'already_private_count' => '{1} One was already private, and was left as it was.|[2,*] :count were already private, and were left as they were.',
        ],
    ],
    // Words any selection on a media list uses — decisions 34 and 35.
    'selection' => [
        'quoted' => '":title"',
        'list_separator' => ', ',
        'awaiting_line' => '{1} Not yet published: :titles. Until it is, its link does not open it, and it opens only through this admin. The log says why; kitsune:media-reconcile :entries --force publishes it.|[2,*] Not yet published: :titles. Until they are, their links do not open them, and they open only through this admin. The log says why; kitsune:media-reconcile :entries --force publishes them.',
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
