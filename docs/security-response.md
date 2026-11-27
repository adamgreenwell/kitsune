# Security response and continuity

This runbook supports [SECURITY.md](../SECURITY.md) and [#212](https://github.com/adamgreenwell/kitsune/issues/212). Its procedures are distinct from an executed rehearsal. Keep the current readiness receipt honest: an unperformed, failed or partially observed step stays open.

## Responsibility

| Responsibility | Primary owner | Required evidence |
|---|---|---|
| Private intake and acknowledgement | Adam Greenwell (`adamgreenwell`) | Actual private-report visibility, an observed notification and human acknowledgement. |
| Assessment and private patch coordination | Adam Greenwell | An assessed scope, affected-source/version record, a private coordination plan and reporter communication. |
| Release and disclosure decisions | Adam Greenwell | Exact qualified fix/artifacts, support and upgrade guidance, operator notice/window and an approved publication sequence. |
| Continuity | No named, qualified delegate | No backup staffing or delegated account/release authority is implied. |

The same person currently holds all primary roles. This does not add staff or promise 24-hour monitoring. A future delegate must consent and have their private access and notifications rehearsed before the responsibility record changes. Governance’s second- and third-maintainer milestones remain as written.

## Intake and privacy

Use the repository’s private vulnerability-reporting form linked from SECURITY.md. Check the feature is enabled when preparing a rehearsal. The form and policy are separate controls; an enabled feature does not prove notification delivery.

Repository owners and other eligible security roles can access advisories, and invited collaborators gain advisory access. Review actual participants before adding sensitive details. Normal advisory comments can be visible to the reporter and collaborators. Confidential comments depend on repository write access; do not assume an external reporter can read them. Use a reporter-visible private comment for acknowledgements and questions intended for that reporter.

Keep customer data, credentials, production configuration and unnecessary exploit material out of the exercise and general logs. Do not copy a real report into a public issue, public CI log, broad chat or unrelated artifact collection. The detail-free fallback in SECURITY.md remains available when a private channel cannot be used.

GitHub’s current guidance distinguishes a researcher’s private-report flow from an administrator creating a draft advisory. A maintainer-authored draft alone does not exercise external intake. Private submission also adds the reporter as an advisory collaborator and pending credited participant; account for that effect in an agreed rehearsal.

## Assessment and private coordination

1. Record the receipt time and actual responsible person. Acknowledge within the existing five-working-day target and give an initial assessment within the existing ten-working-day target. These remain honest targets, not contractual response guarantees.
2. Establish reachability, affected source/package versions and impact without publishing a working exploit. Org and Site isolation failures retain SECURITY.md’s highest-severity treatment; this runbook adds no exclusions or severity downgrades.
3. Reproduce only in an appropriately isolated, authorized environment with synthetic data. Preserve unsuccessful attempts and actual limits. An existing test or asserted control is not proof that a reported issue is safe.
4. Agree private participants, review responsibility and the patch/test plan. Record exact source and dependency inputs. If using an advisory private fork or adding collaborators, agree its permissions and actions first; a written plan is not evidence that a fork or access grant happened.
5. Communicate through a reporter-visible private channel. Distinguish questions, assessment, mitigation advice and a verified fix. Do not claim that a draft patch, successful CI job or future release has already protected operators.

Follow the [versioning and upgrade policy](versioning-and-upgrades.md). There is currently no supported stable release; 0.x reports are welcome without inventing a stable-version maintenance promise. For future stable releases, use the maintainer-selected latest-stable-minor/latest-patch model and the actual supported-version record. Do not infer a support duration, backport line or upgrade origin from a tag.

## Release and disclosure sequence

Keep the fix private while assessing and coordinating it. Before a real release, qualify its exact source, artifacts, affected versions and supported upgrade path, and agree any mitigation and operator communication.

The release owner chooses the actual operator upgrade window with the reporter and records the sequence: verified fix and authorized release, operator guidance/window, then authorized advisory publication. Do not invent a numeric disclosure deadline or publish exploit details because a timer expired. Respect reporter credit or anonymity, and do not require an NDA.

Signing, tags, package publication, deployment, CVE requests and public advisories are real operations with their own approval and verification. A rehearsal uses none of them. A published advisory is not proof that affected installations were upgraded.

## When the maintainer is unavailable

There is no silently staffed backup. With no available authorized owner, intake acknowledgement may be late and security release/disclosure decisions pause. Do not grant access, forward a private report or transfer signing/release authority automatically.

On return, the responsible owner checks the private queue, records the delay, acknowledges affected reporters and revalidates notifications before treating intake as operational again. Do not promise that an availability notice will be published while nobody is available to publish it.

A proposed continuity delegate needs explicit consent, an agreed responsibility scope, appropriate access and an actual notification/handoff rehearsal. Record those facts before naming that person as coverage. The current absence of a delegate must remain visible.

## Benign rehearsal and readiness receipt

Agree the people and exact execution packet before any real message or account change. Use a consenting external reporter without repository admin/security permissions, the actual primary owner, and a logged-out view for the limited public-visibility negative control. A draft created by the owner is a different path.

The exercise must clearly say that it reports **no actual vulnerability**, contains no exploit, customer data or secret, and requests no CVE, public advisory, release, deployment or repository permission grant.

| Step | Evidence needed | What does not establish success |
|---|---|---|
| Private submission | Agreed reporter, exact benign payload, private record returned in the expected intake state, reporter-visible readback. | A prepared packet or owner-created draft. |
| Maintainer visibility | Actual named owner opens that same record and sees the agreed content and participants. | Repository ownership or an API permission flag. |
| Notification | Actual owner observes the agreed GitHub/web or email notification and records its time/channel without exposing addresses or unrelated inbox content. | GitHub saying a notification was sent, or a readable report. |
| Human acknowledgement | The named owner explicitly acknowledges the unique exercise ID; reporter-visible reply is verified. | An assistant’s acknowledgement, automated API response or inferred unread count. |
| Public negative control | Logged-out access does not reveal the private body; scope the claim to that observed control. | An assumption that every other account has identical permissions. |
| Assessment and coordination | The owner works through a synthetic scenario, records scope/version/support decisions, agrees a private patch/review plan and confirms the reporter can see the intended communication. | An unexecuted checklist or a claim that a real patch/fork exists. |
| Publication sequencing | The owner records the fix → operator guidance/window → advisory sequence and identifies the separate execution gates; nothing is published. | A CVE request, release or public test advisory. |
| Absence scenario | The owner confirms the single-maintainer pause/delayed-acknowledgement path, without inventing a delegate or testing unauthorized account takeover. | Assuming another person will monitor the inbox. |
| Closure | Agreed private closure explains that this was an exercise; its actual state is independently read back. | Deleting the evidence or saying it was a remediated vulnerability. |

A notification read blocked by token scope is **unverified**, not evidence that notifications are disabled. Do not expand credentials or alter notification settings just to produce a passing receipt. The responsible human can inspect the actual preferences and witness delivery.

Record only a minimal sanitized readiness receipt: unique exercise ID, UTC times, roles/consent confirmation, observed channels, explicit human acknowledgement, per-step pass/fail/not-run, exact policy/source fingerprints and remaining limitations. Keep private URLs, account/contact details and report content in the agreed protected record; public issue updates should contain only the sanitized outcome.

All original #212 acceptance items remain open until the actual ownership decision, merged procedure and independently verified rehearsal evidence satisfy them. A policy, proposal, CI result or enabled feature alone does not complete the operational process.

## Platform references

- [Private reporting and reporter workflow](https://docs.github.com/en/code-security/how-tos/report-and-fix-vulnerabilities/report-privately)
- [Managing reports and notification prerequisites](https://docs.github.com/en/code-security/how-tos/report-and-fix-vulnerabilities/fix-reported-vulnerabilities/manage-vulnerability-reports)
- [Advisory permissions and collaborator visibility](https://docs.github.com/en/code-security/reference/permissions/repository-security-advisory)
