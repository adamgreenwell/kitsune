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
# to report, so the verdicts go to stdout, one line each, and the exit status only says whether the
# script itself survived.
#
#   VERDICT <check-id> PASS|FAIL|VOID <reason>
#   RECORD  <check-id> <fact>                      (never a verdict: evidence for the report)
#   SENTINEL <family> <count> <id> [<id> ...]      (the last line, always)
#
# ⚠️ THE SENTINEL IS WHAT MAKES SILENCE FAIL. The scripts are piped into `sudo bash -s` over ssh, so
# a dropped connection, a killed process or an abort truncates the stream mid-family. Without a
# terminator, run.sh cannot tell "this family said nothing because every check passed" from "this
# family died before it spoke", and the second one would exit 0. So every family ends with a
# sentinel carrying its own id count, printed from an EXIT trap so it survives an abort, and run.sh
# treats a missing or short sentinel as VOID for the whole family.
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

# The family this script speaks for, and the ids it promises to emit. run.sh holds the same list in
# manifest.txt; RunbookManifestTest asserts the two agree in both directions.
KITSUNE_FAMILY=""
KITSUNE_EXPECTED=""
KITSUNE_EMITTED=""

# Refuse before measuring anything, the way deploy/release.sh does: a refusal is the operator's
# problem to fix, not a verdict about the host.
refuse() {
  echo "Refusing to check: $*" >&2
  exit 1
}

# Open a family. Every id it may emit is declared here, so the sentinel can be checked against the
# promise rather than against whatever happened to be printed.
family() {
  [[ -n "${1:-}" ]] || refuse "family needs a name"
  KITSUNE_FAMILY=$1
  shift
  KITSUNE_EXPECTED="$*"
  trap kitsune_sentinel EXIT
}

# The last line of the family, printed even when the script aborts, so run.sh can tell a truncated
# stream from a quiet one.
kitsune_sentinel() {
  local status=$?
  local count=0 id

  for id in $KITSUNE_EMITTED; do
    count=$((count + 1))
  done

  # The accumulator grows by prepending a space, which is an implementation detail no parser should
  # have to know: the sentinel prints the ids with exactly one space between them.
  printf 'SENTINEL %s %d %s\n' "$KITSUNE_FAMILY" "$count" "${KITSUNE_EMITTED# }"

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
verdict() {
  local id=$1 outcome=$2
  shift 2

  case "$outcome" in
    PASS | FAIL | VOID) ;;
    *) refuse "verdict $id: [$outcome] is not PASS, FAIL or VOID" ;;
  esac

  case " $KITSUNE_EXPECTED " in
    *" $id "*) ;;
    *) refuse "verdict $id: this family did not declare that id" ;;
  esac

  case " $KITSUNE_EMITTED " in
    *" $id "*) refuse "verdict $id: emitted twice" ;;
    *) KITSUNE_EMITTED="$KITSUNE_EMITTED $id" ;;
  esac

  printf 'VERDICT %s %s %s\n' "$id" "$outcome" "$(kitsune_one_line "$*")"
}

# Evidence that is not a verdict. It reaches the report and never the exit status.
record() {
  local id=$1
  shift

  printf 'RECORD %s %s\n' "$id" "$(kitsune_one_line "$*")"
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
