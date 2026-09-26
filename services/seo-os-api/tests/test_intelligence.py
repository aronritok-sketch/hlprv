"""3. ütem: kulcsszó-elemzés, klaszterek, jóváhagyás, oldalstruktúra (kannibalizáció-védelem), roadmap, wireframe-ek,
valamint az OpenAI-útvonal egy helyettesítő klienssel."""

import json
from types import SimpleNamespace
from urllib.parse import quote

import pytest
from conftest import SignedClient, make_project

ROWS = [
    # keyword, volume, KD, CPC, intents
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


def seed_project(c: SignedClient, **over) -> dict:
    base = dict(
        name="Imperial Kitchens", domain="imperialkitchens.com", industry="Kitchen Remodeling",
        locations=["Naples", "Fort Myers"], content_language="en-US",
        business_services=[{"name": "Kitchen Remodeling", "high_margin": True, "priority": True}, {"name": "Cabinets"}],
        excluded_topics=["DIY"], scope=["keyword_research", "structure", "content_strategy", "content_mgmt"],
        start_date="2026-10-01", strategy_months=6, content_per_month=4,
        competitors=[{"domain": "competitorbrand.com"}], seed_keywords=[], client={"name": "Imperial Kitchens"},
    )
    base.update(over)
    p = make_project(c, **base)
    tsv = "Keyword\tVolume\tKD\tCPC\tIntents\n" + "\n".join(f"{k}\t{v}\t{kd}\t{cpc}\t{i}" for k, v, kd, cpc, i in ROWS)
    r = c.request("POST", f"/projects/{p['id']}/imports", raw=tsv.encode("utf-16"),
                  headers={"X-Filename": quote("ahrefs-keywords.csv"), "Content-Type": "text/csv"})
    imp = r.json()["import"]
    assert c.post(f"/projects/{p['id']}/imports/{imp['id']}/run").json()["job"]["status"] == "done"
    return p


def run(c, path, body=None):
    r = c.post(path, json=body or {})
    assert r.status_code == 200, r.text
    job = r.json()
    assert job["status"] == "done", job
    return job["result"]


def rows_by_term(c, pid):
    return {k["term"]: k for k in c.get(f"/projects/{pid}/keywords").json()["keywords"]}


def test_heuristic_analysis_and_review(client, as_role):
    p = seed_project(client)
    pid = p["id"]
    assert as_role("content_manager").post(f"/projects/{pid}/analysis/run").status_code == 403
    res = run(client, f"/projects/{pid}/analysis/run")
    assert res["method"] == "heuristic" and res["keywords"] == 16  # a DIY kizárva
    k = rows_by_term(client, pid)
    assert k["diy kitchen cabinets"]["analysis"] is None and k["diy kitchen cabinets"]["is_excluded"]
    assert k["kitchen remodeling naples"]["intent"] == "commercial" and k["kitchen remodeling naples"]["is_local"]
    assert k["kitchen remodeling naples"]["bucket"] == "local"
    assert k["kitchen remodeling naples"]["business_value"] == 5  # kiemelt, magas árrésű szolgáltatás
    assert k["kitchen remodeling naples"]["priority"] == "P1"
    assert k["kitchen remodel ideas"]["intent"] == "informational"
    assert k["best kitchen remodeling companies"]["bucket"] == "comparison"
    assert k["kitchen cabinet water damage repair"]["intent"] == "problem"
    assert k["competitorbrand kitchens"]["intent"] == "navigational" and k["competitorbrand kitchens"]["priority"] == "parked"
    # A volumen nem dönt egyedül: a nagy volumenű információs téma nem előzi meg a helyi kereskedelmit.
    assert k["kitchen remodeling naples"]["priority_score"] > k["kitchen remodel ideas"]["priority_score"]
    clusters = client.get(f"/projects/{pid}/clusters").json()
    names = {c["name"] for c in clusters}
    assert "Kitchen Remodeling" in names and "Cabinets" in names
    kr = next(c for c in clusters if c["name"] == "Kitchen Remodeling")
    assert {"local", "informational"} <= set(kr["buckets"])
    s = client.get(f"/projects/{pid}/analysis/summary").json()
    assert s["analysed"] == 16 and s["by_status"] == {"suggested": 16}

    # Kézi felülírás: a pontszám újraszámolódik, a státusz „overridden”
    kid = k["kitchen remodel timeline"]["id"]
    r = client.patch(f"/projects/{pid}/keywords/{kid}/analysis", json={"commercial_opportunity": 5, "notes": "Fontos döntési kérdés"})
    assert r.status_code == 200 and r.json()["analysis"] == "overridden"
    assert r.json()["priority_score"] > k["kitchen remodel timeline"]["priority_score"]
    assert client.patch(f"/projects/{pid}/keywords/{kid}/analysis", json={"intent": "bogus"}).status_code == 422
    # Újrafuttatás nem írja felül a kézi döntést
    run(client, f"/projects/{pid}/analysis/run")
    assert rows_by_term(client, pid)["kitchen remodel timeline"]["analysis"] == "overridden"

    # Állapotkapuk: AI-elemzés kész → ok; ügyfél-ellenőrzés előtt a P1-eket át kell nézni
    seo = as_role("seo_manager")
    for to in ("researching", "ai_analysis_complete", "seo_review"):
        assert seo.post(f"/projects/{pid}/transition", json={"to": to}).status_code == 200
    r = seo.post(f"/projects/{pid}/transition", json={"to": "client_review"})
    assert r.status_code == 409 and "P1" in r.json()["problems"][0]
    assert client.post(f"/projects/{pid}/analysis/accept", json={"priority": "P1"}).json()["accepted"] > 0
    assert seo.post(f"/projects/{pid}/transition", json={"to": "client_review"}).status_code == 200


def test_structure_mapping_and_cannibalization(client, as_role):
    p = seed_project(client)
    pid = p["id"]
    run(client, f"/projects/{pid}/analysis/run")
    res = run(client, f"/projects/{pid}/structure/generate")
    assert res["pages"] >= 9
    data = client.get(f"/projects/{pid}/pages").json()
    pages = {pg["url"]: pg for pg in data["pages"]}
    assert "/" in pages and pages["/"]["primary"] is None  # a főoldal nem céloz szolgáltatási kulcsszót
    assert pages["/kitchen-remodeling/"]["page_type"] == "service_hub"
    assert pages["/naples-kitchen-remodeling/"]["page_type"] == "city_service"
    assert pages["/naples-kitchen-remodeling/"]["primary"]["term"] == "kitchen remodeling naples"
    assert pages["/fort-myers-kitchen-remodeling/"]["primary"]["term"] == "kitchen remodeling fort myers"
    # Nincs hub-szintű helyi keresés → nincs városi hub; a városi landing a regionális szolgáltatási oldal alá kerül
    assert "/naples/" not in pages
    assert pages["/naples-kitchen-remodeling/"]["parent_id"] == pages["/kitchen-remodeling/"]["id"]
    assert pages["/kitchen-remodeling/"]["primary"]["term"] == "kitchen remodeling services"
    assert "/insights/" in pages and "/contact/" in pages
    # Egy elsődleges kulcsszó = egy URL
    prim = [pg["primary"]["id"] for pg in data["pages"] if pg["primary"]]
    assert len(prim) == len(set(prim))
    # Belső linkek: városi landing → hub, főoldal → szolgáltatás, CTA gomb → kapcsolat
    links = data["links"]
    naples = pages["/naples-kitchen-remodeling/"]["id"]
    assert any(lk["from_page_id"] == naples and lk["to_url"] == "/kitchen-remodeling/" for lk in links)
    assert any(lk["from_page_id"] == naples and lk["link_type"] == "button" and lk["to_url"] == "/contact/" for lk in links)
    # A kulcsszótáblában megjelenik a javasolt URL és a szerep
    k = rows_by_term(client, pid)
    assert k["kitchen remodeling naples"]["url"] == "/naples-kitchen-remodeling/" and k["kitchen remodeling naples"]["role"] == "primary"
    # Kannibalizáció: ugyanaz a primary két oldalon nem lehet
    other = pages["/fort-myers-kitchen-remodeling/"]["id"]
    r = client.put(f"/projects/{pid}/pages/{other}/keywords", json={"primary": k["kitchen remodeling naples"]["id"], "secondary": []})
    assert r.status_code == 409 and "Egy elsődleges kulcsszó = egy URL" in r.json()["message"]
    # Hub-szintű keresés megjelenik → a hub is (az iparág = szolgáltatás, ezért csak a város nevét kapja)
    client.post(f"/projects/{pid}/keywords", json={"terms": ["kitchen and bath contractor naples"]})
    run(client, f"/projects/{pid}/analysis/run")
    run(client, f"/projects/{pid}/structure/generate")
    pages = {pg["url"]: pg for pg in client.get(f"/projects/{pid}/pages").json()["pages"]}
    assert pages["/naples/"]["page_type"] == "city_hub"
    assert pages["/naples/"]["primary"]["term"] == "kitchen and bath contractor naples"
    assert "/fort-myers/" not in pages
    assert pages["/naples-kitchen-remodeling/"]["parent_id"] == pages["/naples/"]["id"]
    # Kézi oldal, duplikált URL
    assert client.post(f"/projects/{pid}/pages", json={"url": "naples", "page_type": "city_hub"}).status_code == 409
    r = client.post(f"/projects/{pid}/pages", json={"url": "/kitchen-remodel-cost-guide/", "page_type": "article"})
    assert r.status_code == 201
    # Kézi oldal újragenerálás után megmarad, a javasoltak újraépülnek
    run(client, f"/projects/{pid}/structure/generate")
    urls = {pg["url"] for pg in client.get(f"/projects/{pid}/pages").json()["pages"]}
    assert "/kitchen-remodel-cost-guide/" in urls and "/naples-kitchen-remodeling/" in urls
    warnings = client.get(f"/projects/{pid}/pages").json()["warnings"]
    assert any(w["kind"] == "no_primary" and w["url"] == "/kitchen-remodel-cost-guide/" for w in warnings)
    assert as_role("developer").get(f"/projects/{pid}/pages").status_code == 200
    assert as_role("developer").post(f"/projects/{pid}/structure/generate").status_code == 403


def test_roadmap_and_wireframes(client, as_role):
    p = seed_project(client)
    pid = p["id"]
    run(client, f"/projects/{pid}/analysis/run")
    run(client, f"/projects/{pid}/structure/generate")
    res = run(client, f"/projects/{pid}/roadmap/generate")
    assert res["months"] == 6
    rm = client.get(f"/projects/{pid}/roadmap").json()
    items = rm["items"]
    cases = [i for i in items if i["content_type"] == "case_study"]
    arts = [i for i in items if i["content_type"] == "article"]
    assert len(cases) == 6  # havi 4 tartalomnál havonta 1 case study
    assert arts and all(i["url"].startswith("/insights/") for i in arts)
    assert items[0]["month"] == "2026-10-01"
    # A cikk a szolgáltatási oldalra vezet, nem másolja
    cost = next(i for i in arts if i["keyword"] == "kitchen remodel cost")
    assert cost["internal_links"] and cost["internal_links"][0] in ("/kitchen-remodeling/", "/naples-kitchen-remodeling/", "/fort-myers-kitchen-remodeling/")
    assert cost["paid"]["search_target"] == cost["internal_links"][0]
    assert len(rm["measurement"]) == 6 and rm["measurement"][0]["period"] == "Indulás + 0–30 nap"
    # A városi, szolgáltatóválasztó P2 keresések nem blogba kerülnek
    assert all(i["keyword"] != "cabinet installers fort myers" for i in arts)
    # Content manager: csak állapot/felelős/határidő
    cm = as_role("content_manager")
    assert cm.patch(f"/projects/{pid}/roadmap/{cost['id']}", json={"status": "writing"}).status_code == 200
    assert cm.patch(f"/projects/{pid}/roadmap/{cost['id']}", json={"title": "x"}).status_code == 403

    pages = {pg["url"]: pg for pg in client.get(f"/projects/{pid}/pages").json()["pages"]}
    art_page = pages[cost["url"]]
    city = pages["/naples-kitchen-remodeling/"]
    res = run(client, f"/projects/{pid}/wireframes/generate", {"page_ids": [art_page["id"], city["id"], pages[cases[0]["url"]]["id"]]})
    assert res["wireframes"] == 3 and res["method"] == "heuristic"
    wl = {w["url"]: w for w in client.get(f"/projects/{pid}/wireframes").json()}
    wf = client.get(f"/projects/{pid}/wireframes/{wl[cost['url']]['wireframe']['id']}").json()
    assert wf["sections"][0]["h2"] == "Intro / opening problem"
    assert "kitchen remodel cost" in wf["sections"][0]["seo_usage"]
    assert any(lk["target"] == cost["internal_links"][0] for lk in wf["links"])
    assert "Angolul kutass" in wf["essence"]
    assert any("Top 1" in f for f in wf["forbidden"])
    cw = client.get(f"/projects/{pid}/wireframes/{wl['/naples-kitchen-remodeling/']['wireframe']['id']}").json()
    assert "Naples" in cw["essence"] and cw["sections"][0]["h2"] == "Hero"
    case = client.get(f"/projects/{pid}/wireframes/{wl[cases[0]['url']]['wireframe']['id']}").json()
    assert len(case["sections"]) == 11 and case["inputs"]
    # Jóváhagyás: csak SEO manager/admin; jóváhagyott nem szerkeszthető
    assert cm.patch(f"/projects/{pid}/wireframes/{wf['id']}", json={"status": "approved"}).status_code == 403
    assert cm.patch(f"/projects/{pid}/wireframes/{wf['id']}", json={"essence": "Pontosítva"}).status_code == 200
    assert as_role("seo_manager").patch(f"/projects/{pid}/wireframes/{wf['id']}", json={"status": "approved"}).json()["status"] == "approved"
    assert client.patch(f"/projects/{pid}/wireframes/{wf['id']}", json={"essence": "x"}).status_code == 409
    # Új verzió
    run(client, f"/projects/{pid}/wireframes/generate", {"page_ids": [art_page["id"]]})
    wl = {w["url"]: w for w in client.get(f"/projects/{pid}/wireframes").json()}
    assert wl[cost["url"]]["wireframe"]["version"] == 2
    # Roadmap újragenerálás: az elfogadott tételek maradnak
    client.post(f"/projects/{pid}/roadmap/accept")
    run(client, f"/projects/{pid}/roadmap/generate")
    assert len(client.get(f"/projects/{pid}/roadmap").json()["items"]) >= len(items)
    d = client.get("/dashboard").json()
    assert "roadmap_due" in d


class FakeOpenAI:
    """Az OpenAI SDK helyettesítője: a séma neve alapján érvényes JSON-t ad vissza."""

    def __init__(self, calls):
        self.calls = calls
        self.chat = SimpleNamespace(completions=SimpleNamespace(create=self._chat))
        self.embeddings = SimpleNamespace(create=self._embed)

    def _chat(self, model, messages, response_format, temperature):
        name = response_format["json_schema"]["name"]
        self.calls.append(name)
        user = messages[1]["content"]
        if name == "classify_keywords":
            items = json.loads(user.split("Kulcsszavak (JSON):\n", 1)[1])
            out = {"items": [{"id": it["id"], "intent": "informational" if "idea" in it["keyword"] else "commercial",
                              "is_local": "naples" in it["keyword"], "business_value": 4, "commercial_opportunity": 4,
                              "reason": "AI indoklás", "translation": "fordítás: " + it["keyword"]} for it in items]}
        elif name == "name_clusters":
            groups = json.loads(user.split("Csoportok (JSON):\n", 1)[1])
            out = {"clusters": [{"key": g["key"], "name": "AI " + g["keywords"][0].title(), "pillar": "Kitchen Remodeling",
                                 "cannibalization_rule": "Ne másolja a landinget."} for g in groups]}
        elif name == "business_profile":
            out = {"summary": "Prémium konyhafelújítás.", "positioning": "Naples prémium", "services": [], "audiences": [],
                   "differentiators": ["Saját asztalosüzem"], "proof_assets": []}
        else:
            raise AssertionError(name)
        return SimpleNamespace(choices=[SimpleNamespace(message=SimpleNamespace(content=json.dumps(out)))],
                               usage=SimpleNamespace(prompt_tokens=100, completion_tokens=50))

    def _embed(self, model, input):
        # Két irány: „cabinet” vs minden más – így két klaszter jön ki.
        data = [SimpleNamespace(embedding=[1.0, 0.0] if "cabinet" in t else [0.0, 1.0]) for t in input]
        return SimpleNamespace(data=data, usage=SimpleNamespace(prompt_tokens=10))


@pytest.fixture
def openai_on(client):
    from app.config import get_settings
    from app.services import llm

    calls = []
    client.put("/settings", json={"openai_api_key": "sk-test-1234"})
    get_settings().llm_fake = False
    llm._client_factory = lambda key: FakeOpenAI(calls)
    yield calls
    get_settings().llm_fake = True
    llm._client_factory = None


def test_llm_path_with_cache_and_logs(client, openai_on, db):
    p = seed_project(client)
    pid = p["id"]
    res = run(client, f"/projects/{pid}/analysis/run")
    assert res["method"] == "llm"
    assert openai_on.count("classify_keywords") == 1 and openai_on.count("name_clusters") == 1
    k = rows_by_term(client, pid)
    assert k["kitchen remodel ideas"]["intent"] == "informational"
    assert k["kitchen remodeling naples"]["reason"].startswith("AI indoklás")
    assert k["kitchen remodeling naples"]["translation"] == "fordítás: kitchen remodeling naples"
    clusters = {c["name"] for c in client.get(f"/projects/{pid}/clusters").json()}
    assert len(clusters) == 2 and all(n.startswith("AI ") for n in clusters)
    # Második futás: gyorsítótárból, nincs új hívás
    run(client, f"/projects/{pid}/analysis/run")
    assert openai_on.count("classify_keywords") == 1
    from app.models import ApiLog
    from sqlalchemy import select

    logs = db.scalars(select(ApiLog).where(ApiLog.project_id == pid)).all()
    assert any(lg.endpoint.startswith("chat:classify_keywords") and lg.tokens_in == 100 for lg in logs)
    # Üzleti profil AI-val
    run(client, f"/projects/{pid}/profile/generate")
    prof = client.get(f"/projects/{pid}/profile").json()
    assert prof["source"] == "ai" and prof["differentiators"] == ["Saját asztalosüzem"]
