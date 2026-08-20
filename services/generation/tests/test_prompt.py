import pytest

from app.prompt import (
    CHAT_SYSTEM,
    CLUE_NO_CONTEXT,
    CLUE_WITH_CONTEXT,
    QA_NO_CONTEXT,
    QA_WITH_CONTEXT,
    build_messages,
    build_prompt,
)


def test_context_template_used_when_context_present():
    prompt = build_prompt("Who is Einstein?", "albert einstein developed relativity", "albert einstein")
    assert prompt == CLUE_WITH_CONTEXT.format(
        subject="albert einstein", context="albert einstein developed relativity"
    )
    assert "Supporting knowledge:\nalbert einstein developed relativity" in prompt


def test_no_context_template_when_context_blank():
    for blank in ["", "   ", "\n"]:
        prompt = build_prompt("Who is Einstein?", blank, "albert einstein")
        assert prompt == CLUE_NO_CONTEXT.format(subject="albert einstein")
        assert "Supporting knowledge" not in prompt


def test_entity_fills_subject_slot():
    prompt = build_prompt("Who developed relativity?", "some context", "albert einstein")
    assert "Correct response:\nalbert einstein\n" in prompt


def test_question_is_fallback_subject_when_no_entity():
    prompt = build_prompt("Who developed relativity?", "some context", None)
    assert "Correct response:\nWho developed relativity?\n" in prompt


def test_prompt_shape_matches_notebook():
    prompt = build_prompt("q", "ctx", "subject")
    assert prompt.startswith("\nYou are an expert Jeopardy clue writer.\n")
    assert prompt.endswith("Clue:\n")
    assert "- Use only the supporting knowledge." in prompt


def test_braces_in_context_are_literal():
    prompt = build_prompt("q", "weird {braces} in context", "subject")
    assert "weird {braces} in context" in prompt


def test_qa_style_uses_question_and_context():
    prompt = build_prompt("Who is Einstein?", "einstein developed relativity", "albert einstein", style="qa")
    assert prompt == QA_WITH_CONTEXT.format(question="Who is Einstein?", context="einstein developed relativity")
    assert "albert einstein" not in prompt  # entity is ignored in qa style
    assert prompt.endswith("Answer:\n")


def test_qa_style_without_context():
    prompt = build_prompt("Who is Einstein?", "  ", None, style="qa")
    assert prompt == QA_NO_CONTEXT.format(question="Who is Einstein?")
    assert "Supporting knowledge" not in prompt


def test_unknown_style_raises():
    with pytest.raises(ValueError, match="Unknown prompt style"):
        build_prompt("q", "ctx", None, style="haiku")


def test_qa_style_prepends_history():
    history = [
        {"role": "user", "content": "Say something about Albert Einstein"},
        {"role": "assistant", "content": "He developed relativity."},
    ]
    prompt = build_prompt("Where was he born?", "einstein born_in ulm", None, style="qa", history=history)
    assert prompt.startswith(
        "Previous conversation:\n"
        "User: Say something about Albert Einstein\n"
        "Assistant: He developed relativity.\n\n"
    )
    assert "Question:\nWhere was he born?" in prompt


def test_qa_style_without_history_is_unchanged():
    assert build_prompt("q?", "ctx", None, style="qa", history=[]) == build_prompt("q?", "ctx", None, style="qa")


def test_unifiedqa_format_is_lowercased_question_newline_context():
    prompt = build_prompt("Where was Einstein born?", "einstein born_in Ulm", None, style="unifiedqa")
    assert prompt == "where was einstein born? \\n einstein born_in ulm"


def test_unifiedqa_folds_history_into_context():
    history = [{"role": "user", "content": "Tell me about Einstein"}]
    prompt = build_prompt("Where was he born?", "einstein born_in ulm", None, style="unifiedqa", history=history)
    assert prompt.startswith("where was he born? \\n previous conversation: user: tell me about einstein")
    assert prompt.endswith("| einstein born_in ulm")


def test_unifiedqa_without_context_or_history():
    prompt = build_prompt("Who is Einstein?", "", None, style="unifiedqa")
    assert prompt == "who is einstein? \\n no information available"


# --- chat style (Macedonian legal QA for an instruction-tuned chat model) -----


def test_chat_messages_lead_with_the_system_instruction():
    messages = build_messages("Колку трае годишниот одмор?", "[ZAKON-A, чл. 2]\nГодишен одмор")

    assert messages[0] == {"role": "system", "content": CHAT_SYSTEM}
    assert messages[-1]["role"] == "user"


def test_chat_system_demands_grounded_macedonian_answers():
    assert "користејќи ги дадените извори" in CHAT_SYSTEM
    assert "на македонски јазик" in CHAT_SYSTEM
    assert "информацијата не е достапна" in CHAT_SYSTEM


def test_chat_system_bounds_answer_length():
    """Answer length is answer latency at ~0.55s per token on CPU. Without an
    explicit sentence limit the model enumerated until it hit the token cap and
    was truncated mid-word."""
    assert "најмногу три реченици" in CHAT_SYSTEM
    assert "без набројување" in CHAT_SYSTEM


def test_chat_system_stays_short_enough_for_a_small_model():
    """A longer rule list — specifically one demanding a bracketed citation per
    statement — made Qwen2.5-0.5B emit a lone citation instead of an answer."""
    assert len(CHAT_SYSTEM.splitlines()) <= 5


def test_chat_user_message_carries_context_and_question():
    messages = build_messages("Колку трае одморот?", "[ZAKON-A, чл. 2]\nГодишен одмор")

    content = messages[-1]["content"]
    assert "Извори:" in content
    assert "[ZAKON-A, чл. 2]" in content
    assert "Прашање: Колку трае одморот?" in content


def test_chat_without_context_says_so_rather_than_inventing():
    messages = build_messages("Здраво", "   ")

    content = messages[-1]["content"]
    assert "Нема пронајдени извори" in content
    assert "Извори:" not in content


def test_chat_history_is_interleaved_before_the_question():
    history = [
        {"role": "user", "content": "Што е јавна набавка?"},
        {"role": "assistant", "content": "Договор склучен во писмена форма."},
    ]
    messages = build_messages("А кој ја спроведува?", "контекст", history)

    assert [message["role"] for message in messages] == ["system", "user", "assistant", "user"]
    assert messages[1]["content"] == "Што е јавна набавка?"
    assert "А кој ја спроведува?" in messages[-1]["content"]


def test_chat_without_history_is_system_plus_question():
    assert len(build_messages("Прашање?", "контекст", [])) == 2


def test_braces_in_context_are_literal_in_chat_style():
    messages = build_messages("q", "weird {braces} in context")

    assert "weird {braces} in context" in messages[-1]["content"]
