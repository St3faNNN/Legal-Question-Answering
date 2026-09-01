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

## As a service (the app's escalation path)

The same loop also runs as a small HTTP service, which is how the web app reaches
it — a user escalates one chat answer and the agent re-answers the question.

```bash
AGENT_SERVICE_TOKEN=<secret> node src/server.ts    # listens on :5060
curl -N -X POST http://localhost:5060/run \
  -H "Authorization: Bearer <secret>" -H 'Content-Type: application/json' \
  -d '{"question":"Кои се основите за отказ поради технолошки вишок?"}'
```

`POST /run` streams **NDJSON** as the run happens, so the caller can show each
search while it is being issued rather than waiting ~7s behind a spinner:

```
{"type":"search","step":0,"tool":"search_law","query":"технолошки вишок отказ испратнина"}
{"type":"result","step":0,"tool":"search_law","sources":["ZAKON-ZA-RABOTNI, чл. 97 ст. (1)-(5)", …]}
{"type":"done","runId":"…","outcome":"answered","answer":"…","totals":{…},"passages":[…],"steps":[…]}
```

It is a **transport around the CLI, not a second implementation**: same
`runAgent`, same prompt, same tools, same trace written to `runs/`. `agent.ts`
did not change to make this work — `Trace` grew an optional progress listener,
and that is all.

In Docker it is an opt-in service (`docker compose --profile agent up -d`) on
`node:24-alpine` with the source bind-mounted: zero runtime dependencies means
there is nothing to install or build. Its runs land in `runs/` on the host, so a
run started from the web app opens in the inspector below.

| Variable | Meaning | Default |
| --- | --- | --- |
| `AGENT_SERVICE_TOKEN` | bearer secret `/run` requires; unset = refuse everything | — |
| `AGENT_SERVICE_PORT` | listen port | `5060` |

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
