# HELLOPROVISION SEO OS – Architektúra és az 1. ütem terve

Állapot: **javaslat, jóváhagyásra vár**. Alkalmazáskód még nem készült.

A dokumentum a teljes `seotool.zip` csomagra épül: 26 fájl a gyökérben, 7 beágyazott archívum, összesen kb. 60 egyedi dokumentum. Öt részből áll:

1. A HelloProVision SEO-módszertana, ahogy ma működik, a dokumentumokból kiolvasva.
2. A rendszer architektúrája.
3. Az adatbázisséma.
4. A felhasználói felület felépítése.
5. A fejlesztési ütemterv és az 1. ütem megvalósítási terve.

---

## 0. A feldolgozott anyagok

| Csoport | Dokumentumok | Mit határoznak meg? |
|---|---|---|
| **Folyamat** | `SEO lépések - projektmenedzser_sales.docx`, `SEO árak.xlsx` (ütemezés, árak, szolgáltatások tartalma) | A szolgáltatáskatalógus, a bekérendő anyagok, a jóváhagyási pontok, ki mit csinál, az upsell szabály |
| **Kutatási bemenetek** | `Kiindulás.xlsx` (core kulcsszavak, lokációk, versenytársak, kulcsszógyűjtés), 4 db Google Keyword Planner export (Naples, Fort Myers, Cape Coral, Florida), Ahrefs *matching terms* és *content gap* exportok | Milyen nyers adat érkezik, és milyen formában |
| **Kulcsszókutatás (leadandó)** | `Kulcsszókutatás - helloprovision.com.xlsx` | Kulcsszó × lokáció sorok, KD, parent topic, versenytársanként pozíció / URL / forgalom |
| **Struktúra (leadandó)** | `Wireframe - főoldal.docx`, `Wireframe - aloldalak.docx`, `Főoldal wireframe - szövegírónak.docx`, `Aloldalak wireframe - szövegírónak.docx`, `Belső linkelési struktúra - főoldal.docx`, `helloprovision.com struktúra rajz.png`, változásnaplók | Oldalstruktúra, URL / title / H1 mátrix, oldalmodellek, belső linkelési terv |
| **Tartalomstratégia (leadandó)** | `tartalomstratégia 2026-2027 helloprovision.com_.xlsx` (6 munkalap) | Összefoglaló, kulcsszóklaszterek, 6 havi roadmap, mérési terv, PPC/Meta terv, félretett témák |
| **Tartalmi wireframe-ek** | `01–04 HelloProVision wireframe - *.docx` | A cikkenkénti és case study wireframe formátuma |
| **Belső briefek** | `gulyastamas.hu SEO fejlesztői módosítások.docx` (fejlesztő), `gulyastamas.hu SEO szövegírás.docx` (szövegírói kulcsszó-mapping), `artmirror.hu … belső kivitelezési feladatlista.docx` (SEO / szövegíró / fejlesztő szerint színkódolt feladatlista), `SEO alapok - grafikus.docx` (grafikus), `SEO alapok - szövegíró.docx`, `Technikai SEO alapok - fejlesztői.docx`, `FEJLESZTŐI BETANÍTÁSI KÉZIKÖNYV - SABLON.docx` | Szerepkörönkénti briefek és állandó szabályok |
| **Ügyfélsablonok** | `Stílusbeli irányelvek szövegíráshoz - sablon.docx`, `Technikai SEO audit - összefoglaló sablon.docx` + egy kitöltött audit, a TimeHeist ajánlat PDF | Ügyfélnek szóló formátumok és arculat |
| **Technikai audit adatok** | 9 db Screaming Frog export (hiányzó canonical, noindex, duplikált H1 / title / meta, túl hosszú title / meta, 4xx, 100 KB feletti képek) | Az audit bemenetének formája |
| **Kész tartalmak** | 2 cikk + 2 Facebook-poszt | A szövegírói munka minőségi szintje |

---

## 1. A HelloProVision módszertana (kiolvasva)

### 1.1 Eladott szolgáltatások (ezek határozzák meg, mit tartalmaz egy projekt)

A `SEO árak.xlsx` és a folyamatleírás alapján egy projekt **ezeknek a leadandóknak a csomagja**, nem egyetlen rögzített folyamat:

| Szolgáltatás | Típus | Fő kimenet |
|---|---|---|
| Technikai SEO audit (+ opcionális kivitelezés) | egyszeri | Audit összefoglaló (XL / M / S prioritás), fejlesztői hibajegyek |
| Technikai SEO alapok (új weboldal) | egyszeri | Fejlesztői követelmények |
| Kulcsszókutatás | egyszeri | Kulcsszókutatás táblázat. Az ügyfél megjegyzést írhat hozzá, de nem szerkesztheti. |
| Oldalstruktúra javaslat | egyszeri | Struktúra táblázat → grafikus → Figma, ezt kapja az ügyfél |
| Tartalomstratégia (általában 6 hónap) | egyszeri | 6 munkalapos stratégiai táblázat |
| Tartalommenedzsment (havi 2–8 tartalom) | havidíjas | Wireframe → szöveg → ellenőrzés → ügyfél-jóváhagyás → feltöltés |
| Csak wireframe-es tartalommenedzsment | havidíjas | Csak wireframe. A szöveget az ügyfél írja; a fejlesztő egyszer betanítja. |
| Linképítés / linkprofil audit | egyszeri / havi | Linképítési stratégia |
| Monitoring riport | havi | Havi riport, az első hónap után |

**Üzleti szabály:** az 5. hónap elején emlékeztetni kell a salest / projektmenedzsert az upsellre. Két lehetőség van: ajándékba készített stratégia a következő 6 hónapra (szerződéshosszabbítással), vagy új, kedvezményes kutatás és stratégia.

### 1.2 Folyamat, jóváhagyási pontok és felelősök (ahogy ma működik)

```
Sales / ajánlat ─► Szerződés ─► BEKÉRÉS (ügyfél-checklist)
                                 │
          ┌──────────────────────┼──────────────────────────┐
          ▼                      ▼                          ▼
  Technikai audit        Kulcsszókutatás           (új oldal: technikai SEO alapok)
  (SF crawl → audit)     (Ahrefs + GKP + versenytársak)
          │                      │  ◄── JÓVÁHAGYÁS: az ügyfél megjegyzéseket ír / jóváhagy (kötelező)
          │                      ▼
          │              Oldalstruktúra javaslat (Excel → grafikus → Figma)
          │                      │  ◄── JÓVÁHAGYÁS: ügyfél átnézi / meeting
          │                      ▼
          │              Tartalomstratégia (6 havi roadmap)
          │                      │  ◄── JÓVÁHAGYÁS: ügyfél jóváhagyja (+ saját képeket küld)
          │                      ▼
          │              Az ügyfél kitölti a Stílusbeli irányelveket
          │                      ▼
          │              Havonta: wireframe (SEO) → szöveg (szövegíró) → ellenőrzés (PM/SEO)
          │                      │  ◄── JÓVÁHAGYÁS: az ügyfél a szakmai pontosságot, márkanyelvet,
          │                      │          üzleti infókat nézi; SEO-t érintő módosítást az SEO-s értékel.
          ▼                      ▼
  Fejlesztői kivitelezés   Feltöltés / publikálás → indexelés kérése → havi monitoring riport
```

A dokumentumokban szereplő emberek és a kért szerepkörök megfeleltetése:

| Személy a dokumentumokban | Szerepkör az SEO OS-ben |
|---|---|
| Áron | Admin |
| Olívia (kutatás, struktúra, stratégia, wireframe-ek) | SEO manager |
| Zsolti (PM, tartalmak ellenőrzése) | Content manager |
| Anni (szövegíró) | Content manager |
| Laci, Máté (fejlesztők) | Fejlesztő |
| Grafikus | Designer |

### 1.3 Bekérendő anyagok az ügyféltől (strukturált űrlap lesz belőle)

**Minden esetben:**
- A vizsgált domain.
- Korábbi SEO-kutatások, ha vannak.
- Google Search Console hozzáférés. Megadott csapattagoknak Tulajdonos vagy Teljes jogosultság kell. Ha nincs GSC, mi hozzuk létre, ehhez a domainszolgáltató belépési adatai kellenek. A belépési adatokat 1Passwordön keresztül kérjük.

**Technikai kivitelezéshez:** WordPress admin jogosultság, ha nem mi fejlesztettük az oldalt.

**Kulcsszókutatáshoz:**
- 15–25 kiinduló kulcsszó, típus szerint jelölve:
  - fő szolgáltatások és termékek;
  - az ügyfelek által használt elnevezések;
  - települések és szolgáltatási terület;
  - üzletileg fontos, magas árrésű szolgáltatások;
  - kizárt szolgáltatások vagy kulcsszavak;
  - webshopnál az aktuális terméklista.
- 4–5 versenytárs domain organikus pozíció alapján.
- Konverziós célok.

**Tartalommenedzsmenthez:** az ügyfél által jóváhagyott stratégia és a kitöltött Stílusbeli irányelvek. A dokumentum címében szerepeljen az ügyfél domainje, a kiemelt márkanevet pedig kiküldés előtt át kell írni.

### 1.4 A dokumentumokban talált döntési szabályok (a rendszer ezeket betartatja vagy beépíti)

**Kulcsszó-priorizálás**

1. **Az elsődleges kulcsszó nem mindig a legnagyobb volumenű kifejezés.** Szolgáltatási oldalnál a keresési szándék az első. Példa a dokumentumokból: az *önismereti tanácsadás* (30/hó, szolgáltatáskereső szándék) megelőzi az *önismeret* kifejezést (880/hó, információs). Ez egyezik a kért sorrenddel: szándék → üzleti érték → kereskedelmi lehetőség → verseny → volumen.
2. **Használt szándékkategóriák:** információs, kereskedelmi, információs / kereskedelmi vizsgálódó, problématudatos, navigációs, tranzakciós, vegyes.
3. **A lokáció külön dimenzió, nem szándék.** A kutatási táblák kulcsszó × lokáció soronként tárolják az adatot, és a KD városonként eltér.
4. **Prioritási skála:**
   - **P1:** erős üzleti szándék, plusz közvetlen belső link a legerősebb szolgáltatási vagy városi oldalakra.
   - **P2:** támogató vagy tágabb téma, amely proofot és topical authorityt épít, gyakran magasabb KD-vel.
   - **Félretett:** a következő időszakra kerül, indoklással és javasolt kezeléssel, például „GSC-adat alapján” vagy „a városi landing kezeli”.
5. **Az alternatív piaci nyelv nem szolgáltatási primary.** Példa: a *párterápia* nem az ügyfél szolgáltatásának neve, ezért csak összehasonlító cikkben szerepel.

**Kannibalizáció és oldal-hozzárendelés**

6. **Egy elsődleges kereskedelmi kulcsszó pontosan egy URL-hez tartozik.** A közeli változatokat ugyanaz az oldal kezeli, nem készül külön oldal minden szórendre.
7. **Egy oldal = egy szándék.** A főoldal nem céloz olyan kulcsszót, amely egy szolgáltatási oldalé.
8. **A város + szolgáltatás kereskedelmi kulcsszavak saját landingre kerülnek.** Cikkekben a lokáció csak támogató kontextus lehet.
9. **A cikk információs vagy döntési kérdést válaszol meg, majd a szolgáltatási oldalra vezet.** Nem másolhatja a landinget. Minden roadmap-sorhoz tartozik *kannibalizációs szabály*.
10. **A városi hub nem szolgáltatási oldal.** A hub a város szolgáltatási oldalaira irányít.
11. **Minden város ugyanazokat a szolgáltatásokat kapja, egyedi helyi szöveggel.** A problémák, iparágak, FAQ és proof városonként eltér. Az oldalak soha nem városnév-cserés másolatok.
12. **Ne készüljön olyan oldal, amit a kutatás nem indokol.** A helloprovision.com-on például nincs PPC, e-commerce, branding, „near me” vagy külön web development landing.

**Tartalom és linkelés**

13. **Publikálási minimum:**
    - egyedi title és H1;
    - helyi szöveg;
    - proof;
    - legalább 5 valódi FAQ;
    - belső linkek;
    - CTA.
14. **Proof-szabályok:**
    - csak ellenőrzött proof használható;
    - nem találunk ki ügyfelet vagy eredményt, és nem ígérünk helyezést;
    - a case study-ból kimarad minden modul, ami nem volt része a projektnek.
15. **A linkszöveg a látogatónak legyen érthető.**
    - Ne linkeljük automatikusan az exact-match kulcsszavakat, és ne legyen sitewide kulcsszavas link.
    - Linktípusok: MENÜ, GOMB, KÁRTYA, SZÖVEGLINK, NINCS LINK.
16. **A mérési elvárások szakaszosak:**
    - 0–30 nap: indexelés;
    - 31–60 nap: query discovery;
    - 61–90 nap: kattintások és CTR;
    - 4. hónap: assisted path;
    - 5. hónap: leadminőség;
    - 6. hónap: döntés a következő 6 hónapról.
17. **Az SEO és a fizetett kampány egy tartalmi rendszer.** A cikkekből Meta/Facebook kreatív készül. A magas szándékú Google Search forgalom a szolgáltatási vagy városi landingre megy, soha nem a blogra.

**Technikai audit és feladatok**

18. **A technikai audit prioritása pólóméret szerinti (XL / M / S).** A fejlesztői feladatnak van forrása, művelete, célja és *„késznek akkor tekinthető”* ellenőrzése.
19. **A belső feladatlisták szerepkör szerint színkódoltak:** SEO = zöld, szövegíró = kék, fejlesztő = narancs.

### 1.5 Leképezendő dokumentumszerkezetek (mezőszinten)

**Kulcsszókutatás tábla** – soronként egy kulcsszó × lokáció:

`Keyword | Local long-tail | Parent topic | Volume | KD | Location | {versenytárs}: Position | {versenytárs}: URL | {versenytárs}: Traffic … | (Magyar fordítás) | Traffic potential | Current position | Current URL | Category`

**Tartalomstratégia munkafüzet** – 6 munkalap:

1. **Összefoglaló:** a stratégia lényege, pillarok cikkszámmal, havi ritmus, prioritási logika, fő SEO-elvek.
2. **Kulcsszóklaszterek:** `Elsődleges kulcsszó | Volume | KD | Keresési szándék | Pillar | Kapcsolódó kulcsszavak | Klaszter havi keresése | Céloldal | Javasolt tartalomtípus | Prioritás | Kannibalizációs szabály`
3. **6 havi roadmap:** `Hónap | Prioritás | Elsődleges kulcsszó | Pillar | Kapcsolódó kulcsszavak | Havi keresés | Lokáció | Javasolt URL | Oldaltípus | H1/cím | Tartalmi irány / fő blokkok | Fő CTA | Belső linkelés | Boost / social hook | Státusz`
4. **Mérési terv:** `Időszak | Fő fókusz | Mit nézzünk? | Hol mérjük? | Sikerjel | Döntés | Tartalmi bontás | Tulajdonos | Státusz | Megjegyzés`
5. **PPC / Meta:** `Hónap | Tartalom / hook | SEO-szerep | Lokáció | Meta kreatív | Paid szerep | Google Search cél | Remarketing következő lépés | KPI`
6. **Félretett témák:** `Kulcsszó | Volume | KD | Pillar | Miért nincs most a roadmapben? | Javasolt kezelés`

Minden munkalap végén van egy oszlopmagyarázat az ügyfélnek.

**Oldalstruktúra / oldal-wireframe** (szolgáltatási és városi oldalak):
- URL / SEO title / H1 mátrix.
- Oldalmodellek: regionális főoldal, városi hub, szolgáltatás × város.
- Modellenként: oldal célja, majd `# | H2 / blokk | Feladat`, utána PROOF / LINKS / HELYI ELTÉRÉS.
- Blokkonként: benchmark versenytárs URL-ek.
- A főoldal tartalmi arányai és a publikálási minimum.

**Cikk / case study wireframe:**
- **Fejléc:** URL, H1, elsődleges kulcsszó, volume/KD, kapcsolódó kulcsszavak, pillar, terjedelem, fő CTA, case studynál schema és oldaltípus is.
- **„A lényeg” doboz:** a brief 2–4 mondatban, a nyelvi utasítással együtt (pl. „angolul kutass, amerikai angol”).
- **Folyamatsor:** blokk → blokk → …
- **Blokktábla:** `# | H2 | A blokk célja | Mit írjon bele? | SEO / kulcsszóhasználat | Belső link / CTA | Terjedelem`
- **Belső linkelés tábla:** `Hol legyen? | Anchor | Cél | Kivitelezési megjegyzés`
- **Bizonyíték / input doboz:** ki szerzi be a proofot.
- **Checklisták:** „Kötelező a leadás előtt” és „Tilos / kerülendő”.
- **Vizuális sorrend és kötelező inputok** (case study).

**Fejlesztői brief:**
- A döntési alap.
- Módosítási táblák: `Módosítás | Pontos teendő | SEO-indok`.
- URL-struktúra: `Oldal | URL | SEO-szerep`.
- Navigáció.
- Meglévő anchorok → cél URL-ek.
- Átirányítási táblák: `Forrás URL | Művelet (301 / noindex / canonical) | Cél | Ellenőrzés / késznek akkor tekinthető`.

**Szövegírói brief (kulcsszó-mapping):**
- Általános szövegírói szabályok.
- Mapping tábla: `Oldal | URL | Primary | Secondary | Intent | H1`.
- Oldalanként „mit használjunk / mit ne”.
- Kifejezetten *nem* célzott kulcsszavak.
- Kannibalizációs szabályok.
- Szolgáltatási aloldal-sablon.

**Grafikusi brief:**
- A grafikus felelősségének határa.
- Kötelező inputok listája.
- Fájlelnevezési szabályok.
- Formátum tartalomtípusonként.
- Exportméretek, reszponzív vágás, fejlesztői átadás.

**Ügyfélajánlat / PDF-stílus** (TimeHeist): sötét háttér, keretezett kártyák, oldalanként színes címke, oldalszámos lábléc, rövid mondatok.

### 1.6 Két dolog, amivel a briefet érdemes kiegészíteni (javaslat)

- **A táblázatok valódi leadandók, nem csak köztes anyagok.** A kulcsszókutatást olyan táblaként kapja az ügyfél, amelyhez megjegyzést írhat, de nem szerkesztheti. A stratégia 6 munkalapos munkafüzet. Az exportok között ezért a PDF mellett a saját elrendezésű XLSX is kell (később Google Sheets is).
- **Két nyelven folyik a munka.** A belső csapat magyarul írja a briefeket; az ügyfélnek szóló anyag magyar (ideastyle.hu ügyfelek) vagy amerikai angol (helloprovision.com ügyfelek). Minden projekt két beállítást kap:
  - **tartalmi nyelv:** kulcsszavak, H1-ek, címek és ügyféldokumentumok nyelve;
  - **munkanyelv:** a belső briefek nyelve, alapból magyar.

---

## 2. A rendszer architektúrája

### 2.1 Áttekintés

```
 Böngésző (csapat: Admin / SEO manager / Content manager / Designer / Fejlesztő)
   │   azonos domain, WordPress login süti + REST nonce
   ▼
 WordPress  ── bővítmény: helloprovision-seo-os ─────────────────────────
   • Belépés, felhasználók, szerepkörök (a WordPress az identitásforrás)
   • Kiszolgálja az alkalmazást a /seo-os/ címen (vagy seo.helloprovision.com, mint a CRM)
   • REST proxy  /wp-json/hpv-seo/v1/*  → FastAPI, aláírt felhasználói adatokkal
   • Kapcsolódik a meglévő CRM ügyfelekhez és az ügyfélportálhoz (újrahasznosítás, nem újraépítés)
   │   szerver–szerver HTTPS, HMAC-SHA256 aláírt fejlécek
   ▼
 FastAPI szolgáltatás  (Python 3.12, csak belső hálózaton)
   • Szakmai API: projektek, kutatás, kulcsszavak, klaszterek, oldalak, roadmap,
     wireframe-ek, dokumentumok, jóváhagyások
   • Háttérfeladat-futtató (Postgres alapú sor) importhoz, AI-hoz, dokumentumgeneráláshoz
   • Integrációk: OpenAI (elemzés), Anthropic Claude (dokumentumok),
     DataForSEO, Ahrefs, később Google Search Console
   • Renderelők: HTML→PDF (WeasyPrint), DOCX (python-docx), XLSX (openpyxl)
   │
   ▼
 PostgreSQL 16   (az adatok egyetlen forrása)   +   fájltár (helyi kötet / S3)
```

### 2.2 Fő döntések és indoklásuk

| Döntés | Választás | Indok |
|---|---|---|
| Hol élnek az adatok? | **Csak PostgreSQL-ben, a FastAPI-n keresztül.** A WordPress csak a felhasználókat, szerepköröket és a bővítmény beállításait tárolja. | Egy igazságforrás. A WordPress vékony marad, az adatok túlélhetik a frontendet. |
| Böngésző → backend | **WordPress REST proxyn keresztül.** A FastAPI nem érhető el nyilvánosan. | Azonos domain, nincs CORS, a WordPress belépés újrahasznosítható. A FastAPI csak a WordPress aláírt hívásait fogadja el. |
| Szerverek közti hitelesítés | **HMAC-SHA256 a `metódus + útvonal + időbélyeg + törzs-hash + felhasználó-azonosító` adatokon**, közös titokkal és 5 perces időablakkal | Egyszerű, és véd a visszajátszás ellen. A FastAPI az aláírt fejlécben lévő szerepkör alapján újra ellenőrzi a jogosultságot, és saját `users` tükörtáblát is vezet. |
| Hosszú műveletek | **Háttérfeladatok** egy `jobs` táblában (`SELECT … FOR UPDATE SKIP LOCKED`), a felület lekérdezi az állapotukat | Egy PHP proxy nem várhat perceket. Ekkora terhelésnél nem kell Redis vagy Celery. |
| Frontend technológia | **Preact + htm, build lépés nélkül,** ugyanaz, mint a meglévő CRM alkalmazásé (`plugins/helloprovision-portal/assets/app`) | Illeszkedik a kódbázishoz, nincs karbantartandó eszközlánc, gyors. A meglévő `preact-htm.js` fájlt újrahasznosítjuk. |
| AI munkamegosztás | **OpenAI:** osztályozás, klaszterezés, hozzárendelés, tervezés, szigorú JSON-séma kimenettel. **Claude:** minden szöveges és dokumentumtartalom. | Ahogy a brief kéri. Mindkettő egy közös `llm` adapter mögött van, prompt-verziózással, cache-sel és költségnaplóval. |
| AI kontra ember | **Az AI soha nem ír közvetlenül végleges értéket.** Minden AI-eredmény *javaslatként* tárolódik, indoklással, és egy ember elfogadja vagy felülírja. A jóváhagyott anyagok verziózottak és zároltak. | Az ismétlődő munkát automatizálja, a stratégiai döntés emberi marad. |
| Prioritás | **Determinisztikus, megmagyarázható pontszám** a kért sorrendben, Pythonban számolva az AI által osztályozott bemenetekből | Megismételhető, ellenőrizhető, a csapat hangolhatja. A modell csak osztályoz, a rangsort a kód adja. |
| Kannibalizáció | **Az adatbázis kényszeríti ki:** egy kulcsszó csak egy oldalon lehet *primary* (egyedi részleges index) | Az 1.4 / 6. szabály kemény garancia lesz, nem csak checklist-pont. |
| Újrahasznosítás | A projektek a **CRM ügyféltáblához** kapcsolódnak, a gyártási feladatok **CRM feladatokként** mennek tovább, az ügyfél-jóváhagyás a **meglévő ügyfélportálon** fut | Nem építünk második ügyfélportált és feladatkezelőt. |

### 2.3 A FastAPI szolgáltatás felépítése

```
services/seo-os-api/
  app/
    main.py              alkalmazás, routerek, hibakezelés
    config.py            pydantic-settings (DB URL, HMAC titok, API kulcsok)
    db.py                SQLAlchemy 2.0 engine / session
    auth.py              HMAC ellenőrzés, CurrentUser függőség, jogosultságellenőrzés
    permissions.py       szerepkör → jogosultság mátrix (a WordPress tükre)
    models/              ORM modellek, szakterületenként egy modul
    schemas/             Pydantic kérés/válasz modellek
    routers/             projects, dashboard, users, research, keywords, clusters,
                         pages, roadmap, wireframes, documents, approvals, jobs
    services/
      workflow.py        projekt állapotgép + jóváhagyási pontok
      scoring.py         prioritási pontszám (determinisztikus)
      importers/         ahrefs_*, gkp, screaming_frog, generic (automatikus oszlop-hozzárendelés)
      llm/               adapter, prompts/ (verziózott), openai_client, claude_client
      integrations/      dataforseo, ahrefs, gsc
      renderers/         pdf (Jinja2 + WeasyPrint), docx, xlsx
    jobs/                worker ciklus, feladatkezelők
  alembic/               migrációk
  tests/                 pytest (valódi Postgres ellen)
  Dockerfile, docker-compose.yml, .env.example, pyproject.toml
```

### 2.4 Az AI-folyamat (3. ütemtől)

1. **Normalizálás.** Determinisztikus kód: duplikátumok összevonása, kisbetűsítés, a lokációs szavak külön `location` mezőbe, a „near me”, „best”, „vs”, „cost” és hasonló módosítók jelölése.
2. **Szándék-osztályozás** OpenAI-jal, kb. 100 kulcsszavas csomagokban, JSON-sémával. A prompt tartalmazza a projekt üzleti profilját, a szolgáltatáslistát és a kizárt témákat. Az eredmény `(kifejezés, piac, nyelv, prompt_verzió)` szerint cache-elődik, így az újrafuttatás ingyenes.
3. **Üzleti érték** (1–5) az ügyfél szolgáltatásai, árrései és kizárásai alapján, egysoros indoklással.
4. **Klaszterezés.** Embeddingekből agglomeratív klaszterezés; a klasztert és a pillart az LLM nevezi el. Minden klaszter a kért öt csoportra bomlik: kereskedelmi, információs, probléma, összehasonlító, lokális.
5. **Pontozás (kód):**
   ```
   pontszám = 0,35·szándék_illeszkedés + 0,25·üzleti_érték + 0,20·kereskedelmi_lehetőség
            + 0,12·könnyűség(KD) + 0,08·log_volumen
   ```
   A súlyok ügynökségi szinten állíthatók. Kemény felülírások:
   - kizárt → Félretett;
   - alternatív piaci nyelv → csak cikk.

   A P1 / P2 / Félretett határértékek állíthatók.
6. **Hozzárendelés.** Az LLM oldaltípust és URL-t javasol, a kód pedig betartatja a szabályokat:
   - URL-enként egy primary;
   - város + kereskedelmi → városi landing;
   - információs → cikk, ami a szolgáltatási oldalra linkel.

   A rendszer minden hozzárendeléshez kannibalizációs szabályt ír.
7. **Tervezés.** A rendszer elkészíti az oldalstruktúrát, majd a 6 havi roadmapet (a projekt havi ritmusával), végül a wireframe-eket.
8. **Dokumentumírás** Claude-dal. A *jóváhagyott* strukturált adatot kapja, plusz 1–2 referenciadokumentumot a feltöltött mintákból stílus-horgonyként. Szakaszonkénti JSON-t ad vissza, amiből a renderelők márkázott PDF-et, DOCX-et vagy XLSX-et készítenek.

Minden hívás bekerül az `api_logs` táblába: tokenek, költség, időtartam, prompt-verzió.

---

## 3. Adatbázisséma (PostgreSQL, `seo` séma)

Konvenciók:
- `id bigserial` elsődleges kulcsok és `created_at` / `updated_at timestamptz`.
- Archiválás `archived_at` mezővel (nem törlés).
- A felsorolások `text` típusúak `CHECK` megkötéssel, mert ezeket könnyebb migrálni, mint a natív enumokat.
- Az **Ütem** oszlop mutatja, melyik ütemben jelenik meg a tábla.

### 3.1 Felhasználók, projektek, munkafolyamat

| Tábla | Fő oszlopok | Ütem |
|---|---|---|
| `users` | `wp_user_id` (egyedi), `email`, `display_name`, `role` (admin / seo_manager / content_manager / designer / developer), `is_active`, `last_seen_at` | 1 |
| `clients` | `name`, `crm_client_id` (lehet üres → CRM `hpv_clients.id`), `primary_domain`, `notes` | 1 |
| `projects` | `client_id`, `name`, `domain`, `industry`, `market` (országkód), `locations text[]`, `content_language`, `working_language`, `business_services jsonb` (név, árrés: magas/normál, kiemelt-e), `target_audience`, `business_goals`, `conversion_goals`, `excluded_topics text[]`, `scope text[]` (tech_audit, tech_foundations, keyword_research, structure, content_strategy, content_mgmt, wireframes_only, link_building, monitoring), `status`, `owner_id`, `start_date`, `strategy_months` (alapból 6), `content_per_month`, `upsell_reminder_at`, `archived_at` | 1 |
| `project_members` | `project_id`, `user_id`, `project_role` | 1 |
| `project_competitors` | `project_id`, `domain`, `source` (client / seo / ahrefs), `is_active`, `notes` | 1 |
| `seed_keywords` | `project_id`, `keyword`, `kind` (service / customer_term / location / high_margin / excluded / product) | 1 |
| `intake_items` | `project_id`, `key` (gsc_access, admin_access, seed_keywords, competitors, conversion_goals, previous_research, style_guide, product_list), `status` (missing / requested / received / n_a), `note`, `updated_by` | 1 |
| `status_history` | `project_id`, `from_status`, `to_status`, `user_id`, `note`, `at` | 1 |
| `activity_log` | `project_id`, `user_id`, `entity`, `entity_id`, `action`, `diff jsonb`, `at` | 1 |
| `business_profiles` | `project_id`, `version`, `positioning`, `services jsonb`, `audiences jsonb`, `differentiators`, `proof_assets jsonb`, `source` (ai / manual), `approved_by`, `approved_at` | 3 |
| `style_guides` | `project_id`, `answers jsonb` (a Stílusbeli irányelvek sablon 11 kérdése), `received_at` | 2 |

**Projektstátusz** (ahogy a brief kéri):

`draft (vázlat) → researching (kutatás) → ai_analysis_complete (AI-elemzés kész) → seo_review (SEO-ellenőrzés) → client_review (ügyfél-ellenőrzés) → approved (jóváhagyva) → production (gyártás) → completed (kész)`

Az állapotgép engedi a visszalépést `client_review`-ból `seo_review`-ba, ha az ügyfél módosítást kér. Van egy `on_hold` (szünetel) jelző is, ami nem számít külön státusznak.

A valós projekteknek **több ügyfél-jóváhagyási pontjuk** van (kulcsszókutatás, struktúra, stratégia, tartalom). Ezek az `approvals` táblában élnek (3.5), és a projektstátusz akkor lép tovább, ha a projekt terjedelméhez tartozó jóváhagyások megvannak. A státusz olvasható összefoglaló marad, a részleteket a jóváhagyások tárolják.

### 3.2 Kutatás

| Tábla | Fő oszlopok | Ütem |
|---|---|---|
| `files` | `project_id`, `kind` (import / reference_doc / export), `filename`, `mime`, `size`, `storage_key`, `sha256`, `uploaded_by` | 2 |
| `imports` | `project_id`, `file_id`, `source` (ahrefs_keywords / ahrefs_organic / ahrefs_content_gap / ahrefs_matching_terms / gkp / screaming_frog / manual), `sheet`, `location`, `column_map jsonb`, `row_count`, `status`, `error` | 2 |
| `keywords` | `project_id`, `term`, `term_normalized` (projekten belül egyedi), `translation`, `parent_topic`, `category`, `is_excluded`, `exclusion_reason`, `first_import_id` | 2 |
| `keyword_metrics` | `keyword_id`, `location` (üres = országos), `volume`, `kd`, `cpc`, `traffic_potential`, `source`, `fetched_at`; egyedi `(keyword_id, location, source)` | 2 |
| `competitor_rankings` | `keyword_id`, `competitor_id`, `location`, `position`, `url`, `traffic`, `source`, `fetched_at` | 2 |
| `own_rankings` | `keyword_id`, `location`, `position`, `url`, `source` (ahrefs / gsc), `fetched_at` | 2 |
| `serp_snapshots` | `keyword_id`, `location`, `serp jsonb`, `features text[]`, `fetched_at` | 5 |
| `audit_issues` *(opcionális modul)* | `project_id`, `import_id`, `issue_type`, `url`, `detail jsonb`, `size` (XL / M / S) | 5+ |

### 3.3 Kulcsszó-intelligencia

| Tábla | Fő oszlopok | Ütem |
|---|---|---|
| `keyword_analysis` | `keyword_id` (PK), `intent` (informational / commercial_investigation / commercial / transactional / navigational / problem / mixed), `modifiers text[]`, `is_local`, `business_value` 1–5, `commercial_opportunity` 1–5, `priority` (P1 / P2 / parked), `priority_score numeric`, `cluster_id`, `role` (primary / secondary / supporting), `reason`, `notes`, `status` (suggested / accepted / overridden), `reviewed_by`, `reviewed_at` | 3 |
| `ai_suggestions` | `project_id`, `entity`, `entity_id`, `field`, `value jsonb`, `reason`, `model`, `prompt_version`, `job_id`, `status` (pending / accepted / rejected) | 3 |
| `clusters` | `project_id`, `name`, `pillar`, `bucket` (commercial / informational / problem / comparison / local), `intent`, `total_volume`, `priority`, `target_page_id`, `cannibalization_rule`, `notes` | 3 |
| `parked_topics` | `project_id`, `keyword_id`, `reason`, `recommended_handling`, `revisit_after` | 3 |

### 3.4 Struktúra, stratégia, wireframe-ek

| Tábla | Fő oszlopok | Ütem |
|---|---|---|
| `pages` | `project_id`, `url`, `page_type` (home / city_hub / service / city_service / article / case_study / pillar_hub / category / product / support), `parent_page_id`, `location`, `h1`, `seo_title`, `meta_description`, `intent`, `seo_goal`, `cta_label`, `cta_url`, `priority` (P1 / P2 / hub / support), `lifecycle` (existing / new / redirect / merge / remove), `schema_types text[]`, `word_count_min`, `word_count_max`, `notes`, `version` | 3 |
| `page_keywords` | `page_id`, `keyword_id`, `role` (primary / secondary / supporting); **egyedi `(keyword_id)`, ahol `role='primary'`** | 3 |
| `internal_links` | `project_id`, `from_page_id`, `to_page_id` vagy `to_url`, `anchor`, `placement`, `link_type` (menu / button / card / text), `wireframe_section_id`, `note` | 3 |
| `roadmap_items` | `project_id`, `month date`, `priority`, `page_id`, `cluster_id`, `content_type` (article / case_study / service / pillar / category), `title`, `content_direction`, `location_context`, `cta`, `social_hook`, `client_input`, `status` (planned / wireframe / writing / qa / client / published), `assignee_id`, `due_date` | 3 |
| `measurement_plan` | `project_id`, `period`, `focus`, `what`, `where_measured`, `success_signal`, `decision`, `content_scope`, `owner`, `status`, `note` | 3 |
| `paid_plan_items` | `project_id`, `roadmap_item_id`, `seo_role`, `meta_creative`, `paid_role`, `search_target`, `remarketing_next`, `kpi` | 3 |
| `wireframes` | `page_id`, `version`, `essence`, `flow text[]`, `language_note`, `proof_requirements`, `must_have text[]`, `forbidden text[]`, `visual_sequence`, `status`, `approved_by`, `approved_at` | 3 |
| `wireframe_sections` | `wireframe_id`, `position`, `h2`, `goal`, `what_to_write`, `seo_usage`, `link_cta`, `length_min`, `length_max`, `benchmark_urls text[]`, `local_variation` | 3 |
| `wireframe_inputs` | `wireframe_id`, `item`, `description`, `provided` | 3 |
| `production_tasks` | `project_id`, `page_id`, `role` (seo / writer / developer / designer), `priority` (P1 / P2 / P3 vagy XL / M / S), `title`, `source_url`, `action`, `target_url`, `done_when`, `status`, `crm_task_id` | 4 |

### 3.5 Dokumentumok, jóváhagyások, naplók

| Tábla | Fő oszlopok | Ütem |
|---|---|---|
| `documents` | `project_id`, `doc_type` (seo_strategy / content_strategy / roadmap / wireframe_deck / keyword_research / structure / dev_brief / writer_brief / designer_brief / seo_checklist), `audience` (client / internal), `language`, `version`, `status` (draft / review / approved / sent), `title`, `content jsonb` (szakaszok), `source_hash` (annak az adatnak a hash-e, amiből készült; ebből látszik, ha elavult), `model`, `prompt_version`, `created_by` | 4 |
| `document_files` | `document_id`, `format` (pdf / docx / xlsx), `file_id` | 4 |
| `reference_docs` | `doc_type`, `language`, `file_id`, `extracted_text`, `is_active`. A feltöltött saját minták, Claude ezekből veszi a stílust. | 4 |
| `prompt_templates` | `key`, `version`, `provider`, `model`, `body`, `json_schema`, `is_active` | 3 |
| `approvals` | `project_id`, `subject_type` (keyword_research / structure / content_strategy / wireframe / document / content), `subject_id`, `subject_version`, `stage` (internal / client), `status` (pending / approved / changes_requested), `requested_by`, `decided_by`, `decided_at`, `comment` | 6 (egyszerű változat a 4.-ben) |
| `comments` | `project_id`, `subject_type`, `subject_id`, `author_id`, `is_client`, `body`, `resolved_at` | 6 |
| `notifications` | `user_id`, `project_id`, `kind`, `payload`, `read_at`, `emailed_at` | 6 |
| `jobs` | `project_id`, `type`, `payload jsonb`, `status` (queued / running / done / failed), `progress`, `attempts`, `result jsonb`, `error`, `created_by`, `started_at`, `finished_at` | 2 |
| `api_logs` | `project_id`, `job_id`, `provider` (openai / anthropic / ahrefs / dataforseo / gsc), `endpoint`, `model`, `request_hash`, `status_code`, `tokens_in`, `tokens_out`, `units`, `cost_usd`, `duration_ms`, `error`, `created_at` | 1 (csak a tábla), használat a 3.-tól |

---

## 4. A felület felépítése

### 4.1 Keret és design rendszer

- **Elrendezés:** bal oldali sáv (összecsukható, ikon + felirat), felső sáv (projektváltó, ⌘K keresés, háttérfeladat-jelző, felhasználói menü), tartalomterület. Mobilon az oldalsáv alulról felcsúszó panel lesz.
- **Színek** (sötét, khaki/olíva kiemelőszín; a végleges értékek fejlesztés közben finomodnak):

  | Token | Érték |
  |---|---|
  | `--bg` | `#0b0b0a` |
  | `--surface` | `#131311` |
  | `--card` | `#1b1b19` |
  | `--line` | `#2a2a26` |
  | `--ink` | `#ecebe4` |
  | `--muted` | `#9a998d` |
  | `--accent` | `#b3b07a` (khaki) |
  | `--accent-strong` | `#8f8f4e` (olíva) |
  | `--accent-ink` | `#16160f` |
  | `--ok` | `#8fbf6a` |
  | `--warn` | `#d9b35c` |
  | `--danger` | `#d9695c` |

- **Tipográfia:** Inter vagy Satoshi, 14 px alapméret, táblázatokban azonos szélességű számjegyek.
- **Komponensek:**
  - státuszcímke és 8 lépéses munkafolyamat-jelző;
  - adattábla oszlopszűrőkkel és mentett nézetekkel;
  - oldalpaneles szerkesztő;
  - javaslat-címke elfogadás / felülírás gombbal (AI-értékekhez);
  - jóváhagyási sáv;
  - üres állapotok, amelyek megmondják, mi nyitja meg az adott fület.
- **Szerepkör-színek** a meglévő belső konvenció szerint: SEO = zöld, szövegíró = kék, fejlesztő = narancs, designer = lila. A feladatcímkéken jelennek meg.

### 4.2 Képernyők

| Képernyő | Tartalom | Ütem |
|---|---|---|
| **Vezérlőpult** | Aktív projektek (ügyfél, státuszjelző, következő jóváhagyás, felelős). Rám váró jóváhagyások és ellenőrzések. Friss dokumentumok. E havi esedékes roadmap-tételek. Hiányzó bekérendő anyagok. Upsell-emlékeztetők (5. hónap). | 1 (a dokumentum- és roadmap-dobozok később telnek meg) |
| **Projektek** | Tábla és státusz szerinti tábla-nézet. Szűrők: státusz, felelős, terjedelem, piac. | 1 |
| **Új projekt** | 3 lépéses űrlap: 1) ügyfél és domain (CRM ügyfélválasztóval); 2) üzlet (iparág, piac, lokációk, szolgáltatások magas árrés jelöléssel, célközönség, célok, kizárások); 3) terjedelem (eladott szolgáltatások), versenytársak, kiinduló kulcsszavak típus szerint. | 1 |
| **Projekt → Áttekintés** | Üzleti profil, terjedelem, csapat, bekérési checklist (1.3), munkafolyamat-jelző státuszváltó gombokkal, státusztörténet, tevékenység. | 1 |
| **Projekt → Kutatás** | Fájlfeltöltés (Ahrefs / GKP / SF) oszlop-hozzárendelési előnézettel, importtörténet, versenytárslista, API-lekérés gombok (5. ütem). | 2 |
| **Projekt → Kulcsszavak** | Kulcsszó × lokáció tábla. Oszlopok: volume, KD, szándék, klaszter, prioritás, primary / secondary, javasolt URL, megjegyzés, versenytárs-pozíciók (kapcsolható). Tömeges műveletek, kizárás, „a nézet összes javaslatának elfogadása”, klaszter- és csoportnézet. | 2 → 3 |
| **Projekt → Struktúra** | Oldaltérkép-fa plus URL / title / H1 mátrix. Oldalpanel: típus, primary és secondary kulcsszavak, szándék, SEO-cél, H1, CTA. Belső linktérkép és kannibalizációs figyelmeztetések. | 3 |
| **Projekt → Tartalomstratégia** | 6 havi roadmap (havi oszlopok vagy tábla), klaszterek, mérési terv, paid / Meta terv, félretett témák. | 3 |
| **Projekt → Wireframe-ek** | Oldalankénti lista és szerkesztő, amely a saját wireframe-formátumot követi (1.5). | 3 |
| **Projekt → Dokumentumok** | Ügyfél- és belső dokumentumok generálás / újragenerálás gombbal, „elavult” jelzéssel, ha az adat változott, verziókkal és jóváhagyási állapottal. | 4 |
| **Projekt → Exportok** | PDF / DOCX / XLSX letöltés a saját elrendezésekben, Google Sheets link (később), feladatok küldése a CRM-be. | 4 |
| **Saját munkáim** | Szerepkör szerint szűrt feladat- és wireframe-lista az összes projektből. A Designer és a Fejlesztő ezzel a képernyővel indul. | 3–4 |
| **Beállítások** (admin) | Csapat és szerepkörök, API kulcsok (a szerveren tárolva, maszkolva megjelenítve), pontozási súlyok, prompt sablonok, referenciadokumentumok, arculat. | 1 (csapat), a többi később |

### 4.3 Láthatóság szerepkörönként

| Jogosultság | Admin | SEO manager | Content manager | Designer | Fejlesztő |
|---|:-:|:-:|:-:|:-:|:-:|
| Projektek és bekérés létrehozása / szerkesztése | ✓ | ✓ | – | – | – |
| Projektstátusz váltása | ✓ | ✓ | csak `production → completed` | – | – |
| Kutatás, kulcsszavak, klaszterek, struktúra (szerkesztés) | ✓ | ✓ | megtekintés | megtekintés (struktúra) | megtekintés (struktúra) |
| Stratégia / struktúra jóváhagyása (belső) | ✓ | ✓ | – | – | – |
| Tartalomstratégia, roadmap | ✓ | ✓ | ✓ státusz / felelős szerkesztése | – | – |
| Wireframe-ek | ✓ | ✓ | ✓ | megtekintés | megtekintés |
| Szövegírói briefek / tartalom ellenőrzése | ✓ | ✓ | ✓ | – | – |
| Grafikusi brief | ✓ | ✓ | ✓ | megtekintés | – |
| Fejlesztői brief, technikai feladatok | ✓ | ✓ | megtekintés | – | megtekintés + feladatstátusz frissítése |
| Dokumentumgenerálás | ✓ | ✓ | csak szövegírói briefek | – | – |
| Beállítások, API kulcsok, csapat | ✓ | – | – | – | – |

---

## 5. Fejlesztési ütemterv

Az ütemek sorrendje a briefet követi. A 3. ütem két részre (3a, 3b) bomlik, mert a tervezés külön nagy lépés.

| Ütem | Tartalom | Mit nyer vele a csapat? |
|---|---|---|
| **1. Alapok** | WordPress bővítmény (szerepkörök, alkalmazáskeret, REST proxy, beállítások). FastAPI + Postgres alap migrációkkal. Felhasználó-szinkron, ügyfelek (CRM-hez kötve), projektek bekéréssel, versenytársakkal, kiinduló kulcsszavakkal és terjedelemmel. Munkafolyamat-állapotgép előzményekkel. Vezérlőpult és projekt-áttekintés. Sötét design rendszer. | A projektek és a bekérés kikerül az e-mailből és az Excelből. Mindenki látja a státuszt és a következő lépést. |
| **2. Kutatási rendszer** | Fájltár és feltöltés. Import Ahrefs (keywords, organic, matching terms, content gap), Google Keyword Planner és Screaming Frog fájlokból, általános automatikus oszlop-hozzárendeléssel és előnézettel. Kulcsszó-adatbázis kulcsszó × lokáció mérőszámokkal, versenytárs-pozíciókkal, összevonással, kizárással és fordítással. Kulcsszótábla. **XLSX export a saját „Kulcsszókutatás” elrendezésben.** Stílusbeli irányelvek kérdőív. Jobs tábla és worker. | A kulcsszókutatás táblázat összerakása órák helyett percekig tart. |
| **3a. AI kulcsszó-intelligencia** | LLM adapter prompt-verziózással, cache-sel és költségnaplóval. Üzleti profil (oldal-crawl + bekérés alapján). Szándék-osztályozás, üzleti érték, klaszterezés 5 csoportba, determinisztikus prioritási pontszám, javaslat / elfogadás felület. | Az első körös osztályozást, klaszterezést és priorizálást az SEO manager átnézi, nem nulláról építi. |
| **3b. AI tervezés** | URL-hozzárendelés adatbázisszinten kikényszerített kannibalizációs szabályokkal, oldalstruktúra-generátor (oldalmodellek, URL / title / H1 mátrix, belső linkek), 6 havi roadmap-generátor (havi ritmussal, félretett témákkal, mérési és paid tervvel), wireframe-generátor a saját formátumban. | A struktúra, a stratégia és a wireframe-ek előre megírt vázlatként érkeznek, a saját szabályok szerint. |
| **4. Claude dokumentummotor** | Referenciadokumentum-könyvtár, Claude szakaszgenerálás, renderelők: PDF (márkázott HTML → WeasyPrint), DOCX (wireframe, fejlesztői, szövegírói és grafikusi brief a saját táblázatos stílusban), XLSX (6 munkalapos stratégiai munkafüzet). Elavult dokumentum jelzése, verziók, egyszerű belső jóváhagyás, gyártási feladatok, küldés CRM feladatokba. | Az ügyfél- és belső dokumentumok jóváhagyott adatból készülnek. |
| **5. API integrációk** | DataForSEO (lokációnkénti volumen a kézi GKP exportok helyett, SERP adatok, kapcsolódó kulcsszavak). Ahrefs API v3 (versenytársak organikus kulcsszavai, content gap, backlinkek, forgalom). Projektenkénti költségkeret és válasz-cache. Opcionálisan: Screaming Frog import → technikai audit összefoglaló. | Nincs több kézi export. A kutatás egy kattintással frissül. |
| **6. Haladó munkafolyamat** | Teljes jóváhagyási rendszer (belső és ügyfél). Ügyfél-jóváhagyás a **meglévő ügyfélportálon**: csak megjegyzésre jogosító kulcsszónézet, jóváhagyás / módosításkérés. Értesítések (e-mail és CRM chat). Upsell-emlékeztető. Havi riport alap. Később Google Search Console integráció a mérési tervhez. | Bezárul a kör az ajánlattól a monitoringig. |

---

## 6. Az 1. ütem megvalósítási terve

### 6.1 Az 1. ütem felépítése

```
plugins/helloprovision-seo-os/             WordPress bővítmény
  helloprovision-seo-os.php                betöltés, konstansok, aktiválás (szerepkörök / jogosultságok)
  includes/roles.php                       5 szerepkör + jogosultságtérkép, az admin mindent kap
  includes/settings.php                    API alap-URL, közös titok, útvonal / aldomain
  includes/signing.php                     HMAC kérés-aláírás (tiszta PHP, unit tesztelt)
  includes/proxy.php                       /wp-json/hpv-seo/v1/{path} → FastAPI (GET/POST/PATCH/DELETE)
  includes/app.php                         az alkalmazáskeret a /seo-os/ címen (noindex, csak belépve)
  includes/users.php                       felhasználó- és szerepkör-változások küldése a FastAPI-nak
  assets/app/app.js                        útválasztó, API kliens, elrendezés
  assets/app/ui.js                         közös komponensek (tábla, címke, lépésjelző, panel, űrlap)
  assets/app/views/dashboard.js
  assets/app/views/projects.js             lista + tábla-nézet
  assets/app/views/project-new.js          3 lépéses űrlap
  assets/app/views/project.js              fejléc + fülek; az Áttekintés működik, a többi fül „az N. ütemben nyílik”
  assets/app/views/settings.js             csapat és szerepkörök (csak olvasható lista a WP-ből)
  assets/app/app.css                       sötét design tokenek + komponensek
  assets/app/vendor/preact-htm.js          ugyanaz a fájl, mint a CRM-ben

services/seo-os-api/                       FastAPI szolgáltatás
  app/{main,config,db,auth,permissions}.py
  app/models/{user,client,project,workflow,log}.py
  app/schemas/{user,client,project,dashboard}.py
  app/routers/{health,users,clients,projects,workflow,dashboard}.py
  app/services/workflow.py                 állapotgép, szerepkörönként engedett váltások, előzmények
  alembic/versions/0001_foundation.py
  tests/{conftest,test_auth,test_projects,test_workflow,test_dashboard}.py
  Dockerfile, docker-compose.yml (api + postgres), .env.example, pyproject.toml

tests/seo-os.php                           PHP tesztek (WordPress nélkül): aláírás, jogosultságtérkép, proxy útvonal-fehérlista
docs/seo-os/ARCHITECTURE.md                ez a dokumentum
README.md                                  új szakasz: telepítés, beállítás, futtatás
```

### 6.2 Adatbázis-változások (`0001_foundation` migráció)

- **Táblák:** `users`, `clients`, `projects`, `project_members`, `project_competitors`, `seed_keywords`, `intake_items`, `status_history`, `activity_log`, `api_logs` (üres, a későbbi ütemekhez előkészítve).
- **Megkötések:**
  - `CHECK` a státuszokon és szerepkörökön;
  - egyedi `users.wp_user_id`;
  - egyedi `(project_id, lower(domain))` a versenytársaknál;
  - egyedi `(project_id, lower(keyword))` a kiinduló kulcsszavaknál.
- **Indexek:** `projects(status)`, `projects(owner_id)`, `status_history(project_id, at)`, `activity_log(project_id, at)`.
- **Alapadatok:** új projekt létrehozásakor automatikusan létrejön a 8 bekérési tétel (1.3). A terjedelemhez nem tartozó tételek `n_a` állapotban indulnak.

A WordPress csak a bővítmény beállítását (`hpv_seo_os_settings`) és a szerepköröket tárolja. **Nem hoz létre WordPress adatbázistáblát.**

### 6.3 API végpontok (1. ütem)

```
GET    /health
GET    /me                                   aktuális felhasználó + jogosultságok
GET    /users                                csapatlista (admin)
PUT    /users/{wp_user_id}                   frissítés a WordPressből (a bővítmény hívja)
GET    /clients?search=  POST /clients  PATCH /clients/{id}
GET    /projects?status=&owner=&q=
POST   /projects                             létrehozás (+ bekérési tételek, versenytársak, kiinduló kulcsszavak)
GET    /projects/{id}                        teljes áttekintő adat
PATCH  /projects/{id}
POST   /projects/{id}/archive
PUT    /projects/{id}/competitors            lista cseréje
PUT    /projects/{id}/seed-keywords          lista cseréje
PATCH  /projects/{id}/intake/{key}
POST   /projects/{id}/transition             {to, note} → workflow.py + szerepkör ellenőrzi
GET    /projects/{id}/history                státusztörténet + tevékenység
GET    /dashboard                            aktív projektek, rám váró tételek, hiányzó bekérések
```

### 6.4 Megvalósítási lépések

1. **Backend alap:** konfiguráció, adatbázis, HMAC hitelesítés, jogosultságmátrix, health végpont, docker-compose Postgres 16-tal, `0001` Alembic migráció.
2. **Modellek és sémák** a felhasználókhoz, ügyfelekhez, projektekhez és a hozzájuk tartozó listákhoz, validációval (domain normalizálása, lokációk tisztítása, a terjedelem értékei a szolgáltatáskatalógus alapján).
3. **Munkafolyamat szolgáltatás:**
   - Ismeri az engedett váltásokat: előre egy lépés, vissza `client_review`-ból `seo_review`-ba, admin felülírás kötelező megjegyzéssel.
   - Minden váltásnak szerepkör-ellenőrzése van, és bekerül a `status_history` és az `activity_log` táblába.
   - Az 1. ütemben még nincs adatfeltételhez kötött jóváhagyás. Ezek a későbbi ütemekkel, az adatokkal együtt jönnek.
4. **Routerek és pytest tesztcsomag** valódi Postgres ellen, ezekkel:
   - hitelesítés: érvényes, lejárt és manipulált aláírás;
   - CRUD műveletek;
   - bekérési tételek létrehozása;
   - minden engedett és tiltott váltás minden szerepkörrel;
   - a vezérlőpult összesítése.
5. **WordPress bővítmény:**
   - szerepkörök és jogosultságok aktiváláskor;
   - beállítási oldal kapcsolatteszt gombbal;
   - aláírás és proxy útvonal-fehérlistával, az aláírt felhasználó továbbításával, 30 mp-es időkorláttal és tiszta hibaüzenetekkel;
   - felhasználó-szinkron hookok;
   - az alkalmazáskeret a `/seo-os/` címen, belépés nélkül átirányít a bejelentkezésre.
6. **Alkalmazás (SPA):**
   - elrendezés és design tokenek;
   - Vezérlőpult, Projektek lista / tábla-nézet, Új projekt varázsló, Projekt-áttekintés (bekérési checklist, lépésjelző, státuszváltás, előzmények);
   - a többi fül helye;
   - Beállítások → Csapat.
7. **Tesztek és dokumentáció:**
   - `php tests/seo-os.php` (WordPress nélkül);
   - pytest;
   - kézi próba helyi WordPressen, a bővítmény a dockerizált API-val beszél;
   - magyar nyelvű README szakasz, a meglévő README-hez illeszkedve.

### 6.5 Az 1. ütem átvételi feltételei

- Egy Admin létrehoz egy projektet „Imperial Kitchens / Kitchen Remodeling / Naples, FL” adatokkal. Van versenytársa, típus szerinti kiinduló kulcsszavai és terjedelme, és megjelenik a vezérlőpulton a hiányzó bekérési tételekkel.
- Egy SEO manager végig tudja léptetni a projektet: `draft → researching → … → approved`. A Designer és a Fejlesztő csak olvasni látja a projektet, és nem válthat státuszt: az API 403-at ad, nem csak a gomb rejtett.
- Minden státuszváltás megjelenik az előzményekben felhasználóval, időponttal és megjegyzéssel.
- A FastAPI elutasít minden kérést érvényes WordPress-aláírás nélkül, és csak a WordPress szerverről érhető el.
- A felület sötét, 375 px szélességen is használható, és sehol nem látszik a WordPress admin keret.
- A PHP és a Python tesztcsomag hibátlanul lefut.

---

## 7. Döntések, amelyek az 1. ütem kódolása előtt kellenek

1. **Hol fusson a FastAPI + PostgreSQL?** A WordPress maradhat a mostani helyén, de az API-nak és az adatbázisnak szerver kell: kis VPS, vagy menedzselt konténeres hoszting plus menedzselt Postgres. Van már szerver, vagy az 1. ütem tartalmazza a VPS-re szánt Docker telepítőfájlokat is?
2. **A felület nyelve.** A belső CRM magyar, ez a brief angol. Javaslat: magyar csapatfelület; az ügyfélnek szóló anyagok nyelvét a projekt tartalmi nyelve határozza meg.
3. **Belépési cím.** `crm.helloprovision.com/seo-os/` (a CRM aldomainen belül, közös belépéssel) vagy külön `seo.helloprovision.com`? Javaslat: külön aldomain, ugyanazzal a domain-kezeléssel, amit a CRM már használ.
4. **Szerepkörök a meglévő CRM munkatárs mellett.** Az SEO OS szerepkörök a meglévő `hpv_staff` szerepkör *mellé* kerüljenek? Javaslat: igen. Egy felhasználónak lehet mindkettő, és az SEO OS soha nem ad WordPress admin hozzáférést.
5. **Terjedelem megerősítése:**
   - XLSX exportok a saját elrendezésekben a 2. és 4. ütemben, a PDF-ek mellett (javasolt, lásd 1.6);
   - az opcionális technikai audit modul (Screaming Frog import) az 5. ütemben.
