from __future__ import annotations
import argparse, csv, hashlib, json
from collections import Counter
from pathlib import Path
import joblib, numpy as np
from sklearn.base import clone
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix
from sklearn.model_selection import GridSearchCV, StratifiedKFold, train_test_split
from sklearn.pipeline import FeatureUnion, Pipeline
from sklearn.svm import LinearSVC
from normalize_text import normalize_for_model, normalize_text

BASE=Path(__file__).resolve().parent; LABELS=["negative","neutral","positive"]
def fp(text:str)->str:return hashlib.sha256(normalize_text(text).encode("utf-8")).hexdigest()
def pipeline()->Pipeline:
    return Pipeline([("features",FeatureUnion([("word",TfidfVectorizer(lowercase=True,sublinear_tf=True)),("char",TfidfVectorizer(lowercase=True,analyzer="char_wb",sublinear_tf=True))])),("classifier",LinearSVC(class_weight="balanced",random_state=42))])
def confidence(values:np.ndarray)->np.ndarray:
    values=np.asarray(values,dtype=float);values=values if values.ndim>1 else np.column_stack([-values,values]);values=values-values.max(axis=1,keepdims=True);probs=np.exp(np.clip(values,-50,50));return probs.max(axis=1)/np.maximum(probs.sum(axis=1),1e-12)
def main()->None:
    p=argparse.ArgumentParser(description="Leakage-conscious final SVM tuning and independent evaluation pipeline.")
    p.add_argument("dataset",type=Path,help="Approved CSV with comment,label columns")
    p.add_argument("--output-dir",type=Path,default=BASE/"research_results")
    p.add_argument("--quick",action="store_true",help="Small grid for workflow validation")
    p.add_argument("--promote",action="store_true",help="Replace the production model only after institutional approval")
    args=p.parse_args();args.output_dir.mkdir(parents=True,exist_ok=True)
    rows=[];seen=set()
    with args.dataset.open("r",encoding="utf-8-sig",newline="") as h:
        reader=csv.DictReader(h)
        if not reader.fieldnames or not {"comment","label"}.issubset(reader.fieldnames):raise SystemExit("Dataset must contain comment,label columns.")
        for row in reader:
            comment=(row.get("comment")or"").strip();label=(row.get("label")or"").strip().lower();fingerprint=fp(comment)
            if len(comment)>=5 and label in LABELS and fingerprint not in seen:seen.add(fingerprint);rows.append((comment,label,fingerprint))
    counts=Counter(label for _,label,_ in rows)
    if len(rows)<90 or min(counts.values(),default=0)<20:raise SystemExit(f"Need at least 90 unique rows and 20 per class; found {len(rows)} {dict(counts)}")
    texts=np.array([normalize_for_model(r[0]) for r in rows],dtype=object);raw=np.array([r[0] for r in rows],dtype=object);labels=np.array([r[1] for r in rows],dtype=object)
    x_dev,x_test,y_dev,y_test,raw_dev,raw_test=train_test_split(texts,labels,raw,test_size=.15,random_state=42,stratify=labels)
    grid={"classifier__C":[.5,1,2] if not args.quick else [1],"features__word__ngram_range":[(1,2),(1,3)] if not args.quick else [(1,2)],"features__char__ngram_range":[(3,5),(3,6)] if not args.quick else [(3,5)],"features__word__min_df":[1,2] if not args.quick else [1],"features__char__min_df":[1,2] if not args.quick else [1]}
    folds=min(5,min(Counter(y_dev).values()));cv=StratifiedKFold(n_splits=folds,shuffle=True,random_state=42)
    search=GridSearchCV(pipeline(),grid,scoring="f1_macro",cv=cv,n_jobs=-1,return_train_score=False);search.fit(x_dev,y_dev)
    pred=search.best_estimator_.predict(x_test);decision=search.best_estimator_.decision_function(x_test);conf=confidence(decision)
    report=classification_report(y_test,pred,labels=LABELS,output_dict=True,zero_division=0);matrix=confusion_matrix(y_test,pred,labels=LABELS)
    errors=[]
    for comment,actual,guess,score in zip(raw_test,y_test,pred,conf):
        if actual!=guess:
            lower=comment.lower();category="negation" if any(x in lower for x in ["hindi","not ","wala"]) else "mixed_or_context" if any(x in lower for x in ["pero","but","kahit"]) else "slang_spelling_or_unseen_term"
            errors.append({"comment":comment,"actual_label":actual,"predicted_label":guess,"confidence":round(float(score),4),"suggested_error_category":category,"reviewer_notes":""})
    with (args.output_dir/"error_analysis.csv").open("w",encoding="utf-8-sig",newline="") as h:w=csv.DictWriter(h,fieldnames=["comment","actual_label","predicted_label","confidence","suggested_error_category","reviewer_notes"]);w.writeheader();w.writerows(errors)
    with (args.output_dir/"final_test_predictions.csv").open("w",encoding="utf-8-sig",newline="") as h:w=csv.writer(h);w.writerow(["comment","actual_label","predicted_label","confidence"]);w.writerows(zip(raw_test,y_test,pred,[round(float(x),4) for x in conf]))
    with (args.output_dir/"confusion_matrix.csv").open("w",encoding="utf-8-sig",newline="") as h:w=csv.writer(h);w.writerow(["actual/predicted"]+LABELS);[w.writerow([label]+matrix[i].tolist()) for i,label in enumerate(LABELS)]
    metrics={"dataset":str(args.dataset),"unique_samples":len(rows),"class_distribution":dict(counts),"development_count":len(x_dev),"untouched_final_test_count":len(x_test),"cross_validation_folds":folds,"selection_metric":"macro_f1","best_parameters":search.best_params_,"best_cv_macro_f1":round(float(search.best_score_),4),"final_test_accuracy":round(float(accuracy_score(y_test,pred)),4),"final_test_report":report,"labels":LABELS,"confusion_matrix":matrix.tolist(),"errors_for_review":len(errors),"warning":"Report as final research performance only when the input dataset and protocol have institutional approval."}
    (args.output_dir/"final_evaluation_metrics.json").write_text(json.dumps(metrics,indent=2,ensure_ascii=False),encoding="utf-8")
    deploy=clone(search.best_estimator_);deploy.fit(texts,labels);artifact={"features":deploy.named_steps["features"],"classifier":deploy.named_steps["classifier"],"labels":list(deploy.named_steps["classifier"].classes_),"metadata":{"method":"Tuned TF-IDF word/character features + LinearSVC","dataset":args.dataset.name,"sample_count":len(rows),"approved_for_production":bool(args.promote)}}
    candidate=args.output_dir/"candidate_svm_sentiment.joblib";joblib.dump(artifact,candidate)
    if args.promote:joblib.dump(artifact,BASE/"models"/"svm_sentiment.joblib");(BASE/"models"/"model_metrics.json").write_text(json.dumps(metrics,indent=2,ensure_ascii=False),encoding="utf-8")
    print(json.dumps({"candidate_model":str(candidate),"promoted":args.promote,"accuracy":metrics["final_test_accuracy"],"macro_f1":round(float(report["macro avg"]["f1-score"]),4),"best_parameters":search.best_params_},indent=2))
if __name__=="__main__":main()
