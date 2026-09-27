# Social post drafts (final submission)

Task S9 in [SUBMISSION_REQUIREMENTS.md](SUBMISSION_REQUIREMENTS.md). The PRD
asks for a post on X or LinkedIn that describes the project, shows the agent,
and tags @GauntletAI.

**Posted on LinkedIn 2026-09-27:** https://www.linkedin.com/feed/update/urn:li:activity:7509849090348867584/

Attach a screenshot or a short clip from the demo video.
Before posting, check the image shows **only synthetic data**: a seed patient,
a generated lab report, no keys, no real names.

Good images, in order: the click-to-source viewer with the row and value boxed
(shot 3 of [DEMO_SCRIPT.md](DEMO_SCRIPT.md)); the briefing with a guideline
card beside the cited facts (shot 5); the terminal refusing a push (shot 7).

## X (under 280 characters)

> Week 2 of @GauntletAI: my clinical co-pilot now reads scanned lab PDFs and
> intake forms. The model proposes each value; code has to find it on the
> printed row before it reaches the chart. Click any result to see the page.
> 72 eval cases gate every push.

(252 characters, room for a link.)

## LinkedIn

> Week 2 of the @GauntletAI AgentForge program: teaching a clinical co-pilot to
> read the documents clinicians actually get.
>
> In Week 1 I built a pre-visit briefing for primary care physicians inside
> OpenEMR, where every sentence cites a fact from the chart and anything
> uncited is removed before it's shown. The gap: the newest results usually
> arrive as a faxed lab PDF or a paper intake form, which the chart can't read.
>
> This week the co-pilot reads them:
>
> • Lab reports and intake forms, including scans. A model proposes each
>   value; code must find it on its printed row before it becomes a lab
>   result. Anything it can't find is shown as unverified, never as fact.
> • One click from any value to the page it came from, with the row boxed.
> • Guideline evidence from a small hybrid-search corpus, kept visibly
>   separate from the patient's record, with a check that each passage
>   applies to this patient (a statin passage for ages 40-75 is hidden for
>   an 82-year-old).
> • A supervisor that routes work to two workers and logs every handoff.
> • 72 eval cases with pass/fail rubrics. A git hook refuses any push where
>   one of them regresses.
>
> The lesson I'm taking away: the most useful thing a model did all week was
> propose. Checking against the source is what made it safe to show.
>
> All data shown is synthetic.
>
> #AI #HealthTech #LLM #OpenEMR

## After posting

Paste the post's link into the submission form and tick S9 in
[SUBMISSION_REQUIREMENTS.md](SUBMISSION_REQUIREMENTS.md).
