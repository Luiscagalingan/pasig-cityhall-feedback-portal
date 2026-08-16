from __future__ import annotations
import json
import sys
from pathlib import Path
import joblib
import numpy as np
from normalize_text import normalize_for_model

MODEL = Path(__file__).resolve().parent / "research_results" / "candidate_svm_sentiment.joblib"

def confidence_rows(decisions: np.ndarray) -> list[float]:
    arr = np.asarray(decisions, dtype=float)
    if arr.ndim == 1:
        arr = np.column_stack([-arr, arr])
    arr = arr - np.max(arr, axis=1, keepdims=True)
    exp = np.exp(np.clip(arr, -50, 50))
    probs = exp / np.maximum(exp.sum(axis=1, keepdims=True), 1e-12)
    return np.max(probs, axis=1).tolist()

comments = json.loads(sys.stdin.read() or "[]")
if not isinstance(comments, list):
    raise SystemExit("Expected a JSON list")
artifact = joblib.load(MODEL)
X = artifact["features"].transform([normalize_for_model(str(x)) for x in comments])
labels = artifact["classifier"].predict(X)
conf = confidence_rows(artifact["classifier"].decision_function(X))
print(json.dumps([{"label": str(label), "confidence": round(float(c), 4)} for label, c in zip(labels, conf)], ensure_ascii=False))
