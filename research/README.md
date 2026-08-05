# Research Completion Kit

This folder contains templates and automation for the remaining human research work. Do not replace actual respondents, independent reviewers, evaluators, or institutional approval with generated results.

## Recommended workflow

1. Place de-identified raw comments in `templates/dataset_collection_template.csv`.
2. Run `python ml/prepare_research_dataset.py research/templates/dataset_collection_template.csv`.
3. Give independent copies of `reviewer_labeling_template.csv` to two reviewers.
4. Combine their labels and run `python ml/calculate_agreement.py combined_reviewers.csv`.
5. Resolve disagreements and prepare the approved `comment,label` CSV.
6. Validate the final pipeline with `python ml/train_final_model.py approved.csv --quick`.
7. Run full tuning with `python ml/train_final_model.py approved.csv`.
8. Use `--promote` only after approval of the dataset and evaluation protocol.
9. Conduct UAT and ISO/IEC 25010 evaluation using the templates.
10. Run `python research/score_evaluation.py completed_evaluation.csv`.

## Evidence rule

Keep blank templates separate from completed evidence. Never pre-fill respondent scores, reviewer labels, approvals, or evaluation results.
