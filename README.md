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

## Tesztek

```
php tests/seo.php
php tests/reviews.php
```

WordPress nélkül futnak. Az SEO teszt az élő főoldal valódi schemáját használja (`tests/fixtures/home-schema.json`).

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
