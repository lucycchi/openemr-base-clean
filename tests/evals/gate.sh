#!/usr/bin/env bash
# Runs the Clinical Co-Pilot eval gate (tests/evals/gate.php) from wherever
# it is invoked: on the host it routes into the running openemr container
# through openemr-cmd; inside the container it runs php directly. This is
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
    exec php tests/evals/gate.php "${extra[@]}"
fi

if ! command -v openemr-cmd >/dev/null 2>&1; then
    echo "gate.sh: openemr-cmd is not installed; see CONTRIBUTING.md (the gate runs inside the openemr container)" >&2
    exit 1
fi
if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qE -- '-openemr-1$'; then
    cat >&2 <<'EOF'
gate.sh: the openemr container is not running, so the eval gate cannot run. Refusing (fail closed).
  start it:   openemr-cmd up            (from docker/development-easy, or the worktree's stack)
  or bypass:  git push --no-verify      (the gate is the Week 2 regression check; do not bypass for real pushes)
EOF
    exit 1
fi
# Sidecar-backed cases (extract / retrieve / route) need copilot-sidecar as well.
if grep -lq '"mode": *"\(extract\|anchor\|retrieve\|route\)"' "$(git rev-parse --show-toplevel)"/tests/evals/cases/*.json 2>/dev/null \
    && grep -L '"pending": *true' "$(git rev-parse --show-toplevel)"/tests/evals/cases/*.json 2>/dev/null | xargs grep -lq '"mode": *"\(extract\|anchor\|retrieve\|route\)"' 2>/dev/null \
    && ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qE -- 'copilot-sidecar'; then
    echo "gate.sh: non-pending sidecar cases exist but the copilot-sidecar container is not running. Refusing (fail closed). Start it with: openemr-cmd up" >&2
    exit 1
fi

# Unit layers first, deterministic and fast: the sidecar's pytest (schemas vs
# contracts, anchoring, supervisor routing, HTTP surface) when its container is
# up, and the module's isolated PHPUnit suite. A failure here refuses the push
# before the golden cases run.
if [ "$stage" != "self-test" ]; then
    sidecar=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -E -- 'copilot-sidecar' | head -1)
    log=$(mktemp)
    if [ -n "$sidecar" ]; then
        echo "gate: sidecar pytest ($sidecar)"
        if docker exec "$sidecar" python -m pytest -q -p no:cacheprovider >"$log" 2>&1; then tail -1 "$log"; else tail -25 "$log"; echo "gate: sidecar pytest failed (push refused)" >&2; rm -f "$log"; exit 1; fi
    fi
    echo "gate: case index (every case declares a guards category and a failure mode)"
    if openemr-cmd e "cd /var/www/localhost/htdocs/openemr && php tests/evals/case-index.php --check" >"$log" 2>&1; then :; else cat "$log"; echo "gate: case index check failed (push refused)" >&2; rm -f "$log"; exit 1; fi
    echo "gate: module isolated PHPUnit"
    # The exit code of phpunit must survive: no pipes inside the container command.
    if openemr-cmd e "cd /var/www/localhost/htdocs/openemr && vendor/bin/phpunit -c phpunit-isolated.xml --filter ClinicalCopilot --no-coverage" >"$log" 2>&1; then grep -E "^OK|^Tests:" "$log" | tail -1; else tail -25 "$log"; echo "gate: module PHPUnit failed (push refused)" >&2; rm -f "$log"; exit 1; fi
    rm -f "$log"
fi

args=""
for a in "${extra[@]}"; do args+=" $(printf '%q' "$a")"; done
exec openemr-cmd e "su -s /bin/sh apache -c 'cd /var/www/localhost/htdocs/openemr && php tests/evals/gate.php$args'"
