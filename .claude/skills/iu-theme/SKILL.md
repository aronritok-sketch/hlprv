---
name: iu-theme
description: Az Infinite Unity (iu_theme) egyedi WordPress blokk-keretrendszer szabályai és teljes blokk-referenciája. Használd MINDEN WordPress weboldal építésénél és módosításánál — oldal, szekció vagy sablon összerakása, blokk-markup (<!-- wp:iu/... -->) írása, child téma testreszabása (variables.css, templates/*.html), űrlap, csúszka, menü, lista (query) készítése, új iu/ blokk fejlesztése —, és mindig, ha iu_theme, iu/section, iu/row, iu_template vagy iu_pattern szóba kerül. Triggers: WordPress site, WP theme, Gutenberg block markup, page template, child theme.
---

# Infinite Unity (iu_theme): WordPress fejlesztési szabályok

A csapat minden WordPress weboldalt az `iu_theme` keretrendszerre épít. Ez nem Full Site Editing téma: saját sablonmotorja (`iu_template` CPT és HTML fájlok), saját `iu/` blokkjai és saját minta-rendszere (`iu_pattern`) van, a gyári blokkok nagy része le van tiltva.

**Teljes referencia:** `references/iu_theme_documentation.md`. Mielőtt egy blokkot használsz, attribútumot írsz vagy a téma PHP-jához nyúlsz, olvasd el a releváns fejezetet (a fejezetek listája a fájl végén). Az attribútumneveket és -értékeket ne találgasd.

## Alapszabályok

1. **Csak `iu/` blokkok és a hat engedélyezett core blokk** (`core/paragraph`, `core/heading`, `core/list`, `core/list-item`, `core/html`, `core/shortcode`). Minden más core blokk tiltva van, tehát a markupban se szerepeljen:

   | Helyett (tiltott) | Használd |
   |---|---|
   | `core/columns`, `core/column` | `iu/row` > `iu/column` |
   | `core/group`, `core/row`, `core/stack` | `iu/group` |
   | `core/cover` | `iu/section` háttérrel (`bgImage`/`bgColor`) + `lightText` |
   | `core/image` | `iu/image` |
   | `core/gallery` | `iu/image-carousel` / `iu/image-slider` |
   | `core/buttons`, `core/button` | `iu/button-group` > `iu/button` |
   | `core/embed` (YouTube) | `iu/video` |
   | `core/navigation` | `iu/menu` |
   | `core/query`, `core/latest-posts` | `iu/query` |
   | `core/post-title` | `iu/title` |
   | `core/post-featured-image` | `iu/featured-image` |
   | `core/search` | `iu/search` |
   | `core/details` | `iu/accordion` > `iu/accordion-item` |
   | `core/spacer`, `core/separator` | a blokk spacing attribútumai (`marginTop`, `paddingBottom` …) |
   | `core/block` (reusable), `core/pattern` | `iu/pattern` (`iu_pattern` CPT) |
   | `core/template-part` | `iu_template` / `templates/*.html` |

2. **Nincs page builder és nincs űrlap- vagy slider-plugin.** Elementor, Divi, CF7, Gravity Forms, WPForms, Revolution Slider és társaik helyett `iu/form` és az `iu/*slider*` blokkok kellenek.
3. **A szülő `iu_theme`-hez nem nyúlunk, ha csak egy projektről van szó.** Minden projekt-specifikus eltérés a child témába (`child/`) kerül: sablonok, stílus, változók, blokk-felülírás. A szülő módosítása keretrendszer-fejlesztés, ami minden weboldalra hat, ezért csak akkor, ha kifejezetten ez a feladat.
4. **A design CSS változókkal és a `theme.json` palettával állítható.** A `variables.css` értékeit a child téma stíluslapjában, `:root { … }` alatt írd felül. A Gutenberg vizuális beállításai (színválasztó, tipográfia, szegély) szándékosan ki vannak kapcsolva, ne kapcsold vissza őket. Térköz, háttér és link a `supports.iu` paneleken át állítható.
5. **Ne kapcsold vissza, amit a `clean.php` letilt:** hozzászólások, emoji, RSS, oEmbed, fájlszerkesztő.
6. **Nincs beégetett évszám és site URL.** Helyettük `[iu_year]` és `[iu_site_url]`.
7. **Az alapértelmezett felületi szövegek magyarok** (`Keresés...`, `Összes`, `Előző`, `Következő`). Más nyelvű weboldalnál ezeket az attribútumokban mindig állítsd át.

## Oldalszerkezet: kötelező hierarchia

```
iu/section          legfelső szint; csak iu/row lehet benne
└─ iu/row           columns="1-2|1-2" stb.; csak iu/column lehet benne
   └─ iu/column     width = a row columns-ából, automatikusan
      └─ tartalom   core/heading, core/paragraph, core/list, iu/image, iu/group,
                    iu/button-group, iu/card, iu/accordion, iu/form, iu/query …
```

- Tartalmi blokk soha nem kerülhet közvetlenül `iu/section`-be vagy `iu/row`-ba. Egyoszlopos tartalomhoz is kell egy `row` (`"1-1"`) és egy `column`.
- Oszlopszerkezetek: `1-1`, `1-2|1-2`, `1-3|1-3|1-3`, `2-3|1-3`, `1-3|2-3`, `3-4|1-4`, `1-4|3-4`, `1-4|1-4|1-4|1-4`. Más arány nincs, ne találj ki újat.
- Oszlopon belül egymás mellé rendezéshez `iu/group` kell (`direction`, `horizontal`, `vertical`, `equal`).
- Sötét hátterű szekcióban: `bgColor` vagy `bgImage`, és mellé `"lightText": true`.
- Teljes szélesség: `"fullWidth": true`. A tartalom szélessége a `--iu-row-width` változó.
- Térközt a spacing attribútumokkal adj meg, ne üres bekezdéssel. Egy oldalon egy `h1` legyen.

## Sablonrendszer

- Három zóna van: `header`, `content`, `footer`. A téma ebben a sorrendben keres sablont:
  1. admin `iu_template` bejegyzés (Megjelenés → Template-ek) a pontos type/position párra
  2. `child/templates/{type}_{position}.html`
  3. `iu_theme/templates/{type}_{position}.html`
  4. ugyanez a lánc `global` típussal
- Típusok: `front_page` (utána `single_page`), `blog_page`, `404`, `search`, `single_{post_type}`, `archive_{post_type}`, `tax_category`, `tax_post_tag`, `tax_{taxonomy}`. Fájlnév-példák: `single_post_content.html`, `front_page_header.html`, `archive_product_content.html`.
- **Dupla H1:** a `global_content.html` egy `iu/title` (h1) blokkot tesz a tartalom elé. Ha az oldalak saját hero szekcióval és saját H1-gyel készülnek, hozd létre a `child/templates/single_page_content.html` fájlt, benne csak ezzel a sorral: `<!-- wp:iu/content /-->`.
- Sablont inkább fájlként írj a `child/templates/`-be, mert így verziókövetés alatt van. Az admin sablon a fájlt felülírja: ha egy fájl-módosítás nem látszik, először azt nézd meg, van-e admin sablon ugyanarra a type/position párra.
- Az `iu/content` a bejegyzés tartalmának helyőrzője. Az `iu/template` rendszerblokk: kézzel ne tedd be és ne töröld.

## Blokk-markup írása

- Gutenberg komment-szintaxis, az attribútumok JSON-ben. Csak az alapértéktől eltérő attribútumokat írd ki.
- **Dinamikus (render.php-s) blokkok önzáróak:** `iu/title`, `iu/content`, `iu/featured-image`, `iu/video`, `iu/menu`, `iu/breadcrumbs`, `iu/post-navigation`, `iu/search`, `iu/query`, `iu/terms`, `iu/term-cloud`, `iu/pattern`. Példa:
  `<!-- wp:iu/title {"heading":"h1","align":"center"} /-->`
- **Statikus blokkoknál** (section, row, column, group, button, card, image stb.) a komment közötti HTML-nek pontosan meg kell egyeznie a blokk `block.js`-ében lévő `save()` kimenetével. Ha nem egyezik, a szerkesztő „váratlan tartalom” hibát jelez. Mielőtt ilyen markupot generálsz, nézd meg a `save()` függvényt vagy egy meglévő példát. A `iu_theme/templates/global_header.html` pont Section > Row > Column > Group szerkezetű, ebből érdemes kiindulni. Ha egyik sem érhető el, szólj, hogy a markupot érdemes a szerkesztő kódnézetéből kimásolni.
- A core blokkoknál a WordPress szabványos mentett HTML-jét használd (pl. `<h2 class="wp-block-heading">…</h2>`). A `wp-block-heading` és `wp-block-list` osztályokat a téma renderkor eltávolítja.
- A `params` attribútumok (sliderek, `iu/query`, `iu/post-navigation`) **kapcsos zárójel nélküli JSON-t** tartalmaznak stringként, tehát a komment-JSON-ben escapelni kell:
  `<!-- wp:iu/image-carousel {"params":"\"slidesToShow\": 4, \"slidesToScroll\": 1, \"infinite\": true"} /-->`
- A komment-JSON-ben a `<`, `>` és `&` karaktert `<`, `>`, `&` alakban írd (így szerializál a WordPress is), `--` pedig ne legyen benne. Ez főleg az `iu/query` `template` HTML-jénél számít.
- A reszponzív láthatóság a `className` attribútumban adható meg: `d-none m-block` (csak mobilon látszik), `m-none` (mobilon rejtett). A töréspont 992px, ezért a child CSS-ben is ugyanezt használd: `@media (max-width: 991px)`.

## Űrlapok (`iu/form`)

- A `formId` egyedi, stabil, kebab-case azonosító (pl. `ajanlatkeres`), mert a hookok erre épülnek: `iu_form_submit_{formId}` és `iu_form_ac_data_{formId}`. Élesítés után ne nevezd át.
- A mezők `name` értéke űrlapon belül egyedi. Az ActiveCampaign mezőnév is ez lesz.
- `validate` formátuma: soronként egy `szabály|hibaüzenet`, a támogatott szabályok `required` és `email`. Példa: `"required|Kötelező mező\nemail|Érvénytelen e-mail cím"`.
- Minden űrlapra kell egy `iu/form-accept` adatvédelmi checkbox (GDPR), linkkel az adatkezelési tájékoztatóra.
- Töltsd ki az `email`, `subject`, `from` és `success` attribútumot. A `from` a weboldal saját domainjén legyen, különben romlik a kézbesíthetőség. SMTP-t kell beállítani.
- A backend az űrlapot a header, content és footer sablonokban keresi. Ha `iu/pattern`-be vagy `iu/modal`-ba kerül, mindenképp teszteld a beküldést.
- Az `ac_key` a bejegyzés tartalmában tárolódik, tehát minden szerkesztő látja. Csak akkor add meg, ha ez elfogadható. A hook-függvények pontos paramétereit az `inc/forms.php`-ból nézd meg.
- Többlépcsős űrlaphoz: `iu/form-steps` + `iu/form-step`.

## Csúszkák és listák

- A csúszkák Slick Slidert használnak, a `params` Slick-opciókat vár.
- A `_content-slider` mappa `_` prefixe miatt **az `iu/content-slider` nincs regisztrálva**. Helyette `iu/slider` > `iu/slide` kell, hacsak nem kapcsolod be kifejezetten.
- Az `iu/logo-slider` mappája elgépelve `logo-sider`. Ne javítsd ki, mert a blokk betöltése erre épül.
- `iu/query`: archív és keresés sablonban `"main_query": true`, egyedi listához `false` + `params`. Az elem-sablonban az `[iu_post_*]` shortcode-ok használhatók. Lapozáshoz `"paging": true`.

## Új blokk fejlesztése

- Helye `inc/blocks/{nev}/` (vagy a child témában), a `block.json` neve `iu/{nev}`. A `_` prefixes mappa nem töltődik be, így lehet egy blokkot kikapcsolni.
- Az extra paneleket a `block.json` kapcsolja be: `"supports": { "iu": { "spacing": true, "background": true, "link": true } }`. A spacing- és háttér-stílust a téma sorszámozott osztállyal (`.iu-{blokk}_{n}`) a `<head>`-be írja, a render.php-ba ne írj erre inline stílust.
- Dinamikus blokkhoz `render.php`, frontend JS-hez `viewScript` kell (csak akkor töltődik be, ha a blokk az oldalon van). Az interaktív blokkok jQuery-re épülnek.
- CSS osztályok: `.iu-{blokk}`, módosítók: `.iu-{blokk}-{modifier}`. Színt, betűt, térközt `var(--iu-…)` változóval adj meg.

## Buktatók

- **REST API prefix:** a `/wp-json/` helyett `/87sdtzugas76fgcw8atedw/`. A beégetett `/wp-json/` URL-ek (saját JS, pluginek) nem működnek, ezért mindig `rest_url()` kell. Új plugin telepítése után ezt teszteld.
- **`--iu-text-font-size: 1vw`:** mobilon ez nagyon kicsi. Minden projektben ellenőrizd, és ha nincs mobil felülírás, állítsd be pl. `clamp()`-pel a child témában.
- A `wp-block-library` CSS nem töltődik be, így a core blokkoknak nincs gyári stílusa.
- **Menü ID:** az `iu/menu` `menu` attribútuma term ID, ami a helyi és az éles környezetben eltérhet. Deploy után ellenőrizd a header és footer menüket.
- Az `iu/video` a szerkesztőben nem jelenik meg, csak a frontenden.
- Az adminban a fájlszerkesztő ki van kapcsolva, a deploy git/FTP-n megy.
- SVG feltöltés engedélyezve van, ezért SVG-t csak megbízható forrásból tölts fel.

## Új weboldal: ellenőrzőlista

1. Child téma: stíluslap-fejléc `Template: iu_theme`, `:root` változók (betűk, méretek, színek, térközök), webfontok.
2. `theme.json` paletta a márkaszínekre (white, primary, dark-gray, light-gray slugok).
3. Sablonok a `child/templates/`-ben: `global_header` (logó + `iu/menu`), `global_footer` (`[iu_year]`), `single_page_content` (ha hero-s oldalak vannak), `front_page_content`, `404_content`, `search_content`, és post type-onként a `single_*`, `archive_*` sablonok.
4. Menük a WP adminban, a menü ID beírása az `iu/menu`-be.
5. Ismétlődő szekciók (CTA, kapcsolat) `iu_pattern`-ként.
6. Oldalak Section > Row > Column szerkezettel.
7. Űrlapok: validáció, e-mail kézbesítés és sikerüzenet tesztje.
8. Átadás előtt: mobil nézet, oldalanként egy H1, 404, keresés, breadcrumbs, REST-hívások, Lighthouse.

## Referencia fejezetek (`references/iu_theme_documentation.md`)

| § | Tartalom |
|---|---|
| 1–3 | Architektúra, fájlszerkezet, PHP modulok (theme, blocks, clean, templates, forms, patterns) |
| 4 | Alapértelmezett sablonfájlok |
| 5 | CSS változók, `theme.json` paletta |
| 6 | Reszponzív `d-`/`m-` osztályok |
| 7 | `supports.iu`: spacing, background, link attribútumok |
| 8 | Section, Row, Column, Group |
| 9 | Title, Content, Card |
| 10 | Image, Featured Image, Video, Icon Group / Icon |
| 11 | Csúszkák: Image Carousel, Image Slider, Slider/Slide, Content Slider, HTML Slider, Logo Slider, Text Scroller |
| 12 | Menu, Breadcrumbs, Post Navigation, Search, Back to Top |
| 13 | Query, Terms, Term Cloud |
| 14 | Accordion, Tabs, Modal, Button Group / Button |
| 15 | Template, Pattern, Test (belső blokkok) |
| 16 | Űrlap blokkok |
| 17 | Shortcode-ok |
| 18–20 | Pattern-ek, engedélyezett core blokkok, vendor könyvtárak |
