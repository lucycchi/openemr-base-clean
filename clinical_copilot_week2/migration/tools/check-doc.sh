#!/usr/bin/env bash
# Fails unless FILE has every required heading and no {{FILL}} or TBD
# marker. Writers put {{FILL}} wherever a value is still unknown; angle
# brackets are left alone because templates and code samples use them.
# Usage: check-doc.sh FILE "## Heading" ...
set -uo pipefail
f="${1:?usage: check-doc.sh FILE HEADING...}"
shift
[[ -r "$f" ]] || { echo "FAIL: cannot read $f"; exit 1; }
fail=0
for h in "$@"; do
  grep -qF -- "$h" "$f" || { echo "MISSING: $h"; fail=1; }
done
grep -nE '\{\{FILL\}\}|\bTBD\b' "$f"
case $? in
  0) echo "FAIL: placeholder text above"; fail=1 ;;
  1) ;;
  *) echo "FAIL: grep could not read $f"; fail=1 ;;
esac
if [[ $fail -eq 0 ]]; then echo "PASS: $f"; fi
exit $fail
