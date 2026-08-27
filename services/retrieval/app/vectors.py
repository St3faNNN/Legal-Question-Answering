"""The vector half of retrieval: BGE-M3 dense + lexical-sparse search over vectors.sqlite.

The store is the corpus artifact produced alongside the citation graph. Its
`vectors` table holds one row per chunk: `dense` is a packed float32 BLOB of
`meta.dim` values, already L2-normalized at build time, and `sparse` is BGE-M3's
lexical weights as a `{token_id: weight}` JSON object.

At this corpus size (~1.8k chunks x 1024 dims = ~7 MB) the whole matrix is held
resident and dense search is a single matmul; there is no reason to add a vector
index dependency. `_MAX_RESIDENT_ROWS` guards against that assumption silently
breaking if a much larger corpus is dropped in later.
"""

import json
import logging
import sqlite3
from collections import defaultdict
from dataclasses import dataclass

import numpy as np

logger = logging.getLogger("retrieval")

_MAX_RESIDENT_ROWS = 500_000


class VectorStoreError(RuntimeError):
    """The store is missing, malformed, or disagrees with the embedding model."""


@dataclass(frozen=True)
class ChunkHit:
    chunk_id: int
    unit_key: str
    text: str
    citation: str
    header: str
    score: float


class VectorStore:
    """Read-only view of vectors.sqlite, loaded once at startup."""

    def __init__(self, path: str):
        self.path = path
        self.dim = 0
        self.embed_model = ""
        self.chunks: list[dict] = []
        self.dense = np.zeros((0, 0), dtype=np.float32)
        # token_id -> [(row index, weight), ...]
        self.sparse_index: dict[int, list[tuple[int, float]]] = defaultdict(list)
        self._row_of_chunk: dict[int, int] = {}
        self._rows_of_unit: dict[str, list[int]] = defaultdict(list)

    @property
    def loaded(self) -> bool:
        return self.dense.shape[0] > 0

    def load(self) -> None:
        # mode=ro so a corrupt query can never write to the artifact.
        uri = f"file:{self.path}?mode=ro"
        try:
            connection = sqlite3.connect(uri, uri=True)
        except sqlite3.Error as error:
            raise VectorStoreError(f"cannot open vector store at {self.path}: {error}") from error

        connection.row_factory = sqlite3.Row
        try:
            meta = {row["key"]: row["value"] for row in connection.execute("SELECT key, value FROM meta")}
            self.embed_model = meta.get("embed_model", "")
            self.dim = int(meta.get("dim", 0))
            if self.dim <= 0:
                raise VectorStoreError("vector store meta is missing a usable 'dim'")

            rows = connection.execute(
                """
                SELECT c.id, c.unit_key, c.slug, c.article, c.citation, c.header, c.text,
                       v.dense, v.sparse
                FROM chunks c
                JOIN vectors v ON v.chunk_id = c.id
                ORDER BY c.id
                """
            ).fetchall()
        except sqlite3.Error as error:
            raise VectorStoreError(f"vector store at {self.path} is not readable: {error}") from error
        finally:
            connection.close()

        if not rows:
            raise VectorStoreError(f"vector store at {self.path} contains no embedded chunks")
        if len(rows) > _MAX_RESIDENT_ROWS:
            raise VectorStoreError(
                f"{len(rows)} chunks exceeds the resident-matrix limit of {_MAX_RESIDENT_ROWS}; "
                "switch to a batched or indexed search before loading this corpus"
            )

        expected_bytes = self.dim * 4
        matrix = np.empty((len(rows), self.dim), dtype=np.float32)
        for index, row in enumerate(rows):
            blob = row["dense"]
            if blob is None or len(blob) != expected_bytes:
                raise VectorStoreError(
                    f"chunk {row['id']} has a {0 if blob is None else len(blob)}-byte dense vector, "
                    f"expected {expected_bytes} ({self.dim} float32) — the store and "
                    f"'{self.embed_model}' disagree on dimension"
                )
            matrix[index] = np.frombuffer(blob, dtype=np.float32)

            self.chunks.append(
                {
                    "id": int(row["id"]),
                    "unit_key": row["unit_key"] or "",
                    "slug": row["slug"] or "",
                    "article": row["article"] or "",
                    "citation": row["citation"] or "",
                    "header": row["header"] or "",
                    "text": row["text"] or "",
                }
            )
            self._row_of_chunk[int(row["id"])] = index
            if row["unit_key"]:
                self._rows_of_unit[row["unit_key"]].append(index)

            if row["sparse"]:
                self._index_sparse(index, row["sparse"])

        # The build pipeline normalizes, but re-normalizing is cheap and makes
        # the matmul-is-cosine invariant hold regardless of how the store was made.
        norms = np.linalg.norm(matrix, axis=1, keepdims=True)
        np.divide(matrix, np.where(norms == 0, 1.0, norms), out=matrix)
        self.dense = matrix

        logger.info(
            "Vector store loaded: %d chunks, dim=%d, model=%s, sparse terms=%d",
            len(self.chunks),
            self.dim,
            self.embed_model or "unknown",
            len(self.sparse_index),
        )

    def add_chunk(self, chunk: dict, dense: np.ndarray, sparse: dict[int, float]) -> None:
        """Append one chunk to the resident store at runtime.

        Used ONLY by the opt-in injection-test fixture overlay
        ({@see app.injection_fixture}); the persisted vectors.sqlite is never
        written. The dense vector is L2-normalized so the matmul-is-cosine
        invariant that {@see search_dense} relies on still holds.
        """
        vector = np.asarray(dense, dtype=np.float32).reshape(-1)
        if vector.shape != (self.dim,):
            raise VectorStoreError(
                f"overlay chunk dense vector has shape {vector.shape}, expected ({self.dim},)"
            )
        norm = float(np.linalg.norm(vector))
        vector = vector / (norm or 1.0)

        row_index = len(self.chunks)
        self.dense = np.vstack([self.dense, vector]) if self.loaded else vector.reshape(1, -1)
        self.chunks.append(
            {
                "id": int(chunk["id"]),
                "unit_key": chunk.get("unit_key", ""),
                "slug": chunk.get("slug", ""),
                "article": chunk.get("article", ""),
                "citation": chunk.get("citation", ""),
                "header": chunk.get("header", ""),
                "text": chunk.get("text", ""),
            }
        )
        self._row_of_chunk[int(chunk["id"])] = row_index
        if chunk.get("unit_key"):
            self._rows_of_unit[chunk["unit_key"]].append(row_index)
        for token_id, weight in sparse.items():
            self.sparse_index[int(token_id)].append((row_index, float(weight)))

    def _index_sparse(self, row_index: int, raw: str) -> None:
        try:
            weights = json.loads(raw)
        except (TypeError, ValueError):
            return
        for token_id, weight in weights.items():
            self.sparse_index[int(token_id)].append((row_index, float(weight)))

    def search_dense(self, query: np.ndarray, k: int) -> list[ChunkHit]:
        """Cosine similarity by matmul — both sides are L2-normalized."""
        if not self.loaded or k <= 0:
            return []
        scores = self.dense @ query.astype(np.float32)
        return self._top(scores, k)

    def search_sparse(self, weights: dict[int, float], k: int) -> list[ChunkHit]:
        """Lexical scoring: the dot product of two sparse BGE-M3 weight vectors,
        accumulated over the inverted index rather than densified."""
        if not self.loaded or k <= 0 or not weights:
            return []
        scores = np.zeros(self.dense.shape[0], dtype=np.float32)
        for token_id, query_weight in weights.items():
            for row_index, chunk_weight in self.sparse_index.get(token_id, ()):
                scores[row_index] += query_weight * chunk_weight
        if not scores.any():
            return []
        # Unlike cosine, a zero lexical score means "shares no term with the
        # query" — such chunks are not weak matches, they are non-matches, and
        # padding the ranking with them would give them RRF weight.
        return [hit for hit in self._top(scores, k) if hit.score > 0.0]

    def _top(self, scores: np.ndarray, k: int) -> list[ChunkHit]:
        k = min(k, scores.shape[0])
        top = np.argpartition(-scores, k - 1)[:k]
        top = top[np.argsort(-scores[top])]
        return [self._hit(int(index), float(scores[index])) for index in top]

    def _hit(self, row_index: int, score: float) -> ChunkHit:
        chunk = self.chunks[row_index]
        return ChunkHit(
            chunk_id=chunk["id"],
            unit_key=chunk["unit_key"],
            text=chunk["text"],
            citation=chunk["citation"],
            header=chunk["header"],
            score=score,
        )

    def chunks_for_units(self, unit_keys: list[str], limit_per_unit: int = 1) -> list[ChunkHit]:
        """The chunks belonging to given articles, used to turn graph
        cross-references back into retrievable text. Score is 0.0 — these enter
        the pool by rank position, not by similarity."""
        hits: list[ChunkHit] = []
        for unit_key in unit_keys:
            for row_index in self._rows_of_unit.get(unit_key, [])[:limit_per_unit]:
                hits.append(self._hit(row_index, 0.0))
        return hits

    def unit_key_for_chunk(self, chunk_id: int) -> str | None:
        row_index = self._row_of_chunk.get(chunk_id)
        return None if row_index is None else self.chunks[row_index]["unit_key"]
