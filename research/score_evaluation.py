from __future__ import annotations
import argparse, csv, json
from collections import defaultdict
from pathlib import Path

def interpretation(mean: float) -> str:
    if mean >= 4.21: return "Very High / Strongly Agree"
    if mean >= 3.41: return "High / Agree"
    if mean >= 2.61: return "Moderate / Neutral"
    if mean >= 1.81: return "Low / Disagree"
    return "Very Low / Strongly Disagree"

def main() -> None:
    p=argparse.ArgumentParser(description="Calculate ISO/IEC 25010 evaluation means without inventing evaluator responses.")
    p.add_argument("input",type=Path);p.add_argument("--output-dir",type=Path,default=Path("research/results"));args=p.parse_args();args.output_dir.mkdir(parents=True,exist_ok=True)
    categories=defaultdict(list);evaluators=set();invalid=[]
    with args.input.open("r",encoding="utf-8-sig",newline="") as h:
        for number,row in enumerate(csv.DictReader(h),2):
            try:score=float((row.get("score_1_to_5")or"").strip())
            except ValueError:invalid.append(number);continue
            if not 1<=score<=5:invalid.append(number);continue
            category=(row.get("category")or"Uncategorized").strip();categories[category].append(score);evaluators.add((row.get("evaluation_id")or"").strip())
    summary=[];all_scores=[]
    for category,scores in sorted(categories.items()):
        mean=sum(scores)/len(scores);all_scores.extend(scores);summary.append({"category":category,"responses":len(scores),"mean":round(mean,3),"interpretation":interpretation(mean)})
    overall=sum(all_scores)/len(all_scores) if all_scores else 0
    report={"unique_evaluators":len(evaluators-{""}),"valid_scores":len(all_scores),"invalid_or_blank_rows":invalid,"categories":summary,"overall_mean":round(overall,3),"overall_interpretation":interpretation(overall) if all_scores else "No completed responses"}
    (args.output_dir/"evaluation_summary.json").write_text(json.dumps(report,indent=2),encoding="utf-8")
    with (args.output_dir/"evaluation_summary.csv").open("w",encoding="utf-8-sig",newline="") as h:w=csv.DictWriter(h,fieldnames=["category","responses","mean","interpretation"]);w.writeheader();w.writerows(summary)
    print(json.dumps(report,indent=2))
if __name__=="__main__":main()
