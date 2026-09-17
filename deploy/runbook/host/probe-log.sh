#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# A temporary nginx log that records exactly what the web server handed PHP, for the check's own
# requests and nobody else's — the instrument the tunnel-hostname family reads (issue #111). It
# arrives on stdin behind common.sh and runs as root.
#
#   probe-log.sh start <nonce>        install the log, reload nginx, and prove the reload happened
#   probe-log.sh collect <nonce> <id> print the line for one request, and who owned its socket
#   probe-log.sh stop <nonce>         remove it and prove the configuration is back as it was, or say that
#                                     nothing of it was installed
#
# ⚠️ AN INSTRUMENT, NOT A FAMILY — SO IT EMITS NO VERDICTS. run.sh dispatches exactly one script per
# family named in the manifest, with the topology as its only argument. This is driven three times
# around a single request instead, by the tunnel-hostname check. Promising a check id here would make
# the completeness gate demand a verdict from a script run.sh never dispatches, and blame the host for
# the runbook's own shape. So this refuses loudly — non-zero, with the reason on stderr — and prints
# lines its caller parses:
#
#   STATE <action> <detail>        what it did, and the facts the caller needs (worker set, drain time).
#                                  `stop` opens its detail with `removed` or `absent`, which is what the
#                                  tunnel-log family judges TUN-2 on.
#   PROBE <id> <json>              the one log line for that request
#   PROBE-OWNER <id> <ss row>      who owned the socket that request arrived on
#
# The tunnel-log family owns the verdicts, and turns any failure here into VOID with the reason given.
#
# ⚠️ THIS IS THE ONLY PART OF THE RUNBOOK THAT CHANGES A LIVE SERVER, so every part of it undoes
# itself. The file it writes is one `conf.d` snippet holding a log format and an `access_log` gated on
# a 128-bit nonce, so a visitor who is not this check is never logged. `stop` removes it and fails
# unless the configuration hashes back to what it was — or, for a nonce whose start installed nothing,
# says so and leaves the server alone. A dead-man timer removes it even if the
# operator's session dies, and `start` refuses if an earlier probe is still installed: two probes would
# each overwrite the other's idea of "before".
#
# ⚠️ WHY A RELOAD AT ALL. nginx reads a new log directive only on reload, and the connector holds
# keepalive connections an old worker would keep serving — a request answered by a pre-reload worker
# produces no line, and a line that does appear must be attributable to the configuration this
# installed. So `start` reloads, waits for the old workers to go, and records the new set; `collect`
# refuses a line whose worker is not one of those.
#
# ⚠️ AND THE DRAIN BOUND IS NOT INVENTED. nginx documents no default for `worker_shutdown_timeout`:
# unset, a worker finishes its connections in its own time. The wait here is the runbook's own
# patience, printed as such, and never described as nginx's rule.
set -euo pipefail

require_root

action=${1:-}
nonce=${2:-}

[[ -n "$action" ]] || refuse "probe-log.sh needs an action: start, collect or stop"
[[ "$nonce" =~ ^[0-9a-f]{32}$ ]] || refuse "the nonce must be 32 hex characters, and it must come from the runbook rather than from this host"

# ⚠️ THREE SEAMS, DECLARED, AND EACH DEFAULTS TO THE REAL THING. Everything else this instrument reads
# arrives from a command a test can stub on PATH; these are paths it WRITES, on a live server, and
# nothing about start, collect or stop can be exercised off one without them. Same reason as relays.sh's
# $proc: the alternative is an instrument whose only test is the production run.
conf_dir=${KITSUNE_NGINX_CONF_D:-/etc/nginx/conf.d}
run_dir=${KITSUNE_PROBE_DIR:-/run/kitsune-probe}
pid_file=${KITSUNE_NGINX_PID:-/run/nginx.pid}

conf="$conf_dir/kitsune-probe-$nonce.conf"
dir="$run_dir"
log="$dir/$nonce.log"
state="$dir/$nonce.state"
unit="kitsune-probe-$nonce"
patience=${KITSUNE_DRAIN_PATIENCE:-90}

# The nginx variable and log format this run owns, named once so the snippet below reads as what it is
# rather than as a shell variable abutting an nginx one.
gate="kitsune_probe_$nonce"

# The worker set, by pid, as the master's children. A reload replaces every one of them.
workers_now() {
  pgrep -P "$(cat "$pid_file" 2>/dev/null || echo 0)" 2>/dev/null | sort | tr '\n' ' ' || true
}

case "$action" in
  start)
    # ⚠️ ONE PROBE AT A TIME. A second would record its own "before" over the first's, and neither
    # could then prove the configuration was restored.
    existing=$(ls "$conf_dir"/kitsune-probe-*.conf 2>/dev/null | head -5 || true)
    [[ -z "$existing" ]] || refuse "a probe is already installed ($existing). Run stop for it first, or remove it by hand if no run owns it."

    command -v nginx >/dev/null || refuse "nginx is not on PATH"
    nginx -V 2>&1 | grep -q -- --with-http_realip_module \
      || refuse "this nginx has no realip module, so \$realip_remote_addr would be undefined and the probe could not tell a resolved address from the peer"

    mkdir -p "$dir"
    chmod 700 "$dir"

    measure_into "$state.before" nginx -T \
      || refuse "nginx -T failed before anything was installed, so there is no baseline to restore to: $MEASURED"

    sha256sum < "$state.before" | cut -d' ' -f1 > "$state.hash"
    workers_now > "$state.workers"

    # The log itself: every field the tunnel-hostname checks judge, and nothing that identifies a
    # visitor who is not this check.
    cat > "$conf" <<CONF
# Installed by deploy/runbook/host/probe-log.sh for one run of the ADR-034 checks (issue #111).
# It logs only requests carrying X-Kitsune-Probe: $nonce-<n>, and probe-log.sh stop removes it.
log_format $gate escape=json
  '{"pid":"\$pid","remote_addr":"\$remote_addr","remote_port":"\$remote_port"'
  ',"realip_remote_addr":"\$realip_remote_addr","proxy_protocol_addr":"\$proxy_protocol_addr"'
  ',"host":"\$host","http_host":"\$http_host","server_name":"\$server_name"'
  ',"server_port":"\$server_port","https":"\$https","ssl_server_name":"\$ssl_server_name"'
  ',"request_method":"\$request_method","request_uri":"\$request_uri","status":"\$status"'
  ',"upstream_addr":"\$upstream_addr","upstream_status":"\$upstream_status"'
  ',"xff":"\$http_x_forwarded_for","cf_connecting_ip":"\$http_cf_connecting_ip"'
  ',"cf_ray":"\$http_cf_ray","probe":"\$http_x_kitsune_probe"}';

map \$http_x_kitsune_probe \$$gate {
    default      0;
    "~^$nonce-"  1;
}

access_log $log $gate if=\$$gate;
CONF

    # ⚠️ THE DEAD MAN IS ARMED BEFORE THE RELOAD, not after: a session that died between the two would
    # leave the snippet installed with nothing scheduled to remove it. The unit is named after the
    # nonce, so stop cancels exactly this one.
    systemd-run --quiet --unit="$unit" --on-active=900 \
      /bin/sh -c "rm -f '$conf' && nginx -t >/dev/null 2>&1 && nginx -s reload" \
      || { rm -f "$conf"; refuse "could not arm the dead-man timer, and this will not install a probe it cannot guarantee to remove"; }

    if ! measure nginx -t; then
      rm -f "$conf"
      systemctl stop "$unit.timer" 2>/dev/null || true
      refuse "the probe's own configuration does not parse, and it has been removed: $MEASURED"
    fi

    if ! measure nginx -s reload; then
      rm -f "$conf"
      systemctl stop "$unit.timer" 2>/dev/null || true
      refuse "nginx would not reload, so the probe was removed and nothing was measured: $MEASURED"
    fi

    # Drain: the old workers must go, or a request could be answered by one that never read this
    # configuration. The wait is the runbook's own, and its expiry is a refusal rather than a shrug.
    before_workers=$(cat "$state.workers")
    waited=0
    still=""

    while (( waited < patience )); do
      still=""
      for pid in $before_workers; do
        [[ -d "/proc/$pid" ]] && still="$still $pid"
      done
      [[ -n "$still" ]] || break
      sleep 1
      waited=$((waited + 1))
    done

    workers_now > "$state.workers.after"

    if [[ -n "$still" ]]; then
      refuse "after ${patience}s of the runbook's own patience the pre-reload workers$still were still serving, so a probe line could not be attributed to this configuration. nginx documents no default worker_shutdown_timeout, so this is a wait, not a rule."
    fi

    printf 'STATE start installed, reloaded, drained in %ss; workers now %s\n' "$waited" "$(cat "$state.workers.after")"
    ;;

  collect)
    id=${3:-}
    [[ -n "$id" ]] || refuse "collect needs the request id the runbook sent as X-Kitsune-Probe"
    [[ -s "$log" ]] || refuse "no probe log exists at $log, so nothing was recorded"

    lines=$(grep -F "\"probe\":\"$id\"" "$log" || true)
    count=$(grep -cF "\"probe\":\"$id\"" "$log" || true)

    [[ -n "$lines" ]] \
      || refuse "no line carries the id $id, so that request either never reached this server or was answered before the probe was installed"

    (( count == 1 )) \
      || refuse "$count lines carry the id $id, and exactly one request was sent with it"

    # ⚠️ THE WORKER MUST BE A NEW ONE. A line written by a pre-reload worker describes a configuration
    # this run did not install.
    pid=$(grep -oE '"pid":"[0-9]+"' <<<"$lines" | head -1 | cut -d'"' -f4 || true)
    expected=$(cat "$state.workers.after" 2>/dev/null || true)

    case " $expected " in
      *" $pid "*) ;;
      *) refuse "the line was written by worker $pid, which is not one of the workers the reload created ($expected)" ;;
    esac

    printf 'PROBE %s %s\n' "$id" "$lines"

    # Who owned the socket the request arrived on, so a loopback line can be tied to the connector
    # rather than to anything else that can reach 443.
    port=$(grep -oE '"remote_port":"[0-9]+"' <<<"$lines" | head -1 | cut -d'"' -f4 || true)

    if [[ -n "$port" ]]; then
      # ⚠️ THE CLIENT ROW, NOT THE SERVER'S. A loopback connection appears twice in `ss`, once from each
      # end, and both rows carry both ports. Unfiltered, `grep ":$port "` matched whichever came first —
      # always the `127.0.0.1:443 127.0.0.1:<port>` row, owned by nginx, which can never be a connector.
      # Measured on stage (2026-09-16): TUN-1 failed naming nginx as the owner while RLY-1 passed on the
      # same host in the same run, because relays.sh asks for `dport = :443` and this did not.
      #
      # `dport = :443` keeps only rows whose REMOTE end is the web server — the dialer's own row. The
      # row must then carry the WHOLE connection: local `127.0.0.1:<port>`, the port nginx recorded for
      # this request, and peer `127.0.0.1:443`, where the connector is configured to dial.
      #
      # ⚠️ A SOURCE PORT IS NOT A CONNECTION (review on #118). Linux lets one local address and port hold
      # a second established connection when the destination differs, so a socket to 127.0.0.2:443 can
      # share the port nginx recorded. Matched on the local port alone, `head -1` took whichever row came
      # first and could name that socket's owner for this request. A 4-tuple is one socket, so this
      # names exactly one row or none. `[::1]` is not accepted: the host already fails unless nginx saw
      # exactly 127.0.0.1, and the connector's service is configured for that address.
      #
      # ⚠️ `-H` LEAVES NO STATE COLUMN. Measured on stage: a row is `0  0  <local>  <peer>  users:(…)`,
      # ten fields, the queues first — so the local address is field 3 and the peer field 4, compared
      # whole. A pattern anchored on a leading state field matched nothing, emitted `PROBE-OWNER … none`,
      # and turned a FAIL into a VOID that read like a fix.
      owner=$(timeout 5 ss -Htnpe state established '( dport = :443 )' 2>/dev/null \
        | awk -v src="127.0.0.1:$port" '$3 == src && $4 == "127.0.0.1:443"' | head -1 || true)
      printf 'PROBE-OWNER %s %s\n' "$id" "${owner:-none}"
    fi

    printf 'STATE collect one line for %s, written by worker %s, which the reload created\n' "$id" "$pid"
    ;;

  stop)
    # ⚠️ A STOP THAT FINDS NOTHING SAYS SO, AND RELOADS NOTHING. The tunnel-log family runs stop whenever start was
    # attempted, because start can fail after it has installed the probe — its drain wait expiring, or ssh dropping
    # once the remote start had finished. For a nonce whose start never got that far, the proof below would refuse
    # for want of a baseline, and that refusal reads as a probe left behind on a server that never had one. So what
    # the server holds decides: the snippet, and the dead-man timer that would reload nginx later. Neither, and
    # there is nothing to remove and no reason to reload a live server.
    armed=$(systemctl list-units --all "$unit.timer" --no-legend 2>/dev/null | grep -c "$unit" || true)

    if [[ ! -e "$conf" ]] && (( armed == 0 )); then
      rm -f "$log" "$state".* 2>/dev/null || true
      printf 'STATE stop absent no snippet at %s and no dead-man timer %s, so nothing of this probe was installed and nginx was not reloaded\n' \
        "$conf" "$unit.timer"
      exit 0
    fi

    rm -f "$conf"
    systemctl stop "$unit.timer" 2>/dev/null || true

    measure nginx -t \
      || refuse "the probe file is removed, but nginx -t now fails, so the server is NOT back as it was: $MEASURED"

    measure nginx -s reload \
      || refuse "the probe file is removed, but nginx would not reload, so it is still serving the probe configuration: $MEASURED"

    measure_into "$state.after" nginx -T \
      || refuse "the probe is removed, and nginx -T failed, so its removal could not be proven: $MEASURED"

    after=$(sha256sum < "$state.after" | cut -d' ' -f1)
    before=$(cat "$state.hash" 2>/dev/null || true)

    # ⚠️ RESTORATION IS PROVEN, NOT ANNOUNCED. The hash is of what nginx itself dumps, so a leftover
    # snippet, a half-removed map, or an operator's own edit made meanwhile all show up here.
    [[ -n "$before" ]] \
      || refuse "the probe is removed, and no baseline hash was recorded, so this cannot prove the configuration is as it was"

    [[ "$after" == "$before" ]] \
      || refuse "the configuration does not hash back to its baseline after removing the probe: something else changed while this ran"

    systemctl list-units --all "$unit.timer" --no-legend 2>/dev/null | grep -q "$unit" \
      && refuse "the probe is removed, but its dead-man timer $unit.timer still exists and would reload nginx later" \
      || true

    printf 'STATE stop removed, dead-man cancelled, configuration hashes back to its baseline\n'
    rm -f "$log" "$state".* 2>/dev/null || true
    ;;

  *)
    refuse "unknown action [$action]: it must be start, collect or stop"
    ;;
esac
