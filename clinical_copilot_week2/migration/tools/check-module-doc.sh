#!/usr/bin/env bash
# Fails unless a module audit is complete: all seven sections with real
# content, every label filled, every table with at least one data row, a FHIR
# mapping whose rows all have a resource field, a valid status and evidence,
# a List completeness row, and only catalogued bug ids.
set -uo pipefail
f="${1:?usage: check-module-doc.sh MODULE.md}"
[[ -r "$f" ]] || { echo "FAIL: cannot read $f"; exit 1; }
catalog="$(dirname "$f")/../BUGS-MITIGATIONS.md"
[[ -r "$catalog" ]] || { echo "FAIL: cannot read $catalog"; exit 1; }
doc="$(<"$f")"
fail=0
bad() { echo "FAIL: $*"; fail=1; }

titles=("Purpose and lifecycle" "What the user sees" "Controls" "Permission checks" "Data" "Globals" "Problems")
for i in "${!titles[@]}"; do
  n=$((i + 1))
  grep -qxF "## $n. ${titles[$i]}" <<<"$doc" || bad "missing heading '## $n. ${titles[$i]}'"
  body=$(awk -v h="## $n. ${titles[$i]}" '$0 == h {on=1; next} /^## / {on=0} on' <<<"$doc")
  content=$(grep -vE '^[[:space:]]*$|^> Guidance:|^\|[-| ]*\|$' <<<"$body" || true)
  [[ -n "$content" ]] || bad "section $n has no content"
  if [[ $n -ge 2 && $n -le 6 ]]; then
    rows=$(awk '/^\|/ {t++; if (t > 2) r++} END {print r + 0}' <<<"$body")
    [[ "$rows" -ge 1 ]] || bad "section $n table has no data rows"
  fi
done

placeholders=$(grep -nE '^> Guidance:|<name>|<every file|<fixture keys|\bTBD\b|\bTODO\b' <<<"$doc" || true)
[[ -z "$placeholders" ]] || { echo "$placeholders"; bad "template text or placeholders left in"; }

for label in "Source files:" "Test patients used:" "Sort order:" "Filtered out:" "Empty state text:" "Visibility rules:" "Reads:" "Writes:"; do
  line=$(grep -m1 -F "**$label**" <<<"$doc" || true)
  if [[ -z "$line" ]]; then bad "missing label '$label'"; continue; fi
  value=$(sed -E 's/^\*\*[^*]+\*\*[[:space:]]*//' <<<"$line")
  [[ -n "${value// }" ]] || bad "label '$label' is empty (write None if nothing applies)"
done

mapping=$(awk -F'|' '
  function trim(s) { gsub(/^[ \t]+|[ \t]+$/, "", s); return s }
  /^## 5\. Data$/ {on = 1; next}
  /^## / {on = 0}
  on && /^\|/ {
    t++; if (t <= 2) next
    rows++
    field = trim($2); fhir = trim($3); status = trim($4); checked = trim($5)
    if (field == "List completeness") lc = 1
    if (fhir == "") print "mapping row \"" field "\" has no FHIR resource.field"
    if (status != "matches" && status != "differs" && status != "not available") print "mapping row \"" field "\" has status \"" status "\""
    if (checked == "") print "mapping row \"" field "\" has no Checked against"
  }
  END {
    if (rows == 0) print "FHIR mapping table has no rows"
    if (!lc) print "FHIR mapping has no List completeness row"
  }' <<<"$doc")
if [[ -n "$mapping" ]]; then
  while IFS= read -r m; do bad "$m"; done <<<"$mapping"
fi

ids=$(grep -oE 'BM-[0-9]{3}' <<<"$doc" | sort -u || true)
for id in $ids; do
  grep -qE "^\| *$id *\|" "$catalog" || bad "$id is not in BUGS-MITIGATIONS.md"
done

if [[ $fail -eq 0 ]]; then echo "PASS: $f"; fi
exit $fail
