from __future__ import annotations

import json
import sys
from pathlib import Path

import joblib
import numpy as np

from normalize_text import normalize_for_model

MODEL = Path(__file__).resolve().parent / "models" / "svm_sentiment.joblib"


def confidence_from_decision(values: np.ndarray) -> float:
    """Convert SVM margins to a bounded ranking score for review triage.

    LinearSVC does not return probabilities. Softmax makes its class margins
    easier to compare, but the result must not be described as a calibrated
    probability. The portal uses it only to decide whether a prediction needs
    human review.
    """
    values = np.asarray(values, dtype=float).reshape(-1)

    # Subtracting the largest margin prevents overflow without changing the
    # softmax result. Clipping is a second guard for malformed/extreme input.
    values = values - np.max(values)
    exp = np.exp(np.clip(values, -50, 50))
    probs = exp / max(float(exp.sum()), 1e-12)
    return float(np.max(probs))


def main() -> int:
    """Read one comment from stdin and emit one JSON prediction to stdout."""
    text = sys.stdin.read().strip()
    if not text:
        print(json.dumps({"label": "neutral", "confidence": 0.0}))
        return 0

    if not MODEL.exists():
        print(
            "Model not found. Restore ml/models/svm_sentiment.joblib "
            "from a trusted backup.",
            file=sys.stderr,
        )
        return 2

    # The trusted artifact contains both the fitted TF-IDF feature pipeline and
    # its matching classifier. Keeping them together prevents feature drift.
    artifact = joblib.load(MODEL)
    features = artifact["features"]
    classifier = artifact["classifier"]

    matrix = features.transform([normalize_for_model(text)])
    label = str(classifier.predict(matrix)[0])
    decision = classifier.decision_function(matrix)
    confidence = confidence_from_decision(decision)

    print(
        json.dumps(
            {"label": label, "confidence": round(confidence, 4)},
            ensure_ascii=False,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
