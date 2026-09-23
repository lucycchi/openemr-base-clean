# Clinical Co-Pilot contracts

Week 2 contracts (citation, lab-report, intake-form, handoff, run.*) are explained in context in [clinical_copilot_week2/W2_ARCHITECTURE.md](../../../../../clinical_copilot_week2/W2_ARCHITECTURE.md).

JSON Schema (draft 2020-12) for every input and output the agent exchanges
with the browser, the model provider, the sidecar, and operators. **These
files are the source of truth.** Both implementations conform to them:

- PHP is held to them by `ContractsTest` (every response payload, every
  file a valid strict schema), `ChatRequestTest` and `DocumentRequestTest`
  (parser and contract give the same verdict on the same bodies),
  `ContractExamplesTest` (the shared examples below) and `SidecarClientTest`
  (the runtime gate), all in `tests/Tests/Isolated/Modules/ClinicalCopilot/`
  and run by the push gate.
- The sidecar's Pydantic models are held to them by `sidecar/tests/test_contracts.py`
  through the same shared examples, also run by the push gate.
- At runtime, PHP validates every sidecar reply against `run.response`
  before parsing it (`SidecarClient`, via `Contracts::violations()`), and
  both LLM callers send the contract *file* to OpenAI as the strict
  `response_format` (PHP: `llm.briefing.output`, `llm.followup.output`;
  sidecar: `llm.lab-proposal.output`, `llm.intake-proposal.output`).

How this meets the engineering requirement, the decisions and their
trade-offs: [clinical_copilot_week2/ENGINEERING_REQUIREMENTS.md § 3](../../../../../clinical_copilot_week2/ENGINEERING_REQUIREMENTS.md#3-canonical-contracts-as-the-source-of-truth).

## Shared examples

`examples/<name>.examples.json` holds, per contract, documents it must
`accept` and documents it must `reject`. The Python and the PHP test suites
both run every file, so a contract change that one implementation misses
fails the gate on the other side. A contract without an examples file is
untested by behaviour; add one with the contract (at least one accept and
one reject). Rejected examples should each break one rule (an extra key, a
code outside an enum, a missing required field, a value out of range).

| File | Direction | Enforced how |
|---|---|---|
| `chat.request.schema.json` | browser → `public/chat.php` | `ChatRequest::fromBag()` parses the body at the boundary and refuses anything the schema refuses (400, or 403 for a missing CSRF token). `ChatRequestTest` runs the same bodies through the schema and the parser and requires identical verdicts. |
| `chat.briefing.response.schema.json` | `chat.php` → browser, `action=brief` | `PanelPayload::briefing()` output validated in `ContractsTest`. |
| `chat.answer.response.schema.json` | `chat.php` → browser, `action=ask` | `PanelPayload::answer()` output validated in `ContractsTest`. |
| `chat.chart-changed.response.schema.json` | `chat.php` → browser, stale `facts_hash` | `PanelPayload::chartChanged()` output validated in `ContractsTest`. |
| `chat.error.response.schema.json` | `chat.php` → browser, any error | Validated in `ContractsTest`; every error path in `ChatController` uses this shape. |
| `health.response.schema.json` | `public/health.php` → caller | Validated in `ContractsTest`. |
| `ready.response.schema.json` | `public/ready.php` → caller | `ReadinessReport::toArray()` validated in `ContractsTest` for ready, degraded and not-ready. |
| `prewarm.response.schema.json` | `public/prewarm.php` → caller | `PrewarmStatusPayload::build()` validated in `ContractsTest` with and without a last run. Counts only; the alert signal for the morning pre-warm (ALERTS.md § 6). |
| `alerts.response.schema.json` | `public/alerts.php` → webhook sender | Validated in `ContractsTest`. |
| `documents.request.schema.json` | browser → `public/documents.php` (Week 2) | `DocumentRequest::fromBag()` parses the fields at the boundary; `DocumentRequestTest` runs the shared examples through the schema and the parser and requires identical verdicts. The multipart `file` part is described, not schematised. |
| `documents.list.response.schema.json` | `documents.php` → browser, `action=list` | The real controller's response validated by the eval harness (PHI cases 36-38, rubric `schema_valid`); examples in `ContractExamplesTest`. |
| `documents.upload.response.schema.json` | `documents.php` → browser, `action=upload` (201, or 200 with `existing`) | Same. |
| `documents.extract.response.schema.json` | `documents.php` → browser, `action=extract` (run outcome, or the `already` short form) | Same; `handoffs` `$ref` the handoff contract. |
| `documents.error.response.schema.json` | `documents.php` → browser, every error including the 502 "stored, retry" shape | Examples in `ContractExamplesTest`; every error path in `DocumentController` uses this shape. |
| `llm.lab-proposal.output.schema.json` | OpenAI → sidecar, one page of a lab PDF | **Loaded at runtime** by `sidecar/copilot_sidecar/contracts.py` and sent as `response_format.json_schema` with `strict: true`; the `LabReportProposal` Pydantic model validates the reply and is held to the file by the shared examples. Values only: citations and coordinates are attached by `anchor.py`. |
| `llm.intake-proposal.output.schema.json` | OpenAI → sidecar, one page of an intake form | Same, `IntakeFormProposal`. |
| `llm.briefing.output.schema.json` | OpenAI → `OpenAiClient`, briefing | **Loaded at runtime** by `Prompt::briefingSchema()` via `Contracts::forOpenAi()` and sent to OpenAI as `response_format.json_schema` with `strict: true`. The provider enforces it; `LlmSchemaMismatch` is raised on any deviation. |
| `llm.followup.output.schema.json` | OpenAI → `OpenAiClient`, follow-up | Same, via `Prompt::followUpSchema()`. |
| `fact.schema.json` | shared: one row of the fact table | `$ref`'d by the three response contracts; its `category` enum is asserted equal to `FactCategory::cases()`. |
| `sentence.schema.json` | shared: one cited sentence | `$ref`'d by the response contracts. |
| `loinc_map.json` | shared (Week 2): analyte name to LOINC and canonical unit | Read by the sidecar's anchor step (`sidecar/copilot_sidecar/anchor.py`) to code results and flag unit mismatches. Not a schema; a coding table. |
| `citation.schema.json` | shared (Week 2): provenance for one claim or extracted field, with the bounding box for document sources | `$ref`'d by the extraction contracts; `Fact::citation` and the panel render it. Written by hand first; the sidecar's Pydantic export must equal it (pytest). |
| `lab-report.schema.json` | sidecar → PHP: extraction of a lab PDF | `$ref`'d by `run.response`, which PHP validates at runtime before parsing (`SidecarClient`); built by the sidecar's anchor step, never returned by the model directly (the model fills `llm.lab-proposal.output`). `tests/evals` extract and anchor cases validate outputs against it. |
| `intake-form.schema.json` | sidecar → PHP: extraction of an intake form | Same. |
| `handoff.schema.json` | sidecar → PHP: one supervisor routing step | Every hop in `run.response.handoffs`; written to Langfuse spans and the panel drawer. Reasons are fixed codes so they are safe to log. |
| `run.request.schema.json` | PHP → sidecar `POST /run` | `SidecarClient` builds it; the sidecar rejects anything else (422). No patient identifiers cross this boundary. |
| `run.response.schema.json` | sidecar → PHP, success | **Validated at runtime** by `SidecarClient` (`Contracts::violations`) before any of it is typed or persisted: an unknown key, a reason code outside the enum or a malformed chunk id is `schema_mismatch`, whatever the typed parser would tolerate. Carries `usage` for `Pricing` (one entry per model call, each traced as a generation) and, per extraction, `retries` (the sidecar's omission-driven re-ask count, for the dashboard). |
| `run.error.schema.json` | sidecar → PHP, failure | Fixed error codes only. |

## Rules

- Change the file first, then make the code conform. If a change alters
  what the model is asked to return, bump `Prompt::VERSION` (PHP) or
  `PROMPT_VERSION` (sidecar) so cached briefings are invalidated.
- Add or update the examples file with the contract; both test suites read it.
- Use constructs the PHP validator (justinrainbow/json-schema 6) enforces:
  `oneOf`/`anyOf`/`not`/`const`/`required`, not `if`/`then` (it accepts
  documents `if`/`then` would reject; the citation contract's bbox rule is
  written as `anyOf` for that reason). The sidecar's validator and the PHP
  one must agree on every example, which is what the examples test proves.
- Every contract has `additionalProperties: false` at the top level and a
  `description` on every property that a grader could not infer.
- `Contracts::schema($name)` returns the decoded file with `$id` rewritten
  to its on-disk location so sibling `$ref`s resolve; `Contracts::forOpenAi`
  strips `$schema`, `$id` and `title`, which OpenAI rejects, and nothing else.
- The HTTP status codes, headers and authentication for each endpoint are
  in each contract's top-level `description`.

## Validating a document by hand

```bash
openemr-cmd e 'cd /var/www/localhost/htdocs/openemr && php -r "
require \"vendor/autoload.php\";
\$s = json_decode(file_get_contents(\"interface/modules/custom_modules/oe-module-clinical-copilot/contracts/chat.request.schema.json\"));
\$d = json_decode(\"{\\\"csrf_token_form\\\":\\\"t\\\",\\\"action\\\":\\\"brief\\\"}\");
\$v = new JsonSchema\Validator; \$v->validate(\$d, \$s); var_dump(\$v->isValid(), \$v->getErrors());"'
```
