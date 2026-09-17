#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# What nginx hands PHP, and what it listens on — the ADR-034 conditions that live in the web server
# (issue #111). It arrives on stdin behind common.sh, as one stream, and runs as root.
#
#   NGX-1  the configuration can be read, carries its provenance, and did not move while it was read
#   NGX-2  every directive in it is one this runbook has reviewed
#   NGX-3  the values that decide what PHP receives
#   NGX-4  what the running sockets say, which is where `ipv6only=off` is actually visible
#   NGX-5  which nginx this is: binary, package, modules
#
# ⚠️ THE DUMP IS EVIDENCE, NOT THE GATE. What PHP receives is proven by the checks that send a real
# request and read what arrived (the probe-log and throttle families). This family exists because a
# configuration can hold a hazard that no single request would reveal — a second server block, a
# realip directive scoped to a location nothing tested — so it reads the whole of what nginx parsed.
#
# ⚠️ AND A LINE-BASED READING OF THAT DUMP IS NOT A READING OF THE CONFIGURATION. Three shapes in
# stage's own dump break it: a `types { … }` body whose 90-odd lines begin with words like
# `application` and `video`, which are MIME mappings and not directives; trailing comments after a
# directive's `;`; and leading tabs, which make `include` and `\tinclude` look like two names. A
# quoted value may also span newlines. So the dump is tokenized the way nginx reads it — quotes,
# escapes, comments only at a token start — and every rule below runs over the tokens, never over
# the lines.
set -euo pipefail

family nginx NGX-1 NGX-2 NGX-3 NGX-4 NGX-5
topologies tunnel dns-only
require_root

work=$(mktemp -d) || refuse "could not make a working directory"

# ⚠️ REGISTERED, NOT TRAPPED. common.sh's EXIT trap prints the sentinel, and a `trap … EXIT` here
# would replace it: the family would succeed and say nothing, which run.sh reads as a truncated
# stream.
cleanup_at_exit "$work"

# --- NGX-1: the configuration, read twice -------------------------------------------------------
#
# Two dumps back to back, because every rule below is about the configuration nginx is running: if
# the file changed under us — a deploy, an operator, a package upgrade — the rules would describe
# neither the old one nor the new one. A reload is deliberately NOT part of this family: reloading to
# prove the workers are new belongs to the probe-log family, which needs it for its own reason, and
# this family should not touch a live service to read it.

# ⚠️ STDOUT ONLY, AND THE REASON IS A MEASUREMENT. nginx writes "nginx: the configuration file …
# syntax is ok" and "… test is successful" to STDERR. Folded into the dump, those two lines were
# tokenized as a directive called `nginx:` which swallowed the first real directive of the file —
# caught on the first run against the live server, because every stub printed only the configuration.
if ! measure_into "$work/dump1" nginx -T; then
  verdict NGX-1 VOID "nginx -T failed, so there is no configuration to check: $MEASURED"
  verdict NGX-2 VOID "no configuration was read"
  verdict NGX-3 VOID "no configuration was read"
  verdict NGX-4 VOID "no configuration was read"
  verdict NGX-5 VOID "no configuration was read"
  exit 0
fi

if ! measure_into "$work/dump2" nginx -T; then
  verdict NGX-1 VOID "nginx -T failed on the second read: $MEASURED"
  verdict NGX-2 VOID "the configuration could not be read twice"
  verdict NGX-3 VOID "the configuration could not be read twice"
  verdict NGX-4 VOID "the configuration could not be read twice"
  verdict NGX-5 VOID "the configuration could not be read twice"
  exit 0
fi

markers=$(grep -c '^# configuration file ' "$work/dump2" || true)

if ! cmp -s "$work/dump1" "$work/dump2"; then
  verdict NGX-1 VOID "the configuration changed between two reads taken back to back, so no rule below would describe what is running"
elif (( markers == 0 )); then
  # ⚠️ THE MARKERS ARE COMMENTS. A dump that was filtered for comments before it reached this script
  # has lost which file each directive came from, and an `include` can then no longer be resolved.
  # That is not a host fault and must not read as one: it is a VOID input.
  verdict NGX-1 VOID "the dump carries no '# configuration file' markers, so it was filtered and its provenance is gone"
else
  verdict NGX-1 PASS "read twice, identical, with $markers configuration files named"
fi

# --- the tokenizer ------------------------------------------------------------------------------
#
# Emits one record per directive: <file>\t<context>\t<name>\t<args>, where context is the block path
# (`http`, `http>server`, `http>server>location ~ ^/index\.php(/|$)`). It follows nginx's own reading:
# a `#` starts a comment only at a token start, quotes may span newlines, a backslash escapes the
# next character, and `;`, `{`, `}` end a directive. The `types` body is emitted with the context
# `…>types`, so the rules can ignore mappings without ignoring a directive hidden among them.

awk '
  function flush_token() { if (token != "") { args[++n] = token; token = "" } }
  BEGIN { FS = ""; depth = 0; n = 0; file = "(unknown)" }
  /^# configuration file / { file = $0; sub(/^# configuration file /, "", file); sub(/:$/, "", file); next }
  {
    for (i = 1; i <= NF; i++) {
      c = $i
      if (escaped) { token = token c; escaped = 0; continue }
      if (c == "\\") { escaped = 1; token = token c; continue }
      if (quote != "") { token = token c; if (c == quote) quote = ""; continue }
      if (c == "\"" || c == "'"'"'") { quote = c; token = token c; continue }
      if (c == "#" && token == "") { break }
      if (c == " " || c == "\t") { flush_token(); continue }
      if (c == ";" || c == "{" || c == "}") {
        flush_token()
        if (c == "}") {
          if (depth > 0) {
            # A block path is segments joined with ">", so closing the innermost block drops the last
            # segment — and a top-level block has no ">" to drop, which leaves the path empty.
            if (context ~ />/) { sub(/>[^>]*$/, "", context) } else { context = "" }
            depth--
          }
          n = 0
          continue
        }
        if (n > 0) {
          name = args[1]
          rest = ""
          for (j = 2; j <= n; j++) rest = rest (j > 2 ? " " : "") args[j]
          if (c == "{") {
            printf "%s\t%s\t%s\t%s\n", file, (context == "" ? "(root)" : context), name, rest
            context = (context == "" ? name (rest == "" ? "" : " " rest) : context ">" name (rest == "" ? "" : " " rest))
            depth++
          } else {
            printf "%s\t%s\t%s\t%s\n", file, (context == "" ? "(root)" : context), name, rest
          }
        }
        n = 0
        continue
      }
      token = token c
    }
    flush_token()
  }
' "$work/dump2" > "$work/tokens" 2>"$work/tokens.err" || true

if [[ ! -s "$work/tokens" ]]; then
  verdict NGX-2 VOID "the configuration produced no directives when tokenized: $(head -c 200 "$work/tokens.err")"
  verdict NGX-3 VOID "nothing was tokenized"
else
  # --- NGX-2: the directive-name allowlist ------------------------------------------------------
  #
  # ⚠️ AN ALLOWLIST, NOT A LIST OF THINGS TO FORBID. Naming the hazards — proxy_pass, set_real_ip_from,
  # load_module, a lua block, headers-more — means the check is silent about the one nobody thought
  # of. Everything nginx parsed must be a name this runbook has reviewed, so a directive that arrives
  # with a new module fails loudly instead of passing unseen. MIME mappings inside `types` are values,
  # not directives, and are skipped by context.
  # Reviewed against what each one can do to a request before it reaches PHP. `log_not_found off`
  # only silences "file not found" lines in the error log for the favicon and robots locations, and
  # was the one name stage used that this list first missed — found by this check failing on it,
  # which is the shape a new module would arrive in.
  allowed='access_log charset default_type deny error_log error_page events fastcgi_buffer_size
    fastcgi_buffers fastcgi_busy_buffers_size fastcgi_hide_header fastcgi_param fastcgi_pass gzip
    http include index listen location log_not_found pid return root sendfile server server_name
    server_tokens ssl_certificate ssl_certificate_key ssl_prefer_server_ciphers ssl_protocols
    ssl_reject_handshake tcp_nopush try_files types types_hash_max_size user worker_connections
    worker_cpu_affinity worker_processes'

  # ⚠️ `awk -v` TAKES NO NEWLINE. The list above is wrapped for reading, and passing it as-is makes
  # awk die with "newline in string" — which, inside a command substitution under `set -e`, ended the
  # whole family after its first verdict and printed nothing about why. It is flattened here, and the
  # awk run is guarded so its failure is a VOID rather than a silent exit.
  flat=$(tr -s ' \t\n' ' ' <<<"$allowed")

  if ! awk -F'\t' -v allowed="$flat" '
    BEGIN { split(allowed, a, / /); for (i in a) if (a[i] != "") ok[a[i]] = 1 }
    $2 ~ /(^|>)types$/ { next }
    !($3 in ok) { print $3 " (" $1 ", in " $2 ")" }
  ' "$work/tokens" > "$work/unknown" 2>"$work/unknown.err"; then
    verdict NGX-2 VOID "the directive names could not be read: $(head -c 200 "$work/unknown.err")"
    unknown=""
  else
    unknown=$(sort -u "$work/unknown")
  fi

  if [[ -n "$unknown" ]]; then
    verdict NGX-2 FAIL "directives this runbook has never reviewed: $(tr '\n' '; ' <<<"$unknown")"
  else
    verdict NGX-2 PASS "every directive is one of the $(wc -w <<<"$allowed" | tr -d ' ') reviewed names"
  fi

  # --- NGX-3: the values that decide what PHP receives ------------------------------------------
  problems=""

  # listen: parameters, and the address itself. `unix:` is an address, not a parameter, so a rule
  # over parameters alone never sees `listen unix:/…`.
  while IFS=$'\t' read -r _ _ _ args; do
    [[ -n "$args" ]] || continue
    address=${args%% *}
    params=${args#"$address"}

    case "$address" in
      unix:*) problems="$problems; listen on a unix socket: $args" ;;
    esac

    for param in $params; do
      case "$param" in
        default_server | ssl | http2 | http3) ;;
        *) problems="$problems; listen parameter [$param] in: $args" ;;
      esac
    done
  done < <(awk -F'\t' '$3 == "listen"' "$work/tokens")

  # The two directives that let a client's X_Forwarded_For reach PHP as a second HTTP_X_FORWARDED_FOR.
  while IFS=$'\t' read -r _ context name args; do
    value=${args%% *}
    case "$name:$value" in
      underscores_in_headers:on) problems="$problems; underscores_in_headers on in $context" ;;
      ignore_invalid_headers:off) problems="$problems; ignore_invalid_headers off in $context" ;;
    esac
  done < <(awk -F'\t' '$3 == "underscores_in_headers" || $3 == "ignore_invalid_headers"' "$work/tokens")

  # What FastCGI passes to PHP. REMOTE_ADDR must be the peer nginx sees, exactly once, and no
  # spelling of X-Forwarded-For may be set as a parameter: that replaces the header nginx received.
  remote_addr=$(awk -F'\t' '$3 == "fastcgi_param" && $4 ~ /^REMOTE_ADDR[ \t]/' "$work/tokens" | wc -l | tr -d ' ')
  remote_addr_value=$(awk -F'\t' '$3 == "fastcgi_param" && $4 ~ /^REMOTE_ADDR[ \t]/ { print $4 }' "$work/tokens" | head -1)

  (( remote_addr == 1 )) || problems="$problems; fastcgi_param REMOTE_ADDR is set $remote_addr times"
  [[ "$remote_addr_value" == "REMOTE_ADDR \$remote_addr" ]] || problems="$problems; fastcgi_param REMOTE_ADDR is [$remote_addr_value], not \$remote_addr"

  forwarded=$(awk -F'\t' 'tolower($4) ~ /^http_x.forwarded.for[ \t]/ && $3 == "fastcgi_param" { print $1 ": " $4 }' "$work/tokens")
  [[ -z "$forwarded" ]] || problems="$problems; a fastcgi_param sets X-Forwarded-For, replacing the header nginx received: $forwarded"

  # Where PHP is, and that realip is nowhere: the allowlist already fails an unreviewed name, and
  # this says so in the value rules too, because it is ADR-034's condition rather than housekeeping.
  while IFS=$'\t' read -r _ context _ args; do
    case "$args" in
      unix:*) ;;
      *) problems="$problems; fastcgi_pass to [$args] in $context, not a unix socket" ;;
    esac
  done < <(awk -F'\t' '$3 == "fastcgi_pass"' "$work/tokens")

  realip=$(awk -F'\t' '$3 == "set_real_ip_from" || $3 == "real_ip_header" || $3 == "real_ip_recursive" { print $3 " in " $2 }' "$work/tokens")
  [[ -z "$realip" ]] || problems="$problems; realip directives, which resolve an address from a header: $realip"

  if [[ -n "$problems" ]]; then
    verdict NGX-3 FAIL "${problems#; }"
  else
    verdict NGX-3 PASS "listen parameters, header handling, the FastCGI parameter set and the absence of realip all hold"
  fi
fi

# --- NGX-4: the running sockets ------------------------------------------------------------------
#
# ⚠️ WHERE `ipv6only=off` IS ACTUALLY VISIBLE. A dual-stack listener accepts IPv4 traffic on the IPv6
# socket and hands PHP a `::ffff:` address, which ADR-034's trusted list does not match. The socket
# knows; the configuration text only says what was asked for. `-e` is what prints the attribute:
# `-d` is --dccp, and a capture taken with it shows no v6only at all (measured, and it cost a
# correction in this design).

if ! measure ss -Htlnpe; then
  verdict NGX-4 VOID "ss failed, so the running sockets could not be read: $MEASURED"
else
  printf '%s\n' "$MEASURED" > "$work/sockets"

  v6_rows=$(grep -c '\[::\]:' "$work/sockets" || true)
  v6_only=$(grep '\[::\]:' "$work/sockets" | grep -c 'v6only:1' || true)
  nginx_rows=$(grep -c '"nginx"' "$work/sockets" || true)

  if (( nginx_rows == 0 )); then
    verdict NGX-4 VOID "no LISTEN socket is owned by nginx, so either it is not running or this did not see it"
  elif (( v6_rows == 0 )); then
    verdict NGX-4 VOID "no IPv6 listener was found at all, so the v6only attribute could not be read"
  elif (( v6_only != v6_rows )); then
    verdict NGX-4 FAIL "$((v6_rows - v6_only)) of $v6_rows IPv6 listeners are not v6only, so a dual-stack socket can hand PHP a ::ffff: address"
  else
    verdict NGX-4 PASS "all $v6_rows IPv6 listeners are v6only:1, with $nginx_rows sockets owned by nginx"
  fi

  record NGX-4 "$(awk '{ print $4 }' "$work/sockets" | tr '\n' ' ')"
fi

# --- NGX-5: which nginx this is ------------------------------------------------------------------

if ! measure nginx -V; then
  verdict NGX-5 VOID "nginx -V failed: $MEASURED"
else
  version=$MEASURED

  # ⚠️ NEVER INFER THE VERSION FROM WHAT THE CONFIGURATION USES. Stage reports 1.28.3 and parses
  # $request_port, which upstream added in 1.29.3: Ubuntu backported it. A check that concluded "this
  # cannot be 1.28.3" would be wrong about the host and right about nothing.
  case "$version" in
    *--with-http_realip_module*) realip_module=yes ;;
    *) realip_module=no ;;
  esac

  # ⚠️ THE PID FILE IS A CONFIGURED PATH, NOT A FACT. `pid /run/nginx.pid;` is in stage's dump, but a
  # host that sets it elsewhere — or has not written it yet — would make this check report the binary
  # as unidentified while nginx is plainly running. So the pid file is tried first and the running
  # master process second, and only then is it VOID.
  master_pid=$(cat /run/nginx.pid 2>/dev/null || true)

  if [[ ! "$master_pid" =~ ^[0-9]+$ ]]; then
    master_pid=$(pgrep -o -f 'nginx: master process' 2>/dev/null || true)
  fi

  if [[ "$master_pid" =~ ^[0-9]+$ ]] && measure readlink -f /proc/"$master_pid"/exe; then
    master_exe=$MEASURED
  else
    master_exe="(unreadable)"
  fi

  record NGX-5 "$(head -1 <<<"$version")"
  record NGX-5 "master exe $master_exe, realip module built in: $realip_module"

  if [[ "$master_exe" == "(unreadable)" ]]; then
    verdict NGX-5 VOID "the master's own binary could not be read from /proc, so what is running is unidentified"
  else
    verdict NGX-5 PASS "$(head -1 <<<"$version"), running from $master_exe"
  fi
fi
