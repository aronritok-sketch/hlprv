# Átadás: CRM, ügyfélportál és weboldal-bővítmények

Áron döntése (2026-09-26): innentől egy chat dolgozik a repón, az SEO OS chat (ág: `claude/great-gauss-jba4b5`).
A CRM chat eddig a `claude/laughing-wozniak-dpwf28` ágon dolgozott; minden munkája pusholva van, félkész nincs.
Ez a jegyzet mindazt tartalmazza, ami nem derül ki magából a kódból.

## Mi tartozott a CRM chathez

| Terület | Hol | Állapot |
|---|---|---|
| CRM és ügyfélportál | `plugins/helloprovision-portal/` (0.8.3, DB-verzió 9) | kész, tesztelve |
| Weboldal űrlapjai → CRM, forrásmérés | `mu-plugins/helloprovision-leads.php` | kész, tesztelve (valódi HTTP-vel is) |
| Website Grader | `plugins/helloprovision-grader/` | kész; a CRM-be a leads mu-pluginon át küld |
| Google értékelés-kérő | `plugins/helloprovision-reviews/` | kész; a CRM aláírt kéréssel hívja |
| SEO schema javítások | `mu-plugins/helloprovision-seo.php` | kész |
| Felhasználói leírás | `README.md` | naprakész (0.8) |
| SEO OS szerződés | `docs/integrations/seo-os.md` | naprakész |
| Fejlesztői dokumentáció | Claude Doc: https://claude.ai/code/artifact/2c836be0-21a4-4d11-ac38-f865036a9c56 | naprakész (0.8 fejezet, élesítési listák verziónként) |

Verziók története röviden: 0.4 jogosultságok és számlázás (USA: QuickBooks + Stripe, HU: Számlázz.hu + Teya link),
0.5 AI-ajánlatok és szerződések, 0.6 ismétlődő számlák, magyar portál, fájlok, Bitrix24 import, számlák az appban,
0.7 marketing/SEO (havi csomagok, tartalom-jóváhagyás, havi riport, GSC/GA4/Ads/Meta), fizetési emlékeztetők,
munkaidő a számlára, Grader → CRM, értékeléskérés, ügyfél-adatlap és szolgáltatás-katalógus az appban,
kapcsolati űrlap → CRM; 0.8 értékesítési tölcsér és forrásmérés.

## Élő állapot (fontos)

- **Semmi nem fut még élesben** a CRM oldalon, és egyetlen külső API sem volt valódi fiókkal kipróbálva: Számlázz.hu, Stripe,
  QuickBooks, Daily, Anthropic, Google (GSC, GA4, Ads), Meta, Bitrix24, a Reviews/Grader híd. Mind helyettesített
  válaszokkal van tesztelve. Az első élesítésnél ezeket egyenként végig kell próbálni (a fejlesztői doksi élesítési listái
  verziónként megvannak).
- Kulcsot Áron még nem adott meg; a wp-config konstansok üresek.
- A fejlesztői környezetem (WP Playground + SQLite, `crm.localhost` / `clients.localhost`, bemutató-adatok) ideiglenes volt,
  nincs a repóban. A képernyőképek is csak Áronnak mentek el.

## Konfiguráció (csak nevek; értékek a wp-config.php-ban)

- Hostok: `HPV_CRM_HOST`, `HPV_PORTAL_HOST`, `HPV_FORCE_HTTPS`
- Számlázás: `HPV_SZAMLAZZ_AGENT_KEY`, `HPV_STRIPE_SECRET_KEY`, `HPV_STRIPE_WEBHOOK_SECRET`, `HPV_QBO_CLIENT_ID`, `HPV_QBO_CLIENT_SECRET`, `HPV_QBO_SANDBOX`
- Videó és AI: `HPV_DAILY_API_KEY`, `HPV_AI_API_KEY`, `HPV_AI_MODEL` (nem kötelező)
- Riport-adatforrások: `HPV_GOOGLE_CLIENT_ID`, `HPV_GOOGLE_CLIENT_SECRET`, `HPV_GOOGLE_ADS_DEVELOPER_TOKEN`, `HPV_GOOGLE_ADS_LOGIN_CUSTOMER_ID`, `HPV_GOOGLE_ADS_API_VERSION` (alap v21), `HPV_META_ACCESS_TOKEN`, `HPV_META_API_VERSION` (alap v23.0)
- Híd a weboldallal: `HPV_BRIDGE_SECRET` (mindkét oldalon ugyanaz, ≥16 karakter), a CRM-ben `HPV_SITE_URL`, a weboldalon `HPV_CRM_URL`
- Leads mu-plugin: `HPV_LEADS_THEME_ACTION` (alap `form_submit`), `HPV_LEADS_ATTRIBUTION` (false = nincs forrásmérő süti)
- Frissítő (a ti munkátok): `HPV_GITHUB_TOKEN`, `HPV_UPDATE_REPO`, `HPV_AUTO_UPDATE`

A CRM → Beállítások oldalon (admin) állítható: ismétlődő számlák módja, havi riport napja, fizetési emlékeztetők napjai,
óradíjak (USD, HUF), értékeléskérés (késleltetés, országok — alap csak US), értékesítés (alap felelős, válaszidő órában,
reggeli összefoglaló), Google összekapcsolása, QuickBooks összekapcsolása, videó webhook.

## Konvenciók és csapdák

- **Hivatkozás/int oszlop NOT NULL DEFAULT 0**: `0`-t írj, sosem `null`-t (MySQL-en elszáll, SQLite-on nem jön elő). Dátum lehet null.
- `hpv_p_find()` alapból **csökkenő** sorrendű.
- `hpv_p_sanitize()` kihagyja a `readonly` mezőket; a `hidden` mezők az űrlapokon nem jelennek meg.
- **Értékesítési mezők** (`lead_*`, `next_step*`, `lost_*`, `won_at`, `first_contact_at`, `sla_reminded_at`): csak a `hpv_sales_*` függvényekkel.
  Minden `lead` státuszú új ügyfél magától a tölcsérbe kerül (`hpv_p_inserted_client` hook); `lead` → `active` = megnyert.
- Adatréteg hookok: `hpv_p_inserted_{entity}( $id, $data )`, `hpv_p_updated_{entity}( $id, $data, $old )`.
- **i18n**: minden `hpv_t()` szövegnek kell magyar fordítás a `hpv_i18n_hu()`-ban; a `tests/i18n.php` ellenőrzi (a `HPV_METRIC_LABELS`-t
  és a `hpv_p_log_client` sablonokat is). A magyar szövegek **tegezők** (Áron kérése).
- A számlázási mezőket (számlázási név, adószám, cím, óradíj) és a bevételi számokat csak `hpv_invoices` joggal lehet látni/írni.
- Az ügyfél országa dönti el a pénznemet, a számlázót, a fizetést és a portál nyelvét. `hpv_p_client_country()` kérésen belül gyorsítótáraz;
  országváltáskor `hpv_p_client_country( $id, true )`.
- Napi ütemezés: a `hpv_retainer_cron` (reggel 6, WP-idő) hordozza a havi csomagokat, a jóváhagyás-emlékeztetőt, a riport-piszkozatot,
  a fizetési emlékeztetőt, az értékeléskérést és az értékesítési összefoglalót; `hpv_sales_hourly` a válaszidő-figyelést. Élesben valódi cron kell.
- **Leads mu-plugin**: a téma válaszát kimeneti puffer **visszahívással** kapja el, mert a WordPress a `shutdown`-kor minden puffert kiürít
  (`wp_ob_end_flush_all`) — ezt ne írd vissza sima `ob_get_contents()`-re. IP-nként óránként 5 kitöltés (tranziens). A mu-pluginok
  nem frissülnek maguktól, FTP-n kell cserélni.
- SQLite fejlesztői környezetben a `decimal` oszlopot (`money`) a dbDelta nem tudja utólag hozzáadni; ott kézzel kell. MySQL-en rendben van.

## Tesztek

```
php tests/seo.php && php tests/reviews.php && php tests/grader.php && php tests/i18n.php
wp eval-file tests/<x>-integration.php
```

- Az `automation-integration.php` olyan teszt-WP-t kér, ahol **nincs** `HPV_BRIDGE_SECRET` és nincs aktív Reviews bővítmény (a teszt maga definiálja).
- A `leads-integration.php` HTTP-s része csak `HPV_TEST_SITE_URL` környezeti változóval fut, és kell hozzá egy `form_submit` admin-ajax
  kezelő az oldalon (a téma, vagy fejlesztéskor egy mu-plugin utánzat, ami `wp_send_json_success()`-t ad).
- Minden külső szolgáltatást a tesztek `pre_http_request` szűrővel helyettesítenek.
- Utolsó futás (0.8.3, a ti ágatok beolvasztása után): minden csomag „Minden teszt sikeres”; a `mail-integration.php`-t nem futtattam
  (a levelezés bővítmény nem volt a fejlesztői WP-men).

## Nyitott kérdések Áronnak

- Levelezési szolgáltató (Google Workspace / Microsoft 365 / más) — a ti kérdésetek, továbbra is nyitott.
- Kulcsok és fiókok az élesítéshez (lásd konfiguráció); a Google Ads developer token jóváhagyása napokig-hetekig tart, érdemes korán igényelni.
- Kapcsolati űrlap: a téma valószínűleg most is küld levelet az info@ címre; ha a CRM levele elég, a témában kikapcsolható.

## Javasolt következő lépések (Áronnak már felvázolva)

1. **Élesítés és egy hónapos próba** 2–3 ügyféllel (egy USA, egy HU): telepítés, Bitrix24 import, Google/Meta összekötés,
   havi csomag → jóváhagyás → riport → számla egy teljes hónapon át.
2. **Rendszerállapot**: mikor futott utoljára minden automatizmus, szinkronhibák, várakozó leads-sor, lejáró tokenek.
   (Az SEO OS-ben már van „Rendszer állapot” oldal — érdemes oda bővíteni a CRM jeleit.)
3. **Levelezés ↔ tölcsér**: kimenő levél egy `new` érdeklődőnek → `hpv_sales_set_stage( $id, 'contacted', '', $user )`;
   „Felvétel érdeklődőként” gomb → `hpv_leads_ingest( [ 'source' => 'mail', … ] )` (a `mail` forráscímke már megvan).
4. **Ügyfelenkénti jövedelmezőség** (havi díj vs. rögzített órák önköltsége) és **lemorzsolódási jelzés**
   (késő jóváhagyás, lejárt számla, nincs belépés, eső mutatók).
5. Biztonság élesítés előtt: kétlépcsős belépés a `hpv_staff` szerepkörre, napi adatbázis- és `uploads/hpv-private` mentés, audit napló.
6. Később: GDPR export/törlés, időpontfoglalás, Teya API (automatikus magyar kártyás fizetés), a válaszidő-figyelmeztetés munkaidőhöz kötése.

## Áron döntései és preferenciái (ebből a chatből)

- Minden kommunikáció magyarul; a jelentés a végén: mi készült, mi a teendője, mi maradt nyitva.
- Szolgáltatások: web, SEO, helyi SEO, tartalom, hirdetés, közösségi — „mindegyik”. Portálon: havi riport és tartalom-jóváhagyás.
- Adatforrások: Search Console + GA4, Google Ads + Meta Ads, és az SEO OS.
- Értékeléskérés alapból csak amerikai ügyfeleknek (a Google-profil a floridai cégé).
- „Ami össze van kötve, az jól megy” — a meglévő integrációkat ne bontsd szét.
