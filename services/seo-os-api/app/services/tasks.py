"""Gyártási feladatok a jóváhagyott struktúrából, roadmapből és wireframe-ekből.

Szerepkörönként (fejlesztő, szövegíró, grafikus, SEO) konkrét teendő és elfogadási feltétel („akkor kész, ha…”),
ahogy a belső kivitelezési feladatlistákban. Ismételt generálásnál a `key` alapján frissít, nem duplikál, és a már
elkezdett / kész feladatokhoz nem nyúl. A feladatok a CRM-be továbbíthatók (WordPress bővítmény: crm/push-tasks).
"""

from collections import defaultdict
from datetime import timedelta
from typing import Optional

from sqlalchemy import select
from sqlalchemy.orm import Session

from ..models import Page, ProductionTask, Project, RoadmapItem
from .docfacts import Data

DONE_NEW_PAGE = "Az URL 200-as és indexelhető, önhivatkozó canonicallal; a H1, SEO title és meta description a briefnek megfelel; bekerült a sitemapbe és a navigációba / belső linkekbe."
DONE_UPDATE = "A H1, SEO title és meta description a briefnek megfelel; a canonical önhivatkozó; nincs duplikált title vagy H1 a webhelyen."
DONE_REDIRECT = "A forrás URL egy lépésben 301-gyel a célra mutat; a cél 200-as; a forrás nincs a sitemapben; a belső linkek közvetlenül a célra mutatnak."
DONE_REMOVE = "A régi URL 410-et vagy 301-et ad a legközelebbi releváns oldalra; nincs rá belső link; nincs a sitemapben."
DONE_SCHEMA = "A Rich Results Test hibamentes; a jelölés tartalma megegyezik az oldalon látható tartalommal."
DONE_LINKS = "Minden felsorolt link a megadott helyen és anchorral szerepel; egyik sem mutat átirányított vagy 4xx URL-re."
DONE_COPY = "A szöveg a wireframe minden blokkját lefedi, a primary kulcsszó a H1-ben és a bevezetőben szerepel, a CTA a megadott céloldalra visz; SEO managerrel átnézve."
DONE_ARTICLE = "A cikk a wireframe szerint készült, a primary kulcsszó természetesen szerepel, a belső linkek és a CTA a megadott oldalakra mutatnak; nincs kitalált proof vagy garancia."
DONE_DESIGN = "A Figma-terv a wireframe szakaszsorrendjét követi, mobil nézettel együtt; a képek webes exportja (WebP/AVIF, ≤100 KB, leíró fájlnév) átadva."
DONE_IMAGES = "A képek webes exportja (WebP/AVIF, ≤100 KB, leíró fájlnév, alt szöveg javaslattal) átadva a szövegírónak / fejlesztőnek."
DONE_PUBLISH = "A publikálási checklist minden pontja teljesül; az URL-re a Search Console-ban indexelés kérve."


def _task(role: str, key: str, title: str, *, priority: str = "P2", page: Optional[Page] = None, item: Optional[RoadmapItem] = None,
          action: str = "", source_url: str = "", target_url: str = "", done_when: str = "", notes: str = "", due=None) -> dict:
    return {
        "role": role, "key": key, "title": title[:512], "priority": priority or "P2",
        "page_id": page.id if page else None, "roadmap_item_id": item.id if item else None,
        "action": action, "source_url": source_url, "target_url": target_url, "done_when": done_when, "notes": notes, "due_date": due,
    }


def plan(d: Data) -> list[dict]:
    out: list[dict] = []
    links_by_page = defaultdict(list)
    for lk in d.links:
        links_by_page[lk.from_page_id].append(lk)
    for pg in d.pages:
        prim = d.primary(pg)
        prio = pg.priority or "P2"
        is_content = pg.page_type in ("article", "case_study")
        if pg.lifecycle == "new":
            out.append(_task("developer", f"dev:create:{pg.id}", f"Oldal létrehozása: {pg.url}", priority=prio, page=pg, target_url=pg.url,
                             action=f"H1: {pg.h1}\nSEO title: {pg.seo_title}\nMeta description: {pg.meta_description}\nSablon: {pg.page_type}",
                             done_when=DONE_NEW_PAGE))
        elif pg.lifecycle in ("existing", "update"):
            out.append(_task("developer", f"dev:update:{pg.id}", f"SEO elemek frissítése: {pg.url}", priority=prio, page=pg, source_url=pg.url, target_url=pg.url,
                             action=f"H1: {pg.h1}\nSEO title: {pg.seo_title}\nMeta description: {pg.meta_description}", done_when=DONE_UPDATE))
        elif pg.lifecycle in ("redirect", "merge"):
            out.append(_task("developer", f"dev:redirect:{pg.id}", f"301 átirányítás: {pg.url}", priority="P1", page=pg, source_url=pg.url,
                             target_url=pg.redirect_to, action="301 átirányítás egy lépésben; a régi URL kikerül a sitemapből.", done_when=DONE_REDIRECT))
            continue
        elif pg.lifecycle == "remove":
            out.append(_task("developer", f"dev:remove:{pg.id}", f"Oldal eltávolítása: {pg.url}", priority="P2", page=pg, source_url=pg.url,
                             target_url=pg.redirect_to, action="410, vagy 301 a legközelebbi releváns oldalra.", done_when=DONE_REMOVE))
            continue
        if pg.schema_types:
            out.append(_task("developer", f"dev:schema:{pg.id}", f"Strukturált adat: {pg.url}", priority=prio, page=pg, target_url=pg.url,
                             action="Schema típusok: " + ", ".join(pg.schema_types), done_when=DONE_SCHEMA))
        lks = links_by_page.get(pg.id) or []
        if lks:
            out.append(_task("developer", f"dev:links:{pg.id}", f"Belső linkek beépítése ({len(lks)}): {pg.url}", priority=prio, page=pg, source_url=pg.url,
                             action="\n".join(f"„{lk.anchor}” → {lk.to_url} ({lk.placement or lk.link_type})" for lk in lks), done_when=DONE_LINKS))
        if not is_content:
            out.append(_task("writer", f"writer:page:{pg.id}", f"Oldalszöveg: {pg.url}", priority=prio, page=pg, target_url=pg.url,
                             action=f"Primary: {prim.term if prim else '–'}\nSecondary: " + "; ".join(k.term for k in d.secondary(pg)[:8]) + f"\nH1: {pg.h1}\nCTA: {pg.cta_label}",
                             done_when=DONE_COPY, notes=pg.seo_goal))
            if pg.id in d.wireframes:
                w = d.wireframes[pg.id]
                out.append(_task("designer", f"design:page:{pg.id}", f"Oldalterv (Figma): {pg.url}", priority=prio, page=pg, target_url=pg.url,
                                 action="Szakaszok: " + " → ".join(w.flow), done_when=DONE_DESIGN, notes=w.visual_sequence or ""))
            out.append(_task("seo", f"seo:publish:{pg.id}", f"Publikálás előtti SEO-ellenőrzés: {pg.url}", priority=prio, page=pg, target_url=pg.url,
                             action="Publikálási checklist (SEO checklist dokumentum) végigvitele, majd indexelés kérése.", done_when=DONE_PUBLISH))
    for r in d.roadmap:
        kw = d.kw.get(r.keyword_id)
        due = r.due_date or (r.month + timedelta(days=14))
        wf = d.wireframes.get(r.page_id) if r.page_id else None
        out.append(_task("writer", f"writer:item:{r.id}", f"{'Case study' if r.content_type == 'case_study' else 'Cikk'}: {r.title}", priority=r.priority, item=r,
                         target_url=r.url, due=due, done_when=DONE_ARTICLE,
                         action=f"Primary: {kw.term if kw else '–'}\nKapcsolódó: " + "; ".join(r.related_keywords[:8]) + f"\nIrány: {r.content_direction}\nCTA: {r.cta}"
                                + ("\nWireframe: v" + str(wf.version) if wf else "")))
        out.append(_task("designer", f"design:item:{r.id}", f"Képek: {r.title}", priority=r.priority, item=r, target_url=r.url, due=due,
                         action="Kiemelt kép és belső képek; ha van folyamat, saját folyamatábra.", done_when=DONE_IMAGES))
        out.append(_task("seo", f"seo:item:{r.id}", f"Publikálás és indexelés: {r.title}", priority=r.priority, item=r, target_url=r.url,
                         due=due + timedelta(days=7), action="Belső linkek a céloldalakra; publikálási checklist; indexelés kérése.", done_when=DONE_PUBLISH))
    return out


def generate(db: Session, project: Project) -> dict:
    d = Data(db, project)
    wanted = plan(d)
    existing = {t.key: t for t in db.scalars(select(ProductionTask).where(ProductionTask.project_id == project.id)).all()}
    created = updated = 0
    keys = set()
    for w in wanted:
        keys.add(w["key"])
        t = existing.get(w["key"])
        if t is None:
            db.add(ProductionTask(project_id=project.id, **w))
            created += 1
        elif t.status == "todo" and not t.crm_task_id:
            for k, v in w.items():
                if k != "due_date" or not t.due_date:
                    setattr(t, k, v)
            updated += 1
    # Ami már nem része a tervnek és még nem kezdődött el: törlés.
    removed = 0
    for key, t in existing.items():
        if key and key not in keys and t.status == "todo" and not t.crm_task_id:
            db.delete(t)
            removed += 1
    db.flush()
    return {"created": created, "updated": updated, "removed": removed}
