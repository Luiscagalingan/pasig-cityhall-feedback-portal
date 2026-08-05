from __future__ import annotations
import argparse, csv, json
from collections import Counter
from pathlib import Path

LABELS = ("positive", "neutral", "negative")

def main() -> None:
    parser = argparse.ArgumentParser(description="Calculate two-reviewer Cohen's Kappa and export disagreements.")
    parser.add_argument("input", type=Path, help="CSV with record_id, comment, reviewer_1_label, reviewer_2_label")
    parser.add_argument("--output-dir", type=Path, default=Path("ml/data/agreement"))
    args = parser.parse_args(); args.output_dir.mkdir(parents=True, exist_ok=True)
    valid, disagreements, incomplete = [], [], []
    with args.input.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        required = {"record_id", "comment", "reviewer_1_label", "reviewer_2_label"}
        if not reader.fieldnames or not required.issubset(reader.fieldnames): raise SystemExit("Missing required reviewer columns.")
        for row in reader:
            a = (row.get("reviewer_1_label") or "").strip().lower(); b = (row.get("reviewer_2_label") or "").strip().lower()
            if a not in LABELS or b not in LABELS: incomplete.append(row); continue
            valid.append((a,b,row))
            if a != b: disagreements.append(row)
    n = len(valid); observed = sum(a == b for a,b,_ in valid) / n if n else 0.0
    ac, bc = Counter(a for a,_,_ in valid), Counter(b for _,b,_ in valid)
    expected = sum((ac[label]/n)*(bc[label]/n) for label in LABELS) if n else 0.0
    kappa = (observed-expected)/(1-expected) if n and expected < 1 else (1.0 if n else 0.0)
    interpretation = "almost perfect" if kappa >= .81 else "substantial" if kappa >= .61 else "moderate" if kappa >= .41 else "fair" if kappa >= .21 else "slight/poor"
    fields = ["record_id","comment","reviewer_1_label","reviewer_2_label","final_label","resolution_notes"]
    for name, rows in [("disagreements_for_resolution.csv", disagreements), ("incomplete_or_invalid_labels.csv", incomplete)]:
        with (args.output_dir/name).open("w", encoding="utf-8-sig", newline="") as handle:
            writer=csv.DictWriter(handle,fieldnames=fields,extrasaction="ignore");writer.writeheader();writer.writerows(rows)
    report={"valid_double_labeled":n,"agreements":sum(a==b for a,b,_ in valid),"disagreements":len(disagreements),"incomplete":len(incomplete),"observed_agreement":round(observed,4),"expected_agreement":round(expected,4),"cohens_kappa":round(kappa,4),"interpretation":interpretation}
    (args.output_dir/"agreement_report.json").write_text(json.dumps(report,indent=2),encoding="utf-8");print(json.dumps(report,indent=2))

if __name__ == "__main__": main()
