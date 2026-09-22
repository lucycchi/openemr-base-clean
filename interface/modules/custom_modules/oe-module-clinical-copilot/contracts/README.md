# Clinical Co-Pilot contracts

Week 2 contracts (citation, lab-report, intake-form, handoff, run.*) are explained in context in [clinical_copilot_week2/W2_ARCHITECTURE.md](../../../../../clinical_copilot_week2/W2_ARCHITECTURE.md).

JSON Schema (draft 2020-12) for every input and output the agent exchanges
with the browser, the model provider, and operators. **These files are the
source of truth.** The PHP implementation conforms to them and is held to
them by `tests/Tests/Isolated/Modules/ClinicalCopilot/ContractsTest.php`
and `ChatRequestTest.php`, which run on every commit (`openemr-cmd pit`).

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
| `llm.briefing.output.schema.json` | OpenAI → `OpenAiClient`, briefing | **Loaded at runtime** by `Prompt::briefingSchema()` via `Contracts::forOpenAi()` and sent to OpenAI as `response_format.json_schema` with `strict: true`. The provider enforces it; `LlmSchemaMismatch` is raised on any deviation. |
| `llm.followup.output.schema.json` | OpenAI → `OpenAiClient`, follow-up | Same, via `Prompt::followUpSchema()`. |
| `fact.schema.json` | shared: one row of the fact table | `$ref`'d by the three response contracts; its `category` enum is asserted equal to `FactCategory::cases()`. |
| `sentence.schema.json` | shared: one cited sentence | `$ref`'d by the response contracts. |
| `loinc_map.json` | shared (Week 2): analyte name to LOINC and canonical unit | Read by the sidecar's anchor step (`sidecar/copilot_sidecar/anchor.py`) to code results and flag unit mismatches. Not a schema; a coding table. |
| `citation.schema.json` | shared (Week 2): provenance for one claim or extracted field, with the bounding box for document sources | `$ref`'d by the extraction contracts; `Fact::citation` and the panel render it. Written by hand first; the sidecar's Pydantic export must equal it (pytest). |
| `lab-report.schema.json` | sidecar → PHP: extraction of a lab PDF | Validated by PHP before persisting; sent to OpenAI as the Structured Output schema by the sidecar. `tests/evals` extract and anchor cases validate fixtures against it. |
| `intake-form.schema.json` | sidecar → PHP: extraction of an intake form | Same. |
| `handoff.schema.json` | sidecar → PHP: one supervisor routing step | Every hop in `run.response.handoffs`; written to Langfuse spans and the panel drawer. Reasons are fixed codes so they are safe to log. |
| `run.request.schema.json` | PHP → sidecar `POST /run` | `SidecarClient` builds it; the sidecar rejects anything else (422). No patient identifiers cross this boundary. |
| `run.response.schema.json` | sidecar → PHP, success | Validated by PHP before any persistence; carries `usage` for `Pricing`. |
| `run.error.schema.json` | sidecar → PHP, failure | Fixed error codes only. |

## Rules

- Change the file first, then make the code conform. If a change alters
  what the model is asked to return, bump `Prompt::VERSION` so cached
  briefings are invalidated.
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
