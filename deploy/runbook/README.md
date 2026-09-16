# The ADR-034 runbook

ADR-034 makes the skeleton trust `127.0.0.1` and `::1` for `X-Forwarded-For` only. That is safe while
a list of properties holds on the server itself, and those properties are not Kitsune's to test: no
unit test can see whether a second relay reaches nginx over loopback, or whether sshd still refuses
port forwarding for a session opened yesterday. Issue #111 lists them. This directory checks them.

## What a verdict means

Every check reports one of three outcomes, and the third is the point.

| Verdict | Meaning |
|---|---|
| **PASS** | The condition was measured, and it holds. |
| **FAIL** | The condition was measured, and it does not hold. |
| **VOID** | The condition **could not be measured**. |

A run exits non-zero on a VOID exactly as it does on a FAIL, because a host that could not be
measured must never read as a host that is correct. Every check is written so that its own failure
mode is VOID rather than a quiet PASS — the design's long review found that checks fail by passing
vacuously far more often than by missing a condition outright.

Three rules make silence fail with everything else:

- **`manifest.txt` is a promise.** It lists the check ids that must produce a verdict, per topology.
  An id with no verdict is VOID. It is committed and read as an input, never generated from a run.
- **Every family that does not refuse ends with a sentinel** carrying its own verdict count, printed
  from an EXIT trap. The host scripts are piped over ssh, so a dropped connection truncates a family
  mid-stream. Each family is judged on its own stream. A sentinel that is missing, printed twice,
  malformed or short voids that whole family, and so does one that names a check twice or reports a
  check the manifest does not promise, or a verdict the sentinel does not name. A check given two
  verdicts is VOID, never the last of them, and a voided check still shows every verdict its family
  gave it. A run that does not pass keeps each family's raw output and says where.
- **A refusal is written into the stream.** A family that refuses — a precondition it cannot meet, or
  a verdict its own guard rejects — prints `REFUSED <family> <reason>` and withholds its sentinel, and
  either one voids the whole family with the reason shown. A refusal exits 1, as a FAIL does, so the
  exit status cannot say it, and a refused verdict is never printed, so without this a refusal after
  every promised verdict left a stream that added up.

## Running it

```bash
deploy/runbook/run.sh --host forge@stage.example --expect tunnel --token-file ~/.config/kitsune/cloudflare-runbook-token
```

- `--expect tunnel` for a host behind a Cloudflare Tunnel, `--expect dns-only` for a Forge server with
  no tunnel. The flag is **asserted equal** to what the checks measure, never used to choose
  behaviour: a host that looks like a tunnel from outside and has no connector on it is exactly the
  case ADR-034 forbids, and a flag would hide it.
- `--token-file` names a **read-only** Cloudflare API token. Without it, the Cloudflare checks are
  VOID and the run cannot report success; the checks that measure the running host still run.
- The host needs **passwordless sudo** for this user. The scripts arrive on the remote shell's stdin,
  so a sudo that prompted would read the password from the script itself.

## Layout

| Path | What it is |
|---|---|
| `run.sh` | The orchestrator: dispatch, the completeness gate, the exit code. Runs on the operator's machine. |
| `manifest.txt` | The promised check ids, per topology. |
| `host/common.sh` | Verdicts, records, the sentinel, and the guards every host check needs. Sent ahead of each host family in the same stream, never run on its own. |
| `host/*.sh` | One file per family that must run **on** the server, as root, piped over ssh. |
| `host/probe-log.sh` | Not a family: the instrument `outside/tunnel-log.php` drives to see what nginx received. The one part of the runbook that changes a live server, and it undoes itself. |
| `outside/*.php` | One file per family that must reach the server from somewhere else, over the real network. Runs on the operator's machine. |

Tests live in `tests/Core/Release/Runbook*Test.php` and run the real scripts against fixtures, in the
style of `ReleaseScriptTest`: a deploy script is prose until something executes it.

## How it is being built

Family by family, each proven on the stage server before the next begins, because the design's own
history is that these checks stay wrong while they are prose. `manifest.txt` lists a family only when
its script exists, and the tests assert that the ids the scripts can emit and the ids the manifest
promises are the same set — so a family cannot land without being promised, and cannot be promised
without landing.

The order, and why: the plumbing and the completeness gate first, since nothing else is trustworthy
until an unrun family fails; then nginx, relays, the probe log and tunnel hostnames, the PHP-level
throttle, sshd (last of the host families — it restarts a live daemon), and the Cloudflare inventory,
which waits on the operator's token.
