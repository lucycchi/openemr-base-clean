#!/usr/bin/env bash
# The kill matrix: plants each small regression in tests/evals/mutants/*.patch
# into the code, runs the real pre-push hook chain (tests/evals/gate.sh
# pre-push, no API key) and records whether the push would have been refused.
# Design and decisions: docs/designs/golden-set-kill-matrix.md.
#
# Verdicts
#   KILLED    the hook refused and named why: a failed pytest or PHPUnit test,
#             PROMPT CHANGED, a rubric row BELOW / REGRESSED, a deterministic
#             case FAILED or MISSING, or "harness produced no results".
#   SURVIVED  the hook printed GATE: PASS: this regression would ship.
#   ERROR     anything else (the patch does not compile, a timeout, a missing
#             container). Never counted as a kill; any ERROR fails the run.
#   STALE     the patch no longer applies (the code moved); fails the run.
#
# Order of a run: refuse unless inside an openemr-cmd managed worktree with no
# model keys in its containers -> gate-sync-test.sh -> control run (must pass)
# -> canaries C1 (comment only, must SURVIVE) and C2 (syntax error, must be
# ERROR) -> every M*.patch -> closing control run (must pass). A canary or a
# control with the wrong verdict aborts the run and writes nothing.
#
# Each patch starts with a header the table is built from:
#   id: M1 / layer: Verifier / mutation: ... / expected: ...
# then an ordinary unified diff (git apply skips the header).
#
# Usage (from the worktree created by
#   openemr-cmd worktree add mutants -b --base <pdf_reader sha> --start):
#   tests/evals/mutants/run.sh               run the matrix, record the run
#   tests/evals/mutants/run.sh --only M13a   one patch, nothing recorded
#   tests/evals/mutants/run.sh --check-stale report whether the last recorded
#                                            run still describes this checkout
# Results: tests/evals/mutants/results.json (a list of runs) and the table in
# EVAL_GATE.md, written here and copied into the primary checkout.
set -euo pipefail

root=$(git rev-parse --show-toplevel)
here="$root/tests/evals/mutants"
results="$here/results.json"
module="interface/modules/custom_modules/oe-module-clinical-copilot"
# Paths whose change makes a recorded run stale (execution inputs only; the
# generated results and table are excluded).
stale_paths=("$module/src" "$module/sidecar" "$module/contracts" "tests/evals" "tests/Tests/Isolated/Modules/ClinicalCopilot")

check_stale() {
    [ -f "$results" ] || { echo "no recorded run"; return 1; }
    local sha
    sha=$(python3 -c "import json,sys; runs=json.load(open(sys.argv[1])); print(runs[-1]['sha'] if runs else '')" "$results")
    [ -n "$sha" ] || { echo "no recorded run"; return 1; }
    local changed
    changed=$(git -C "$root" diff --name-only "$sha" HEAD -- "${stale_paths[@]}" | grep -v '^tests/evals/mutants/results.json$' || true)
    if [ -n "$changed" ]; then
        echo "STALE: the last recorded run ($sha) predates changes to:"
        printf '  %s\n' $changed
        return 1
    fi
    echo "current: the last recorded run ($sha) covers every execution input"
}

only=""
case "${1:-}" in
    --check-stale) check_stale; exit $? ;;
    --only) only=${2:?--only needs a patch id}; ;;
    "") ;;
    *) echo "usage: $0 [--only <id> | --check-stale]" >&2; exit 2 ;;
esac

case "$root" in
    */openemr-wt-*) ;;
    *) echo "run.sh: plants regressions in the code, so it runs only inside an openemr-cmd managed worktree (openemr-wt-<slug>); this is $root" >&2; exit 2 ;;
esac

# The worktree's own containers, found by its compose working directory.
compose_dir="$root/docker/development-easy"
export GATE_CONTAINER=${GATE_CONTAINER:-$(docker ps --filter "label=com.docker.compose.project.working_dir=$compose_dir" \
    --filter "label=com.docker.compose.service=openemr" --format '{{.Names}}' | head -1)}
[ -n "$GATE_CONTAINER" ] || { echo "run.sh: no openemr container for $compose_dir; start the worktree stack (openemr-cmd worktree up <branch>)" >&2; exit 1; }
project=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$GATE_CONTAINER")
sidecar=$(docker ps --filter "label=com.docker.compose.project=$project" --filter "label=com.docker.compose.service=copilot-sidecar" --format '{{.Names}}' | head -1)
[ -n "$sidecar" ] || { echo "run.sh: stack '$project' has no copilot-sidecar running" >&2; exit 1; }

# A plain push has no model key; with one, retrieve cases call Cohere live and
# verdicts would depend on the network (eng review A4).
for c in "$GATE_CONTAINER" "$sidecar"; do
    for k in OPENAI_API_KEY COHERE_API_KEY; do
        if [ -n "$(docker exec "$c" printenv "$k" 2>/dev/null || true)" ]; then
            echo "run.sh: $k is set in $c; the matrix measures the keyless push. Remove it from the worktree's .env and restart the stack." >&2
            exit 1
        fi
    done
done

logs=$(mktemp -d)
applied=""
cleanup() {
    if [ -n "$applied" ]; then
        git -C "$root" apply -R "$applied" 2>/dev/null || git -C "$root" checkout -- "$module" tests/evals
    fi
    # Put the sidecar back on the unmutated code (a no-op when it already is).
    "$root/tests/evals/gate.sh" sync >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

header() { sed -n "s/^$2: //p" "$1" | head -1; }

# Lints every file the patch touches before the gate runs: a parse error would
# otherwise surface as "module PHPUnit failed" and look like a named refusal.
lint_changed() {
    local f
    for f in $(git -C "$root" apply --numstat "$1" | awk '{print $3}'); do
        case "$f" in
            *.php) docker exec "$GATE_CONTAINER" php -l "/var/www/localhost/htdocs/openemr/$f" >/dev/null 2>&1 || return 1 ;;
            *.py) python3 -c "import ast,sys; ast.parse(open(sys.argv[1]).read())" "$root/$f" 2>/dev/null || return 1 ;;
        esac
    done
}

# Runs the hook chain; prints "<verdict>|<stage>|<detail>".
gate_verdict() {
    local log=$1 code=0
    timeout 900 "$root/tests/evals/gate.sh" pre-push >"$log" 2>&1 || code=$?
    if [ "$code" = 0 ] && grep -q '^GATE: PASS' "$log"; then
        echo "SURVIVED|-|GATE: PASS"; return
    fi
    if [ "$code" = 124 ]; then echo "ERROR|timeout|gate ran past 900 s"; return; fi
    local detail
    if grep -q 'PROMPT CHANGED' "$log"; then
        echo "KILLED|prompt lock|$(grep -o 'PROMPT CHANGED: [A-Za-z_]*' "$log" | head -1 || true)"; return
    fi
    if grep -q 'gate: sidecar pytest failed' "$log"; then
        detail=$(grep -Eo '^FAILED [^ ]+' "$log" | head -3 | tr '\n' ' ' || true)
        echo "KILLED|pytest|${detail:-see log}"; return
    fi
    if grep -q 'gate: module PHPUnit failed' "$log"; then
        detail=$(grep -Eo '^[0-9]+\) [^ ]+' "$log" | head -3 | tr '\n' ' ' || true)
        echo "KILLED|PHPUnit|${detail:-see log}"; return
    fi
    if grep -q 'gate: case index check failed' "$log"; then echo "KILLED|case index|see log"; return; fi
    if grep -q 'harness produced no results' "$log"; then echo "KILLED|harness|harness produced no results"; return; fi
    detail=$(grep -E ' (BELOW|REGRESSED) ' "$log" | awk '{$2=$3=$4=""; print}' | tr -s ' ' | head -3 | tr '\n' ';' || true)
    local extra
    extra=$(grep -E '^deterministic (cases FAILED|baseline cases MISSING)' "$log" | head -2 | tr '\n' ';' || true)
    if [ -n "$detail$extra" ]; then echo "KILLED|golden|$detail$extra"; return; fi
    echo "ERROR|unknown|exit $code without a named refusal"
}

# Plants one patch, runs the gate, reverts; sets VERDICT to "<verdict>|<stage>|<detail>".
# Runs in this shell (never inside $(...)) so the EXIT trap sees what is applied.
VERDICT=""
run_patch() {
    local patch=$1 log=$2
    if ! git -C "$root" diff --quiet -- "$module" tests/evals; then VERDICT="ERROR|dirty|the tree was not clean before the patch"; return; fi
    if ! git -C "$root" apply --check "$patch" 2>/dev/null; then VERDICT="STALE|-|the patch no longer applies"; return; fi
    git -C "$root" apply "$patch"
    applied=$patch
    if ! lint_changed "$patch"; then
        VERDICT="ERROR|lint|the patched code does not parse"
    else
        VERDICT=$(gate_verdict "$log")
    fi
    git -C "$root" apply -R "$patch"
    applied=""
}

echo "== sync test"
"$root/tests/evals/gate-sync-test.sh" || { echo "run.sh: gate-sync-test.sh failed; aborting" >&2; exit 1; }

control() {
    local v
    v=$(gate_verdict "$logs/control-$1.log")
    [ "${v%%|*}" = "SURVIVED" ] || { echo "run.sh: $1 control run did not pass ($v); log: $logs/control-$1.log. Aborting, nothing recorded." >&2; exit 1; }
    echo "  control $1: PASS"
}
echo "== control run"
control before

echo "== canaries"
for c in C1 C2; do
    run_patch "$here/$c.patch" "$logs/$c.log"
    v=$VERDICT
    want=$(header "$here/$c.patch" expected)
    [ "${v%%|*}" = "$want" ] || { echo "run.sh: canary $c came back ${v%%|*}, expected $want; the classifier cannot be trusted. Aborting." >&2; exit 1; }
    echo "  $c: ${v%%|*} (as expected)"
done

echo "== mutants"
rows=$(mktemp)
failed=0
for patch in $(ls "$here"/M*.patch | sort -V); do
    id=$(header "$patch" id)
    if [ -n "$only" ] && [ "$id" != "$only" ]; then continue; fi
    run_patch "$patch" "$logs/$id.log"
    IFS='|' read -r verdict stage detail <<<"$VERDICT"
    printf '  %-5s %-8s %-12s %s\n' "$id" "$verdict" "$stage" "$detail"
    case "$verdict" in ERROR|STALE) failed=1 ;; esac
    python3 -c 'import json,sys; print(json.dumps(dict(zip(["id","layer","mutation","expected","verdict","stage","detail"], sys.argv[1:]))))' \
        "$id" "$(header "$patch" layer)" "$(header "$patch" mutation)" "$(header "$patch" expected)" "$verdict" "$stage" "$detail" >>"$rows"
done

echo "== closing control run"
control after

if [ -n "$only" ]; then
    echo "(--only: nothing recorded; logs in $logs)"
    exit $failed
fi

sha=$(git -C "$root" rev-parse HEAD)
python3 - "$results" "$rows" "$sha" "$root" <<'PY'
import datetime, hashlib, json, pathlib, sys
results, rows, sha, root = sys.argv[1:]
root = pathlib.Path(root)
def digest(rel):
    p = root / rel
    return hashlib.sha256(p.read_bytes()).hexdigest() if p.exists() else None
runs = json.loads(pathlib.Path(results).read_text()) if pathlib.Path(results).exists() else []
mutants = [json.loads(l) for l in pathlib.Path(rows).read_text().splitlines() if l.strip()]
runs.append({
    "sha": sha,
    "ran_at": datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    "keys_empty": True,
    "controls": {"before": "PASS", "after": "PASS"},
    "canaries": {"C1": "SURVIVED", "C2": "ERROR"},
    "inputs": {
        "baseline.json": digest("tests/evals/baseline.json"),
        "php prompts.lock.json": digest("tests/Tests/Isolated/Modules/ClinicalCopilot/prompts.lock.json"),
        "sidecar prompts.lock.json": digest("interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/tests/prompts.lock.json"),
    },
    "mutants": mutants,
})
pathlib.Path(results).write_text(json.dumps(runs, indent=2) + "\n")
PY
"$here/table.py" "$results" "$root/EVAL_GATE.md"

# Copy the evidence into the primary checkout, where it is committed (eng review D4).
primary=$(git -C "$root" worktree list --porcelain | awk '/^worktree /{print $2; exit}')
if [ -n "$primary" ] && [ "$primary" != "$root" ]; then
    cp "$results" "$primary/tests/evals/mutants/results.json"
    "$here/table.py" "$primary/tests/evals/mutants/results.json" "$primary/EVAL_GATE.md"
    echo "results copied to $primary (tests/evals/mutants/results.json, EVAL_GATE.md table)"
fi
echo "logs: $logs"
exit $failed
