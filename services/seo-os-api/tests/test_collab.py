"""6. ütem: megjegyzések és @említés, belső jóváhagyás, ügyfél-jóváhagyás tokenes oldallal, értesítések és e-mail outbox,
napi emlékeztetők (upsell, lejárt feladat), havi riport."""

from datetime import date, timedelta

from conftest import SignedClient
from test_documents import full_project
from test_intelligence import run


def system():
    return SignedClient("system", wp_user=0)


def users(c):
    return {u["role"]: u for u in c.get("/users").json()}


def notes(c):
    return c.get("/me/notifications").json()


def test_comments_mentions_and_notifications(client, as_role):
    pid = full_project(client)
    dev, cm = as_role("developer"), as_role("content_manager")
    dev.get("/me"), cm.get("/me")
    doc_id = run(client, f"/projects/{pid}/documents/generate", {"doc_type": "dev_brief"})["document_id"]
    r = cm.post("/comments", json={"subject_type": "document", "subject_id": doc_id, "body": "@Laci nézd meg a 301-es sorokat, kérlek."})
    assert r.status_code == 201, r.text
    c = r.json()
    assert c["mentions"] == [users(client)["developer"]["id"]]
    n = notes(dev)
    assert n["unread"] == 1 and n["items"][0]["kind"] == "mention" and f"doc={doc_id}" in n["items"][0]["link"]
    # A dokumentum készítője (admin) „comment” értesítést kap
    assert any(i["kind"] == "comment" for i in notes(client)["items"])
    # Válasz, megoldás, szerkesztés csak saját
    reply = dev.post("/comments", json={"subject_type": "document", "subject_id": doc_id, "body": "Kész.", "parent_id": c["id"]}).json()
    assert dev.patch(f"/comments/{c['id']}", json={"body": "x"}).status_code == 403
    assert dev.patch(f"/comments/{c['id']}", json={"resolved": True}).json()["resolved"] is True
    listing = client.get(f"/comments?subject_type=document&subject_id={doc_id}").json()
    assert [x["id"] for x in listing] == [c["id"], reply["id"]] and listing[1]["parent_id"] == c["id"]
    # Olvasottnak jelölés
    assert dev.post("/me/notifications/read", json={"all": True}).json()["marked"] >= 1
    assert notes(dev)["unread"] == 0
    # Grafikus nem láthatja a fejlesztői brief megjegyzéseit
    assert as_role("designer").get(f"/comments?subject_type=document&subject_id={doc_id}").status_code == 403


def test_internal_and_client_approval(client, as_role):
    pid = full_project(client)
    seo = as_role("seo_manager")
    seo.get("/me")
    doc_id = run(client, f"/projects/{pid}/documents/generate", {"doc_type": "seo_strategy"})["document_id"]
    cm = as_role("content_manager")
    # Ügyfélnek nem küldhető belső jóváhagyás nélkül
    assert seo.post(f"/documents/{doc_id}/client-review", json={}).status_code == 409
    a = client.post("/approvals", json={"subject_type": "document", "subject_id": doc_id, "message": "Kérlek nézd át."}).json()
    assert client.get(f"/documents/{doc_id}").json()["status"] == "review"
    assert client.post("/approvals", json={"subject_type": "document", "subject_id": doc_id}).status_code == 409
    assert any(i["kind"] == "approval_request" for i in notes(seo)["items"])
    assert seo.get("/dashboard").json()["approvals"][0]["stage_label"] == "Rád vár"
    assert cm.post(f"/approvals/{a['id']}/decide", json={"decision": "approved"}).status_code == 403
    assert seo.post(f"/approvals/{a['id']}/decide", json={"decision": "changes_requested"}).status_code == 422
    d = seo.post(f"/approvals/{a['id']}/decide", json={"decision": "changes_requested", "note": "A versenytárs-táblát bővítsük."}).json()
    assert d["status"] == "changes_requested" and client.get(f"/documents/{doc_id}").json()["status"] == "draft"
    assert any(i["kind"] == "approval_decision" for i in notes(client)["items"])
    a2 = client.post("/approvals", json={"subject_type": "document", "subject_id": doc_id}).json()
    seo.post(f"/approvals/{a2['id']}/decide", json={"decision": "approved", "note": ""})
    assert client.get(f"/documents/{doc_id}").json()["status"] == "approved"

    # Ügyfél-jóváhagyás
    cr = seo.post(f"/documents/{doc_id}/client-review", json={"email": "owner@imperialkitchens.com", "message": "Kérjük, nézzék át."}).json()
    token = cr["token"]
    assert len(token) > 30 and cr["client"] == "Imperial Kitchens" and client.get(f"/documents/{doc_id}").json()["status"] == "sent"
    # A tokenes végpontot csak a WordPress rendszerhívása érheti el
    assert client.get(f"/review/{token}").status_code == 403
    sysc = system()
    page = sysc.get(f"/review/{token}").json()
    assert page["type_label"] == "SEO stratégia" and page["language"] == "en" and "pdf" in page["formats"] and page["status"] == "pending"
    pdf = sysc.get(f"/review/{token}/file/pdf")
    assert pdf.status_code == 200 and pdf.content.startswith(b"%PDF")
    assert sysc.get("/review/nincs-ilyen-token").status_code == 404
    # Kérdés, majd jóváhagyás
    assert sysc.post(f"/review/{token}", json={"decision": "comment", "name": "John", "note": "Mikor indul a tartalomgyártás?"}).json()["status"] == "pending"
    assert sysc.post(f"/review/{token}", json={"decision": "approved", "name": "John Smith", "note": "Rendben, mehet."}).json()["status"] == "approved"
    assert sysc.post(f"/review/{token}", json={"decision": "changes_requested", "name": "John", "note": "x"}).status_code == 409
    appr = client.get(f"/approvals?subject_type=document&subject_id={doc_id}").json()
    client_a = next(x for x in appr if x["stage"] == "client")
    assert client_a["status"] == "approved" and client_a["decided_by"] == "John Smith (ügyfél)" and client_a["viewed_at"]
    comments = client.get(f"/comments?subject_type=document&subject_id={doc_id}").json()
    assert any(c["is_client"] and "Mikor indul" in c["body"] for c in comments)
    assert any(i["kind"] == "approval_decision" and "Ügyfél jóváhagyta" in i["title"] for i in notes(seo)["items"])
    # Új kiküldés visszavonja a régit
    seo.post(f"/documents/{doc_id}/client-review", json={})
    assert sysc.get(f"/review/{token}").json()["status"] == "approved"


def test_outbox_and_daily(client, as_role, db):
    pid = full_project(client)
    dev = as_role("developer")
    dev.get("/me")
    client.post(f"/projects/{pid}/tasks/generate")
    task = client.get(f"/projects/{pid}/tasks?role=developer").json()[0]
    uid = users(client)["developer"]["id"]
    client.patch(f"/tasks/{task['id']}", json={"assignee_id": uid, "due_date": (date.today() - timedelta(days=2)).isoformat()})
    assert notes(dev)["items"][0]["kind"] == "task_assigned"
    sysc = system()
    assert client.get("/system/outbox").status_code == 403
    box = sysc.get("/system/outbox").json()
    item = next(x for x in box if x["kind"] == "task_assigned")
    assert item["email"] == "developer@example.com" and item["wp_user_id"] == 5 and item["link"].startswith("#/projects/")
    assert sysc.post("/system/outbox/ack", json={"sent": [x["id"] for x in box]}).json()["ok"]
    assert sysc.get("/system/outbox").json() == []
    # Napi: lejárt feladat + upsell (a projekt vége közel)
    # Az upsell dátuma a kezdésből számolódik (6 havi stratégiánál a 5. hónap eleje): 4 hónapja indult projekt → most esedékes
    start = date.today().replace(day=1)
    for _ in range(4):
        start = (start - timedelta(days=1)).replace(day=1)
    assert client.patch(f"/projects/{pid}", json={"start_date": start.isoformat()}).json()["upsell_reminder_at"] <= date.today().isoformat()
    res = sysc.post("/system/daily").json()
    assert res["overdue"] == 1 and res["upsell"] == 1
    assert sysc.post("/system/daily").json() == {"upsell": 0, "overdue": 0}  # csak egyszer szól
    assert any(i["kind"] == "task_overdue" for i in notes(dev)["items"])
    assert any(i["kind"] == "upsell" for i in notes(client)["items"])


def test_monthly_report(client):
    pid = full_project(client)
    client.post(f"/projects/{pid}/tasks/generate")
    t = client.get(f"/projects/{pid}/tasks?role=writer").json()[0]
    client.patch(f"/tasks/{t['id']}", json={"status": "done"})
    period = date.today().strftime("%Y-%m")
    res = client.post(f"/projects/{pid}/documents/generate", json={"doc_type": "monthly_report", "period": period, "language": "hu"}).json()
    assert res["status"] == "done", res
    d = client.get(f"/documents/{res['result']['document_id']}").json()
    kpis = d["content"]["sections"][0]["blocks"][-1]["items"]
    assert kpis[0]["value"] == "1"
    titles = [s["title"] for s in d["content"]["sections"]]
    assert "Keresési teljesítmény" in titles and "Elvégzett munka" in titles
    assert client.post(f"/projects/{pid}/documents/generate", json={"doc_type": "monthly_report", "period": "2026/9"}).status_code == 422
