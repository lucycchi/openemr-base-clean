#!/usr/bin/env bash
# Manual stand-in for the deploy-vps job in .gitlab-ci.yml (GitLab CI is not
# available to students on labs.gauntletai.com). Same steps: push the branch
# to GitLab, recreate the openemr container on the VPS (it re-clones the
# branch from GitLab at start), then poll readiness and the module's health.
#
# Usage: docker/vps/deploy.sh [--no-push]
# Env:   DEPLOY_SSH=do-openemr  DEPLOY_DOMAIN=146-190-139-37.sslip.io
#        DEPLOY_DIR=~/openemr   DEPLOY_BRANCH=audit  GITLAB_REMOTE=gitlab
set -euo pipefail

DEPLOY_SSH=${DEPLOY_SSH:-do-openemr}
DEPLOY_DOMAIN=${DEPLOY_DOMAIN:-146-190-139-37.sslip.io}
DEPLOY_DIR=${DEPLOY_DIR:-'~/openemr'}
DEPLOY_BRANCH=${DEPLOY_BRANCH:-pdf_reader}
GITLAB_REMOTE=${GITLAB_REMOTE:-gitlab}
MODULE_DIR=interface/modules/custom_modules/oe-module-clinical-copilot
push=1
[ "${1:-}" = "--no-push" ] && push=0

cd "$(git rev-parse --show-toplevel)"

if [ "$(git rev-parse --abbrev-ref HEAD)" != "$DEPLOY_BRANCH" ]; then
    echo "on $(git rev-parse --abbrev-ref HEAD), expected $DEPLOY_BRANCH" >&2
    exit 1
fi
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "uncommitted changes will NOT be deployed (the VPS clones from GitLab):" >&2
    git status --short --untracked-files=no >&2
fi

if [ "$push" = 1 ]; then
    git push "$GITLAB_REMOTE" "$DEPLOY_BRANCH"
fi
remote_sha=$(git ls-remote "$GITLAB_REMOTE" "refs/heads/$DEPLOY_BRANCH" | cut -f1)
echo "deploying $DEPLOY_BRANCH @ ${remote_sha:0:7} from GitLab"

# Week 2: the sidecar is built on the VPS from the module's sidecar/ directory
# and its contracts; both are copied here because the VPS holds no checkout.
# The compose file itself is copied too so a changed service definition lands.
rsync -az --delete "$MODULE_DIR/sidecar/" "$DEPLOY_SSH:$DEPLOY_DIR/copilot-sidecar/"
rsync -az --delete "$MODULE_DIR/contracts/" "$DEPLOY_SSH:$DEPLOY_DIR/copilot-contracts/"
scp -q docker/vps/docker-compose.yml "$DEPLOY_SSH:$DEPLOY_DIR/docker-compose.yml"
# Module schema upgrades: plain idempotent SQL (CREATE TABLE IF NOT EXISTS), the
# #If/#EndIf lines stripped, applied after the stack is up. install.sql only
# runs on first enable, so this is the deploy-time equivalent of Module
# Manager > Upgrade for a stack that was installed at an older version.
mkdir -p /tmp/copilot-deploy
for f in "$MODULE_DIR"/sql/*_upgrade.sql; do grep -v '^#' "$f"; done > /tmp/copilot-deploy/module-upgrades.sql
scp -q /tmp/copilot-deploy/module-upgrades.sql "$DEPLOY_SSH:$DEPLOY_DIR/copilot-module-upgrades.sql"

# shellcheck disable=SC2029 # DEPLOY_DIR is meant to expand on the remote side
ssh "$DEPLOY_SSH" "set -e
    cd $DEPLOY_DIR
    grep -q '^FLEX_REPOSITORY=.*labs.gauntletai.com' .env || { echo 'FLEX_REPOSITORY in .env does not point at GitLab'; exit 1; }
    grep -q '^FLEX_REPOSITORY_BRANCH=$DEPLOY_BRANCH' .env || { sed -i '/^FLEX_REPOSITORY_BRANCH=/d' .env; echo 'FLEX_REPOSITORY_BRANCH=$DEPLOY_BRANCH' >> .env; echo 'set FLEX_REPOSITORY_BRANCH=$DEPLOY_BRANCH in .env'; }
    docker compose build --quiet copilot-sidecar
    docker compose up -d --force-recreate openemr copilot-sidecar
    docker compose ps --format '{{.Name}} {{.Status}}'"

echo "waiting for https://$DEPLOY_DOMAIN/meta/health/readyz (up to 20 min)"
for i in $(seq 1 80); do
    if curl -fsk --max-time 10 "https://$DEPLOY_DOMAIN/meta/health/readyz" > /dev/null 2>&1; then
        echo "ready after $((i * 15))s"
        break
    fi
    [ "$i" = 80 ] && { echo "not ready after 20 min; check: ssh $DEPLOY_SSH 'cd $DEPLOY_DIR && docker compose logs --tail 100 openemr'" >&2; exit 1; }
    sleep 15
done

# Apply module schema upgrades now that mysql and the app are up (idempotent).
# shellcheck disable=SC2029
ssh "$DEPLOY_SSH" "cd $DEPLOY_DIR && docker compose exec -T mysql sh -c 'mariadb -uroot -p\"\$MYSQL_ROOT_PASSWORD\" openemr' < copilot-module-upgrades.sql"
echo "module schema upgrades applied"
curl -fsSk "https://$DEPLOY_DOMAIN/$MODULE_DIR/public/health.php"; echo
curl -fsSk "https://$DEPLOY_DOMAIN/$MODULE_DIR/public/ready.php"; echo
echo "deployed ${remote_sha:0:7}"
