# Sentiment Model Card

## Intended use

The model classifies de-identified Pasig service-feedback comments as positive, neutral, or negative. It supports dashboard summaries and action triage; it does not make eligibility, legal, disciplinary, or personnel decisions.

## Current packaged model

- Algorithm: tuned TF-IDF word 1–2 grams and character 3–5 grams with LinearSVC
- Artifact metadata: `approved_feedback.csv`, 990 samples, three balanced classes
- Packaged artifact: `models/svm_sentiment.joblib`
- The packaged artifact has the same SHA-256 hash as the evaluated candidate artifact.

## Separate 90-sample evaluation

The current artifact was rerun against `data/blind_test_v2_90.csv`: 90 labeled comments,
30 per class, with no exact normalized-comment overlap with `approved_feedback.csv` and
no duplicates within the 90-comment file.

- Accuracy: 0.9333
- Macro F1: 0.9336
- Weighted F1: 0.9336
- Negative precision / recall / F1: 0.8750 / 0.9333 / 0.9032
- Neutral precision / recall / F1: 0.9667 / 0.9667 / 0.9667
- Positive precision / recall / F1: 0.9643 / 0.9000 / 0.9310
- Confusion matrix (actual rows, predicted columns; negative/neutral/positive): `[[28,1,1],[1,29,0],[3,0,27]]`

This is a technical regression result, not proof of institutional research accuracy.
The repository does not establish who labeled the 90 comments, the labeling protocol,
inter-rater agreement, source provenance, or that the set remained unseen throughout
all model selection. Those facts require independent documentation and approval.

The final evaluation must use an approved, de-identified, manually labeled dataset, documented labeling instructions, inter-rater agreement, class distribution, and an independent test set. Synthetic comments must not be presented as actual respondent data.

## Confidence and review threshold

LinearSVC does not directly produce calibrated probabilities. The portal converts the multiclass decision-function margins with a softmax transformation to create a consistent operational confidence score. This score is a triage indicator, not a statistical probability.

Predictions below 0.60 and every non-SVM fallback prediction enter the human-review queue. The threshold is an operational default and must be re-evaluated using the final validation dataset.

## Fallback behavior

When Python or the model is unavailable, an expanded Filipino/English/Taglish phrase lexicon provides service continuity. Fallback results retain `sentiment_source=fallback`, are never represented as SVM output, and always require authorized human review.

## Known limitations

- Sarcasm, indirect language, and complex negation may be misclassified.
- One overall label cannot fully represent mixed or aspect-specific sentiment.
- Language and slang may change over time.
- Results depend on the representativeness and labeling quality of the approved dataset.

## Human oversight

Authorized reviewers can correct predictions, preserve the original label, record notes, recalculate the weighted score, and approve de-identified comments as future retraining candidates.
