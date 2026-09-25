# Infinite Unity (iu_theme) — Teljes Dokumentáció

> Ez a dokumentum az `iu_theme` WordPress téma teljes, részletes referenciáját tartalmazza: az architektúrától a sablonrendszeren át az összes egyedi blokkig és azok tulajdonságaiig.

---

## Tartalomjegyzék

1. [Téma Áttekintés és Architektúra](#1-téma-áttekintés-és-architektúra)
2. [Fájlszerkezet](#2-fájlszerkezet)
3. [PHP Modulok](#3-php-modulok)
4. [Sablonrendszer (Templates)](#4-sablonrendszer-templates)
5. [CSS Változók és Designrendszer](#5-css-változók-és-designrendszer)
6. [Reszponzív Segédosztályok](#6-reszponzív-segédosztályok)
7. [Globális Blokk-tulajdonságok (Supports)](#7-globális-blokk-tulajdonságok-supports)
8. [Egyedi Blokkok — Struktúra és Elrendezés](#8-egyedi-blokkok--struktúra-és-elrendezés)
9. [Egyedi Blokkok — Tartalom és Szöveg](#9-egyedi-blokkok--tartalom-és-szöveg)
10. [Egyedi Blokkok — Média](#10-egyedi-blokkok--média)
11. [Egyedi Blokkok — Csúszkák és Animációk](#11-egyedi-blokkok--csúszkák-és-animációk)
12. [Egyedi Blokkok — Navigáció és Keresés](#12-egyedi-blokkok--navigáció-és-keresés)
13. [Egyedi Blokkok — Adatmegjelenítés](#13-egyedi-blokkok--adatmegjelenítés)
14. [Egyedi Blokkok — Interaktív elemek](#14-egyedi-blokkok--interaktív-elemek)
15. [Egyedi Blokkok — Rendszer és Belső](#15-egyedi-blokkok--rendszer-és-belső)
16. [Űrlap Blokkok (Form Blocks)](#16-űrlap-blokkok-form-blocks)
17. [Shortcode-ok](#17-shortcode-ok)
18. [Pattern-ek (Minták)](#18-pattern-ek-minták)
19. [Engedélyezett Core Blokkok](#19-engedélyezett-core-blokkok)
20. [Vendor könyvtárak](#20-vendor-könyvtárak)

---

## 1. Téma Áttekintés és Architektúra

Az **Infinite Unity** (`iu_theme`) egy egyedi WordPress blokk-alapú keretrendszer. Nem a WordPress Full Site Editing rendszerét használja, hanem egy saját sablon motort, amely:

- Letiltja az összes beépített WordPress gyári blokkot (kivéve egy szűk listát)
- Saját `iu/` előtagú blokkokat biztosít az oldalak összeállításához
- Saját Custom Post Type-alapú sablonkezelőt (`iu_template`) használ
- Eltávolítja a WordPress felesleges funkcióit (hozzászólások, emoji-k, RSS feedek, oEmbed stb.)
- Elrejti a REST API alapértelmezett URL-jét (egyedi prefix: `87sdtzugas76fgcw8atedw`)
- Engedélyezi az SVG fájlok feltöltését
- Letiltja a fájl-szerkesztést az admin felületen
- Eltávolítja a dashboard widgeteket

A téma **child theme kompatibilis**: a `child/` mappában felülírhatók a sablonok, blokkok és stílusok. A szülő és a child téma stíluslapja is automatikusan betöltődik.

---

## 2. Fájlszerkezet

```
iu_theme/
├── functions.php              # Belépési pont, betölti a Theme osztályt
├── header.php                 # HTML <head> és <body> nyitás, wp_head(), action hook-ok
├── footer.php                 # wp_footer(), </body></html> zárás
├── index.php                  # Fő renderelő: header + content + footer sablon betöltés
├── style.css                  # Alapstílus (box-sizing, alap tipográfia, reszponzív utilok)
├── variables.css              # CSS változók (betűtípusok, méretek, színek, térközök)
├── theme.json                 # WordPress Gutenberg beállítások szűrése/letiltása
├── screenshot.jpg             # Téma előnézeti kép
│
├── templates/                 # Alapértelmezett sablon HTML fájlok
│   ├── global_header.html     # Globális fejléc sablon
│   ├── global_content.html    # Globális tartalom sablon (cím + content blokk)
│   ├── global_footer.html     # Globális lábléc sablon
│   ├── front_page_content.html # Főoldal sablon (csak iu/content)
│   ├── search_content.html    # Keresési eredmények sablon
│   └── 404_content.html       # 404-es hibaoldal sablon
│
├── inc/
│   ├── theme.php              # Fő Theme osztály (hookok, téma support, enqueue)
│   ├── blocks.php             # Blokk-regisztráció, támogatások, renderelés
│   ├── clean.php              # WordPress tisztítás (emoji, feed, comments stb.)
│   ├── forms.php              # Űrlap feldolgozás (validáció, email küldés, ActiveCampaign)
│   ├── patterns.php           # Pattern (minta) rendszer kezelése
│   ├── shortcodes.php         # Beépített shortcode-ok
│   ├── templates.php          # Sablonkezelő (CPT + fájl fallback)
│   ├── patterns.js            # Pattern szerkesztő JavaScript
│   │
│   ├── blocks/                # Egyedi blokk mappák (40 almappa)
│   │   ├── block-styles.css   # Globális blokk stílusok (tipográfia)
│   │   ├── block-utils.js     # Szerkesztő segédeszközök (háttér, spacing, link panelek)
│   │   ├── section/           # iu/section blokk
│   │   ├── row/               # iu/row blokk
│   │   ├── column/            # iu/column blokk
│   │   ├── group/             # iu/group blokk
│   │   ├── ... (lásd részletes leírás alább)
│   │   └── test/              # Teszt blokk (fejlesztői célokra)
│   │
│   ├── form-blocks/           # Űrlap-specifikus blokkok (7 almappa)
│   │   ├── text/              # iu/form-text
│   │   ├── textarea/          # iu/form-textarea
│   │   ├── select/            # iu/form-select
│   │   ├── radios/            # iu/form-radios
│   │   ├── accept/            # iu/form-accept
│   │   ├── steps/             # iu/form-steps
│   │   └── step/              # iu/form-step
│   │
│   └── js/
│       └── slick.min.js       # Slick Slider könyvtár
│
└── vendor/
    └── activecampaigne/       # ActiveCampaign PHP API kliens
```

---

## 3. PHP Modulok

### 3.1 Theme (`iu_theme/inc/theme.php`)
A központi vezérlő osztály, amely az összes modult inicializálja.

**Fő funkciók:**
- `theme_support()`: Bekapcsolja a post-thumbnails, menus, editor-styles támogatásokat; letiltja a core-block-patterns és block-templates-t.
- `enqueue_main_style()`: Betölti a szülő és child téma stíluslapjait; eltávolítja a `wp-block-library` és `wp-block-library-theme` stílusokat.
- `editor_fixes()`: CSS javítások a blokk szerkesztőben (előnézet elrejtés, tabbar elrejtés, tipográfia panel javítás).
- `body_class()`: Egyedi `<body>` osztályok generálása (`admin-bar`, `front-page`).
- `allow_svg_upload()`: SVG fájlok feltöltésének engedélyezése.
- `remove_query_params()`: JavaScript-tel eltávolítja az `error` és `success` URL paramétereket az oldal betöltése után.
- `remove_default_settings()`: A `theme.json` alapértelmezett beállításait (színpaletta, gradient-ek, font-méretek, árnyékok, spacingek) programozottan nullázza ki.

### 3.2 Blocks (`iu_theme/inc/blocks.php`)
Az egyedi blokkok regisztrálását, a core blokkok szűrését és a blokk-stílusok renderelését végzi.

**Fő funkciók:**
- `register_blocks()`: Bejárja a `blocks/` és `form-blocks/` almappákat, és minden alkönyvtárat (ami nem `_` prefixszel kezdődik) `register_block_type()`-al regisztrál. Ez azt jelenti, hogy a `_content-slider` mappa **kikapcsolt/inaktív** blokk — nem töltődik be.
- `remove_core_blocks()`: Csak a `core/paragraph`, `core/heading`, `core/list`, `core/list-item`, `core/html` és `core/shortcode` blokkokat engedélyezi.
- `add_supports()`: A blokkok `supports.iu` tulajdonságai alapján dinamikusan hozzáadja a `background`, `link` és `spacing` attribútumokat (lásd [7. fejezet](#7-globális-blokk-tulajdonságok-supports)).
- `block_styles()`: Rendereléskor minden blokkhoz egyedi sorszámozott CSS osztályt ad hozzá (pl. `.iu-section_1`, `.iu-section_2`), amelyet a rendszer inline `<style>` tag-ben használ az oldal fejlécében az egyedi `style` attribútumok (padding, margin, háttérkép) megjelenítésére.
- `render_block_styles()`: A `wp_head` action-re akasztva kiírja a `<style id="iu-block-styles-css">` tag-et, amelyben az összes renderelt blokk egyedi stílusa van.
- A `core/heading` és `core/list` renderelésénél eltávolítja a `wp-block-heading` és `wp-block-list` osztályneveket.
- Az `iu/content` blokk renderelése során a globális `$post->post_content`-et szúrja be.

### 3.3 Clean (`iu_theme/inc/clean.php`)
A WordPress felesleges kimeneteinek és funkcióinak eltávolítása.

**Letiltott funkciók:**
- WordPress verziószám generátor
- REST API link a `<head>`-ben, oEmbed
- RSD, WLW manifest, shortlink, resource hints, feed linkek
- Emoji szkriptek és stílusok (mind frontend, mind admin)
- TinyMCE emoji plugin
- RSS feedek (404-et ad vissza bármely feed URL-re)
- SVG szűrők a body-ban
- **Hozzászólások teljesen letiltva**: comments meta box, menu, admin oldal, adminbar, post type support

### 3.4 Templates (`iu_theme/inc/templates.php`)
A saját sablonmotor logikája.

**Custom Post Type: `iu_template`**
- Megjelenés → Template-ek menüpont alatt kezelhető
- Minden sablonhoz két meta mező tartozik: **Position** (header/content/footer) és **Type** (global, front_page, single_page stb.)
- A lista nézetben megjelenik a Position és Type oszlop

**Sablon betöltés prioritási sorrendje** (a `get_template()` metódusban):
1. Specifikus típusra illeszkedő WP admin sablon (pl. `iu_template` bejegyzés `position=content`, `type=single_page`)
2. Child téma fájl: `child/templates/{type}_{position}.html`
3. Parent téma fájl: `iu_theme/templates/{type}_{position}.html`
4. Ha a specifikus típus nem található, a `global` típusra is végigmegy a láncon

**Típus detektálás** (`get_query_type()`):
| WordPress kondíció | Visszaadott típus(ok) |
|---|---|
| `is_front_page()` | `front_page`, `single_page` |
| `is_home()` | `blog_page` |
| `is_404()` | `404` |
| `is_search()` | `search` |
| `is_category()` | `tax_category` |
| `is_tag()` | `tax_post_tag` |
| `is_singular()` | `single_{post_type}` |
| `is_tax()` | `tax_{taxonomy}` |
| `is_archive()` | `archive_{post_type}` |

**Három zónás renderelés** az `index.php`-ból:
1. `header_template()` → `<header>...</header>`
2. `main_template()` → `<main>...</main>` — ha van sablon, annak tartalmát rendereli, ha nincs, a WordPress loop-ot futtatja
3. `footer_template()` → `<footer>...</footer>`

**Template blokk integráció**: Ha egy `single_{post_type}` típusú sablon létezik, az `alter_post_content()` metódus a bejegyzés tartalmát automatikusan egy `<!-- wp:iu/template -->...<!-- /wp:iu/template -->` blokkba csomagolja a szerkesztőben. A `handle_save()` metódus a mentésnél visszabontja és csak a tényleges tartalom kerül elmentésre.

### 3.5 Forms (`iu_theme/inc/forms.php`)
Az egyedi űrlapok backend feldolgozása.

**Működés:**
1. A `template_redirect` hook-on figyeli a `$_POST['iu_form_id']` meglétét
2. Megkeresi a megfelelő `iu/form` blokkot a header, content vagy footer sablonokban (rekurzívan)
3. Kiolvassa a blokkok `validate` attribútumait és lefuttatja a validációt
4. Támogatott validációs szabályok: `required`, `email`
5. Ha a validáció sikeres és van ActiveCampaign konfiguráció (`ac_url`, `ac_key`, `ac_listid`), meghívja az AC API-t
6. Ha van email cím beállítva, `wp_mail()`-el elküldi az üzenetet
7. JSON választ ad vissza (`{ "success": 1 }` vagy `{ "errors": {...} }`)
8. Szűrő hookok: `iu_form_submit_{form_id}` és `iu_form_ac_data_{form_id}`

### 3.6 Patterns (`iu_theme/inc/patterns.php`)
Egyedi minta-kezelő rendszer az `iu_pattern` Custom Post Type-al.

**Funkcionalitás:**
- **Synced** és **Unsynced** nézetek a lista oldalon
- A szerkesztőben elérhető variációként megjelenik az összes minta
- A `iu/pattern` blokk a `ref` attribútumban hivatkozik a mintára (ID alapján), és rendereléskor a pattern tartalmát `the_content` filterrel jeleníti meg

### 3.7 Shortcodes (`iu_theme/inc/shortcodes.php`)
Lásd a [17. fejezet](#17-shortcode-ok).

---

## 4. Sablonrendszer (Templates)

### Alapértelmezett sablon fájlok

#### `iu_theme/templates/global_content.html`
Az alapértelmezett tartalom sablon. Tartalmaz egy `iu/title` blokkot (h1, középre igazított) és egy `iu/content` blokkot. Ha egy oldalhoz nincs specifikus tartalom-sablon, ez töltődik be. **Ez a szekció generálja az automatikus oldalcímet** — ha nem szükséges, a child témában felülírható (pl. `child/templates/single_page_content.html` amely csak `<!-- wp:iu/content /-->` sort tartalmaz).

#### `iu_theme/templates/global_header.html`
Alapértelmezett fejléc sablon, ami egy Section > Row > Column > Group struktúrába helyez egy h3 címsort (link a főoldalra) és egy menü blokkot.

#### `iu_theme/templates/global_footer.html`
Alapértelmezett lábléc sablon: szerzői jogi szöveg (`© [iu_year]. Infinite Unity`) és egy „Lap tetejére" gomb (`iu/backtotop`).

#### `iu_theme/templates/front_page_content.html`
A főoldal tartalom sablonja — mindössze egyetlen `<!-- wp:iu/content /-->` sort tartalmaz, ami azt jelenti, hogy a főoldal automatikusan az adott oldal Gutenberg tartalmát rendereli cím-szekció nélkül.

#### `iu_theme/templates/search_content.html`
Keresési eredmények sablonja: egy Title szekció (ami „Keresés erre: ..." szöveget jelenít meg) és egy Query blokk, amely lapozható bejegyzéslistát renderel.

#### `iu_theme/templates/404_content.html`
404-es hibaoldal sablon: egy nagy „404" címsor és egy magyarázó bekezdés.

---

## 5. CSS Változók és Designrendszer

A téma a `iu_theme/variables.css` fájlban definiálja az összes testreszabható CSS változót. Ezek a szerkesztőben és a frontend-en is érvényesek.

### Betűtípusok

| Változó | Alapérték | Leírás |
|---|---|---|
| `--iu-text-font-family` | `sans-serif` | Alap szöveges betűtípus |
| `--iu-heading-font-family` | `var(--iu-text-font-family)` | Címsor betűtípus |
| `--iu-menu-font-family` | `var(--iu-text-font-family)` | Menü betűtípus |

### Betűméretek

| Változó | Alapérték | Leírás |
|---|---|---|
| `--iu-text-font-size` | `1vw` | Alapszöveg méret (viewport-relatív) |
| `--iu-text-small-font-size` | `calc(var(--iu-text-font-size) * 0.87)` | Kisebb szöveg |
| `--iu-heading-1-font-size` | `3.4rem` | H1 méret |
| `--iu-heading-2-font-size` | `2.4rem` | H2 méret |
| `--iu-heading-3-font-size` | `1.5rem` | H3 méret |
| `--iu-heading-4-font-size` | `1.3rem` | H4 méret |
| `--iu-heading-5-font-size` | `1.2rem` | H5 méret |
| `--iu-heading-6-font-size` | `1.1rem` | H6 méret |
| `--iu-button-font-size` | `1rem` | Gomb betűméret |
| `--iu-menu-font-size` | `1rem` | Menü betűméret |
| `--iu-back-to-top-font-size` | `var(--iu-text-font-size)` | Vissza a tetejére gomb mérete |

### Sortávolságok

| Változó | Alapérték |
|---|---|
| `--iu-text-line-height` | `1.5em` |
| `--iu-text-small-line-height` | `calc(var(--iu-text-line-height) * 0.87)` |
| `--iu-heading-line-height` | `1.1` |

### Színek

| Változó | Alapérték | Leírás |
|---|---|---|
| `--iu-text-color` | `var(--wp--preset--color--dark-gray)` → `#373737` | Alap szövegszín |
| `--iu-text-color-muted` | `var(--wp--preset--color--light-gray)` → `#939393` | Halványabb szövegszín |
| `--iu-heading-color` | `var(--iu-text-color)` | Címsor szín |
| `--iu-menu-color` | `var(--iu-text-color)` | Menü szövegszín |
| `--iu-link-color` | `var(--wp--preset--color--primary)` → `black` | Link szín |
| `--iu-icon-default-color` | `var(--iu-text-color)` | Ikon alapszín |
| `--iu-icon-highlight-color` | `var(--wp--preset--color--primary)` | Kiemelt ikon szín |

### Elrendezés és Térközök

| Változó | Alapérték | Leírás |
|---|---|---|
| `--iu-section-fullwidth-padding` | `2rem` | Full-width szekció belső térköze |
| `--iu-section-default-padding` | `2.5rem` | Szekció alapértelmezett padding |
| `--iu-row-width` | `80%` | A sorok maximális szélessége |
| `--iu-column-gap` | `2.1rem` | Oszlopok közötti rés |
| `--iu-group-gap` | `.9rem` | Csoport elemek közötti rés |
| `--iu-menu-gap` | `1.5rem` | Menü elemek közötti rés |

### theme.json Színpaletta

A `theme.json` fájlban 4 előre definiált szín van, amelyekre a CSS változók hivatkoznak:

| Név | Slug | Szín |
|---|---|---|
| White | `white` | `#ffffff` |
| Primary | `primary` | `black` |
| Dark gray | `dark-gray` | `#373737` |
| Light gray | `light-gray` | `#939393` |

> [!NOTE]
> A `theme.json` szándékosan letiltja szinte az összes Gutenberg vizuális beállítást (színválasztó, háttérkép, szegély, tipográfia, térközök stb.), hogy a blokkok kizárólag a téma egyedi paneljein keresztül legyenek testreszabhatóak.

---

## 6. Reszponzív Segédosztályok

A `iu_theme/style.css` fájl tartalmaz két media query blokkot, amelyek asztali és mobil nézetben is alkalmazhatóak bármely elemen CSS osztály hozzáadásával:

### Asztali (≥ 992px) — `d-` prefix

| Osztály | Hatás |
|---|---|
| `.d-block` | `display: block !important` |
| `.d-inline-block` | `display: inline-block !important` |
| `.d-flex` | `display: flex !important` |
| `.d-inline-flex` | `display: inline-flex !important` |
| `.d-none` | `display: none !important` |

### Mobil (≤ 991px) — `m-` prefix

| Osztály | Hatás |
|---|---|
| `.m-block` | `display: block !important` |
| `.m-inline-block` | `display: inline-block !important` |
| `.m-flex` | `display: flex !important` |
| `.m-inline-flex` | `display: inline-flex !important` |
| `.m-none` | `display: none !important` |

**Használat**: Egy elemhez add hozzá a `d-none m-block` osztályokat, és az elem csak mobilon lesz látható (asztali nézetben rejtett).

---

## 7. Globális Blokk-tulajdonságok (Supports)

A blokkok `block.json` fájljában a `supports.iu` objektum jelzi, hogy milyen extra szerkesztő-paneleket és attribútumokat kap a blokk. Ezeket a `Blocks::add_supports()` metódus dinamikusan regisztrálja.

### 7.1 Spacing (Térközök)
Ha `"spacing": true` van beállítva, a blokk kap egy térköz-panelt az oldalsávban:

| Attribútum | Típus | Leírás |
|---|---|---|
| `marginTop` | `string` | Felső margó (pl. `20px`, `2rem`) |
| `marginBottom` | `string` | Alsó margó |
| `paddingTop` | `string` | Felső belső térköz |
| `paddingBottom` | `string` | Alsó belső térköz |
| `paddingLeft` | `string` | Bal belső térköz |
| `paddingRight` | `string` | Jobb belső térköz |
| `style` | `string` | Inline CSS stílus (automatikusan generálódik) |

A rendszer a megadott értékekből inline `style` attribútumot vagy sorszámozott CSS osztályt generál.

### 7.2 Background (Háttér)
Ha `"background": true` van beállítva, a blokk kap egy háttér-beállító panelt:

| Attribútum | Típus | Leírás |
|---|---|---|
| `bgColor` | `string` | Háttérszín (pl. `#ff0000`) |
| `bgGradient` | `string` | Színátmenet érték |
| `bgImage` | `string` | Háttérkép teljes méretű URL |
| `bgImageThumb` | `string` | Háttérkép előnézeti URL |
| `bgImageSize` | `string` | `cover` / `contain` / `auto` |
| `bgImagePosition` | `string` | Pozíció (pl. `center center`, `top left`) |
| `style` | `string` | Inline CSS stílus |

### 7.3 Link
Ha `"link": true` van beállítva, a blokk linkelhető lesz:

| Attribútum | Típus | Leírás |
|---|---|---|
| `linkUrl` | `string` | Cél URL |
| `linkNewTab` | `boolean` | Új ablakban nyisson-e (`target="_blank"`) |

---

## 8. Egyedi Blokkok — Struktúra és Elrendezés

### 8.1 Section (`iu/section`)
Az oldal legkülső szerkezeti egysége. Minden tartalom szekciókba van szervezve.

- **HTML kimenet**: `<section class="iu-section ...">`
- **Szülő**: Nincs (legfelső szintű blokk)
- **Gyerekek**: Kizárólag `iu/row` blokkokat fogad
- **Támogatás**: `background`, `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `lightText` | `boolean` | `false` | Világos szöveg mód (sötét háttérhez). Hozzáadja az `.iu-section-light` osztályt, ami fehérre állítja a szöveget és a címsorokat. |
| `fullWidth` | `boolean` | `false` | Teljes szélességű szekció. Hozzáadja az `.iu-section-fullwidth` osztályt. |
| `templateLock` | `string` | `""` | A belső blokkok zárolásának módja (pl. `"all"`, `"insert"`) |

**CSS osztályok**: `.iu-section`, `.iu-section-light`, `.iu-section-fullwidth`

---

### 8.2 Row (`iu/row`)
A szekción belüli sor, amely oszlopokra bontja a tartalmat.

- **HTML kimenet**: `<div class="iu-row ...">`
- **Szülő**: Kizárólag `iu/section`
- **Gyerekek**: Kizárólag `iu/column` blokkokat fogad
- **Támogatás**: `background`, `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `columns` | `string` | `"1-1"` | Az oszlopszerkezet definíciója. A `\|` karakterrel elválasztott törtszámokból áll, ahol a tört a szélességi arányt jelöli. |
| `vertical` | `string` | `""` | Függőleges igazítás: `""` (top), `"center"`, `"flex-end"` (bottom) |

**Elérhető oszlopszerkezetek:**

| Beállítás | Érték |
|---|---|
| 100% | `1-1` |
| 50% — 50% | `1-2\|1-2` |
| 33% — 33% — 33% | `1-3\|1-3\|1-3` |
| 66% — 33% | `2-3\|1-3` |
| 33% — 66% | `1-3\|2-3` |
| 75% — 25% | `3-4\|1-4` |
| 25% — 75% | `1-4\|3-4` |
| 25% — 25% — 25% — 25% | `1-4\|1-4\|1-4\|1-4` |

**Intelligens oszlopkezelés**: A row blokk JS kódja automatikusan kezeli az oszlopok létrehozását, törlését és átmozgatását, ha megváltozik az oszlopszerkezet. Ha csökkentjük az oszlopszámot, a felesleges oszlopok tartalma automatikusan átkerül az utolsó oszlopba.

---

### 8.3 Column (`iu/column`)
Az egyedi oszlop a soron belül.

- **HTML kimenet**: `<div class="iu-column iu-column-{width} ...">`
- **Szülő**: Kizárólag `iu/row`
- **Gyerekek**: Bármilyen engedélyezett blokk
- **Támogatás**: `background`, `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `width` | `string` | `"1-1"` | Oszlop szélessége (automatikusan a szülő Row `columns` attribútuma alapján töltődik ki) |
| `templateLock` | `string` | `""` | Belső blokkok zárolása |
| `align` | `string` | `""` | Tartalom igazítása: `""`, `"center"`, `"right"` |

**CSS osztályok**: `.iu-column`, `.iu-column-1-1`, `.iu-column-1-2`, `.iu-column-1-3`, `.iu-column-2-3`, `.iu-column-1-4`, `.iu-column-3-4`

---

### 8.4 Group (`iu/group`)
Flexbox-alapú csoportosító konténer, amely bármely blokkok halmazát képes egy logikai egységbe foglalni.

- **HTML kimenet**: `<div class="iu-group iu-group-{direction} ...">`
- **Szülő**: Bármi
- **Gyerekek**: Bármilyen engedélyezett blokk
- **Támogatás**: `background`, `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `direction` | `string` | `""` | Flexbox irány: `""` (alapértelmezett row), `"column"`, `"row-reverse"`, `"column-reverse"` |
| `equal` | `boolean` | `false` | Egyenlő szélességű gyermekek (`flex: 1`) |
| `horizontal` | `string` | `""` | Vízszintes igazítás: `""`, `"center"`, `"flex-end"`, `"space-between"`, `"space-around"` |
| `vertical` | `string` | `""` | Függőleges igazítás: `""`, `"center"`, `"flex-end"`, `"flex-start"` |

**CSS osztályok**: `.iu-group`, `.iu-group-column`, `.iu-group-horizontal-center`, `.iu-group-horizontal-space-between`, `.iu-group-vertical-center`, `.iu-group-vertical-flex-end`, stb.

---

## 9. Egyedi Blokkok — Tartalom és Szöveg

### 9.1 Title (`iu/title`)
Dinamikus oldalcím-megjelenítő. Automatikusan lekéri és kiírja az aktuális bejegyzés címét vagy a kontextusnak megfelelő szöveget.

- **Renderelés**: Szerver-oldali (`render.php`)
- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `heading` | `string` | `"h2"` | HTML heading szint: `h1`–`h6` |
| `align` | `string` | `"left"` | Szövegigazítás: `"left"`, `"center"`, `"right"` |

**Dinamikus tartalom kontextus szerint:**

| Kontextus | Kimenet |
|---|---|
| Egyedi oldal/bejegyzés | Az oldal/bejegyzés címe |
| Blog/Hír lista (`is_home()`) | A blogoldal címe |
| Keresés (`is_search()`) | `Keresés erre: {keresőszó}` |
| Címke (`is_tag()`) | `Címke: {címke neve}` |
| Kategória (`is_category()`, `is_tax()`) | `Kategória: {kategória neve}` |

**HTML kimenet**: `<h2 class="iu-title iu-heading iu-text-align-center">Cím szöveg</h2>`

---

### 9.2 Content (`iu/content`)
Sablonhelyőrző blokk. Nem látható a frontend-en — a rendereléskor az aktuális bejegyzés Gutenberg-tartalmát szúrja be a helyére.

- **Attribútumok**: Nincsenek
- **Csak szerkesztő fájljai vannak** (`block.js`, `editor.css`), frontend stílust nem tölt be
- A `Blocks` osztály `__construct()` metódusában az `render_block_iu/content` filter kezeli: `apply_filters('the_content', $post->post_content)`

---

### 9.3 Card (`iu/card`)
Előre definiált struktúrájú kártya elem képpel, címsorral, szöveggel és opcionális gombbal.

- **Támogatás**: `link`, `background`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `imageID` | `number` | `null` | Média könyvtár kép azonosítója |
| `imageURL` | `string` | `""` | Kép teljes méretű URL-je |
| `imageThumbURL` | `string` | `""` | Kép előnézeti URL-je |
| `heading` | `string` | `"Enter a title..."` | Kártya címsora |
| `text` | `string` | `"Enter the content..."` | Kártya szövege |
| `button` | `string` | `""` | Gomb felirata (ha üres, nem jelenik meg) |

---

## 10. Egyedi Blokkok — Média

### 10.1 Image (`iu/image`)
Optimalizált képblokk méret- és vágás-beállításokkal.

- **Támogatás**: `link`, `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `imageID` | `number` | `null` | Média könyvtár kép azonosítója |
| `imageURL` | `string` | `""` | Kép teljes URL-je |
| `imageThumbURL` | `string` | `""` | Kép előnézeti URL-je |
| `crop` | `boolean` | `false` | Vágás mód. Ha igaz, a kép a megadott szélességre és magasságra vágódik CSS `object-fit: cover`-rel |
| `width` | `string` | `""` | Kép szélessége (pl. `"300px"`, `"50%"`) |
| `height` | `string` | `""` | Kép magassága |
| `align` | `string` | `""` | Igazítás: `""` (bal), `"center"`, `"right"` |

**CSS osztályok**: `.iu-image`, `.iu-image-wrap`, `.iu-image-crop`, `.iu-image-justify-center`, `.iu-image-justify-flex-end`

---

### 10.2 Featured Image (`iu/featured-image`)
Az aktuális bejegyzés kiemelt képét jeleníti meg. Szerver-oldalon renderelődik.

- **Renderelés**: `render.php`
- **Támogatás**: `link`, `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `crop` | `boolean` | `false` | Vágás mód |
| `width` | `string` | `""` | Szélesség |
| `height` | `string` | `""` | Magasság |
| `align` | `string` | `""` | Igazítás |

Működése megegyezik az Image blokkal, de automatikusan a `get_post_thumbnail_id()` alapján tölti ki a képet.

---

### 10.3 Video (`iu/video`)
YouTube videó beágyazására alkalmas blokk.

- **Renderelés**: `render.php` — kiolvassa a YouTube URL `v` paraméterét és `<iframe>` embed-et generál
- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `url` | `string` | `""` | YouTube videó URL (pl. `https://youtube.com/watch?v=XXXXX`). Támogatja a shortcode-okat az URL-ben. |

**HTML kimenet**: `<div class="iu-video"><iframe src="https://youtube.com/embed/XXXXX"></iframe></div>`

> [!NOTE]
> A videó nem renderelődik a szerkesztőben és a sablon szerkesztőben, csak a publikus frontend-en.

---

### 10.4 Icon Group (`iu/icon-group`) és Icon (`iu/icon`)
Ikonok megjelenítése a WordPress Dashicons készletéből.

**Icon Group** — az ikonok konténere:
- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `align` | `string` | `"flex-start"` | Igazítás: `"flex-start"`, `"center"`, `"flex-end"` |

**Icon** — egyedi ikon:
- **Szülő**: Kizárólag `iu/icon-group`
- **Támogatás**: `link`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `icon` | `string` | `"email"` | Dashicon neve (a `dashicons-` prefix nélkül) |
| `type` | `string` | `"default"` | Stílus típus: `"default"`, `"highlight"`, `"inverted"` |

**CSS osztályok**: `.iu-icon`, `.iu-icon-default`, `.iu-icon-highlight`, `.iu-icon-inverted`

---

## 11. Egyedi Blokkok — Csúszkák és Animációk

Minden csúszka blokk a **Slick Slider** jQuery könyvtárat használja, amely a `inc/js/slick.min.js` fájlban található. A csúszka paraméterei JSON formátumban adhatók meg a `params` attribútumban.

### 11.1 Image Carousel (`iu/image-carousel`)
Horizontális képgaléria csúszka (több kép egyszerre látható).

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `images` | `array` | `[]` | Képek tömbje a Média Könyvtárból |
| `params` | `string` | `"slidesToShow": 3, "slidesToScroll": 3, "infinite": false` | Slick Slider paraméterek JSON formátumban |

---

### 11.2 Image Slider (`iu/image-slider`)
Teljes szélességű képcsúszka (egyszerre 1 kép, lapozás/fade animáció).

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `slides` | `array` | `[]` | Dia-képek tömbje |
| `params` | `string` | `"arrows": true, "dots": true, "fade": true` | Slick Slider paraméterek |

---

### 11.3 Slider (`iu/slider`) és Slide (`iu/slide`)
Általános célú blokk-csúszka, ahol minden dia tetszőleges blokkokat tartalmazhat.

**Slider** — a csúszka konténere:

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `params` | `string` | `"autoplay": true, "autoplaySpeed": 3000` | Slick Slider paraméterek |

**Slide** — egyedi dia:
- **Szülő**: `iu/slider` vagy `iu/content-slider`
- **Gyerekek**: Bármilyen blokk
- **Saját háttér attribútumai vannak**: `bgColor`, `bgGradient`, `bgImage`, `bgImageThumb`, `bgImageSize`, `bgImagePosition`

---

### 11.4 Content Slider (`iu/content-slider`)
Tartalom-csúszka, ahol az egyes diák (`iu/slide`) más-más blokkokat tartalmazhatnak, és egyszerre több is látható lehet.

- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `params` | `string` | `"slidesToShow": 3, "slidesToScroll": 1, "infinite": false, "autoplay": true, "autoplaySpeed": 3000, "fade": false, "arrows": true, "dots": false` | Slick Slider paraméterek |

---

### 11.5 HTML Slider (`iu/html-slider`)
HTML-alapú csúszka, ahol az egyes diák szöveges HTML kódot tartalmaznak.

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `slides` | `array` | `[]` | Diák HTML tartalmának tömbje |
| `params` | `string` | `"slidesToShow": 3, "slidesToScroll": 1, "infinite": true, "autoplay": true` | Slick Slider paraméterek |

---

### 11.6 Logo Slider (`iu/logo-slider`)
Partnerlogók vagy márkák automatikus, végtelen futású csúszkája.

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `images` | `array` | `[]` | Logó képek tömbje a Média Könyvtárból |

> [!NOTE]
> A mappa neve `logo-sider` (elgépelés), de a blokk neve helyesen `iu/logo-slider`.

---

### 11.7 Text Scroller (`iu/text-scroller`)
Végtelen futású vízszintes szöveg-csúszka (marquee jellegű animáció CSS-sel).

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `texts` | `string` | `""` | A megjelenítendő szöveg |

---

## 12. Egyedi Blokkok — Navigáció és Keresés

### 12.1 Menu (`iu/menu`)
WordPress menü megjelenítése a `wp_nav_menu()` függvénnyel. Szerver-oldalon renderelődik.

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `menu` | `number` | `0` | A WP menü azonosítója (Term ID) |
| `vertical` | `boolean` | `false` | Függőleges elrendezés (hozzáadja a `.vertical` osztályt) |
| `collapse` | `boolean` | `true` | Mobilon hamburger menüvé összecsukódik (`.mobile-toggle` gomb és `.iu-menu-close` bezáró gomb generálódik) |

**HTML struktúra**:
```html
<div class="iu-menu-block-wrapper">
    <span class="mobile-toggle"></span>
    <div class="iu-menu-container">
        <span class="mobile-toggle iu-menu-close"></span>
        <ul class="iu-menu-block">...</ul>
    </div>
</div>
```

---

### 12.2 Breadcrumbs (`iu/breadcrumbs`)
Dinamikus morzsa-navigáció Schema.org BreadcrumbList jelöléssel. Szerver-oldalon renderelődik.

- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `separator` | `string` | `"/"` | Az elválasztó karakter/szöveg az elemek között |
| `align` | `string` | `""` | Igazítás: `""` (bal), `"center"`, `"right"` |

**Támogatott oldaltípusok**: egyedi bejegyzés (post), oldal (page, szülő-oldalakkal), termék (product, WooCommerce kategória-hierarchiával), termékkategória, blog főoldal, keresés, kategória, címke, CPT archívum, egyedi poszt típus.

---

### 12.3 Post Navigation (`iu/post-navigation`)
Bejegyzések közötti navigáció (előző/következő) egy adott lekérdezés kontextusában. Szerver-oldalon renderelődik.

- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `params` | `string` | `""` | WP_Query paraméterek JSON formátumban (pl. `"post_type": "post", "orderby": "date"`) — ez határozza meg, mely bejegyzések között navigálunk |
| `previous` | `string` | `""` | Előző gomb egyedi szövege (ha üres, az előző bejegyzés címe jelenik meg csonkítva 36 karakterre) |
| `next` | `string` | `""` | Következő gomb egyedi szövege |

---

### 12.4 Search (`iu/search`)
Keresőmező blokk. Szerver-oldalon renderelődik.

- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `placeholder` | `string` | `"Keresés..."` | A beviteli mező placeholder szövege |

**HTML kimenet**: Egy `<form>` elem szöveges input mezővel és nagyító ikon gombbal, ami a `home_url()`-re `GET` kérést küld `s` paraméterrel.

---

### 12.5 Back to Top (`iu/backtotop`)
„Vissza a tetejére" gomb, amely kattintásra sima görgetéssel a lap tetejére navigál.

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `text` | `string` | `"Back to Top"` | A gomb felirata |

**HTML kimenet**: `<a class="iu-backtotop" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">Szöveg</a>`

---

## 13. Egyedi Blokkok — Adatmegjelenítés

### 13.1 Query (`iu/query`)
Dinamikus tartalomlista blokk, amely WP_Query lekérdezés alapján HTML sablonnal jeleníti meg a bejegyzéseket. Szerver-oldalon renderelődik.

- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `main_query` | `boolean` | `true` | Ha igaz, az aktuális oldal fő WordPress lekérdezését használja (pl. blog lista, keresés). Ha hamis, egyedi WP_Query-t futtat a `params` alapján. |
| `params` | `string` | `"post_type": "page", "posts_per_page": -1, "orderby": "post_title"` | WP_Query paraméterek JSON formátumban (kapcsos zárójelek nélkül) |
| `template` | `string` | Lásd alább | HTML sablon, amelyben shortcode-ok használhatók a bejegyzés adatainak megjelenítéséhez |
| `columns` | `string` | `"3"` | Oszlopszám a grid elrendezésben (CSS `.iu-query-col-{n}`) |
| `paging` | `boolean` | `false` | Lapozás engedélyezése. Ha igaz, a lekérdezés a `paged` query variable alapján lapoz, és a lista alatt `paginate_links()` jelenik meg. |

**Template példa** (alapértelmezett):
```html
<div class="iu-query-item">
    <h3 class="iu-heading">[iu_post_title]</h3>
    <p class="iu-text">[iu_post_excerpt]</p>
    <p class="iu-text">
        <a href="[iu_post_link]">Megnézem</a>
    </p>
</div>
```

A sablonban az összes `[iu_...]` shortcode használható (lásd [17. fejezet](#17-shortcode-ok)).

---

### 13.2 Terms (`iu/terms`)
Taxonómia kifejezések (kategóriák, címkék) listázása szűrő-linkekként vagy gombokként. Szerver-oldalon renderelődik.

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `taxonomy` | `string` | `"category"` | A megjelenítendő taxonómia slug-ja |
| `all_text` | `string` | `"Összes"` | Az „összes" szűrő felirata (ha üres, nem jelenik meg) |
| `post_counts` | `boolean` | `false` | Bejegyzésszám megjelenítése zárójelben |
| `buttons` | `boolean` | `false` | Ha igaz, gombok stílusban jelenik meg (`iu-button-group`); ha hamis, egyszerű linkekként |

Az aktív/kiválasztott kategória `.iu-terms-active` vagy `.iu-button-disabled` osztályt kap.

---

### 13.3 Term Cloud (`iu/term-cloud`)
Tag cloud (címkefelhő), ahol a kifejezések betűmérete a bejegyzésszámhoz igazodik. Szerver-oldalon renderelődik.

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `taxonomy` | `string` | `"post_tag"` | A megjelenítendő taxonómia slug-ja |

A betűméret 1em–3em között skálázódik a bejegyzések száma alapján.

---

## 14. Egyedi Blokkok — Interaktív elemek

### 14.1 Accordion (`iu/accordion`) és Accordion Item (`iu/accordion-item`)
Harmonika (lenyíló) elem, amelyet például GYIK szekciókhoz vagy árlistákhoz lehet használni. jQuery-alapú animációval nyílik/csukódik.

**Accordion** — konténer:
- **Támogatás**: `spacing`
- **Attribútumok**: Nincsenek saját attribútumai
- A `viewScript` mezőben betölt egy `script.js` fájlt jQuery-vel

**Accordion Item** — egyedi elem:
- **Szülő**: Kizárólag `iu/accordion`
- **Gyerekek**: Bármilyen blokk (a lenyíló részben)

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `title` | `string` | `""` | A fejléc szövege (a kattintható sáv) |

**CSS osztályok**: `.iu-accordion-item-head` (kattintható fejléc), `.iu-accordion-item-body` (a lenyíló tartalom)

---

### 14.2 Tabs (`iu/tabs`) és Tab (`iu/tab`)
Füles (tabbed) tartalommegjelenítés. jQuery-alapú.

**Tabs** — konténer:
- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `tabs` | `array` | `[]` | A tabulátorok listája |

**Tab** — egyedi fül:
- **Szülő**: Kizárólag `iu/tabs`
- **Gyerekek**: Bármilyen blokk

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `title` | `string` | `""` | A fül fejlécének szövege |
| `imageID` | `number` | `null` | Opcionális kép a fülhöz |
| `imageURL` | `string` | `""` | Kép URL |
| `imageThumbURL` | `string` | `""` | Kép előnézeti URL |

---

### 14.3 Modal (`iu/modal`)
Felugró ablak (modális dialógus). jQuery-alapú.

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `id` | `string` | `""` | A modál egyedi azonosítója. Erre az ID-re kell hivatkozni a nyitó elem `data-modal` attribútumában (vagy linkjében `#modal-id`). |

**Gyerekek**: Bármilyen blokk (ez lesz a modális ablak tartalma).

---

### 14.4 Button Group (`iu/button-group`) és Button (`iu/button`)
Gombok csoportosítására és megjelenítésére szolgáló blokkok.

**Button Group** — konténer:
- **Támogatás**: `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `align` | `string` | `""` | Gombok igazítása |

**Button** — egyedi gomb:
- **Szülő**: Kizárólag `iu/button-group`
- **Támogatás**: `link`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `text` | `string` | `"Click me!"` | Gomb felirata |
| `type` | `string` | `"default"` | Gomb stílusa: `"default"`, `"outline"`, `"link"` |

**CSS osztályok**: `.iu-button`, `.iu-button-default`, `.iu-button-outline`, `.iu-button-link`

---

## 15. Egyedi Blokkok — Rendszer és Belső

### 15.1 Template (`iu/template`)
Belső rendszerblokk, amely a sablon-szerkesztőben a bejegyzés tartalmát csomagolja be. **Nem a felhasználó számára készült**, a rendszer automatikusan használja.

- **Gyerekek**: Bármilyen blokk (a bejegyzés tényleges tartalma)
- **Attribútumok**: Nincsenek
- `renaming: false`, `html: false` — nem nevezhető át és nem szerkeszthető HTML-ként

**Működés**: Ha létezik egy `single_{post_type}` sablon, a szerkesztőben a bejegyzés tartalma automatikusan egy `<!-- wp:iu/template -->` blokkba kerül. Mentéskor a rendszer kibontja a tényleges tartalmat és azt menti el.

---

### 15.2 Pattern (`iu/pattern`)
Synced pattern (szinkronizált minta) megjelenítő blokk. A Pattern rendszer saját implementációja.

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `ref` | `number` | `null` | Az `iu_pattern` CPT bejegyzés azonosítója (post ID) |

**Renderelés** (`render.php`): Lekéri a hivatkozott bejegyzést (`get_post($ref)`) és `apply_filters('the_content', ...)` szűrővel rendereli a tartalmát.

---

### 15.3 Test (`iu/test`)
Fejlesztői célú teszt blokk. Nem rendelkezik frontend renderelővel.

| Attribútum | Típus | Alapérték |
|---|---|---|
| `slides` | `array` | `[]` |
| `params` | `string` | `""` |

---

## 16. Űrlap Blokkok (Form Blocks)

A téma saját, beépített űrlapkezelővel rendelkezik — **nem igényel külső bővítményt** (mint Contact Form 7 vagy Gravity Forms).

### 16.1 Form (`iu/form`)
Az űrlap fő konténere. Tartalmazza a küldési logikát és az integráció beállításait.

- **Támogatás**: `background`, `spacing`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `formId` | `string` | `""` | **Egyedi űrlap-azonosító** — ez alapján azonosítja a rendszer a beérkező adatokat |
| `buttonLabel` | `string` | `"Submit"` | Beküldő gomb felirata |
| `email` | `string` | `""` | Címzett e-mail cím, ahova az értesítés érkezik |
| `subject` | `string` | `""` | Az e-mail tárgya |
| `message` | `string` | `""` | Az e-mail üzenet sablonja |
| `from` | `string` | `""` | Feladó e-mail cím |
| `success` | `string` | `""` | Sikeres küldés esetén megjelenő üzenet |
| `ac_url` | `string` | `""` | ActiveCampaign API URL |
| `ac_key` | `string` | `""` | ActiveCampaign API kulcs |
| `ac_listid` | `string` | `""` | ActiveCampaign lista azonosító |

**Hook-ok az integrációhoz:**
- `iu_form_submit_{form_id}` — filter a válasz módosítására
- `iu_form_ac_data_{form_id}` — filter az AC-nak küldött adatok módosítására

---

### 16.2 Text Input (`iu/form-text`)
Egysoros szöveges beviteli mező.
- **Szülő**: `iu/form`, `iu/group`, `iu/form-step`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `name` | `string` | `""` | A mező neve (a `$_POST` kulcsa és az ActiveCampaign mezőnév) |
| `label` | `string` | `""` | Felirat a mező felett |
| `placeholder` | `string` | `""` | Placeholder szöveg |
| `validate` | `string` | `""` | Validációs szabályok, soronként egy. Formátum: `szabály\|hibaüzenet`. Pl. `required\|Ez a mező kötelező` |

---

### 16.3 Textarea Input (`iu/form-textarea`)
Többsoros szöveges beviteli mező.
- **Szülő**: `iu/form`, `iu/group`
- **Attribútumok**: Megegyeznek a Text Input-tal (`name`, `label`, `placeholder`, `validate`)

---

### 16.4 Select Input (`iu/form-select`)
Legördülő választómező.
- **Szülő**: `iu/form`, `iu/group`, `iu/form-step`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `name` | `string` | `""` | Mező neve |
| `label` | `string` | `""` | Felirat |
| `options` | `string` | `""` | Választási lehetőségek (soronként egy érték) |
| `default` | `string` | `""` | Alapértelmezett kiválasztott érték |
| `validate` | `string` | `""` | Validáció |

---

### 16.5 Radios (`iu/form-radios`)
Rádiógombok csoportja.
- **Szülő**: `iu/form`, `iu/group`, `iu/form-step`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `name` | `string` | `""` | Mező neve |
| `label` | `string` | `""` | Felirat |
| `options` | `string` | `""` | Választási lehetőségek (soronként egy) |
| `validate` | `string` | `""` | Validáció |

---

### 16.6 Accept Input (`iu/form-accept`)
Adatvédelmi/elfogadási checkbox.
- **Szülő**: `iu/form`, `iu/group`, `iu/form-step`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `name` | `string` | `""` | Mező neve |
| `label` | `string` | `""` | A checkbox mellett megjelenő szöveg (elfogadási nyilatkozat, HTML támogatott) |
| `error` | `string` | `""` | Hibaüzenet, ha nincs bejelölve |

---

### 16.7 Steps (`iu/form-steps`) és Step (`iu/form-step`)
Többlépcsős űrlap építésére szolgáló blokkok.

**Steps** — a lépéseket összefogó konténer:
- **Szülő**: `iu/form`

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `steps` | `string` | `""` | A lépések konfigurációja |

**Step** — egyedi lépés:
- **Szülő**: `iu/form`
- **Gyerekek**: Űrlap-mezők (`iu/form-text`, `iu/form-select`, stb.)

| Attribútum | Típus | Alapérték | Leírás |
|---|---|---|---|
| `label` | `string` | `""` | A lépés címe/felirata |
| `prev` | `string` | `"Előző"` | Visszafelé navigáló gomb szövege |
| `next` | `string` | `"Következő"` | Előre navigáló gomb szövege |

---

## 17. Shortcode-ok

A téma beépített shortcode-okat biztosít, amelyeket elsősorban a `iu/query` blokk sablonjaiban és a sablonfájlokban lehet használni:

| Shortcode | Leírás | Paraméterek |
|---|---|---|
| `[iu_site_url]` | A weboldal alap URL-je (záró `/`-vel) | — |
| `[iu_year]` | Az aktuális év (pl. `2026`) | — |
| `[iu_post_title]` | Az aktuális bejegyzés címe | — |
| `[iu_post_excerpt]` | Az aktuális bejegyzés kivonata | `words` (alapérték: `21`) |
| `[iu_post_link]` | Az aktuális bejegyzés permalink-je | — |
| `[iu_post_thumbnail]` | Az aktuális bejegyzés kiemelt képe `<img>` tagként | `size` (alapérték: `"post-thumbnail"`) |
| `[iu_post_featured_image_link]` | Az aktuális bejegyzés kiemelt kép URL-je | `size` (alapérték: `"full"`) |
| `[iu_post_date]` | Az aktuális bejegyzés dátuma | `format` (alapérték: a WP dátum+idő formátum) |
| `[iu_post_category]` | Az aktuális bejegyzés első kategóriája linkként | — |

---

## 18. Pattern-ek (Minták)

A téma a WordPress beépített pattern rendszere helyett saját implementációt használ:

- **CPT**: `iu_pattern` — elérhető a Megjelenés → Pattern-ek menüpont alatt
- **Típusok**: Synced (szinkronizált — minden helyen frissül) és Unsynced (nem szinkronizált — beillesztéskor másolat készül)
- **Használat**: A szerkesztőben az `iu/pattern` blokk variációjaként jelenik meg, ahol kiválasztható a kívánt minta
- **Renderelés**: A synced pattern tartalma az `iu/pattern` blokk `render.php` fájlján keresztül `the_content` filterrel renderelődik

---

## 19. Engedélyezett Core Blokkok

A téma szándékosan szűk körre korlátozza az engedélyezett WordPress Core blokkokat:

| Blokk | Leírás |
|---|---|
| `core/paragraph` | Bekezdés — kap extra attribútumokat: `small` (kisebb betűméret), `muted` (halványabb szín) és `spacing` támogatást |
| `core/heading` | Címsor — kap `spacing` támogatást. A rendereléskor a `wp-block-heading` osztály eltávolításra kerül. |
| `core/list` | Lista — a `wp-block-list` osztály eltávolításra kerül |
| `core/list-item` | Lista elem |
| `core/html` | Egyedi HTML kód |
| `core/shortcode` | Shortcode blokk |

Minden más Core blokk (kép, gomb, csoport, oszlopok, galéria stb.) **le van tiltva** és nem jelenik meg a blokk-beszúróban.

---

## 20. Vendor könyvtárak

### ActiveCampaign API kliens
Elérési út: `vendor/activecampaigne/ActiveCampaign.class.php`

Az űrlap-küldésnél használt PHP osztály, amely az ActiveCampaign API-val kommunikál kontaktok szinkronizálásához és listákhoz adásához. Kizárólag az `iu/form` blokk `ac_url`, `ac_key` és `ac_listid` attribútumainak megadása esetén aktiválódik.

### Slick Slider
Elérési út: `inc/js/slick.min.js`

jQuery-alapú karusszel/slider könyvtár. Az összes csúszka-blokk (`iu/image-carousel`, `iu/image-slider`, `iu/slider`, `iu/content-slider`, `iu/html-slider`, `iu/logo-slider`) ezt használja. A `viewScript` mezőben van hivatkozva, így csak akkor töltődik be, ha az adott blokk szerepel az oldalon.
