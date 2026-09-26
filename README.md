# HelloProVision – SEO javítások

A `helloprovision.com` WordPress oldal helyi SEO-javításai (Fort Myers, Cape Coral, Naples).

## `mu-plugins/helloprovision-seo.php`

Bővítmény, ami a Yoast SEO és a Yoast Local SEO által generált schema adatokat javítja. A Yoast beállításaihoz nem nyúl, csak a kimenetet igazítja.

| Hiba az élő oldalon | Javítás |
|---|---|
| Háromféle márkanév: `HelloProVision`, `Hello Provision`, `HelloProvision` | Mindenhol `HelloProVision`, a többi `alternateName` lesz |
| `legalName`: `Arovia Group LLC - Helloprovision.com` | `Arovia Group LLC` |
| `streetAddress`: `12557 NEW BRITTANY BLVD, SUITE 3 FORT MYERS, FL` | `12557 New Brittany Blvd, Suite 3`, régió: `FL` |
| Telefonszám: `2399551655` | `+1-239-955-1655` |
| `sameAs`: csak a Facebook | Minden hivatalos profil (a konfigurációban kell kitölteni) |
| Nincs `areaServed` | Fort Myers, Cape Coral, Naples (Wikipedia-azonosítóval) |
| Breadcrumb: `Kezdőlap` | `Home` (a schemában és a látható morzsában is) |
| `twitter:title`: `Home` | Az oldal valódi címe |
| A szolgáltatás- és városoldalakon nincs `Service` jelölés | `Service` jelölés, benne a szolgáltató és a kiszolgált város |

### Telepítés

1. Bővítmények → Új hozzáadása → Bővítmény feltöltése: `helloprovision-seo.zip`, majd Bekapcsolás.
   - **Frissítésnél:** ha a régebbi verzió már fel van töltve zipként, a WordPress rákérdez, hogy lecserélje-e. Válaszd a „Csere a feltöltöttre” lehetőséget.
   - **Ha korábban FTP-n a `wp-content/mu-plugins/` mappába másoltad:** előbb töröld onnan a `helloprovision-seo.php` fájlt. Ha mindkét helyen ott van, az oldal „kritikus hibával” leáll.
2. Ha van cache plugin vagy CDN, ürítsd a gyorsítótárat.
3. Ellenőrizd a [Rich Results Test](https://search.google.com/test/rich-results?url=https%3A%2F%2Fhelloprovision.com%2F) és a [Schema Validator](https://validator.schema.org/) segítségével, majd kérj újraindexelést a Search Console-ban (URL-ellenőrzés, majd „Indexelés kérése”).

### Beállítások: Beállítások → HelloProVision SEO

Minden beállítás az admin felületen szerkeszthető, kódot nem kell írni. A Bővítmények listában a bővítmény alatt is van egy „Beállítások” link.

- **Cégadatok:** márkanév, egyéb írásmódok, cégjogi név, rövid leírás, telefonszám.
- **Cím és nyitvatartás:**
  - utcacím;
  - **Cím elrejtése:** kapcsold be, ha a Google Business Profile service-area (rejtett címes) lesz;
  - napi nyitvatartás: a pipával bekapcsolva ez lép a Yoast Local SEO-ban beállított helyére.
- **Hivatalos profilok:** soronként egy link (LinkedIn, Instagram, Clutch, Yelp stb.). A Google-profil linkjét a Google értékelések bővítmény magától hozzáadja.
- **Kiszolgált városok:** város és Wikipedia link. Minden mentés után 3 üres sor jelenik meg az új városoknak.
- **Szolgáltatás- és városoldalak:** oldal, szolgáltatás neve, típus, városok. Az oldal mezőben gépelés közben felajánlja a meglévő oldalakat. Új város-oldal (pl. `/markets/cape-coral-seo/`) létrehozása után vedd fel ide.
- **Ellenőrzés:** mentés után minden oldalhoz van egy link a Google Rich Results Testhez.

Ha valamit hibásan adsz meg (pl. rossz link, ismeretlen város, a nyitásnál korábbi zárás), a mentés után figyelmeztetés jelenik meg, és a hibás elem kimarad.

## `plugins/helloprovision-reviews/` – Google értékelés-kérő rendszer

A Google-profil helyezésére az általatok befolyásolható tényezők közül az értékelések rendszeres érkezése hat a legjobban. Ez a bővítmény ezt automatizálja.

- **Rövid link:** a `helloprovision.com/review/` cím egyenesen a Google értékelő ablakára visz. Névjegyre, számlára, e-mail aláírásba is jó.
- **QR-kód:** az admin felületen PNG-ként letölthető, pl. irodai matricára vagy prezentáció utolsó diájára.
- **E-mailes kérés:** az admin felületen megadod az ügyfél nevét és e-mail címét, és az ügyfél egy személyre szabott, angol nyelvű levelet kap.
- **Egy emlékeztető:** ha az ügyfél 6 nap alatt nem nyitja meg a linket, egyszer automatikusan emlékeztetőt kap. Többször nem.
- **Követés:** látod, ki nyitotta meg a linket, és hányan kattintottak a rövid linkre vagy a QR-kódra. A levelezőrendszerek biztonsági szkennereit nem számolja.
- **Duplikáció-védelem:** ugyanarra a címre 90 napon belül nem megy ki két kérés.
- **Shortcode-ok:**
  - `[hpv_map]`: beágyazott Google-térkép a Kapcsolat oldalra;
  - `[hpv_google_profile]`: „Find us on Google” link;
  - `[hpv_review_link]`: értékelés gomb, pl. a köszönőoldalra.
- **Kapcsolat az SEO bővítménnyel:** a profil linkje automatikusan bekerül a schema `sameAs` listájába.

### Telepítés

1. Bővítmények → Új hozzáadása → Bővítmény feltöltése: `helloprovision-reviews.zip`, majd Bekapcsolás.
2. **Google értékelések → Beállítások**:
   - **Google értékelő link:** Google Business Profile → „Értékelések kérése” → link másolása.
   - **Profil linkje:** Google Maps → a profil → Megosztás.
   - **Térkép beágyazás:** Google Maps → Megosztás → Térkép beágyazása. A teljes `<iframe>` kód bemásolható.
3. **Levélküldés:** a WordPress alap levélküldése gyakran a spam mappában köt ki. Telepítsétek a **WP Mail SMTP** bővítményt, és kössétek be a céges levelezést (Google Workspace vagy Microsoft 365).
4. **Próba:** küldjetek egy kérést a saját címetekre, és nézzétek meg a levelet meg a linket.

### Szabályok, amiket a bővítmény betart, és nektek is be kell tartanotok

- **Mindenkitől kérjetek értékelést,** ne csak az elégedett ügyfelektől. A Google tiltja, hogy csak a pozitívnak ígérkező ügyfeleknek küldjétek ki a kérést (review gating). Ezért nincs a bővítményben „elégedett volt?” szűrő.
- **Az értékelésért ne adjatok semmit:** se kedvezményt, se ajándékot.
- **A szöveget ne diktáljátok.** Azt kérhetitek, hogy írják le, mit csináltatok együtt.

## `plugins/helloprovision-grader/` – SWFL Website & Local SEO Grader

Ingyenes weboldal-elemző eszköz érdeklődőgyűjtéshez. A látogató beírja a weboldala címét, és kb. 30 másodperc alatt pontszámot kap öt területen:

| Terület | Mit néz |
|---|---|
| **Speed** | Google PageSpeed Insights mobil mérés: pontszám, LCP, CLS, blokkolási idő, oldalméret, plusz a szerver válaszideje |
| **Mobile experience** | viewport, tap-to-call link, reszponzív képek, oldal ikon |
| **SEO basics** | title, meta description, H1 (a csonka, animált H1-et is kiszűri), indexelhetőség, HTTPS, canonical, alt szövegek, nyelv, megosztási előnézet, robots.txt, XML sitemap |
| **Schema markup** | JSON-LD, üzlettípus, név/cím/telefon, nyitvatartás, sameAs, kiszolgált terület (a Yoast-féle `@id` hivatkozásokat is feloldja) |
| **Local business info** | telefon és cím az oldalon, délnyugat-floridai város a címben/H1-ben, térkép, Google-értékelés link, egyezik-e a telefonszám a schemában és az oldalon, kapcsolat oldal |

**Hogyan gyűjti az érdeklődőket:**
- A pontszámok és a hibák listája azonnal, regisztráció nélkül látszik.
- A javítási javaslatokból csak a legfontosabb látszik mintaként, a többi el van mosva. Ezeket név és e-mail cím megadása után kapja meg a látogató az oldalon, és e-mailben is (HTML riport, „Book a Consultation” gombbal).
- Minden új érdeklődőről értesítő e-mail jön a súlyos hibák listájával, hogy a hívás előtt lássátok, mivel lehet megkeresni.
- A javaslatok feloldás előtt a szerverről sem jönnek le, tehát a böngésző forráskódjából sem lehet kiolvasni őket.

### Telepítés

1. Bővítmények → Új hozzáadása → Bővítmény feltöltése: `helloprovision-grader.zip`, majd Bekapcsolás.
2. **Google PageSpeed API kulcs** (ingyenes, kb. 5 perc; kulcs nélkül a Google gyakran elutasítja a mérést):
   1. [console.cloud.google.com](https://console.cloud.google.com/) → új projekt (pl. „HelloProVision Grader”).
   2. „APIs & Services” → „Library” → **PageSpeed Insights API** → Enable.
   3. „APIs & Services” → „Credentials” → „Create credentials” → „API key”.
   4. Biztonsághoz: a kulcsnál „API restrictions” → csak a PageSpeed Insights API.
   5. Másold be: **Website Grader → Beállítások → Google PageSpeed API kulcs**.
3. **Új oldal:** cím „Free Website & Local SEO Audit”, slug `website-grader`, a tartalma csak ennyi: `[hpv_grader]`.
   - Ha a téma az oldal címét H1-ként kiírja, használd ezt: `[hpv_grader heading="h2"]`, hogy ne legyen két H1.
   - A címsor és az alcím átírható: `[hpv_grader title="…" subtitle="…"]`.
4. **Levélküldés:** a **WP Mail SMTP** bővítmény legyen beállítva (ugyanaz, mint az értékelés-kérőnél).
5. **Adatvédelmi tájékoztató:** egészítsétek ki egy bekezdéssel. Az eszköz a megadott nevet, e-mail címet, cégnevet, weboldal-címet és az elemzés eredményét tárolja, hogy elküldje a riportot, és kapcsolatba lépjen az érdeklődővel.
6. Próbáljátok ki néhány ismert oldallal, a sajátotokkal is.

### Beállítások (Website Grader → Beállítások)

- **Értesítési e-mail:** ide jönnek az új érdeklődők.
- **Konzultáció gomb:** a riport és az e-mail alján lévő gomb szövege és linkje.
- **Korlát:** ennyi elemzés futhat óránként egy látogatótól (alapból 10). Ugyanazt az oldalt egy órán belül nem méri újra, hanem a már kész eredményt adja vissza.
- **Megjelenés:** sötét vagy világos háttér. Alapból a téma `btn-pill` gombjait használja. Ha azok nem jól néznek ki az oldalon, kapcsold ki, és saját lime gombot kapnak.

Az érdeklődők a **Website Grader → Érdeklődők** menüben vannak: teljes riport, CSV export, törlés.

### Terjesztés (erre jönnek majd a linkek)

- **Partner link:** `helloprovision.com/website-grader/?site=cegneve.com` előre kitölti a mezőt. Jól használható kamarai hírlevélben, partnereknek küldött e-mailben, vagy LinkedIn-üzenetben egy konkrét cégnek.
- Ajánljátok fel a Greater Fort Myers és a Cape Coral kamaráknak, üzleti blogoknak és BNI-csoportoknak „ingyenes eszköz a tagoknak” formában.
- Az oldal `WebApplication` schemát kap, ingyenes eszközként.

### Tudnivalók

- Egyes oldalak (Cloudflare és egyéb biztonsági szolgáltatások) blokkolják az elemzőt. Ilyenkor a látogató üzenetet kap, hogy vegye fel veletek a kapcsolatot.
- A Google sebességmérése futásonként néhány pontot ingadozhat. Ez normális, az eszköz GYIK része is elmondja.
- Ha a szerver Cloudflare vagy más proxy mögött van, a látogatónkénti korlát a proxy IP-címét látja. Ilyenkor a `hpv_grader_client_ip` filterrel állítható be a valódi IP.

## `plugins/helloprovision-portal/` – Ügyfélportál, CRM, projektkezelő és videóhívás (0.3)

Egy WordPress bővítmény, két bejárattal:

| Cím | Kinek | Mit lát |
|---|---|---|
| `crm.helloprovision.com` | a csapat (Adminisztrátor és „Munkatárs (CRM)” szerepkör) | minden ügyfelet, a belső jegyzeteket és az összes adatot |
| `clients.helloprovision.com` | az ügyfél portál-felhasználói („Ügyfél (portál)” szerepkör) | csak a saját cégét, és abból is csak azt, amit láthatóvá tettetek |

Mindkét cím csak bejelentkezés után működik.

**A CRM webalkalmazás (`crm.helloprovision.com` nyitóoldala, magyar felület):**

Saját, gyors felület, nem a WordPress admin. Oldalújratöltés nélkül működik, mobilon is.

- **Vezérlőpult:**
  - saját nyitott és lejárt feladatok, a csapat heti teljesítése, a rögzített idő;
  - havi ismétlődő bevétel, kintlévőség, aktív ügyfelek, aláírásra váró szerződések;
  - a ma esedékes feladatok és az aktív projektek haladással.
- **Saját feladataim:** csoportosítva: lejárt, ma, 7 napon belül, később, határidő nélkül.
- **Projektek:**
  - szűrés ügyfélre, státuszra, felelősre;
  - színek;
  - sablonok: új projekt sablonból, a feladatok dátumai a kezdőnaphoz igazodnak.
- **Projekt nézetek:**
  - **Tábla** (Kanban, húzással átrendezhető): Teendő → Folyamatban → Belső ellenőrzés → Ügyfélre vár → Kész;
  - **Lista:** gyors szerkesztés soron belül, kinyitható alfeladatok;
  - **Idővonal (Gantt):**
    - a sáv húzással mozgatható, a két végén nyújtható;
    - a függőségek nyilakkal látszanak;
    - a nézet a mai napra görget.
- **Feladat panel:**
  - státusz, felelős, prioritás, kezdés, határidő, becslés, látható-e az ügyfélnek;
  - leírás, alfeladatok, ellenőrzőlista, hozzászólások;
  - függőségek (körkörös függőséget nem enged).
- **Időmérés:**
  - stopper egy kattintással: felhasználónként egy futhat, fent végig látszik és onnan leállítható;
  - utólagos időrögzítés, időnapló.
- **Értesítések:**
  - az új felelős e-mailt kap;
  - ha egy látható feladat „Ügyfélre vár” lesz, az ügyfél is értesítést kap.
- **Keresés (Ctrl+K):** projektek, feladatok és ügyfelek egy helyen.
- **Chat:** csoportok és ügyfél-csatornák, olvasatlan-számlálóval; a projekt fejlécéből egy kattintással nyílik az ügyfél csatornája.
- **Videóhívás (Daily.co):**
  - indítás a Hívások oldalról vagy a projekt fejlécéből („Hívás”);
  - a csatlakozási link bekerül az ügyfél chat-csatornájába, kérésre e-mail meghívó is megy;
  - a hívás a CRM-be, illetve a portálba ágyazva fut, videófelvétel opcionális.
- **Leirat és AI-összefoglaló:**
  - a munkatárs belépésekor automatikusan indul a leirat;
  - a hívás után elkészül az összefoglaló: döntések, teendők (kié és mikorra), belső megjegyzések;
  - a teendők egy kattintással feladatok lesznek a projektben (az ügyfél teendője „Ügyfélre vár” státusszal);
  - az összefoglalót egy kapcsolóval meg lehet osztani az ügyféllel; a belső megjegyzéseket és a leiratot az ügyfél nem látja;
  - kereshető leirat, felvétel letöltése, e-mail a hívást indítónak, ha kész.
- **Hozzájárulás a rögzítéshez:** Floridában minden fél beleegyezése kell. Az ügyfél csak a hozzájárulás bejelölése után léphet be; a rendszer naplózza, ki, mikor, milyen IP-címről fogadta el.

A számla-, szerződés- és szolgáltatás-szerkesztők egyelőre a klasszikus CRM-ben vannak (WordPress admin). A webalkalmazás oldalsávja oda linkel.

**A klasszikus CRM-ben (WordPress admin, magyar felület):**
- **Ügyfelek:** kulcsszámok (aktív ügyfelek, havi ismétlődő bevétel, kintlévőség, lejárt számlák, aláírásra váró szerződések), ügyfél-adatlap.
- **Portál-meghívó:** a kapott e-mailben jelszó-beállító link van. A hozzáférés visszavonható.
- **Szolgáltatás-katalógus és előfizetések:** havi, negyedéves, éves és egyszeri díjak.
- **Projektek és feladatok:** feladatonként állítható, hogy látja-e az ügyfél; az ügyfél az alfeladatokat és a sablonokat nem látja.
- **Számlák:**
  - tételek, adó, automatikus számlaszám;
  - fizetési link (pl. Stripe Payment Link), ez a portálon „Pay now” gombként jelenik meg;
  - „kiküldés” e-mailt küld, „fizetve” jelöléssel lezárható.
- **Szerződések:**
  - szövegszerkesztő, „kiküldés aláírásra”;
  - aláíráskor a rendszer rögzíti az aláíró nevét, e-mail címét, IP címét, böngészőjét, az időpontot (UTC) és a dokumentum SHA-256 lenyomatát;
  - aláírt szerződés nem szerkeszthető, kiküldött számla és aláírt szerződés nem törölhető, csak érvényteleníthető.
- **Chat:**
  - belső csoportok (#General, #Design team…) és ügyfél-csatornák;
  - az ügyfél-csatornába a portál-felhasználók automatikusan bekerülnek;
  - olvasatlan-számláló a menüben;
  - e-mail értesítés, ha a címzett 2 perce nem nézte a csatornát, csatornánként legfeljebb 15 percenként egy.
- **Tevékenység:** belső jegyzetek és rendszeresemények idővonala.

**A portálon (angol felület):**
- **Overview:** egyenleg, teendők (fizetendő számla, aláírandó szerződés, „Waiting on you” feladat, olvasatlan üzenet), friss hírek.
- **Projects:** haladás és feladattábla.
- **Messages:** chat a csapattal.
- **Meetings:** élő hívásba belépés (hozzájárulás után), és a megosztott hívás-összefoglalók: „Your next steps” és „What we'll do”.
- **Invoices:** nyomtatható számlakép, „Pay now” gomb, „Download PDF” (böngészős nyomtatás).
- **Contracts:** elolvasás és aláírás.
- **Services, Account.**

A portál saját keretben fut, a WordPress témától függetlenül, mobilon is.

### Telepítés

1. **Külön WordPress-telepítés** a CRM-nek, ne a marketing-weboldalon fusson. Így a weboldal bővítményei és szerkesztői nem férnek hozzá az ügyféladatokhoz.
2. **DNS:** a `crm` és a `clients` aldomain A rekordja mutasson erre a szerverre, mindkettőre SSL tanúsítvánnyal (pl. Let's Encrypt).
3. **`wp-config.php`**, a `require_once ABSPATH . 'wp-settings.php';` sor elé:
   ```php
   $hpv_host = strtolower( $_SERVER['HTTP_HOST'] ?? '' );
   if ( in_array( $hpv_host, array( 'crm.helloprovision.com', 'clients.helloprovision.com' ), true ) ) {
       define( 'WP_HOME', 'https://' . $hpv_host );
       define( 'WP_SITEURL', 'https://' . $hpv_host );
   }
   ```
   Más címek esetén: `define( 'HPV_CRM_HOST', '…' ); define( 'HPV_PORTAL_HOST', '…' );`
4. **Bővítmény:** töltsd fel és kapcsold be. Ekkor jönnek létre a táblák és a szerepkörök.
5. **CRM → Beállítások:** cégadatok, számlaszám előtag, fizetési határidő, értesítési cím.
6. **Levélküldés:** WP Mail SMTP, az e-mail útmutató szerint.
7. **Munkatársak:** felhasználóként, „Munkatárs (CRM)” szerepkörrel. Ők csak a CRM-et és a profiljukat látják.
8. **Videóhívás:**
   - Daily.co fiók → Developers → API key;
   - AI kulcs az összefoglalóhoz: Anthropic Console → API keys.
   - A `wp-config.php`-ba:
     ```php
     define( 'HPV_DAILY_API_KEY', '…' );
     define( 'HPV_AI_API_KEY', '…' );
     // define( 'HPV_AI_MODEL', 'claude-sonnet-5' ); // nem kötelező
     ```
   - CRM → Beállítások → Videóhívás: „Kapcsolat tesztelése”, majd „Webhook regisztrálása”.
   - Valódi cron kell (5 percenként): a leirat feldolgozása háttérben fut.

### Következő ütemek

1. **Pénzügy és egyebek:**
   - Stripe fizetés automatikus „fizetve” jelöléssel, ismétlődő számlák automatikus kiállítása;
   - fájlmegosztás, Bitrix24 átköltöztetés;
   - a számla- és szerződés-szerkesztő átköltözése a webalkalmazásba.

## Tesztek

```
php tests/seo.php
php tests/reviews.php
php tests/grader.php
wp eval-file tests/portal-integration.php   # valódi WordPressen, a portál bővítménnyel
wp eval-file tests/video-integration.php    # ugyanott, videó kulcsok nélkül (a Daily-t és az AI-t a teszt helyettesíti)
```

Az első három WordPress nélkül fut. A portál teszt valódi WordPressen és adatbázison fut, és a projektkezelő API-t is végigpróbálja: sablonmásolás, átrendezés, függőségek, stopper, jogosultságok, törlés. A videó teszt a teljes hívás-folyamatot végigviszi: szoba, belépők, hozzájárulás, lezárás, leirat, AI-összefoglaló és hibakezelés, teendőből feladat, webhook, portál. Az SEO teszt az élő főoldal valódi schemáját, a grader teszt a főoldal megtisztított HTML-jét használja (`tests/fixtures/`).

## Amit a plugin nem tud javítani (admin felületen kell)

1. **Beállítások → Általános → Honlap címe:** `HelloProVision` (most `HelloProvision`).
2. **Yoast → Beállítások → Webhely alapjai → Webhely neve:** `HelloProVision`.
3. **Yoast → Beállítások → Speciális → Morzsamenü → Főoldal szövege:** `Home`.
4. **Yoast Local SEO (üzleti adatok):** az utcacím mezőbe csak `12557 New Brittany Blvd, Suite 3` kerüljön, a város és az állam a saját mezőjébe. A jogi név (a Yoast Local SEO-ban vagy a Webhely-reprezentációnál) legyen `Arovia Group LLC`, a `- Helloprovision.com` nélkül.
5. **A főoldal meta leírása** (Yoast doboz a főoldal szerkesztőjében). Javaslat (146 karakter):
   > Web design, SEO and local search for businesses in Fort Myers, Cape Coral and Naples. One team from strategy to launch. Call (239) 955-1655.
6. **Logó:** a `Nevtelen-terv-11-….png` helyett tölts fel egy `helloprovision-logo.png` nevű fájlt, és állítsd be a Yoastban.

## Témát érintő javítások (a `helloprovision` téma kódjában)

- **H1:** most csak `for Southwest Florida Businesses`, mert a kulcsszó („SEO & Local Search”) a H1-en kívül, egy animált `div`-ben van. A teljes szöveg kerüljön a H1-be, az animáció maradhat díszítésnek.
- **Mobil sebesség:** a hero részben futó UnicornStudio WebGL-animációt mobilon érdemes statikus képre cserélni (LCP, INP).
