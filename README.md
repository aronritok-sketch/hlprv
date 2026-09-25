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

## Tesztek

```
php tests/seo.php
php tests/reviews.php
php tests/grader.php
php tests/linora.php
```

WordPress nélkül futnak. Az SEO teszt az élő főoldal valódi schemáját, a grader teszt a főoldal megtisztított HTML-jét használja (`tests/fixtures/`).

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

## `linora/` – L'INORA időpontfoglaló

Külön ügyfél (L'INORA, Zsadányi Zsanett): kezelési konfigurátor blokk Amelia + WooCommerce alapon. Részletek: [`linora/README.md`](linora/README.md).
