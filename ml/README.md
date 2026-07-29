# SVM Sentiment Component

The PHP application sends each required English, Filipino, or Taglish comment to `predict.py`. The included model uses:

- Informal Filipino/Taglish text normalization
- Word TF-IDF (1–2 grams)
- Character TF-IDF (3–5 grams)
- Linear Support Vector Classifier (`LinearSVC`)

The normalizer recognizes common shorthand and slang such as `nmn`, `dba`, `ndi`, `hndi`, `kc`, `kse`, `lng`, `oks`, `goods`, `ambagal`, `badtrip`, `waley`, and elongated spellings such as `bagaaal` and `tagaaal`. The original wording is preserved together with its normalized form so the model can learn both.

## Dataset files

- `data/sample_training.csv` — 300 balanced demonstration comments: 100 positive, 100 neutral, and 100 negative.
- `data/tagalog_slang_dictionary.csv` — reference list of common variants and normalized forms.
- `SLANG_AND_SHORTCUTS.md` — readable examples and reminders about context.

## Windows/XAMPP setup

1. Install Python 3.
2. Double-click `install_and_train.bat`, or run:
   `python -m pip install -r requirements.txt`
3. Train/retrain:
   `python train.py`
4. Test:
   `echo Ambagal nmn dba, ilang oras ako naghintay. | python predict.py`

The included dataset and trained model are demonstration assets only. Replace or expand them with an approved, de-identified, manually labeled CHD dataset before reporting final research accuracy, precision, recall, F1-score, or confusion-matrix results.
