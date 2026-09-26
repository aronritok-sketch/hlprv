"""Demó projekt: kitalált, de valósághű adatokkal végigviszi a folyamatot (kutatás → elemzés → struktúra → roadmap →
wireframe → feladatok), hogy élesítés előtt API-kulcsok nélkül is kipróbálható legyen minden fül."""

from types import SimpleNamespace

from sqlalchemy import select
from sqlalchemy.orm import Session

from ..jobs.runner import Progress, handler
from ..models import Import, Job, Page, Project
from . import analysis, planning, storage
from . import tasks as task_service
from .research import apply_import

# kulcsszó, volumen, KD, CPC, szándék – egy floridai konyhafelújító cég jellegzetes keresései
ROWS = [
    ("kitchen remodeling naples", 880, 12, 18.5, "Commercial,Local"),
    ("kitchen remodeling fort myers", 590, 8, 16.0, "Commercial,Local"),
    ("kitchen remodeling contractors naples", 170, 5, 20.0, "Commercial,Local"),
    ("kitchen cabinets naples", 320, 15, 9.0, "Commercial,Local"),
    ("cabinet installers fort myers", 90, 3, 7.0, "Commercial,Local"),
    ("kitchen remodeling services", 2400, 35, 14.0, "Commercial"),
    ("custom kitchen cabinets", 6600, 42, 6.0, "Commercial"),
    ("kitchen remodel cost", 14800, 30, 4.0, "Informational"),
    ("how to plan a kitchen remodel", 1300, 18, 2.0, "Informational"),
    ("kitchen remodel ideas", 22000, 45, 1.5, "Informational"),
    ("kitchen remodel timeline", 880, 10, 2.0, "Informational"),
    ("best kitchen remodeling companies", 1000, 20, 12.0, "Commercial,Informational"),
    ("quartz vs granite countertops", 9900, 25, 1.8, "Informational"),
    ("kitchen cabinet water damage repair", 320, 6, 8.0, "Informational"),
    ("competitorbrand kitchens", 400, 1, 1.0, "Navigational,Branded"),
    ("diy kitchen cabinets", 5400, 22, 1.0, "Informational"),
    ("kitchen cabinet refacing cost", 4400, 20, 5.0, "Informational"),
]

PROJECT = dict(
    name="DEMÓ – Imperial Kitchens", domain="demo-imperialkitchens.com", industry="Kitchen Remodeling", market="US",
    locations=["Naples", "Fort Myers"], content_language="en-US",
    business_services=[{"name": "Kitchen Remodeling", "high_margin": True, "priority": True}, {"name": "Cabinets"}],
    target_audience="Homeowners in Naples and Fort Myers planning a kitchen remodel",
    business_goals="More qualified remodeling leads from organic search", excluded_topics=["DIY"],
    scope=["keyword_research", "structure", "content_strategy", "content_mgmt"], strategy_months=6, content_per_month=4,
)


@handler("demo_project")
def job_demo(db: Session, job: Job, progress: Progress):
    pid = job.project_id
    project = db.get(Project, pid)
    sub = lambda payload=None: SimpleNamespace(id=job.id, project_id=pid, payload=payload or {}, created_by=job.created_by)  # noqa: E731
    progress(0.05, "Kulcsszavak importálása…")
    from ..routers.research import detect_for

    tsv = "Keyword\tVolume\tKD\tCPC\tIntents\n" + "\n".join(f"{k}\t{v}\t{kd}\t{cpc}\t{i}" for k, v, kd, cpc, i in ROWS)
    f = storage.save(db, tsv.encode("utf-16"), "ahrefs-keywords-demo.csv", "import", pid, job.created_by, "text/csv")
    imp = Import(project_id=pid, file_id=f.id, source="manual", created_by=job.created_by)
    imp.file = f
    db.add(imp)
    detect_for(db, imp, project)
    db.flush()
    apply_import(db, imp)
    db.flush()
    progress(0.25, "Kulcsszó-elemzés…")
    analysis.job_analyze(db, sub(), progress)
    db.flush()
    progress(0.5, "Oldalstruktúra…")
    planning.job_structure(db, sub(), progress)
    db.flush()
    progress(0.7, "Roadmap…")
    planning.job_roadmap(db, sub({"include_paid": True}), progress)
    db.flush()
    progress(0.85, "Wireframe-ek…")
    pages = db.scalars(select(Page).where(Page.project_id == pid, Page.page_type.in_(["city_service", "service_hub"])).order_by(Page.id).limit(2)).all()
    planning.job_wireframes(db, sub({"page_ids": [p.id for p in pages]}), progress)
    db.flush()
    res = task_service.generate(db, project)
    return {"project_id": pid, "tasks": res["created"]}
