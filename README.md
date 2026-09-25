# HelloProVision – SEO javítások

A `helloprovision.com` WordPress oldal helyi SEO-javításai (Fort Myers, Cape Coral, Naples).

## `mu-plugins/helloprovision-seo.php`

Must-use plugin, ami a Yoast SEO és a Yoast Local SEO által generált schema adatokat javítja. A Yoast beállításaihoz nem nyúl, csak a kimenetet igazítja.

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

1. A `mu-plugins/helloprovision-seo.php` fájlt töltsd fel a szerverre ide: `wp-content/mu-plugins/`. Ha a mappa nem létezik, hozd létre. Aktiválni nem kell.
2. Ha van cache plugin vagy CDN, ürítsd a gyorsítótárat.
3. Ellenőrizd a [Rich Results Test](https://search.google.com/test/rich-results?url=https%3A%2F%2Fhelloprovision.com%2F) és a [Schema Validator](https://validator.schema.org/) segítségével, majd kérj újraindexelést a Search Console-ban (URL-ellenőrzés, majd „Indexelés kérése”).

### Amit ki kell tölteni (a fájl tetején, `hpv_seo_config()`)

- **`same_as`**: a Google Business Profile linkje, a LinkedIn, a saját Instagram, a Clutch és a Yelp. Az üres sorok kimaradnak, ezért a profilokat akkor is fel lehet venni, amikor később létrejönnek.
- **`opening_hours`**: most hétfőtől vasárnapig 9–17 szerepel. Ha ez nem igaz, állítsd be, és **egyezzen a Google Business Profile-lal**.
- **`hide_address`**: állítsd `true`-ra, ha a Google Business Profile-t service-area vállalkozásként, rejtett címmel regisztráljátok. Ez a javasolt megoldás, ha a New Brittany Blvd-i cím virtuális iroda. Ekkor a cím kikerül a schemából, és csak a kiszolgált városok maradnak.
- **`services`**: ha új szolgáltatás × város oldal készül (pl. `/markets/cape-coral-seo/`), vedd fel ide.

A beállításokat a fájl módosítása nélkül is felül lehet írni, egy másik fájlból a `hpv_seo_config` filterrel.

### Teszt

```
php tests/run.php
```

WordPress nélkül fut, az élő főoldal valódi schemáján (`tests/fixtures/home-schema.json`).

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
