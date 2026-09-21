# kitsune/person

People as content — authors, contacts, staff — shipped as a first-party Kitsune module.

This is the module the roadmap's Phase 3 line means by *"one hardcoded entity type end to end as a normal
module, to prove the stack"*. It is installed, enabled, upgraded and removed through `kitsune:module`, and it
is the first thing in the repository that is not core.

## What it adds

A **global system entry type**, `person`, with two fields. It creates no tables and ships no models: people are
entries, and entries already have a table. The module's whole footprint is rows in `entry_types`,
`field_storage` and `fields`.

That is deliberate rather than minimal for its own sake. A module that invented its own table to look
substantial would be inventing schema to make a proof prettier, and the proof it is here to give — that the
kernel can install, verify, enable, register and remove a real module — is stronger when there is nothing else
in the way.

## Why the fields are prefixed

`field_storage` carries `unique(['org_id', 'handle'])`, and NULLs compare distinct on every engine, so a
**global** field's handle is claimed install-wide. `person_name` and `person_email` rather than `name` and
`email`: an unprefixed global handle would block every later module and every org that wanted the obvious word.

## Removing it

`kitsune:module uninstall kitsune/person` refuses while any person entry exists. The module knows what its
content is; core does not, so core asks.

## Known limitation: a person is created under "Title"

`EntryResource` hardcodes `title`, `slug` and `status` as the platform columns every entry type gets, so the
create form asks for a **Title** (required) and offers a **Slug** before it reaches `Full name` and `Email`.
For an article that reads correctly. For a person it does not — nobody's title is their name, and a person is
not usually addressable at a URL.

It is recorded rather than worked around because working around it means either a per-type vocabulary on the
platform columns or a module-supplied form, and both are larger decisions than this module should make on its
own. It is also the clearest argument yet that people-as-entries is a starting point rather than an ending
one: the schema fits, and the vocabulary does not.
