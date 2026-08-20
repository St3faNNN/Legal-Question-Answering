#!/usr/bin/env bash
#
# verify-reliability.sh — live-stack regression check for the streaming pipeline.
#
# Exercises the reliability behaviour that the Pest suite can only prove in
# isolation, against a RUNNING `docker compose` stack. Each check prints what it
# is doing, what it expects, and the actual observed value with a PASS/FAIL.
#
#   Checks (run a subset by naming them; default runs all):
#     normal              end-to-end reply; reports retrieval_ms/ttft_ms/total_ms
#     idempotency         a re-dispatched stream is not generated twice
#     skip-log            the duplicate-skip is observable (shortfall #5):
#                         logs graphrag.stream_skipped_duplicate, no failed_jobs
#     pretoken-retry      a pre-token failure is retried, then a clean error
#     first-chunk-timeout a worker killed pre-token surfaces a terminal error in
#                         ~30s (hosted) / ~120s (grpc), not minutes
#     worker-restart      a killed worker revives itself; the orphan is present
#                         for reaping (add --wait-reap to watch it clear)
#
#   Usage:
#     scripts/verify-reliability.sh                 # all checks
#     scripts/verify-reliability.sh normal skip-log # a subset
#     scripts/verify-reliability.sh --wait-reap worker-restart
#     scripts/verify-reliability.sh --app-url http://localhost:8000 --keep
#
#   Flags:
#     --app-url URL   base URL of the app        (default: http://localhost:8000)
#     --wait-reap     for worker-restart, wait out retry_after to see the orphan
#                     reaped (adds up to ~7 min); otherwise it is reported deferred
#     --keep          do not delete the rows/keys this run created (for debugging)
#     -h | --help     this help
#
#   Cost: a full run makes ~1 hosted-generation (Gemini) call — only `normal`
#   runs generation to completion; the failure-injection checks kill or fail
#   before generation and cost 0. On the grpc/local-llm profile: 0 external calls.
#
#   Cleanup: the script tags every row it creates and, on exit, deletes ONLY
#   those specific ids (never a range, never a row it did not make) and restores
#   any service it stopped or killed.
#
set -uo pipefail

# ---------------------------------------------------------------------------
# Config + argument parsing
# ---------------------------------------------------------------------------
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || { echo "cannot cd to repo root"; exit 2; }

APP_URL="http://localhost:8000"
WAIT_REAP=0
KEEP=0
REQUESTED=()

while [ $# -gt 0 ]; do
    case "$1" in
        --app-url) APP_URL="$2"; shift 2 ;;
        --wait-reap) WAIT_REAP=1; shift ;;
        --keep) KEEP=1; shift ;;
        -h|--help) awk 'NR==1{next} /^#/{sub(/^# ?/,"");print;next} {exit}' "${BASH_SOURCE[0]}"; exit 0 ;;
        -*) echo "unknown flag: $1"; exit 2 ;;
        *) REQUESTED+=("$1"); shift ;;
    esac
done

ALL_CHECKS=(normal idempotency skip-log pretoken-retry first-chunk-timeout worker-restart)
if [ "${#REQUESTED[@]}" -eq 0 ] || { [ "${#REQUESTED[@]}" -eq 1 ] && [ "${REQUESTED[0]}" = "all" ]; }; then
    CHECKS=("${ALL_CHECKS[@]}")
else
    CHECKS=("${REQUESTED[@]}")
fi

DC="docker compose"
TMP="$(mktemp -d 2>/dev/null || mktemp -d -t verifyrel)"

# Tracked-for-cleanup registries (specific ids only).
CREATED_USER_ID=""
TOKEN=""
declare -a CONV_IDS=()
declare -a STREAM_IDS=()
declare -a SSE_PIDS=()

# Memoized completed stream (shared by normal / idempotency / skip-log).
COMPLETED_CONV=""; COMPLETED_STREAM=""; COMPLETED_DONE_LINE=""
DUP_STREAM=""; DUP_CONV=""; DUP_SKIP_LOGGED=""

# Results for the summary.
declare -a RESULTS=()
ANY_FAIL=0

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------
if [ -t 1 ]; then B=$'\e[1m'; G=$'\e[32m'; R=$'\e[31m'; Y=$'\e[33m'; D=$'\e[2m'; Z=$'\e[0m'; else B=""; G=""; R=""; Y=""; D=""; Z=""; fi

say()      { printf '%s\n' "$*"; }
head_ck()  { printf '\n%s━━ CHECK: %s%s — %s\n' "$B" "$1" "$Z" "$2"; }
doing()    { printf '   %sdoing:%s    %s\n' "$D" "$Z" "$*"; }
expectln() { printf '   %sexpect:%s   %s\n' "$D" "$Z" "$*"; }
observe()  { printf '   %sobserved:%s %s\n' "$D" "$Z" "$*"; }
pass()     { printf '   %sRESULT:   PASS%s\n' "$G" "$Z"; RESULTS+=("$1|PASS|$2"); }
fail()     { printf '   %sRESULT:   FAIL%s\n' "$R" "$Z"; RESULTS+=("$1|FAIL|$2"); ANY_FAIL=1; }
skipck()   { printf '   %sRESULT:   SKIP%s (%s)\n' "$Y" "$Z" "$2"; RESULTS+=("$1|SKIP|$2"); }
note()     { printf '   %s· %s%s\n' "$D" "$*" "$Z"; }

# ---------------------------------------------------------------------------
# Infrastructure helpers
# ---------------------------------------------------------------------------
psql_q()  { $DC exec -T postgres psql -U graphrag -d graphrag_app -tAc "$1" 2>/dev/null | tr -d '\r'; }
redis_q() { $DC exec -T redis redis-cli "$@" 2>/dev/null | tr -d '\r'; }
now()     { date +%s.%N; }
elapsed() { awk "BEGIN{printf \"%.1f\", $2 - $1}"; }
json_num(){ printf '%s' "$1" | grep -oE "\"$2\":[0-9]+" | grep -oE '[0-9]+' | head -1; }

wait_running() { # service timeout -> 0 if State.Running
    local svc="$1" to="$2" cid deadline
    deadline=$(( $(date +%s) + to ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        cid="$($DC ps -q "$svc" 2>/dev/null)"
        if [ -n "$cid" ] && [ "$(docker inspect -f '{{.State.Running}}' "$cid" 2>/dev/null)" = "true" ]; then
            return 0
        fi
        sleep 1
    done
    return 1
}

wait_healthy() { # service timeout -> 0 when healthy (or running if no healthcheck)
    local svc="$1" to="$2" cid st deadline
    deadline=$(( $(date +%s) + to ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        cid="$($DC ps -q "$svc" 2>/dev/null)"
        if [ -n "$cid" ]; then
            st="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$cid" 2>/dev/null)"
            [ "$st" = "healthy" ] || [ "$st" = "running" ] && return 0
        fi
        sleep 2
    done
    return 1
}

# ---------------------------------------------------------------------------
# App helpers (Bearer-token auth — works for auth:sanctum incl. the SSE route)
# ---------------------------------------------------------------------------
create_user_token() {
    local email php out line
    email="reliability-check-$$-$(date +%s)@verify.local"
    php='$e="'"$email"'";$u=\App\Models\User::firstOrCreate(["email"=>$e],["name"=>"reliability-check","password"=>\Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(40)),"email_verified_at"=>now()]);echo "RESULT:::".$u->id.":::".$u->createToken("verify-reliability")->plainTextToken."\n";'
    out="$($DC exec -T laravel php artisan tinker --execute "$php" 2>/dev/null)"
    line="$(printf '%s' "$out" | grep -m1 'RESULT:::')"
    [ -n "$line" ] || { echo "could not create verification user: $out" >&2; return 1; }
    CREATED_USER_ID="$(printf '%s' "$line" | awk -F':::' '{print $2}' | tr -d '[:space:]')"
    TOKEN="$(printf '%s' "$line" | awk -F':::' '{print $3}' | tr -d '[:space:]')"
    [ -n "$CREATED_USER_ID" ] && [ -n "$TOKEN" ]
}

dispatch_stream() { # message -> echoes "convid streamid" and tracks them
    local msg="$1" resp sid cid
    resp="$(curl -s -X POST "$APP_URL/api/v1/chat/stream" \
        -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
        -d "{\"message\":\"$msg\"}")"
    sid="$(printf '%s' "$resp" | grep -oE '"stream_id":"[^"]+"' | sed -E 's/.*:"([^"]+)"/\1/')"
    cid="$(printf '%s' "$resp" | grep -oE '"id":[0-9]+' | head -1 | grep -oE '[0-9]+')"
    [ -n "$sid" ] && [ -n "$cid" ] || { echo "dispatch failed: $resp" >&2; return 1; }
    CONV_IDS+=("$cid"); STREAM_IDS+=("$sid")
    printf '%s %s\n' "$cid" "$sid"
}

redispatch() { # convid streamid message  (re-run the same stream id via the queue)
    local php
    php='\App\Jobs\StreamChatReply::dispatch('"$1"',"'"$2"'","'"$3"'",microtime(true));echo "REDISPATCHED\n";'
    $DC exec -T laravel php artisan tinker --execute "$php" >/dev/null 2>&1
}

sse_capture() { # streamid outfile maxtime -> starts a backgrounded, timestamped reader
    local sid="$1" out="$2" maxt="$3"
    : > "$out"
    ( curl -sN --max-time "$maxt" -H "Authorization: Bearer $TOKEN" \
        "$APP_URL/api/v1/chat/stream/$sid" \
        | while IFS= read -r l; do printf '%s\t%s\n' "$(now)" "$l"; done >> "$out" ) &
    SSE_PIDS+=("$!")
}

wait_sse_event() { # file jsonpattern timeout -> sets EV_TS/EV_LINE, 0 if found
    local f="$1" pat="$2" to="$3" deadline line
    deadline=$(( $(date +%s) + to ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        line="$(grep -m1 -F -- "$pat" "$f" 2>/dev/null)"
        if [ -n "$line" ]; then
            EV_TS="${line%%$'\t'*}"; EV_LINE="${line#*$'\t'}"; return 0
        fi
        sleep 0.3
    done
    return 1
}

last_status_ts() { # file -> echoes timestamp of the last status event seen
    grep -F '"type":"status"' "$1" 2>/dev/null | tail -1 | cut -f1
}

# Dispatch a normal query and read the SSE stream to the done event.
# Sets COMPLETED_CONV/COMPLETED_STREAM/COMPLETED_DONE_LINE and T_DISPATCH/T_DONE.
complete_one_stream() {
    local pair cid sid f
    pair="$(dispatch_stream "What are the legal working hours?")" || return 1
    cid="${pair% *}"; sid="${pair#* }"
    T_DISPATCH="$(now)"
    f="$TMP/sse-normal-$sid.log"
    sse_capture "$sid" "$f" 120
    if ! wait_sse_event "$f" '"type":"done"' 120; then
        COMPLETED_DONE_LINE=""; return 1
    fi
    T_DONE="$EV_TS"; COMPLETED_DONE_LINE="$EV_LINE"
    COMPLETED_CONV="$cid"; COMPLETED_STREAM="$sid"
    return 0
}

ensure_dup_scenario() {
    [ -n "$DUP_STREAM" ] && return 0
    if [ -z "$COMPLETED_STREAM" ]; then complete_one_stream || return 1; fi
    DUP_CONV="$COMPLETED_CONV"; DUP_STREAM="$COMPLETED_STREAM"
    redispatch "$DUP_CONV" "$DUP_STREAM" "verify duplicate execution"
    if poll_skip_log "$DUP_STREAM" 25; then DUP_SKIP_LOGGED="yes"; else DUP_SKIP_LOGGED="no"; fi
    return 0
}

poll_skip_log() { # streamid timeout
    local sid="$1" to="$2" deadline
    deadline=$(( $(date +%s) + to ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        if $DC exec -T worker sh -c "grep -h stream_skipped_duplicate /var/www/html/storage/logs/ai-*.log 2>/dev/null | grep -q '$sid'"; then
            return 0
        fi
        sleep 1
    done
    return 1
}

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------
preflight() {
    say "${B}Preflight${Z} — $APP_URL"
    local svc missing=0
    for svc in postgres redis retrieval laravel worker; do
        if ! wait_running "$svc" 3; then say "  ${R}✗${Z} service '$svc' is not running"; missing=1; fi
    done
    if [ "$missing" = 1 ]; then
        say "  Bring the stack up first:  docker compose up -d"
        exit 2
    fi
    local code
    # Probe the health endpoint, not an auth-guarded route: it needs no token
    # (none exists yet at this point) and returns a clean 200 when the app is up.
    code="$(curl -s -o /dev/null -w '%{http_code}' "$APP_URL/up" 2>/dev/null)"
    if [ "$code" != "200" ]; then
        say "  ${R}✗${Z} app not reachable at $APP_URL (GET /up → ${code:-no response})"; exit 2
    fi
    # The idempotency guard needs the Section 3 column.
    if [ "$(psql_q "SELECT 1 FROM information_schema.columns WHERE table_name='messages' AND column_name='stream_id' LIMIT 1;")" != "1" ]; then
        say "  ${R}✗${Z} messages.stream_id column missing — the running stack predates the Section 3 migration."
        say "     Recreate it:  docker compose up -d --build   (runs migrate on boot)"; exit 2
    fi
    GEN_DRIVER="$($DC exec -T worker printenv AI_GENERATOR 2>/dev/null | tr -d '[:space:]')"
    [ -z "$GEN_DRIVER" ] && GEN_DRIVER="unknown"
    if [ "$GEN_DRIVER" = "grpc" ]; then FIRST_CHUNK_EXPECT=120; else FIRST_CHUNK_EXPECT=30; fi
    RETRY_AFTER="$($DC exec -T worker printenv DB_QUEUE_RETRY_AFTER 2>/dev/null | tr -d '[:space:]')"; [ -z "$RETRY_AFTER" ] && RETRY_AFTER=90
    local pol cid; cid="$($DC ps -q worker)"
    pol="$(docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$cid" 2>/dev/null)"
    say "  ${G}✓${Z} stack up · generator=${GEN_DRIVER} · retry_after=${RETRY_AFTER}s · worker restart-policy=${pol:-none}"
    if [ "$pol" != "unless-stopped" ]; then
        say "  ${Y}!${Z} worker restart-policy is '${pol:-none}', not 'unless-stopped' — the running container predates the"
        say "     Section 2 amendment. 'worker-restart' will fail until you: docker compose up -d"
    fi
    if ! create_user_token; then say "  ${R}✗${Z} could not create the verification user"; exit 2; fi
    say "  ${G}✓${Z} verification user #$CREATED_USER_ID + token created"
}

# ---------------------------------------------------------------------------
# CHECK: normal
# ---------------------------------------------------------------------------
check_normal() {
    head_ck "normal" "end-to-end reply and the split timers"
    doing "dispatch a real query, read the SSE stream to the done event"
    expectln "a terminal done event carrying retrieval_ms / ttft_ms / total_ms"
    if [ -n "$COMPLETED_DONE_LINE" ]; then
        note "reusing the already-completed stream"
    elif ! complete_one_stream; then
        observe "no done event arrived within 120s"; fail "normal" "no done event"; return
    fi
    local rms tms totms wall
    rms="$(json_num "$COMPLETED_DONE_LINE" retrieval_ms)"
    tms="$(json_num "$COMPLETED_DONE_LINE" ttft_ms)"
    totms="$(json_num "$COMPLETED_DONE_LINE" total_ms)"
    wall="$(elapsed "$T_DISPATCH" "$T_DONE")"
    observe "done in ${wall}s of wall-clock — retrieval_ms=${rms:-?} ttft_ms=${tms:-?} total_ms=${totms:-?}"
    if [ -n "$rms" ] && [ -n "$tms" ] && [ -n "$totms" ]; then
        pass "normal" "retrieval_ms=${rms} ttft_ms=${tms} total_ms=${totms} (wall ${wall}s)"
    else
        fail "normal" "done event missing one or more timers"
    fi
}

# ---------------------------------------------------------------------------
# CHECK: idempotency
# ---------------------------------------------------------------------------
check_idempotency() {
    head_ck "idempotency" "a re-executed stream is not generated twice"
    doing "complete a stream, then re-dispatch the SAME stream_id through the queue"
    expectln "still exactly ONE assistant message for that stream_id (generation skipped)"
    if ! ensure_dup_scenario; then observe "could not establish a completed stream"; fail "idempotency" "setup failed"; return
    fi
    local count
    count="$(psql_q "SELECT count(*) FROM messages WHERE stream_id='$DUP_STREAM' AND role='assistant';")"
    observe "assistant messages for stream ${DUP_STREAM:0:8}… = ${count:-?} (skip logged: ${DUP_SKIP_LOGGED})"
    if [ "$count" = "1" ]; then pass "idempotency" "1 message after re-dispatch (no double generation)"
    else fail "idempotency" "expected 1 assistant message, found ${count:-?}"; fi
}

# ---------------------------------------------------------------------------
# CHECK: skip-log (shortfall #5 is actually observable)
# ---------------------------------------------------------------------------
check_skip_log() {
    head_ck "skip-log" "the duplicate-skip is monitorable (shortfall #5)"
    doing "re-dispatch a completed stream and inspect the worker's ai log + failed_jobs"
    expectln "graphrag.stream_skipped_duplicate present in the log, and 0 rows in failed_jobs"
    if ! ensure_dup_scenario; then observe "could not establish a completed stream"; fail "skip-log" "setup failed"; return
    fi
    local fj
    fj="$(psql_q "SELECT count(*) FROM failed_jobs WHERE payload LIKE '%$DUP_STREAM%';")"
    observe "stream_skipped_duplicate logged: ${DUP_SKIP_LOGGED} · failed_jobs for this stream: ${fj:-?}"
    if [ "$DUP_SKIP_LOGGED" = "yes" ] && [ "${fj:-0}" = "0" ]; then
        pass "skip-log" "log line present, no failed_jobs row — the sole signal is emitted"
    elif [ "$DUP_SKIP_LOGGED" != "yes" ]; then
        fail "skip-log" "graphrag.stream_skipped_duplicate did NOT appear — this failure mode would be unmonitorable"
    else
        fail "skip-log" "unexpected failed_jobs row(s) for the skipped stream: ${fj}"
    fi
}

# ---------------------------------------------------------------------------
# CHECK: pretoken-retry
# ---------------------------------------------------------------------------
check_pretoken_retry() {
    head_ck "pretoken-retry" "a pre-token failure retries, then surfaces a clean error"
    doing "stop retrieval, dispatch, and inspect the stream's Redis event list"
    expectln "multiple 'retrieving' status events (retries) followed by one terminal 'error'"
    local pair sid key deadline retr err
    $DC stop retrieval >/dev/null 2>&1
    note "retrieval stopped (its calls now fail fast, before any token)"
    pair="$(dispatch_stream "What are the legal working hours?")" || { $DC start retrieval >/dev/null 2>&1; fail "pretoken-retry" "dispatch failed"; return; }
    sid="${pair#* }"; key="chat:stream:$sid"
    # Poll the (undrained) list until the terminal error appears, up to ~50s.
    deadline=$(( $(date +%s) + 50 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        err="$(redis_q LRANGE "$key" 0 -1 | grep -c '"type":"error"')"
        [ "${err:-0}" -ge 1 ] && break
        sleep 1
    done
    retr="$(redis_q LRANGE "$key" 0 -1 | grep -c '"stage":"retrieving"')"
    err="$(redis_q LRANGE "$key" 0 -1 | grep -c '"type":"error"')"
    observe "retrieving attempts=${retr:-0} · terminal error events=${err:-0}"
    $DC start retrieval >/dev/null 2>&1
    note "retrieval restarting (waiting for healthy so later checks can run)…"
    wait_healthy retrieval 90 || note "retrieval did not report healthy within 90s"
    if [ "${retr:-0}" -ge 2 ] && [ "${err:-0}" -ge 1 ]; then
        pass "pretoken-retry" "${retr} attempts then a clean terminal error (retries engage on a pre-token failure)"
        note "note: the retry SUCCEEDING on recovery is covered by the unit suite; recovering retrieval within the"
        note "      ~6s / 3-try window is not feasible externally (a real reboot is slower), so this asserts retries engage."
    else
        fail "pretoken-retry" "expected >=2 retrieving attempts and >=1 error; got attempts=${retr:-0} error=${err:-0}"
    fi
}

# ---------------------------------------------------------------------------
# CHECK: first-chunk-timeout
# ---------------------------------------------------------------------------
check_first_chunk_timeout() {
    head_ck "first-chunk-timeout" "a worker killed pre-token unblocks the client fast"
    doing "dispatch, wait for the retrieving status, kill the worker, time the terminal event"
    expectln "an SSE error ~${FIRST_CHUNK_EXPECT}s after the last status event (driver=${GEN_DRIVER}), not minutes"
    local pair sid f status_ts err_ts d_status d_dispatch t0
    pair="$(dispatch_stream "What are the legal working hours?")" || { fail "first-chunk-timeout" "dispatch failed"; return; }
    sid="${pair#* }"; t0="$(now)"
    f="$TMP/sse-kill-$sid.log"
    sse_capture "$sid" "$f" 200
    if ! wait_sse_event "$f" '"stage":"retrieving"' 30; then
        observe "worker never published 'retrieving' (did it pick up the job?)"; fail "first-chunk-timeout" "no retrieving status"; restore_worker; return
    fi
    $DC kill worker >/dev/null 2>&1
    note "worker killed during retrieval (pre-token; generation never runs → 0 API calls)"
    if ! wait_sse_event "$f" '"type":"error"' $((FIRST_CHUNK_EXPECT + 60)); then
        observe "no terminal error within $((FIRST_CHUNK_EXPECT + 60))s — the client would have hung"
        fail "first-chunk-timeout" "no terminal error observed"; restore_worker; return
    fi
    err_ts="$EV_TS"
    status_ts="$(last_status_ts "$f")"; [ -z "$status_ts" ] && status_ts="$t0"
    d_status="$(elapsed "$status_ts" "$err_ts")"
    d_dispatch="$(elapsed "$t0" "$err_ts")"
    local reason=""; printf '%s' "$EV_LINE" | grep -q '"reason":"timeout"' && reason="timeout"
    observe "terminal error ${d_status}s after last status (${d_dispatch}s after dispatch); reason=${reason:-?}"
    restore_worker
    # Accept a generous band around the expected window; the point is ~30s vs minutes.
    local lo hi ok
    lo="$FIRST_CHUNK_EXPECT"; hi=$(awk "BEGIN{print $FIRST_CHUNK_EXPECT*1.5 + 5}")
    ok="$(awk "BEGIN{print ($d_status >= $lo-3 && $d_status <= $hi) ? 1 : 0}")"
    if [ "$ok" = "1" ] && [ "$reason" = "timeout" ]; then
        pass "first-chunk-timeout" "clean 'timeout' error ${d_status}s after last status (expected ~${FIRST_CHUNK_EXPECT}s)"
    else
        fail "first-chunk-timeout" "delay ${d_status}s / reason ${reason:-?} outside expected ~${FIRST_CHUNK_EXPECT}s window"
    fi
}

restore_worker() {
    # The restart policy should revive it; wait, then force it up as a fallback.
    wait_running worker 25 || $DC up -d worker >/dev/null 2>&1
    wait_running worker 30 || true
}

# ---------------------------------------------------------------------------
# CHECK: worker-restart
# ---------------------------------------------------------------------------
check_worker_restart() {
    head_ck "worker-restart" "a killed worker revives itself and can reap the orphan"
    doing "reserve a job, kill the worker, confirm it comes back on its own; check the orphan"
    expectln "worker returns to Running via restart-policy; the orphaned job sits in 'jobs' for reaping"
    local pair sid t_kill t_up d_up orphan deadline
    pair="$(dispatch_stream "What are the legal working hours?")" || { fail "worker-restart" "dispatch failed"; return; }
    sid="${pair#* }"
    # Wait until the worker has reserved the job, then kill it (pre-token).
    deadline=$(( $(date +%s) + 15 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        [ "$(psql_q "SELECT count(*) FROM jobs WHERE payload LIKE '%$sid%' AND reserved_at IS NOT NULL;")" = "1" ] && break
        sleep 0.5
    done
    $DC kill worker >/dev/null 2>&1
    t_kill="$(now)"
    note "worker killed while holding a reserved job"
    if wait_running worker 40; then
        t_up="$(now)"; d_up="$(elapsed "$t_kill" "$t_up")"
    else
        d_up=""
    fi
    orphan="$(psql_q "SELECT count(*) FROM jobs WHERE payload LIKE '%$sid%';")"
    if [ -n "$d_up" ]; then
        observe "worker back to Running ${d_up}s after kill (unassisted) · orphaned job in table: ${orphan:-?}"
    else
        observe "worker did NOT come back within 40s · orphaned job in table: ${orphan:-?}"
    fi
    if [ -z "$d_up" ]; then
        fail "worker-restart" "worker did not auto-restart (restart-policy not applied? run: docker compose up -d)"
        $DC up -d worker >/dev/null 2>&1; wait_running worker 30 || true; return
    fi
    if [ "${orphan:-0}" != "1" ]; then
        fail "worker-restart" "expected 1 orphaned job for this stream, found ${orphan:-?}"; return
    fi
    if [ "$WAIT_REAP" = "1" ]; then
        note "--wait-reap: watching the live worker reclaim the orphan (up to retry_after=${RETRY_AFTER}s + margin)…"
        local rdeadline reaped=0
        rdeadline=$(( $(date +%s) + RETRY_AFTER + 60 ))
        while [ "$(date +%s)" -lt "$rdeadline" ]; do
            [ "$(psql_q "SELECT count(*) FROM jobs WHERE payload LIKE '%$sid%';")" = "0" ] && { reaped=1; break; }
            sleep 5
        done
        if [ "$reaped" = "1" ]; then
            pass "worker-restart" "auto-restart in ${d_up}s; orphan reaped by the live worker within retry_after"
        else
            fail "worker-restart" "auto-restart in ${d_up}s but orphan not reaped within retry_after+60s"
        fi
    else
        pass "worker-restart" "auto-restart in ${d_up}s; orphan present (reaping occurs at retry_after=${RETRY_AFTER}s — re-run with --wait-reap to watch it)"
    fi
}

# ---------------------------------------------------------------------------
# Cleanup — only the specific ids/keys this run created.
# ---------------------------------------------------------------------------
CLEANED=0
cleanup() {
    [ "$CLEANED" = 1 ] && return; CLEANED=1
    local pid sid cid
    for pid in "${SSE_PIDS[@]:-}"; do [ -n "$pid" ] && kill "$pid" >/dev/null 2>&1; done
    pkill -f "chat/stream/" >/dev/null 2>&1

    # Make sure nothing we touched is left stopped/dead.
    $DC start retrieval >/dev/null 2>&1
    wait_running worker 3 || $DC up -d worker >/dev/null 2>&1

    if [ "$KEEP" = 1 ]; then
        printf '\n%s--keep set:%s leaving created rows/keys. user=#%s conversations=[%s] streams=[%s]\n' \
            "$Y" "$Z" "$CREATED_USER_ID" "${CONV_IDS[*]:-}" "${STREAM_IDS[*]:-}"
    else
        for sid in "${STREAM_IDS[@]:-}"; do
            [ -z "$sid" ] && continue
            psql_q "DELETE FROM messages    WHERE stream_id='$sid';"           >/dev/null
            psql_q "DELETE FROM jobs         WHERE payload LIKE '%$sid%';"      >/dev/null
            psql_q "DELETE FROM failed_jobs  WHERE payload LIKE '%$sid%';"      >/dev/null
            redis_q DEL "chat:stream:$sid" "chat:stream:$sid:user" "chat:stream:$sid:emitted" >/dev/null
        done
        for cid in "${CONV_IDS[@]:-}"; do
            [ -z "$cid" ] && continue
            psql_q "DELETE FROM messages      WHERE conversation_id=$cid;" >/dev/null
            psql_q "DELETE FROM conversations WHERE id=$cid;"              >/dev/null
        done
        if [ -n "$CREATED_USER_ID" ]; then
            psql_q "DELETE FROM personal_access_tokens WHERE tokenable_id=$CREATED_USER_ID AND tokenable_type LIKE '%User%';" >/dev/null
            psql_q "DELETE FROM sessions WHERE user_id=$CREATED_USER_ID;" >/dev/null
            psql_q "DELETE FROM users    WHERE id=$CREATED_USER_ID;"      >/dev/null
        fi
    fi
    rm -rf "$TMP" 2>/dev/null
}
trap cleanup EXIT INT TERM

# ---------------------------------------------------------------------------
# Run
# ---------------------------------------------------------------------------
preflight

for ck in "${CHECKS[@]}"; do
    case "$ck" in
        normal)              check_normal ;;
        idempotency)         check_idempotency ;;
        skip-log)            check_skip_log ;;
        pretoken-retry)      check_pretoken_retry ;;
        first-chunk-timeout) check_first_chunk_timeout ;;
        worker-restart)      check_worker_restart ;;
        *) say "${Y}unknown check '$ck' — known: ${ALL_CHECKS[*]}${Z}" ;;
    esac
done

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
printf '\n%s━━ SUMMARY%s\n' "$B" "$Z"
for row in "${RESULTS[@]}"; do
    nm="${row%%|*}"; rest="${row#*|}"; verdict="${rest%%|*}"; obs="${rest#*|}"
    case "$verdict" in
        PASS) c="$G" ;; FAIL) c="$R" ;; *) c="$Y" ;;
    esac
    printf '   %s%-6s%s %-20s %s\n' "$c" "$verdict" "$Z" "$nm" "$obs"
done
printf '\n'
if [ "$ANY_FAIL" = 1 ]; then say "${R}One or more checks FAILED.${Z}"; exit 1; fi
say "${G}All selected checks passed.${Z}"
exit 0
