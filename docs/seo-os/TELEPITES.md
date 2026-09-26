# HelloProVision SEO OS – telepítés és üzemeltetés

A rendszer három részből áll:

| Rész | Hol fut | Mit csinál |
|---|---|---|
| **WordPress bővítmény** (`plugins/helloprovision-seo-os`) | a CRM WordPress-telepítésén, a `seo.helloprovision.com` aldomainen | felület (sötét SPA), belépés és szerepkörök, aláírt proxy az API felé, e-mail kiküldés, ügyfél-jóváhagyó oldal, CRM / portál kapcsolat |
| **API + worker** (`services/seo-os-api`) | ugyanazon a szerveren, Dockerben | adatbázis (PostgreSQL), kutatás, AI-elemzés (OpenAI), dokumentumok (Claude), audit, háttérfeladatok |
| **Screaming Frog ügynök** (`services/seo-os-crawler`) | ahol a licencelt Screaming Frog fut (irodai gép vagy szerver) | crawlok futtatása a felületről indítva, exportok feltöltése |

A böngésző csak a WordPresst látja; az API nem nyilvános (csak a `127.0.0.1:8100` címen figyel), minden kérés
HMAC-SHA256 aláírással érkezik a bővítménytől.

## 0/A. Telepítés terminál nélkül (tárhely FileZillával + Render)

Ha a WordPress tárhelyén nincs terminál (SSH) és Docker, a háttérrendszer (API, worker, adatbázis) egy felhős
szolgáltatáson fut, amit böngészőből lehet beállítani; a WordPress (felület) marad a saját tárhelyen.

1. **Tárhely-ellenőrzés:** `services/deploy/hpv-check.php` feltöltése FileZillával a seo aldomain mappájába, majd
   megnyitás böngészőben (`https://seo.helloprovision.com/hpv-check.php`). Megmutatja a PHP-t, a WordPresst, a mappa-
   linkeket, az írási jogokat és a kimenő kapcsolatokat. 2 óra után magától letiltja magát; használat után töröld.
2. **Render:** render.com → regisztráció GitHubbal → *New → Blueprint* → ez a repó. A `render.yaml` alapján létrejön az
   adatbázis és az `seo-os-api` szolgáltatás (a háttérfeladat-worker ugyanebben fut, `SEO_OS_RUN_WORKER=1`). A
   csomagokat (plan) itt ellenőrizd: az API-nak legalább 1 GB memória ajánlott. Az első telepítés után a
   `https://…onrender.com/health` címen `{"ok":true}` a válasz.
3. **Titkok:** Render → `seo-os-api` → *Environment*: a `SEO_OS_HMAC_SECRET` a wp-config.php-ba kell, a
   `SEO_OS_AGENT_TOKEN` a Screaming Frog ügynökhöz.
4. **WordPress (FileZillával):** a `plugins/helloprovision-seo-os` (és ha még nincs, a `helloprovision-portal`) mappa a
   `wp-content/plugins` alá; a `wp-config.php`-ba:
   ```php
   define( 'HPV_SEO_OS_SECRET', '…a Render SEO_OS_HMAC_SECRET értéke…' );
   define( 'HPV_SEO_OS_API_URL', 'https://seo-os-api.onrender.com' ); // a Render által adott cím
   define( 'DISABLE_WP_CRON', true );
   ```
   Majd a WordPress adminban a bővítmények bekapcsolása.
   **TiDB adatbázisnál** (a hibaüzenetben „Unsupported collation when new collation is enabled”) a wp-config.php-ban
   `define( 'DB_COLLATE', 'utf8mb4_general_ci' );` kell: az `utf8mb4_unicode_ci` értéknél a WordPress a TiDB által nem
   ismert `utf8mb4_unicode_520_ci`-re vált, és egyetlen tábla sem jön létre.
5. **Cron terminál nélkül:** a tárhely vezérlőpultjának cron-beállításában, vagy ingyenes külső szolgáltatással
   (pl. cron-job.org) 5 percenként: `https://seo.helloprovision.com/wp-cron.php?doing_wp_cron`.
6. **Ellenőrzés:** `seo.helloprovision.com` → Beállítások → Rendszer állapot – az ellenőrzőlista mutatja, mi van hátra.

## 0/B. Gyors telepítés szkriptekkel (saját szerver, SSH-val)

1. A repó `services` mappáját töltsd fel a szerverre `/opt/seo-os` néven (FileZilla).
2. Felmérés (csak olvas, jelszót nem ír ki): `sudo bash /opt/seo-os/deploy/diagnose.sh` – az eredmény a
   `/tmp/hpv-diagnose.txt` fájlban is megvan.
3. Telepítés: `sudo bash /opt/seo-os/deploy/install-api.sh` – feltelepíti a Dockert, véletlen titkokkal létrehozza a
   `.env`-et, elindítja az adatbázist, az API-t és a workert, végül kiírja a `wp-config.php`-ba másolandó sorokat,
   a cron sort és az ügynök tokenjét. Frissítéskor: új fájlok feltöltése, majd ugyanez a parancs.

## 1. Szerver: API, worker, adatbázis

```bash
cd services
cp .env.example .env          # töltsd ki (lásd lent)
docker compose up -d --build  # db + api + worker; az API induláskor lefuttatja a migrációkat
curl http://127.0.0.1:8100/health
```

A `.env` fontos értékei:

- `POSTGRES_PASSWORD` – erős jelszó.
- `SEO_OS_HMAC_SECRET` – közös titok a WordPress-szel (`openssl rand -hex 32`); ugyanez kerül a `wp-config.php`-ba.
- `SEO_OS_AGENT_TOKEN` – a Screaming Frog ügynök tokenje (`openssl rand -hex 24`).
- Az API-kulcsok (OpenAI, Anthropic, DataForSEO, Ahrefs) itt is megadhatók, de kényelmesebb a felületen: **Beállítások → Integrációk és AI**.

Mentés: a `pgdata` (adatbázis) és a `files` (feltöltések, generált dokumentumok) Docker-kötetet kell menteni, pl. naponta `docker compose exec db pg_dump -U seo seo_os`.

## 2. WordPress

1. **DNS és SSL:** a `seo` aldomain A rekordja mutasson a CRM szerverére, SSL tanúsítvánnyal (az aldomain már létezik).
2. **`wp-config.php`** – a CRM README-jében szereplő host-listát bővítsd a `seo` aldomainnel, és add meg a kapcsolatot:
   ```php
   $hpv_host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
   if ( in_array( $hpv_host, array( 'crm.helloprovision.com', 'clients.helloprovision.com', 'seo.helloprovision.com' ), true ) ) {
       define( 'WP_HOME', 'https://' . $hpv_host );
       define( 'WP_SITEURL', 'https://' . $hpv_host );
   }
   define( 'HPV_SEO_OS_SECRET', '…ugyanaz, mint a SEO_OS_HMAC_SECRET…' );
   define( 'HPV_SEO_OS_API_URL', 'http://127.0.0.1:8100' );
   // define( 'HPV_SEO_HOST', 'seo.helloprovision.com' ); // ez az alapértelmezés
   ```
3. **Bővítmény:** `plugins/helloprovision-seo-os` feltöltése és bekapcsolása (a CRM bővítménye mellett).
   *Beállítások → SEO OS* oldalon a „Kapcsolat tesztelése” gomb ellenőrzi az API-t.
4. **Szerepkörök:** *Beállítások → SEO OS → Csapat* (vagy a felhasználó profilján): Admin, SEO manager, Content manager,
   Designer, Fejlesztő. A WordPress adminisztrátorok automatikusan Adminok. Külsős kollégának (pl. grafikus) az
   „SEO OS munkatárs” WordPress-szerepkör elég – ő csak az SEO OS-t látja.
5. **Ütemezett feladatok:** a bővítmény 5 percenként kiküldi az e-mail értesítéseket, naponta egyszer lefuttatja az
   emlékeztetőket (upsell, lejárt határidő). Megbízható működéshez valódi cron kell:
   `define( 'DISABLE_WP_CRON', true );` a `wp-config.php`-ba, és a szerveren
   `*/5 * * * * curl -s https://seo.helloprovision.com/wp-cron.php > /dev/null`.
   A levelek a CRM levélsablonjával, a WP Mail SMTP-n keresztül mennek.

## 3. Screaming Frog – hova és hogyan

A Screaming Frog asztali program (Java), licence felhasználóhoz/géphez kötött, és parancssorból is futtatható
(`--headless --crawl … --export-tabs … --output-folder …`). Ezért a SEO OS nem „távolról hívja” a programot, hanem egy kis
**ügynök** fut a Screaming Frog mellett, ami kimenő HTTPS-sel kér munkát:

```
SEO OS felület ──„Crawl indítása”──▶ API (sorba teszi)
                                          ▲
ügynök (30 mp-enként) ── heartbeat / claim ┘   ← csak kimenő kapcsolat, tűzfalat nem kell nyitni
   │  lefuttatja: ScreamingFrogSEOSpiderCli --headless --crawl https://ugyfel.hu/ --export-tabs "Internal:All,…"
   └─ ZIP feltöltése ─▶ API ─▶ Technikai audit fül (XL / M / S témák) ─▶ fejlesztői feladatok, audit PDF
```

Három lehetőség, ajánlott sorrendben:

**A) Irodai gép, ahol a licencelt Screaming Frog már fut (javasolt indulásnak).**
Python 3.9+ kell, más semmi.
1. Másold a gépre a `services/seo-os-crawler/agent.py`-t és mellé `agent.env` néven az `agent.env.example`-t.
2. Töltsd ki: `HPV_SEO_URL=https://seo.helloprovision.com`, `HPV_AGENT_TOKEN=` (a szerver `SEO_OS_AGENT_TOKEN` értéke).
3. Ellenőrzés: `python agent.py --check` (megtalálja-e a Screaming Frogot, eléri-e a szervert).
4. Indítás: `python agent.py` – Windowson a Feladatütemezőben „bejelentkezéskor” indítva, macOS-en launchd-vel.
   A gépnek bekapcsolva kell lennie, amikor crawl fut; ha nincs online ügynök, a crawl sorban vár.
   A Screaming Frog szokásos helye (`C:\Program Files (x86)\Screaming Frog SEO Spider\ScreamingFrogSEOSpiderCli.exe`,
   `/Applications/Screaming Frog SEO Spider.app/…`, `/usr/bin/screamingfrogseospider`) magától megtalálható; ha máshol
   van, `SF_CLI=` az `agent.env`-ben.

**B) A szerveren, Dockerben** (mindig elérhető, nem függ az irodai géptől):
`docker compose --profile crawler up -d --build` – a kép telepíti a Screaming Frogot, a licencet a `.env`
`SF_LICENCE_USER` / `SF_LICENCE_KEY` értéke adja. Két dolgot kell ellenőrizni: a licencfeltételek engedik-e a szerveren
futtatást (licencenként egy felhasználó), és a licencszerződés elfogadása (`SF_EULA`, verziófüggő érték – ha a crawl
EULA-hiba miatt áll le, egyszer grafikusan indítva fogadd el, és az ottani `spider.config` sort másold át).
Nagy oldalakhoz: `SF_MEMORY=8g` és a programban mentett, adatbázis-tárolású konfiguráció.

**C) Kézi feltöltés** (mindig működik, ügynök nélkül is): a Screaming Frogban *Export* a fülekről (vagy a Bulk Export
menüből), majd a **Technikai audit** fülön a feltöltőbe húzva (CSV, XLSX vagy ZIP). A rendszer a fájl- vagy munkalapnév
alapján felismeri, melyik ellenőrzésről van szó (pl. `response_codes_client_error_(4xx).xlsx`, `page_titles_duplicate.xlsx`,
`images over 100kb.xlsx`), és előnézetet mutat.

**Beállítások → Screaming Frog ügynök:** itt látszik, melyik ügynök online, itt szerkeszthető az export fülek listája
(„Fül:Szűrő”, a program felületén látható nevekkel), a bulk exportok, és egy opcionális `.seospiderconfig`
(pl. URL-limit, JavaScript renderelés, sitemap crawl). Az árva oldalak és a sitemap-szűrők csak akkor töltődnek, ha a
konfigurációban be van kapcsolva a sitemap crawl és a crawl-elemzés.

A feldolgozás a HelloProVision audit-sablon témáit követi: HTTP státuszkódok, Oldalcímek, Címsorok, URL formátum (XL);
Indexelhetőség, Canonical tagek, Képek, Biztonság, Sitemap (M); Oldalsebesség, Meta leírások (S). A méret projektenként
átírható. Újrafuttatáskor az eltűnt hibák „Javítva” állapotba kerülnek, az előző darabszám látszik.

## 4. Adatforrások és AI

- **OpenAI** – kulcsszó-elemzés, klaszterek (embedding), struktúra, wireframe. Kulcs nélkül szabályalapú motor dolgozik.
- **Anthropic (Claude)** – dokumentumszövegek a saját minták (Beállítások → Saját minták) hangnemén. Kulcs nélkül
  sablonszöveggel készül a dokumentum (a táblák így is teljesek).
- **DataForSEO** – volumen, nehézség, ötletek, rangsorolt kulcsszavak, SERP. Használat szerinti díjazás.
- **Ahrefs API v3** – csak az Ahrefs **Enterprise** csomagjával érhető el. Enélkül az Ahrefs exportok feltöltése
  (Keywords Explorer, Matching terms, Organic keywords, Content gap) ugyanúgy működik.
- **Havi keret:** *Beállítások → Havi API-keret projektenként*; a keret elérése után a lekérések leállnak. Minden hívás
  naplózva van a költséggel, az azonos kérések 30 napig gyorsítótárból jönnek.
- **Google Search Console:** a havi riportban a helye előkészítve; a bekötés a következő fejlesztési kör.

## 5. Ügyfél-jóváhagyás és portál

Belső jóváhagyás után a dokumentum *Küldés ügyfélnek* gombbal megy ki: az ügyfél a CRM ügyfélportál e-mailjében és
chatjében kap egy titkos linket (`https://seo.helloprovision.com/review/…`), ahol belépés nélkül letöltheti a
dokumentumot, kérdezhet, jóváhagyhatja vagy módosítást kérhet (45 napig érvényes). A döntésről a kérő és a projekt
felelőse értesítést kap. Ehhez a projekt ügyfelét össze kell kötni a CRM ügyféllel (Áttekintés → Ügyfél); ha nincs
összekötve, a megadott e-mail címre megy a link.

## 6. Tesztek és fejlesztés

```bash
cd services/seo-os-api
pip install -e .[dev]
createdb seo_os_test   # a tesztek saját adatbázist használnak
pytest                 # 42 teszt
php ../../tests/seo-os.php   # a bővítmény aláírási és útválasztási tesztjei
```

Helyi futtatás: `alembic upgrade head`, `uvicorn app.main:app --port 8100 --reload`, külön ablakban
`python -m app.jobs.worker`. A `SEO_OS_LLM_FAKE=1` kikapcsolja a fizetős AI-hívásokat.
