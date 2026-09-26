"""A technikai audit szótára: témák (a HelloProVision audit-sablonja szerint) és a Screaming Frog exportok felismerése.

Egy SF export (CSV vagy XLSX, a CLI vagy a program „Export” gombja) fájlneve vagy munkalapneve a „Fül – Szűrő” párt
hordozza, pl. `response_codes_client_error_(4xx).csv`, `page_titles_over_561_pixels.xlsx`, „1 - H1 - Duplicate”.
A szabályok erre illeszkednek, és megmondják: melyik témához tartozik, mi a megállapítás szövege, melyik oszlop a részlet.
"""

import re
from dataclasses import dataclass, field

# Téma: cím, alapméret, bevezető, miért fontos, alap fejlesztési javaslat, elfogadási feltétel (fejlesztői feladathoz).
TOPICS: dict[str, dict] = {
    "http_status": {
        "title": "HTTP státuszkódok", "size": "XL",
        "intro": "Minden lekérésre a szerver egy státuszkóddal válaszol. Ezek a kódok mondhatják azt, hogy minden rendben, de jelenthetik azt is, hogy valamilyen hiba történt az erőforrás letöltése közben. Ezen kódok elemzésével az oldal technikai felkészültségére lehet következtetni.",
        "ideal": ["200 – OK: minden rendben", "301 – Moved Permanently: véglegesen áthelyezve, SEO erőt közvetít", "302 – Found: ideiglenesen áthelyezve, nem közvetít SEO erőt",
                  "404 – Not Found: a tartalom nem található", "500 – Internal Server Error: a szerver belső hibája miatt a tartalom nem elérhető"],
        "why": "Egyrészt fontos a felhasználók szempontjából, mert ők egy céllal érkeznek az oldalra; ha technikai hiba miatt ez nem teljesül, az nem vet jó fényt a márkára. Másrészt a keresőmotorok, ha gyakran kapnak hibát a tartalom helyett, rossz minőségűnek ítélhetik az oldalt és hátrébb sorolhatják.",
        "recommendation": "Javasoljuk az elérhetetlen erőforrások helyreállítását. Amennyiben ez nem lehetséges, az elérhetetlen URL 301-es átirányítással mutasson a tartalmilag legközelebb álló oldalra, vagy legrosszabb esetben a kezdőlapra. A belső linkek közvetlenül a végső, 200-as URL-re mutassanak.",
        "done_when": "Egyik belső link sem mutat 4xx/5xx URL-re; az átirányítások egy lépésben, 301-gyel történnek; a Screaming Frog újrafuttatásakor a lista üres.",
    },
    "titles": {
        "title": "Oldalcímek", "size": "XL",
        "intro": "Az oldalcím (title tag, meta title, SEO title) egy HTML elem a weboldalak fejlécében. A felhasználók számára közvetlenül nem látható, de megjelenik a böngésző fülén és a Google találati listáján.",
        "ideal": ["minden oldalon van", "egy oldalon csak egy van", "rövidebb, mint 60 karakter (561 pixel) – a hosszabbat a Google levághatja",
                  "hosszabb, mint 30 karakter (200 pixel) – a rövid cím inkább kihagyott lehetőség", "egyedi tartalommal bír"],
        "why": "Összefoglalja, miről szól az adott oldal a felhasználóknak és a keresőmotoroknak. Széles körben az egyik legfontosabb on-page SEO elemnek tartják.",
        "recommendation": "Javasoljuk, hogy minden oldal rendelkezzen egyedi, 30–60 karakteres oldalcímmel, amely az oldal elsődleges kulcsszavát tartalmazza.",
        "done_when": "Minden indexelhető oldalnak egyedi, 561 px alatti title-je van; a duplikált és hiányzó címek listája üres.",
    },
    "headings": {
        "title": "Címsorok", "size": "XL",
        "intro": "A címsorok (H1, H2, H3… tagek) mutatják meg a keresőrobotok és a felhasználók számára, hogy az adott oldal miről szól; egy oldal tartalmi vázát alkotják. A hierarchiában a H1 a legfontosabb.",
        "ideal": ["minden oldalon van H1 és – ahol indokolt – H2", "egy oldalon csak egy H1 szerepel", "minden oldal H1 címsora egyedi"],
        "why": "Egyrészt az olvashatóság és a felhasználói élmény miatt fontos, másrészt a keresőmotorok figyelembe veszik a címsorokat, amikor felmérik egy oldal témáját.",
        "recommendation": "Javasoljuk a H1 és H2 címsorok javítását: minden oldalon egy, egyedi H1; a tagolást igénylő oldalakon H2-k; a duplikált címsorok egyedivé tétele a kulcsszókutatás kifejezéseivel.",
        "done_when": "Minden indexelhető oldalon pontosan egy, egyedi H1 van; a hiányzó/duplikált H1 lista üres.",
    },
    "url_format": {
        "title": "URL formátum", "size": "XL",
        "intro": "URL formátum alatt egy oldal URL-jének olvashatóságát, beszédességét értjük. Ideális esetben csak az angol ábécé kisbetűiből és számokból áll, szóközök helyett kötőjelet tartalmaz, a szinteket egy / jel választja el.",
        "ideal": ["nem tartalmaz nagybetűt", "nem tartalmaz ékezetes karaktert", "nem tartalmaz speciális karaktert vagy alulvonást", "nem túl hosszú, paraméter nélküli"],
        "why": "Az URL-eket az emberek miatt találták ki, ezért fontos az olvashatóságuk. Bizonyos jellemzőket a keresőmotorok is figyelembe vesznek.",
        "recommendation": "Javasoljuk a listázott URL-ek javítását (kisbetű, ékezet nélkül, kötőjellel), a régi URL-ekről 301-es átirányítással. Fontos: az URL kis- és nagybetűérzékeny.",
        "done_when": "Az új URL-ek a szabálynak megfelelnek, a régiek egy lépésben 301-gyel átirányítanak, és a belső linkek az új URL-re mutatnak.",
    },
    "indexability": {
        "title": "Indexelhetőség", "size": "M",
        "intro": "Az indexelhetőséget a meta robots / X-Robots-Tag direktívák, a robots.txt és a canonical tag együtt határozza meg.",
        "ideal": ["minden rangsorolásra szánt oldal indexelhető", "noindex csak tudatos döntés alapján (pl. köszönőoldal, tesztoldal)"],
        "why": "Ha egy fontos oldal véletlenül noindex vagy robots.txt által tiltott, a Google nem jeleníti meg a találatok között – ez a leggyorsabban javítható, legnagyobb hatású hiba.",
        "recommendation": "Javasoljuk a noindex oldalak áttekintését: ami rangsorolásra szánt, legyen indexelhető; a tesztoldalak legyenek törölve és átirányítva, vagy noindexeltek.",
        "done_when": "A noindex oldalak listáját a SEO manager jóváhagyta; minden rangsorolásra szánt oldal indexelhető.",
    },
    "canonicals": {
        "title": "Canonical tagek", "size": "M",
        "intro": "A canonical taggel jelezhető a Google felé, hogy több hasonló tartalmú oldal közül melyik az eredeti. Alapesetben minden oldal saját magára mutat, hacsak nem másolat.",
        "ideal": ["minden indexelhető oldalon önhivatkozó canonical", "canonical csak indexelhető, 200-as URL-re mutat", "egy oldalon egy canonical"],
        "why": "Ahol a canonical nem rendezi az alá-fölé rendeltséget, a tartalmak egymás elől kannibalizálhatják a forgalmat, és kiszámíthatatlan, melyik URL jelenik meg a találatok között.",
        "recommendation": "Javasoljuk a hiányzó canonical tagek pótlását, alapbeállításként önhivatkozó módon; a nem indexelhető URL-re mutató canonicalöket indexelhető oldalra kell javítani. A duplikált tartalmú oldalaknál döntsünk az eredetiről.",
        "done_when": "Minden indexelhető HTML oldalon van önhivatkozó (vagy tudatosan beállított) canonical; a hiányzó canonical lista üres.",
    },
    "images": {
        "title": "Képek optimalizálása", "size": "M",
        "intro": "Egy weboldal összméretének nagy részét a képek teszik ki, ezért optimalizáltságuk egyre fontosabb.",
        "ideal": ["beszédes fájlnév", "van alt attribútuma; ha a tartalom része, egyedi alt szövege", "optimalizált felbontás és fájlméret (≤100 KB)",
                  "modern formátum (WebP, AVIF)", "a hajtás alatti képek lazy loadinggal"],
        "why": "Az oldal sebessége, mobilbarátsága és felhasználóbarátsága rangsorolási tényező, és ebben a képeknek nagy szerepe van. Az alt szöveg és a fájlnév segít a robotoknak értelmezni a képet és a tartalom témáját.",
        "recommendation": "Javasoljuk a feleslegesen nagy képek optimalizálását és az alt attribútumok pótlását. A UI részét képező képek üres alt taggel (alt=\"\"), a tartalom részét képezők pár szavas leíró alt szöveggel.",
        "done_when": "A 100 KB feletti képek lecserélve optimalizált WebP/AVIF változatra; a tartalmi képeknek van alt szövege.",
    },
    "security": {
        "title": "Biztonsági beállítások", "size": "M",
        "intro": "Alapvető biztonsági beállítások minden informatikai rendszer esetében javallottak.",
        "ideal": ["minden oldal és erőforrás (kép, CSS, JS) HTTPS-en töltődik be", "a http:// változat 301-gyel a https://-re irányít", "van security.txt (RFC 9116)", "a teljes szoftverkörnyezet naprakész"],
        "why": "SEO szempontból fontos, hogy a weboldal minden aloldala és erőforrása titkosított kapcsolaton keresztül töltődjön be. Biztonsági rések időről időre minden rendszerben előkerülnek.",
        "recommendation": "Javasoljuk a nem HTTPS erőforrások javítását és a security.txt fájl létrehozását.",
        "done_when": "Nincs HTTP-s belső URL vagy vegyes tartalom; a security.txt elérhető.",
    },
    "sitemap": {
        "title": "Sitemap", "size": "M",
        "intro": "A sitemap a domain alatti oldalakat, képeket, videókat listázó fájl, elsősorban keresőmotorok számára. Közepes és nagy oldalaknál erősen ajánlott.",
        "ideal": ["minden indexelésre szánt oldal szerepel benne, átirányítás nélkül", "nem indexelhető URL nem szerepel benne", "egy URL csak egy sitemapben", "nincs árva oldal (minden oldalra mutat belső link)"],
        "why": "Segíti a keresőmotorokat az oldalak feltérképezésében, és jelzi, mikor frissült egy tartalom.",
        "recommendation": "Javasoljuk, hogy minden indexelésre szánt, elérhető aloldal átirányítás nélkül szerepeljen a sitemapben; az árva oldalakra kerüljenek belső linkek; egy URL csak egy sitemapben szerepeljen.",
        "done_when": "A sitemap csak indexelhető, 200-as URL-eket tartalmaz, és a Search Console-ban hibamentes.",
    },
    "speed": {
        "title": "Oldalsebesség optimalizálása", "size": "S",
        "intro": "Sem a felhasználók, sem a keresőmotorok nem szeretik, ha megvárakoztatják őket; a visszafordulási arány a betöltési idővel együtt nő.",
        "ideal": ["gyors szerver", "kevés lekérés, kis fájlméretek", "tömörített HTML/CSS/JS", "minél kevesebb átirányítás", "nincs renderelést blokkoló erőforrás", "optimalizált képek"],
        "why": "A Google a minőségi tartalom mellett a felhasználói élményt is figyelembe veszi; a Core Web Vitals mutatók 2021 óta rangsorolási szempontok.",
        "recommendation": "A PageSpeed Insights javaslatai közül a legfontosabb a nem használt JavaScript- és CSS-kód eltávolítása és a nagy képek felbontásának csökkentése.",
        "done_when": "A PageSpeed mobil pontszám és a Core Web Vitals (LCP, CLS, INP) a jóváhagyott célértéken belül.",
    },
    "meta": {
        "title": "Meta leírások", "size": "S",
        "intro": "A meta leírás a találati listán a cím alatt megjelenő 1–3 mondatos, figyelemfelkeltő szöveg.",
        "ideal": ["hosszabb, mint 70 karakter", "nem hosszabb, mint 150 karakter (985 pixel)", "minden oldalon egyedi", "kattintásra ösztönöz"],
        "why": "Ma már nem közvetlen rangsorolási tényező, de a jó meta leírás növeli a kattintási arányt, így a látogatások számát.",
        "recommendation": "Javasoljuk, hogy minden oldal rendelkezzen egyedi, 70–150 karakteres meta leírással.",
        "done_when": "Minden indexelhető oldalnak egyedi, 985 px alatti meta leírása van.",
    },
}
TOPIC_ORDER = list(TOPICS)


@dataclass
class Rule:
    key: str
    topic: str
    pattern: str
    label: str  # {n} = darabszám
    detail: list[str] = field(default_factory=list)
    inlinks: bool = False  # „From / To” szerkezetű (bulk / inlinks) export

    def matches(self, name: str) -> bool:
        return re.search(self.pattern, name) is not None


RULES: list[Rule] = [
    Rule("status_4xx", "http_status", r"response_codes_(internal_)?(all_)?client_error", "{n} URL-t találtunk, ami 4xx (pl. 404) válaszkódot ad vissza", ["Status Code", "Inlinks"]),
    Rule("status_5xx", "http_status", r"response_codes_(internal_)?(all_)?server_error", "{n} URL-t találtunk, ami 5xx (szerverhiba) válaszkódot ad vissza", ["Status Code", "Inlinks"]),
    Rule("status_3xx", "http_status", r"response_codes_(internal_)?(all_)?redirection_\(?3xx", "{n} átirányított (3xx) URL-t találtunk, amelyre belső link mutat", ["Status Code", "Redirect URL"]),
    Rule("status_blocked", "http_status", r"response_codes_(internal_)?blocked_by_robots", "{n} URL-t tilt a robots.txt", ["Status"]),
    Rule("redirect_chains", "http_status", r"redirect_chains|redirect_and_canonical_chains", "{n} átirányítási láncot találtunk", ["Number of Redirects", "Final Address"]),
    Rule("title_missing", "titles", r"page_titles_missing", "{n} oldal nem rendelkezik oldalcímmel"),
    Rule("title_duplicate", "titles", r"page_titles_duplicate", "{n} oldal nem egyedi oldalcímmel rendelkezik", ["Title 1"]),
    Rule("title_long", "titles", r"page_titles_over_\d+", "{n} oldal címe túl hosszú (a Google levághatja)", ["Title 1", "Title 1 Length", "Title 1 Pixel Width"]),
    Rule("title_short", "titles", r"page_titles_below_\d+", "{n} oldal címe túl rövid", ["Title 1", "Title 1 Length"]),
    Rule("title_multiple", "titles", r"page_titles_multiple", "{n} oldalon több title tag is van", ["Title 1", "Title 2"]),
    Rule("h1_missing", "headings", r"^h1_missing", "{n} oldal nem rendelkezik H1 címsorral"),
    Rule("h1_duplicate", "headings", r"^h1_duplicate", "{n} oldal nem egyedi H1 címsort tartalmaz", ["H1-1"]),
    Rule("h1_multiple", "headings", r"^h1_multiple", "{n} oldalon több H1 címsor is van", ["H1-1", "H1-2"]),
    Rule("h2_missing", "headings", r"^h2_missing", "{n} oldal nem rendelkezik H2 címsorral"),
    Rule("h2_duplicate", "headings", r"^h2_duplicate", "{n} oldal nem egyedi H2 címsort tartalmaz", ["H2-1"]),
    Rule("url_non_ascii", "url_format", r"^url_(non_ascii|special_characters)", "{n} URL ékezetes vagy speciális karaktereket tartalmaz"),
    Rule("url_underscores", "url_format", r"^url_underscores", "{n} URL alulvonást tartalmaz"),
    Rule("url_uppercase", "url_format", r"^url_uppercase", "{n} URL nagybetűt tartalmaz"),
    Rule("url_parameters", "url_format", r"^url_parameters", "{n} URL paramétert tartalmaz"),
    Rule("url_long", "url_format", r"^url_over_\d+", "{n} URL túl hosszú"),
    Rule("url_multiple_slashes", "url_format", r"^url_multiple_slashes", "{n} URL-ben több egymást követő / jel van"),
    Rule("noindex", "indexability", r"directives_noindex", "{n} oldal noindex direktívát tartalmaz", ["Meta Robots 1", "X-Robots-Tag 1"]),
    Rule("nofollow", "indexability", r"directives_nofollow", "{n} oldal nofollow direktívát tartalmaz", ["Meta Robots 1"]),
    Rule("canonical_missing", "canonicals", r"canonicals_missing", "{n} URL nem tartalmaz canonical taget", ["Indexability"]),
    Rule("canonical_non_indexable", "canonicals", r"canonicals_non_indexable_canonical", "{n} URL canonical tagje nem indexelhető URL-re mutat", ["Canonical Link Element 1"]),
    Rule("canonicalised", "canonicals", r"canonicals_canonicalised", "{n} URL canonical tagje másik oldalra mutat", ["Canonical Link Element 1"]),
    Rule("canonical_multiple", "canonicals", r"canonicals_multiple", "{n} URL több canonical taget tartalmaz", ["Canonical Link Element 1", "Canonical Link Element 2"]),
    Rule("content_exact_duplicate", "canonicals", r"content_exact_duplicates", "{n} oldal tartalma pontosan megegyezik egy másik oldaléval", ["Hash"]),
    Rule("content_near_duplicate", "canonicals", r"content_near_duplicates", "{n} oldal tartalma majdnem megegyezik egy másik oldaléval", ["Closest Similarity Match"]),
    Rule("images_large", "images", r"images_over_\d+|images?_over_?\d+_?kb", "{n} olyan képet találtunk, amelynek mérete feltehetően feleslegesen nagy", ["Size (Bytes)", "Size"]),
    Rule("images_alt_missing", "images", r"images_missing_alt_(text|attribute)", "{n} kép nem tartalmaz alt szöveget", ["Alt Text"]),
    Rule("security_http", "security", r"security_http_urls", "{n} belső URL nem HTTPS"),
    Rule("security_mixed", "security", r"security_mixed_content", "{n} oldalon vegyes (HTTP + HTTPS) tartalom töltődik be"),
    Rule("security_hsts", "security", r"security_missing_hsts", "{n} oldalon hiányzik a HSTS fejléc"),
    Rule("sitemap_orphan", "sitemap", r"sitemaps?_orphan", "{n} árva oldalt találtunk (nem mutat rá belső link)"),
    Rule("sitemap_not_in", "sitemap", r"sitemaps?_urls_not_in_sitemap", "{n} olyan URL-t találtunk, amely nem szerepel a sitemapben"),
    Rule("sitemap_non_indexable", "sitemap", r"sitemaps?_non_indexable_urls_in_sitemap", "{n} nem indexelhető URL-t találtunk a sitemapben", ["Indexability Status"]),
    Rule("sitemap_multiple", "sitemap", r"sitemaps?_urls_in_multiple_sitemaps", "{n} URL több sitemapben is szerepel"),
    Rule("meta_missing", "meta", r"meta_description_missing", "{n} URL nem tartalmaz meta leírást"),
    Rule("meta_duplicate", "meta", r"meta_description_duplicat", "{n} URL nem egyedi meta leírást tartalmaz", ["Meta Description 1"]),
    Rule("meta_long", "meta", r"meta_description_over_\d+", "{n} URL meta leírása túl hosszú", ["Meta Description 1 Length", "Meta Description 1 Pixel Width"]),
    Rule("meta_short", "meta", r"meta_description_below_\d+", "{n} URL meta leírása túl rövid", ["Meta Description 1 Length"]),
]
RULES_BY_KEY = {r.key: r for r in RULES}

# Nem Screaming Frogból jövő megállapítások (helyszíni ellenőrzés, kézi mérés).
EXTRA = {
    "status_302": ("http_status", "{n} URL-t találtunk, ami 302-es (ideiglenes) átirányítást ad vissza"),
    "security_txt_missing": ("security", "Nem találtunk security.txt fájlt (RFC 9116)."),
    "https_not_forced": ("security", "A http:// változat nem irányít át a https:// változatra."),
    "robots_missing": ("indexability", "Nem találtunk robots.txt fájlt."),
    "sitemap_missing": ("sitemap", "Nem találtunk XML sitemapet (sitemap.xml / robots.txt Sitemap sor)."),
}


def label_for(key: str, n: int) -> str:
    if key in RULES_BY_KEY:
        return RULES_BY_KEY[key].label.format(n=n)
    if key in EXTRA:
        return EXTRA[key][1].format(n=n)
    return f"{key}: {n}"


def topic_for(key: str) -> str:
    if key in RULES_BY_KEY:
        return RULES_BY_KEY[key].topic
    return EXTRA.get(key, ("http_status",))[0]


def normalize_name(name: str) -> str:
    n = name.lower()
    n = re.sub(r"\.(csv|xlsx|xls|tsv)$", "", n)
    n = re.sub(r"^\d+\s*-\s*", "", n)
    n = re.sub(r"[^a-z0-9()]+", "_", n).strip("_")
    return n


def match(filename: str, sheet: str = "") -> tuple[str, str]:
    """(issue_key, kind) – kind: 'issue' | 'internal_all' | ''"""
    for candidate in (normalize_name(filename), normalize_name(sheet)):
        if not candidate:
            continue
        if re.match(r"^internal_(all|html)$", candidate):
            return "internal_all", "internal_all"
        for r in RULES:
            if r.matches(candidate):
                return r.key, "issue"
    return "", ""
