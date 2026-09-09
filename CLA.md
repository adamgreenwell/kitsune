# Contributor License Agreement

> **Not yet in force.** This is the text that will be required before the first external pull request is merged. It has not been reviewed by a lawyer. **Do not treat it as legal advice, and it should be reviewed by counsel before the first signature is collected.** Issues and discussion require no agreement and never will.

## Why this exists

A project asking you to sign something owes you a straight explanation, so here it is.

**Kitsune may be dual-licensed in the future.** The core is [MPL-2.0](LICENSE) and will stay open source — [`GOVERNANCE.md`](GOVERNANCE.md) commits to that permanently. But some organisations cannot accept copyleft of any kind, and selling them a separate commercial licence is a legitimate way to fund maintenance. Doing that requires holding or being licensed all rights in the codebase.

**In practical terms: signing means your contribution could end up in a commercially licensed version of Kitsune that someone pays for.** You keep the copyright to your work. You grant the project the rights it needs to relicense.

If that is not acceptable to you, that is a completely reasonable position and you should not sign. Contribute through issues and discussion instead — that is genuinely valuable and requires nothing.

**Why now rather than later:** the first pull request merged without a CLA permanently forecloses this option for that code, absent tracking down every past contributor for a signature. Some never answer. Some say no. It is one of very few decisions in this project that cannot be reversed ([ADR-005](docs/decision-log.md)).

**Why not a DCO:** a Developer Certificate of Origin certifies you have the right to submit the code. It does not grant relicensing rights, so it would quietly close the door this exists to keep open.

---

## Individual Contributor License Agreement

By signing, You accept and agree to the following terms for Your present and future Contributions to Kitsune. Except for the licences granted here, You reserve all right, title and interest in and to Your Contributions.

**1. Definitions.**

"You" means the copyright owner, or the legal entity authorised by the copyright owner, entering into this Agreement.

"Contribution" means any original work of authorship, including any modifications or additions to an existing work, that is intentionally submitted by You to the Project for inclusion in, or documentation of, any of the products owned or managed by the Project.

"Submitted" means any form of electronic, verbal or written communication sent to the Project, including but not limited to communication on electronic mailing lists, source code control systems and issue tracking systems, excluding communication conspicuously marked or otherwise designated in writing by You as "Not a Contribution."

**2. Grant of Copyright Licence.** Subject to the terms of this Agreement, You grant to the Project and to recipients of software distributed by the Project a perpetual, worldwide, non-exclusive, no-charge, royalty-free, irrevocable copyright licence to reproduce, prepare derivative works of, publicly display, publicly perform, sublicense, and distribute Your Contributions and such derivative works.

**3. Grant of Patent Licence.** Subject to the terms of this Agreement, You grant to the Project and to recipients of software distributed by the Project a perpetual, worldwide, non-exclusive, no-charge, royalty-free, irrevocable (except as stated in this section) patent licence to make, have made, use, offer to sell, sell, import and otherwise transfer the Work.

This licence applies only to those patent claims licensable by You that are necessarily infringed by Your Contribution alone or by combination of Your Contribution with the Work to which it was submitted.

If any entity institutes patent litigation against You or any other entity alleging that Your Contribution, or the Work to which You have contributed, constitutes direct or contributory patent infringement, then any patent licences granted to that entity under this Agreement for that Contribution or Work terminate as of the date such litigation is filed.

**4. Right to grant.** You represent that You are legally entitled to grant the above licences. If Your employer has rights to intellectual property that You create, You represent that You have received permission to make Contributions on behalf of that employer, that Your employer has waived such rights, or that Your employer has executed a separate Corporate CLA with the Project.

**5. Original work.** You represent that each of Your Contributions is Your original creation. You represent that Your Contribution submissions include complete details of any third-party licence or other restriction of which You are personally aware and which are associated with any part of Your Contributions.

**6. Support and warranty.** You are not expected to provide support for Your Contributions, except to the extent You desire to provide support. **Unless required by applicable law or agreed to in writing, You provide Your Contributions on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND**, either express or implied, including, without limitation, any warranties or conditions of TITLE, NON-INFRINGEMENT, MERCHANTABILITY, or FITNESS FOR A PARTICULAR PURPOSE.

**7. Notification.** You agree to notify the Project of any facts or circumstances of which You become aware that would make these representations inaccurate in any respect.

---

## Corporate Contributor License Agreement

Where contributions are made by employees in the course of their employment, an authorised representative of the entity signs the same terms above on the entity's behalf, and additionally:

- Schedules the initial list of employees designated to submit Contributions on the entity's behalf
- Agrees to notify the Project when that list changes
- Represents that each designated employee is authorised to submit Contributions under this Agreement

The corporate variant is otherwise identical to the individual terms.

---

## Signing

Signing happens on your first pull request — a bot comments with a link, you agree once by
replying to it, and it never asks again. Your GitHub username and the date are recorded on a
separate `cla-signatures` branch.

⚠️ **What the signing bot records, stated exactly rather than approximately.** Read out of the
pinned action's own source (`contributor-assistant/github-action` v2.6.1) rather than from its
documentation, because a privacy claim taken on trust is not a claim:

| Field | What it is |
|---|---|
| `name` | your GitHub login |
| `id` | your GitHub numeric user id |
| `comment_id` | the id of the comment you signed with |
| `body` | the signing sentence itself, lowercased |
| `created_at` | when you signed |
| `repoId` | this repository's numeric id |
| `pullRequestNo` | the pull request you signed on |

**No email address, no postal address, no employer, and no name beyond your GitHub login.**

An earlier draft of this section claimed only a username and a date were stored. That was
wrong — review caught it, and verifying against the source found one field (`body`) that the
review had not listed either. The reasoning `pii_class` applies to an **org's** fields
(ADR-020) applies to the project's own records too, and it starts with saying accurately what
is held.

⚠️ **The bot is wired up and deliberately switched off.** `.github/workflows/cla.yml` is gated
on a repository variable, so enabling it is a settings change rather than a code change — and
it must stay off until this text has been through counsel. A bot collecting signatures against
an unreviewed agreement produces a record that *looks* like consent and may not be, which is
worse than collecting nothing.

Until then, external pull requests are not being accepted, so there is nothing to sign for.

---

*Derived from the Apache Software Foundation Individual Contributor License Agreement v2.0, which is offered for reuse. Adapted for Kitsune. Not reviewed by counsel.*
