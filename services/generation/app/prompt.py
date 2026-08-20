"""Prompt construction, selected by PROMPT_STYLE.

Four styles are supported:

- "chat" (production default): a Macedonian legal-QA instruction for an
  instruction-tuned chat model (Qwen2.5-Instruct). Unlike the styles below it
  produces a *message list* via `build_messages`, which the engine renders with
  the model's own chat template — a chat model given a raw string prompt answers
  noticeably worse than one given its template.
- "qa" (the instruction-tuned seq2seq stand-in, e.g. LaMini-Flan-T5): a
  question-answering prompt that grounds the answer in the
  retrieved knowledge and falls back to a natural reply when the knowledge is
  irrelevant (small talk). Conversation history is prepended so follow-up
  questions can resolve references.
- "clue": the notebook-faithful Jeopardy clue-writer prompt for the fine-tuned
  BART checkpoint (`bart_eval_with_coref.ipynb`). The CLUE_* templates are
  byte-identical to the notebook's, including leading/trailing newlines. The
  `Correct response:` slot is filled with the linked entity (the distribution
  the checkpoint was trained on), or the raw question when nothing linked.
- "unifiedqa": the allenai/unifiedqa input convention (lowercased
  `question \\n context`).
"""

import os
from pathlib import Path

CLUE_WITH_CONTEXT = """
You are an expert Jeopardy clue writer.

Correct response:
{subject}

Supporting knowledge:
{context}

Task:
Write one Jeopardy-style clue whose correct response is the entity above.

Rules:
- Do NOT mention or reveal the answer.
- Use only the supporting knowledge.
- Make the clue concise, natural, and factually accurate.
- Return only the clue.

Clue:
"""

CLUE_NO_CONTEXT = """
You are an expert Jeopardy clue writer.

Correct response:
{subject}

Task:
Write one Jeopardy-style clue whose correct response is the entity above.

Rules:
- Do NOT mention or reveal the answer.
- Make the clue concise, natural, and factually accurate.
- Return only the clue.

Clue:
"""


QA_WITH_CONTEXT = """Answer the question using the supporting knowledge. If the knowledge is not relevant, answer naturally instead.

Question:
{question}

Supporting knowledge:
{context}

Answer:
"""

QA_NO_CONTEXT = """Answer the question.

Question:
{question}

Answer:
"""


#: Deliberately short. An earlier version added a fourth rule demanding a
#: bracketed citation after every statement; a small instruction model (Qwen2.5
#: 0.5B) responded by emitting a lone garbled citation and stopping. Each
#: instruction here earns its place — the citations are already inline in the
#: context blocks, so the model reproduces them without being ordered to.
#:
#: The sentence limit is a latency control, not a style preference. CPU decoding
#: costs ~0.55s per token, so answer length *is* answer latency: asking merely
#: for a "short" answer produced a five-point enumeration that ran into the
#: 256-token cap and was truncated mid-word after 146s.
#:
#: The text lives in a single file at the repo root (prompts/legal_mk.txt) so the
#: PHP OpenAI-compatible generation driver serves the *same* system prompt — two
#: runtimes, one source of truth, no silent drift. The file's conventional
#: trailing newline is stripped so this value is byte-identical to the original
#: inline literal.
def _load_chat_system() -> str:
    here = Path(__file__).resolve()
    candidates: list[Path] = []

    override = os.environ.get("LEGAL_PROMPT_PATH")
    if override:
        candidates.append(Path(override))

    # Repo layout (tests, local dev): app/prompt.py -> <repo>/prompts/... The
    # index is guarded: in the container the file is /srv/app/prompt.py, which
    # has no parents[3], and indexing it unconditionally raised IndexError at
    # import — before the override below was ever consulted.
    if len(here.parents) > 3:
        candidates.append(here.parents[3] / "prompts" / "legal_mk.txt")

    # Container mount (docker-compose.yml maps ./prompts -> /prompts:ro).
    candidates.append(Path("/prompts/legal_mk.txt"))

    for path in candidates:
        if path.is_file():
            return path.read_text(encoding="utf-8").rstrip("\n")

    raise FileNotFoundError(
        "Macedonian legal system prompt not found. Set LEGAL_PROMPT_PATH, or "
        "provide prompts/legal_mk.txt at the repository root."
    )


CHAT_SYSTEM = _load_chat_system()

CHAT_USER_WITH_CONTEXT = """Извори:
{context}

Прашање: {question}"""

CHAT_USER_NO_CONTEXT = """Прашање: {question}

(Нема пронајдени извори во документите.)"""


def build_messages(
    question: str,
    context: str,
    history: list[dict] | None = None,
) -> list[dict]:
    """The chat-style message list for an instruction-tuned chat model.

    Returned rather than rendered because the rendering is the model's own chat
    template, which lives on the tokenizer — see `GenerationEngine.apply_chat_template`.
    """
    messages: list[dict] = [{"role": "system", "content": CHAT_SYSTEM}]

    for message in history or []:
        role = "user" if message["role"] == "user" else "assistant"
        messages.append({"role": role, "content": message["content"]})

    template = CHAT_USER_WITH_CONTEXT if context.strip() else CHAT_USER_NO_CONTEXT
    messages.append({"role": "user", "content": template.format(question=question, context=context)})
    return messages


def _history_block(history: list[dict] | None) -> str:
    if not history:
        return ""
    lines = [
        f"{'User' if message['role'] == 'user' else 'Assistant'}: {message['content']}"
        for message in history
    ]
    return "Previous conversation:\n" + "\n".join(lines) + "\n\n"


def build_prompt(
    question: str,
    context: str,
    entity: str | None,
    style: str = "clue",
    history: list[dict] | None = None,
) -> str:
    """Builds the string model input for one of three seq2seq styles.

    The "chat" style is deliberately absent: it produces messages, not a string.
    Callers select `build_messages` for it — see the module docstring.

    - "clue": the notebook-faithful Jeopardy template for the fine-tuned BART
      checkpoint. Ignores `history` (the checkpoint never saw conversations).
    - "qa": instruction-style question answering for models like FLAN-T5;
      ignores `entity`, prepends the conversation history when present.
    - "unifiedqa": the allenai/unifiedqa input convention — lowercased
      `question \\n context` with no instruction text; history is folded into
      the context so follow-up questions can resolve references.
    """
    has_context = bool(context.strip())
    if style == "qa":
        prompt = (
            QA_WITH_CONTEXT.format(question=question, context=context)
            if has_context
            else QA_NO_CONTEXT.format(question=question)
        )
        return _history_block(history) + prompt if history else prompt
    if style == "unifiedqa":
        parts = []
        if history:
            parts.append(
                "previous conversation: "
                + " ".join(f"{m['role']}: {m['content']}" for m in history)
            )
        if has_context:
            parts.append(context)
        joined = " | ".join(parts) if parts else "no information available"
        return f"{question} \\n {joined}".lower()
    if style == "clue":
        subject = entity or question
        if has_context:
            return CLUE_WITH_CONTEXT.format(subject=subject, context=context)
        return CLUE_NO_CONTEXT.format(subject=subject)
    raise ValueError(
        f"Unknown prompt style: {style!r} (expected 'chat', 'clue', 'qa', or 'unifiedqa')"
    )
