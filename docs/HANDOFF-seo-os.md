# Átadás: SEO OS, crawler, levelezés, frissítő (2026. szeptember 26.)

Az SEO OS munkamenet (ág: `claude/great-gauss-jba4b5`) átadja a munkáját a CRM munkamenetnek (`claude/laughing-wozniak-dpwf28`).
Az átadáskor a két ág ugyanazon a commiton áll (a CRM 0.8.3 is benne van), **félkész, nem commitolt munka nincs**.

## 0. Az első teendő az átadás után: a Render ága

**A Render az `seo-os-api` szolgáltatást a `claude/great-gauss-jba4b5` ágról telepíti.** Ha ezután csak a `claude/laughing-wozniak-dpwf28` ágon folyik a munka, az SEO OS szerver (és a levelezés szerver oldala) nem frissül.

- Megoldás: Render → seo-os-api → Settings → Build & Deploy → **Branch: `claude/laughing-wozniak-dpwf28`**. Az adatbázis (seo-os-db) nem ághoz kötött, nem kell hozzányúlni. A Blueprint (Render → Blueprints → hlprv) ágát is érdemes átállítani, hogy a `render.yaml` változásai is átjöjjenek.
- Ezt Áronnak kell megcsinálnia (Render fiók); a „Holnapi teendők” listában már ez szerepel.
- A `.github/workflows/plugin-releases.yml` mindkét ágra figyel; ha a `great-gauss` ág megszűnik, onnan kivehető.

## 1. Állapot területenként

### SEO OS szerver és API (`services/seo-os-api`)
**Kész:** FastAPI + SQLAlchemy 2 + Alembic (`seo` séma, migrációk 0001–0009), HMAC-aláírt kérések a WordPresstől, Postgres alapú feladatsor és worker (életjellel), kutatás és importálók (Ahrefs, Keyword Planner, DataForSEO, saját XLSX), AI-elemzés és klaszterezés (OpenAI), struktúra, tartalomstratégia, roadmap, wireframe, dokumentumok (Claude: PDF / DOCX / XLSX, WeasyPrint), gyártási feladatok és CRM-be küldés, technikai audit (Screaming Frog exportokból, XL/M/S témakörök), DataForSEO / Ahrefs API költségkerettel és 30 napos gyorsítótárral, jóváhagyások (belső + ügyfél), megjegyzések @említéssel, értesítések, e-mail kimenő sor, napi emlékeztetők (upsell, lejárt feladat), havi rangsor-pillanatkép, havi riport-mutatók a portálnak (`/system/report-metrics`), portál-döntés fogadása (`/system/client-decision`), admin rendszeroldal (`/admin/status`), demó projekt.
**Élesben:** fut a Renderen (Frankfurt), `https://seo-os-api.onrender.com`; Áron szerint a Rendszer állapot oldalon az API, az adatbázis és a worker zöld (a WordPress cron, a napi emlékeztetők és az SF ügynök még piros, mert nincs beállítva).
**Nincs kész / ismert hiányok:** nincs valódi API-kulcs beállítva (AI nélkül a dokumentumok sablonszöveggel készülnek, ezt a felület jelzi); a Google Search Console adat közvetlen bekötése az SEO OS-be nincs (a riport-mutatók a CRM connectoraiból jönnek).

### Screaming Frog ügynök (`services/seo-os-crawler`)
**Kész:** `agent.py` (pull modell: `/wp-json/hpv-seo/v1/agent/*`, Bearer token), `--check`, `--once`, exportok feltöltése, újrapróbálás; Dockeres változat is. Hamis SF CLI-vel végigtesztelve.
**Nincs elkezdve élesben:** Áron irodai gépére még nincs telepítve (a lépések: `docs/seo-os/TELEPITES.md` 3. fejezet és a „Holnapi teendők” lista).

### SEO OS WordPress bővítmény (`plugins/helloprovision-seo-os`, 0.3.0)
**Kész:** saját sötét felület a `seo.helloprovision.com` címen (Preact/htm, build nélkül), szerepkörök (Admin, SEO manager, Content manager, Designer, Fejlesztő – a felhasználó profiljában), aláírt proxy az API felé, Screaming Frog ügynök végpont, CRM-összekötés (projekt `kind=seo`, feladat `assignee_id` 0, retainer `period`), ügyfél-jóváhagyás a portálon (`hpv_approval_upsert` / `send`, `hpv_approval_decided`), havi riport-mutatók (`hpv_report_metrics`), nyilvános jóváhagyó oldal (`/review/{token}`) CRM-ügyfél nélküli esetre, cron (5 perces kimenő levelek, napi feladatok), Rendszer állapot oldal (kulcsok, csapat, SF, saját minták, frissítés-állapot).
**Élesben:** a CRM WordPressbe feltöltve (egy korábbi változat); a 0.3.0 zip Áronnál van, holnap tölti fel.

### Levelezés (`plugins/helloprovision-mail` 0.2.0 + `app/services/mail`, `app/routers/mail.py`)
**Kész (v1):** postafiókok (IMAP/SMTP, bármely szolgáltató), titkosított jelszó (Fernet), 2 percenkénti szinkron a workerben (Beérkezett + Elküldött, UIDVALIDITY, mellékletek a fájltárban, beágyazott képek), biztonságos HTML (szkript, esemény, követőpixel tiltva; képek gombra), küldés válasszal / továbbítással / melléklettel, egységes aláírás (admin sablon + munkatárs adatai), a küldött levél a szerver Elküldött mappájába is (Gmail / M365 kivétel), olvasottság visszaírása a szerverre (háttérfeladat), ügyfél-párosítás cím és domain alapján, kézi hozzárendelés megjegyzéssel, szálak, feladat levélből, „Felvétel érdeklődőként”, tölcsér: válasz új érdeklődőnek → `contacted`. Új szerepkör az API-ban: `staff` (CRM munkatárs, csak `mail.use`). A CRM-be a portál bővítés-pontján át épül be (`docs/integrations/crm-extensions.md`, `docs/integrations/mail.md`).
**Élesben:** nincs még telepítve (a zip Áronnál van). Áron válasza: **nincs Google Workspace / Microsoft 365, a tárhely webmailjét használják** → a sima IMAP/SMTP út a megfelelő, OAuth nem kell. A pontos IMAP/SMTP szervernevet a webmail beállításaiból kell kiolvasni (általában `mail.helloprovision.com`, 993 / 465 SSL).
**Nincs kész / korlátok:**
- A szerveren (webmailben) történt változások a már letöltött levelekre nem jönnek vissza: olvasottság, törlés, áthelyezés. Csak az új levelek jönnek; a CRM-ből jelölt olvasottság viszont kimegy a szerverre.
- Nincs piszkozat, nincs mappakezelés (csak Beérkezett + Elküldött), nincs címzett-automatikus kiegészítés, nincs levél-sablon.
- Az ügyfél adatlapján nincs „Levelek” fül (a levelezés oldal `?client=ID` szűrővel tudja; a portál `client.js`-ébe kellene egy link / fül).
- A küldés szinkron: lassú SMTP szerver esetén a kérés legfeljebb 30 mp-ig várhat.
- Az első szinkron fiókonként mappánként legfeljebb 300 levelet hoz be az elmúlt 30 napból.

### Frissítő és kiadás (`includes/github-updater.php`, `.github/workflows/plugin-releases.yml`)
**Kész:** minden HelloProVision bővítményben ugyanaz a fájl (portal, seo-os, mail, grader, reviews); a WordPress a GitHub kiadásokat frissítésként látja és magától telepíti; a workflow verzióemeléskor kiad (`<slug>-v<X.Y.Z>`, `<slug>.zip`), a lemaradt ág nem ad ki régebbit. Kiadva: grader 1.0.1, reviews 1.0.1, seo-os 0.3.0, portal 0.8.1–0.8.3, mail 0.1.0–0.2.0.
**Élesben még nem működik:** kell a `HPV_GITHUB_TOKEN` mindkét wp-config.php-ba (a CRM és a weboldal), és egy utolsó kézi zip-feltöltés. A Rendszer állapot oldalon látszik, működik-e.

### Telepítés: Render, tárhely, nginx, TiDB (`render.yaml`, `services/deploy/`, `docs/seo-os/TELEPITES.md`)
**Kész:** Render Blueprint (`seo-os-api` Standard + `seo-os-db` Basic-256mb, Frankfurt; API és worker egy konténerben: `SEO_OS_RUN_WORKER=1`, `start.sh`: migráció, jogosultság-váltás setprivvel), a tárhely teljes `nginx.conf`-ja (`services/deploy/helloprovision-host/nginx.conf`: crm / clients / seo közös blokk, SSL), `hpv-check.php` (állapot + nginx újratöltés terminál nélkül, `?reload=<token>`), `hpv-install-wp.php` (WordPress letöltése a szerverrel).
**Élesben:** `crm.`, `clients.`, `seo.helloprovision.com` HTTPS-en a `/home/container/webroot/crm` WordPresst szolgálja ki (TiDB, `crm_` előtag); a fő weboldal a `webroot/wordpress`.

## 2. Nyitott kérdések Áronnak

| Kérdés | Ha… | Akkor |
|---|---|---|
| Levelező szolgáltató | a tárhely webmailje marad (Áron válasza) | IMAP/SMTP postafiókonként, a postafiók jelszavával; semmi fejlesztés nem kell. |
| | később Google Workspace | alkalmazásjelszóval azonnal megy; OAuth (szolgáltatásfiók, domain-szintű delegálás) fejlesztés lenne. |
| | később Microsoft 365 | az IMAP/SMTP hitelesítést az admin központban engedélyezni kell, vagy Graph API / OAuth fejlesztés. |
| Render ág átállítása | megcsinálja | a szerver a CRM chat ágáról frissül. |
| | nem | az SEO OS szerver a régi ágon ragad (lásd 0.). |
| API-kulcsok (Anthropic, OpenAI, DataForSEO, Ahrefs) | megadja | valódi elemzés és dokumentumok. |
| | nem | az SEO OS csak demó/sablon szinten használható. |
| Screaming Frog gép | melyik gépen fusson, legyen-e mindig bekapcsolva | az ügynök csak akkor dolgozik, ha a gép fut. |

## 3. Konfiguráció (csak a nevek – értéket, titkot ide ne írj)

**Render (seo-os-api → Environment):**
- `SEO_OS_DATABASE_URL` – a Render adja (`fromDatabase`), a `postgres://` előtagot a kód átírja.
- `SEO_OS_HMAC_SECRET` – Render generálta; ugyanez kerül a wp-config `HPV_SEO_OS_SECRET`-be.
- `SEO_OS_AGENT_TOKEN` – Render generálta; ugyanez az SF ügynök `HPV_AGENT_TOKEN`-je.
- `SEO_OS_RUN_WORKER=1`, `SEO_OS_STORAGE_DIR=/var/lib/seo-os/files` (5 GB lemez).
- Nem kötelező: `SEO_OS_MAIL_KEY` (a levelezés jelszavainak kulcsa; **ajánlott beállítani, mielőtt postafiókot vesznek fel**, lásd 5.), `SEO_OS_MAIL_SYNC_SECONDS` (alap 120, 0 = ki), `SEO_OS_OPENAI_API_KEY`, `SEO_OS_ANTHROPIC_API_KEY`, `SEO_OS_DATAFORSEO_LOGIN`, `SEO_OS_DATAFORSEO_PASSWORD`, `SEO_OS_AHREFS_API_KEY` (ezek az admin felületen is megadhatók, az erősebb), `WEB_CONCURRENCY`, `PORT`.

**CRM wp-config.php (`webroot/crm`):**
- `HPV_SEO_OS_SECRET`, `HPV_SEO_OS_API_URL` – beállítva.
- `DISABLE_WP_CRON` – a külső cron beállítása után.
- `HPV_GITHUB_TOKEN` – fine-grained, csak a `hlprv` repó, Contents: Read-only.
- Nem kötelező: `HPV_SEO_HOST` (alap: seo.helloprovision.com), `HPV_FORCE_HTTPS`, `HPV_UPDATE_REPO`, `HPV_AUTO_UPDATE`.
- `DB_COLLATE` = `utf8mb4_general_ci` (TiDB miatt, beállítva).
- A CRM saját kulcsai (portál): `HPV_AI_API_KEY`, `HPV_DAILY_API_KEY`, `HPV_STRIPE_*`, `HPV_SZAMLAZZ_AGENT_KEY`, `HPV_QBO_*`, `HPV_GOOGLE_*`, `HPV_META_ACCESS_TOKEN`, `HPV_BRIDGE_SECRET`, `HPV_SITE_URL` (README).

**Weboldal wp-config.php (`webroot/wordpress`):** `HPV_GITHUB_TOKEN`, `HPV_BRIDGE_SECRET`, `HPV_CRM_URL`, `DISABLE_WP_CRON` (külső cron után).

**SF ügynök (`agent.env` az irodai gépen):** `HPV_SEO_URL`, `HPV_AGENT_TOKEN`, `HPV_AGENT_NAME`, nem kötelező: `SF_CLI`, `HPV_POLL_SECONDS`, `HPV_MAX_HOURS`, `HPV_WORK_DIR`.

**Külső cron:** cron-job.org, 5 percenként `https://seo.helloprovision.com/wp-cron.php?doing_wp_cron` és `https://helloprovision.com/wp-cron.php?doing_wp_cron`.

## 4. Tesztek

- **API:** `cd services/seo-os-api && pip install -e '.[dev]' && pytest -q`. Valódi PostgreSQL kell: `SEO_OS_TEST_DATABASE_URL` (alap: `postgresql+psycopg://seo:seo@localhost/seo_os_test`); a teszt a `seo` sémát eldobja és újra migrál. WeasyPrinthez a Pango könyvtárak kellenek (Dockerfile). Az AI-t `SEO_OS_LLM_FAKE=1`, a feladatsort `SEO_OS_JOBS_INLINE=1` helyettesíti (a conftest beállítja). A levelezést hamis IMAP/SMTP szerver teszteli (`tests/test_mail.py`).
- **PHP egységtesztek** (WordPress nélkül): `php tests/seo-os.php`, `seo.php`, `grader.php`, `reviews.php`, `i18n.php`.
- **WordPress integrációs tesztek:** teszt WordPress a portál, az SEO OS és a levelezés bővítménnyel, a wp-config-ban `HPV_SEO_OS_SECRET` (min. 32 karakter) és `HPV_SEO_OS_API_URL`; `wp eval-file tests/<x>-integration.php`. A `leads-integration` a weboldal mu-pluginját és `HPV_TEST_SITE_URL`-t kéri.
- **Az átadáskori eredmény:** pytest 51/51, PHP egységtesztek 5/5, integrációs tesztek 17/17 (a leads kimaradt, mert ahhoz a weboldal mu-pluginja kell). Helyben SQLite-os WordPressszel futott (sqlite-database-integration), PHP beépített szerverrel.
- **Csapda:** a WordPress integrációs tesztek egy része minden kimenő HTTP kérést számol; más bővítmények (SEO OS felhasználó-szinkron, levelezés ügyfél-cím küldés) beállításkori hívásai ezt elrontják. Két tesztben (reports, video) ezért a mérés előtt nullázzuk a naplót – új tesztnél ugyanígy.

## 5. Ismert hibák, kockázatok, csapdák

- **Levelezés jelszókulcs:** `SEO_OS_MAIL_KEY` nélkül a kulcs a `SEO_OS_HMAC_SECRET`-ből származik. Ha a HMAC titkot lecserélik, minden postafiók jelszavát újra meg kell adni. Az első postafiók előtt érdemes `SEO_OS_MAIL_KEY`-t beállítani a Renderen (és onnantól nem cserélni).
- **Render lemez (5 GB):** a levelek mellékletei (fájlonként max. 15 MB) és a dokumentumok ide kerülnek; figyelni kell. A levélhez feltöltött, el nem küldött mellékletek takarítója (`mail.cleanup_old_uploads`) megvan, de még nincs ütemezve.
- **A WordPress egyszálú fejlesztői szervere** (php -S) a levelezés lassú SMTP-hívása alatt minden más kérést feltart – élesben (php-fpm) ez nem gond, de helyi próbánál zavaró.
- **Tárhely:** nincs terminál, nincs újraindítás gomb; nginx újratöltés csak a `hpv-check.php`-val (SIGHUP, előtte `nginx -t`). Az openresty `access_by_lua_block` indítja a php-fpm-et (`/scripts/lambda-startup.sh`). A PHP alap memóriakorlát 15 MB, a wp-config emeli.
- **Biztonság (Áron teendője):** a webrootban `tinyfilemanager.php` és adminer volt – törölni kell; a fő weboldal wp-config-jában a `wp-settings.php` után két WP_DEBUG sor van – törölni; **az adatbázis jelszava szerepelt a chatben – a szolgáltatóval le kell cseréltetni.** A `hpv-check.php` / `hpv-install-wp.php` fájlokat használat után törölni kell (2 óra után maguktól letiltanak).
- **Ismert tartalmi hibák az SEO OS-ben:** az angol nyelvű SEO stratégia PDF-ben az oldal-célok („A(z) Kitchen Remodeling szolgáltatás…”) magyarul maradnak – a `docfacts` oldal-cél szövegét lokalizálni kell; AI-kulcs nélkül a case study címek „[Client/Project]: [Specific Outcome]” sablonok.
- **Bővítés-pont:** a levelezés a CRM `ui.js`-ét `window.HPV_APP.ui` címről importálja (verzió nélkül, hogy egy példány legyen). Ha a portál a `ui.js`-t átnevezi vagy verziót tesz az import útvonalába, a levelezés modul nem töltődik be.
- **Frissítő:** a verzióemelés nélküli változtatás nem jut ki; a `mu-plugins/` nem frissül magától; a fine-grained token lejár (naptárba!).

## 6. Javasolt következő lépések

1. Render ág átállítása a CRM chat ágára (0.) – Áron teendője, a listában benne van.
2. Áron holnapi listája: zipek, GitHub token, cron, WP Mail SMTP, API-kulcsok, postafiókok (webmail IMAP/SMTP), aláírás, csapattagok, SF ügynök, Bitrix24 import, biztonsági takarítás. `SEO_OS_MAIL_KEY` a postafiókok előtt.
3. Élesben végigpróbálni: egy SEO projekt a demó helyett valódi adatokkal, ügyfél-jóváhagyás a portálon, havi riport-mutatók, egy crawl az SF ügynökkel, levél küldése / fogadása / feladat levélből.
4. Levelezés v2: „Levelek” fül az ügyfél adatlapján, olvasottság / törlés visszaszinkron a szerverről, piszkozat, címzett-kiegészítés a CRM kapcsolattartókból, levélsablonok, a feltöltés-takarító ütemezése.
5. SEO OS: az angol dokumentumok oldal-cél szövegének lokalizálása; Search Console közvetlen bekötése a rangsor-pillanatképhez.

## 7. Ami nincs a repóban

- **Élő dokumentumok (Claude artifactok, Áron fiókjában):** Rendszerkönyv képernyőképekkel `https://claude.ai/artifact/BuNQTP2LrQRqW2EKastvgx` (+ PDF, Áronnak elküldve); Holnapi teendők (pipálható) `https://claude.ai/artifact/5Rsq2Ge4bq95it85PbbTbg` (a Render lépés már az új ágat írja).
- **Kézi beállítások az élő rendszeren:** a tárhely `nginx.conf`-ja a repóban lévő teljes változatra cserélve és újratöltve; a CRM WordPress telepítve (TiDB, `crm_` előtag, admin: aronritok); a CRM wp-config-ja Áronnál van (a sablonja nincs a repóban, mert jelszavakat tartalmaz); Render Blueprint telepítve, a wp-config-ban a két SEO OS konstans kitöltve.
- **Áronnak elküldött, még fel nem töltött zipek:** portal 0.8.2, seo-os 0.3.0, mail 0.1.0, grader 1.0.1, reviews 1.0.1 (a token beállítása után a frissebbekre magától frissülnek).
- **Demó környezet:** a dokumentáció képernyőképeihez használt demó adatok (kitalált ügyfelek, projektek, levelek) seed szkriptjei csak az átadó munkamenet ideiglenes könyvtárában voltak, a repóban nincsenek; az SEO OS saját demó projektje (`POST /admin/demo`) a repóban van.
- **Áron döntései a beszélgetésből:** a felület magyar; a levelezés a tárhely webmailjével megy (nincs Google / Microsoft); mostantól egy chat dolgozik a repón.
