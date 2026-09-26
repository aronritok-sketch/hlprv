"""Levelezés: postafiók, szinkron (hamis IMAP), küldés aláírással (hamis SMTP), ügyfél-párosítás, jogosultság."""

import base64
import re
from email.message import EmailMessage
from email.utils import formatdate

import pytest

from app.services.mail import transport

from conftest import SignedClient


class FakeImapServer:
    def __init__(self):
        self.folders = {
            "INBOX": {"uidvalidity": 11, "msgs": {}},
            "Sent": {"uidvalidity": 12, "msgs": {}},
            "Drafts": {"uidvalidity": 13, "msgs": {}},
        }
        self.stores: list[tuple] = []
        self.logins: list[tuple] = []
        self.password = "jó-jelszó"

    def add(self, folder: str, raw: bytes, flags: str = "") -> int:
        msgs = self.folders[folder]["msgs"]
        uid = max(msgs, default=0) + 1
        msgs[uid] = (raw, flags)
        return uid


class FakeImap:
    def __init__(self, server: FakeImapServer):
        self.s = server
        self.selected = None

    def login(self, user, password):
        if password != self.s.password:
            import imaplib

            raise imaplib.IMAP4.error("AUTHENTICATIONFAILED Invalid credentials")
        self.s.logins.append((user, password))
        return "OK", [b"Logged in"]

    def list(self):
        return "OK", [b'(\\HasNoChildren) "/" "INBOX"', b'(\\HasNoChildren \\Sent) "/" "Sent"', b'(\\HasNoChildren \\Drafts) "/" "Drafts"']

    def select(self, name, readonly=False):
        name = name.strip('"')
        if name not in self.s.folders:
            return "NO", [b"no such folder"]
        self.selected = name
        return "OK", [str(len(self.s.folders[name]["msgs"])).encode()]

    def response(self, code):
        return code, [str(self.s.folders[self.selected]["uidvalidity"]).encode()]

    def uid(self, cmd, *args):
        msgs = self.s.folders[self.selected]["msgs"]
        if cmd == "SEARCH":
            crit = args[-1]
            m = re.match(r"UID (\d+):\*", crit)
            uids = [u for u in msgs if not m or u >= int(m.group(1))]
            return "OK", [" ".join(str(u) for u in sorted(uids)).encode()]
        if cmd == "FETCH":
            out = []
            for u in [int(x) for x in args[0].split(",")]:
                raw, flags = msgs[u]
                out.append((f"{u} (UID {u} FLAGS ({flags}) BODY[] {{{len(raw)}}}".encode(), raw))
                out.append(b")")
            return "OK", out
        if cmd == "STORE":
            self.s.stores.append((self.selected, *args))
            return "OK", []
        raise AssertionError(cmd)

    def append(self, folder, flags, date, raw):
        self.s.add(folder.strip('"'), raw, "\\Seen")
        return "OK", []

    def logout(self):
        return "BYE", []


class FakeSmtp:
    sent: list[EmailMessage] = []

    def __init__(self, conn):
        self.conn = conn

    def login(self, user, password):
        return 235, b"ok"

    def send_message(self, msg):
        FakeSmtp.sent.append(msg)

    def quit(self):
        pass


@pytest.fixture
def imap(monkeypatch):
    server = FakeImapServer()
    monkeypatch.setattr(transport, "IMAP_FACTORY", lambda c: FakeImap(server))
    FakeSmtp.sent = []
    monkeypatch.setattr(transport, "SMTP_FACTORY", lambda c: FakeSmtp(c))
    return server


def raw_mail(frm, to, subject, *, html=None, text="Szia!", msgid=None, in_reply_to=None, attach=None, inline_png=False) -> bytes:
    m = EmailMessage()
    m["From"], m["To"], m["Subject"] = frm, to, subject
    m["Date"] = formatdate(localtime=True)
    m["Message-ID"] = msgid or f"<{abs(hash(subject + frm))}@example.com>"
    if in_reply_to:
        m["In-Reply-To"] = in_reply_to
        m["References"] = in_reply_to
    m.set_content(text)
    if html:
        m.add_alternative(html, subtype="html")
        if inline_png:
            png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")
            m.get_payload()[1].add_related(png, maintype="image", subtype="png", cid="<logo1>")
    if attach:
        m.add_attachment(attach[1], maintype="application", subtype="pdf", filename=attach[0])
    return bytes(m)


def make_account(c: SignedClient, **over) -> dict:
    body = {"email": "Dora@HelloProVision.com", "display_name": "Kiss Dóra", "owner_wp_user_id": 6,
            "imap_host": "mail.example.com", "imap_port": 993, "smtp_host": "mail.example.com", "smtp_port": 465,
            "password": "jó-jelszó", **over}
    r = c.post("/mail/accounts", json=body)
    assert r.status_code == 201, r.text
    return r.json()


def system() -> SignedClient:
    c = SignedClient("admin")
    c.role, c.wp_user, c.name = "system", 0, "WordPress"
    return c


def test_account_setup_sync_and_client_matching(imap):
    admin = SignedClient("admin")
    acc = make_account(admin)
    assert acc["email"] == "dora@helloprovision.com" and acc["has_password"] and "password" not in acc
    t = admin.post(f"/mail/accounts/{acc['id']}/test").json()
    assert t["ok"] and t["sent_folder"] == "Sent"

    imap.password = "rossz"
    bad = admin.post(f"/mail/accounts/{acc['id']}/test").json()
    assert not bad["ok"] and "jelszó" in bad["error"]
    imap.password = "jó-jelszó"

    r = system().post("/system/mail-contacts", json={"contacts": [{"client_id": 7, "name": "Imperial Kitchens", "emails": ["Mike@ImperialKitchens.example"]}], "full": True})
    assert r.status_code == 200 and r.json()["contacts"] == 1

    imap.add("INBOX", raw_mail("Mike Carter <mike@imperialkitchens.example>", "dora@helloprovision.com", "Photos for the Naples page",
                               html='<p onclick="x()">Hi <b>Dóra</b><script>alert(1)</script><img src="https://track.example/p.gif"><img src="cid:logo1"></p>',
                               inline_png=True, attach=("brief.pdf", b"%PDF-1.4 test")))
    imap.add("INBOX", raw_mail("Sarah <sarah@imperialkitchens.example>", "dora@helloprovision.com", "Invoice question"), "\\Seen")
    imap.add("INBOX", raw_mail("Random <someone@gmail.com>", "dora@helloprovision.com", "Hello"))
    imap.add("Sent", raw_mail("dora@helloprovision.com", "mike@imperialkitchens.example", "Re: Photos", msgid="<sent1@helloprovision.com>"))

    staff = SignedClient("staff")
    job = staff.post("/mail/sync", json={"account_id": acc["id"]}).json()
    assert job["status"] == "done", job

    lst = staff.get("/mail/messages").json()
    assert lst["total"] == 3
    by = {m["subject"]: m for m in lst["items"]}
    assert by["Photos for the Naples page"]["crm_client_id"] == 7 and not by["Photos for the Naples page"]["seen"]
    assert by["Invoice question"]["crm_client_id"] == 7 and by["Invoice question"]["seen"]  # ugyanaz a domain
    assert by["Hello"]["crm_client_id"] is None  # ingyenes levelező: nincs domain-párosítás
    assert staff.get("/mail/messages?folder=sent").json()["items"][0]["crm_client_id"] == 7
    assert staff.get("/mail/messages?client_id=7&folder=all").json()["total"] == 3

    me = staff.get("/mail/me").json()
    assert me["accounts"][0]["unread"] == 2 and not me["is_admin"]

    d = staff.get(f"/mail/messages/{by['Photos for the Naples page']['id']}").json()
    assert "<script" not in d["body_html"] and "onclick" not in d["body_html"] and "alert" not in d["body_html"]
    assert 'data-blocked-src="https://track.example/p.gif"' in d["body_html"] and d["blocked_images"] == 1
    assert 'src="data:image/png;base64,' in d["body_html"]  # beágyazott kép
    assert d["attachments"][0]["name"] == "brief.pdf" and d["seen"]
    assert ("INBOX", str(1), "+FLAGS", "(\\Seen)") in [tuple(x) for x in imap.stores]
    shown = staff.get(f"/mail/messages/{d['id']}?images=1").json()
    assert 'src="https://track.example/p.gif"' in shown["body_html"]
    dl = staff.get(f"/mail/messages/{d['id']}/attachments/0")
    assert dl.status_code == 200 and dl.content == b"%PDF-1.4 test"

    # Második szinkron: csak az új levél jön be.
    imap.add("INBOX", raw_mail("Mike Carter <mike@imperialkitchens.example>", "dora@helloprovision.com", "One more thing"))
    staff.post("/mail/sync", json={"account_id": acc["id"]})
    assert staff.get("/mail/messages").json()["total"] == 4


def test_access_is_per_owner(imap):
    admin = SignedClient("admin")
    mine = make_account(admin)
    other = make_account(admin, email="bence@helloprovision.com", owner_wp_user_id=3)
    shared = make_account(admin, email="info@helloprovision.com", owner_wp_user_id=None)
    staff = SignedClient("staff")
    ids = {a["id"] for a in staff.get("/mail/me").json()["accounts"]}
    assert ids == {mine["id"], shared["id"]}
    assert staff.get(f"/mail/messages?account_id={other['id']}").status_code == 404
    assert staff.post("/mail/sync", json={"account_id": other["id"]}).status_code == 404
    assert staff.get("/mail/accounts").status_code == 403
    assert staff.put("/mail/signature", json={"template": "x"}).status_code == 403
    assert SignedClient("designer").get("/mail/me").status_code == 200  # minden SEO OS szerepkör levelezhet
    assert len(admin.get("/mail/me?all=1").json()["accounts"]) == 3


def test_send_reply_with_signature_and_sent_dedupe(imap):
    admin = SignedClient("admin")
    acc = make_account(admin)
    admin.put("/mail/profiles", json={"wp_user_id": 6, "name": "Kiss Dóra", "title": "SEO manager", "phone": "+1 239 555 0100"})
    system().post("/system/mail-contacts", json={"contacts": [{"client_id": 7, "name": "Imperial", "emails": ["mike@imperialkitchens.example"]}]})
    imap.add("INBOX", raw_mail("Mike <mike@imperialkitchens.example>", "dora@helloprovision.com", "Question", msgid="<q1@imperial.example>"))
    staff = SignedClient("staff")
    staff.post("/mail/sync", json={"account_id": acc["id"]})
    q = staff.get("/mail/messages").json()["items"][0]

    up = staff.request("POST", "/mail/uploads", raw=b"hello pdf", headers={"X-Filename": "ajanlat.pdf", "Content-Type": "application/pdf"})
    assert up.status_code == 201
    r = staff.post("/mail/send", json={"account_id": acc["id"], "to": [{"email": "mike@imperialkitchens.example", "name": "Mike"}],
                                       "subject": "Re: Question", "body_html": "<p>Szia Mike!<script>x</script></p>",
                                       "reply_to_id": q["id"], "upload_ids": [up.json()["id"]]})
    assert r.status_code == 201, r.text
    sent = FakeSmtp.sent[-1]
    assert sent["In-Reply-To"] == "<q1@imperial.example>" and "Kiss Dóra" in sent["From"]
    html = sent.get_body(("html",)).get_content()
    assert "SEO manager" in html and "+1 239 555 0100" in html and "<script" not in html and "Mike írta" in html
    assert [p.get_filename() for p in sent.iter_attachments()] == ["ajanlat.pdf"]
    assert r.json()["crm_client_id"] == 7 and r.json()["thread_key"] == q["thread_key"]
    assert len(imap.folders["Sent"]["msgs"]) == 1  # a szerver Elküldött mappájába is bekerült

    # A következő szinkron nem hozza be másodszor (Message-ID alapján).
    staff.post("/mail/sync", json={"account_id": acc["id"]})
    assert staff.get("/mail/messages?folder=sent").json()["total"] == 1

    bad = staff.post("/mail/send", json={"account_id": acc["id"], "to": [{"email": "nem-cim"}], "subject": "x", "body_html": "x"})
    assert bad.status_code == 422


def test_gmail_does_not_append_and_signature_admin(imap):
    admin = SignedClient("admin")
    acc = make_account(admin, email="aron@helloprovision.com", owner_wp_user_id=1, smtp_host="smtp.gmail.com", imap_host="imap.gmail.com")
    tpl = '<p><b>{name}</b> – {title}<script>x</script></p>'
    s = admin.put("/mail/signature", json={"template": tpl}).json()
    assert "<script" not in s["template"]
    prev = admin.post("/mail/signature/preview", json={"template": "<i>{name} · {email}</i>"}).json()
    assert "{name}" not in prev["html"] and "{email}" not in prev["html"] and "@" in prev["html"]
    admin.post("/mail/send", json={"account_id": acc["id"], "to": [{"email": "x@example.com"}], "subject": "Hi", "body_html": "Hello"})
    assert len(imap.folders["Sent"]["msgs"]) == 0  # a Gmail magától elteszi
    assert "<b>" in FakeSmtp.sent[-1].get_body(("html",)).get_content()


def test_manual_link_remembers_contact_and_thread(imap):
    admin = SignedClient("admin")
    acc = make_account(admin)
    imap.add("INBOX", raw_mail("Tom <tom@bayshore.example>", "dora@helloprovision.com", "Boat storage", msgid="<b1@bayshore.example>"))
    imap.add("INBOX", raw_mail("Tom <tom@bayshore.example>", "dora@helloprovision.com", "Re: Boat storage", in_reply_to="<b1@bayshore.example>"))
    staff = SignedClient("staff")
    staff.post("/mail/sync", json={"account_id": acc["id"]})
    items = staff.get("/mail/messages").json()["items"]
    assert all(m["crm_client_id"] is None for m in items)
    first = next(m for m in items if m["subject"] == "Boat storage")
    r = staff.post(f"/mail/messages/{first['id']}/link", json={"crm_client_id": 4})
    assert r.json()["crm_client_id"] == 4
    assert all(m["crm_client_id"] == 4 for m in staff.get("/mail/messages").json()["items"])  # a szál többi levele is
    staff.post(f"/mail/messages/{first['id']}/link", json={"crm_task_id": 55})
    d = staff.get(f"/mail/messages/{first['id']}").json()
    assert d["crm_task_id"] == 55 and d["crm_client_id"] == 4 and len(d["thread"]) == 2
    # A megjegyzett címről jövő új levél már magától az ügyfélhez kerül.
    imap.add("INBOX", raw_mail("Tom <tom@bayshore.example>", "dora@helloprovision.com", "New topic"))
    staff.post("/mail/sync", json={"account_id": acc["id"]})
    new = next(m for m in staff.get("/mail/messages").json()["items"] if m["subject"] == "New topic")
    assert new["crm_client_id"] == 4


def test_uidvalidity_change_and_delete_account(imap, db):
    admin = SignedClient("admin")
    acc = make_account(admin)
    imap.add("INBOX", raw_mail("a@x.example", "dora@helloprovision.com", "One", attach=("a.pdf", b"%PDF")))
    SignedClient("staff").post("/mail/sync", json={"account_id": acc["id"]})
    imap.folders["INBOX"]["uidvalidity"] = 99
    imap.folders["INBOX"]["msgs"] = {}
    imap.add("INBOX", raw_mail("b@x.example", "dora@helloprovision.com", "Two"))
    SignedClient("staff").post("/mail/sync", json={"account_id": acc["id"]})
    assert {m["subject"] for m in SignedClient("staff").get("/mail/messages").json()["items"]} == {"One", "Two"}
    assert admin.delete(f"/mail/accounts/{acc['id']}").status_code == 204
    from app.models import StoredFile

    assert db.query(StoredFile).filter(StoredFile.kind == "mail").count() == 0
