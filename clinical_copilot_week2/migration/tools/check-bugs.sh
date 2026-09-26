#!/usr/bin/env bash
# Fails unless every data row of the Catalogue table is well formed, ids are
# unique, and the Summary counts equal the rows per severity. No row is
# skipped for looking odd: a malformed row is a failure.
set -uo pipefail
f="${1:-$(dirname "$0")/../BUGS-MITIGATIONS.md}"
[[ -r "$f" ]] || { echo "FAIL: cannot read $f"; exit 1; }
awk -F'|' -v min="${MIN_ROWS:-1}" '
  function trim(s) { gsub(/^[ \t]+|[ \t]+$/, "", s); return s }
  function fail(msg) { print "FAIL: line " NR ": " msg; bad = 1 }
  /^## Summary/ {sec = "summary"; next}
  /^## Catalogue/ {sec = "cat"; next}
  /^## / {sec = ""}
  sec == "summary" && /^\|/ {
    s = trim($2); if (s ~ /^(Critical|High|Medium|Low)$/) { summary[s] = trim($3); seenSummary[s] = 1 }
    next
  }
  sec == "cat" && /^\|/ {
    t++; if (t <= 2) next
    rows++
    if (NF != 9) { fail("expected 7 columns, found " (NF - 2)); next }
    id = trim($2); sev = trim($3); cat = trim($4); mod = trim($5); cite = trim($6); act = trim($7); mit = trim($8)
    if (id !~ /^BM-[0-9][0-9][0-9]$/) fail("bad id \"" id "\"")
    if (id in seen) fail("duplicate id " id); seen[id] = 1
    if (sev !~ /^(Critical|High|Medium|Low)$/) fail(id " bad severity \"" sev "\"")
    else count[sev]++
    if (cat == "" || mod == "" || cite == "") fail(id " has an empty category, module or citation")
    if (act != "keep for parity" && act != "fix in the new app" && act != "out of scope") fail(id " bad port action \"" act "\"")
    if ((act == "fix in the new app" || act == "keep for parity") && (mit == "" || mit == "-")) fail(id " needs a mitigation or reason")
  }
  END {
    if (rows + 0 < min) { print "FAIL: " rows + 0 " catalogue rows, expected at least " min; bad = 1 }
    split("Critical High Medium Low", levels, " ")
    for (i = 1; i <= 4; i++) {
      l = levels[i]
      if (!(l in seenSummary)) { print "FAIL: Summary has no " l " row"; bad = 1 }
      else if (summary[l] + 0 != count[l] + 0) { print "FAIL: Summary says " summary[l] " " l ", catalogue has " count[l] + 0; bad = 1 }
    }
    if (!bad) print "PASS: " rows + 0 " rows"
    exit bad
  }' "$f"
