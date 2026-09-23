#!/usr/bin/env bash
# Installs the Clinical Co-Pilot eval gate as a git pre-push hook and proves
# it works. This is the Week 2 regression gate: once installed, `git push`
# runs tests/evals/gate.php inside the openemr container and is refused when
# any rubric regresses against the committed baseline.
#
# Usage:
#   tests/evals/install-hooks.sh              install .git/hooks/pre-push
#   tests/evals/install-hooks.sh --self-test  install, then inject a regression and require a refusal
#   tests/evals/install-hooks.sh --uninstall  remove the hook
#
# The hook is client-side: it blocks pushes on machines where it is
# installed and can be bypassed with `git push --no-verify`. It is not a
# server-side check (this GitLab instance does not allow student pipelines).
set -euo pipefail

root=$(git rev-parse --show-toplevel)
hook="$root/.git/hooks/pre-push"
marker="# clinical-copilot-eval-gate"

if [ "${1:-}" = "--uninstall" ]; then
    if [ -f "$hook" ] && grep -q "$marker" "$hook"; then
        rm -f "$hook" && echo "removed $hook"
    else
        echo "no eval-gate pre-push hook installed"
    fi
    exit 0
fi

if [ -f "$hook" ] && ! grep -q "$marker" "$hook"; then
    echo "install-hooks: $hook exists and was not written by this script; refusing to overwrite it." >&2
    echo "  Add this line to it instead:  \"\$(git rev-parse --show-toplevel)/tests/evals/gate.sh\" pre-push" >&2
    exit 1
fi

mkdir -p "$(dirname "$hook")"
cat > "$hook" <<'EOF'
#!/usr/bin/env bash
# clinical-copilot-eval-gate
# Installed by tests/evals/install-hooks.sh. Runs the eval gate before every
# push; a rubric regression against tests/evals/baseline.json refuses the push.
# COPILOT_GATE_LIVE=1 git push   also runs the live (API-backed) cases.
exec "$(git rev-parse --show-toplevel)/tests/evals/gate.sh" pre-push
EOF
chmod +x "$hook" "$root/tests/evals/gate.sh"
echo "installed $hook"

if [ "${1:-}" = "--self-test" ]; then
    echo "self-test: running the gate through the installed hook path with an injected regression"
    if "$root/tests/evals/gate.sh" self-test; then
        echo "self-test: OK, the gate refuses a regression"
    else
        echo "self-test: FAILED, the gate did not refuse the injected regression" >&2
        exit 1
    fi
fi
