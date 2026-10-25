<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

return [
    'address_help' => 'The entry\'s address on this site. Entries shared across the organisation have none.',
    'type' => 'Type',
    'saved' => 'Saved',
    'note' => 'Note',
    'restored' => 'Restored',
    'owner' => 'Owner',
    'role_holders_help' => 'Everybody in this organisation who has this role. Assigning one is recorded.',
    'grants' => 'Grants',
    'held_by' => 'Held by',
    'type_handle_description' => 'The handle appears in URLs and in the API, and cannot be changed once entries exist.',
    'type_handle_help' => 'Lowercase, no spaces. Used in /c/{type} and in the API.',
    'singular_name' => 'Singular name',
    'icon' => 'Icon',
    'icon_help' => 'Shown in the sidebar. Leave empty for the default.',
    'subject_description' => 'ADR-020: "everything you hold about this person" is unanswerable without knowing which field identifies the person.',
    'subject_identifier' => 'Subject identifier',
    'subject_unavailable' => 'Available once the type has fields.',
    'fields' => 'Fields',
    'system' => 'System',
    'subject' => 'Subject',
    'locked_field_description' => 'Locked once entries hold data: converting a field in place is how content gets destroyed quietly.',
    'handle' => 'Handle',
    'field_handle_help' => 'Lowercase snake_case. Becomes the JSON key and, if indexed, part of a column name.',
    'field_type' => 'Field type',
    'immutable_type_help' => 'Cannot change once the field exists — create a new field, convert, verify, then drop the old one.',
    'values' => 'Values',
    'maximum_values' => 'Maximum values',
    'cardinality_help' => 'Stored as the cardinality, so it is part of the locked shape.',
    'pii_description' => 'ADR-020: an unclassified field does not save. Only you know whether "Customer notes" holds personal data.',
    'personal_data' => 'Personal data',
    'shared_pii_help' => 'Shared with every entity type using this storage, so it is not editable here.',
    'suggested_pii_help' => 'Suggested by the field type; confirm it, because the platform cannot know.',
    'fieldset_help' => 'Optional grouping in the entry form.',
    'index_description' => 'Every indexed field costs write throughput on every entry save.',
    'index_field' => 'Index this field',
    'index_help' => 'Adds a generated column and an index so this field can be filtered and sorted efficiently.',
    'indexed' => 'Indexed',
    'locked' => 'Locked',
    'field_save_failed' => 'That field could not be saved',
    'field_index_failed' => 'The field was saved, but it could not be indexed',
    'recent_heading' => 'Recently updated',
    'updated' => 'Updated',
    'recent_empty' => 'Nothing has been written yet',
    'counts_create' => 'None yet. Create one to start adding content.',
    'counts_unshared' => 'No entry type on this site is shared with you yet.',
    'title' => 'Title',
    'status' => 'Status',
    'entry_types' => 'Entry types',
    'content' => 'Content',
    'none_yet' => 'None yet',
    'list_separator' => ', ',
    'counted_status' => [
        'draft' => ':count draft',
        'published' => ':count published',
        'archived' => ':count archived',
    ],
    'roles' => [
        'owner_help' => 'Bypasses every permission check, including the ones protecting this page and the entry type builder. Give it to people who administer the organisation.',
        'every_type_help' => 'A type created next month is covered by these the day it appears. Leave them empty and grant per type below.',
        'assignment_unavailable' => 'Assignment is unavailable: this panel authenticates against a model that role_user does not reference, so the ids here would name different people.',
        'permissions_named' => 'Permissions named entry.:type.{action}',
    ],
];
