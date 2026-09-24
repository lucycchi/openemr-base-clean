#!/usr/bin/env bash
# Runs the eval gate the way GitLab CI does (the eval-gate job in
# .gitlab-ci.yml): brings up a throwaway docker/development-easy stack from
# THIS checkout, runs tests/evals/gate.sh against it, and takes the stack
# down again. The stack must come from the checkout under test: the openemr
# container bind-mounts the source tree, so a developer's running stack
# would test that developer's files, not the pushed commit.
#
# Usage: tests/evals/ci-gate.sh
#   COPILOT_CI_PROJECT  compose project name (default copilot-ci)
#   COPILOT_CI_KEEP=1   leave the stack running afterwards, for debugging
#
# The stack is keyless (the sidecar's .env is optional and a runner checkout
# has none), so only the deterministic cases run; gate.sh fails closed if the
# stack does not come up. Host ports are random (WT_*_PORT=0), so the stack
# never collides with a dev or production stack on the same host.
#
# Dependency volumes (vendor, node_modules, webpack cache, built assets) are
# kept between runs so a pipeline does not reinstall Composer and npm from
# scratch; the database, the site config and the logs are removed, so every
# run starts from a fresh OpenEMR install. run.php creates the module's
# tables on a fresh database (ensureModuleSchema).
set -euo pipefail

root=$(git rev-parse --show-toplevel)
compose_dir="$root/docker/development-easy"
export COMPOSE_PROJECT_NAME=${COPILOT_CI_PROJECT:-copilot-ci}
export WT_HTTP_PORT=0 WT_HTTPS_PORT=0 WT_MYSQL_PORT=0
# Apache in the container adopts this uid, so it can write to the checkout.
HOST_UID=$(id -u)
HOST_GID=$(id -g)
export HOST_UID HOST_GID

dc() { docker compose -f "$compose_dir/docker-compose.yml" -f "$root/tests/evals/ci-compose.yml" "$@"; }

# State that must not carry over between runs; everything else is a cache.
state_volumes=(databasevolume sitesvolume logvolume couchdbvolume)

teardown() {
    if [ "${COPILOT_CI_KEEP:-}" = "1" ]; then
        echo "ci-gate: COPILOT_CI_KEEP=1, leaving stack $COMPOSE_PROJECT_NAME running"
        return
    fi
    dc down --remove-orphans >/dev/null 2>&1 || true
    # The openemr container writes into the bind-mounted checkout as root (a
    # module it installs); hand every file back to this user so the runner can
    # clean the working copy before the next job.
    docker run --rm -v "$root:/w" alpine:3.20 chown -R "$HOST_UID:$HOST_GID" /w >/dev/null 2>&1 || true
    for v in "${state_volumes[@]}"; do
        docker volume rm "${COMPOSE_PROJECT_NAME}_$v" >/dev/null 2>&1 || true
    done
}
trap teardown EXIT

# A previous run that was killed may have left its database behind.
teardown

echo "ci-gate: starting mysql, openemr and copilot-sidecar as project $COMPOSE_PROJECT_NAME"
start=$(date +%s)
if ! dc up --detach --build --wait --wait-timeout 1800 mysql openemr copilot-sidecar; then
    echo "ci-gate: the stack did not become healthy; last openemr log lines:" >&2
    dc logs --tail 80 openemr >&2 || true
    exit 1
fi
echo "ci-gate: stack healthy after $(( $(date +%s) - start )) s"

GATE_CONTAINER=$(docker inspect -f '{{.Name}}' "$(dc ps -q openemr)" | sed 's#^/##')
export GATE_CONTAINER
"$root/tests/evals/gate.sh" pre-push
