#!/usr/bin/env bash
# Starts a GitLab CI pipeline on labs.gauntletai.com with the project bot token.
#
# A student's personal account cannot create pipelines on this instance (every
# push creates none and POST /pipeline returns 403), but the bot user of a
# project access token can. So after a push, the pipeline is started here.
#
# Usage: tests/evals/ci-pipeline.sh [--wait] [branch]     branch pipeline (default: current branch)
#        tests/evals/ci-pipeline.sh [--wait] --mr <iid>   merge request pipeline
#   --wait   poll until the pipeline finishes, print each job, exit 0 only on success
# Env:   GITLAB_CI_BOT_TOKEN (read from .env when not set; project access token,
#        Maintainer, scope api), GITLAB_URL (https://labs.gauntletai.com),
#        GITLAB_PROJECT_ID (1993)
set -euo pipefail

root=$(git rev-parse --show-toplevel)
if [ -z "${GITLAB_CI_BOT_TOKEN:-}" ] && [ -f "$root/.env" ]; then
    GITLAB_CI_BOT_TOKEN=$(grep -E '^GITLAB_CI_BOT_TOKEN=' "$root/.env" | tail -1 | cut -d= -f2- | tr -d '"'"'"' \r')
fi
: "${GITLAB_CI_BOT_TOKEN:?set GITLAB_CI_BOT_TOKEN (a project access token, scope api) in .env}"
api="${GITLAB_URL:-https://labs.gauntletai.com}/api/v4/projects/${GITLAB_PROJECT_ID:-1993}"

wait=0
mr=""
ref=""
while [ $# -gt 0 ]; do
    case "$1" in
        --wait) wait=1 ;;
        --mr) mr=${2:?--mr needs a merge request iid}; shift ;;
        *) ref=$1 ;;
    esac
    shift
done

# The token goes in a header file, not on a command line another user could read in ps.
hdr=$(mktemp)
trap 'rm -f "$hdr"' EXIT
chmod 600 "$hdr"
printf 'PRIVATE-TOKEN: %s\n' "$GITLAB_CI_BOT_TOKEN" > "$hdr"
call() { curl -sS -H @"$hdr" "$@"; }
field() { python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('$1') if isinstance(d, dict) else '')"; }

if [ -n "$mr" ]; then
    created=$(call -X POST "$api/merge_requests/$mr/pipelines")
else
    ref=${ref:-$(git rev-parse --abbrev-ref HEAD)}
    created=$(call -X POST "$api/pipeline?ref=$ref")
fi
id=$(printf '%s' "$created" | field id)
if [ -z "$id" ] || [ "$id" = "None" ]; then
    echo "ci-pipeline: GitLab did not create a pipeline: $(printf '%s' "$created" | field message)" >&2
    exit 1
fi
echo "ci-pipeline: pipeline $id started: $(printf '%s' "$created" | field web_url)"
[ "$wait" = 1 ] || exit 0

status=""
for _ in $(seq 1 180); do
    status=$(call "$api/pipelines/$id" | field status)
    case "$status" in success|failed|canceled|skipped) break ;; esac
    sleep 20
done
call "$api/pipelines/$id/jobs" | python3 -c "
import json, sys
for j in json.load(sys.stdin):
    print(f\"  {j['name']:14} {j['status']:9} {round(j.get('duration') or 0):>4} s  {j['web_url']}\")"
echo "ci-pipeline: pipeline $id: $status"
[ "$status" = success ]
