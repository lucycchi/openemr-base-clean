#!/usr/bin/env bash
# Partial coverage check. Fails if a template or fragment that demographics.php,
# stats.php or a loaded fragment renders directly is missing from INVENTORY.md,
# or if a required scope row is missing. It cannot see transitive includes or
# event listeners, so the tree still needs a careful human read.
set -uo pipefail
root="$(git rev-parse --show-toplevel)" || exit 1
dir="$root/interface/patient_file/summary"
inv="$root/clinical_copilot_week2/migration/INVENTORY.md"
[[ -r "$inv" ]] || { echo "FAIL: cannot read $inv"; exit 1; }
fail=0

fragments=$(grep -oE 'placeHtml\("[^"]+"' "$dir/demographics.php" | sed -E 's/placeHtml\("//; s/"$//' | sort -u)
sources=("$dir/demographics.php" "$dir/stats.php")
for fr in $fragments; do
  [[ -f "$dir/$fr" ]] && sources+=("$dir/$fr")
done
targets=$( {
  printf '%s\n' $fragments
  grep -hoE "render\(['\"]patient/[^'\"]+" "${sources[@]}" | sed -E "s/render\(['\"]//"
  grep -hoE "TEMPLATE_FILE = ['\"]patient/[^'\"]+" "$root"/src/Patient/Cards/*.php | sed -E "s/.*['\"]//"
  echo "controllers/C_Prescription.class.php"
  echo "templates/prescription/general_fragment.html"
} | sort -u )

count=0
for t in $targets; do
  count=$((count + 1))
  grep -qF -- "$t" "$inv" || { echo "MISSING: $t"; fail=1; }
done
for word in getHiddenDashboardCards hide_dashboard_cards aclCheckCore patient_data_template.php patient_data_view_model.js erx_enable; do
  grep -qF -- "$word" "$inv" || { echo "MISSING: $word"; fail=1; }
done
for m in "Header" "Allergies" "Problem List" "Medications" "Prescriptions" "Care Team" "Vitals" "Labs" "Notes" "Immunizations" "Appointments" "Encounters"; do
  grep -qE "^\| *$m *\|" "$inv" || { echo "MISSING scope row: $m"; fail=1; }
done
if [[ $fail -eq 0 ]]; then echo "PASS: $count targets listed (partial coverage check)"; fi
exit $fail
