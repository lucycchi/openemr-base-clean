#!/usr/bin/env bash
# Runs the Clinical Co-Pilot eval gate (tests/evals/gate.php) from wherever
# it is invoked: on the host it finds this checkout's openemr container and
# runs there with docker exec; inside the container it runs php directly. This is
# the single entry point the git hooks and .pre-commit-config.yaml use.
#
# Usage: tests/evals/gate.sh [pre-push|pre-commit|self-test] [extra gate.php args]
#   pre-push    deterministic gate; add COPILOT_GATE_LIVE=1 to include live cases
#   pre-commit  same deterministic gate (fast; seconds)
#   self-test   proves the gate refuses an injected regression
# Exit code is gate.php's exit code: 0 pass, 1 refuse. The hook fails closed
# when the container is not running, and prints the command to start it.
set -euo pipefail

stage=${1:-pre-push}
shift || true
extra=("$@")
case "$stage" in
    pre-push) [ "${COPILOT_GATE_LIVE:-}" = "1" ] && extra+=(--live) ;;
    pre-commit) ;;
    self-test) extra+=(--self-test) ;;
    *) echo "gate.sh: unknown stage '$stage'" >&2; exit 2 ;;
esac

# Inside the openemr container (prek routes pre-commit hooks there): run php directly.
if [ -d /var/www/localhost/htdocs/openemr ] && ! command -v docker >/dev/null 2>&1; then
    cd /var/www/localhost/htdocs/openemr
    # OpenEMR's CLI guard refuses root (a manual `openemr-cmd prek run` arrives as
    # root); the harness then runs as the web user, as the commit hooks do.
    if [ "$(id -u)" = "0" ] && id apache >/dev/null 2>&1; then
        exec su -s /bin/sh apache -c "php tests/evals/gate.php $(printf '%q ' "${extra[@]}")"
    fi
    exec php tests/evals/gate.php "${extra[@]}"
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "gate.sh: docker is not installed; the gate runs inside the openemr container of docker/development-easy" >&2
    exit 1
fi
root=$(git rev-parse --show-toplevel)
# Find this checkout's openemr container by its compose labels, so the gate
# tests this checkout's code even when another clone's or worktree's stack is
# also running. Fall back to the only running openemr service (worktree stacks
# are started from a different compose directory).
container=$(docker ps --filter "label=com.docker.compose.project.working_dir=$root/docker/development-easy" \
    --filter "label=com.docker.compose.service=openemr" --format '{{.Names}}' 2>/dev/null | head -1)
if [ -z "$container" ]; then
    candidates=$(docker ps --filter "label=com.docker.compose.service=openemr" --format '{{.Names}}' 2>/dev/null)
    if [ "$(printf '%s\n' "$candidates" | grep -c .)" = "1" ]; then
        container=$candidates
    fi
fi
if [ -z "$container" ]; then
    cat >&2 <<'EOF'
gate.sh: no openemr container for this checkout is running, so the eval gate cannot run. Refusing (fail closed).
  start it:   cd docker/development-easy && docker compose up --detach --wait
  or bypass:  git push --no-verify      (the gate is the Week 2 regression check; do not bypass for real pushes)
EOF
    exit 1
fi
project=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$container")
sidecar=$(docker ps --filter "label=com.docker.compose.project=$project" \
    --filter "label=com.docker.compose.service=copilot-sidecar" --format '{{.Names}}' 2>/dev/null | head -1)
# Runs a shell command in the openemr container (what `openemr-cmd e` does).
in_openemr() { docker exec -i "$container" sh -c "$1"; }

# Sidecar-backed cases (extract / retrieve / route) need copilot-sidecar as well.
if grep -lq '"mode": *"\(extract\|anchor\|retrieve\|route\)"' "$root"/tests/evals/cases/*.json 2>/dev/null \
    && grep -L '"pending": *true' "$root"/tests/evals/cases/*.json 2>/dev/null | xargs grep -lq '"mode": *"\(extract\|anchor\|retrieve\|route\)"' 2>/dev/null \
    && [ -z "$sidecar" ]; then
    echo "gate.sh: non-pending sidecar cases exist but the copilot-sidecar container of stack '$project' is not running. Refusing (fail closed). Start it with: cd docker/development-easy && docker compose up --detach --wait" >&2
    exit 1
fi
echo "gate: stack '$project' (container $container)"

# Unit layers first, deterministic and fast: the sidecar's pytest (schemas vs
# contracts, anchoring, supervisor routing, HTTP surface) when its container is
# up, and the module's isolated PHPUnit suite. A failure here refuses the push
# before the golden cases run.
if [ "$stage" != "self-test" ]; then
    log=$(mktemp)
    if [ -n "$sidecar" ]; then
        echo "gate: sidecar pytest ($sidecar)"
        if docker exec "$sidecar" python -m pytest -q -p no:cacheprovider >"$log" 2>&1; then tail -1 "$log"; else tail -25 "$log"; echo "gate: sidecar pytest failed (push refused)" >&2; rm -f "$log"; exit 1; fi
    fi
    echo "gate: case index (every case declares a guards category and a failure mode)"
    if in_openemr "cd /var/www/localhost/htdocs/openemr && php tests/evals/case-index.php --check" >"$log" 2>&1; then :; else cat "$log"; echo "gate: case index check failed (push refused)" >&2; rm -f "$log"; exit 1; fi
    echo "gate: module isolated PHPUnit"
    # The exit code of phpunit must survive: no pipes inside the container command.
    if in_openemr "cd /var/www/localhost/htdocs/openemr && vendor/bin/phpunit -c phpunit-isolated.xml --filter ClinicalCopilot --no-coverage" >"$log" 2>&1; then grep -E "^OK|^Tests:" "$log" | tail -1; else tail -25 "$log"; echo "gate: module PHPUnit failed (push refused)" >&2; rm -f "$log"; exit 1; fi
    rm -f "$log"
fi

args=""
for a in "${extra[@]}"; do args+=" $(printf '%q' "$a")"; done
exec docker exec -i "$container" sh -c "su -s /bin/sh apache -c 'cd /var/www/localhost/htdocs/openemr && php tests/evals/gate.php$args'"
