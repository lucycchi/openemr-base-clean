#!/usr/bin/env bash
# Deploys the patient dashboard to the droplet as its own compose project (~/patient-dashboard).
# The droplet holds no checkout: the source is copied, built there, and started.
# Usage: patient-dashboard/deploy/deploy.sh
# Env:   DEPLOY_SSH=do-openemr  DEPLOY_DIR=~/patient-dashboard
#        DASHBOARD_URL=https://dashboard.146-190-139-37.sslip.io
set -euo pipefail

DEPLOY_SSH=${DEPLOY_SSH:-do-openemr}
DEPLOY_DIR=${DEPLOY_DIR:-'~/patient-dashboard'}
DASHBOARD_URL=${DASHBOARD_URL:-https://dashboard.146-190-139-37.sslip.io}

cd "$(dirname "$0")/.."

# shellcheck disable=SC2029 # DEPLOY_DIR is meant to expand on the remote side
ssh "$DEPLOY_SSH" "mkdir -p $DEPLOY_DIR/src $DEPLOY_DIR/names-key && test -f $DEPLOY_DIR/.env && test -f $DEPLOY_DIR/names-key/names-client-key.pem" \
    || { echo "missing $DEPLOY_DIR/.env or names-key/names-client-key.pem on $DEPLOY_SSH; see deploy/README.md" >&2; exit 1; }

rsync -az --delete \
    --exclude node_modules --exclude dist --exclude test-results --exclude playwright-report \
    --exclude .env --exclude '.env.*' --exclude certs --exclude tests --exclude deploy \
    ./ "$DEPLOY_SSH:$DEPLOY_DIR/src/"
scp -q deploy/docker-compose.yml "$DEPLOY_SSH:$DEPLOY_DIR/docker-compose.yml"

# shellcheck disable=SC2029
ssh "$DEPLOY_SSH" "set -e
    cd $DEPLOY_DIR
    docker compose build --quiet dashboard
    docker compose up -d --force-recreate dashboard
    docker compose ps --format '{{.Name}} {{.Status}}'"

echo "waiting for $DASHBOARD_URL/healthz"
for i in $(seq 1 40); do
    if curl -fsS --max-time 10 "$DASHBOARD_URL/healthz" > /dev/null 2>&1; then
        echo "healthy after $((i * 5))s"
        exit 0
    fi
    sleep 5
done
echo "not healthy after 200 s; check: ssh $DEPLOY_SSH 'cd $DEPLOY_DIR && docker compose logs --tail 100 dashboard'" >&2
exit 1
