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
