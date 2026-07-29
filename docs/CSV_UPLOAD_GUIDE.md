# Historical CSV Upload Guide

Use `sample_feedback_import.csv` as the template.

Required headers:

`visit_date,sex,age,client_type,service_received,timeliness,client_handling,quality_of_service,overall_satisfaction,comment`

Allowed values:

- `sex`: Female, Male, Prefer not to say
- `client_type`: Pasigueño, Non-Pasigueño, City Government Employee
- Ratings: whole numbers 1, 2, 3, or 4
- Comment: required, complete English/Filipino/Taglish sentence
- Date: `YYYY-MM-DD`

The importer validates all rows, performs batch SVM prediction, computes the final 60/40 score, creates action items for negative/low-scoring feedback, and stores rejected-row details in Import History.

Historical comments must be de-identified. Do not include names, phone numbers, detailed medical information, or unnecessary identifiers.
