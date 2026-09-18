#!/usr/bin/env bash
# Runs the load matrix (10 and 50 virtual users × brief / mixed / ask) against
# BASE_URL, sampling CPU/memory on the target host for the whole run, and
# writes everything to tests/load/results/<stamp>-<label>.*
#
# Required: k6 on PATH. For STATS=ssh the target host must be reachable as
# SSH_HOST with docker on it (read-only sampling; nothing is written there).
#
#   BASE_URL=https://146-190-139-37.sslip.io LOGIN_PASS=... STATS=ssh SSH_HOST=do-openemr tests/load/run-baselines.sh
#   BASE_URL=http://localhost:8300 STATS=local tests/load/run-baselines.sh          # dev stack
#   VUS_LIST="10" SCENARIOS="brief" DURATION=30s ... tests/load/run-baselines.sh   # smaller matrix
set -euo pipefail
cd "$(dirname "$0")/../.."

BASE_URL=${BASE_URL:-http://localhost:8300}
LOGIN_USER=${LOGIN_USER:-admin}
LOGIN_PASS=${LOGIN_PASS:-pass}
DURATION=${DURATION:-2m}
VUS_LIST=${VUS_LIST:-"10 50"}
SCENARIOS=${SCENARIOS:-"brief mixed ask"}
STATS=${STATS:-none}          # none | local | ssh
SSH_HOST=${SSH_HOST:-do-openemr}
RESULTS=${RESULTS:-tests/load/results}
STAMP=${STAMP:-$(date -u +%Y%m%dT%H%MZ)}
K6=${K6:-k6}

mkdir -p "$RESULTS"
# "2m" -> 120, "30s" -> 30: the stats sampler needs the duration in seconds.
dur_s() { local d=$1; case "$d" in *m) echo $(( ${d%m} * 60 )) ;; *s) echo "${d%s}" ;; *) echo "$d" ;; esac; }
SECS=$(dur_s "$DURATION")

echo "target=$BASE_URL commit=$(git rev-parse --short HEAD) stamp=$STAMP duration=$DURATION vus=[$VUS_LIST] scenarios=[$SCENARIOS] stats=$STATS"

for vus in $VUS_LIST; do
  for sc in $SCENARIOS; do
    label="$STAMP-${vus}vu-$sc"
    echo "=== $label ==="
    # Start the CPU/memory sampler in the background for the run's duration
    # (+15s tail), locally or over ssh, writing CSV next to the k6 results.
    sampler_pid=""
    case "$STATS" in
      local) bash tests/load/sample-stats.sh $(( SECS + 15 )) 2 > "$RESULTS/$label-stats.csv" & sampler_pid=$! ;;
      ssh)   ssh "$SSH_HOST" 'bash -s' $(( SECS + 15 )) 2 < tests/load/sample-stats.sh > "$RESULTS/$label-stats.csv" & sampler_pid=$! ;;
    esac
    # `|| true`: a failed threshold or crashed run must not abort the matrix.
    "$K6" run --quiet \
      -e BASE_URL="$BASE_URL" -e LOGIN_USER="$LOGIN_USER" -e LOGIN_PASS="$LOGIN_PASS" \
      -e SCENARIO="$sc" -e VUS="$vus" -e DURATION="$DURATION" -e LABEL="$label" -e RESULTS_DIR="$RESULTS" \
      tests/load/copilot.js || true
    if [ -n "$sampler_pid" ]; then wait "$sampler_pid" || true; fi
    # 30 s gap so the next level starts from a quiet box and Langfuse windows do not overlap.
    sleep 30
  done
done
echo "done: $RESULTS/$STAMP-*"
