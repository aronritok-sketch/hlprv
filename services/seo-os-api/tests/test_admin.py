"""Super admin: rendszerállapot, élesítési ellenőrzőlista, hibás feladat újraindítása, demó projekt."""

from conftest import SignedClient


def checks(st):
    return {c["key"]: c for c in st["checklist"]}


def test_status_and_checklist(client, as_role):
    assert as_role("seo_manager").get("/admin/status").status_code == 403
    st = client.get("/admin/status").json()
    assert st["database"]["up_to_date"] is True and st["database"]["revision"] == st["database"]["head"]
    c = checks(st)
    # Tesztkörnyezet: helyettesítő AI mód → hiba; nincs worker és WP cron életjel → hiba; kulcsok nincsenek → figyelmeztetés
    assert c["fake_mode"]["status"] == "error" and c["worker"]["status"] == "error" and c["wp_cron"]["status"] == "error"
    assert c["openai"]["status"] == "warn" and c["ahrefs"]["status"] == "optional" and c["migrations"]["status"] == "ok"
    assert c["worker"]["fix"].startswith("Indítsd el")
    # Életjelek: worker és WordPress cron
    from app.services.system import heartbeat

    heartbeat("worker", {"pid": 1})
    SignedClient("system", wp_user=0).get("/system/outbox")
    client.put("/settings", json={"openai_api_key": "sk-test-abcd"})
    st = client.get("/admin/status").json()
    c = checks(st)
    assert c["worker"]["status"] == "ok" and c["wp_cron"]["status"] == "ok" and c["openai"]["status"] == "ok"
    oa = next(i for i in st["integrations"] if i["key"] == "openai")
    assert oa["fields"][0] == {"key": "openai_api_key", "source": "db", "masked": "••••abcd"}
    assert st["services"]["worker"]["online"] is True and st["counts"]["projects"] == 0


def test_retry_and_demo(client, db):
    from app.models import Job

    j = Job(type="business_profile", project_id=None, payload={}, status="failed", error="Váratlan hiba: X")
    db.add(j)
    db.commit()
    st = client.get("/admin/status").json()
    assert st["jobs"]["failed"][0]["id"] == j.id
    assert client.post(f"/admin/jobs/{j.id}/retry").json()["status"] == "queued"
    assert client.post(f"/admin/jobs/{j.id}/retry").status_code == 409

    res = client.post("/admin/demo").json()
    assert res["job"]["status"] == "done", res["job"]
    pid = res["project_id"]
    assert res["job"]["result"]["tasks"] > 10
    pages = client.get(f"/projects/{pid}/pages").json()["pages"]
    assert any(p["url"] == "/naples-kitchen-remodeling/" for p in pages)
    assert client.get(f"/projects/{pid}/roadmap").json()["items"]
    assert len(client.get(f"/projects/{pid}/wireframes").json()) >= 1
    assert client.get(f"/projects/{pid}").json()["name"].startswith("DEMÓ")
