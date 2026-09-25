---
name: iu-theme
description: Az Infinite Unity (iu_theme) egyedi WordPress blokk-keretrendszer és a hozzá tartozó iu_* mu-pluginek (iu_woocommerce, iu_custom_blocks, iu_settings) szabályai, blokk-referenciája és bevált munkafolyamata. Használd MINDEN WordPress weboldal vagy WooCommerce webshop építésénél és módosításánál — oldal, szekció vagy sablon összerakása, blokk-markup (<!-- wp:iu/... -->) írása, child téma testreszabása, termékoldal, kategória, kosár, pénztár, űrlap, csúszka, menü, lista készítése, új blokk fejlesztése (iucb_add_block) —, és mindig, ha iu_theme, iu/section, iu/row, iu_template, iu_pattern vagy iu-woocommerce szóba kerül. Triggers: WordPress site, WooCommerce shop, WP theme, Gutenberg block markup, page template, child theme.
---

# Infinite Unity (iu_theme): WordPress fejlesztési szabályok

A csapat minden WordPress weboldalt az `iu_theme` keretrendszerre épít. Ez nem Full Site Editing téma: saját sablonmotorja (`iu_template` CPT és HTML fájlok), saját `iu/` blokkjai, saját minta-rendszere (`iu_pattern`) és a `mu-plugins/iu_*` kiegészítői vannak. A gyári blokkok nagy része le van tiltva.

**Teljes referencia:** `references/iu_theme_documentation.md`. Mielőtt egy blokkot használsz, attribútumot írsz vagy a téma PHP-jához nyúlsz, olvasd el a releváns fejezetet (lista a fájl végén). A doksi nem minden verzióval egyezik: kétség esetén a projektben lévő `block.json` / `block.js` / `render.php` a mérvadó.

**Mintaprojekt:** a `vasskisgep` repó (WooCommerce webshop) a lent leírt munkafolyamat teljes, működő példája: child téma blokkokkal, sablonfájlokkal, telepítő lépésekkel és a `dev/` ellenőrző eszközökkel.

## Alapszabályok

1. **Csak `iu/` (és `iu-woocommerce/`) blokkok, plusz a hat engedélyezett core blokk** (`core/paragraph`, `core/heading`, `core/list`, `core/list-item`, `core/html`, `core/shortcode`). Minden más core blokk tiltva van:

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
   | `core/search` | `iu/search` (bejegyzések), `iu-woocommerce/search` (termékek) |
   | `core/details` | `iu/accordion` > `iu/accordion-item` |
   | `core/spacer`, `core/separator` | a blokk spacing attribútumai |
   | `core/block`, `core/pattern` | `iu/pattern` (`iu_pattern` CPT) |
   | `core/template-part` | `iu_template` / `templates/*.html` |
   | WooCommerce blokkok (`woocommerce/*`) | `iu-woocommerce/*` blokkok (a WC blokkjait az iu_woocommerce kikapcsolja) |

2. **Nincs page builder, űrlap-, slider- vagy wishlist-plugin.** Hiányzó funkciót saját blokkal (`iucb_add_block`) és a child téma PHP-jával kell megoldani.
3. **A keretrendszerhez (`themes/iu_theme`, `mu-plugins/iu_*`) nem nyúlunk egy projekt kedvéért.** Minden projekt-specifikus kód a child témába kerül. A child téma külön WordPress téma (`style.css`: `Template: iu_theme`), pl. `themes/vasskisgep`. Keretrendszer-hibát a child témában kerülünk meg (hook, szűrő), és a projekt README-jében dokumentáljuk.
4. **A design CSS változókkal és a `theme.json` palettával állítható.** A változókat a child téma `vars.css`/`style.css` fájljában, `:root { … }` alatt írd felül. A Gutenberg vizuális beállításai szándékosan ki vannak kapcsolva.
5. **Ne kapcsold vissza, amit a `clean.php` letilt:** hozzászólások, emoji, RSS, oEmbed, fájlszerkesztő.
6. **Nincs beégetett évszám és site URL a sablonfájlokban:** `[iu_year]`, `[iu_site_url]` (a `[iu_site_url]` záró perjellel tér vissza, és HTML attribútumban is működik, pl. `href="[iu_site_url]kapcsolat/"`).
7. **Az alapértelmezett felületi szövegek magyarok.** Más nyelvű weboldalnál az attribútumokban mindig állítsd át őket.

## Oldalszerkezet: kötelező hierarchia

```
iu/section          legfelső szint; csak iu/row lehet benne
└─ iu/row           columns="1-2|1-2" stb.; csak iu/column lehet benne
   └─ iu/column     width = a row columns-ából
      └─ tartalom   core/heading, core/paragraph, iu/image, iu/group, iu/button-group, iu/card, iu/form, iu/query …
```

- Tartalmi blokk soha nem kerülhet közvetlenül `iu/section`-be vagy `iu/row`-ba. Egyoszlopos tartalomhoz is kell egy `row` és egy `column`.
- Oszlopszerkezetek: `1-1`, `1-2|1-2`, `1-3|1-3|1-3`, `2-3|1-3`, `1-3|2-3`, `3-4|1-4`, `1-4|3-4`, `1-4|1-4|1-4|1-4`.
- Oszlopon belüli elrendezés: `iu/group` (`direction`, `horizontal`, `vertical`, `equal`).
- Sötét hátterű szekció: `bgColor` + `"lightText": true`. **A `bgColor` a paletta slugja** (pl. `"dark"`, `"light"`, `"primary"`), nem hex kód.
- Egy oldalon egy `h1` legyen.

## Sablonrendszer

- Három zóna: `header`, `content`, `footer`. Feloldási sorrend típusonként (`single_product`, `tax_product_cat`, …, végül `global`):
  1. admin `iu_template` bejegyzés (Megjelenés → Template-ek) a pontos type/position párra
  2. **child téma:** `{child}/templates/{type}_{position}.html` (`get_stylesheet_directory()`)
  3. `iu_theme/templates/{type}_{position}.html`
- Típusok: `front_page` (utána `single_page`), `blog_page`, `404`, `search`, `single_{post_type}`, `archive_{post_type}`, `tax_category`, `tax_post_tag`, `tax_{taxonomy}`.
- Új sablon lehetőleg **fájl a child téma `templates/` mappájában**, mert verziókövetett, és nem kell hozzá adatbázis-módosítás. Az admin sablon felülírja a fájlt: ha egy fájl-módosítás nem látszik, először az admin sablonokat nézd meg.
- A sablon nem futtatja a WP loopot. Ami a `global $post`/`$product`-ra támaszkodik, annak neked kell beállítanod (pl. `wp` hookon: `$GLOBALS['product'] = wc_get_product(get_queried_object_id())` a termékoldalon).
- **Dupla H1:** a `global_content.html` egy `iu/title` (h1) blokkot tesz a tartalom elé. Saját hero/H1 esetén `{child}/templates/single_page_content.html`, benne `<!-- wp:iu/content /-->`.

## Blokk-markup írása

- Gutenberg komment-szintaxis, az attribútumok JSON-ben.
- **Statikus blokkoknál** (section, row, column, group, button, card, image, icon-text, form mezők …) a komment közti HTML-nek pontosan egyeznie kell a `block.js` `save()` kimenetével, különben a szerkesztő „váratlan tartalom” hibát ad. A link (`linkUrl`) is a mentett HTML-ben van (`href`), nem elég az attribútumot átírni.
- **`iu_style`:** a spacing/background támogatású blokkok számított CSS-e (pl. `"iu_style":"padding-top:3rem"`). A frontend ebből dolgozik; ha csak a `paddingTop` attribútumot írod, nem lesz térköz.
- **Ne írj kézzel végleges markupot.** Bevált munkafolyamat (a `vasskisgep/dev/` eszközei):
  1. Generátor (Python) írja a vázlatot: pontos attribútumok, egyszerűsített HTML.
  2. A vázlat egy piszkozat `iu_template` bejegyzésbe kerül, a Playwright megnyitja a valódi blokkszerkesztőben, a hibás blokkokat `wp.blocks.createBlock(név, attribútumok, belső blokkok)`-kal újraépíti, kivárja az `iu_style` számítását, elmenti.
  3. A mentett, kanonikus markup visszaíródik a fájlba. Második futásra minden blokknak hibátlannak kell lennie.
- A tartalom mentésekor WP-CLI alól (nincs bejelentkezett felhasználó) a kses és a WP 7 `wp_strip_custom_css_from_blocks` szűrő elrontja a blokk-JSON-t. Mentés előtt: `wp_set_current_user(<admin>)`, `kses_remove_filters()`.
- Komment-JSON-ben: `<` → `<`, `>` → `>`, `&` → `&`, `"` (stringben) → `"`, `--` → `--`.
- Dinamikus (render.php-s) blokkok önzáróak: `iu/title`, `iu/content`, `iu/featured-image`, `iu/menu`, `iu/breadcrumbs`, `iu/query`, `iu/terms`, `iu/pattern`, minden `iu-woocommerce/*` és `iucb_add_block`-kal regisztrált blokk.
- A `params` attribútumok kapcsos zárójel nélküli JSON-t tartalmaznak stringként.
- Reszponzív láthatóság: `className`-ben `d-none m-block` / `m-none`; töréspont 992px (`@media (max-width: 991.8px)`).

## Saját blokk a child témában: `iucb_add_block`

Az `iu_custom_blocks` mu-plugin szerveroldali blokkot regisztrál JS build nélkül. A child téma `inc/blocks/{név}/block.php` fájljaiban (az `init` hookon betöltve):

```php
iucb_add_block('projekt/blokk-nev', [
    'title' => 'Blokk neve',
    'category' => 'widgets',            // vagy 'iu-woocommerce'
    'attributes' => [ 'limit' => [ 'type' => 'string', 'default' => '8' ] ],
    'fields' => [ [ 'panel' => 'Beállítások', 'fields' => [
        'limit' => [ 'type' => 'text', 'label' => 'Darabszám' ],   // text, textarea, toggle, select (options), image
    ] ] ],
    'editJS' => 'return "Blokk neve";',  // szerkesztőbeli előnézet (különben ServerSideRender)
    'template' => function($attributes, $children){ return '<div>…</div>'; },
    'style' => [ 'handle' ], 'view_script' => 'handle',
]);
```

- Belső blokkos konténer: `editJS` → `InnerBlocks`, `saveJS` → `InnerBlocks.Content`; a `template` második paramétere a renderelt gyerekek tömbje.
- A kimenet `do_shortcode`-on megy át. Mindig escapelj (`esc_html`, `esc_url`, `esc_attr`).

## WooCommerce (`iu_woocommerce` mu-plugin)

- **Terméklista:** oldal `[products … class="mainquery"]` shortcode-dal és `iu-woocommerce/filter` blokkal; a kártya kinézetét a „Loop Product” nevű `iu_pattern` adja (`loop-product-title`, `-image`, `-pirce` [sic], `-button`, `product-badges`, `product-brand`, `product-attributes`, `stock`, `short-description`).
- **Termékoldal:** `single_product_content.html` sablon a blokkokból: `images`, `price`, `add-to-cart`, `short-description`, `stock`, `product-brand`, `product-badges`, `product-attributes`, `iu/title`, `iu/breadcrumbs`, `iu/content` (leírás).
- **Fejléc:** `search`, `mini-cart`, `account-menu`; karusszel: `product-carousel` (a legújabb 12 termék; válogatáshoz saját blokk kell).
- A kosár, pénztár és fiók oldal shortcode-os; a sablonfelülírások az `iu_woocommerce/inc/templates/` mappában vannak. **Klasszikus pénztár:** a csak blokkos pénztárral működő funkciók (pl. „Pickup location” szállítás) nem jelennek meg. Helyettük `local_pickup` kell.
- Árak: magyar B2C-nél bruttó ár (`woocommerce_prices_include_tax = yes`) és 27% ÁFA-kulcs kell.

## Űrlapok (`iu/form`)

- **Keretrendszer-hiba:** az `iu/form` e-mailje csak a `message` attribútum fix szövegét küldi, a kitöltött mezőket nem, és a `From` fejléc sosem áll be. Megoldás: az `email` attribútum maradjon üres, és az `iu_form_submit_{formId}` szűrőben küldd el a mezőket (`$form['innerBlocks']`-ből a címkékkel), válaszcímnek a kitöltő e-mailjével. Hiba esetén a válasz `['errors' => [], 'error' => '…']` legyen (az `errors` kulcs kötelező, különben a JS elszáll).
- A kötelező `iu/form-accept` checkboxot a szerver nem ellenőrzi; a szűrőben kell.
- A backend az űrlapot a header, content és footer sablonban, illetve az oldal tartalmában keresi.
- A `formId` stabil, kebab-case; `validate`: soronként `szabály|hibaüzenet` (`required`, `email`).

## Telepítés: adatbázis-változások kódként

A tartalom és a beállítások (oldalak, menü, WooCommerce opciók, sablonok) a child témában verziózott, egyszer futó lépésekként legyenek (`inc/setup.php`: `admin_init`-en egy admin első betöltésekor, vagy WP-CLI parancs). Meglévő, szerkeszthető tartalmat csak akkor írj felül, ha az eredetivel egyezik (md5 a manifestben); ellenkező esetben figyelmeztess.

## Buktatók

- **REST API prefix:** `/wp-json/` helyett egyedi prefix; mindig `rest_url()`.
- **Relatív `require_once('blocks.php')`** a keretrendszerben: ha a PHP munkakönyvtárában van ilyen fájl, rossz töltődik be. A saját kódban mindig `__DIR__.'/…'`, és WP-CLI-t semleges mappából futtass.
- **`is_archive('product_cat')`** (iu_woocommerce) minden archívumon igaz: márka/címke oldalon a lista a slugot kategóriaként kapja, ezért üres. Javítás `shortcode_atts_products` szűrővel.
- **WooCommerce márka-archívum** saját sablont töltene (fejléc/lábléc nélkül): `template_include` szűrővel a téma `index.php`-jára kell irányítani.
- A téma a keresést bejegyzésekre szűkítheti (`pre_get_posts`); ez a `get_posts(['s' => …])` hívásokat is érinti.
- A `--iu-text-font-size` alapból vw-alapú: mobilon mindig ellenőrizd.
- A fejléc és a lábléc fix oszlopszélességei (pl. `width: 32%`) mobilon felülírandók.
- Az `iu/video` a szerkesztőben nem jelenik meg; az adminban a fájlszerkesztő ki van kapcsolva.

## Ellenőrzőlista új weboldalhoz / webshophoz

1. Child téma: `Template: iu_theme`, `vars.css` változók, `theme.json` paletta.
2. Sablonok a `templates/` mappában: header, footer, `single_page_content`, `front_page_content`, `404`, `search`, `blog_page`, `single_post`, post type-onként `single_*`, `archive_*`, `tax_*`.
3. Menü, oldalak, jogi szövegek (ÁSZF, adatkezelés, elállás) telepítő lépésként.
4. Webshop: ÁFA, szállítás (klasszikus pénztárral működő módok), fizetés, e-mail feladó, ÁSZF oldal a pénztárhoz, termékoldal-, kategória-, márka- és keresősablon.
5. Minden tartalom átfut a szerkesztős ellenőrzésen (`"invalid":[]`).
6. Tesztek: rendelés végig (kosár → pénztár → köszönő oldal → e-mailek), minden űrlap beküldése, mobil nézet (vízszintes görgetés nincs), oldalanként egy H1, 404, keresés.

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
| 11 | Csúszkák (Image Carousel, Image Slider, Slider/Slide, Content Slider, HTML Slider, Logo Slider, Text Scroller) |
| 12 | Menu, Breadcrumbs, Post Navigation, Search, Back to Top |
| 13 | Query, Terms, Term Cloud |
| 14 | Accordion, Tabs, Modal, Button Group / Button |
| 15 | Template, Pattern, Test (belső blokkok) |
| 16 | Űrlap blokkok |
| 17 | Shortcode-ok |
| 18–20 | Pattern-ek, engedélyezett core blokkok, vendor könyvtárak |
