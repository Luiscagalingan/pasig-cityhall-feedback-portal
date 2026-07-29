from __future__ import annotations
import csv
import json
from pathlib import Path
import joblib
from sklearn.model_selection import train_test_split
from sklearn.pipeline import FeatureUnion
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.svm import LinearSVC
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix
from normalize_text import normalize_for_model

BASE = Path(__file__).resolve().parent
DATA = BASE / "data" / "sample_training.csv"
MODEL = BASE / "models" / "svm_sentiment.joblib"
METRICS = BASE / "models" / "model_metrics.json"

texts: list[str] = []
labels: list[str] = []
with DATA.open("r", encoding="utf-8-sig", newline="") as f:
    for row in csv.DictReader(f):
        comment = (row.get("comment") or "").strip()
        label = (row.get("label") or "").strip().lower()
        if comment and label in {"positive", "neutral", "negative"}:
            texts.append(normalize_for_model(comment))
            labels.append(label)

if len(texts) < 30:
    raise SystemExit("Dataset is too small. Add more manually labeled comments.")

x_train, x_test, y_train, y_test = train_test_split(
    texts, labels, test_size=0.20, random_state=42, stratify=labels
)
features = FeatureUnion([
    ("word", TfidfVectorizer(lowercase=True, ngram_range=(1, 2), min_df=1, sublinear_tf=True)),
    ("char", TfidfVectorizer(lowercase=True, analyzer="char_wb", ngram_range=(3, 5), min_df=1, sublinear_tf=True)),
])
X_train = features.fit_transform(x_train)
classifier = LinearSVC(class_weight="balanced", random_state=42)
classifier.fit(X_train, y_train)
X_test = features.transform(x_test)
pred = classifier.predict(X_test)

# Fit the deployable model again using all approved sample rows after evaluation.
final_features = FeatureUnion([
    ("word", TfidfVectorizer(lowercase=True, ngram_range=(1, 2), min_df=1, sublinear_tf=True)),
    ("char", TfidfVectorizer(lowercase=True, analyzer="char_wb", ngram_range=(3, 5), min_df=1, sublinear_tf=True)),
])
X_all = final_features.fit_transform(texts)
final_classifier = LinearSVC(class_weight="balanced", random_state=42)
final_classifier.fit(X_all, labels)

artifact = {
    "features": final_features,
    "classifier": final_classifier,
    "labels": list(final_classifier.classes_),
    "metadata": {
        "method": "Informal Tagalog/Taglish normalization + TF-IDF word 1-2 grams + character 3-5 grams + LinearSVC",
        "dataset": DATA.name,
        "normalization": "Common Filipino/Taglish shortcuts, slang, elongated spellings, and phrase variants",
        "warning": "Demo model only. Replace with the approved, de-identified, manually labeled CHO dataset before research evaluation or deployment.",
    },
}
MODEL.parent.mkdir(parents=True, exist_ok=True)
joblib.dump(artifact, MODEL)

metrics = {
    "sample_count": len(texts),
    "train_count": len(x_train),
    "test_count": len(x_test),
    "accuracy": accuracy_score(y_test, pred),
    "classification_report": classification_report(y_test, pred, output_dict=True, zero_division=0),
    "labels": list(classifier.classes_),
    "confusion_matrix": confusion_matrix(y_test, pred, labels=classifier.classes_).tolist(),
    "warning": artifact["metadata"]["warning"],
}
METRICS.write_text(json.dumps(metrics, indent=2), encoding="utf-8")
print(json.dumps(metrics, indent=2))
