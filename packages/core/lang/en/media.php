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
        // For a row read as a format whose location is removed (`MediaLocation::STRIPPED`): custody publishes none whose
        // copy fails its checksum (decision 37).
        'public_awaiting_stripped' => 'Public — not yet published, so opened only through this admin. If its file was changed outside Kitsune, making it private and then public publishes it, or says what stands in the way',
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
        'made_public_awaiting_body' => 'Until it is, its link does not open it, and it opens only through this admin. The log says why; kitsune:media-reconcile --entry=:id --force publishes it, or says what will.',
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
            'trashed_private_line' => '":title" is in the trash, so it was not made private. Its file is off the web while it is there, but it is set public, and a restore publishes it again, or says what will.',
            'failed_public_line' => '{1} :titles may not have been made public: something went wrong. Its page shows what it is now; tell whoever runs this site if it happens again.|[2,*] :titles may not have been made public: something went wrong. Their pages show what each is now; tell whoever runs this site if it happens again.',
            'failed_private_line' => '{1} :titles may not have been made private: something went wrong. Its page shows what it is now; tell whoever runs this site if it happens again.|[2,*] :titles may not have been made private: something went wrong. Their pages show what each is now; tell whoever runs this site if it happens again.',
            'not_tried' => '{1} One file was not tried: one request may take only so long. It is still selected; run it again on the same selection to go on.|[2,*] :count files were not tried: one request may take only so long. They are still selected; run it again on the same selection to go on.',
            'made_public_count' => '{1} One file was made public.|[2,*] :count files were made public.',
            'made_private_count' => '{1} One file was made private.|[2,*] :count files were made private.',
            'already_public_count' => '{1} One was already public, and was left as it was.|[2,*] :count were already public, and were left as they were.',
            'already_private_count' => '{1} One was already private, and was left as it was.|[2,*] :count were already private, and were left as they were.',
        ],
    ],
    /*
     * *Offer to readers*, *Change offer* and *Stop offering*, and the File section's lines about them — ADR-041
     * decisions 1–4 (Adam, 2026-10-11).
     *
     * ⚠️ DECISION 4, SAID PLAINLY: an offered file is served as it is stored here — a private file is never opened
     * (ADR-042 decision 30) — so a photo keeps any location it still holds. Not "as uploaded": a JPEG made public at some
     * point lost its GPS coordinates then (`MediaVisibility::makePublic()` makes the stripped copy the file on the private
     * disk, and making it private again keeps that copy), so the words claim neither that anything is removed nor that
     * the upload's bytes are what readers get. `as_stored` says it in the dialog, and `file.as_stored` in the File section.
     *
     * ⚠️ AN INSTRUCTION ONLY TO WHOEVER CAN FOLLOW IT. The File section is shown to whoever may view the entry, and only
     * whoever may publish the type has *Stop offering*: `file.malformed` and `file.not_served` say "Stop offering" to
     * them, and their `_others` forms name who can.
     */
    'readers' => [
        'bulk' => [
            'offer' => 'Offer selected to readers',
            'warning' => 'Offer at most :max private files kept to this site. The name below replaces any different name they are already offered for. Each file is processed separately; completed offers are kept if another file refuses or time runs out.',
            'confirm_name' => 'Type the entitlement name again',
            'confirm_help' => 'Confirm the exact name for every eligible selected file, including files already offered under another name.',
            'not_confirmed' => 'Enter a valid entitlement name and type exactly the same name again. No file was changed.',
            'needs_publish' => 'Publishing permission for this media type is required.',
            'not_started' => 'Reader offers were not started',
            'too_many' => 'Select at most :max files. Nothing was changed; the selection was not truncated.',
            'empty' => 'No selected files are available in this list. Select available files and try again. Nothing was changed.',
            'selected_file' => 'Selected file :position',
            'not_tried' => ':title: not attempted because time ran out. Select it again to continue.',
            'unavailable' => ':title: unavailable or not permitted. Nothing was changed for this selection.',
            'unavailable_reason' => 'The file is unavailable or you no longer have permission. It was not offered by this attempt.',
            'in_trash' => 'Restore this file before offering it.',
            'no_file' => 'No file is recorded for this entry.',
            'offered' => ':title: now offered for the confirmed name.',
            'unchanged' => ':title: already offered for the confirmed name; left unchanged.',
            'refused' => ':title: not offered. :reason',
            'unconfirmed' => ':title: the offer could not be confirmed. Check it before trying again.',
            'gone' => 'Unavailable selections: :count. They were not changed by this run.',
            'summary' => 'Offered now: :offered; already offered: :unchanged; refused or unavailable: :refused; unconfirmed: :failed; not attempted: :not_tried.',
            'results' => 'Reader offer results',
            'per_file_results' => 'Per-file reader offer results',
        ],
        'offer' => 'Offer to readers',
        'change' => 'Change offer',
        'stop' => 'Stop offering',
        'offer_heading' => 'Offer ":title" to readers',
        'change_heading' => 'Change which readers may download ":title"',
        'stop_heading' => 'Stop offering ":title" to readers',
        'name' => 'Entitlement',
        'name_help' => 'Readers signed in on this site who hold this entitlement can download the file. Write it exactly as it is granted: two lower-case words joined by a dot, such as course.advanced-php.',
        'not_a_name' => 'This is not an entitlement name. It is two lower-case words joined by a dot, such as course.advanced-php, made of letters, digits and single hyphens, the first word starting with a letter, at most 100 characters.',
        'as_stored' => 'Readers get this file as it is stored here: a photo keeps any location it still holds (a JPEG made public at some point lost its GPS coordinates then), and any other file keeps whatever it holds. If that should not reach readers, upload a copy without it and offer that instead.',
        'change_warning' => 'Readers who hold only the entitlement it is offered for now will no longer be able to download it.',
        'stop_warning' => 'Its reader link stops opening it for everyone. Whoever has already downloaded it keeps their copy.',
        'offer_submit' => 'Offer',
        'change_submit' => 'Change',
        'stop_submit' => 'Stop offering',
        'shared' => 'Only a file kept to this site can be offered to readers, and this one is shared with every site in the organisation. Upload it to this site alone to offer it.',
        'public' => 'Only a private file can be offered to readers: anyone can already download a public one. Make it private first.',
        'offered_not_public' => 'It is offered to readers, so it stays private: making it public would give it to everyone. Stop offering it first.',
        'offered' => '":title" is offered to readers',
        'changed' => 'The offer of ":title" was changed',
        'offered_body' => 'Readers signed in on this site who hold :name can download it from its reader link, shown with the file.',
        'already_offered' => '":title" was already offered for that entitlement. Nothing was changed.',
        'not_offered' => '":title" was not offered to readers',
        'not_changed' => 'The offer of ":title" was not changed',
        'stopped' => '":title" is no longer offered to readers',
        'stopped_body' => 'Its reader link no longer opens it. Whoever has already downloaded it keeps their copy.',
        'already_stopped' => '":title" was not offered to readers. Nothing was changed.',
        'not_stopped' => '":title" is still offered to readers',
        'file' => [
            'readers' => 'Readers',
            'not_offered' => 'Not offered',
            'malformed' => 'Not offered: what is stored is not an entitlement name. Stop offering clears it.',
            'malformed_others' => 'Not offered: what is stored is not an entitlement name. Whoever may publish this type can clear it with Stop offering.',
            'link' => 'Reader link',
            'copy' => 'Copy reader link',
            'copied' => 'Reader link copied',
            'not_copied' => 'The reader link could not be copied. Select it and copy it instead.',
            'path_only' => 'This site has no address of its own, so this is the link\'s path. Add it to the address readers use, or give the site one with kitsune:site address.',
            'not_served' => 'No reader can download it: only a private file kept to this site is served to readers. Stop offering it.',
            'not_served_others' => 'No reader can download it: only a private file kept to this site is served to readers. Whoever may publish this type can stop offering it.',
            'accounts_off' => 'Reader accounts are off on this site, so no reader can sign in to download it until they are turned on. kitsune:readers status says why.',
            'not_a_reader' => 'Your admin account is not a reader account: the link asks you to sign in as a reader, and opens the file only for one who holds the entitlement.',
            'as_stored' => 'Readers get it as it is stored here, with any location it holds.',
        ],
    ],
    // Words any bounded selection uses — decisions 34, 35 and 36; `awaiting_line` only where it holds media.
    'selection' => [
        'quoted' => '":title"',
        'list_separator' => ', ',
        'awaiting_line' => '{1} Not yet published: :titles. Until it is, its link does not open it, and it opens only through this admin. The log says why; kitsune:media-reconcile :entries --force publishes it, or says what will.|[2,*] Not yet published: :titles. Until they are, their links do not open them, and they open only through this admin. The log says why; kitsune:media-reconcile :entries --force publishes them, or says what will.',
    ],
    // An entry list's selection deleted, restored or deleted forever — decisions 35 and 36; `restore.awaiting` only where it
    // holds media.
    'removal' => [
        'too_many_title' => 'Too many entries are selected',
        'none' => 'None of the selected entries is on this list any more. Nothing was changed.',
        'not_tried' => '{1} One entry was not tried: one request may take only so long. It is still selected; run it again on the same selection to go on.|[2,*] :count entries were not tried: one request may take only so long. They are still selected; run it again on the same selection to go on.',
        'gone_line' => '{1} One of the selected entries is no longer on this list, and was left as it was.|[2,*] :count of the selected entries are no longer on this list, and were left as they were.',
        'delete' => [
            'too_many_note' => 'At most :max entries are deleted at a time, and :count are selected, so as it is nothing will be deleted. Select fewer first.',
            'too_many' => 'More than :max entries are selected, and at most :max are deleted at a time, so nothing was deleted. Select :max or fewer, and run it again.',
            'done' => '{1} One entry was deleted|[2,*] :count entries were deleted',
            'done_count' => '{1} One entry was deleted.|[2,*] :count entries were deleted.',
            'not_done' => '{1} One entry was not deleted|[2,*] :count entries were not deleted',
            'already' => '{1} The entry was already in the trash. Nothing was changed.|[2,*] All :count entries were already in the trash. Nothing was changed.',
            'already_count' => '{1} One was already in the trash, and was left as it was.|[2,*] :count were already in the trash, and were left as they were.',
            'refused_line' => '":title" was not deleted: :reason',
            'not_permitted' => 'you may not delete it.',
            'failed_line' => '{1} :titles may not have been deleted: something went wrong. The list shows where it is now; tell whoever runs this site if it happens again.|[2,*] :titles may not have been deleted: something went wrong. The list shows where each is now; tell whoever runs this site if it happens again.',
        ],
        'restore' => [
            'too_many_note' => 'At most :max entries are restored at a time, and :count are selected, so as it is nothing will be restored. Select fewer first.',
            'too_many' => 'More than :max entries are selected, and at most :max are restored at a time, so nothing was restored. Select :max or fewer, and run it again.',
            'done' => '{1} One entry was restored|[2,*] :count entries were restored',
            'done_count' => '{1} One entry was restored.|[2,*] :count entries were restored.',
            'not_done' => '{1} One entry was not restored|[2,*] :count entries were not restored',
            'already' => '{1} The entry was not in the trash. Nothing was changed.|[2,*] None of the :count entries was in the trash. Nothing was changed.',
            'already_count' => '{1} One was not in the trash, and was left as it was.|[2,*] :count were not in the trash, and were left as they were.',
            'awaiting' => '{1} One entry was restored, and its file is not yet published|[2,*] :count entries were restored, and their files are not yet published',
            'refused_line' => '":title" was not restored: :reason',
            'not_permitted' => 'you may not restore it.',
            'failed_line' => '{1} :titles may not have been restored: something went wrong. The list shows where it is now; tell whoever runs this site if it happens again.|[2,*] :titles may not have been restored: something went wrong. The list shows where each is now; tell whoever runs this site if it happens again.',
        ],
        'erase' => [
            'too_many_note' => 'At most :max entries are deleted forever at a time, and :count are selected, so as it is nothing will be deleted. Select fewer first.',
            'too_many' => 'More than :max entries are selected, and at most :max are deleted forever at a time, so nothing was deleted. Select :max or fewer, and run it again.',
            'done' => '{1} One entry was deleted forever|[2,*] :count entries were deleted forever',
            'done_count' => '{1} One entry was deleted forever.|[2,*] :count entries were deleted forever.',
            'not_done' => '{1} One entry was not deleted forever|[2,*] :count entries were not deleted forever',
            'already' => '{1} The entry was already deleted forever. Nothing was changed.|[2,*] All :count entries were already deleted forever. Nothing was changed.',
            'already_count' => '{1} One was already deleted forever.|[2,*] :count were already deleted forever.',
            'refused_line' => '":title" was not deleted forever: :reason',
            'not_permitted' => 'you may not delete it forever.',
            'failed_line' => '{1} :titles may not have been deleted forever: something went wrong. The trash shows whether it is still there; tell whoever runs this site if it happens again.|[2,*] :titles may not have been deleted forever: something went wrong. The trash shows which are still there; tell whoever runs this site if it happens again.',
        ],
    ],
    'tile' => [
        'show' => 'Show preview',
        // Begins with the words the button shows, so it is the name a voice user speaks (WCAG 2.5.3, label in name).
        'show_label' => 'Show preview of ":title"',
        'private' => 'Private',
        'missing' => 'No file',
        'trashed' => 'In the trash',
        // A public image the web server did not serve as one — missing from its disk, refused there, or not an image. An
        // `<img>` cannot tell which, so the words say what happened, never why (Adam, decision 42).
        'unloaded' => 'Did not load',
        // A private tile's status line, by the state its script is in (decision 39): one lower-case word each, as
        // `MediaTileColumn::SAID` explains. `shown` is said to a screen reader alone; the image says it to everyone else.
        'status' => [
            'loading' => 'Loading the preview.',
            'shown' => 'Preview shown.',
            'signedout' => 'You are signed out.',
            'refused' => 'You may no longer see this file.',
            'gone' => 'This file is no longer available here.',
            'failed' => 'The preview could not be loaded. Reload the page and try again.',
        ],
        // After the signed-out words: a link that reloads this page, which sends the editor to sign in and back here
        // (Adam, decision 41).
        'sign_in' => 'Sign in again',
    ],
];
