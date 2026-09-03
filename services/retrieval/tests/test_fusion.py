from app.fusion import Candidate, pack_context, reciprocal_rank_fusion


def candidate(key: str, text: str = "", citation: str = "") -> Candidate:
    return Candidate(
        key=key,
        text=text or f"text of {key}",
        unit_key=f"zakon:{key}",
        citation=citation,
        chunk_id=int(key),
    )


def test_fusion_of_one_ranking_preserves_its_order():
    ranking = [candidate("1"), candidate("2"), candidate("3")]

    fused = reciprocal_rank_fusion({"dense": ranking})

    assert [item.key for item in fused] == ["1", "2", "3"]


def test_candidate_in_both_rankings_outranks_one_that_leads_a_single_ranking():
    """The point of RRF: agreement between retrievers beats a single strong hit."""
    fused = reciprocal_rank_fusion(
        {
            "dense": [candidate("1"), candidate("2")],
            "sparse": [candidate("3"), candidate("2")],
        },
        k=60,
    )

    assert fused[0].key == "2"
    assert fused[0].sources == ["dense", "sparse"]


def test_fusion_scores_follow_the_rrf_formula():
    fused = reciprocal_rank_fusion({"dense": [candidate("1"), candidate("2")]}, k=60)

    assert fused[0].fused_score == 1 / 61
    assert fused[1].fused_score == 1 / 62


def test_fusion_deduplicates_by_key():
    fused = reciprocal_rank_fusion(
        {"dense": [candidate("1")], "sparse": [candidate("1")], "graph": [candidate("1")]}
    )

    assert len(fused) == 1
    assert fused[0].sources == ["dense", "sparse", "graph"]


def test_fusion_respects_the_limit():
    ranking = [candidate(str(i)) for i in range(1, 11)]

    assert len(reciprocal_rank_fusion({"dense": ranking}, limit=3)) == 3


def test_fusion_of_nothing_is_empty():
    assert reciprocal_rank_fusion({}) == []
    assert reciprocal_rank_fusion({"dense": []}) == []


def test_fused_candidates_are_copies_not_the_inputs():
    """Reranking mutates the fused candidates; the source rankings must not
    change underneath a caller that still holds them."""
    original = candidate("1")
    fused = reciprocal_rank_fusion({"dense": [original]})


def test_pack_context_joins_in_rank_order_with_citations():
    context, used = pack_context(
        [candidate("1", text="прв текст", citation="A, чл. 1"), candidate("2", text="втор текст", citation="A, чл. 2")],
        char_budget=1000,
    )

    assert used == 2
    assert context == "[A, чл. 1]\nпрв текст\n\n[A, чл. 2]\nвтор текст"


def test_pack_context_stops_at_the_budget_and_reports_the_count():
    candidates = [candidate(str(i), text="x" * 100) for i in range(1, 6)]

    context, used = pack_context(candidates, char_budget=250)

    assert used == 2
    assert len(context) <= 250


def test_pack_context_keeps_scanning_past_an_oversized_candidate():
    """A single huge passage must not discard every shorter one ranked below it."""
    candidates = [
        candidate("1", text="x" * 500),
        candidate("2", text="short"),
    ]

    context, used = pack_context(candidates, char_budget=100)

    assert used == 1
    assert "short" in context


def test_pack_context_does_not_repeat_a_citation_already_in_the_text():
    text = "A, чл. 1 — текстот на членот"
    context, _used = pack_context([candidate("1", text=text, citation="A, чл. 1")], char_budget=1000)

    assert context == text


def test_pack_context_without_citation_uses_bare_text():
    context, used = pack_context([candidate("1", text="само текст")], char_budget=1000)

    assert context == "само текст"
    assert used == 1


def test_pack_context_of_nothing_is_empty():
    assert pack_context([], char_budget=1000) == ("", 0)
    assert pack_context([candidate("1")], char_budget=0) == ("", 0)
