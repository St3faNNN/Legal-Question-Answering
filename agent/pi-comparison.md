# Pi, read against the hand-written loop

A source reading of Pi against `agent/src/agent.ts`, to understand what a framework
abstracts — not a porting plan. `agent.ts` is unchanged and stays that way.

---

## What I actually read

`@earendil-works/pi-ai` is **not** the agent. Its own `package.json` describes it as
*"Unified LLM API with automatic model discovery and provider configuration"*, and its
`dist/` contains no loop: grepping the type definitions for `maxSteps`, `tool_call`, or a
turn driver returns nothing. It is the provider layer only.

The loop lives two packages away. `pi-coding-agent`'s `package.json` names its
dependencies, which is how I found them:

| Package | v | What it holds |
|---|---|---|
| `@earendil-works/pi-ai` | 0.84.4 | Provider adapters, message/tool types, streaming, validation |
| `@earendil-works/pi-agent-core` | 0.84.4 | **`dist/agent-loop.js` — the actual loop** (550 lines), session store, compaction |
| `@earendil-works/pi-coding-agent` | 0.84.4 | `AgentSession` (125 KB), extensions, four modes, CLI, docs |

Everything below cites those tarballs. Where I could not read something I say so rather
than paraphrasing the docs.

For scale: my whole agent is **1,886 lines** including tests and the inspector; the loop
itself (`agent/src/agent.ts`) is **104 lines**. Pi's `agent-loop.js` is 550 lines of
compiled output and is the smallest of its three layers.

---

## The four failure points

### 1. Appending the assistant message before its tool results

**Mine** — `agent.ts:63`, an explicit push before the tool loop.

**Pi** — structural, and more careful than mine. In
`pi-agent-core/dist/agent-loop.js`, `streamAssistantResponse()` pushes the *partial*
assistant message into `context.messages` on the `start` event and then **replaces it in
place** as deltas arrive:

```js
case "start":
    partialMessage = event.partial;
    context.messages.push(partialMessage);        // L203
    addedPartial = true;
...
case "toolcall_delta":
    context.messages[context.messages.length - 1] = partialMessage;   // L218
```

Only after `streamAssistantResponse` returns does `runLoop` push tool results
(`agent-loop.js` L143–145). The ordering cannot be got wrong by a caller because the
caller never touches the array.

**But `pi-ai` alone does not do this** — and its README demonstrates the bug. The
"Handling Tool Calls" example (README §526) does:

```typescript
const response = await models.complete(model, context);
for (const block of response.content) {
  if (block.type === 'toolCall') {
    context.messages.push({ role: 'toolResult', ... });   // assistant message never pushed
  }
}
```

`models.js` contains no `context.messages` mutation at all, so the assistant message is
genuinely missing from that context. If you use the provider layer without
`pi-agent-core`, you hit failure point 1 exactly as I did — the framework's own docs
walk you into it.

**Verdict:** Pi's *loop* is better than mine here. Pi's *SDK* is not; it has the same
trap and documents it wrongly.

### 2. Matching `tool_call_id` across parallel calls

**Mine** — `agent.ts:94`, `tool_call_id: call.id` on each result, with a comment about
parallel calls getting mispaired.

**Pi** — the pairing is carried in a typed field rather than a convention.
`pi-ai/dist/types.d.ts` defines `ToolResultMessage` with required `toolCallId` *and*
`toolName`, and `agent-loop.js`'s `createToolResultMessage()` (L~530) is the single
construction site:

```js
return {
    role: "toolResult",
    toolCallId: finalized.toolCall.id,
    toolName: finalized.toolCall.name,
    ...
};
```

The interesting part is parallel ordering. `executeToolCallsParallel()` (L330) pushes
*thunks* into `finalizedCalls`, resolves them with `Promise.all`, and only then builds
result messages in array order. So calls run concurrently but results are appended
deterministically in call order. My loop is strictly sequential (`for (const call of
toolCalls)`), which sidesteps the problem rather than solving it.

**Verdict:** Pi is better, and by a real margin — it gets concurrency *and*
deterministic ordering. Mine gets ordering by giving up concurrency.

### 3. Tool failures as tool results, not exceptions

**Mine** — `agent.ts:81-88`: `executeTool` returns `{ error }` for expected failures, and
a `catch` backstops the rest.

**Pi** — the same decision, made twice and typed. `executePreparedToolCall()`
(`agent-loop.js` L451) wraps `tool.execute` in try/catch and converts a throw:

```js
catch (error) {
    return {
        result: createErrorToolResult(error instanceof Error ? error.message : String(error)),
        isError: true,
    };
}
```

and `prepareToolCall()` (L391) returns an immediate error result for an unknown tool
rather than throwing. Crucially, `isError` is a **field on `ToolResultMessage`**, not a
convention buried in my JSON payload. That is strictly better: the transport carries the
error flag, so a provider that distinguishes error results can use it, and rendering code
does not have to sniff for an `error` key.

One inconsistency worth noting: `pi-ai`'s own `validateToolCall` helper is documented as
`@throws Error if tool is not found or validation fails`. The SDK's helper throws; the
loop catches. Again — the loop is right, the SDK layer alone is a trap.

**Verdict:** Pi is better. `isError` as a typed field is the thing I'd steal if I were
changing `agent.ts`, which I'm not.

### 4. The step budget

**Mine** — `agent.ts:44`, `for (let step = 0; step < maxSteps; step++)`, with
`finishExhausted()` synthesising an out-of-scope answer when the budget runs out.

**Pi — there isn't one.** Grepping both packages' type definitions for `maxSteps`,
`maxTurns`, `maxIterations`, `stepLimit` returns **nothing**. `runLoop` is
`while (true)` around `while (hasMoreToolCalls || pendingMessages.length > 0)`
(`agent-loop.js` L85–88). It exits when the model stops emitting tool calls, when a tool
batch sets `terminate`, or when the caller's optional `config.shouldStopAfterTurn(...)`
hook says so (L154).

That hook is the framework's answer, and it is a *delegation*, not a solution: Pi hands
you the same problem with a callback attached. For an interactive coding agent with a
human watching, unbounded is defensible — you can hit Ctrl-C. For my use case, where a
run is dispatched from a queue worker against a metered API, an unbounded loop is a
billing incident. My hard budget is the right call for what I built, and I would keep it
even if I ported everything else.

**Verdict:** Mine is better *for this project*. Pi's is better for an interactive tool.
This is the clearest case where the framework's default encodes its own use case rather
than a universal truth.

**One thing Pi does that I don't, in the same area:** when a message comes back with
`stopReason === "length"`, Pi fails *every* tool call in it rather than executing any
(L133–138, `failToolCallsFromTruncatedMessage`). The comment explains why: streamed tool
arguments are finalised with a best-effort JSON salvage parser, so a truncated message can
yield calls whose arguments *parse and validate* but are silently incomplete. My loop
would execute them. That is a real bug in mine that I had not considered — it is latent
rather than active, because `search_law` takes one short string and my `max_tokens` is
generous, but the reasoning is sound and the failure would be silent.

---

## The things I found by measurement

### Context compaction within a run

**Mine** — `compaction.ts`, 74 lines. Deduplicates passages by `source` (keeping text at
its most recent occurrence) and snippets superseded search results to 500 characters,
always preserving the citation. **Measured: 16% saving on a two-search run, zero on
single-search runs.**

**Pi compacts, but at a different altitude and for a different reason.**
`pi-agent-core/dist/harness/compaction/compaction.js` is threshold-triggered, not
content-aware:

- `estimateContextTokens(messages)` → `shouldCompact(contextTokens, contextWindow, settings)`
- `AgentSession._compactBeforeNextAssistantResponse()` (`agent-session.js` L274) checks it
  before each assistant turn and calls `_runAutoCompaction("threshold", false)`
- compaction finds turn-aligned cut points (`findTurnStartIndex`, `CutPointResult`) and
  **summarises the prefix with an LLM call** (`completeSimpleWithRetries` lives in the same
  module), replacing it with a `compaction_summary` entry

So: Pi waits until you approach the context window, then spends a model call to summarise
history. I never approach the window — a three-search run is ~25k tokens against a large
window — so Pi's compaction would **never fire on my workload**. Mine fires on every
multi-search run and costs nothing, because it is a pure transformation of structured tool
results I happen to know the shape of.

These are not competing designs; they solve different problems. Pi's is general (it knows
nothing about what a tool returned) and expensive (an extra model call). Mine is
domain-specific (it knows a `search_law` result is a list of `{source, text}`) and free.
A framework can't write mine, because mine depends on knowing that two searches returning
the same article is a duplication worth collapsing.

Pi does expose `config.transformContext(messages, signal)` (`agent-loop.js` L177) — an
arbitrary `AgentMessage[] → AgentMessage[]` hook applied before each LLM call. My
compaction would drop into it almost unchanged. That is the honest framework answer:
Pi gives me the seam, not the behaviour.

### Sessions

**Mine** — `session.ts`, 152 lines. One JSON file per session, carrying `{question,
answer, runId, citations, at}` per turn. Passages are deliberately dropped, because a
follow-up re-retrieves. Lock file to reject a concurrent turn, lazy TTL.

**Pi carries far more, and its session is a tree, not a list.**
`pi-agent-core/dist/harness/session/types.d.ts` has `parentId: string | null` on every
entry, `branch_summary` as an entry type, and queries scoped to `"branch"` or
"session-wide, all branches, sequence order". Storage is append-only JSONL
(`harness/session/jsonl/`), with an in-memory implementation and a **conformance test
suite** (`harness/session/testing/conformance.js`) so alternative stores can be validated
against the same contract.

What it carries: everything. Full assistant messages including thinking blocks, every tool
result, usage per turn, model per turn. Not configurable in the sense I chose — you don't
get to say "keep Q&A, drop passages". You get compaction (lossy, LLM-summarised, at a
threshold) and branch summarisation instead.

My choice is only available because I know follow-ups re-retrieve. Pi's coding agent
cannot assume that: if the model read a file three turns ago, that content may be the only
record. **Different domain, different correct answer.** Neither is configurable into the
other.

### Provider abstraction

**Mine** — `llm.ts`, 95 lines, one `fetch` to one OpenAI-compatible `/chat/completions`,
non-streaming on purpose, one 429 retry honouring `Retry-After`.

**Pi** — 85 provider files, 10 API dialects (`KnownApi` in `pi-ai/dist/types.d.ts`), ~40
named providers. The indirection chain is `StreamFunction → provider → api adapter`, and
the adapters are not thin: `api/openai-completions.js` is **64 KB**,
`anthropic-messages.js` 47 KB, `bedrock-converse-stream.js` 49 KB.

**What that buys** is visible in `utils/overflow.d.ts`, which is the best argument for the
abstraction I found in the whole package. It is a table of context-overflow error strings
per provider — Anthropic's `"prompt is too long: X tokens > Y maximum"`, Groq's `"reduce
the length of the messages"`, Cerebras returning a bare 413 with no body, z.ai sometimes
accepting overflow *silently* so you must compare `usage.input` against the context window,
Xiaomi MiMo truncating and returning `stopReason: "length"` with zero output. Fifteen-plus
providers, each with its own way of saying the same thing, and a documented "unreliable
detection" section for the ones that can't be detected properly at all.

That is knowledge you acquire by getting paged, not by reading specs. I have none of it and
would not want to write it.

**What it costs** is that every provider quirk is now in *my* dependency tree. I talk to
one endpoint. The 64 KB OpenAI adapter handles Codex WebSocket transport, prompt caching,
constrained sampling, deferred fetch, and session affinity formats — none of which I use.

**Verdict:** the abstraction is worth it the moment you have a second provider or need to
survive a provider outage by switching. At one provider, on a free-tier key, it is
overhead. But `isContextOverflow` alone would be worth vendoring if I ever hit that class
of failure.

---

## What Pi has that mine doesn't

**Tree-structured sessions.** `parentId` on every entry plus `branch_summary` entries and
`AgentSession.navigateTree()`. You can fork a conversation at any user message
(`getUserMessagesForForking()`), explore, and come back — with the abandoned branch
summarised rather than lost. For my use case this maps onto something real: "answer this
question, then answer it again with a different decomposition, and compare". I currently
do that by running the CLI twice and diffing traces by hand.

**Model switching mid-run.** `AgentSession.setModel()` and `cycleModel()`, and the loop's
`prepareNextTurn` hook returns `{ context, model, thinkingLevel }` applied to the *next*
turn (`agent-loop.js` L90–101). Concretely: run cheap searches on flash-lite, then switch
to a stronger model for the synthesis step. My escalation is all-or-nothing at one model.
This is the feature I'd most want.

**Extensions and packaging.** `core/extensions/{runner,loader}.js` (40 KB + 26 KB), a
package manager (`package-manager.js`, 88 KB), and hooks including `beforeToolCall` /
`afterToolCall` that can **block or rewrite a tool call** (`agent-session.js` L224–272).
My equivalent of a tool-call interceptor is editing `tools.ts`.

**Four modes.** `dist/modes/` has `interactive` (a TUI), `print-mode`, `json-event`, and
`rpc`, plus an SDK (`docs/sdk.md`). My agent has a CLI and the HTTP service I wrote for
the escalation path — which is, roughly, a hand-rolled version of the `rpc` mode.

## MCP and sub-agents: scoping, not limitation

The docs are explicit (`pi-coding-agent/docs/usage.md:309`):

> "It intentionally does not include built-in MCP, sub-agents, permission popups, plan
> mode, to-dos, or background bash. You can build or install those workflows as extensions
> or packages, or use external tools such as containers and tmux."

And the extension catalogue lists a `subagent/` extension that spawns sub-agents using
`registerTool` + `exec` (`docs/extensions.md:3002`), with `docs/sdk.md` describing building
tools that spawn sub-agents.

So it is a **scoping decision, and a coherent one**: the core is a loop plus a session
store plus provider adapters, and everything above that is an extension. Sub-agents are
not absent, they are un-privileged — a sub-agent is a tool that happens to run another
agent, with no special place in the loop.

**What that implies for small-LLM orchestration work.** It is the right shape. Sub-agents
as a framework primitive push you toward "spawn an agent" as the answer to every
decomposition problem, which is exactly the reflex my own measurements argued against —
the agent earns its cost on questions past a specific boundary, and is overhead everywhere
else. A framework that makes sub-agents *possible but not privileged* leaves that judgement
where it belongs. The absence of MCP matters more: it means tool integration is Pi's own
`registerTool`, so tools you write for Pi don't move to another host.

---

## Port, or not?

**What I'd gain**

- The `stopReason === "length"` truncated-tool-call guard — a real latent bug in mine.
- `isError` as a typed field rather than a convention inside my JSON.
- Parallel tool execution with deterministic result ordering.
- Model switching mid-run — cheap searches, expensive synthesis.
- Tree sessions, which would make "same question, two decompositions" a first-class
  operation instead of two CLI runs and a diff.
- ~40 providers and, more valuably, `isContextOverflow`'s hard-won per-provider knowledge.

**What I'd lose**

- **The point of the project.** `agent.ts` exists to demonstrate what a framework
  abstracts. Replacing it with `agentLoop()` deletes the artefact and leaves a
  `package.json` entry in its place.
- **Zero dependencies.** Today the agent runs on stock Node with `fetch` and `node:http`,
  which is why its Docker service is `node:24-alpine` plus a bind mount — no install, no
  build. Pi brings `pi-ai` + `pi-agent-core` + `pi-coding-agent` + `pi-client` +
  `pi-protocol` + `pi-tui` + `photon-node`. That is a real change to a deployment that
  currently has none.
- **The hard step budget.** I would have to re-implement it in `shouldStopAfterTurn` — not
  hard, but it is *my* invariant re-expressed in someone else's callback, and it would be
  the only thing standing between a looping model and a metered API.
- **My compaction.** It would survive as a `transformContext` hook, but it is 74 lines of
  domain knowledge that the framework contributes nothing to.

**Recommendation: don't port. Read it, steal three things.**

The framework is genuinely better at the mechanics — points 1, 2 and 3 are all handled more
carefully than mine, and the truncated-tool-call guard is something I'd simply missed. But
none of that is why my loop exists. It exists so that when someone asks "what does a
framework do for you?", the answer is a diff rather than an opinion — and this document is
that answer.

Three things worth taking without taking the dependency:

1. **Fail all tool calls on a `length` stop.** ~5 lines in `agent.ts`. It's a correctness
   fix, not a framework feature.
2. **Make the error flag structural** rather than an `{ error }` key inside the JSON payload,
   if the trace format is ever revised.
3. **Read `pi-ai/dist/utils/overflow.d.ts` before adding a second provider.** Not to copy
   the code — to know which failure modes exist.

For next month's work: if the target is *small-LLM orchestration* rather than *this
project*, Pi is a reasonable base, and its scoping instinct (thin core, everything else an
extension) matches how I'd want to build. The thing to keep from my own loop is the
budget — a framework designed around a human pressing Ctrl-C makes a different assumption
than a queue worker with a billing account.

---

*Read against `@earendil-works/pi-ai`, `pi-agent-core`, and `pi-coding-agent` @ 0.84.4,
fetched via `npm pack` into a scratch directory. No dependency was added to this project.*
