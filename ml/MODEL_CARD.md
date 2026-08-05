# Sentiment Model Card

## Intended use

The model classifies de-identified Pasig service-feedback comments as positive, neutral, or negative. It supports dashboard summaries and action triage; it does not make eligibility, legal, disciplinary, or personnel decisions.

## Current development model

- Algorithm: TF-IDF word 1–2 grams and character 3–5 grams with balanced LinearSVC
- Development samples: 300
- Fixed evaluation split: 80% train, 20% test, stratified with random state 42
- Current test accuracy: 81.67%
- Status: demonstration/integration model, not the final institutional research model

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
