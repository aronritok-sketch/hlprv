"""Wireframe-sablonok oldaltípusonként – a feltöltött HelloProVision wireframe-ek szerkezete alapján
(01 website design and seo – cikk; 04 case study; Aloldalak wireframe – városi hub és szolgáltatási landing; Főoldal wireframe).

A {kulcs} helyőrzőket a generátor tölti ki. A H2-k a tartalmi nyelven, a magyarázatok a munkanyelven (magyarul) vannak,
ahogy a szövegírónak szóló dokumentumokban.
"""

ARTICLE = {
    "word_count": (1200, 1600),
    "essence": "{summary} A cikk válaszoljon meg egy információs vagy döntési kérdést, majd vezessen tovább a(z) {target_url} oldalra. "
               "Mehet a kutatás; írjunk jobbat, informatívabbat, mint a versenytársak.{language_note}",
    "sections": [
        ("Intro / opening problem", "Azonnal felismerhetővé tenni a problémát.", "Ne definícióval kezdjen. Mutassa meg a tipikus helyzetet, amelyben az olvasó van, és hogy miért fontos most a téma.", "Az elsődleges kulcsszó ({primary}) természetesen 1× jelenjen meg az első 100–150 szóban.", "Még ne legyen link.", 120, 170),
        ("Why it matters", "Az üzleti probléma megértetése szakzsargon nélkül.", "Tipikus hibák és következményeik, konkrét példákkal.", "Kapcsolódó kifejezések csak természetesen: {secondary}.", "A blokk végén kontextuális link: {target_anchor} → {target_url}.", 170, 220),
        ("How to approach it", "Bemutatni a helyes megközelítést lépésenként.", "Gyakorlati lépések, döntési szempontok; ahol illik, a mi folyamatunk.", "Nem kell exact match ismétlés; szemantikai támogatás.", "Opcionális link a kapcsolódó szolgáltatásra.", 190, 240),
        ("What to check / key factors", "Döntéstámogató, listázható rész (boostolható).", "Lista vagy checklist formában a legfontosabb szempontok.", "A kapcsolódó kulcsszavak lefedése természetes nyelven.", "Nincs kötelező link.", 170, 220),
        ("Local context", "A lokáció támogató kontextusként jelenjen meg.", "{location_context} – csak valódi helyi relevancia esetén; ne legyen városlista.", "A város + szolgáltatás exact kifejezést a dedikált landing célozza, itt ne erőltessük.", "1–2 városi oldal természetes példaként.", 110, 160),
        ("Proof: how it works in practice", "Hitelesség: valós folyamat vagy példa.", "Saját folyamatábra, ellenőrzött mini példa vagy releváns case study. Kitalált eredmény nem használható.", "–", "Link a releváns case study-ra, ha van.", 120, 170),
        ("When to get help", "Döntéstámogató lezárás.", "Mikor érdemes szakemberhez fordulni, és mi történik az első lépésben. Ne mondja, hogy mindenkinek szüksége van rá.", "Az elsődleges kulcsszó egyszer még szerepelhet.", "Fő CTA: {cta} → {target_url}. Másodlagos: {contact_url}.", 150, 190),
        ("FAQ", "Valódi döntési kérdések megválaszolása.", "3–5 valós kérdés rövid válasszal; nem kulcsszóismétlésre írt kérdések.", "FAQ schema használható.", "–", 120, 200),
    ],
    "must_have": [
        "A H1 pontosan a javasolt cím vagy annak nagyon közeli változata.",
        "Az elsődleges kulcsszó természetesen szerepeljen, de ne ismétlődjön mechanikusan.",
        "Legalább 1 valós folyamat- vagy proof-elem kerüljön a cikkbe.",
        "A CTA a(z) {target_url} oldalra vigyen.",
    ],
    "forbidden": [
        "Ne ígérjen Top 1 helyezést vagy garantált eredményt.",
        "Ne legyen a(z) {target_url} landing másolata.",
        "Ne linkeljünk minden exact-match kulcsszót.",
        "Ne legyen több városból hosszú, ismétlődő city blokk.",
    ],
    "proof": "Használható: saját folyamatábra, wireframe- vagy sitemap-részlet, ellenőrzött mini példa vagy releváns case study. "
             "Kitalált eredmény, kitalált ügyfél vagy garantált SEO-hatás nem használható. A proofnak a SEO-s néz utána.",
}

CASE_STUDY = {
    "word_count": (700, 1300),
    "essence": "A case study nem SEO-cikk és nem portfóliógaléria. A feladata az üzleti probléma, a projekt mögötti döntések, a kivitelezés "
               "és az ellenőrzött eredmény bizonyítása. Csak azokat a modulokat használjuk, amelyek ténylegesen részei voltak a projektnek.",
    "sections": [
        ("Project overview", "5–10 másodperc alatt érthetővé tenni, mi volt a projekt és mi lett az eredmény.", "Ügyfél/projekt neve, iparág, projekttípus, jóváhagyott szolgáltatások, rövid kiinduló helyzet és az eredmény. Helyszínt csak akkor, ha igaz és releváns.", "Nincs kötelező kulcsszó.", "Breadcrumb → {work_url}.", 80, 120),
        ("The challenge", "Pontosan megmutatni a kiinduló üzleti problémát.", "Mi nem működött? Csak dokumentálható problémát írj.", "Projektalapú, természetes nyelv.", "Nincs kötelező link.", 100, 160),
        ("Objectives", "Különválasztani a problémát attól, amit el kellett érni.", "3–5 konkrét, valós cél.", "Nincs exact-match cél.", "–", 60, 150),
        ("Research / strategy", "Bizonyítani, hogy a projekt nem a vizuális designnal kezdődött.", "Milyen input alapján döntöttünk – csak ami ténylegesen történt.", "strategy, research természetesen.", "Kontextuális link: {target_anchor} → {target_url}.", 100, 160),
        ("Structure / wireframe", "Megmutatni, hogyan lett a kutatásból ügyfélút.", "2–4 szerkezeti döntés és azok indoka; sitemap vagy wireframe részlet, ha van.", "sitemap, wireframe természetesen.", "–", 100, 160),
        ("UX/UI design", "A design döntéseit üzleti és UX-nyelven bemutatni.", "Hierarchy, navigation, CTA, trust/proof – csak ami releváns. Kötelező desktop + mobile vizuál.", "UX/UI design szemantikusan.", "–", 100, 160),
        ("Development", "Hogyan vált a terv működő rendszerré.", "Platform, CMS, integrációk, teljesítmény – csak ami része volt a projektnek.", "website development, ha releváns.", "–", 80, 140),
        ("SEO / marketing – only if relevant", "Csak a ténylegesen elvégzett munka.", "Ha nem volt, a blokk teljesen maradjon ki.", "Csak a tényleges szolgáltatás kifejezései.", "Releváns szolgáltatási oldal.", 0, 140),
        ("Results", "A bizonyítás csúcspontja.", "Jóváhagyott mérhető eredmény vagy pontos kvalitatív fejlődés. Ne találj ki százalékot.", "Nincs kötelező kulcsszó.", "Itt ne vigyük el a figyelmet linkkel.", 100, 180),
        ("Client feedback", "Harmadik fél proof.", "Csak jóváhagyott testimonial; ha nincs, maradjon ki.", "–", "–", 0, 80),
        ("Related services + next step", "A proofot visszakötni a szolgáltatási rendszerhez.", "1–3 releváns szolgáltatási link + Work hub + záró CTA.", "Nincs kulcsszóhalmozás.", "Fő CTA: {cta} → {contact_url}.", 40, 90),
    ],
    "must_have": ["Csak valós, ellenőrzött proof.", "Webes projektnél desktop + mobile vizuál kötelező.", "A nem releváns modulok maradjanak ki."],
    "forbidden": ["Kitalált ügyfél, eredmény vagy százalék.", "Általános „great team” típusú testimonial.", "Olyan szolgáltatás bemutatása, ami nem volt része a projektnek."],
    "proof": "Kötelező input: projektbrief, before állapot (screenshot, audit, analytics), a ténylegesen használt kutatás, jóváhagyott sitemap/wireframe, "
             "desktop + mobile képernyők, jóváhagyott eredmények és testimonial.",
    "visual": "Hero: legerősebb responsive mockup → Challenge: before screenshot → Strategy/Structure: sitemap vagy wireframe részlet → "
              "Design: desktop + mobile UI → Development: releváns funkció → Results: before/after vagy jóváhagyott metrika → Testimonial.",
    "inputs": [
        ("Projektbrief", "Üzleti cél, scope, kiinduló helyzet, jóváhagyott szolgáltatások."),
        ("Before állapot", "Régi oldal screenshot, audit, analytics vagy dokumentált működési probléma."),
        ("Kutatás", "A ténylegesen használt kutatási inputok."),
        ("Sitemap / wireframe", "Jóváhagyott tervezési artifact vagy screenshot."),
        ("Design", "Desktop + mobile képernyők; fontos UX/UI döntések."),
        ("Eredmények", "Jóváhagyott számok vagy kvalitatív eredmény; testimonial engedéllyel."),
    ],
}

CITY_HUB = {
    "word_count": (700, 1100),
    "essence": "A(z) {location} városi hub a helyi szolgáltatások választási és belső linkelési központja: megmutatja, hogyan működnek együtt, "
               "és a részletes szolgáltatási oldalakra irányít. Nem szolgáltatási oldal – a részletes tartalom a saját landingekre kerül.",
    "sections": [
        ("Hero", "Város + fő téma H1, rövid helyi értékajánlat.", "Egy fő CTA és egy korai proof.", "Primary: {primary}.", "CTA: {cta} → {contact_url}.", 60, 90),
        ("Local market", "Milyen vállalkozások és problémák jellemzőek a városban.", "{local_variation}", "Helyi kontextus, nem kulcsszóismétlés.", "–", 100, 150),
        ("Service paths", "A helyi szolgáltatások bemutatása kártyákkal.", "Minden szolgáltatás 2–3 mondat + link a városi landingre.", "Szolgáltatásnevek természetesen.", "Kártyák: {service_links}.", 150, 250),
        ("Why it works together", "A szolgáltatások közös üzleti célja.", "Rövid magyarázat, miért egy rendszer.", "–", "–", 90, 140),
        ("Selected work", "2–3 releváns case study.", "Nem kell hamis helyi referenciát állítani.", "–", "Link: {work_url}.", 60, 120),
        ("Why us", "Megkülönböztetés.", "SEO-first struktúra, design + development, senior hozzáférés, ownership.", "–", "–", 80, 120),
        ("Process", "Kiszámítható együttműködés.", "Audit → structure → execution → measurement.", "–", "–", 60, 100),
        ("FAQ + CTA", "Döntési akadályok oldása.", "5–8 valódi FAQ: terület, szolgáltatásválasztás, indulás, timeline.", "FAQ schema.", "Záró CTA: {cta} → {contact_url}.", 150, 250),
    ],
    "must_have": ["Egyedi helyi szöveg, nem városnév-csere.", "Proof az első két képernyőn.", "Mind a helyi szolgáltatási oldal belinkelve.", "5–8 valódi FAQ."],
    "forbidden": ["Nem ismételjük a szolgáltatási oldalak részletes tartalmát.", "Nincs hamis helyi referencia.", "Nincs helyezésgarancia."],
    "proof": "Legalább egy projekt vagy eredmény a városból vagy az iparágból; ha nincs helyi, általános proof helyi állítás nélkül.",
}

CITY_SERVICE = {
    "word_count": (900, 1400),
    "essence": "A(z) {location} + {service} kereskedelmi keresések lefedése és ajánlatkérés generálása. Ugyanaz a szolgáltatási modell minden "
               "városban, de a problémák, iparágak, FAQ és proof egyedi helyi tartalmat kapnak.",
    "sections": [
        ("Hero", "Város + szolgáltatás H1, projektvizuál, egy fő CTA, egy korai proof.", "60–90 szavas bevezető: kinek, mit, milyen eredménnyel.", "Primary: {primary} a H1-ben vagy közvetlenül alatta.", "CTA: {cta} → {contact_url}.", 60, 90),
        ("Why it isn't working", "A tipikus üzleti problémák.", "3–5 konkrét probléma, helyi példákkal. {local_variation}", "Kapcsolódó kifejezések: {secondary}.", "–", 120, 180),
        ("What's included", "Pontos szolgáltatástartalom.", "A szolgáltatás elemei, érthetően.", "Szemantikai támogatás.", "–", 150, 220),
        ("How we work", "Rövid folyamat.", "Discover → Structure → Build → QA → Launch (vagy a szolgáltatáshoz illő lépések).", "–", "–", 80, 130),
        ("Selected work", "Proof.", "3 projekt: probléma, megoldás, eredmény – csak valós.", "–", "Link: {work_url}.", 90, 150),
        ("Local industries", "Helyi relevancia.", "Csak a városhoz és a proofhoz valóban releváns iparágak.", "–", "–", 80, 130),
        ("Related services", "Belső linkelés.", "A városi hub és a kapcsolódó helyi szolgáltatások.", "–", "Linkek: {related_links}.", 50, 90),
        ("FAQ + CTA", "Döntési akadályok.", "5–8 valódi FAQ: ár, időtáv, folyamat, tulajdonjog, karbantartás.", "FAQ schema.", "Záró CTA: {cta} → {contact_url}.", 150, 250),
    ],
    "must_have": ["Egyedi SEO title + egy H1 + 60–90 szavas bevezető + egy fő CTA.", "3–5 konkrét üzleti probléma.", "Case study, portfólió, adat vagy testimonial az első két képernyőn.", "Városi hub, kapcsolódó szolgáltatások és releváns cikk belinkelve.", "5–8 valódi döntési FAQ."],
    "forbidden": ["Városnév-cserés másolat más városok oldalairól.", "Helyezésgarancia.", "Hamis helyi referencia."],
    "proof": "Responsive mockup, valódi case study, PageSpeed vagy konverziós eredmény – csak ellenőrzött adat.",
}

SERVICE = {
    "word_count": (900, 1500),
    "essence": "A(z) {service} szolgáltatás teljes keresési szándékának lefedése és ajánlatkérés generálása. {summary}",
    "sections": [(h, g, w.replace("{local_variation}", "").replace("Város + szolgáltatás", "Szolgáltatás"), s, l, a, b) for h, g, w, s, l, a, b in CITY_SERVICE["sections"] if h != "Local industries"],
    "must_have": ["Egy H1, a primary kulcsszó a H1-ben vagy a bevezetőben.", "Pontos szolgáltatástartalom és folyamat.", "Proof az első két képernyőn.", "Városválasztó a helyi oldalakra (ha vannak)."],
    "forbidden": ["A városi oldalak kulcsszavainak célzása.", "Helyezésgarancia.", "Lexikoncikk jellegű szöveg – az információs témák cikket kapnak."],
    "proof": "Valós projekt, eredmény vagy testimonial.",
}

HOME = {
    "word_count": (900, 1400),
    "essence": "A főoldal a márka, a teljes ajánlat és a fő útvonalak központja. Nem próbál egyetlen várost vagy szolgáltatást túlcélozni: "
               "a szolgáltatási és városi kulcsszavakat a saját oldalaik kapják.",
    "sections": [
        ("Hero", "Mit csinálunk, hol, milyen eredménnyel – 5 másodperc alatt.", "H1, rövid értékajánlat, egy fő CTA, projektvezérelt vizuál.", "Témaszintű fogalmak, nem egy kulcsszó erőltetése.", "Fő CTA: {cta} → {contact_url}; másodlagos: {work_url}.", 40, 80),
        ("Proof strip", "Bizonytalanság csökkentése.", "Csak bizonyítható számok, tapasztalat, platformok.", "–", "–", 20, 50),
        ("Business problem", "A keresési szándék üzleti problémává fordítása.", "Miért nem elég egy különálló megoldás.", "–", "Szöveglink: rólunk oldal.", 80, 130),
        ("Services", "Belső link a szolgáltatási oldalak felé.", "Szolgáltatáskártyák rövid leírással.", "Szolgáltatásnevek természetesen.", "Kártyák: {service_links}.", 120, 200),
        ("Locations", "Választási út városok szerint.", "Városválasztó a városi hubokra (ha vannak).", "Városnevek természetesen.", "Szöveglinkek: {city_links}.", 60, 120),
        ("Selected work", "Vizuális és üzleti proof.", "3–4 projekt: probléma, megoldás, eredmény.", "–", "Kártyák: {work_url}.", 80, 140),
        ("Why us", "Megkülönböztetés.", "Mitől más, mint a versenytársak.", "–", "–", 80, 130),
        ("Process", "Kiszámíthatóság.", "A folyamat lépései.", "–", "Gomb: {contact_url}.", 60, 100),
        ("FAQ + CTA", "Döntési akadályok.", "Ár, időtáv, terület, tulajdonjog.", "FAQ schema.", "Záró CTA: {cta} → {contact_url}.", 120, 220),
    ],
    "must_have": ["Egy fő konverzió; ne legyen több versengő CTA.", "Proof az első képernyőkön.", "Világos választási út szolgáltatás és város szerint."],
    "forbidden": ["Szolgáltatási vagy városi kulcsszó elsődleges célzása.", "Hosszú, minden kombinációt felsoroló linklista."],
    "proof": "Projekt, tapasztalat vagy eredmény az első képernyőkön.",
}

SUPPORT = {
    "word_count": (300, 700),
    "essence": "Támogató oldal ({title}): bizalom, információ vagy konverzió. Nem kap önálló SEO-fókuszt.",
    "sections": [
        ("Hero", "Az oldal célja egy mondatban.", "Rövid bevezető.", "–", "–", 30, 60),
        ("Main content", "Az oldal fő tartalma.", "A céljához illő blokkok.", "–", "–", 150, 400),
        ("CTA", "Következő lépés.", "Egyértelmű cselekvésre ösztönzés.", "–", "{cta} → {contact_url}", 20, 50),
    ],
    "must_have": ["Egyértelmű következő lépés."],
    "forbidden": ["Kulcsszóhalmozás."],
    "proof": "",
}

BY_TYPE = {
    "article": ARTICLE,
    "pillar": ARTICLE,
    "case_study": CASE_STUDY,
    "city_hub": CITY_HUB,
    "city_service": CITY_SERVICE,
    "service": SERVICE,
    "service_hub": SERVICE,
    "category": SERVICE,
    "product": SERVICE,
    "home": HOME,
    "support": SUPPORT,
}
