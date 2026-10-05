from __future__ import annotations

import json
import sys
from pathlib import Path

import joblib
import numpy as np

from normalize_text import normalize_for_model

MODEL = Path(__file__).resolve().parent / "models" / "svm_sentiment.joblib"


def confidence_rows(decisions: np.ndarray) -> list[float]:
    """Return one operational confidence score for every prediction row."""
    arr = np.asarray(decisions, dtype=float)

    # Binary LinearSVC returns one margin per row, while multiclass returns one
    # margin per class. Convert the binary shape to two opposing class margins.
    if arr.ndim == 1:
        arr = np.column_stack([-arr, arr])

    arr = arr - np.max(arr, axis=1, keepdims=True)
    exp = np.exp(np.clip(arr, -50, 50))
    probs = exp / np.maximum(exp.sum(axis=1, keepdims=True), 1e-12)
    return np.max(probs, axis=1).tolist()


def main() -> int:
    """Read a JSON comment list from stdin and emit predictions in order."""
    comments = json.loads(sys.stdin.read() or "[]")
    if not isinstance(comments, list):
        raise SystemExit("Expected a JSON list")
    if not MODEL.exists():
        raise SystemExit("Model not found")

    artifact = joblib.load(MODEL)
    matrix = artifact["features"].transform(
        [normalize_for_model(str(comment)) for comment in comments]
    )
    classifier = artifact["classifier"]
    labels = classifier.predict(matrix)
    confidence = confidence_rows(classifier.decision_function(matrix))
    predictions = [
        {"label": str(label), "confidence": round(float(score), 4)}
        for label, score in zip(labels, confidence)
    ]
    print(json.dumps(predictions, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
