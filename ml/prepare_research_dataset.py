from __future__ import annotations
import argparse, csv, hashlib, json, re
from collections import Counter
from pathlib import Path
from normalize_text import normalize_text

LABELS = {"positive", "neutral", "negative"}
EMAIL = re.compile(r"\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b", re.I)
PHONE = re.compile(r"(?<!\d)(?:\+?63|0)?9\d{9}(?!\d)|(?<!\d)\d{7,12}(?!\d)")
URL = re.compile(r"\b(?:https?://|www\.)\S+", re.I)
IDENTIFIER = re.compile(r"\b(?:account|reference|ref|patient|case|id)\s*(?:no\.?|number|#)?\s*[:#-]?\s*[A-Z0-9-]{5,}\b", re.I)

def fingerprint(text: str) -> str:
    normalized = re.sub(r"[^\w\s]", " ", normalize_text(text), flags=re.UNICODE)
    normalized = re.sub(r"\s+", " ", normalized).strip()
    return hashlib.sha256(normalized.encode("utf-8")).hexdigest()

def pii_flags(text: str) -> list[str]:
    checks = [("email", EMAIL), ("phone_or_long_number", PHONE), ("url", URL), ("possible_identifier", IDENTIFIER)]
    return [name for name, pattern in checks if pattern.search(text)]

def main() -> None:
    parser = argparse.ArgumentParser(description="Validate, de-identify-check, normalize, and deduplicate a research sentiment CSV.")
    parser.add_argument("input", type=Path, help="CSV with comment and optional label/record_id columns")
    parser.add_argument("--output-dir", type=Path, default=Path("ml/data/prepared"))
    args = parser.parse_args()
    args.output_dir.mkdir(parents=True, exist_ok=True)
    accepted, rejected, duplicates = [], [], []
    seen: dict[str, str] = {}
    with args.input.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if not reader.fieldnames or "comment" not in reader.fieldnames:
            raise SystemExit("Input must contain a comment column.")
        for number, row in enumerate(reader, 2):
            record_id = (row.get("record_id") or row.get("id") or str(number - 1)).strip()
            comment = (row.get("comment") or "").strip()
            label = (row.get("label") or "").strip().lower()
            problems = []
            if len(comment) < 5: problems.append("comment_too_short")
            if len(comment) > 3000: problems.append("comment_too_long")
            if label and label not in LABELS: problems.append("invalid_label")
            problems.extend(pii_flags(comment))
            fp = fingerprint(comment) if comment else ""
            output = {"record_id": record_id, "comment": comment, "label": label, "normalized_comment": normalize_text(comment), "fingerprint": fp}
            if fp and fp in seen:
                output["duplicate_of"] = seen[fp]
                duplicates.append(output)
            elif problems:
                output["rejection_reasons"] = "|".join(problems)
                rejected.append(output)
            else:
                seen[fp] = record_id
                accepted.append(output)
    fields = ["record_id", "comment", "label", "normalized_comment", "fingerprint"]
    for name, rows, extra in [("accepted.csv", accepted, []), ("rejected_pii_or_invalid.csv", rejected, ["rejection_reasons"]), ("exact_duplicates.csv", duplicates, ["duplicate_of"])]:
        with (args.output_dir / name).open("w", encoding="utf-8-sig", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fields + extra, extrasaction="ignore"); writer.writeheader(); writer.writerows(rows)
    counts = Counter(row["label"] or "unlabeled" for row in accepted)
    report = {"input": str(args.input), "accepted": len(accepted), "rejected": len(rejected), "exact_duplicates": len(duplicates), "class_distribution": dict(counts), "warning": "Flagged records require human review; this tool does not claim automatic legal de-identification."}
    (args.output_dir / "preparation_report.json").write_text(json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(report, indent=2, ensure_ascii=False))

if __name__ == "__main__": main()
