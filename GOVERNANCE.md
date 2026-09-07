# Governance

This document exists before there is anything to govern. That's deliberate.

Winter CMS forked October CMS in March 2021 over **process** — maintainers doing the work while decisions were made without them — five weeks before the licence change everyone remembers as the cause. Licensing gets blamed. Governance is usually the actual trigger.

So here is how Kitsune is run, written down while it costs nothing.

---

## Where this project actually is

**One person maintains Kitsune.** It is a benevolent-dictator project, and pretending otherwise would be a lie you could verify in about thirty seconds of commit history.

There is no steering committee, no vote, no foundation. If you are evaluating whether to build on this, **the bus factor is currently one.** That is a real risk and you should weigh it. It is also the single thing most likely to change first, and the section on succession below says what happens meanwhile.

What this project has instead of a committee is an unusual amount of written reasoning — see below.

## How decisions get made

**[`docs/decision-log.md`](docs/decision-log.md) is the governance mechanism.**

Every architectural decision is recorded with the alternatives that were considered and the specific reason each one lost. Decisions that were later disproven stay in the log as failures rather than being quietly edited out — ADR-003 and ADR-012 are both plans that got reversed, one of them by a measurement spike that took two hours and invalidated a month of reasoning.

This binds the maintainer, not just contributors:

- **A decision that isn't in the log isn't made.** If reasoning isn't written down, it can be challenged and it will be
- **Changing a settled decision means amending its ADR**, with the old reasoning left visible. That applies to the maintainer identically
- **Direction is not announced retroactively.** Winter's stated grievance was *"plans and updates that had apparently been in motion for months were thrust upon the maintenance team without warning."* A public log with reasoning makes that structurally hard

To challenge a decision, open an issue titled `ADR-0XX: <what should change>` and bring evidence — measurements, prior art, a failing case. Good arguments have already changed decisions here. So has one adversarial spike.

## Commitments

These are binding. They are the promises other projects broke, listed so you can hold this one to them.

**On the licence**

1. Kitsune core will remain under an OSI-approved open source licence, permanently. It will never move to BSL, to a source-available licence, or to a bespoke licence.
2. If core is ever relicensed, it will only be to a **more** permissive OSI licence, or a later version of the MPL.
3. Everything already released stays released. A future licence cannot retroactively close what has shipped.

**On the open/paid line**

4. The line between free core and paid modules is declared publicly and **does not move**. What is free stays free.
5. **No feature will be removed from open core to create a paid version.** Paid modules add capability; they never re-sell something taken away.

**On your data and your freedom to leave**

6. A working data export path is always present, always free, and never degraded. Whether you self-host or pay for hosting, you can leave with everything.
7. **No telemetry without explicit opt-in**, with a visible disclosure of exactly what is sent (ADR-020).

**On forking**

8. You may fork Kitsune. The MPL guarantees it and that is a feature, not a threat. What you may not do is call your fork Kitsune — the trademark is separate from the code licence, and it exists so that "Kitsune" means one predictable thing. Fork the code freely; pick your own name.

## Conflict of interest

**The maintainer operates a commercial hosted service built on Kitsune** (see ADR-004). That is a structural conflict of interest, and every project that pretended otherwise eventually spent the trust it was hiding.

The commitment: **where a decision benefits the hosted service and the open project differently, the ADR says so explicitly.** You will not have to infer it.

If you think a decision has been made for commercial reasons that were not disclosed, say so publicly in an issue. That is a legitimate question and it will get a straight answer.

## Becoming a maintainer

There is no points system and no vote. The path is ordinary:

1. Contribute consistently and well over a period of months — code, review, triage, documentation, or translation all count
2. Demonstrate judgment about **what not to build**, which matters more here than throughput
3. Get invited

Commit rights come with real responsibility for the security invariants in [`CONTRIBUTING.md`](CONTRIBUTING.md) — multi-tenant isolation especially, where a mistake is a customer-data incident rather than a bug.

## Succession and continuity

The honest current state: one person, and that person could be hit by a bus.

Concrete commitments, in order of when they become possible:

- **Now:** the licence guarantees continuity of the code regardless of what happens to the maintainer. Anyone can fork MPL-2.0 code and continue. That is the floor, and it is not nothing
- **On the second maintainer:** repository, Packagist and domain access are held by more than one person. Not promised eventually — done at that milestone
- **On the third maintainer:** a written continuity plan naming who acts if the maintainer is unreachable for 90 days, and a documented process for them to act
- **Ongoing:** no critical infrastructure depends on a single personal account where a shared one is possible

If you are an organisation whose adoption depends on continuity guarantees stronger than this, say so in an issue. That is a reasonable requirement and it is useful to know how many people have it.

## What would change this model

Written down now so it isn't a surprise later. Any of these would trigger a governance revision, publicly, with an ADR:

- **Three or more active maintainers** — decision-making becomes consensus among maintainers, with the founder breaking ties
- **A second organisation contributing substantially** — a written conflict-of-interest and roadmap-input process
- **Meaningful revenue** — public accounting of what funds the project and what that money is committed to
- **A serious fork** — a public post-mortem of what governance failure allowed it

## Code of conduct and its honest limitation

[`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md) applies to every project space.

The limitation, stated plainly: **with one maintainer, the person who receives conduct reports is also the person you might need to report.** That is a real gap and no wording dissolves it. Until a second maintainer exists, anyone uncomfortable reporting directly may raise it publicly in an issue, and that will not be held against them.

## Security

Do not open a public issue for a vulnerability. See [`SECURITY.md`](SECURITY.md).

Multi-tenant data isolation is the highest-severity category in this project and will be treated as such.

---

*This document changes as the project does. Its history is in git, so you can see what changed and when.*
