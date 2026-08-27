# The Legal Research Agent

## Working notes on design, implementation, and what the measurements showed

---

## 1. Why this exists

Two motivations, and they pull in slightly different directions.

**The practical one.** The web app answers a question with one retrieval pass. That works well
for questions with a single answer — *"what is the value threshold for low-value
procurement?"* returns чл. 48 and the figures. It fails on questions with several parts:
*"our contracting authority ran a negotiated procedure without publishing a notice — what are
the consequences and what deadlines apply?"* needs the procedure's conditions, then the
penalty provisions, then the appeal deadlines. One embedding of the whole question retrieves
a blur of all three and does none well.

**The learning one.** The agent is a surface for practising things that matter in LLM
orchestration generally: tool design, sessions, memory, security. Those topics are mostly
independent of the legal domain — they'd be the same for any agent — but having a real corpus
and a real evaluation harness makes them concrete instead of theoretical.

The second motivation is the honest primary one. The first is what makes the exercise
non-trivial.

---

## 2. What an agent is here

A pipeline is fixed: retrieve, then generate. Every question takes the same path.

An agent is a loop where the model decides what happens next:

```
messages = [system prompt, user question]

repeat up to N times:
    response = model(messages, available_tools)
    append the response to messages

    if the model returned no tool calls:
        it has finished — return its answer

    for each tool call:
        execute it
        append the result to messages

return "exhausted"
```

That is the entire mechanism. Roughly fifty lines. Everything interesting is in the details.

**Four details that break it if you get them wrong:**

*The assistant's message goes into history before the tool results.* The API needs the call
and its result adjacent and in order. Otherwise you get confusing 400s.

*`tool_call_id` must match the call it answers.* With parallel calls the model needs to know
which result belongs to which request.

*Tool failures become tool results, not exceptions.* If retrieval errors, tell the model —
it can rephrase and retry. Throwing kills a run the model could have recovered from.

*The step budget is mandatory.* Without it, a model that keeps searching will loop until the
API quota dies. This is not theoretical; it happened during testing.

**It was built by hand deliberately.** A framework would have supplied the loop, and the
awkward parts it hides are exactly the parts worth understanding before adopting one.

---

## 3. How it relates to the web app

They are separate programs that share one service.

```
     ┌──────────────────────────────┐         ┌────────────────────────────┐
     │  Web app (Laravel + Vue)     │         │  Agent (TypeScript, CLI)   │
     │                              │         │                            │
     │  one question → one          │         │  loop: search, read,       │
     │  retrieval → one answer      │         │  decide, search again      │
     └───────────┬──────────────────┘         └─────────────┬──────────────┘
                 │                                          │
                 │ gRPC                        HTTP tool endpoints
                 │                                          │
                 └──────────────┬───────────────────────────┘
                                ▼
                  ┌──────────────────────────────┐
                  │  Retrieval service (Python)  │
                  │  BGE-M3 · vectors.sqlite     │
                  │  citation graph              │
                  └──────────────────────────────┘
```

The agent does not import the web app or share its runtime. It reaches retrieval through thin
HTTP endpoints in Laravel that wrap the existing `RetrievalClient` — reusing the app's
authentication and rate limiting rather than opening a second door to the retrieval service.

**Why a separate program.** The web app is close to deployable. Bolting a TypeScript agent
runtime into a Laravel/Python stack would add a moving part to something being stabilised, and
the topics being practised don't need the coupling.

**Two things the shared retrieval service gives for free:** the agent inherits a corpus that
was tuned with measurements — 1,831 chunks, hybrid dense and sparse search, RRF fusion, ~300ms
warm — and the evaluation harness built for the app applies to the agent's tools unchanged.

---

## 4. The tools

### `search_law(query)`

Semantic search over the corpus. Returns up to ten passages, each with a citation, the
statutory text, and a fused relevance score.

The description given to the model matters as much as the implementation. An early version
omitted that search is semantic, and the model started adding quotation marks as though there
were an exact-phrase operator — applying a keyword-search mental model that doesn't match the
tool. The description now says so explicitly.

### `follow_references(law, article)`

Returns the articles a given article cites, from the citation graph — 1,410 articles, 945
edges, parsed from statutory phrases like *"од член 28 став (1)"*.

**One design decision worth recording: identifiers, not text.** The tool returns each cited
article's citation and heading, never the body. чл. 181 cites seventeen articles; returning
their full text would inject thousands of tokens that then persist in context for the rest of
the run. Seventeen short lines is a tenth the size, and the heading is enough for the model to
decide which reference matters.

This was borne out by measurement — the run that traversed cost 5,391 tokens against ~9,900
for search-only runs, because compact identifiers beat full passages.

---

## 5. The trace inspector

Every run is persisted under an id, capturing per step: the model's message, each tool call
with arguments, each tool result in full, token usage, timing, and the final outcome. A local
page renders the trajectory in order, with tool results collapsed and expandable.

**This is not instrumentation added afterwards — it is the point.** The question being asked
of every run is not *"did it answer?"* but *"did it decompose the question sensibly, or search
once and answer, or loop in circles?"* That is unanswerable without reading the trajectory.

The first run demonstrated why. Reading it step by step:

```
step 0  search_law("преговарање без објавување оглас последици")
step 1  search_law("мислење од Бирото постапка со преговарање рокови")
step 2  final answer
```

Step 1 names **мислење од Бирото** — the Bureau's prior opinion. That concept is nowhere in
the original question. It could only have come from reading чл. 55 in the step 0 results and
noticing the procedure requires a Bureau opinion, which implies a deadline worth chasing.

That is the model reading its results and forming an informed follow-up — not a hardcoded
"now search for deadlines". It's the clearest available evidence that the decomposition is
genuine reasoning rather than a prompt-shaped reflex.

---

## 6. What the measurements showed

Each piece of work produced a finding, and several were the opposite of what was expected.

### Out-of-scope questions caused a spiral

Asked about Macedonian Army officer ranks — nothing in the corpus — the agent searched, got
irrelevant passages, and **rephrased the same concept with quotation marks**. It treated an
empty result as *search harder* rather than *not here*, and was still looping after two
minutes.

The fix was entirely at the prompt level: name the ten laws explicitly, so the model can
recognise an out-of-scope question before searching, and give it a concrete stopping rule —
if two searches return unrelated passages, stop.

Result: the same question now refuses in one step with zero tool calls, ~1,500 tokens against
6,113-and-climbing. A code-level guard was added as a backstop, but the prompt does the work.

### The reference tool is barely used — and that is correct

`follow_references` was expected to see heavy use. It doesn't. On a natural cross-reference
question the model searched once and answered, never calling it.

The reason is sound: чл. 181's own text names the obligations it references inline
(*"не донесе одлука во роковите од членот 112…"*), so reading it gives both the penalty and
its trigger. Retrieval also auto-expands one graph hop, so cited neighbours often arrive for
free.

A steering probe confirmed the tool works when genuinely needed. So this is the model
declining to spend a call on information it already has — judgment, not a broken tool.

**The lever, if more traversal were wanted, is not a stronger prompt.** It would be narrowing
`search_law`'s automatic expansion so referenced articles don't arrive without asking. That is
a separate decision, deliberately not folded in.

### Prompt injection: resisted, but for fragile reasons

A test fixture was added to the corpus — an env-gated in-memory overlay, never written to
`vectors.sqlite` — containing statutory-looking text with embedded instructions.

Four variants. All ignored:

| Variant | Disguise | Why it failed |
|---|---|---|
| A, B | Fake law code `ЗАКОН-ТЕСТ` | The ten-law scope guard rejected the source outright |
| C | Real law code, tried to alter citation format | Model resisted on its own merit |
| D | Real law code, claimed the topic was out of scope | Model resisted on its own merit |

C and D are the meaningful passes — labelled with genuine law codes and mixed among real
results, so the scope guard couldn't help.

**But the honest reading is that this is evidence, not proof.** Four hand-crafted attempts
against one model, all carrying conspicuous meta-instructions. A payload phrased as ordinary
legal reasoning would be a harder test.

**The real defence is that the tools are read-only.** A fully successful injection can make
the agent produce a wrong answer. It cannot exfiltrate, write, delete, send, or spend. That
property — not the prompt — is carrying the security of this system, and adding the first
tool that can take a consequential action is the moment to redesign the trust model rather
than patch the prompt.

### In-run compaction: real but bounded

Token cost grew steeply: 736 → 5,858 → 12,165 across three steps, because each request
re-sends the entire history including all retrieved passages. Analysis confirmed 77–83% of a
later step's context is passage text from earlier steps, and only 2–3 of every ten retrieved
passages are ever cited.

Compaction was implemented: deduplicate passages appearing in multiple searches (lossless),
and reduce superseded search results to a 500-character snippet while always retaining the
citation.

**Saving: 16% on a typical two-search run, zero on single-search runs.**

The reason the win is small is the finding:

> the model genuinely re-reads earlier passages at synthesis, and which detail it will quote
> isn't knowable at compaction time

A concrete example makes it sharp. чл. 145's "30 days" rule sits at character ~1050 of a
1471-character passage. A 500-character snippet drops it — the citation survives, so a
grounding check that asks *"does the cited article appear in what was retrieved?"* still
**passes**, while the answer is quietly thinner.

That is a failure mode which evades the obvious quality check, and it was found by static
analysis rather than by waiting for it to bite.

### Sessions: the inverse conclusion

Multi-turn sessions were added — a conversation id, state on disk, lazy TTL expiry, and
rejection rather than last-write-wins if two turns race on the same id.

The design question was what carries forward. The answer, from probing real traces: **the
transcript of questions and answers, and nothing else.** Not passages, not a citation ledger.

Because follow-ups re-retrieve. Asked *"which of those deadlines is shortest?"* — a question
answerable from turn 1's text alone — the model searched again anyway, since the prompt
requires answering only from retrieved provisions.

**Which inverts the in-run finding.** Within a run, the model re-reads passages at synthesis,
so they are kept. Across turns, it re-retrieves, so they are dropped. Same model, same
passages, opposite conclusion depending on the boundary.

The value of the transcript is therefore **reference resolution, not retrieval avoidance.**
It's what lets *"the notice"* and *"those deadlines"* become well-formed searches.

Cost: ~450 tokens per turn of carried context, growing linearly — 1,858 → 2,350 → 2,835 →
3,264 across four turns. Turn 4 cost 11.2K, dominated by its own search, against ~40K for a
fresh deep run.

A deliberate topic change on turn 4 — three procurement turns then a labour question —
produced a clean labour answer with no procurement leakage.

### Long-term memory across sessions: evaluated and rejected

The layer above sessions — a persistent profile of the laws a user works in, the articles
they return to, their terminology, prior conclusions — was built as a minimal, off-by-default
injection and held to the same on/off discipline as everything else. It does not earn its
place.

The test that decided it was an **ambiguous** question: appeals exist in procurement, labour,
and administrative procedure. With memory **off**, the answer acknowledged the ambiguity and
covered both plausible domains. With a strong procurement profile **on**, it assumed
procurement from history and narrowed to it — and the safer answer is the one that doesn't
guess. On a **clear** question — a labour query carrying the same procurement profile — memory
changed nothing: retrieval grounded the answer on the question, and the profile didn't leak.

> Memory is inert exactly where it's safe — clear questions self-ground through retrieval —
> and active only where it's risky, on ambiguous ones, where it biases toward the user's
> history instead of asking.

That is the same shape as every finding above: when retrieval is good, the added machinery
has little to do, and what it does do is as likely to mislead as to help. If ever revisited,
the candidate is **prior conclusions scoped to an explicit matter id**, not an inferred global
profile — scoping removes the ambiguous-question bias — but even that needs a correction path
and export/deletion before it's safe, and the evidence didn't ask for it. (A memory store is
personal data; whatever is built has to join the app's export and account-deletion paths on
the same terms — another cost against a benefit that didn't appear.)

### Where decomposition earns its cost — the severance question

The clearest evidence yet of what the agent is *for*, from putting one two-part question to both
paths:

> *Кои се основите за отказ поради технолошки вишок и колкава испратнина следува?*
> — the grounds for a redundancy dismissal, and how much severance follows.

**Chat path:** one retrieval, one generation, 2.6s. It retrieved чл. 96, 6, 76, 265, 101 and
answered *both* parts — including a severance scale of one to seven net salaries and a 50% floor on
the calculation base. A legal practitioner confirmed the answer was correct.

**Agent** (run `20260827-145236-b4pq4i`): three searches, 25,274 tokens, ~7s.

```
step 0  search_law("технолошки вишок отказ испратнина")
step 1  search_law("деловни причини отказ договор за вработување")
step 2  search_law("испратнина деловни причини став висина")
step 3  final answer
```

The third search surfaced **чл. 97**, the severance article, and the answer cites it paragraph by
paragraph (ст. 1 through ст. 6): the full six-band scale, the calculation base, payment timing, the
nullity of any waiver, and the seasonal-work exception.

**чл. 97 never appeared in the chat path's retrieved passages.** So the chat answer's severance
figures came from the model's own knowledge of Macedonian labour law — not from anything it was
shown. It was correct, and the citation panel gave no sign the figures weren't grounded.

The failure is subtle, and worth stating precisely: the chat answer was **not wrong — it was right
with a broken audit trail.** For a legal tool that is arguably the *worse* failure mode, because a
practitioner checking the answer for correctness will pass it, and the missing provenance is
invisible. A single embedding of a two-part question retrieved the "grounds" half and missed the
"severance" half, and generation papered over the gap from parametric knowledge.

Don't overstate it: one question, one run each. The earlier procurement multi-hop ("consequences and
deadlines") was answered adequately by the chat path, so this is a demonstration of a real failure
mode, not evidence that every multi-hop question fails single-shot. But where one embedding *cannot*
cover the question, the agent's separate searches are not overhead — they are the difference between
**grounded** and **merely correct**.

### Three ways a question can fail, and where each is handled

Working on the app's **non-legal input gate** clarified a distinction that had been blurred.
Three different failures need three different mechanisms:

1. **The input isn't a legal question at all** — a greeting, thanks, small talk, an empty box.
   *Cheaply detectable from shape alone*, before any work. The app gates it with a pattern
   check (no LLM, no score threshold) that short-circuits before embedding, search, and
   generation, returning a bilingual "here's what this is for" message. Biased hard toward
   false negatives: a terse real query (`рок за жалба`, `чл. 132`) must never be blocked, so
   the gate fires only when the *whole* input is a known non-legal phrase — zero of the eval
   questions are gated.

2. **A legal question about an uncovered domain** — Army ranks, criminal law, tax. *Not
   detectable before retrieval*: you can't know the corpus lacks it without searching. Handled
   at generation, by the prompt. Naming the ten laws in the *agent's* prompt transformed its
   behaviour — a spiral of rephrased searches became a one-step refusal. The same enumeration is
   now in the chat generation prompt (`prompts/legal_mk.txt`), kept to a single prose line so the
   ≤5-line small-model guard still holds. (An earlier attempt as a multi-line list was reverted
   for breaking that guard; on the hosted Gemini path the enumeration's effect on the *refusal
   itself* is small — capable Gemini already refuses cleanly — so its real payoff is that it gives
   the model the material to name *which* areas are covered, once refusals are handled properly.)

   **Refusal handling, and a caution about markers.** When generation refuses, the provisions
   panel is now suppressed — five unrelated articles above a refusal imply they are relevant when
   the model has just said they aren't. Detecting a refusal reliably is the interesting part. The
   prompt appends a machine-readable marker (`[[NO_SOURCE]]`) on refusal; but the marker **alone
   is not trustworthy** — during verification Gemini appended it to a *real negative answer*
   ("secondary qualification does not meet the conditions"). Marker-only detection would have
   stripped a valid answer's citations, which are the product. So the panel is suppressed only
   when the marker **and** a required refusal opening agree, and the marker is always stripped from
   the text regardless. The general lesson: **a marker the model emits is a signal, not a
   guarantee — models over-emit, so never let one alone strip something the user needs.** A false
   positive can't fire; a missed suppression only leaves the prior behaviour.

3. **A genuine retrieval gap** — the answer *is* in the corpus but the pipeline never surfaces
   it (Q2 and Q9 in the eval set: a vocabulary mismatch between the question and the statute; and
   the severance case above, where чл. 97 never surfaced for a two-part question). The hard one,
   and **unsolved**. Not the gate's job and not the prompt's — a retrieval-quality problem that
   stays open.

---

## 7. The pattern across all of it

Four separate pieces of work produced the same shape of result:

- `follow_references` is barely used, because articles are self-describing and retrieval
  already expands one hop
- In-run compaction saves 16%, because the model genuinely needs what it's carrying
- Sessions need only a transcript, because follow-ups re-retrieve
- Cross-session memory doesn't earn its place, because clear questions self-ground and it
  bites only on ambiguous ones

**When retrieval is good, the agent machinery has less to do** — for *single-hop* questions.
The severance question (§6) marks exactly where that stops holding: when one embedding cannot
cover a two-part question, single-shot retrieves one half and generation fills the other from
parametric knowledge — right, but ungrounded, and with a citation panel that hides the gap.
There the agent's separate searches are not overhead; they are the difference between grounded
and merely correct.

The agent earns its complexity on questions a single retrieval genuinely cannot handle —
decomposition into distinct sub-questions, and exhaustive enumeration of what an article
cites. Everywhere else it is overhead on top of a pipeline that already worked.

That is a more valuable conclusion than any of the individual optimisations, and it was
reached by measurement rather than assumption — which is only possible because the trace
inspector and the evaluation harness existed first.

---

## 8. Current state

**Built:** hand-written agent loop; two tools; trace inspector; scope guard with corpus
enumeration and a stopping rule; injection test fixture with four variants; in-run history
compaction; multi-turn sessions with file storage, lock-based concurrency rejection, lazy TTL,
and reconnection.

**Evaluated and rejected:** long-term memory across sessions — built as a minimal off-by-default
injection, measured, and found not to earn its place (§6).

**Not built:** comparison against the Pi framework, which was the original motivation for
building the loop by hand.

**Open questions worth noting:**

*Small samples.* Most findings rest on one to three runs per configuration. Run-to-run
variance on the same question could easily account for a search more or less.

*The grounding check has a blind spot.* Verifying that cited articles appear in retrieved
passages does not catch content loss *within* a retained citation — the чл. 145 case above.
Checking claims against passage text, rather than citations against a retrieved set, would be
a stronger test.

*The citation panel shows what was retrieved, not what the answer rests on.* The severance case
(§6) is the sharp version: the chat answer's severance figures came from the model's own knowledge,
чл. 97 was never retrieved, and the panel gave no indication. This is the **same class of gap** as
the чл. 145 blind spot — a check on *citations against a retrieved set* passes while the *claims
themselves* go unsourced. The same fix would catch both: verify claims against passage text, not
citations against the retrieved set. Neither is currently tested for.

*The strict grounding prompt costs a search on pure-reasoning follow-ups.* Asked which of
three already-cited deadlines is shortest, the agent re-retrieves rather than reasoning over
the answer. That is a deliberate trade-off, but the two cases — reasoning over already-cited
material versus asserting new facts — could be distinguished.

*The injection tests used conspicuous meta-instructions.* A subtler payload phrased as
ordinary legal reasoning has not been tried. The fixture was left in place for exactly this.
