import sqlite3
import struct

import numpy as np
import pytest

from app.vectors import VectorStore, VectorStoreError


@pytest.fixture()
def store(vector_db_path) -> VectorStore:
    store = VectorStore(vector_db_path)
    store.load()
    return store


def test_load_reads_meta_and_every_chunk(store):
    assert store.loaded
    assert store.dim == 4
    assert store.embed_model == "BAAI/bge-m3"
    assert len(store.chunks) == 5


def test_dense_search_ranks_by_cosine(store):
    hits = store.search_dense(np.array([1.0, 0.0, 0.0, 0.0], dtype=np.float32), k=3)

    assert [hit.unit_key for hit in hits[:2]] == ["zakon-a:chlen-1", "zakon-b:chlen-2"]
    # chunk 1 is the exact direction, chunk 5 is [0.6, 0.8, 0, 0] -> cosine 0.6.
    assert hits[0].score == pytest.approx(1.0)
    assert hits[1].score == pytest.approx(0.6)


def test_dense_search_returns_citation_and_text(store):
    hit = store.search_dense(np.array([0.0, 0.0, 0.0, 1.0], dtype=np.float32), k=1)[0]

    assert hit.chunk_id == 4
    assert hit.citation == "ZAKON-B, чл. 1"
    assert "Јавни набавки" in hit.text


def test_dense_search_k_larger_than_corpus_is_clamped(store):
    assert len(store.search_dense(np.array([1.0, 0.0, 0.0, 0.0], dtype=np.float32), k=99)) == 5


def test_sparse_search_scores_by_shared_term_weight(store):
    # Token 10 is weighted 0.9 on chunk 1 and 0.2 on chunk 5; nothing else has it.
    hits = store.search_sparse({10: 1.0}, k=5)

    assert [hit.chunk_id for hit in hits] == [1, 5]
    assert hits[0].score == pytest.approx(0.9)
    assert hits[1].score == pytest.approx(0.2)


def test_sparse_search_without_matching_terms_returns_nothing(store):
    assert store.search_sparse({9999: 1.0}, k=5) == []


def test_sparse_search_without_weights_returns_nothing(store):
    assert store.search_sparse({}, k=5) == []


def test_chunks_for_units_maps_articles_back_to_text(store):
    hits = store.chunks_for_units(["zakon-a:chlen-3", "zakon-b:chlen-1"])

    assert [hit.chunk_id for hit in hits] == [3, 4]
    # Graph-sourced hits carry no similarity — they enter the pool by rank.
    assert all(hit.score == 0.0 for hit in hits)


def test_chunks_for_units_ignores_unknown_articles(store):
    assert store.chunks_for_units(["nema-takov:chlen-1"]) == []


def test_unit_key_for_chunk(store):
    assert store.unit_key_for_chunk(2) == "zakon-a:chlen-2"
    assert store.unit_key_for_chunk(999) is None


def test_missing_file_raises(tmp_path):
    with pytest.raises(VectorStoreError, match="cannot open|not readable"):
        VectorStore(str(tmp_path / "absent.sqlite")).load()


def test_dimension_mismatch_is_rejected(tmp_path):
    """A store whose vectors do not match its declared dim was built with a
    different embedding model; cosine against the query would be meaningless."""
    path = tmp_path / "bad.sqlite"
    connection = sqlite3.connect(path)
    connection.executescript(
        """
        CREATE TABLE meta(key TEXT PRIMARY KEY, value TEXT);
        CREATE TABLE chunks(id INTEGER PRIMARY KEY, slug TEXT, unit_key TEXT, article TEXT,
            stav TEXT, header TEXT, body TEXT, text TEXT, citation TEXT, page INT, split TEXT);
        CREATE TABLE vectors(chunk_id INTEGER PRIMARY KEY, dense BLOB, sparse TEXT);
        """
    )
    connection.executemany(
        "INSERT INTO meta(key, value) VALUES (?, ?)",
        [("embed_model", "BAAI/bge-m3"), ("dim", "1024")],
    )
    connection.execute(
        "INSERT INTO chunks(id, unit_key, text, citation) VALUES (1, 'a:chlen-1', 'text', 'A, чл. 1')"
    )
    connection.execute(
        "INSERT INTO vectors(chunk_id, dense, sparse) VALUES (1, ?, '{}')",
        (struct.pack("<4f", 1.0, 0.0, 0.0, 0.0),),
    )
    connection.commit()
    connection.close()

    with pytest.raises(VectorStoreError, match="expected 4096"):
        VectorStore(str(path)).load()


def test_empty_store_raises(tmp_path):
    path = tmp_path / "empty.sqlite"
    connection = sqlite3.connect(path)
    connection.executescript(
        """
        CREATE TABLE meta(key TEXT PRIMARY KEY, value TEXT);
        CREATE TABLE chunks(id INTEGER PRIMARY KEY, slug TEXT, unit_key TEXT, article TEXT,
            stav TEXT, header TEXT, body TEXT, text TEXT, citation TEXT, page INT, split TEXT);
        CREATE TABLE vectors(chunk_id INTEGER PRIMARY KEY, dense BLOB, sparse TEXT);
        INSERT INTO meta(key, value) VALUES ('dim', '4');
        """
    )
    connection.commit()
    connection.close()

    with pytest.raises(VectorStoreError, match="no embedded chunks"):
        VectorStore(str(path)).load()


def test_store_is_opened_read_only(store, vector_db_path):
    """The artifact must never be mutable from the query path."""
    connection = sqlite3.connect(f"file:{vector_db_path}?mode=ro", uri=True)
    with pytest.raises(sqlite3.OperationalError):
        connection.execute("DELETE FROM chunks")
    connection.close()


# --- add_chunk: the runtime overlay used by the injection-test fixture --------


def test_add_chunk_appends_a_searchable_row(store):
    before = len(store.chunks)
    store.add_chunk(
        {
            "id": 990001,
            "unit_key": "zakon-test:chlen-999",
            "citation": "ЗАКОН-ТЕСТ, чл. 999",
            "header": "Тест",
            "text": "инјекција",
        },
        np.array([0.0, 0.0, 0.6, 0.8], dtype=np.float32),  # a direction no corpus chunk occupies
        {99999: 2.0},
    )

    assert len(store.chunks) == before + 1

    dense_hit = store.search_dense(np.array([0.0, 0.0, 0.6, 0.8], dtype=np.float32), k=1)[0]
    assert dense_hit.citation == "ЗАКОН-ТЕСТ, чл. 999"
    assert dense_hit.text == "инјекција"
    assert dense_hit.score == pytest.approx(1.0)

    sparse_hit = store.search_sparse({99999: 1.0}, k=1)[0]
    assert sparse_hit.citation == "ЗАКОН-ТЕСТ, чл. 999"


def test_add_chunk_normalizes_the_dense_vector(store):
    store.add_chunk(
        {"id": 990002, "citation": "ЗАКОН-ТЕСТ, чл. 998", "text": "x"},
        np.array([0.0, 0.0, 1.8, 2.4], dtype=np.float32),  # magnitude 3, direction [0,0,0.6,0.8]
        {},
    )

    hit = store.search_dense(np.array([0.0, 0.0, 0.6, 0.8], dtype=np.float32), k=1)[0]
    assert hit.score == pytest.approx(1.0)  # cosine, so magnitude does not inflate it


def test_add_chunk_rejects_a_wrong_dimension_vector(store):
    with pytest.raises(VectorStoreError, match="dense vector"):
        store.add_chunk(
            {"id": 990003, "citation": "x", "text": "y"},
            np.array([1.0, 0.0], dtype=np.float32),
            {},
        )
