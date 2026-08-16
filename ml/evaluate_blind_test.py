from __future__ import annotations

import argparse
import csv
import json
from pathlib import Path

import joblib
import numpy as np
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix, f1_score

from normalize_text import normalize_for_model


def confidence_rows(decisions: np.ndarray) -> list[float]:
    arr = np.asarray(decisions, dtype=float)
    if arr.ndim == 1:
        arr = np.column_stack([-arr, arr])
    arr = arr - np.max(arr, axis=1, keepdims=True)
    exp = np.exp(np.clip(arr, -50, 50))
    probs = exp / np.maximum(exp.sum(axis=1, keepdims=True), 1e-12)
    return np.max(probs, axis=1).tolist()


def main() -> None:
    parser = argparse.ArgumentParser(description="Evaluate the candidate SVM on a separate blind test CSV.")
    parser.add_argument("csv_path", help="CSV with columns: comment,label")
    parser.add_argument(
        "--model",
        default=r"research_results\candidate_svm_sentiment.joblib",
        help="Candidate model path"
    )
    parser.add_argument(
        "--output",
        default=r"research_results\blind_test_evaluation.json",
        help="JSON result output path"
    )
    parser.add_argument(
        "--review-threshold",
        type=float,
        default=0.60,
        help="Confidence threshold used only to count cases for human review"
    )
    args = parser.parse_args()

    csv_path = Path(args.csv_path)
    model_path = Path(args.model)
    output_path = Path(args.output)

    if not csv_path.exists():
        raise SystemExit(f"Blind test CSV not found: {csv_path}")
    if not model_path.exists():
        raise SystemExit(f"Candidate model not found: {model_path}")

    comments: list[str] = []
    expected: list[str] = []

    with csv_path.open("r", encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        required = {"comment", "label"}
        if not reader.fieldnames or not required.issubset(set(reader.fieldnames)):
            raise SystemExit("CSV must contain columns: comment,label")

        for row in reader:
            comment = (row.get("comment") or "").strip()
            label = (row.get("label") or "").strip().lower()
            if not comment:
                continue
            if label not in {"negative", "neutral", "positive"}:
                raise SystemExit(f"Invalid label: {label!r}")
            comments.append(comment)
            expected.append(label)

    artifact = joblib.load(model_path)
    features = artifact["features"]
    classifier = artifact["classifier"]

    X = features.transform([normalize_for_model(x) for x in comments])
    predicted = [str(x) for x in classifier.predict(X)]
    decisions = classifier.decision_function(X)
    confidence = confidence_rows(decisions)

    labels = ["negative", "neutral", "positive"]
    acc = float(accuracy_score(expected, predicted))
    macro_f1 = float(f1_score(expected, predicted, average="macro"))
    report = classification_report(
        expected,
        predicted,
        labels=labels,
        output_dict=True,
        zero_division=0
    )
    matrix = confusion_matrix(expected, predicted, labels=labels).tolist()

    errors = []
    low_confidence = []
    for i, (text, truth, pred, conf) in enumerate(zip(comments, expected, predicted, confidence), start=1):
        item = {
            "row": i + 1,
            "comment": text,
            "expected": truth,
            "predicted": pred,
            "confidence": round(float(conf), 4),
        }
        if truth != pred:
            errors.append(item)
        if conf < args.review_threshold:
            low_confidence.append(item)

    result = {
        "dataset": str(csv_path),
        "model": str(model_path),
        "sample_count": len(comments),
        "class_distribution": {lab: expected.count(lab) for lab in labels},
        "accuracy": round(acc, 4),
        "macro_f1": round(macro_f1, 4),
        "labels": labels,
        "classification_report": report,
        "confusion_matrix": matrix,
        "misclassified_count": len(errors),
        "misclassified": errors,
        "review_threshold": args.review_threshold,
        "low_confidence_count": len(low_confidence),
        "low_confidence_cases": low_confidence,
        "confidence_note": (
            "Confidence is derived from softmax over SVM decision scores. "
            "It is useful for ranking/review thresholds but is not a calibrated probability."
        ),
        "research_note": (
            "Treat this as a separate blind-test result only if these comments were not used "
            "for training, tuning, or model selection before this evaluation."
        ),
    }

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")

    print(json.dumps({
        "sample_count": len(comments),
        "accuracy": round(acc, 4),
        "macro_f1": round(macro_f1, 4),
        "misclassified_count": len(errors),
        "low_confidence_count": len(low_confidence),
        "confusion_matrix": matrix,
        "output": str(output_path),
    }, ensure_ascii=False, indent=2))

    if errors:
        print("\nMISCLASSIFIED COMMENTS")
        print("-" * 80)
        for e in errors:
            print(
                f'Row {e["row"]}: expected={e["expected"]}, '
                f'predicted={e["predicted"]}, confidence={e["confidence"]}'
            )
            print(e["comment"])
            print()


if __name__ == "__main__":
    main()
