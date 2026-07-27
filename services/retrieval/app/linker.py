"""Entity normalization, graph-node indexing, and question -> entity linking.

`normalize_entity` is a verbatim port of the reference notebook
(`bart_eval_with_coref.ipynb`) and is the single normalization authority for
both index construction and question linking. The notebook's
`find_entity_node` did a linear scan over all graph nodes per lookup;
`EntityIndex` precomputes the same semantics (exact match wins, otherwise the
shortest node name containing the query) behind a dict + length-sorted list.

The notebook received entities directly; linking them out of a free-text
question is new logic: a spaCy NER pass first, with an n-gram scan over the
normalized question as fallback when NER finds nothing that links.
"""

import re
import unicodedata
from dataclasses import dataclass
from typing import Callable, Protocol

import networkx as nx

STOPWORDS = frozenset(
    "a an the is are was were who whom whose what when where which why of in "
    "on for to and or how did does do it its this that with by as at from be "
    "been being have has had can could would should will "
    # Conversational pronouns and greetings: linking these to graph nodes turns
    # small talk ("how are you", "tell me") into irrelevant fact dumps.
    "you your yours me my mine we us our ours i he him his she her hers they "
    "them their theirs hello hi hey thanks thank please yes no okay ok "
    "something anything someone anyone tell say".split()
)


def normalize_entity(text: str | None) -> str:
    if text is None:
        return ""
    text = unicodedata.normalize("NFKC", str(text))
    text = text.lower()
    text = re.sub(r"<pad>", "", text)
    text = re.sub(r"</?s>", "", text)
    text = re.sub(r"\(.*?\)", "", text)
    text = re.sub(r'"|\'|`', "", text)
    text = re.sub(r"\s+", " ", text)
    text = re.sub(r"[^\w\s\-]", "", text)
    return text.strip()


class EntityIndex:
    """Normalized node-name index with the notebook's matching semantics."""

    def __init__(self, graph: nx.MultiGraph):
        self.exact: dict[str, str] = {}
        entries: list[tuple[str, str]] = []
        for node in graph.nodes():
            norm = normalize_entity(node)
            if not norm:
                continue
            self.exact.setdefault(norm, node)
            entries.append((norm, node))
        entries.sort(key=lambda entry: len(entry[0]))
        self.by_length = entries

    def find_exact(self, name: str) -> str | None:
        norm = normalize_entity(name)
        return self.exact.get(norm) if norm else None

    def find(self, name: str) -> str | None:
        norm = normalize_entity(name)
        if not norm:
            return None
        exact_hit = self.exact.get(norm)
        if exact_hit is not None:
            return exact_hit
        for norm_node, node in self.by_length:
            if norm in norm_node:
                return node
        return None


@dataclass(frozen=True)
class LinkedEntity:
    node_id: str
    surface: str
    source: str  # "ner" | "ngram"
    match: str  # "exact" | "containment"


class SupportsEnts(Protocol):
    ents: tuple


class QuestionLinker:
    """Links graph entities mentioned in a question: spaCy NER first, n-grams as fallback."""

    def __init__(
        self,
        nlp: Callable[[str], SupportsEnts],
        index: EntityIndex,
        max_ngram: int = 5,
        max_entities: int = 3,
    ):
        self.nlp = nlp
        self.index = index
        self.max_ngram = max_ngram
        self.max_entities = max_entities

    def link(self, question: str) -> list[LinkedEntity]:
        linked = self._link_ner(question)
        if linked:
            return linked
        return self._link_ngrams(question)

    def _link_ner(self, question: str) -> list[LinkedEntity]:
        linked: list[LinkedEntity] = []
        seen: set[str] = set()
        for ent in self.nlp(question).ents:
            node = self.index.find(ent.text)
            if node is None or node in seen:
                continue
            match = "exact" if self.index.find_exact(ent.text) == node else "containment"
            seen.add(node)
            linked.append(LinkedEntity(node, ent.text, "ner", match))
            if len(linked) >= self.max_entities:
                break
        return linked

    def _link_ngrams(self, question: str) -> list[LinkedEntity]:
        tokens = normalize_entity(question).split()
        if not tokens:
            return []

        linked: list[LinkedEntity] = []
        seen: set[str] = set()
        consumed: set[int] = set()

        # Exact pass: greedy longest-span-first, non-overlapping.
        for n in range(min(self.max_ngram, len(tokens)), 0, -1):
            for start in range(len(tokens) - n + 1):
                positions = range(start, start + n)
                if any(p in consumed for p in positions):
                    continue
                words = tokens[start : start + n]
                if all(w in STOPWORDS for w in words):
                    continue
                if n == 1 and len(words[0]) < 3:
                    continue
                span = " ".join(words)
                node = self.index.find_exact(span)
                if node is None or node in seen:
                    continue
                seen.add(node)
                consumed.update(positions)
                linked.append(LinkedEntity(node, span, "ngram", "exact"))
                if len(linked) >= self.max_entities:
                    return linked
        if linked:
            return linked

        # Containment pass, only for multi-token spans: single tokens contained
        # in 122k node names produce far too many false positives.
        for n in range(min(self.max_ngram, len(tokens)), 1, -1):
            for start in range(len(tokens) - n + 1):
                words = tokens[start : start + n]
                if all(w in STOPWORDS for w in words):
                    continue
                span = " ".join(words)
                node = self.index.find(span)
                if node is None or node in seen:
                    continue
                seen.add(node)
                linked.append(LinkedEntity(node, span, "ngram", "containment"))
                if len(linked) >= self.max_entities:
                    return linked
        return linked
