#!/usr/bin/env python3
"""Jev A/B 實驗：同一批選擇題，在「沒給背景」與「有給背景」兩種條件下跑，
比較準確率、平均信心，以及 ECE（expected calibration error，信心與實際正確率的落差）。

用途：量化「Jev 是 reader，不是 knowledge base」——答案寫在 state 裡時很準，
需要它自己具備的知識時會高信心答錯。

    pip install typesafe-sdk
    export TYPESAFE_API_KEY=...
    python docs/jev/experiments/calibration_ab.py docs/jev/experiments/sample_items.jsonl

資料格式（JSONL，一行一題）：
    {"id": "hist-1",
     "question": "...題目...",
     "options": {"a": "選項A的意思", "b": "...", "other": "以上皆非"},
     "answer": "b",
     "context": "把答案寫在裡面的背景段落"}

只有 `context` 那一欄是 B 組才會放進 state 的東西；其餘兩組完全相同。
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from dataclasses import dataclass
from pathlib import Path

BINS = 10


@dataclass
class Result:
    item_id: str
    correct: bool
    confidence: float
    predicted: str
    expected: str


def load_items(path: Path) -> list[dict]:
    items = []
    for line_no, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        item = json.loads(line)
        missing = {"id", "question", "options", "answer"} - item.keys()
        if missing:
            raise ValueError(f"{path}:{line_no} 缺少欄位 {sorted(missing)}")
        if item["answer"] not in item["options"]:
            raise ValueError(f"{path}:{line_no} answer 不在 options 裡")
        items.append(item)
    if not items:
        raise ValueError(f"{path} 沒有任何題目")
    return items


def ece(results: list[Result], bins: int = BINS) -> float:
    """把預測依 confidence 分桶，算 |平均信心 - 實際正確率| 的加權平均。0 = 完美校準。"""
    total = len(results)
    error = 0.0
    for b in range(bins):
        lo, hi = b / bins, (b + 1) / bins
        bucket = [r for r in results if (lo < r.confidence <= hi) or (b == 0 and r.confidence == 0.0)]
        if not bucket:
            continue
        acc = sum(r.correct for r in bucket) / len(bucket)
        conf = sum(r.confidence for r in bucket) / len(bucket)
        error += (len(bucket) / total) * abs(acc - conf)
    return error


def run_arm(client, items: list[dict], *, with_context: bool, verbose: bool) -> list[Result]:
    from typesafe_sdk import Choice

    results: list[Result] = []
    for item in items:
        state: dict[str, str] = {"question": item["question"]}
        if with_context:
            # 缺 context 的題目在 B 組會退化成 A 組，這點在報告裡要記得。
            state["background"] = item.get("context", "")

        response = client.system_one(
            state=state,
            questions={
                "answer": Choice(
                    instructions="Which option correctly answers the question?",
                    criteria=item["options"],
                )
            },
        )
        answer = response.answers["answer"]
        result = Result(
            item_id=item["id"],
            correct=answer.choice == item["answer"],
            confidence=float(answer.confidence),
            predicted=answer.choice,
            expected=item["answer"],
        )
        results.append(result)
        if verbose:
            mark = "✓" if result.correct else "✗"
            print(
                f"  {mark} {result.item_id:<12} pred={result.predicted:<16}"
                f" exp={result.expected:<16} conf={result.confidence:.2f}",
                file=sys.stderr,
            )
    return results


def summarize(name: str, results: list[Result]) -> dict:
    n = len(results)
    wrong = [r for r in results if not r.correct]
    return {
        "arm": name,
        "n": n,
        "accuracy": sum(r.correct for r in results) / n,
        "mean_confidence": sum(r.confidence for r in results) / n,
        # 「答錯時有多自信」——這個數字越高，代表越不能拿 confidence 當安全閥。
        "mean_confidence_when_wrong": (sum(r.confidence for r in wrong) / len(wrong)) if wrong else 0.0,
        "ece": ece(results),
    }


def print_table(rows: list[dict]) -> None:
    header = f"{'arm':<16}{'n':>4}{'accuracy':>10}{'mean conf':>11}{'conf|wrong':>12}{'ECE':>8}"
    print(header)
    print("-" * len(header))
    for row in rows:
        print(
            f"{row['arm']:<16}{row['n']:>4}{row['accuracy']:>10.3f}"
            f"{row['mean_confidence']:>11.3f}{row['mean_confidence_when_wrong']:>12.3f}{row['ece']:>8.3f}"
        )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("dataset", type=Path, help="JSONL 題庫")
    parser.add_argument("--model", default=os.environ.get("TYPESAFE_MODEL", "jev-latest"))
    parser.add_argument("--base-url", default=os.environ.get("TYPESAFE_BASE_URL"), help="指向 jeff 等相容服務")
    parser.add_argument("--json-out", type=Path, help="把每題結果寫成 JSON 供後續分析")
    parser.add_argument("-v", "--verbose", action="store_true", help="逐題印出 stderr")
    args = parser.parse_args()

    items = load_items(args.dataset)

    try:
        from typesafe_sdk import TypeSafeClient
    except ImportError:
        print("需要官方 SDK：pip install typesafe-sdk", file=sys.stderr)
        return 2

    if not os.environ.get("TYPESAFE_API_KEY") and not args.base_url:
        print("請設定 TYPESAFE_API_KEY（或用 --base-url 指向自架的相容服務）", file=sys.stderr)
        return 2

    client_kwargs = {"model": args.model}
    if args.base_url:
        client_kwargs["base_url"] = args.base_url

    with TypeSafeClient(**client_kwargs) as client:
        print(f"A 組：不給背景（{len(items)} 題）", file=sys.stderr)
        no_ctx = run_arm(client, items, with_context=False, verbose=args.verbose)
        print(f"B 組：給背景（{len(items)} 題）", file=sys.stderr)
        with_ctx = run_arm(client, items, with_context=True, verbose=args.verbose)

    rows = [summarize("no-context", no_ctx), summarize("with-context", with_ctx)]
    print_table(rows)

    flipped = [
        (a.item_id, a.confidence, b.confidence)
        for a, b in zip(no_ctx, with_ctx)
        if not a.correct and b.correct
    ]
    if flipped:
        print("\n給了背景才答對的題目（信心：無背景 → 有背景）：")
        for item_id, before, after in flipped:
            print(f"  {item_id:<12} {before:.2f} → {after:.2f}")

    if args.json_out:
        args.json_out.write_text(
            json.dumps(
                {
                    "summary": rows,
                    "no_context": [r.__dict__ for r in no_ctx],
                    "with_context": [r.__dict__ for r in with_ctx],
                },
                ensure_ascii=False,
                indent=2,
            ),
            encoding="utf-8",
        )
        print(f"\n明細已寫入 {args.json_out}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
