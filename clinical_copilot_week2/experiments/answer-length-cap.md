# Experiment: capping the follow-up answer length (2026-09-22)

## Why

The live eval gate refused three pushes in a row on case 12 (an ambiguous
follow-up, "Is it higher than it was last time?", asked of the three
`abnormal:3` seed patients). For patient 5, the densest chart in the set
(28 facts), the model call ran past `OpenAiClient`'s 20 s per-attempt limit
and the retry ran out of the 28 s total. The prompt was byte-for-byte the
same size as on passing runs (2,157 prompt tokens), OpenAI answered a probe
in about a second, and the same patient's other follow-ups answered in under
a second, so the length of the *generated* answer was the suspect.

## Method

`tmp/cap_experiment.php` (not committed; the script is reproduced at the end)
assembled patient 5's facts exactly as the harness does (latest encounter as
the history boundary), built the production `Prompt::followUpSystem()` and
`followUpUser()`, and posted directly to OpenAI with a 90 s timeout so real
durations were measured rather than cut at 20 s. Four conditions, five calls
each, `gpt-4o-mini`, `temperature` 0, strict JSON schema, same session:

- **uncapped**: today's prompt as shipped
- **cap N**: the same prompt plus one line, "Answer in at most N sentences;
  prefer the most recent values and the direct comparison the question asks
  for."

## Results

| Cap | Response times (ms), in order | Median | Sentences returned | Completion tokens |
|---|---|---|---|---|
| none | 7,221 · 4,890 · 9,900 · 5,344 · 9,275 | 7.2 s | 18 · 10 · 1 · 10 · 1 | 780 · 473 · 1,221 · 482 · 1,211 |
| none (second batch) | 4,277 · 7,830 · 9,896 · 8,198 · 3,905 | 7.8 s | 10 · 21 · 25 · 22 · 10 | 474 · 1,003 · 1,105 · 1,040 · 482 |
| 3 | 1,644 · 1,223 · 1,501 · 1,233 · 1,154 | 1.2 s | 2 · 2 · 2 · 2 · 2 | 104 each |
| 6 | 3,478 · 1,523 · 3,785 · 2,727 · 5,835 | 3.5 s | 6 · 2 · 6 · 6 · 6 | 408 · 137 · 371 · 290 · 485 |
| 10 | 4,447 · 4,504 · 5,038 · 4,579 · 4,229 | 4.5 s | 10 · 10 · 10 · 10 · 10 | 511 · 509 · 596 · 524 · 524 |

Prompt size was constant (2,157 tokens uncapped, 2,179 with the cap line), so
the differences are output length: about 1 s fixed plus 7–8 ms per generated
token.

What the uncapped answers contained (second batch, text captured): for a
question whose "it" is undefined, the model enumerated every changed value on
the chart, one cited sentence per lab delta ("Platelet changed from 10.28 fL
to 11.07 fL [17582d54], indicating an increase." and so on), 10 to 25
sentences. The two "1 sentence, 1,200 tokens" replies in the first batch were
the same enumeration written as one run-on sentence. At temperature 0, five
identical requests still produced five different lengths.

What the capped answers contained: with 3, the prior-visit date and the most
recent change (the two sentences the question needs); with 6 and 10, the same
followed by further deltas, all cited. `answer_type` was `cited` in every
capped run.

## Decision: six sentences

Adopted in `Prompt::followUpSystem()` with `Prompt::VERSION` bumped to
`2026-09-22.5` (cached briefings are keyed on the version, so nothing stale
is served). The cap halves the median latency, removes the tail that crossed
the 20 s limit (worst observed 5.8 s), and leaves room for a value, its date,
the comparison, a second value, and the guideline sentence the prompt
requires on treatment questions.

The product rationale, from the project owner, who practised as a clinician:
clinicians read the top few pertinent data points, not an exhaustive list;
the panel's fact table beside the answer already lists everything, and the
omission guard still forces must-surface facts onto the screen regardless of
the cap.

Rejected alternatives: raising the time budgets (a physician waits longer
before "unavailable", and the enumeration behaviour stays); scoring a
timeout as "not decidable" in the eval rubric (hides an availability problem
the product should not have); changing the case's patient selector (hides
the behaviour instead of handling it).

## Follow-ups

- Re-run the live gate and refresh `baseline-live.json` under the new prompt
  version (done the same day; see the commit).
- Watch `latency_ms_p95` in `results.json` metrics and the Langfuse p95 alert
  for follow-ups; the cap should show as a drop.

## Script

```php
<?php
// tmp/cap_experiment.php: patient 5, the case-12 question, four conditions, five calls each.
$ignoreAuth = 1; $_GET['site'] = 'default'; $sessionAllowWrite = true;
require_once __DIR__ . '/../interface/globals.php';
$loaders = Composer\Autoload\ClassLoader::getRegisteredLoaders();
reset($loaders)->addPsr4('OpenEMR\\Modules\\ClinicalCopilot\\', __DIR__ . '/../interface/modules/custom_modules/oe-module-clinical-copilot/src/');
use OpenEMR\Modules\ClinicalCopilot\{Config, FactAssembler, OpenEmrChartSource, AclAuthorization, PatientId, Prompt};
$config = Config::fromEnvironment(); $pid = 5; $question = 'Is it higher than it was last time?';
$encRow = OpenEMR\Common\Database\QueryUtils::querySingleRow("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", [$pid]);
$assembled = (new FactAssembler(new OpenEmrChartSource(), new AclAuthorization('admin'), OpenEMR\BC\ServiceContainer::getClock()))->assemble(new PatientId($pid), (int) $encRow['encounter']);
$prompt = new Prompt(); $http = new GuzzleHttp\Client(['timeout' => 90]);
foreach (['uncapped' => null, 'cap 3' => 3, 'cap 6' => 6, 'cap 10' => 10] as $label => $cap) {
    $system = $prompt->followUpSystem() . ($cap === null ? '' : "\nAnswer in at most $cap sentences; prefer the most recent values and the direct comparison the question asks for.");
    for ($i = 1; $i <= 5; $i++) {
        $t = microtime(true);
        $res = $http->post('https://api.openai.com/v1/chat/completions', ['headers' => ['Authorization' => 'Bearer ' . $config->openAiApiKey], 'json' => [
            'model' => $config->openAiModel, 'temperature' => 0,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt->followUpUser($assembled, $question, [])]],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'followup', 'strict' => true, 'schema' => $prompt->followUpSchema()]]]]);
        $d = json_decode((string) $res->getBody(), true); $c = json_decode($d['choices'][0]['message']['content'], true);
        printf("%s #%d %d ms, %d sentences, %d completion tokens\n", $label, $i, (microtime(true) - $t) * 1000, count($c['sentences']), $d['usage']['completion_tokens']);
    }
}
```
