# Scope, Limitations, and Methodology

## Iterative Software Development Methodology

The system follows an Iterative Software Development Methodology. Development is divided into repeated cycles so requirements, interface decisions, database rules, sentiment-analysis behavior, security controls, and dashboard results can be reviewed and improved before final deployment.

1. Requirements gathering and analysis of the existing Ugnayan sa Pasig Office feedback form
2. Role, office-scope, and database design
3. Public feedback survey and validation
4. Administrator, Office Head, and Office Staff dashboards
5. Feedback records, filters, demographics, and visualizations
6. SVM dataset preparation, text preprocessing, TF-IDF extraction, training, and testing
7. PHP-to-Python SVM integration and 60/40 weighted computation
8. CSV historical-data import, action monitoring, reports, security review, and revision

Each iteration includes planning, design, implementation, testing, review, and refinement.

## Scope

### Target Environment
The study is conducted within the Local Government Unit of Pasig City, specifically covering the City Health Department (CHD).

### Core Functionality
The proposed system digitizes the existing Ugnayan sa Pasig Office feedback form, automating the collection of client demographic data, transaction details, and four-point Likert ratings across Timeliness, Client Handling, Quality of Service, and Overall Satisfaction, alongside open-ended comments. Submitted qualitative comments are preprocessed and classified using a trained SVM model, with results presented through dynamic dashboards.

### Client Data Captured
Consistent with the existing instrument, the system records client sex, age, and client classification (Pasigueño, non-Pasigueño, or city government employee), enabling administrators to segment satisfaction trends by client demographic in addition to by department and transaction type.

### Linguistic Capability
The system's text classification component is designed to handle qualitative comments submitted in English, Filipino, and Taglish.

## Limitations

### Institutional Setting
The software architecture and database schema are designed specifically around the workflows of Pasig City's CHD and cannot be deployed in other city offices or agencies without adaptation, even though those offices may use the same underlying citywide feedback form.

### Data Modality
Processing is strictly limited to written qualitative text comments and structured numeric ratings; multi-modal inputs such as audio, speech, or video feedback are excluded from the scope of this study.

### Linguistic Complexities
Highly implicit sarcasm, figurative expressions, or obscure non-standard dialects may present classification constraints inherent to supervised machine learning text models generally, and not unique to this implementation.

### Third-Party System Integration
The platform functions as an independent service satisfaction monitoring system and does not directly interface with municipal payroll systems, other city offices' use of the Ugnayan sa Pasig Office form, or external electronic health records (EHR) databases.

## Implemented Access Rules

- The administrator sees consolidated results from all offices.
- Each Office Head and Office Staff account sees only its assigned office.
- Only an Office Head sees Dataset & CSV Upload and office-level Manage Staff.
- The administrator can create an office and its first head in one transaction.
- New offices automatically appear in the public survey list and admin comparison dashboard.
- Archived offices disappear from the public survey and block assigned users from logging in.
