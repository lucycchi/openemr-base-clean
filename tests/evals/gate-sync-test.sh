#!/usr/bin/env bash
# Proves the sidecar sync in tests/evals/gate.sh (the step that copies the
# checkout's Python into the running copilot-sidecar before pytest and the
# golden cases run). Three cases:
#
#   1. checkout and container already match   -> nothing is copied
#   2. a sidecar module gains a line          -> it is copied, the container
#                                                holds the new line, hashes match
#   3. pyproject.toml changes (dependencies)  -> the gate refuses and names the
#                                                image rebuild
#
# It edits files in the checkout and restarts the sidecar, so it runs only
# inside an openemr-cmd managed worktree (a directory named openemr-wt-<slug>),
# never in the primary clone. Every edit is reverted on exit, and the sidecar
# is synced back to the unmodified checkout.
#
# Usage (from the worktree): tests/evals/gate-sync-test.sh
#   GATE_CONTAINER=<openemr container> selects the stack, as for gate.sh.
# Exit 0 when all three cases behave; 1 otherwise.
set -euo pipefail

root=$(git rev-parse --show-toplevel)
case "$root" in
    */openemr-wt-*) ;;
    *) echo "gate-sync-test: runs only inside an openemr-cmd managed worktree (openemr-wt-<slug>); this is $root" >&2; exit 2 ;;
esac

gate="$root/tests/evals/gate.sh"
src="$root/interface/modules/custom_modules/oe-module-clinical-copilot/sidecar"
module="$src/copilot_sidecar/__init__.py"
marker="gate-sync-test $$"

restore() {
    git -C "$root" checkout -- "$module" "$src/pyproject.toml"
    # Copy the unmodified files back so the stack is left as the checkout has it.
    "$gate" sync >/dev/null 2>&1 || echo "gate-sync-test: WARNING could not re-sync the sidecar after the test" >&2
}
trap restore EXIT

fail() { echo "gate-sync-test: FAIL: $1" >&2; exit 1; }

# The sidecar container of the stack gate.sh picks (same project label lookup).
out=$("$gate" sync 2>&1) || fail "the starting sync refused: $out"
project=$(printf '%s\n' "$out" | sed -n "s/^gate: stack '\([^']*\)'.*/\1/p")
sidecar=$(docker ps --filter "label=com.docker.compose.project=$project" \
    --filter "label=com.docker.compose.service=copilot-sidecar" --format '{{.Names}}' | head -1)
[ -n "$sidecar" ] || fail "no copilot-sidecar container for stack '$project'"

echo "case 1: checkout and container match, nothing is copied"
out=$("$gate" sync 2>&1) || fail "case 1 refused: $out"
case "$out" in *copying*) fail "case 1 copied although nothing changed: $out" ;; esac

echo "case 2: an edited sidecar module is copied in"
printf '# %s\n' "$marker" >>"$module"
out=$("$gate" sync 2>&1) || fail "case 2 refused: $out"
case "$out" in *"now runs the checkout's code"*) ;; *) fail "case 2 did not report the copy: $out" ;; esac
docker exec "$sidecar" grep -q "$marker" /app/copilot_sidecar/__init__.py || fail "case 2: the container does not hold the edited module"
git -C "$root" checkout -- "$module"

echo "case 3: a dependency change refuses and names the rebuild"
printf '# %s\n' "$marker" >>"$src/pyproject.toml"
if out=$("$gate" sync 2>&1); then
    fail "case 3 passed although pyproject.toml changed: $out"
fi
case "$out" in *"dependencies changed"*"docker compose build copilot-sidecar"*) ;; *) fail "case 3 refused for the wrong reason: $out" ;; esac

echo "gate-sync-test: OK (3 cases)"
