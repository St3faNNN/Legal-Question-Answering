# Legal research agent

A hand-written, multi-hop legal research agent over the app's retrieval service.
It answers questions the single-shot chat pipeline cannot — ones that need
several searches and a synthesis, e.g.:

> Договорниот орган спроведе постапка со преговарање без објавување оглас.
> Кои се последиците и кои рокови важат?

The agent searches for the obligation, then separately for the deadlines and
penalty provisions, then writes a cited answer.

## Why it looks like this

It is a **learning project about agent orchestration from first principles**, so:

- **No framework.** The loop is written by hand in [`src/agent.ts`](src/agent.ts).
  The four things that actually break in a tool-calling loop — appending the
  assistant message before its tool results, matching `tool_call_id`, turning
  tool failures into tool results instead of exceptions, and a step budget — are
  explicit and commented there.
- **Zero runtime dependencies.** It runs on Node's native TypeScript
  (type-stripping, Node ≥ 23.6), using only built-ins (`fetch`, `node:http`,
  `node:fs`). There is nothing to `npm install` to run it. The code is written
  in *erasable* TypeScript; `tsconfig.json` enforces that (`erasableSyntaxOnly`).

The existing app is a set of **tools it calls over HTTP**
(`POST /api/v1/tools/search-law` and `/tools/follow-references`), not a codebase it
merges into.

> Design notes and what the measurements showed live in
> [`agent-documentation.md`](agent-documentation.md).

## Requirements

- **Node ≥ 23.6** (native `.ts` execution). This machine has v24.
- The **Laravel app running** with `AI_GENERATOR=openai_compatible` and the
  **retrieval service up** (Docker), so `search-law` returns real passages.
- **Gemini credentials** — the same `LLM_*` the app uses.

## Configuration

Read from the repo-root `.env` (overlaid by real environment variables):

| Variable | Meaning | Default |
| --- | --- | --- |
| `LLM_BASE_URL` | OpenAI-compatible base URL (Gemini compat layer) | — (required) |
| `LLM_API_KEY` | Gemini API key | — (required) |
| `LLM_MODEL` | model id | `gemini-2.5-flash-lite` |
| `AGENT_APP_URL` | app base URL serving the tool endpoint | `APP_URL`, else `http://localhost:8000` |
| `AGENT_API_TOKEN` | Sanctum token for the tool endpoint | — (required) |
| `AGENT_MAX_STEPS` | step budget | `8` |

Mint a token with the app's existing `createToken` (no new command was added):

```bash
php artisan tinker --execute 'echo App\Models\User::first()->createToken("agent")->plainTextToken;'
# then, in the repo-root .env:  AGENT_API_TOKEN=<paste>
```

## Run

```bash
cd agent
node src/main.ts "Договорниот орган спроведе постапка со преговарање без објавување оглас. Кои се последиците и кои рокови важат?"
# optional: --max-steps 6   (or AGENT_MAX_STEPS=6)
```

Operational lines print to stderr; the final answer prints to stdout. Each run
writes a full trace to `agent/runs/<runId>.json` and prints its id.

### Multi-turn sessions

Pass `--session <id>` to keep a conversation across invocations — follow-ups then
resolve references to the earlier exchange (*"those deadlines"*, *"the same law"*):

```bash
node src/main.ts --session matter-42 "…first question…"
node src/main.ts --session matter-42 "и што ако не објавил известување?"
```

State is one JSON transcript per session under `agent/sessions/<id>.json` (a file,
not Redis — the agent is a zero-dependency local CLI). It resumes from a fresh
process, expires lazily (`AGENT_SESSION_TTL_HOURS`, default 72), and **rejects** a
second turn while one is running rather than racing.

## Inspect a run

```bash
node src/inspector.ts          # serves http://localhost:5055
```

Open the URL, enter a run id (or click a recent one). Each step shows the
model's tool calls, the tool result **collapsed and expandable** (passages are
long), elapsed time per step, and cumulative tokens. The question it is built to
answer: *did the agent decompose the query into several searches, or search once
and answer?*

## Cost

One Gemini call **per step**. A well-decomposed run is 2 searches + 1 synthesis ≈
**3 Gemini calls**; the ceiling is `maxSteps + 1`. The `search_law` calls hit
**local retrieval**, not Gemini, so they cost no Gemini quota. Lower
`--max-steps` to cap spend.

## Develop

```bash
node --test src/agent.test.ts                 # loop mechanics, no network
npx -p typescript tsc --noEmit -p tsconfig.json   # typecheck (uses the repo's TypeScript)
```
