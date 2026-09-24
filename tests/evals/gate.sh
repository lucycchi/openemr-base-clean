#!/usr/bin/env bash
# Runs the Clinical Co-Pilot eval gate (tests/evals/gate.php) from wherever
# it is invoked: on the host it finds this checkout's openemr container and
# runs there with docker exec; inside the container it runs php directly. This is
# the single entry point the git hooks and .pre-commit-config.yaml use.
#
# Usage: tests/evals/gate.sh [pre-push|pre-commit|self-test|sync] [extra gate.php args]
#   pre-push    deterministic gate; add COPILOT_GATE_LIVE=1 to include live cases
#   pre-commit  same deterministic gate (fast; seconds)
#   self-test   proves the gate refuses an injected regression
#   sync        only brings the sidecar in line with the checkout, then stops
#               (tests/evals/gate-sync-test.sh uses it)
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
    sync) ;;
    *) echo "gate.sh: unknown stage '$stage'" >&2; exit 2 ;;
esac

# Inside the openemr container (prek routes pre-commit hooks there): run php directly.
if [ -d /var/www/localhost/htdocs/openemr ] && ! command -v docker >/dev/null 2>&1; then
    if [ "$stage" = "sync" ]; then
        echo "gate.sh: sync needs docker on the host; nothing to do inside the container"
        exit 0
    fi
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
# are started from a different compose directory). GATE_CONTAINER names the
# openemr container explicitly (the mutant matrix uses it for its worktree).
container=${GATE_CONTAINER:-}
if [ -z "$container" ]; then
    container=$(docker ps --filter "label=com.docker.compose.project.working_dir=$root/docker/development-easy" \
        --filter "label=com.docker.compose.service=openemr" --format '{{.Names}}' 2>/dev/null | head -1)
fi
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

# The sidecar's code is baked into its image (no bind mount), so a Python edit
# is invisible to the running sidecar until it is copied in. Without this step
# pytest and the golden cases would test the old code and a Python regression
# would pass the push. The package is installed in editable mode (pip -e), so
# /app/copilot_sidecar is what runs: copy the checkout's directories in,
# restart, and check that the container now holds exactly the same files.
# docker cp merges, so a file deleted or renamed in the checkout stays in the
# container; the second hash check then refuses and names the rebuild.
sidecar_src="$root/interface/modules/custom_modules/oe-module-clinical-copilot/sidecar"
sidecar_dirs="copilot_sidecar tests tools corpus"
# One hash over every file in those directories (sorted, caches excluded), computed the same way on both sides.
list_hash='find '"$sidecar_dirs"' -type f ! -path "*/__pycache__/*" ! -name "*.pyc" -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -d" " -f1'
sidecar_hash_host() { (cd "$sidecar_src" && sh -c "$list_hash"); }
sidecar_hash_container() { docker exec "$sidecar" sh -c "cd /app && $list_hash"; }
sidecar_healthy() {
    docker exec "$sidecar" python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=2).status == 200 else 1)" >/dev/null 2>&1
}
if [ -n "$sidecar" ] && [ -d "$sidecar_src" ]; then
    rebuild_hint="rebuild it: cd docker/development-easy && docker compose build copilot-sidecar && docker compose up --detach copilot-sidecar"
    if [ "$(sha256sum <"$sidecar_src/pyproject.toml")" != "$(docker exec "$sidecar" sh -c 'sha256sum </app/pyproject.toml')" ]; then
        echo "gate: sidecar dependencies changed (pyproject.toml differs from the running image); $rebuild_hint. Refusing (fail closed)." >&2
        exit 1
    fi
    if [ "$(sidecar_hash_host)" != "$(sidecar_hash_container)" ]; then
        echo "gate: sidecar code differs from the checkout; copying it into $sidecar and restarting"
        for d in $sidecar_dirs; do
            docker cp "$sidecar_src/$d" "$sidecar:/app/" >/dev/null
        done
        docker restart "$sidecar" >/dev/null
        for _ in $(seq 1 60); do sidecar_healthy && break; sleep 1; done
        if ! sidecar_healthy; then
            echo "gate: sidecar did not answer /health within 60 s after the copy. Refusing (fail closed)." >&2
            exit 1
        fi
        if [ "$(sidecar_hash_host)" != "$(sidecar_hash_container)" ]; then
            echo "gate: sidecar still differs from the checkout after the copy (a file was deleted or renamed; docker cp only adds); $rebuild_hint. Refusing (fail closed)." >&2
            exit 1
        fi
        echo "gate: sidecar now runs the checkout's code"
    fi
fi
if [ "$stage" = "sync" ]; then
    echo "gate: sync done"
    exit 0
fi

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
