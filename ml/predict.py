from __future__ import annotations
import json
import math
import sys
from pathlib import Path
import joblib
import numpy as np
from normalize_text import normalize_for_model

MODEL = Path(__file__).resolve().parent / "models" / "svm_sentiment.joblib"

def confidence_from_decision(values: np.ndarray) -> float:
    values = np.asarray(values, dtype=float).reshape(-1)
    values = values - np.max(values)
    exp = np.exp(np.clip(values, -50, 50))
    probs = exp / max(float(exp.sum()), 1e-12)
    return float(np.max(probs))

text = sys.stdin.read().strip()
if not text:
    print(json.dumps({"label": "neutral", "confidence": 0.0}))
    raise SystemExit(0)
if not MODEL.exists():
    print("Model not found. Restore ml/models/svm_sentiment.joblib from a trusted backup.", file=sys.stderr)
    raise SystemExit(2)
artifact = joblib.load(MODEL)
features = artifact["features"]
classifier = artifact["classifier"]
X = features.transform([normalize_for_model(text)])
label = str(classifier.predict(X)[0])
decision = classifier.decision_function(X)
confidence = confidence_from_decision(decision)
print(json.dumps({"label": label, "confidence": round(confidence, 4)}, ensure_ascii=False))
