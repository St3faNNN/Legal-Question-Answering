"""Prompt construction, selected by PROMPT_STYLE.

Three styles are supported:

- "qa" (production default with the instruction-tuned stand-in model, e.g.
  LaMini-Flan-T5): a question-answering prompt that grounds the answer in the
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
    """Builds the model input for one of three styles:

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
    raise ValueError(f"Unknown prompt style: {style!r} (expected 'clue', 'qa', or 'unifiedqa')")
