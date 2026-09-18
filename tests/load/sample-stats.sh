#!/usr/bin/env bash
# Samples container CPU/memory and host load every INTERVAL seconds for
# DURATION seconds and prints CSV to stdout. Read-only: docker stats and
# /proc. Run on the host that runs the stack (locally, or via ssh for the
# droplet — the runner pipes the output back to tests/load/results/).
#
#   sample-stats.sh <duration-seconds> [interval-seconds]
set -euo pipefail
DURATION=${1:?duration seconds}
INTERVAL=${2:-2}
echo "ts,container,cpu_pct,mem_used_mib,mem_limit_mib,mem_pct,load1"
end=$(( $(date +%s) + DURATION ))
# One CSV row per matching container per tick.
while [ "$(date +%s)" -lt "$end" ]; do
  ts=$(date +%s)
  load1=$(cut -d' ' -f1 /proc/loadavg)
  docker stats --no-stream --format '{{.Name}},{{.CPUPerc}},{{.MemUsage}},{{.MemPerc}}' \
    | grep -Ei 'openemr|mysql|mariadb' \
    | while IFS=, read -r name cpu mem mempct; do
        cpu=${cpu%\%}; mempct=${mempct%\%}
        used=$(echo "$mem" | awk '{print $1}'); limit=$(echo "$mem" | awk '{print $3}')
        # normalise GiB/MiB to MiB
        tomib() { case "$1" in *GiB) echo "${1%GiB} * 1024" | bc ;; *MiB) echo "${1%MiB}" ;; *KiB) echo "${1%KiB} / 1024" | bc ;; *) echo "$1" ;; esac; }
        echo "$ts,$name,$cpu,$(tomib "$used"),$(tomib "$limit"),$mempct,$load1"
      done
  sleep "$INTERVAL"
done
