# Module: <name>

**Source files:** <every file, with line ranges>
**Test patients used:** <fixture keys from TEST-PATIENTS.md>

## 1. Purpose and lifecycle

> Guidance: what happens on page load, on each user action, and anything loaded later by JavaScript.

## 2. What the user sees

> Guidance: every field shown, including tooltips and styling that carries meaning (for example severity colour); sort order; what is filtered out; the empty state exactly as worded; visibility rules. Write "None" for a label that genuinely has nothing.

| Field shown | Format | Source column |
|---|---|---|

**Sort order:**
**Filtered out:**
**Empty state text:**
**Visibility rules:**

## 3. Controls

> Guidance: every button, link and toggle; what it does; and whether the new dashboard shows it, links out to OpenEMR for it, or leaves it out. Use a "None" row if there are no controls.

| Control | What it does | New dashboard |
|---|---|---|

## 4. Permission checks

> Guidance: every ACL check with its line number, missing checks, and the OAuth scope that covers the same access under each auth option.

| Check | Line | Scope in the new app |
|---|---|---|

## 5. Data

> Guidance: tables and columns read, anything written, then map each field the user sees to FHIR. Status must be one of: matches, differs, not available. "Checked against" names the fixture key and the value you saw in the API response. Keep exactly one table in this section, because the checker reads every table row here as a mapping row.

**Reads:**
**Writes:**

| Field shown | FHIR resource.field | Status | Checked against | Notes |
|---|---|---|---|---|
| List completeness | | | | |

## 6. Globals

> Guidance: every $GLOBALS, OEGlobalsBag, session or local-scope variable read or written, and its effect on the card. Use a "None" row if there are none.

| Global | Read or write | Effect on the card |
|---|---|---|

## 7. Problems

> Guidance: each problem as a BM-NNN id with a citation; the full row goes in BUGS-MITIGATIONS.md. Write "None found." if there are none.
