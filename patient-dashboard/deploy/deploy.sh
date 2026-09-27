#!/usr/bin/env bash
# Deploys the patient dashboard to the droplet as its own compose project (~/patient-dashboard).
# The droplet holds no checkout: the source is copied, built there, and started.
# Usage: patient-dashboard/deploy/deploy.sh
# Env:   DEPLOY_SSH=do-openemr  DEPLOY_DIR=~/patient-dashboard
#        DASHBOARD_URL=https://dashboard.146-190-139-37.sslip.io
#
# Runs on a developer's machine and acts on the droplet (the cloud server that also runs OpenEMR) over SSH.
# In plain terms, the steps are:
#   1. Check the droplet already has the dashboard's secret settings (.env) and the names client's private
#      key; these are placed there by hand and never copied from here.
#   2. Copy the source code across (leaving out build output, tests and secrets).
#   3. On the droplet, build the Docker image (see ../Dockerfile) and restart the dashboard container.
#   4. Poll the dashboard's /healthz address until it answers, or give up after 200 seconds.
# It stops with a non-zero exit code (a failure signal) at the first step that fails.
#
# `set -euo pipefail`: stop at the first failed command, and treat an unset variable as an error.
set -euo pipefail

# Settings, each with a default: ${NAME:-default} means "use $NAME if set, otherwise the default".
DEPLOY_SSH=${DEPLOY_SSH:-do-openemr}
DEPLOY_DIR=${DEPLOY_DIR:-'~/patient-dashboard'}
DASHBOARD_URL=${DASHBOARD_URL:-https://dashboard.146-190-139-37.sslip.io}

# Work from the patient-dashboard folder (the parent of this script's folder), whatever folder it was run from.
cd "$(dirname "$0")/.."

# Step 1: make the folders on the droplet and check the two secret files are there; if not, explain and stop.
# (`||` runs the part after it only if the part before it failed.)
# shellcheck disable=SC2029 # DEPLOY_DIR is meant to expand on the remote side
ssh "$DEPLOY_SSH" "mkdir -p $DEPLOY_DIR/src $DEPLOY_DIR/names-key && test -f $DEPLOY_DIR/.env && test -f $DEPLOY_DIR/names-key/names-client-key.pem" \
    || { echo "missing $DEPLOY_DIR/.env or names-key/names-client-key.pem on $DEPLOY_SSH; see deploy/README.md" >&2; exit 1; }

# Step 2: copy the source to the droplet. rsync sends only what changed; --delete removes files on the droplet
# that no longer exist here. The compose file is copied separately, next to the source folder.
rsync -az --delete \
    --exclude node_modules --exclude dist --exclude test-results --exclude playwright-report \
    --exclude .env --exclude '.env.*' --exclude certs --exclude tests --exclude deploy --exclude data \
    ./ "$DEPLOY_SSH:$DEPLOY_DIR/src/"
scp -q deploy/docker-compose.yml "$DEPLOY_SSH:$DEPLOY_DIR/docker-compose.yml"

# Step 3: on the droplet, build the image, replace the running container with a new one, and print its status.
# shellcheck disable=SC2029
ssh "$DEPLOY_SSH" "set -e
    cd $DEPLOY_DIR
    docker compose build --quiet dashboard
    docker compose up -d --force-recreate dashboard
    docker compose ps --format '{{.Name}} {{.Status}}'"

# Step 4: ask the public address every 5 seconds, up to 40 times, until the health check answers.
echo "waiting for $DASHBOARD_URL/healthz"
for i in $(seq 1 40); do
    if curl -fsS --max-time 10 "$DASHBOARD_URL/healthz" > /dev/null 2>&1; then
        echo "healthy after $((i * 5))s"
        exit 0
    fi
    sleep 5
done
# Never healthy: say where to look (the container's recent log) and exit with a failure.
echo "not healthy after 200 s; check: ssh $DEPLOY_SSH 'cd $DEPLOY_DIR && docker compose logs --tail 100 dashboard'" >&2
exit 1
