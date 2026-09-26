# SEO OS ↔ ügyfélportál és CRM (helloprovision-portal 0.7)

Az SEO OS nem épít saját ügyfélportált. A jóváhagyásokat, a havi riportot, a feladatokat és az ügyfél-értesítéseket
a portál bővítmény adja. Ez a leírás a két rendszer közötti szerződés: mit hívhat az SEO OS, és mit kap vissza.

Két út van, ugyanazzal a tartalommal:

| Ha az SEO OS WordPress bővítménye… | Használd |
|---|---|
| ugyanazon a WordPressen fut, mint a CRM (`hpv_p_*` függvények elérhetők) | PHP függvények és WordPress hookok (lent) |
| másik szerveren fut (pl. a FastAPI közvetlenül) | REST: `https://crm.helloprovision.com/wp-json/hpv/v1/…`, WordPress **alkalmazásjelszóval** egy „Munkatárs (CRM)” szerepkörű technikai felhasználóhoz (Felhasználók → Profil → Alkalmazásjelszavak), HTTP Basic |

Az ügyfél azonosítója mindkét oldalon a CRM `client.id` (az SEO OS-ben `clients.crm_client_id`).

## 1. Jóváhagyás a portálon (kulcsszókutatás, struktúra, stratégia, wireframe, tartalom)

Az anyag a portál **Jóváhagyás / Approvals** menüjébe kerül. Az ügyfél jóváhagyja, vagy javítást kér (a javításkérésnél
kötelező megjegyzéssel), és hozzászólhat. A csapat a CRM app **Tartalom** oldalán is látja.

### Létrehozás vagy frissítés

Ugyanarra a `source` + `external_ref` párra **frissít**, nem hoz létre újat. Így az SEO OS egy dokumentum minden új
verzióját ugyanarra a jóváhagyásra küldheti.

```php
$approval = hpv_approval_upsert(
	array(
		'client_id'    => 12,                  // CRM ügyfél
		'project_id'   => 34,                  // nem kötelező: CRM projekt (ugyanahhoz az ügyfélhez)
		'task_id'      => 56,                  // nem kötelező: ez a feladat „Ügyfélre vár” lesz, jóváhagyáskor „Kész”
		'type'         => 'document',          // blog | social | gbp | ad | email | page | document | other
		'title'        => 'Kulcsszókutatás — kovacskert.hu',   // az ügyfél nyelvén
		'body'         => '<p>Rövid magyarázat az ügyfélnek…</p>', // HTML, megtisztítva tárolódik
		'link'         => 'https://seo.helloprovision.com/share/…', // előnézet vagy letöltés (PDF/XLSX)
		'file_ids'     => array( 78 ),         // nem kötelező: a CRM fájlmegosztásába feltöltött fájlok
		'publish_date' => null,
		'source'       => 'seo-os',
		'external_ref' => 'document-981',      // az SEO OS saját azonosítója
	),
	0,               // meglévő jóváhagyás CRM id-je, vagy 0
	$wp_user_id      // a műveletet végző munkatárs
);
if ( is_wp_error( $approval ) ) { /* üzenet: $approval->get_error_message() */ }

hpv_approval_send( (int) $approval['id'], $wp_user_id ); // e-mail az ügyfélnek, az ügyfél nyelvén
```

REST megfelelő:

```
POST /hpv/v1/content              {client_id, type, title, body, link, file_ids, source:"seo-os", external_ref}
POST /hpv/v1/content/{id}          ugyanezek a mezők (frissítés)
POST /hpv/v1/content/{id}/send     kiküldés jóváhagyásra
GET  /hpv/v1/content/{id}          állapot, előzmény (thread), csatolt fájlok
POST /hpv/v1/files                 multipart: file, client_id, project_id, visible=1, notify=0  → {id, url}
```

Szabályok:

- `[[TODO: …]]` jelöléssel az anyag nem küldhető ki.
- Jóváhagyott vagy megjelent anyag szövege nem írható át. Új kör: `POST /content/{id}/draft`, módosítás, újraküldés. A kör számát a rendszer lépteti.
- Kiküldéskor a csatolt fájlok az ügyfél számára láthatóvá válnak.
- 3 nap után egyszer emlékeztető megy az ügyfélnek.

### Értesítés a döntésről

```php
add_action( 'hpv_approval_decided', function ( array $approval, string $status, string $note ) {
	if ( 'seo-os' !== $approval['source'] ) {
		return;
	}
	// $approval['external_ref'] = az SEO OS dokumentum azonosítója
	// $status: 'approved' | 'changes'   $note: az ügyfél megjegyzése
	// → SEO OS: approvals tábla + projekt-állapotgép léptetése (client_review → approved, vagy vissza seo_review)
}, 10, 3 );
```

Másik szerveren futó SEO OS esetén ebből a hookból kell egy HMAC-kal aláírt webhookot küldeni az SEO OS API-nak. Ezt
a hookot az SEO OS WordPress bővítménye adja hozzá, a portál bővítményben nincs.

## 2. A havi riportba: helyezések és SEO-számok

A havi riport a `hpv_report_metrics` szűrőből gyűjti a számokat. A Search Console, a GA4, a Google Ads és a Meta
magától bekerül, ha az ügyfél adatlapján be van állítva. Az SEO OS ide adhatja a kulcsszó-helyezéseket vagy bármilyen
saját mutatót.

```php
add_filter( 'hpv_report_metrics', function ( array $metrics, array $client, string $period, string $prev_period ) {
	// $period = 'YYYY-MM' (a riport hónapja), $prev_period az előző hónap.
	$metrics[] = array(
		'section' => 'Keyword rankings',       // csoport; az angol címkéket a portál lefordítja (i18n), más szöveg változatlanul jelenik meg
		'key'     => 'seo_top10',
		'label'   => 'Keywords in the top 10',
		'value'   => 18,                       // a riport hónapja
		'prev'    => 14,                       // előző hónap (a változás ebből számolódik)
		'format'  => 'int',                    // int | pct | money | decimal | position
		'better'  => 'up',                     // up | down (pl. helyezés) | neutral (pl. költés)
		'history' => array( 9, 11, 12, 14, 14, 18 ), // nem kötelező: legfeljebb 6 hónap, régebbi elöl → mini grafikon
	);
	return $metrics;
}, 20, 4 );
```

A címkét vagy **az ügyfél nyelvén** add meg, vagy angolul, és vedd fel a fordítást a `includes/i18n.php`
szótárába. A `tests/i18n.php` a `HPV_METRIC_LABELS` listát ellenőrzi.

Hibát így jelezhetsz (a riport ettől elkészül, a hiba a CRM-ben látszik):
`add_filter( 'hpv_report_errors', fn( $e ) => array_merge( $e, array( 'SEO OS: a helyezések lekérése nem sikerült' ) ) );`

Az **elvégzett munka** a hónapban lezárt, ügyfél által látható CRM-feladatokból jön. Ha az SEO OS gyártási feladatai
CRM-feladatként futnak (lásd 3.), a riportban maguktól megjelennek.

## 3. Gyártási feladatok a CRM-be

Az SEO OS `includes/crm.php` már ezt csinálja. Két javítás kell hozzá:

- A hivatkozás-mezők (`assignee_id`, `project_id`, `parent_id`) `NOT NULL DEFAULT 0` oszlopok. Üres értéknél `0`-t kell írni, nem `null`-t (MySQL strict módban a `null` hibát dob). Konkrétan: `'assignee_id' => $assignee ?: 0`.
- Havidíjas (retainer) projektnél add meg a hónapot: `'period' => 'YYYY-MM'`. Így a feladat a projekt hónapszűrőjében és a havi riportban a helyes hónaphoz kerül.

Az ügyfél akkor látja a feladatot, ha `visible = 1`, és a projekt is látható. Az „Ügyfélre vár” (`client`) státusznál az ügyfél e-mailt kap.

## 4. Ügyfél-adatok

- Ügyféllista: `hpv_p_find( 'client', … )` vagy az SEO OS saját `/crm/clients` végpontja (már megvan).
- Az ügyfél nyelve: `hpv_doc_client_language( $client_id )` → `hu` | `en`. Az ügyfélnek szóló szöveg ezen a nyelven készüljön.
- A riport adatforrásai az ügyfélen: `gsc_property`, `ga4_property`, `gads_customer`, `meta_ad_account`. Ha az SEO OS is használ Search Console-t, ugyanezt a tulajdont olvassa.

## 5. Ami még nincs (javaslat a következő körre)

- Webhook a döntésekről egy másik szerveren futó SEO OS-nek (HMAC aláírás, újrapróbálás). Ma csak a PHP hook van.
- Kulcsszó-táblázat soronkénti megjegyzéssel a portálon. Ma a teljes dokumentumhoz lehet hozzászólni; a táblázat PDF/XLSX linkként vagy csatolt fájlként megy.
