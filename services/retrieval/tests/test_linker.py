from dataclasses import dataclass

import pytest

from app.linker import EntityIndex, QuestionLinker, normalize_entity


@dataclass
class StubEnt:
    text: str


@dataclass
class StubDoc:
    ents: tuple


def stub_nlp_returning(*ent_texts: str):
    return lambda text: StubDoc(ents=tuple(StubEnt(t) for t in ent_texts))


@pytest.mark.parametrize(
    ("raw", "expected"),
    [
        ("Albert Einstein", "albert einstein"),
        ("  Marie   Curie  ", "marie curie"),
        ("Marie Curie (physicist)", "marie curie"),
        ('"Quoted" `name\'s`', "quoted names"),
        ("<pad>text</s><s>", "text"),
        ("Jean-Paul Sartre!", "jean-paul sartre"),
        ("Café Résumé?", "café résumé"),
        (None, ""),
    ],
)
def test_normalize_entity(raw, expected):
    assert normalize_entity(raw) == expected


class TestEntityIndex:
    def test_exact_match_wins_over_containment(self, fixture_graph):
        index = EntityIndex(fixture_graph)
        assert index.find("Albert Einstein") == "albert einstein"

    def test_containment_returns_shortest_node(self, fixture_graph):
        fixture_graph.add_node("albert einstein institute berlin")
        index = EntityIndex(fixture_graph)
        assert index.find("einstein") == "albert einstein"

    def test_parenthetical_normalized_at_index_time(self, fixture_graph):
        index = EntityIndex(fixture_graph)
        assert index.find_exact("Marie Curie") == "marie curie (physicist)"

    def test_no_match_returns_none(self, fixture_graph):
        index = EntityIndex(fixture_graph)
        assert index.find("zzz unknown zzz") is None
        assert index.find_exact("") is None


class TestQuestionLinker:
    def test_ner_pass_links_entity(self, fixture_graph):
        index = EntityIndex(fixture_graph)
        linker = QuestionLinker(stub_nlp_returning("Albert Einstein"), index)
        linked = linker.link("Who is Albert Einstein?")
        assert [e.node_id for e in linked] == ["albert einstein"]
        assert linked[0].source == "ner"
        assert linked[0].match == "exact"

    def test_ngram_fallback_when_ner_finds_nothing(self, fixture_graph):
        index = EntityIndex(fixture_graph)
        linker = QuestionLinker(stub_nlp_returning(), index)
        linked = linker.link("Who developed the theory of relativity?")
        assert [e.node_id for e in linked] == ["theory of relativity"]
        assert linked[0].source == "ngram"

    def test_ngram_prefers_longest_span(self, fixture_graph):
        fixture_graph.add_node("relativity")
        index = EntityIndex(fixture_graph)
        linker = QuestionLinker(stub_nlp_returning(), index)
        linked = linker.link("Explain the theory of relativity")
        assert linked[0].node_id == "theory of relativity"

    def test_stopword_only_spans_skipped(self, fixture_graph):
        fixture_graph.add_node("the who")  # would match "the who" if stopwords were allowed
        index = EntityIndex(fixture_graph)
        linker = QuestionLinker(stub_nlp_returning(), index)
        assert linker.link("who did what to whom") == []

    def test_no_match_returns_empty_list(self, fixture_graph):
        index = EntityIndex(fixture_graph)
        linker = QuestionLinker(stub_nlp_returning(), index)
        assert linker.link("completely unrelated gibberish xyzzy") == []

    def test_max_entities_cap(self, fixture_graph):
        index = EntityIndex(fixture_graph)
        linker = QuestionLinker(
            stub_nlp_returning("Albert Einstein", "Isaac Newton", "Ulm", "Germany"),
            index,
            max_entities=2,
        )
        assert len(linker.link("many entities")) == 2

    def test_ner_result_suppresses_ngram_pass(self, fixture_graph):
        index = EntityIndex(fixture_graph)
        linker = QuestionLinker(stub_nlp_returning("Isaac Newton"), index)
        linked = linker.link("Did Isaac Newton influence Albert Einstein?")
        assert [e.node_id for e in linked] == ["isaac newton"]
