#!/usr/bin/env python
"""Retrieval evaluation harness — calls the containerized retrieval gRPC service
directly (no generation, no Gemini, no API quota).

WHERE IT RUNS
-------------
From the HOST, against the retrieval container's published port (localhost:8001).
Use the retrieval service's own virtualenv, which already has grpcio + PyYAML +
protobuf + the generated stubs — no new dependency:

    ./services/retrieval/.venv/Scripts/python.exe scripts/eval_retrieval.py run --label torch-baseline
    ./services/retrieval/.venv/Scripts/python.exe scripts/eval_retrieval.py compare A.json B.json

The `docker` CLI must be on PATH: the runner reads the active config from the
container's env and scrapes the per-stage `stage_ms` line from its logs (those
timings are logged by the service, not returned in the gRPC response). The
retrieval service itself is never modified.

OUTPUTS (per run, under tests/eval/results/)
    {timestamp}-{label}.json   machine-readable (retrieval + config + scoring)
    {timestamp}-{label}.md     human-readable — read this to fill in expected_articles

Resumable: results accumulate in .inprogress-{label}.json after every question, so
a crash loses nothing; re-running with the same --label skips already-done ids.
"""

import argparse
import json
import re
import subprocess
import sys
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from statistics import mean, median

# The report lines carry Cyrillic and a literal delta; on Windows the console
# defaults to cp1252 and every print of them raises UnicodeEncodeError before any
# output appears. Force UTF-8 on our own streams rather than requiring callers to
# set PYTHONIOENCODING.
for _stream in (sys.stdout, sys.stderr):
    if hasattr(_stream, "reconfigure"):
        _stream.reconfigure(encoding="utf-8")

REPO = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(REPO / "services" / "retrieval" / "app"))  # self-contained pb package (no torch)

import grpc  # noqa: E402
import yaml  # noqa: E402
from pb import retrieval_pb2, retrieval_pb2_grpc  # noqa: E402

EVAL_DIR = REPO / "tests" / "eval"
QUESTIONS = EVAL_DIR / "questions.yaml"
RESULTS_DIR = EVAL_DIR / "results"
# The reranker was removed, so RERANK_BACKEND / RERANK_TOP_N / RERANK_MAX_LENGTH
# are no longer captured. Runs recorded BEFORE the removal still carry them in
# their stored config; `compare` only json.dumps that dict for the header, so
# those files still read correctly — the header simply shows a key the service
# no longer has, which is accurate history.
CONFIG_KEYS = ["CANDIDATE_POOL", "RETRIEVAL_TOP_N", "QUANTIZE"]
EXCERPT_CHARS = 500

# The `rerank=` field disappeared from the log line when the cross-encoder was
# removed. It is matched optionally so this harness reads BOTH the current
# service and the archived logs of runs captured before the removal.
STAGE_RE = re.compile(
    r"stage_ms embed=(\d+) search=(\d+) fusion=(\d+) graph=(\d+)"
    r"(?: rerank=(\d+))? pack=(\d+) total=(\d+)"
)
ARTICLE_RE = re.compile(r"чл\.?\s*(\d+)")


# --- docker helpers (config + per-stage timings live on the container) --------


def _docker(*args) -> str:
    result = subprocess.run(
        ["docker", "compose", *args],
        cwd=REPO,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    return result.stdout


def active_config() -> dict:
    """The retrieval service's live env for the knobs that shape a run, so results
    are self-describing. Absent keys (an older container) record as None."""
    env = {}
    for line in _docker("exec", "-T", "retrieval", "env").splitlines():
        if "=" in line:
            key, value = line.split("=", 1)
            if key in CONFIG_KEYS:
                env[key] = value
    return {key: env.get(key) for key in CONFIG_KEYS}


def stage_timings(since_rfc3339: str, service_latency_ms: int) -> dict:
    """The per-stage breakdown is only in the service log. Read the log window
    opened just before this (sequential) call and take its stage_ms line. Falls
    back to the response's total latency if the line hasn't surfaced."""
    for _ in range(6):
        matches = STAGE_RE.findall(_docker("logs", "retrieval", "--since", since_rfc3339))
        if matches:
            embed, search, fusion, graph, rerank, pack, total = (
                int(x) if x else None for x in matches[-1]
            )
            return {
                "embed": embed, "search": search, "fusion": fusion, "graph": graph,
                "rerank": rerank, "pack": pack, "total": total,
                "service_latency_ms": service_latency_ms,
            }
        time.sleep(0.3)
    return {
        "embed": None, "search": None, "fusion": None, "graph": None,
        "rerank": None, "pack": None, "total": None,
        "service_latency_ms": service_latency_ms,
    }


# --- retrieval + scoring ------------------------------------------------------


def retrieve(stub, question: str, excerpt: int) -> tuple[list, list, int]:
    response = stub.Retrieve(retrieval_pb2.RetrieveRequest(question=question), timeout=180)
    retrieved = [
        {
            "rank": i + 1,
            "article": chunk.source,
            "chunk_id": chunk.id,
            "score": round(chunk.score, 4),
            "text_excerpt": chunk.text[:excerpt],
        }
        for i, chunk in enumerate(response.chunks)
    ]
    cross_references = [
        {"head": t.head, "relation": t.relation, "tail": t.tail, "confidence": round(t.confidence, 4)}
        for t in response.triples
    ]
    return retrieved, cross_references, response.latency_ms


def normalize_article(citation: str) -> str:
    """Collapse a citation to law + article number so a retrieved
    'ZAKON-ZA-JAVNITE, чл. 132 ст. (1)-(5)' matches an expected
    'ZAKON-ZA-JAVNITE, чл. 132'. Stav, case, and spacing are dropped. Adjust here
    if expected_articles are authored at a different granularity."""
    law = citation.split(",")[0].strip().upper()
    match = ARTICLE_RE.search(citation)
    return f"{law}|чл.{match.group(1)}" if match else law


def score_run(questions: list) -> dict | None:
    scored = [q for q in questions if q["expected_articles"]]
    if not scored:
        return None

    def recall_at(q, k):
        expected = {normalize_article(a) for a in q["expected_articles"]}
        got = {normalize_article(r["article"]) for r in q["retrieved"][:k]}
        return len(expected & got) / len(expected)

    ranks, misses = [], []
    for q in scored:
        found = False
        for expected in {normalize_article(a) for a in q["expected_articles"]}:
            rank = next(
                (r["rank"] for r in q["retrieved"] if normalize_article(r["article"]) == expected),
                None,
            )
            if rank is not None:
                ranks.append(rank)
                found = True
        if not found:
            misses.append(q["id"])

    return {
        "n_scored": len(scored),
        # recall@5 exists because RETRIEVAL_TOP_N=5 was proposed: a run capped at
        # 5 can never score at k=6, so comparing it against recall@6 would read
        # as a loss no matter how good the ordering is. Compare like with like.
        "recall_at_5": round(mean(recall_at(q, 5) for q in scored), 4),
        "recall_at_6": round(mean(recall_at(q, 6) for q in scored), 4),
        "recall_at_16": round(mean(recall_at(q, 16) for q in scored), 4),
        "mean_rank_of_expected": round(mean(ranks), 2) if ranks else None,
        "questions_with_no_expected_retrieved": misses,
    }


# --- human-readable export ----------------------------------------------------


def to_markdown(result: dict) -> str:
    lines = [
        f"# Retrieval eval — {result['label']}",
        "",
        f"_{result['timestamp']}_ · config `{json.dumps(result['config'], ensure_ascii=False)}`",
        "",
    ]
    for q in result["questions"]:
        lines += [f"## Q{q['id']}: {q['question']}", "", f"**Expected answer:** {q['expected_answer']}"]
        expected = ", ".join(q["expected_articles"]) if q["expected_articles"] else "_(not yet established)_"
        lines += [f"**Expected articles:** {expected}", "", "**Retrieved:**"]
        for r in q["retrieved"]:
            excerpt = " ".join(r["text_excerpt"].split())
            lines += [f"{r['rank']}. **{r['article']}** · score {r['score']}", f"   > {excerpt}"]
        if q["cross_references"]:
            refs = "; ".join(f"{t['head']} —{t['relation']}→ {t['tail']}" for t in q["cross_references"])
            lines += ["", f"**Graph cross-references:** {refs}"]
        t = q["timings"]
        lines += [
            "",
            f"_timings (ms): total {t['total']} · rerank {t['rerank']} · embed {t['embed']} · "
            f"search {t['search']} · fusion {t['fusion']} · graph {t['graph']} · pack {t['pack']}_",
            "",
        ]
    if result.get("scoring"):
        s = result["scoring"]
        lines += [
            "---",
            "## Scoring",
            f"- recall@5: **{s.get('recall_at_5')}**",
            f"- recall@6: **{s['recall_at_6']}**",
            f"- recall@16: **{s['recall_at_16']}**",
            f"- mean rank of expected: {s['mean_rank_of_expected']}",
            f"- questions with no expected article retrieved: {s['questions_with_no_expected_retrieved']}",
        ]
    return "\n".join(lines) + "\n"


# --- commands -----------------------------------------------------------------


def cmd_run(args) -> None:
    questions = yaml.safe_load(Path(args.questions).read_text(encoding="utf-8"))
    RESULTS_DIR.mkdir(parents=True, exist_ok=True)
    inprogress = RESULTS_DIR / f".inprogress-{args.label}.json"

    if inprogress.exists():
        state = json.loads(inprogress.read_text(encoding="utf-8"))
        print(f"resuming '{args.label}': {len(state['questions'])} question(s) already recorded")
    else:
        state = {
            "label": args.label,
            "timestamp": datetime.now().strftime("%Y%m%d-%H%M%S"),
            "config": active_config(),
            "questions": [],
        }
    done_ids = {q["id"] for q in state["questions"]}

    stub = retrieval_pb2_grpc.RetrievalStub(grpc.insecure_channel(args.host))
    for item in questions:
        if item["id"] in done_ids:
            continue
        since = (datetime.now(timezone.utc) - timedelta(seconds=2)).strftime("%Y-%m-%dT%H:%M:%SZ")
        retrieved, cross_references, latency = retrieve(stub, item["question"], args.excerpt)
        state["questions"].append(
            {
                "id": item["id"],
                "question": item["question"],
                "expected_answer": item.get("expected_answer", ""),
                "expected_articles": item.get("expected_articles") or [],
                "retrieved": retrieved,
                "cross_references": cross_references,
                "timings": stage_timings(since, latency),
            }
        )
        inprogress.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
        print(f"  Q{item['id']}: {len(retrieved)} chunks, total {state['questions'][-1]['timings']['total']}ms")

    state["questions"].sort(key=lambda q: q["id"])
    state["scoring"] = score_run(state["questions"])

    out_json = RESULTS_DIR / f"{state['timestamp']}-{args.label}.json"
    out_md = RESULTS_DIR / f"{state['timestamp']}-{args.label}.md"
    out_json.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
    out_md.write_text(to_markdown(state), encoding="utf-8")
    inprogress.unlink(missing_ok=True)

    print(f"\nwrote {out_json.relative_to(REPO)}")
    print(f"wrote {out_md.relative_to(REPO)}")
    if state["scoring"]:
        print(f"scoring: {json.dumps(state['scoring'], ensure_ascii=False)}")
    else:
        print("scoring skipped — no expected_articles populated yet")


def cmd_compare(args) -> None:
    a = json.loads(Path(args.file_a).read_text(encoding="utf-8"))
    b = json.loads(Path(args.file_b).read_text(encoding="utf-8"))
    print(f"A  {a['label']}  ({a['timestamp']})  config {json.dumps(a['config'], ensure_ascii=False)}")
    print(f"B  {b['label']}  ({b['timestamp']})  config {json.dumps(b['config'], ensure_ascii=False)}\n")

    aq = {q["id"]: q for q in a["questions"]}
    bq = {q["id"]: q for q in b["questions"]}
    common = sorted(set(aq) & set(bq))
    deltas, changed = [], 0

    for qid in common:
        arts_a = [normalize_article(r["article"]) for r in aq[qid]["retrieved"]]
        arts_b = [normalize_article(r["article"]) for r in bq[qid]["retrieved"]]
        top6_a, top6_b = set(arts_a[:6]), set(arts_b[:6])
        ta, tb = aq[qid]["timings"]["total"], bq[qid]["timings"]["total"]
        dt = (tb - ta) if (ta is not None and tb is not None) else None
        if dt is not None:
            deltas.append(dt)

        if arts_a == arts_b:
            status = "identical"
        elif top6_a == top6_b:
            status = "top-6 same set, reordered"
            changed += 1
        else:
            status = f"top-6 set CHANGED  -{sorted(top6_a - top6_b)} +{sorted(top6_b - top6_a)}"
            changed += 1
        print(f"Q{qid}: {status}  |  Δtotal {f'{dt:+d}ms' if dt is not None else 'n/a'}")

    sa, sb = a.get("scoring"), b.get("scoring")
    if sa and sb:
        if sa.get("recall_at_5") is not None and sb.get("recall_at_5") is not None:
            print(f"\nrecall@5  {sa['recall_at_5']} -> {sb['recall_at_5']}  (Δ{sb['recall_at_5'] - sa['recall_at_5']:+.4f})")
        print(f"\nrecall@6  {sa['recall_at_6']} -> {sb['recall_at_6']}  (Δ{sb['recall_at_6'] - sa['recall_at_6']:+.4f})")
        print(f"recall@16 {sa['recall_at_16']} -> {sb['recall_at_16']}  (Δ{sb['recall_at_16'] - sa['recall_at_16']:+.4f})")

    print(f"\n{changed}/{len(common)} question(s) changed retrieved articles")
    if deltas:
        print(f"total-latency Δ over {len(deltas)} q: mean {mean(deltas):+.0f}ms, median {median(deltas):+.0f}ms")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="cmd", required=True)

    run = sub.add_parser("run", help="run the question set against the retrieval service")
    run.add_argument("--label", required=True, help="short label for this run (goes in the filename)")
    run.add_argument("--questions", default=str(QUESTIONS), help="path to questions.yaml")
    run.add_argument("--host", default="localhost:8001", help="retrieval gRPC target")
    run.add_argument("--excerpt", type=int, default=EXCERPT_CHARS, help="chars of chunk text to record")
    run.set_defaults(func=cmd_run)

    compare = sub.add_parser("compare", help="compare two result JSON files")
    compare.add_argument("file_a")
    compare.add_argument("file_b")
    compare.set_defaults(func=cmd_compare)

    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
