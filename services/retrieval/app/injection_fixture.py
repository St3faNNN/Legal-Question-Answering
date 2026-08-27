"""Opt-in prompt-injection test overlay.

When ``INJECTION_FIXTURE_PATH`` points at a JSON file, each entry's text is
embedded with the live model and appended to the in-memory VectorStore, so a
targeted query retrieves untrusted, instruction-like text through ``search_law``.
This exists ONLY to test the agent against prompt injection in retrieved text.

Isolation, by construction:
  - it writes nothing to vectors.sqlite and triggers no re-index;
  - the chunks live only in memory, only for the lifetime of a process that was
    started with the env var set;
  - production and the eval harness never set ``INJECTION_FIXTURE_PATH``, so they
    never load it.

Remove by unsetting the env var (restart the service without it). The fixture
file may stay on disk for re-runs.
"""

import json
import logging

logger = logging.getLogger("retrieval")

#: Fixture chunk ids start here to stay clear of real corpus ids.
_FIXTURE_ID_BASE = 990_000


def load_injection_fixture(store, embedder, path: str) -> int:
    """Embed and append the fixture chunks. Returns how many were loaded."""
    with open(path, encoding="utf-8") as handle:
        fixtures = json.load(handle)

    count = 0
    for index, item in enumerate(fixtures):
        # `embed_text` (the topical retrieval hook) is embedded when present, so
        # a normal query surfaces the chunk; the agent still reads the full
        # `text`, which carries the injection payload. Falls back to `text`.
        dense, sparse = embedder.encode(item.get("embed_text") or item["text"])
        store.add_chunk(
            {
                "id": item.get("id", _FIXTURE_ID_BASE + index),
                "unit_key": item.get("unit_key", ""),
                "slug": item.get("slug", "injection-fixture"),
                "article": item.get("article", ""),
                "citation": item["citation"],
                "header": item.get("header", ""),
                "text": item["text"],
            },
            dense,
            sparse,
        )
        count += 1

    logger.warning(
        "INJECTION FIXTURE ENABLED: loaded %d test chunk(s) from %s. This is a "
        "prompt-injection test overlay and must never be set in production.",
        count,
        path,
    )
    return count
