"""1. ütem: aláírás, jogosultságok, projektek, bekérés, állapotgép, vezérlőpult."""

import time

from conftest import SignedClient, make_project


def test_signature_required_and_verified(client):
    raw = client.http.get("/me")
    assert raw.status_code == 401
    assert client.get("/me").status_code == 200
    # Lejárt időbélyeg
    assert client.get("/me", ts=int(time.time()) - 3600).status_code == 401
    # Rossz titok
    assert SignedClient("admin", secret="wrong-" + "y" * 40).get("/me").status_code == 401
    # Manipulált törzs: az aláírás a törzs hash-ét is védi
    r = client.request("POST", "/projects", raw=b'{"name":"x"}', headers={})
    assert r.status_code in (201, 422)  # érvényes aláírás
    body = b'{"name":"x"}'
    ts = str(int(time.time()))
    from app.auth import canonical, sign
    from conftest import SECRET

    sig = sign(SECRET, canonical("POST", "/projects", ts, body, "1", "admin", "", ""))
    r = client.http.post(
        "/projects",
        content=b'{"name":"y"}',
        headers={"X-HPV-Timestamp": ts, "X-HPV-User": "1", "X-HPV-Role": "admin", "X-HPV-Signature": sig},
    )
    assert r.status_code == 401


def test_me_mirrors_user_and_caps(client, as_role):
    me = client.get("/me").json()
    assert me["role"] == "admin" and "settings.manage" in me["caps"]
    dev = as_role("developer").get("/me").json()
    assert "project.edit" not in dev["caps"] and "tasks.update" in dev["caps"]
    users = client.get("/users").json()
    assert {u["role"] for u in users} == {"admin", "developer"}
    assert as_role("designer").get("/users").status_code == 403


def test_unknown_role_rejected():
    assert SignedClient("hacker").get("/me").status_code == 403


def test_create_project_normalizes_and_seeds_intake(client):
    p = make_project(client)
    assert p["domain"] == "imperialkitchens.com"
    assert p["market"] == "US"
    assert p["locations"] == ["Naples", "Fort Myers"]
    assert [c["domain"] for c in p["competitors"]] == ["competitor-a.com", "competitor-b.com"]
    assert p["status"] == "draft"
    intake = {i["key"]: i["status"] for i in p["intake_items"]}
    assert intake["domain"] == "received"
    assert intake["seed_keywords"] == "missing"
    assert intake["admin_access"] == "n_a"  # nincs technikai audit a terjedelemben
    assert intake["style_guide"] == "missing"  # tartalommenedzsment van
    # 6 hónapos tartalommenedzsment: az 5. hónap elején upsell
    assert p["upsell_reminder_at"] == "2027-01-01"
    assert p["allowed_transitions"]  # admin


def test_scope_change_updates_intake(client):
    p = make_project(client, scope=["keyword_research"])
    r = client.patch(f"/projects/{p['id']}", json={"scope": ["keyword_research", "tech_audit"]})
    assert r.status_code == 200
    intake = {i["key"]: i["status"] for i in r.json()["intake_items"]}
    assert intake["admin_access"] == "missing"
    r = client.patch(f"/projects/{p['id']}/intake/admin_access", json={"status": "received", "note": "1Password"})
    assert r.json()["status"] == "received"
    assert client.patch(f"/projects/{p['id']}/intake/nope", json={"status": "received"}).status_code == 404
    assert client.patch(f"/projects/{p['id']}", json={"scope": ["bogus"]}).status_code == 422


def test_validation_messages(client):
    r = client.post("/projects", json={"name": "", "domain": "nodot", "client": {"name": "X"}})
    assert r.status_code == 422
    assert r.json()["message"] == "Hibás adatok."
    assert any("domain" in p for p in r.json()["problems"])


def test_competitors_and_seeds_replace(client):
    p = make_project(client)
    r = client.put(f"/projects/{p['id']}/competitors", json=[{"domain": "new.com"}, {"domain": "NEW.com"}, {"domain": "competitor-a.com"}])
    assert sorted(c["domain"] for c in r.json()) == ["competitor-a.com", "new.com"]
    r = client.put(f"/projects/{p['id']}/seed-keywords", json=[{"keyword": "  a   b ", "kind": "location"}, {"keyword": "A B"}])
    assert [(s["keyword"], s["kind"]) for s in r.json()] == [("a b", "location")]


def test_workflow_roles(as_role):
    seo = as_role("seo_manager")
    p = make_project(seo)
    pid = p["id"]
    assert p["allowed_transitions"] == ["researching"]
    # Designer / fejlesztő: csak olvas
    for role in ("designer", "developer"):
        c = as_role(role)
        assert c.get(f"/projects/{pid}").status_code == 200
        assert c.post(f"/projects/{pid}/transition", json={"to": "researching"}).status_code == 403
        assert c.patch(f"/projects/{pid}", json={"name": "x"}).status_code == 403
    # Kihagyás nem megy
    assert seo.post(f"/projects/{pid}/transition", json={"to": "seo_review"}).status_code == 403
    seo.post(f"/projects/{pid}/keywords", json={"terms": ["kitchen remodeling naples"]})
    seo.post(f"/projects/{pid}/analysis/run")
    seo.post(f"/projects/{pid}/analysis/accept", json={})
    for to in ("researching", "ai_analysis_complete", "seo_review", "client_review"):
        r = seo.post(f"/projects/{pid}/transition", json={"to": to})
        assert r.status_code == 200, r.text
    assert set(r.json()["allowed_transitions"]) == {"approved", "seo_review"}
    # Az ügyfél módosítást kért: vissza
    assert seo.post(f"/projects/{pid}/transition", json={"to": "seo_review", "note": "ügyfél kérte"}).status_code == 200
    for to in ("client_review", "approved", "production"):
        seo.post(f"/projects/{pid}/transition", json={"to": to})
    cm = as_role("content_manager")
    assert cm.get(f"/projects/{pid}").json()["allowed_transitions"] == ["completed"]
    assert cm.post(f"/projects/{pid}/transition", json={"to": "completed"}).status_code == 200
    hist = seo.get(f"/projects/{pid}/history").json()
    assert [h["to"] for h in hist["statuses"]][:2] == ["completed", "production"]
    assert hist["statuses"][0]["user"] == "Zsolti Content"
    assert hist["statuses"][-1]["to"] == "draft"


def test_admin_override_needs_note(client):
    p = make_project(client)
    pid = p["id"]
    r = client.post(f"/projects/{pid}/transition", json={"to": "approved"})
    assert r.status_code == 422
    r = client.post(f"/projects/{pid}/transition", json={"to": "approved", "note": "régi projekt átvétele", "force": True})
    assert r.status_code == 200 and r.json()["status"] == "approved"


def test_archive_blocks_transition(client):
    p = make_project(client)
    client.post(f"/projects/{p['id']}/archive")
    assert client.post(f"/projects/{p['id']}/transition", json={"to": "researching"}).status_code == 409
    assert client.get("/projects").json() == []
    assert len(client.get("/projects?archived=true").json()) == 1


def test_dashboard(client, as_role):
    make_project(client)
    make_project(client, name="Art Mirror", domain="artmirror.hu", client={"name": "Art Mirror"}, start_date="2026-05-01")
    d = as_role("designer").get("/dashboard").json()
    assert len(d["projects"]) == 2
    assert d["counts"]["draft"] == 2
    assert any(m["key"] == "seed_keywords" for m in d["missing_intake"])
    assert [u["name"] for u in d["upsell"]] == ["Art Mirror"]


def test_clients_and_settings(client, as_role):
    make_project(client)
    assert client.get("/clients?search=imperial").json()[0]["name"] == "Imperial Kitchens"
    r = client.post("/clients", json={"name": "CRM ügyfél", "crm_client_id": 7})
    assert r.status_code == 201
    assert client.post("/clients", json={"name": "Dup", "crm_client_id": 7}).status_code == 409
    assert as_role("seo_manager").get("/settings").status_code == 403
    r = client.put("/settings", json={"openai_api_key": "sk-secret-1234", "openai_model": "gpt-x"})
    view = {s["key"]: s for s in r.json()}
    assert view["openai_api_key"]["value"] == "••••1234"
    # A maszkolt érték visszaküldése nem írja felül a kulcsot
    client.put("/settings", json={"openai_api_key": "••••1234"})
    view = {s["key"]: s for s in client.get("/settings").json()}
    assert view["openai_api_key"]["value"] == "••••1234" and view["openai_model"]["value"] == "gpt-x"


def test_user_sync_admin_only(client, as_role):
    r = client.put("/users/42", json={"role": "developer", "display_name": "Máté", "email": "m@x.hu"})
    assert r.status_code == 200 and r.json()["role"] == "developer"
    client.put("/users/42", json={"role": "developer", "is_active": False})
    assert SignedClient("developer", wp_user=42).get("/me").status_code == 403
    assert as_role("seo_manager").put("/users/43", json={"role": "admin"}).status_code == 403
