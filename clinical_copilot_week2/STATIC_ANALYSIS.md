# Static analysis: PHPStan level 10, from 544 errors to zero

The repository runs PHPStan at level 10 (`composer phpstan`, `openemr-cmd
pst`) with the project's custom rules in `tests/PHPStan/Rules/`. On
2026-09-22 the Clinical Co-Pilot code was measured for the first time as a
whole: **544 errors**, every one in a file the Co-Pilot work had added or
touched (the rest of OpenEMR is covered by the upstream baseline in
`.phpstan/baseline/`). By the end of the day the run reports
`[OK] No errors`, with no new baseline entries and no `@phpstan-ignore`.
This file records what was wrong, how each kind was fixed, the decisions
taken, and how to keep it at zero.

Paths are relative to the repository root; `<module>/` is
`interface/modules/custom_modules/oe-module-clinical-copilot/`.

## Where the 544 were

| Area | Errors | Files |
|---|---|---|
| Eval harness scripts | 279 | `tests/evals/run.php` (217), `smoke.php` (35), `gate.php` (14), `phi.php` (8), `case-index.php` (5) |
| Historical spike scripts | 74 | `tests/evals/spike/*.php` (Week 1 timing and seed-data probes) |
| Module source | 122 | `<module>/src/FactAssembler.php` (29), `Documents/DocumentIngestService.php` (11), `OpenEmrChartSource.php` (9), `Prewarmer.php` (8), and 30 files with one to six each |
| Module tests | 39 | `DocumentIngestServiceTest.php` (11), `LangfuseTracerTest.php` (9), `PanelPayloadTest.php` (5), and eight others |
| Upstream files touched by the Week 0 security fixes | 18 | `library/ajax/upload.php` (9), `interface/patient_file/summary/create_portallogin.php` (6), `interface/reports/ippf_statistics.php` (1), `tests/Tests/Isolated/Services/Search/SearchFieldStatementResolverFieldNameTest.php` (2) |
| Files written earlier the same day (contracts work) | 12 | fixed within that commit; see `ENGINEERING_REQUIREMENTS.md` § 3 |

## The five root causes and their fixes

### 1. Inline docblock tags PHPStan does not read (about 120 errors, 30 docblocks)

The module used one-line docblocks with the tag after the description:

```php
/** Steps in the order they ran. @var list<Step> */
private array $steps = [];
```

PHPStan's PHPDoc parser only recognises a tag at the start of a line, so
every one of these read as *untyped*: `array` with "no value type
specified", and everything flowing out of it became `mixed`. That one
pattern explained the 29 errors in `FactAssembler` (the `ChartSource`
interface's return types were invisible), the `StepRecorder`, `PanelPayload`,
`Row`, `Pricing`, `ReferenceRanges`, `FileRunLock` and `FactSet` errors, and
their echoes in the tests.

**Fix:** a script converted every inline-tag docblock in the module, its
tests and the harness to the multi-line form (30 docblocks); two more that
carried the tag mid-way through a multi-line block were fixed by hand.
Nothing about the declared types changed; they simply became visible.

**Rule going forward:** a docblock that carries a tag is multi-line, tag on
its own line. `phpcs` does not catch the inline form; PHPStan's
"no value type specified" is the symptom.

### 2. Casting `mixed` instead of narrowing it (about 200 errors)

Decoded JSON (case files, fixtures, sidecar replies) and database rows are
`mixed` at every offset. The harness cast its way through them:

```php
$id = (string) $case['id'];
$oid = (int) $o['procedure_order_id'];
if (empty($c['source_id']) || ...) { ... }
```

Level 10 reports every cast of `mixed` and every offset access on `mixed`,
which is right: a cast silently turns a missing key into `""` or `0` and a
nested array into `"Array"`.

**Fix:** typed readers with a harmless default, used at the point of
access. The module already had one for database rows (`<module>/src/Row.php`:
`Row::str`, `Row::int`, `Row::float`); the harness now has its own for
decoded JSON, `tests/evals/lib.php`:

| Reader | Returns | Use |
|---|---|---|
| `str($a, 'key')`, `int($a, 'key')` | `string`, `int` (with a default) | one scalar field |
| `map($a, 'key')` / `mapOf($v)` | `array<string, mixed>` | a JSON object |
| `lst($a, 'key')` / `listOf($v)` | `list<mixed>` | a JSON array |
| `strings($v)` | `list<string>` | a JSON array of strings |
| `jsonFile($path)` | `array<string, mixed>` | a whole case, fixture or baseline file |

Every `(string)`, `(int)` and `empty()` on a decoded value in the harness
became one of these. In the module, three private helpers do the same for
`COUNT(*)` results and joined document columns
(`DocumentIngestService::count()`, `OpenEmrChartSource::pageLabel()`, and
locals narrowed with `is_numeric()` before use).

### 3. Parsers declared narrower than their input (about 25 errors)

The Week 2 value objects' `fromArray()` methods were declared
`@param array<string, mixed> $a`, but what they receive is `json_decode()`
output, whose honest type is `array<mixed>` (keys may be ints, values
anything). PHPStan refused every call site.

**Fix:** the parsers now say what they accept, `array<mixed>`, and narrow
every value themselves (they already did). The contract, not the parser, is
the gate on that boundary (`ENGINEERING_REQUIREMENTS.md` § 3), so widening
the parser's declared input loses nothing.

### 4. Constructs the project's rules forbid (about 20 errors)

| Rule | Where | Fix |
|---|---|---|
| Functions in the global namespace | every harness script | `namespace OpenEMR\Tests\Evals;` in `run.php`, `phi.php`, `gate.php`, `case-index.php`, `smoke.php`, `lib.php`, with `use` imports for the global classes they name |
| `global $failures` | `smoke.php` | a small `Failures` counter object passed to `check()` |
| `$argv` "might not be defined", `$_SERVER` forbidden | `run.php`, `gate.php`, `case-index.php`, `smoke.php` | Symfony Console's `ArgvInput` (already a dependency): `hasParameterOption('--live')`, positional arguments through an `InputDefinition` |
| `passthru()` forbidden | `gate.php` | `Symfony\Component\Process\Process` with array arguments (no shell), output streamed |
| `sqlQuery()` instead of `QueryUtils` | `upload.php`, `create_portallogin.php` (Week 0 additions) | `QueryUtils::querySingleRow()` and `is_array` / `is_numeric` narrowing |
| deprecated `startTransaction()` / `commitTransaction()` | `DocumentIngestService::persist()` | `QueryUtils::inTransaction(fn() => ...)` |
| nullsafe `?->` on the left of `??` | `AttachCommand`, `DocumentController` | plain `->` (`??` already tolerates a null in the chain) |

### 5. The harness validated schemas with a private copy (retrieve cases)

`run.php` walked `Contracts::schema('run.response')->properties->chunks->items`
(untyped `stdClass` access) to validate retrieval chunks. It now validates a
one-chunk `run.response` document through `Contracts::violations()`, the
same function the module uses at runtime, so the harness and production
judge documents identically and there is no second validator to drift.

## Decisions and trade-offs

1. **Fix at the source, no baseline growth, no ignores.** Every error was
   removed by changing the code (or, for the inline docblocks, by making
   the intended type visible). The four baseline files touched are
   upstream entries for files the Week 0 security commits had edited: three
   stale entries dropped (the errors no longer occur) and one count reduced
   from 4 to 3. *Trade-off:* about 1,200 lines changed in one day; the eval
   suite, the smoke test and the module's 330 PHPUnit tests are the proof
   that behaviour did not.
2. **Historical spike scripts are excluded, not fixed.** `tests/evals/spike/`
   holds four one-off Week 1 probes (chart-load timings, seed-data column
   checks, first end-to-end runs). They are the record behind
   `tests/evals/spike-results.md`, are never run by the gate, the hook or
   the application, and would need a rewrite to type. They are listed under
   `excludePaths` in `phpstan.neon.dist` with that reason. *Trade-off:* 74
   errors were retired by exclusion rather than repair; the scripts are
   documented as historical and stay out of the analysed set.
3. **A typed-reader library for the harness rather than DTOs per case
   mode.** Cases have eleven modes with different shapes; DTOs for each
   would be more code than the harness. Readers with defaults keep the
   scripts short while making every access explicit about what it expects.
   *Trade-off:* a misspelled key yields a default instead of a crash; the
   expectation compare (`compare()`) catches a wrong default as a mismatch
   on the next run.
4. **`array<mixed>` for decoded JSON parsers.** Declaring the honest input
   type is preferable to a `@var` cast at each call site (the guide's
   "narrow, don't cast"). The contract validation that runs before parsing
   is what guarantees the shape.
5. **The search-builder test constructs its search field explicitly.** The
   SEC-11 regression test had passed a primitive through
   `FhirSearchWhereClauseBuilder::build()` to exercise the wrapping path;
   the builder's contract says `array<string, ISearchField>`. Widening that
   upstream contract produced sixteen new errors in unrelated services, so
   the test now passes a `StringSearchField` named after the injected
   parameter, which reaches the same resolver guard. *Trade-off:* the
   builder's primitive-wrapping line is no longer covered by that test; the
   guard it protects is.
6. **Upstream files: only the lines the security commits added were
   rewritten.** `upload.php` and `create_portallogin.php` keep their legacy
   shape; the Week 0 ownership and ACL checks now narrow their inputs
   (`is_numeric`, `is_array`, `QueryUtils`) and the file-level counters in
   the baseline were adjusted to match.

## Verify it

```bash
openemr-cmd pst                                   # [OK] No errors
tests/evals/gate.sh pre-push                      # the harness still passes all deterministic cases
openemr-cmd e 'php tests/evals/smoke.php http://openemr 3'   # the Selenium smoke script still drives the UI
```

## Keeping it at zero

- Run `openemr-cmd pst` before committing anything in the module, the
  harness or the tests; the result cache makes a rerun take under a minute.
- Install the pre-commit hooks (`openemr-cmd prek-install`); with the
  Co-Pilot files clean, the hook's PHPStan step no longer refuses every
  commit, which is why it had stayed uninstalled.
- New code: multi-line docblocks for any tag; `Row::*` for database rows;
  `tests/evals/lib.php` readers for decoded JSON in the harness; parsers of
  decoded JSON take `array<mixed>`; never `(string)` / `(int)` a `mixed`.
- Do not add baseline entries for Co-Pilot files. The only Co-Pilot paths
  that are not analysed are the historical spike scripts.
