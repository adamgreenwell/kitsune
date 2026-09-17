#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Run the ADR-034 host-condition checks against one server and decide whether it passed — issue #111.
# ADR-034 makes the skeleton trust 127.0.0.1 and ::1 for X-Forwarded-For only, and that is safe only
# while a list of properties holds on the host itself. Those properties are not Kitsune's to test:
# no unit test can see whether another relay reaches nginx over loopback. This runs on the operator's
# machine, drives the checks, and refuses to call a host correct on anything less than a measurement.
#
# Every input arrives as an option, and the ones that decide anything are required:
#
#   --host <user@host>     the server to check, as ssh reaches it (required)
#   --expect tunnel|dns-only   the topology the operator believes this host has (required)
#   --manifest <path>      the promise file (default: deploy/runbook/manifest.txt)
#   --token-file <path>    a Cloudflare read-only token file; without it every CF check is VOID
#
# ⚠️ WHAT A VERDICT MEANS. PASS, FAIL, and VOID — "this could not be measured". A run exits non-zero
# on a VOID exactly as on a FAIL, because a host that could not be measured must never read as a host
# that is correct. That rule is the whole design: every check below is written so that its failure
# mode is VOID rather than a quiet PASS.
#
# ⚠️ AND SILENCE IS VOID TOO. The host scripts are piped into `sudo -n bash -s` over ssh, so a
# dropped connection, a killed process or an abort truncates a family mid-stream. Two things stop
# that reading as success: the manifest, which promises which ids must arrive, and each family's
# sentinel, which carries its own id count and is printed from an EXIT trap. A missing or short
# sentinel voids the whole family, and an id in the manifest with no verdict is VOID by itself.
#
# ⚠️ AND A REFUSAL IS READ FROM THE STREAM, NEVER FROM AN EXIT STATUS. A family that refuses exits 1, as
# one that measured a FAIL does, and over ssh 255 is also ssh's own failure. So a refusing family writes
# `REFUSED <family> <reason>` into its stream and withholds its sentinel, and either one voids it here.
#
# ⚠️ `sudo -n`, NOT `sudo`. The script arrives on the remote shell's stdin. A sudo that decided to
# prompt would read the password from that stdin — that is, it would eat the first line of the check
# and then fail — so the runbook requires passwordless sudo for this user and says so when it is
# missing, rather than hanging or half-running.
#
# ⚠️ THE TOPOLOGY IS MEASURED, NEVER TAKEN FROM --expect. The flag is asserted equal to what the
# checks measure from both ends; a host that looks like a tunnel from outside and has no connector
# on it is exactly the case ADR-034 forbids, and a flag would hide it. Until the topology family
# lands, --expect only selects which manifest rows are promised, and run.sh says so in its output.
set -euo pipefail

refuse() {
  echo "Refusing to run: $*" >&2
  exit 1
}

here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)

# Every family is sent as this file followed by the family's own, in one stream (see the dispatch
# loop for why it cannot be sourced on the far side).
common="$here/host/common.sh"

host=""
expect=""
manifest="$here/manifest.txt"
token_file=""

while (( $# > 0 )); do
  case "$1" in
    --host) host=${2:-}; shift 2 || refuse "--host needs a value" ;;
    --expect) expect=${2:-}; shift 2 || refuse "--expect needs a value" ;;
    --manifest) manifest=${2:-}; shift 2 || refuse "--manifest needs a value" ;;
    --token-file) token_file=${2:-}; shift 2 || refuse "--token-file needs a value" ;;
    *) refuse "unknown option: $1" ;;
  esac
done

[[ -n "$host" ]] || refuse "--host is required"
[[ "$expect" == tunnel || "$expect" == dns-only ]] || refuse "--expect must be tunnel or dns-only, not [$expect]"
[[ -f "$manifest" ]] || refuse "the manifest $manifest does not exist"

# Checked here, with the other options, so a mistyped path is reported as a mistyped path. Left
# later, it would be reached only after the manifest had already refused for its own reason.
[[ -z "$token_file" || -f "$token_file" ]] || refuse "the token file $token_file does not exist"

# ⚠️ ONE LIST, NOT TWO. The families to run are derived from the manifest itself, because a separate
# table would be one more thing to drift apart: a family in the table but not the manifest runs and
# is never checked for completeness, and a family in the manifest but not the table is promised and
# never dispatched — which the completeness gate would report as VOID, blaming the host for a bug in
# this file. The script for a family is host/<family>.sh, by convention.
families=()

# 1. The promise. Rows are "<topology> <family> <check-id>"; comments and blank lines are ignored.
expected=()
# Every check id seen so far, as " <id>=<family> ", across both topologies.
owners=" "
# A family name that is one path segment: see the refusal below.
plain='^[[:alnum:]][[:alnum:]._-]*$'

# ⚠️ BYTE FOR BYTE, IN THE C LOCALE, BECAUSE A MANIFEST IS BYTES TOO. The rows used to arrive through
# `sed 's/#.*//'` in the operator's locale, and macOS sed in a UTF-8 locale stops at the first byte that is not
# UTF-8, having written only the rows before it. A process substitution's exit status is lost, so the loop took
# that for the end of the file: one Latin-1 byte in the comment above relays' rows dropped relays and tunnel-log
# from a run, which promised 5 checks and passed over relays' FAIL. The shell's own matching is no steadier
# there: `[[:alnum:]]` takes `é` for a letter, so the operator's locale decided whether a family name was one
# plain path segment. So bash reads the manifest itself, with nothing between the file and the loop whose
# failure a process substitution would hide, in the C locale. `local -x` keeps that locale to this function:
# ssh and the families still run in the operator's.
read_manifest() {
  local -x LC_ALL=C
  local row topology family id extra owner

  # ⚠️ A FOURTH FIELD IS A TYPO, NOT A LONGER ID. `read` folds every extra word into the last variable,
  # so a row like `tunnel good G-1 GOOD` would promise the id "G-1 GOOD" — which no verdict can ever
  # match, so the run would report VOID and blame the host for a mistake in this file. (Measured: that
  # is exactly what a stray word in a fixture row did.)
  #
  # ⚠️ THE LAST ROW COUNTS WITHOUT ITS NEWLINE. `read` fails on a line that reaches the end of the file without
  # one, having read it all the same, and the loop used to stop there. So a last row saved without a newline was
  # neither checked nor promised: a family named only there was never dispatched, so the run passed over its
  # FAIL, and a misspelt topology or family name there was never refused.
  while IFS= read -r row || [[ -n "$row" ]]; do
    read -r topology family id extra <<<"${row%%#*}"
    [[ -n "${topology:-}" ]] || continue
    [[ -n "${family:-}" && -n "${id:-}" ]] || refuse "malformed manifest row: $topology ${family:-} ${id:-}"
    [[ -z "${extra:-}" ]] || refuse "manifest row has more than three fields, so the id would be unmatchable: $topology $family $id $extra"

    # ⚠️ A TOPOLOGY NO RUN SELECTS DROPS ITS FAMILY FROM EVERY RUN. --expect picks rows by their topology, so a
    # row for `dns_only` promised its check to no run at all: a dns-only run never dispatched that family, never
    # saw its FAIL, and passed on the others.
    [[ "$topology" == tunnel || "$topology" == dns-only ]] \
      || refuse "the manifest row [$topology $family $id] names the topology [$topology], which is neither tunnel nor dns-only, so no run would ever promise it"

    # ⚠️ A FAMILY NAME IS ONE PATH SEGMENT. It becomes host/<family>.sh, outside/<family>.php and <family>.out in
    # the streams directory. `sub/fa` ran a script from a subdirectory and then stopped this script under
    # `set -e`, before any summary, writing into a directory that did not exist; `../fa` ran a script from
    # outside host/, passed, and left its output outside the streams directory.
    [[ "$family" =~ $plain ]] \
      || refuse "the manifest row [$topology $family $id] names the family [$family], which is not one plain path segment: run.sh finds a family as host/<family>.sh or outside/<family>.php and keeps its output as <family>.out"

    # ⚠️ ONE CHECK, ONE FAMILY. A verdict line names its check and not its family, so a check promised to
    # two families — a new family copied from an old one, keeping one of its ids — could not say whose
    # verdict is whose. Refused here, before anything runs, in either topology.
    case "$owners" in
      *" $id="*)
        owner=${owners#*" $id="}
        owner=${owner%% *}
        [[ "$owner" == "$family" ]] || refuse "the check $id is promised to both [$owner] and [$family], and a verdict line does not say which family printed it"
        ;;
      *) owners="$owners$id=$family " ;;
    esac

    [[ "$topology" == "$expect" ]] || continue
    expected+=("$family $id")

    case " ${families[*]:-} " in
      *" $family "*) ;;
      *) families+=("$family") ;;
    esac
  done < "$manifest"
}

read_manifest

# ⚠️ AN EMPTY PROMISE IS A VACUOUS RUN. With nothing expected, every check could be missing and the
# run would still report success, which is precisely the shape this file exists to prevent.
(( ${#expected[@]} > 0 )) || refuse "the manifest promises no checks for topology [$expect], so a run could not prove anything. Families are listed in $manifest as they land."

echo "Checking $host, expecting a $expect host, against ${#expected[@]} promised checks."
echo "⚠️ The topology is asserted, not yet measured: the topology family has not landed."

if [[ -z "$token_file" ]]; then
  echo "No --token-file: every Cloudflare check will be VOID, so this run cannot report success."
fi

# 2. Run each family, keeping its stream whole. A family that fails to run at all still has to
# produce a verdict for every id it promised, so its absence is turned into VOID below rather than
# being allowed to leave the promise unanswered.
#
# ⚠️ ONE STREAM PER FAMILY, because a verdict line does not say which family printed it. With one shared
# stream the gate had to infer a line's family from the sentinels, and every inference had a hole: a
# verdict printed from a subshell was invisible to the count, one id printed by two families was blamed
# on the transport, and a family whose output ended mid-line glued its tail onto the next family's first
# verdict. A family's own file holds only what that family's process wrote, so none of it is inferred.
streams=""

# The start of what a family wrote to stderr, on one line, for the operator.
#
# ⚠️ IN BYTES, FOR THE MANIFEST'S REASON. macOS tr in a UTF-8 locale stops at a byte that is not UTF-8, with
# "Illegal byte sequence", so whatever ssh or the family said after one never reached the operator.
complaint() {
  local -x LC_ALL=C

  tr '\n' ' ' < "$1" | cut -c1-200
}

finish() {
  local status=$?

  if [[ -n "$streams" && -d "$streams" ]]; then
    # ⚠️ A FAILED RUN KEEPS ITS EVIDENCE. The families' own output is the only record of what a sample
    # saw, and a check voided for its stream's sake may still hold a measurement worth reading.
    if (( status != 0 )) && [[ -n "$(ls -A "$streams")" ]]; then
      echo "The families' own output is kept in $streams" >&2
    else
      rm -rf "$streams"
    fi
  fi

  exit "$status"
}

trap finish EXIT
# ⚠️ A TEMPLATE, BECAUSE macOS mktemp IGNORES TMPDIR WITHOUT ONE. `mktemp -d` alone creates under the
# per-user system directory there, so kept evidence landed where neither the operator nor a test that
# set TMPDIR would look. Measured: 22 directories of fixture output collected there in one session.
#
# ⚠️ AND A TMPDIR THAT CANNOT BE USED IS REFUSED, NEVER WORKED AROUND. Falling back to /tmp would keep a failed
# run's evidence somewhere other than where the operator is told to look. The refusal used to say only "could
# not make a temporary directory"; it now names the directory, and TMPDIR whenever TMPDIR is what chose it.
streams=$(mktemp -d "${TMPDIR:-/tmp}/kitsune-runbook.XXXXXX") \
  || refuse "a directory for the families' output could not be made in ${TMPDIR:-/tmp}${TMPDIR:+, which TMPDIR names}, and a failed run keeps its evidence there. Create that directory or make it writable${TMPDIR:+, or unset TMPDIR to use /tmp}."

for family in "${families[@]:-}"; do
  [[ -n "$family" ]] || continue

  on_host="$here/host/$family.sh"
  from_outside="$here/outside/$family.php"
  out="$streams/$family.out"
  err="$streams/$family.err"

  # A promised family with no script is this runbook's bug, not the host's. Say so, rather than
  # letting the completeness gate report the host as unmeasurable.
  if [[ -f "$on_host" ]]; then
    kind=host
  elif [[ -f "$from_outside" ]]; then
    kind=outside
  else
    refuse "the manifest promises the family [$family], and neither $on_host nor $from_outside exists"
  fi

  echo "--- $family ($kind)"

  if [[ "$kind" == host ]]; then
    [[ -f "$common" ]] || refuse "$common is missing, and every family that runs on the host needs it"

    # ⚠️ common.sh IS CONCATENATED, NOT SOURCED. The family arrives on the remote shell's stdin, so it
    # has no path of its own to source a sibling from: `$0` is `bash`, and nothing was copied to the
    # host. Sending the two files as one stream is also what keeps the promise that nothing writable by
    # the site's user ever runs under sudo — the bytes come from the operator's checkout.
    if ! cat "$common" "$on_host" | ssh -o BatchMode=yes -o ClearAllForwardings=yes "$host" \
      sudo -n bash -s -- "$expect" >> "$out" 2>"$err"; then
      echo "  the family did not finish: $(complaint "$err")" >&2
    fi
  else
    # ⚠️ AN OUTSIDE FAMILY RUNS HERE, NOT THERE, AND THAT IS THE POINT. What ADR-034 turns on is what
    # arrives at the server from the network a visitor uses: a request made on the host would traverse
    # neither the edge nor the tunnel, and would prove nothing about either. These families reach the
    # server the way a visitor does, and drive anything they need on the host over their own ssh.
    if ! php "$from_outside" --host "$host" --expect "$expect" \
      ${token_file:+--token-file "$token_file"} >> "$out" 2>"$err"; then
      echo "  the family did not finish: $(complaint "$err")" >&2
    fi
  fi
done

# 3. Each family's own stream, judged against its own sentinel and against the promise.
#
# ⚠️ A STREAM CAN MISLEAD IN MORE WAYS THAN BY STOPPING EARLY, and each of them voids the whole family:
#   - it refused. A refused verdict is never printed as a verdict, only quoted in its refusal, so a refusal after
#     every promised verdict used to leave a stream that added up — and when a family closed over it, the run
#     passed;
#   - a verdict, sentinel or refusal starts partway through a line, glued onto output that did not end its
#     own. The gate accepts one only at the start of a line, because a reason may quote one, so a glued FAIL
#     counted for nothing and was reported nowhere, and a glued sentinel read as a family that never ran;
#   - its sentinel is missing, printed twice, closes another family, or is malformed;
#   - its sentinel names a check twice, as a PHP family's does for a check it printed twice;
#   - it printed a verdict its sentinel does not name, from a subshell or a pipeline its own tally
#     never saw;
#   - its sentinel reports checks the manifest does not promise this family;
#   - it printed a different number of verdicts than its sentinel counts.
# A short count used to print a warning and change nothing, and a check listed twice made the count add up.
#
# ⚠️ A COUNT THAT DISAGREES WITH ONE SENTINEL IS NOT THE TRANSPORT'S DOING. A cut stream loses its sentinel and
# a doubled one has two, so by the count branch neither is possible, and blaming "a stream cut or doubled"
# sent the operator to the network for a bug in the family. The reason names the checks instead: one printed
# more often than the tally counted it came from outside that tally, and one counted with no line never
# reached the stream.
#
# ⚠️ AND ONE CHECK, ONE VERDICT. The gate used to take the last verdict line for a check, so FAIL then PASS
# read as PASS. Two verdicts mean the family does not know which it measured, so neither stands.
#
# ⚠️ AND NOTHING MEASURED IS DROPPED. A check voided for its stream's sake still shows every verdict its
# family gave it, so a FAIL that arrived is never reported only as "could not be measured" — one that does
# not start its line included, marked as such, though it never counts.
#
# ⚠️ IN THE C LOCALE, BECAUSE A STREAM IS BYTES. macOS grep in a UTF-8 locale skips some lines holding a byte
# that is not UTF-8: `VERDICT I-1 FAIL \377…` matched nothing for its own check, so a FAIL whose reason began
# with a command's raw output read as "no verdict arrived". `.` matches no such byte either, so a verdict
# glued after one would go unseen, and `cut` exits 1 on one with "Illegal byte sequence", which under
# `set -e` ends this script before its summary. Set here, after every family has run, so the operator's
# locale still reaches ssh and the families.
export LC_ALL=C

fails=0
voids=0
passes=0
number='^(0|[1-9][0-9]*)$'

# Every verdict a family's stream gave one check, as "FAIL: <reason> | PASS: <reason>", wherever on its line it
# starts. `-a` throughout: a stray NUL anywhere in a stream makes grep call the file binary and match nothing
# line by line.
#
# ⚠️ AND THE NULs GO BEFORE awk SEES THEM. macOS awk ends a record at the first NUL, so a verdict glued after
# NUL-separated output — `cat /proc/<pid>/cmdline` left uncaptured, as a family reading a process's own words
# does — matched
# nothing here: the family was voided for the glue, and the FAIL it had measured was shown nowhere at all.
reported() {
  local id=$1 file=$2

  { grep -aE "VERDICT $id (PASS|FAIL|VOID) " "$file" || true; } |
    tr '\000' ' ' |
    awk -v verdict="VERDICT $id (PASS|FAIL|VOID) " 'match($0, verdict) {
      printf "%s%s%s: %s", sep, substr($0, RSTART + RLENGTH - 5, 4), (RSTART > 1 ? ", after other output on its line" : ""), substr($0, RSTART + RLENGTH)
      sep = " | "
    }'
}

for entry in "${expected[@]}"; do
  family=${entry%% *}
  id=${entry#* }
  out="$streams/$family.out"

  [[ -f "$out" ]] || : >"$out"

  promised=""
  for other in "${expected[@]}"; do
    if [[ "${other%% *}" == "$family" ]]; then
      promised="$promised ${other#* }"
    fi
  done

  closes=$(grep -acE '^SENTINEL ' "$out" || true)
  # ⚠️ A REFUSAL IS ITS LINE, NOT ITS REASON. This used to key on the reason text, so `REFUSED fa `, which
  # `refuse "$MEASURED"` prints after a command that failed without a word, was no refusal at all: from a pipeline,
  # whose refusal cannot withhold its parent's sentinel, the stream closed as if nothing were refused, and the run
  # passed.
  refused=$(grep -acE '^REFUSED( |$)' "$out" || true)
  # Every reason the family refused for, after its name, as "<reason>; <reason>", and one it gave no reason for as such.
  refusals=$({ grep -aE '^REFUSED( |$)' "$out" || true; } | awk '{
    sub(/^REFUSED( [^ ]*)? ?/, "")
    printf "%s%s", sep, ($0 ~ /[^ ]/ ? $0 : "no reason given")
    sep = "; "
  }')
  # The first line with a verdict, sentinel or refusal after other output.
  glued=$({ grep -anE '.(VERDICT [^ ]+ (PASS|FAIL|VOID) |SENTINEL [^ ]+ |REFUSED [^ ]+ )' "$out" || true; } | head -1 | cut -d: -f1)
  listed=""
  distrust=""

  if (( refused > 0 )); then
    distrust="the family refused to check ($refusals), and what a family refuses is invisible to its count, so none of its verdicts stand"
  elif [[ -n "$glued" ]]; then
    distrust="the family's stream has a verdict, sentinel or refusal partway through line $glued, after output that did not end its line, and one that does not start its line cannot be told from text quoting one"
  elif (( closes == 0 )); then
    distrust="the family produced no sentinel, so its stream was truncated or it never ran"
  elif (( closes > 1 )); then
    distrust="the family closed its stream $closes times, so which part of it belongs to this run cannot be told"
  else
    read -r _ closer counted listed <<<"$(grep -aE '^SENTINEL ' "$out")"
    closer=${closer:-}
    counted=${counted:-}
    listed=${listed:-}
    named=$(( $(wc -w <<<"$listed") ))
    repeated=$(awk '{ for (i = 1; i <= NF; i++) if (seen[$i]++ == 1) printf "%s ", $i }' <<<"$listed")
    printed=$(grep -ac '^VERDICT ' "$out" || true)
    # The check id of every verdict line, once per line.
    printed_ids=$({ grep -a '^VERDICT ' "$out" || true; } | awk '{ print $2 }')

    unnamed=""
    for printed_id in $(sort -u <<<"$printed_ids"); do
      case " $listed " in
        *" $printed_id "*) ;;
        *) unnamed="$unnamed; $printed_id $(reported "$printed_id" "$out")" ;;
      esac
    done

    unpromised=""
    for named_id in $listed; do
      case " $promised " in
        *" $named_id "*) ;;
        *) unpromised="$unpromised; $named_id $(reported "$named_id" "$out")" ;;
      esac
    done

    if [[ "$closer" != "$family" ]]; then
      distrust="the family closed its stream as [$closer], so the script that ran is not the family promised"
    elif [[ ! "$counted" =~ $number ]] || (( counted != named )); then
      distrust="the family's sentinel is malformed: it counts [$counted] and names $named checks"
    elif [[ -n "$repeated" ]]; then
      distrust="the family reported ${repeated% } more than once, so which verdict stands cannot be told"
    elif [[ -n "$unnamed" ]]; then
      distrust="the family printed verdicts its sentinel does not name (${unnamed#; }), so they came from a subshell or pipeline its own tally never saw"
    elif [[ -n "$unpromised" ]]; then
      distrust="the family reports checks the manifest does not promise for a $expect host (${unpromised#; }), so what it measured is not what was promised"
    elif (( printed != counted )); then
      twice=$(sort <<<"$printed_ids" | uniq -d | tr '\n' ' ')
      silent=""
      for named_id in $listed; do
        grep -qxF -- "$named_id" <<<"$printed_ids" || silent="$silent $named_id"
      done

      distrust="the family printed $printed verdicts and its sentinel counts $counted:"
      and=""

      if [[ -n "$twice" ]]; then
        distrust="$distrust it printed more verdicts than its own tally counted for ${twice% }, so a verdict came from a subshell, a pipeline, a by-value closure or a line printed by hand, which that tally never sees"
        and="; and"
      fi

      if [[ -n "$silent" ]]; then
        distrust="$distrust$and no verdict line arrived for ${silent# }, which its sentinel counts, so its tally counted what its stream never received — the family was killed between counting a verdict and printing it, printed a verdict somewhere else, or its sentinel was written by hand"
      fi
    fi
  fi

  if [[ -n "$distrust" ]]; then
    measured=$(reported "$id" "$out")

    if [[ -n "$measured" ]]; then
      echo "VOID  $id ($family) — $distrust; for this check it reported $measured"
    else
      echo "VOID  $id ($family) — $distrust"
    fi

    voids=$((voids + 1))
    continue
  fi

  given=$(grep -acE "^VERDICT $id (PASS|FAIL|VOID) " "$out" || true)

  if (( given == 0 )); then
    echo "VOID  $id ($family) — promised by the manifest, and no verdict arrived"
    voids=$((voids + 1))
    continue
  fi

  if (( given > 1 )); then
    echo "VOID  $id ($family) — the check was given $given verdicts, so none of them can stand: $(reported "$id" "$out")"
    voids=$((voids + 1))
    continue
  fi

  lines=$(grep -aE "^VERDICT $id (PASS|FAIL|VOID) " "$out")
  outcome=$(awk '{print $3}' <<<"$lines")
  reason=${lines#VERDICT "$id" "$outcome" }

  case "$outcome" in
    PASS) passes=$((passes + 1)); echo "PASS  $id — $reason" ;;
    FAIL) fails=$((fails + 1)); echo "FAIL  $id — $reason" ;;
    VOID) voids=$((voids + 1)); echo "VOID  $id — $reason" ;;
  esac
done

echo
echo "$passes passed, $fails failed, $voids could not be measured."

if (( fails > 0 || voids > 0 )); then
  echo "This host has NOT been shown to hold ADR-034's conditions." >&2
  exit 1
fi

echo "Every promised check passed."
