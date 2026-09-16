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
# ⚠️ A FOURTH FIELD IS A TYPO, NOT A LONGER ID. `read` folds every extra word into the last variable,
# so a row like `tunnel good G-1 GOOD` would promise the id "G-1 GOOD" — which no verdict can ever
# match, so the run would report VOID and blame the host for a mistake in this file. (Measured: that
# is exactly what a stray word in a fixture row did.)
while read -r topology family id extra; do
  [[ -z "${topology:-}" || "$topology" == \#* ]] && continue
  [[ -n "${family:-}" && -n "${id:-}" ]] || refuse "malformed manifest row: $topology ${family:-} ${id:-}"
  [[ -z "${extra:-}" ]] || refuse "manifest row has more than three fields, so the id would be unmatchable: $topology $family $id $extra"
  [[ "$topology" == "$expect" ]] || continue
  expected+=("$family $id")

  case " ${families[*]:-} " in
    *" $family "*) ;;
    *) families+=("$family") ;;
  esac
done < <(sed 's/#.*//' "$manifest")

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
stream=$(mktemp) || refuse "could not make a temporary file"
trap 'rm -f "$stream"' EXIT

for family in "${families[@]:-}"; do
  [[ -n "$family" ]] || continue

  on_host="$here/host/$family.sh"
  from_outside="$here/outside/$family.php"

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
      sudo -n bash -s -- "$expect" >> "$stream" 2>"$stream.err"; then
      echo "  the family did not finish: $(tr '\n' ' ' < "$stream.err" | cut -c1-200)" >&2
    fi
  else
    # ⚠️ AN OUTSIDE FAMILY RUNS HERE, NOT THERE, AND THAT IS THE POINT. What ADR-034 turns on is what
    # arrives at the server from the network a visitor uses: a request made on the host would traverse
    # neither the edge nor the tunnel, and would prove nothing about either. These families reach the
    # server the way a visitor does, and drive anything they need on the host over their own ssh.
    if ! php "$from_outside" --host "$host" --expect "$expect" \
      ${token_file:+--token-file "$token_file"} >> "$stream" 2>"$stream.err"; then
      echo "  the family did not finish: $(tr '\n' ' ' < "$stream.err" | cut -c1-200)" >&2
    fi
  fi
done

# 3. The verdicts that arrived, judged against the sentinel that closes each family and against the
# promise.
#
# ⚠️ A STREAM CAN MISLEAD IN MORE WAYS THAN BY STOPPING EARLY, and each of them voids the family. Its
# sentinel may be missing, printed twice, malformed, or name one check twice; its count may disagree
# with the verdict lines that arrived; it may report checks the manifest does not promise. A short count
# used to print a warning and change nothing, so a stream cut after its first verdicts still passed
# those verdicts — and a family that printed one check twice, and listed it twice in its sentinel, made
# the count add up.
#
# ⚠️ AND ONE CHECK, ONE VERDICT. This used to take the last verdict line for an id, so a family that
# printed FAIL and then PASS for the same check reported PASS. Two verdicts mean the family does not know
# which one it measured, so neither stands — and the reason names both, so the FAIL is not lost.
#
# ⚠️ AND A VERDICT COUNTS ONLY FOR THE FAMILY THAT GAVE IT. Verdict lines do not name their family, so
# without this a check one family never ran was satisfied by another family's line for the same id.
fails=0
voids=0
passes=0
number='^(0|[1-9][0-9]*)$'

for entry in "${expected[@]}"; do
  family=${entry%% *}
  id=${entry#* }

  promised=""
  for other in "${expected[@]}"; do
    if [[ "${other%% *}" == "$family" ]]; then
      promised="$promised ${other#* }"
    fi
  done

  closes=$(grep -cE "^SENTINEL $family " "$stream" || true)
  listed=""
  distrust=""

  if (( closes == 0 )); then
    distrust="the family produced no sentinel, so its stream was truncated or it never ran"
  elif (( closes > 1 )); then
    distrust="the family closed its stream $closes times, so which part of it belongs to this run cannot be told"
  else
    sentinel=$(grep -E "^SENTINEL $family " "$stream")
    read -r _ _ counted listed <<<"$sentinel"
    counted=${counted:-}
    listed=${listed:-}
    named=$(( $(wc -w <<<"$listed") ))
    repeated=$(awk '{ for (i = 1; i <= NF; i++) if (seen[$i]++ == 1) printf "%s ", $i }' <<<"$listed")
    arrived=0

    if (( named > 0 )); then
      arrived=$(grep -cE "^VERDICT ($(awk '{ $1 = $1; gsub(/ /, "|"); print }' <<<"$listed")) (PASS|FAIL|VOID) " "$stream" || true)
    fi

    unpromised=""
    for reported in $listed; do
      case " $promised " in
        *" $reported "*) ;;
        *) unpromised="$unpromised $reported" ;;
      esac
    done

    if [[ ! "$counted" =~ $number ]] || (( counted != named )); then
      distrust="the family's sentinel is malformed: it counts [$counted] and names $named checks"
    elif [[ -n "$repeated" ]]; then
      distrust="the family reported ${repeated% } more than once, so which verdict stands cannot be told"
    elif (( arrived != counted )); then
      distrust="the family's sentinel counted $counted verdicts and $arrived arrived, so its stream was cut or doubled"
    elif [[ -n "$unpromised" ]]; then
      distrust="the family reports checks the manifest does not promise for a $expect host (${unpromised# }), so what it measured is not what was promised"
    fi
  fi

  if [[ -n "$distrust" ]]; then
    echo "VOID  $id ($family) — $distrust"
    voids=$((voids + 1))
    continue
  fi

  lines=$(grep -E "^VERDICT $id (PASS|FAIL|VOID) " "$stream" || true)
  given=$(grep -cE "^VERDICT $id (PASS|FAIL|VOID) " "$stream" || true)

  if (( given == 0 )); then
    echo "VOID  $id ($family) — promised by the manifest, and no verdict arrived"
    voids=$((voids + 1))
    continue
  fi

  if (( given > 1 )); then
    outcomes=$(awk '{ printf "%s%s", sep, $3; sep = ", " }' <<<"$lines")
    echo "VOID  $id ($family) — the check was given $given verdicts ($outcomes), so none of them can stand"
    voids=$((voids + 1))
    continue
  fi

  case " $listed " in
    *" $id "*) ;;
    *)
      echo "VOID  $id ($family) — a verdict arrived that this family's sentinel does not name, so another family gave it"
      voids=$((voids + 1))
      continue
      ;;
  esac

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
