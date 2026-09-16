#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Who may reach the web server over loopback — ADR-034's central condition (issue #111). It arrives on
# stdin behind common.sh, as one stream, and runs as root. The topology is its only argument.
#
#   RLY-1  every loopback client of the web server is the tunnel's own connector, and nothing else
#   RLY-2  every peer of PHP-FPM's unix socket is one of nginx's workers
#   RLY-3  who can open that socket, read rather than assumed
#   RLY-4  every client seen during the window belongs to a process this check identified
#   RLY-5  every listening socket on the host belongs to a service this runbook has reviewed
#
# ⚠️ WHY THIS FAMILY DECIDES WHETHER ADR-034 IS SAFE AT ALL. The skeleton trusts whatever `127.0.0.1`
# sends in `X-Forwarded-For`. That is sound while the only thing dialling nginx over loopback is the
# tunnel's connector, which appends the address Cloudflare saw. A second relay — an SSH forward, a
# proxy, a container's port mapping — would let its client choose the address Laravel believes, or
# make every visitor look like `127.0.0.1`. No test in the suite can see that; this can.
#
# ⚠️ AN IDLE HOST PROVES NOTHING, AND IT LOOKS EXACTLY LIKE A CLEAN ONE. Measured on stage: with no
# traffic, the connector holds no origin connection at all — five samples over two seconds found
# zero. A check that sampled `ss` on a quiet host would report "no unknown clients" having seen no
# clients whatsoever. So this family drives its own traffic, through the real edge, and requires the
# connector's own socket to appear in the sample before it will believe anything else it saw.
#
# ⚠️ AND IDENTITY COMES FROM THE TUNNEL, NOT FROM A NAME OR A PATH. A process called `cloudflared`
# proves nothing; the connector is the process whose metrics endpoint reports this host's tunnel.
set -euo pipefail

family relays RLY-1 RLY-2 RLY-3 RLY-4 RLY-5
require_root

topology=${1:-}
[[ "$topology" == tunnel || "$topology" == dns-only ]] || refuse "the topology must be tunnel or dns-only, not [${topology}]"

work=$(mktemp -d) || refuse "could not make a working directory"
cleanup_at_exit "$work"

# ⚠️ ONE SEAM, DECLARED. Every other input to this family arrives from a command, which a test can
# stub on PATH; the process table does not. This is the single path taken from the environment, it
# defaults to the real one, and it exists so the tests can hand the family a fixture tree of
# processes rather than asserting against whatever happens to be running on the machine.
proc=${KITSUNE_PROC:-/proc}

# --- who is the connector -----------------------------------------------------------------------
#
# Every cloudflared process, by the tunnel its own metrics endpoint reports. The metrics port is not
# fixed: cloudflared takes the first free port from 20241 upwards and falls back to a random one, so
# it is read from the process's own listening socket rather than assumed.

connector_pids=""
connector_cgroups=""
tunnel_ids=""

while read -r pid; do
  [[ -n "$pid" ]] || continue
  exe=$(readlink -f "$proc/$pid/exe" 2>/dev/null || true)
  [[ "$exe" == *cloudflared* ]] || continue

  # Every command here carries a clock: this loop runs once per process on the host, and a single
  # slow call inside it is what turned the whole family into a session that never returned.
  port=$(timeout 5 ss -Htlnp 2>/dev/null | grep "pid=$pid," | grep -oE '127\.0\.0\.1:[0-9]+' | head -1 | cut -d: -f2 || true)

  if [[ -z "$port" ]]; then
    record RLY-1 "a cloudflared process (pid $pid) has no local metrics listener, so its tunnel cannot be read"
    continue
  fi

  body=$(timeout 8 curl -s --max-time 5 "http://127.0.0.1:$port/diag/tunnel" 2>/dev/null || true)
  id=$(grep -oE '"tunnelID":"[^"]+"' <<<"$body" | cut -d'"' -f4 || true)

  connector_pids="$connector_pids $pid"
  # ⚠️ A PROCESS THAT EXITS MID-SCAN IS ORDINARY. Between listing the process table and reading this
  # file, the process may be gone: `cut` then fails, and in an assignment under `set -e` that ends the
  # family silently — measured once on stage, where the run died right after the first connector was
  # recorded and the sentinel reported zero verdicts. The gate caught it; this stops it happening.
  connector_cgroups="$connector_cgroups $(cut -d: -f3 "$proc/$pid/cgroup" 2>/dev/null | head -1 || true)"
  tunnel_ids="$tunnel_ids $id"

  record RLY-1 "connector pid $pid, exe $exe, tunnel ${id:-unreadable}"
done < <(ls "$proc" | grep -E '^[0-9]+$')

# --- RLY-1: every loopback client of the web server ----------------------------------------------
#
# The traffic is driven through the real edge — a request to the site's own public name leaves the
# host, reaches Cloudflare, and comes back down the tunnel — so what the sample catches is the
# connector doing its actual job, not a loopback request this script could have made to itself.

# ⚠️ STRIP THE SEMICOLON BEFORE COMPARING IT, NOT AFTER. `server_name _;` tokenizes as `_;`, so a
# guard written as `$2 != "_"` never matched and the catch-all was chosen as the site: the driver
# then asked for `https://_/up` forty times, each waiting on a lookup that cannot succeed. That is
# what turned this family into a five-minute session, and it left RLY-1 VOID for the wrong reason.
site=$(timeout 15 nginx -T 2>/dev/null | awk '$1 == "server_name" { gsub(/;/, "", $2); if ($2 != "_" && $2 != "") { print $2; exit } }' || true)

if [[ "$topology" == tunnel && -z "$site" ]]; then
  verdict RLY-1 VOID "no server_name was found in the configuration, so there is no hostname to drive traffic through"
else
  # ⚠️ BOUNDED, BECAUSE AN UNBOUNDED CHECK IS ONE NOBODY RUNS. The first version drove forty requests
  # with a five-second timeout each and then WAITED for them, so a slow trip out to the edge and back
  # down the tunnel turned a fifteen-second check into a five-minute one: measured on stage, where it
  # passed a 300-second ceiling without emitting a single verdict. The window is capped here, the
  # driver is killed rather than waited on, and running out of window is VOID with the reason said.
  #
  # ⚠️ AND A REQUEST FROM HERE IS NOT CHEAP: measured on stage, 3.5 to 7.0 seconds each, because it
  # hairpins from the host out to Cloudflare and back down the tunnel to the same machine. Twenty
  # seconds therefore buys three or four requests — enough, because the connector's socket is there
  # for the whole of any one of them. Do not tune this window down without re-measuring that round
  # trip on the host in question.
  # The second declared seam, for the same reason as $proc: a test drives a stubbed curl that returns
  # at once, so without this the sampling loop would spin for the full window in every case.
  window=${KITSUNE_WINDOW:-20}
  deadline=$((SECONDS + window))

  if [[ "$topology" == tunnel ]]; then
    ( while (( SECONDS < deadline )); do curl -s -o /dev/null --max-time 3 "https://$site/up" || true; done ) >/dev/null 2>&1 &
    driver=$!
  else
    driver=""
  fi

  saw_connector=0
  strangers=""

  while (( SECONDS < deadline )); do
    while read -r line; do
      [[ -n "$line" ]] || continue
      # ⚠️ A SOCKET LINE WITHOUT AN OWNER IS ORDINARY, AND grep EXITS 1 ON IT. In an assignment under
      # `set -e` that ends the family with no verdict at all — measured on stage, intermittently,
      # because such a socket is only sometimes present. This is the third time this one class of
      # failure has stopped a run here, which is why every substitution in this file now carries its
      # own guard and an unowned line is skipped rather than fatal.
      pid=$(grep -oE 'pid=[0-9]+' <<<"$line" | head -1 | cut -d= -f2 || true)
      [[ -n "$pid" ]] || continue
      # The driver above is this check's own client, and it is not a relay: it serves nobody.
      [[ -n "$driver" && "$pid" == "$driver" ]] && continue
      comm=$(cat "$proc/$pid/comm" 2>/dev/null || echo gone)
      [[ "$comm" == curl ]] && continue

      case " $connector_pids " in
        *" $pid "*) saw_connector=1 ;;
        *) strangers="$strangers; pid $pid ($comm): $(grep -oE '127\.0\.0\.1:[0-9]+ +127\.0\.0\.1:443|\[::1\]:[0-9]+ +\[::1\]:443' <<<"$line" | head -1 || true)" ;;
      esac
    done < <(timeout 5 ss -Htnpe state established '( dport = :443 )' 2>/dev/null | grep -E '127\.0\.0\.1:443|\[::1\]:443' || true)

    # On a DNS-only host there is no driver and nothing should ever appear, so one sweep of an empty
    # set is the whole measurement. On a tunnel host the window above is what bounds the sampling.
    [[ -n "$driver" ]] || break
    sleep 0.2
  done

  # Killed, never waited on: the driver's own timeouts are not this check's budget to spend.
  if [[ -n "$driver" ]]; then
    kill "$driver" 2>/dev/null || true
    wait "$driver" 2>/dev/null || true
  fi

  if [[ -n "$strangers" ]]; then
    verdict RLY-1 FAIL "a process that is not this tunnel's connector reached the web server over loopback: ${strangers#; }"
  elif [[ "$topology" == tunnel && "$saw_connector" == 0 ]]; then
    # ⚠️ THE EMPTY SAMPLE. Measured on stage: an idle connector holds no origin connection, so a
    # sample that found nothing has not shown the host is clean — it has shown the instrument was
    # not looking while anything was happening.
    verdict RLY-1 VOID "the connector's own connection never appeared while traffic was driven through $site, so the sample proves nothing about what else may dial the web server"
  elif [[ "$topology" == dns-only ]]; then
    verdict RLY-1 PASS "no process dialled the web server over loopback, which is what a host with no tunnel must look like"
  else
    verdict RLY-1 PASS "the only loopback client of the web server was this tunnel's connector"
  fi
fi

# --- RLY-2: PHP-FPM's unix socket ----------------------------------------------------------------
#
# ⚠️ `ss -tanpu` CANNOT SEE THIS AT ALL, and `ss -x` without `-a` shows only connected sockets, so the
# listener itself is invisible. PHP-FPM listens on a unix socket, which is the one path into the
# application that carries no address for anything to forge — and therefore the one place a relay
# could sit unseen by every check that reads TCP.

if ! measure_in 10 ss -H -x -a -p; then
  verdict RLY-2 VOID "the unix sockets could not be read: $MEASURED"
else
  printf '%s\n' "$MEASURED" > "$work/unix"
  fpm_socket=$(nginx -T 2>/dev/null | grep -oE 'unix:/[^;]+\.sock' | head -1 | cut -d: -f2 || true)

  if [[ -z "$fpm_socket" ]]; then
    verdict RLY-2 VOID "no unix socket is named by a fastcgi_pass, so there is nothing to account for"
  elif ! grep -q "$fpm_socket" "$work/unix"; then
    verdict RLY-2 VOID "$fpm_socket is configured but has no listening socket, so PHP-FPM is not listening where nginx sends"
  else
    workers=$(ps -o pid= -C nginx 2>/dev/null | tr -d ' ' | tr '\n' ' ' || true)
    foreign=""

    while read -r pid comm; do
      [[ -n "$pid" ]] || continue
      case " $workers " in
        *" $pid "*) ;;
        *) case "$comm" in php-fpm*) ;; *) foreign="$foreign; pid $pid ($comm)" ;; esac ;;
      esac
    done < <(grep "$fpm_socket" "$work/unix" | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u \
      | while read -r p; do printf '%s %s\n' "$p" "$(cat "$proc/$p/comm" 2>/dev/null || echo gone)"; done)

    if [[ -n "$foreign" ]]; then
      verdict RLY-2 FAIL "something other than nginx or PHP-FPM holds PHP's socket: ${foreign#; }"
    else
      verdict RLY-2 PASS "every holder of $fpm_socket is nginx or PHP-FPM itself"
    fi
  fi
fi

# --- RLY-3: who can open PHP's socket -------------------------------------------------------------
#
# ⚠️ NEVER `test -r`. On Ubuntu 26.04 /usr/bin/test is rust-coreutils, which ignores supplementary
# groups: it answers "no" for a file a real open would succeed on. Ownership comes from stat, groups
# from id -nG. And the answer differs between hosts by design — stage runs nginx as www-data, Forge
# runs it as forge — so it is read, never assumed.

fpm_socket=${fpm_socket:-}

if [[ -z "$fpm_socket" || ! -S "$fpm_socket" ]]; then
  verdict RLY-3 VOID "there is no PHP-FPM socket to describe"
else
  triple=$(owner_triple "$fpm_socket" 2>/dev/null || true)
  worker_user=$(ps -o user= -C nginx 2>/dev/null | sort | uniq -c | sort -rn | head -1 | awk '{print $2}' || true)

  record RLY-3 "$fpm_socket is $triple, nginx workers run as ${worker_user:-unknown}"

  socket_user=${triple%% *}
  socket_rest=${triple#* }
  socket_group=${socket_rest%% *}

  if [[ -z "$triple" ]]; then
    verdict RLY-3 VOID "the socket's ownership could not be read, so who may open it is unknown"
  elif [[ -z "$worker_user" ]]; then
    verdict RLY-3 VOID "no nginx worker is running, so there is nothing to compare the socket's ownership against"
  elif [[ "$socket_user" == "$worker_user" ]] || user_in_group "$worker_user" "$socket_group"; then
    verdict RLY-3 PASS "nginx's workers reach the socket as ${worker_user}, through $([[ "$socket_user" == "$worker_user" ]] && echo ownership || echo "the $socket_group group")"
  else
    verdict RLY-3 FAIL "nginx runs as $worker_user and the socket is $triple, so the workers cannot open it"
  fi
fi

# --- RLY-4: everything seen during the window ------------------------------------------------------
#
# The cgroup is read from the socket itself (`ss -e` prints it), rather than matched with a fixed nft
# level: `socket cgroupv2 level 1` resolves to `system.slice` for every systemd service, so it cannot
# tell one unit from another. This is a narrower claim than "no relay ever existed" — a relay that
# dialled and vanished between samples is not caught — and the family says so rather than implying a
# proof it does not have.

if ! measure_in 10 ss -Htnpe state established '( dport = :443 )'; then
  verdict RLY-4 VOID "the established connections could not be read: $MEASURED"
else
  # ⚠️ AN EMPTY SAMPLE IS DATA, NOT A FAILURE. With no established connection to the web server,
  # grep exits 1 — and under `set -e` that ended the family right here, so RLY-4 and RLY-5 never ran.
  # The sentinel then reported three ids where the manifest promises five, which is exactly how the
  # completeness gate is meant to surface a family that died halfway.
  cgroups=$(grep -oE 'cgroup:[^ ]+' <<<"$MEASURED" | cut -d: -f2 | sort -u | tr '\n' ' ' || true)
  unexpected=""

  for cgroup in $cgroups; do
    case " $connector_cgroups " in
      *" $cgroup "*) continue ;;
    esac
    case "$cgroup" in
      */user.slice/*) continue ;; # this check's own curl
      *) unexpected="$unexpected $cgroup" ;;
    esac
  done

  record RLY-4 "cgroups seen dialling the web server: ${cgroups:-none}"

  if [[ -n "$unexpected" ]]; then
    verdict RLY-4 FAIL "connections to the web server came from services this check did not identify:$unexpected"
  else
    verdict RLY-4 PASS "every connection seen belonged to the connector or to this check itself"
  fi
fi

# --- RLY-5: every listening socket ------------------------------------------------------------------
#
# A dormant relay holds a listening socket and dials nothing until someone connects to it, so no
# sample of established connections would ever see it. This is the inventory that would.

if ! measure_in 10 ss -Htulnpe; then
  verdict RLY-5 VOID "the listening sockets could not be read: $MEASURED"
else
  reviewed='nginx sshd systemd postgres systemd-resolve cloudflared php-fpm8.5 systemd-network chronyd'
  strangers=""

  while read -r owner; do
    [[ -n "$owner" ]] || continue
    case " $reviewed " in
      *" $owner "*) ;;
      *) strangers="$strangers $owner" ;;
    esac
  done < <(grep -oE 'users:\(\("[^"]+"' <<<"$MEASURED" | cut -d'"' -f2 | sort -u)

  record RLY-5 "listening: $(grep -oE 'users:\(\("[^"]+"' <<<"$MEASURED" | cut -d'"' -f2 | sort -u | tr '\n' ' ' || true)"

  if [[ -n "$strangers" ]]; then
    verdict RLY-5 FAIL "a service this runbook has never reviewed is listening:$strangers"
  else
    verdict RLY-5 PASS "every listening socket belongs to a reviewed service"
  fi
fi
