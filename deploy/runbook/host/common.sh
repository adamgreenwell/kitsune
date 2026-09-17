#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Shared by every host-side check of the ADR-034 runbook (issue #111). It is sourced, never run: a
# check script sources it, declares the check ids it is going to emit, measures, and lets the EXIT
# trap close its own stream.
#
# ⚠️ WHY A VERDICT IS NOT AN EXIT STATUS. These checks report three outcomes, and the third is the
# point: PASS, FAIL, and VOID — "this could not be measured". A host that cannot be measured must
# never read as a host that is correct, so run.sh exits non-zero on a VOID exactly as it does on a
# FAIL. An exit status cannot carry that, and a check that measures a failing command still has more
# to report, so the verdicts go to the family's stdout, one line each, and the exit status only says
# whether the script itself survived.
#
#   VERDICT <check-id> PASS|FAIL|VOID <reason>
#   RECORD  <check-id> <fact>                      (never a verdict: evidence for the report)
#   REFUSED <family> <reason>                      (the family refused to go on; no sentinel follows)
#   SENTINEL <family> <count> <id> [<id> ...]      (the last line of a family that did not refuse)
#
# ⚠️ THE SENTINEL IS WHAT MAKES SILENCE FAIL. The scripts are piped into `sudo bash -s` over ssh, so
# a dropped connection, a killed process or an abort truncates the stream mid-family. Without a
# terminator, run.sh cannot tell "this family said nothing because every check passed" from "this
# family died before it spoke", and the second one would exit 0. So every family ends with a
# sentinel carrying its own id count, printed from an EXIT trap so it survives an abort, and run.sh
# treats a missing or short sentinel as VOID for the whole family. A family that refused is the one
# exception, and `refuse` says why.
#
# ⚠️ AND `set -e` PULLS THE OTHER WAY. A measurement that exits non-zero is ordinary here — it is
# what VOID exists for — but errexit would end the script at that line. Every measurement therefore
# goes through `measure`, which captures the status instead of letting it reach errexit. `refuse` is
# the only thing in this runbook that exits on purpose.
#
# Guards these helpers apply, because each has produced a false reading on this host:
#   - Root, or the socket and process accounting sees a fraction of the host and calls it clean.
#   - Never `test -r` / `[ -x ]` for a permission fact: on Ubuntu 26.04 /usr/bin/test is
#     rust-coreutils, which ignores supplementary groups, so it reports "no" where a real open
#     succeeds. Ownership comes from `stat`, ACLs from `getfacl`, groups from parsing `id -nG`.
#   - Never a shell redirect under sudo (`sudo cmd < /proc/…`): the redirect is opened by the
#     calling user, before sudo runs, and fails as that user.

# The family this script speaks for, and the ids it promises to emit. manifest.txt holds the same list:
# RunbookManifestTest asserts this declaration and the committed manifest agree, and run.sh voids a
# family whose sentinel reports a check the manifest does not promise it.
KITSUNE_FAMILY=""
KITSUNE_EXPECTED=""
KITSUNE_EMITTED=""
KITSUNE_REFUSED=""

# Refuse, the way deploy/release.sh does. A refusal is not a verdict about the host: it is a condition
# the operator has to fix, or a guard below catching this runbook's own bug.
#
# ⚠️ A REFUSAL IS WRITTEN INTO THE VERDICT STREAM, AND THE SENTINEL IS WITHHELD AFTER IT. The exit status
# cannot carry it: a family that measured a FAIL exits 1 too, and over ssh 255 is also ssh's own failure.
# stderr is not judged at all. So when `verdict X-1 FAIL` followed X-1's PASS, the refusal reached only
# stderr, and the EXIT trap closed the stream with a sentinel naming the verdicts already accepted: the
# refused FAIL was simply absent, the count added up, and run.sh passed the run and deleted its streams.
# Now the stream says `REFUSED`, which voids the whole family, and is never closed, so even a gate that
# ignored that line would void it as a stream with no sentinel. An instrument opens no family and has no
# verdict stream, so it refuses on stderr alone.
#
# ⚠️ AND THE LINE REACHES THE STREAM FROM INSIDE A CAPTURE, where the sentinel cannot be withheld: a refusal
# in `$(…)` or a pipeline exits only that subshell. It is written to descriptor 3 (see family), and run.sh
# voids a stream that carries a refusal and still closes.
refuse() {
  echo "Refusing to check: $*" >&2

  if [[ -n "$KITSUNE_FAMILY" ]]; then
    KITSUNE_REFUSED=1
    printf 'REFUSED %s %s\n' "$KITSUNE_FAMILY" "$(kitsune_one_line "$*")" >&3
  fi

  exit 1
}

# Open a family. Every id it may emit is declared here, so the sentinel can be checked against the
# promise rather than against whatever happened to be printed.
#
# ⚠️ AND THE STREAM IS PINNED HERE, TO DESCRIPTOR 3, WHERE EVERY LINE OF THE PROTOCOL IS WRITTEN. These helpers
# printed to whatever stdout was current, and `$(…)` captures that. So in `local site=$(pick_site)`, a
# pick_site that refused put its REFUSED line into $site, `local` masked the exit, the parent's sentinel was
# not withheld, and the run passed. A verdict inside a capture vanished the same way, leaving a later verdict
# for its check to stand alone. Descriptor 3 stays the stream whatever a subshell, a pipeline or a capture
# does with stdout, so the line arrives, and a verdict the tally never counted is caught by run.sh's count.
#
# ⚠️ A JOB THAT MAY OUTLIVE THE FAMILY GIVES DESCRIPTOR 3 UP. Every child inherits it, and the session does not
# end while anything holds it: a background job that is killed rather than waited on, or a child that stays
# running, closes it with `3>&-`, as relays.sh's traffic driver does. A family uses 3 for nothing else.
family() {
  [[ -n "${1:-}" ]] || refuse "family needs a name"
  exec 3>&1
  KITSUNE_FAMILY=$1
  shift
  KITSUNE_EXPECTED="$*"
  trap kitsune_sentinel EXIT
}

# Paths the family wants removed when it ends.
#
# ⚠️ A FAMILY MUST NOT SET ITS OWN `trap … EXIT`. There is one EXIT trap per shell, and a second one
# replaces the first: a family that trapped EXIT to remove its temporary directory would finish
# successfully and print no sentinel, and run.sh would read that as a stream cut mid-family. Register
# the path here instead, and the sentinel's own trap removes it. (Measured: nginx.sh did exactly this
# and emitted no sentinel at all.)
KITSUNE_CLEANUP=""
cleanup_at_exit() {
  KITSUNE_CLEANUP="$KITSUNE_CLEANUP $1"
}

# The last line of the family, printed even when the script aborts, so run.sh can tell a truncated
# stream from a quiet one — and never after a refusal (see refuse).
kitsune_sentinel() {
  local status=$?
  local count=0 id path

  for id in $KITSUNE_EMITTED; do
    count=$((count + 1))
  done

  for path in $KITSUNE_CLEANUP; do
    [[ -n "$path" && "$path" != / ]] && rm -rf "$path"
  done

  # The accumulator grows by prepending a space, which is an implementation detail no parser should
  # have to know: the sentinel prints the ids with exactly one space between them.
  if [[ -z "$KITSUNE_REFUSED" ]]; then
    printf 'SENTINEL %s %d %s\n' "$KITSUNE_FAMILY" "$count" "${KITSUNE_EMITTED# }" >&3
  fi

  exit "$status"
}

# ⚠️ A REASON IS ONE LINE, ALWAYS. The first thing that exercised this file produced a VOID whose
# reason was a captured stdout-and-stderr pair — two lines — so the stream carried a verdict line
# followed by a line no parser would recognise. A check reporting a multi-line failure is the normal
# case, not the exotic one, so the collapse happens here rather than at every call site. The full
# text stays in MEASURED for whoever is reading the log.
kitsune_one_line() {
  local text=$*

  text=${text//$'\r'/ }
  text=${text//$'\n'/ }
  text=${text//$'\t'/ }

  printf '%s' "$text"
}

# One verdict. The reason is printed for every outcome, including PASS, because a PASS whose reason
# reads as "nothing to check" is how a vacuous check announces itself to the person reading the log.
#
# ⚠️ A REFUSED VERDICT IS QUOTED, NOT DROPPED. Each guard below named only the id, so a FAIL it refused — a
# second verdict for a check, or one for a check nobody declared — appeared nowhere: not in the report, the
# kept stream or stderr, and the only measurement run.sh could quote for that check was the PASS before it.
# The refusal now carries the verdict as it was called, starting `verdict` in lower case, which neither
# run.sh's verdict patterns nor its search for a glued verdict can match.
verdict() {
  local id=$1 outcome=$2
  shift 2
  local reason refused
  reason=$(kitsune_one_line "$*")
  refused="verdict $id $outcome [$reason]"

  case "$outcome" in
    PASS | FAIL | VOID) ;;
    *) refuse "$refused: [$outcome] is not PASS, FAIL or VOID" ;;
  esac

  case " $KITSUNE_EXPECTED " in
    *" $id "*) ;;
    *) refuse "$refused: this family did not declare that id" ;;
  esac

  case " $KITSUNE_EMITTED " in
    *" $id "*) refuse "$refused: emitted twice" ;;
    *) KITSUNE_EMITTED="$KITSUNE_EMITTED $id" ;;
  esac

  printf 'VERDICT %s %s %s\n' "$id" "$outcome" "$reason" >&3
}

# Evidence that is not a verdict. It reaches the report and never the exit status.
record() {
  local id=$1
  shift

  printf 'RECORD %s %s\n' "$id" "$(kitsune_one_line "$*")" >&3
}

# ⚠️ THE ONLY WAY A CHECK RUNS A COMMAND. It captures stdout and the status, so a non-zero status is
# data rather than the end of the script, and so a command's stderr cannot be mistaken for a verdict
# line on stdout. Usage:
#
#   if measure ss -Htlnpe; then parse "$MEASURED"; else verdict NGX-4 VOID "ss failed: $MEASURED"; fi
#
# MEASURED holds stdout on success, and stdout plus stderr on failure — the failure text is what the
# VOID reason needs to name.
MEASURED=""
measure() {
  local output status

  output=$("$@" 2>&1)
  status=$?
  MEASURED=$output

  return "$status"
}

# ⚠️ WHEN THE OUTPUT IS DATA, STDERR MUST NOT BE MIXED INTO IT. `measure` merges the two on purpose,
# because a VOID reason needs whatever the command complained about. But a command whose stdout is
# the thing being parsed must not have its stderr folded in: `nginx -T` writes "nginx: the
# configuration file … syntax is ok" to stderr, and merging it made the configuration tokenizer read
# `nginx:` as a directive — which then swallowed the first real directive of the file. Stubs printed
# only the configuration, so no fixture could show it; the live server did, on the first run.
#
#   if ! measure_into "$work/dump" nginx -T; then verdict NGX-1 VOID "nginx -T failed: $MEASURED"; fi
#
# Stdout goes to the file; MEASURED holds stderr, which is what the refusal or VOID reason needs.
measure_into() {
  local file=$1
  shift
  local status

  MEASURED=$("$@" 2>&1 >"$file")
  status=$?

  return "$status"
}

# ⚠️ EVERY MEASUREMENT GETS A CLOCK, BECAUSE A CHECK THAT HANGS IS A CHECK NOBODY RUNS. Twice on
# stage a family passed a five-minute ceiling without emitting a verdict, and both times the cause was
# a command that was slower than anyone expected rather than a host that was wrong. So a measurement
# that outlives its budget is VOID — "could not be measured in N seconds" — which is a verdict the
# operator can act on, unlike a session that never returns.
#
#   if ! measure_in 10 ss -Htnpe; then verdict RLY-4 VOID "$MEASURED"; fi
#
# `timeout` exits 124 when it fires, and MEASURED then says so in the words the verdict will carry.
measure_in() {
  local budget=$1
  shift
  local status

  measure timeout "$budget" "$@"
  status=$?

  if (( status == 124 )); then
    MEASURED="[$*] did not finish within ${budget}s"
  fi

  return "$status"
}

# The same, for a command whose stdout is data (see measure_into).
measure_into_in() {
  local budget=$1 file=$2
  shift 2
  local status

  measure_into "$file" timeout "$budget" "$@"
  status=$?

  if (( status == 124 )); then
    MEASURED="[$*] did not finish within ${budget}s"
  fi

  return "$status"
}

# Root, or this family measures a fraction of the host and calls it clean: /proc/<pid>/exe is
# unreadable for other users' processes, ss prints no owner for them, and journalctl shows only this
# user's entries. Every one of those reads as "nothing found".
require_root() {
  [[ "$(id -u)" == 0 ]] || refuse "run as root: unprivileged reads of /proc, ss and journalctl return partial answers that look clean"
}

# The permission facts, read the way that survives rust-coreutils: ownership and mode from stat, ACLs
# from getfacl when it exists, group membership by parsing id -nG. Never `test -r`.
owner_triple() {
  local path=$1

  stat -c '%U %G %a' "$path"
}

# Whether a user is in a group, by the list the kernel would use, not by a test(1) answer.
user_in_group() {
  local user=$1 group=$2

  case " $(id -nG "$user" 2>/dev/null) " in
    *" $group "*) return 0 ;;
    *) return 1 ;;
  esac
}
