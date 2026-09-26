<?php
/**
 * Az ügyfél felé menő szövegek nyelve: angol (USA) vagy magyar (magyar ügyfél).
 *
 * A kódban angolul írjuk: hpv_t( 'Pay now' ). Magyar nyelvnél a szótárból (hpv_i18n_hu) jön a fordítás;
 * ha hiányzik, angolul marad (a tests/i18n.php jelzi a hiányzót). A nyelvet a portál az ügyféltől veszi,
 * a levelek a címzett ügyféltől (hpv_with_lang).
 */

defined( 'ABSPATH' ) || exit;

function hpv_lang(): string {
	return $GLOBALS['hpv_lang'] ?? 'en';
}

function hpv_set_lang( string $lang ): void {
	$GLOBALS['hpv_lang'] = 'hu' === $lang ? 'hu' : 'en';
}

/**
 * Egy művelet adott nyelven (pl. levél egy magyar ügyfélnek), utána visszaáll az előző nyelv.
 */
function hpv_with_lang( string $lang, callable $fn ) {
	$prev = hpv_lang();
	hpv_set_lang( $lang );
	try {
		return $fn();
	} finally {
		hpv_set_lang( $prev );
	}
}

function hpv_with_client_lang( int $client_id, callable $fn ) {
	return hpv_with_lang( hpv_doc_client_language( $client_id ), $fn );
}

/**
 * Fordítás. Paraméterekkel sprintf-ként működik: hpv_t( 'Invoice %s', $number ).
 */
function hpv_t( string $en, ...$args ): string {
	$text = $en;
	if ( 'hu' === hpv_lang() ) {
		$dict = hpv_i18n_hu();
		$text = $dict[ $en ] ?? $en;
	}

	return $args ? vsprintf( $text, $args ) : $text;
}

/**
 * Egyes vagy többes szám (magyarul nincs többes szám a számnév után).
 */
function hpv_tn( string $one, string $many, int $n ): string {
	return hpv_t( 1 === $n ? $one : $many, $n );
}

/**
 * Dátum a nyelv szerint. $gmt: az adatbázisban UTC-ben tárolt időpont (pl. created_at).
 * Stílusok: date (Sep 26, 2026 / 2026. szept. 26.), long (September 26, 2026 / 2026. szeptember 26.),
 * short (Sep 26 / szept. 26.), datetime (… · 3:05 pm / … 15:05), full (Saturday, Sep 26, 2026 · 3:05 pm).
 */
function hpv_date( ?string $date, string $style = 'date', bool $gmt = false ): string {
	if ( ! $date || 0 === strpos( $date, '0000' ) ) {
		return '';
	}
	$local = $gmt ? get_date_from_gmt( $date, 'Y-m-d H:i:s' ) : $date;
	$ts    = strtotime( $local );
	if ( ! $ts ) {
		return '';
	}
	if ( 'hu' !== hpv_lang() ) {
		$formats = array(
			'date'     => 'M j, Y',
			'long'     => 'F j, Y',
			'short'    => 'M j',
			'datetime' => 'M j, Y · g:i a',
			'full'     => 'l, M j, Y · g:i a',
			'month'    => 'F Y',
		);

		return gmdate( $formats[ $style ] ?? $formats['date'], $ts );
	}

	$long  = array( 'január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december' );
	$short = array( 'jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.' );
	$days  = array( 'vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat' );
	$m     = (int) gmdate( 'n', $ts ) - 1;
	$y     = gmdate( 'Y', $ts );
	$d     = gmdate( 'j', $ts );
	$time  = gmdate( 'G:i', $ts );

	switch ( $style ) {
		case 'long':
			return "$y. {$long[ $m ]} $d.";
		case 'month':
			return "$y. {$long[ $m ]}";
		case 'short':
			return "{$short[ $m ]} $d.";
		case 'datetime':
			return "$y. {$short[ $m ]} $d. · $time";
		case 'full':
			return "$y. {$short[ $m ]} $d., " . $days[ (int) gmdate( 'w', $ts ) ] . " · $time";
		default:
			return "$y. {$short[ $m ]} $d.";
	}
}

/**
 * A bejelentkezés előtti oldal nyelve: ?lang=, a korábbi látogatás sütije, végül a böngésző nyelve.
 */
function hpv_guess_lang(): string {
	$param = sanitize_key( $_GET['lang'] ?? '' );
	if ( in_array( $param, array( 'en', 'hu' ), true ) ) {
		return $param;
	}
	$cookie = sanitize_key( $_COOKIE['hpv_lang'] ?? '' );
	if ( in_array( $cookie, array( 'en', 'hu' ), true ) ) {
		return $cookie;
	}

	return preg_match( '/^\s*hu\b/i', (string) ( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ) ) ? 'hu' : 'en';
}

/**
 * Megjegyezzük a nyelvet a következő bejelentkezéshez.
 */
function hpv_remember_lang( string $lang ): void {
	if ( headers_sent() || ( $_COOKIE['hpv_lang'] ?? '' ) === $lang ) {
		return;
	}
	setcookie( 'hpv_lang', $lang, array( 'expires' => time() + YEAR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
}

/**
 * Magyar szótár. Kulcs: az angol szöveg pontosan úgy, ahogy a kódban a hpv_t()-ben áll.
 */
function hpv_i18n_hu(): array {
	static $dict = null;
	if ( null !== $dict ) {
		return $dict;
	}

	$dict = array(
		// Keret, navigáció
		'Client Portal'                      => 'Ügyfélportál',
		'Overview'                           => 'Áttekintés',
		'Projects'                           => 'Projektek',
		'Messages'                           => 'Üzenetek',
		'Meetings'                           => 'Megbeszélések',
		'Proposals'                          => 'Ajánlatok',
		'Invoices'                           => 'Számlák',
		'Contracts'                          => 'Szerződések',
		'Files'                              => 'Fájlok',
		'Services'                           => 'Szolgáltatások',
		'Account'                            => 'Fiók',
		'Log out'                            => 'Kijelentkezés',
		'← Back'                             => '← Vissza',
		'Staff preview of %s — read only.'   => '%s előnézete (munkatárs) — csak olvasás.',
		'Back to CRM'                        => 'Vissza a CRM-be',
		'Staff preview: you do not have access to this section.' => 'Előnézet: ehhez a részhez nincs jogod.',

		// Bejelentkezés
		'Welcome back'                       => 'Üdv újra!',
		'Sign in to see your projects, invoices, contracts and messages.' => 'Jelentkezz be a projektjeid, számláid, szerződéseid és üzeneteid megtekintéséhez.',
		'Email or username'                  => 'E-mail cím vagy felhasználónév',
		'Password'                           => 'Jelszó',
		'Remember me'                        => 'Emlékezz rám',
		'Sign in'                            => 'Bejelentkezés',
		'Forgot your password?'              => 'Elfelejtetted a jelszavad?',
		'Need access? Email %s'              => 'Nincs hozzáférésed? Írj nekünk: %s',
		'Preview a client'                   => 'Ügyfél előnézete',
		'You are signed in as staff. Choose a client to preview their portal (read only).' => 'Munkatársként vagy bejelentkezve. Válassz ügyfelet a portálja megtekintéséhez (csak olvasás).',
		'No portal access yet'               => 'Még nincs portál-hozzáférésed',
		'Your account is not linked to a company. Please contact us at %s.' => 'A fiókod még nincs céghez rendelve. Írj nekünk: %s.',
		'Magyar'                             => 'Magyar',
		'English'                            => 'English',

		// Áttekintés
		'Hi %s'                              => 'Szia, %s!',
		'Video call in progress: %s'         => 'Folyamatban lévő videóhívás: %s',
		'Join now'                           => 'Csatlakozás most',
		'Review proposal: %s'                => 'Ajánlat átnézése: %s',
		'Valid until %s'                     => 'Érvényes: %s',
		'Pay invoice %s — %s'                => '%s számla fizetése — %s',
		'Overdue since %s'                   => 'Lejárt: %s',
		'Due %s'                             => 'Határidő: %s',
		'Review & sign: %s'                  => 'Átnézés és aláírás: %s',
		'Awaiting your signature'            => 'Aláírásodra vár',
		' · due %s'                          => ' · határidő: %s',
		'%d unread message'                  => '%d olvasatlan üzenet',
		'%d unread messages'                 => '%d olvasatlan üzenet',
		'From your HelloProVision team'      => 'A HelloProVision csapattól',
		'New file: %s'                       => 'Új fájl: %s',
		'Balance due'                        => 'Fizetendő',
		'Active projects'                    => 'Aktív projektek',
		'Active services'                    => 'Aktív szolgáltatások',
		'Unread messages'                    => 'Olvasatlan üzenetek',
		'Needs your attention'               => 'Teendőid',
		"You're all caught up. Nothing needs your attention right now." => 'Minden rendben, most nincs teendőd.',
		'No active projects.'                => 'Nincs aktív projekt.',
		'%d%% complete'                      => '%d%% kész',
		'Recent updates'                     => 'Legutóbbi események',
		'Updates from our team will appear here.' => 'Itt jelennek meg a csapatunk frissítései.',

		// Projektek
		'What we are working on for you.'    => 'Amin éppen dolgozunk nektek.',
		'No projects yet.'                   => 'Még nincs projekt.',
		'Project not found'                  => 'A projekt nem található',
		'Status'                             => 'Állapot',
		'Start'                              => 'Kezdés',
		'Due'                                => 'Határidő',
		'Progress'                           => 'Haladás',
		'Month'                              => 'Hónap',

		// Státuszok (a séma angol címkéi)
		'Planning'                           => 'Tervezés',
		'In progress'                        => 'Folyamatban',
		'Awaiting your review'               => 'Jóváhagyásodra vár',
		'Completed'                          => 'Kész',
		'On hold'                            => 'Szünetel',
		'To do'                              => 'Teendő',
		'In review'                          => 'Ellenőrzés alatt',
		'Waiting on you'                     => 'Rád vár',
		'Done'                               => 'Kész',
		'Draft'                              => 'Piszkozat',
		'Paid'                               => 'Fizetve',
		'Void'                               => 'Érvénytelen',
		'Overdue'                            => 'Lejárt',
		'Awaiting signature'                 => 'Aláírásra vár',
		'Signed'                             => 'Aláírva',
		'Active'                             => 'Aktív',
		'Paused'                             => 'Szünetel',
		'Cancelled'                          => 'Lemondva',
		'One-time'                           => 'Egyszeri',
		'Monthly'                            => 'Havonta',
		'Quarterly'                          => 'Negyedévente',
		'Yearly'                             => 'Évente',
		'Accepted'                           => 'Elfogadva',
		'Declined'                           => 'Elutasítva',
		'Expired'                            => 'Lejárt',
		'Live now'                           => 'Most élő',

		// Számlák
		'No invoices yet.'                   => 'Még nincs számla.',
		'Invoice'                            => 'Számla',
		'Issued'                             => 'Kiállítva',
		'Amount'                             => 'Összeg',
		'Invoice not found'                  => 'A számla nem található',
		'← All invoices'                     => '← Összes számla',
		'Download invoice (PDF)'             => 'Számla letöltése (PDF)',
		'Download PDF'                       => 'Letöltés PDF-ben',
		'Pay now · %s'                       => 'Fizetés most · %s',
		'Payment received — thank you! A receipt is on its way to your inbox.' => 'Megkaptuk a befizetést, köszönjük! A nyugtát e-mailben küldjük.',
		'Thank you! Your payment is being processed; this page will show it as paid within a few minutes.' => 'Köszönjük! A fizetés feldolgozása folyamatban van, néhány percen belül itt is fizetettként látszik.',
		'This is a summary. The official invoice is the PDF issued by Számlázz.hu (also sent to you by email).' => 'Ez egy összesítő. A hivatalos számla a Számlázz.hu által kiállított PDF (e-mailben is megkaptad).',
		'Bill to'                            => 'Vevő',
		'Description'                        => 'Megnevezés',
		'Qty'                                => 'Menny.',
		'Unit price'                         => 'Egységár',
		'Subtotal'                           => 'Nettó összesen',
		'VAT (%s)'                           => 'ÁFA (%s)',
		'Tax (%s%%)'                         => 'Adó (%s%%)',
		'Total'                              => 'Összesen',

		// Szerződések
		'Agreements, proposals and documents to sign.' => 'Szerződések és aláírandó dokumentumok.',
		'No documents yet.'                  => 'Még nincs dokumentum.',
		'Signed %s by %s'                    => 'Aláírta: %2$s, %1$s',
		'Sent %s'                            => 'Elküldve: %s',
		'Document not found'                 => 'A dokumentum nem található',
		'← All documents'                    => '← Összes dokumentum',
		'Thank you — your signature is recorded and a copy was emailed to you.' => 'Köszönjük, az aláírásod rögzítettük, és egy példányt e-mailben is elküldtünk.',
		'Signed electronically by %s (%s)'   => 'Elektronikusan aláírta: %s (%s)',
		'Document fingerprint (SHA-256):'    => 'A dokumentum ujjlenyomata (SHA-256):',
		'Sign this document'                 => 'A dokumentum aláírása',
		'Type your full name'                => 'Írd be a teljes neved',
		'I have read this document and agree to sign it electronically. I understand my electronic signature is legally binding, the same as a handwritten signature.' => 'Elolvastam a dokumentumot, és elektronikusan aláírom. Tudomásul veszem, hogy az elektronikus aláírásom ugyanúgy kötelez, mint a kézi aláírás.',
		'Sign document'                      => 'Aláírás',
		"Awaiting the client's signature."   => 'Az ügyfél aláírására vár.',
		'Staff cannot sign on behalf of a client.' => 'Munkatárs nem írhat alá az ügyfél nevében.',
		'Please confirm that you agree to sign electronically.' => 'Kérjük, erősítsd meg, hogy elektronikusan aláírod.',
		'This contract is not available for signature.' => 'Ez a szerződés nem írható alá.',
		'Please type your full name to sign.' => 'Az aláíráshoz írd be a teljes neved.',

		// Szolgáltatások, fiók
		'Your active plans with us.'         => 'Nálunk futó szolgáltatásaid.',
		'No active services.'                => 'Nincs aktív szolgáltatás.',
		'Next invoice %s'                    => 'Következő számla: %s',
		'Talk with your HelloProVision team.' => 'Beszélgess a HelloProVision csapattal.',
		'Company'                            => 'Cég',
		'Name'                               => 'Név',
		'Email'                              => 'E-mail',
		'Phone'                              => 'Telefon',
		'Billing address'                    => 'Számlázási cím',
		'Tax number'                         => 'Adószám',
		"Need to change something? Send us a message and we'll update it." => 'Változott valami? Írj nekünk, és frissítjük.',
		'Your login'                         => 'Bejelentkezés',
		'Username'                           => 'Felhasználónév',
		'Change password'                    => 'Jelszó módosítása',
		'People with access'                 => 'Hozzáféréssel rendelkezők',

		// Megbeszélések
		'Video calls with your team, with a written summary of what we agreed.' => 'Videóhívások a csapattal, írásos összefoglalóval arról, amiben megállapodtunk.',
		'No meetings yet. When we start a video call with you, it appears here and you get an email with the link.' => 'Még nem volt megbeszélés. Ha videóhívást indítunk veled, itt jelenik meg, és e-mailben is elküldjük a linket.',
		'Join call'                          => 'Csatlakozás',
		'Meeting not found'                  => 'A megbeszélés nem található',
		'Connecting…'                        => 'Kapcsolódás…',
		'This call is being recorded and transcribed. Use the Leave button in the call to hang up.' => 'A hívást rögzítjük és leírjuk. A bontáshoz használd a hívásban a Kilépés gombot.',
		'We could not start the video. Please reload the page, or allow camera and microphone access.' => 'Nem sikerült elindítani a videót. Töltsd újra az oldalt, vagy engedélyezd a kamerát és a mikrofont.',
		'Staff preview: the client joins here after agreeing to the recording.' => 'Előnézet: az ügyfél itt csatlakozik, miután hozzájárult a rögzítéshez.',
		'Join the video call'                => 'Csatlakozás a videóhíváshoz',
		'We record and transcribe this call so we can send you a written summary of what we agreed. You can turn your camera off at any time.' => 'A hívást rögzítjük és leírjuk, hogy írásban elküldhessük, miben állapodtunk meg. A kamerádat bármikor kikapcsolhatod.',
		'I agree that this video call is recorded and transcribed, and that %s keeps the recording, the transcript and an AI-generated summary. (Florida law requires everyone\'s consent to record a conversation.)' => 'Hozzájárulok, hogy a videóhívást rögzítsék és leírják, és hogy a(z) %s megőrizze a felvételt, a leiratot és az AI által készített összefoglalót.',
		'This call has ended.'               => 'A hívás véget ért.',
		'We could not connect you to the call. Please try again or message us.' => 'Nem sikerült csatlakozni a híváshoz. Próbáld újra, vagy írj nekünk.',
		'Please confirm that you agree to the recording before joining.' => 'Csatlakozás előtt kérjük, járulj hozzá a rögzítéshez.',
		'This meeting has ended. A summary will appear here once your team shares it.' => 'A megbeszélés véget ért. Az összefoglaló itt jelenik meg, amint a csapat megosztja.',
		'Summary'                            => 'Összefoglaló',
		'What we agreed'                     => 'Amiben megállapodtunk',
		'Your next steps'                    => 'A te teendőid',
		'Nothing for you to do.'             => 'Nincs teendőd.',
		"What we'll do"                      => 'A mi teendőink',
		'No follow-ups for our team.'        => 'Nincs teendő a csapatnak.',

		// Ajánlatok
		'Proposals from your HelloProVision team.' => 'A HelloProVision csapat ajánlatai.',
		'No proposals yet.'                  => 'Még nincs ajánlat.',
		' · valid until %s'                  => ' · érvényes: %s',
		'%s / month'                         => '%s / hó',

		// Fizetés
		'We could not open the payment page. Please try again in a minute or message us.' => 'Nem sikerült megnyitni a fizetőoldalt. Próbáld újra egy perc múlva, vagy írj nekünk.',

		// Napló (az ügyfél is látja)
		'"%s" is ready for your signature.'  => '„%s” aláírásra vár.',
		'Invoice %s issued: %s.'             => '%s számla kiállítva: %s.',
		'Payment of %s received for invoice %s. Thank you!' => '%s befizetés érkezett a(z) %s számlára. Köszönjük!',
		'Action needed: %s (%s)'             => 'Teendő: %s (%s)',
		'"%s" was signed by %s.'             => '„%s” dokumentumot aláírta: %s.',
		'%s shared a file: %s'               => '%s megosztott egy fájlt: %s',
		'%s uploaded a file: %s'             => '%s feltöltött egy fájlt: %s',

		// Levelek
		'Invoice %s from %s'                 => '%s számla – %s',
		'Invoice %s'                         => '%s számla',
		'A new invoice is ready in your client portal.' => 'Új számla érhető el az ügyfélportálodon.',
		'Amount due:'                        => 'Fizetendő:',
		'Due date:'                          => 'Fizetési határidő:',
		'upon receipt'                       => 'azonnal',
		'View & pay invoice'                 => 'Számla megtekintése és fizetés',
		'Please review and sign: %s'         => 'Átnézésre és aláírásra: %s',
		'A document is waiting for your review and signature in your client portal.' => 'Egy dokumentum vár az átnézésedre és aláírásodra az ügyfélportálon.',
		'Review & sign'                      => 'Átnézés és aláírás',
		'Signed: %s'                         => 'Aláírva: %s',
		'Thank you — your signature is recorded' => 'Köszönjük, az aláírásodat rögzítettük',
		'You signed <strong>%s</strong> on %s (UTC). A copy is always available in your client portal.' => 'Aláírtad: <strong>%s</strong>, %s (UTC). Egy példány mindig elérhető az ügyfélportálon.',
		'View signed document'               => 'Aláírt dokumentum megtekintése',
		'Your %s client portal'              => 'A(z) %s ügyfélportálod',
		'Welcome to your client portal'      => 'Üdv az ügyfélportálon!',
		'Hi %s,'                             => 'Kedves %s!',
		'there'                              => 'Ügyfelünk',
		"We've set up a client portal for <strong>%s</strong>. You can see your projects, invoices, contracts and services, and message our team — all in one place." => 'Ügyfélportált hoztunk létre a(z) <strong>%s</strong> részére. Itt egy helyen látod a projektjeidet, számláidat, szerződéseidet és szolgáltatásaidat, és üzenhetsz a csapatunknak.',
		'Click below to set your password. Your username is <strong>%s</strong>.' => 'Kattints lent a jelszavad beállításához. A felhasználóneved: <strong>%s</strong>.',
		'Set your password'                  => 'Jelszó beállítása',
		'New message from %s'                => 'Új üzenet tőle: %s',
		'%s sent you a message'              => '%s üzenetet küldött',
		'Reply in your portal'               => 'Válasz a portálon',
		'Action needed: %s'                  => 'Teendő: %s',
		'We need something from you'         => 'Szükségünk van valamire tőled',
		'<strong>%s</strong> in <em>%s</em> is waiting on you%s.' => 'A(z) <em>%2$s</em> projektben a(z) <strong>%1$s</strong> rád vár%3$s.',
		' — due %s'                          => ' — határidő: %s',
		'Open project'                       => 'Projekt megnyitása',
		'Video call: %s'                     => 'Videóhívás: %s',
		'Your video call is starting'        => 'Indul a videóhívás',
		'%s from %s started a video call with you: <strong>%s</strong>.' => '%s (%s) videóhívást indított veled: <strong>%s</strong>.',
		'Join from your client portal. The call is recorded and transcribed so we can send you a summary; you will be asked to agree before joining.' => 'Az ügyfélportálon tudsz csatlakozni. A hívást rögzítjük és leírjuk, hogy összefoglalót küldhessünk; csatlakozás előtt a hozzájárulásodat kérjük.',
		'Join the call'                      => 'Csatlakozás a híváshoz',
		'Video call started: %s'             => 'Videóhívás indult: %s',
		'Join here: %s'                      => 'Csatlakozás: %s',
		'Summary of our call: %s'            => 'A hívásunk összefoglalója: %s',
		'Here is a summary of our call'      => 'A hívásunk összefoglalója',
		'View meeting summary'               => 'Összefoglaló megtekintése',
		'Payment received: invoice %s'       => 'Befizetés megérkezett: %s számla',
		'Thank you for your payment'         => 'Köszönjük a befizetést',
		'We received %s for invoice %s.'     => 'Megkaptuk a(z) %2$s számlára a(z) %1$s összeget.',
		'Remaining balance: %s'              => 'Fennmaradó tartozás: %s',
		'The invoice is now paid in full.'   => 'A számla teljesen kifizetve.',
		'View invoice'                       => 'Számla megtekintése',
		'%s shared a file with you: <strong>%s</strong>' => '%s megosztott veled egy fájlt: <strong>%s</strong>',
		'Open files'                         => 'Fájlok megnyitása',

		// Jóváhagyások
		'Approvals'                          => 'Jóváhagyás',
		'Content and documents waiting for your OK, and what you approved before.' => 'A jóváhagyásodra váró tartalmak és dokumentumok, és amit korábban jóváhagytál.',
		'Nothing to approve yet.'            => 'Most nincs jóváhagyandó anyag.',
		'Not found'                          => 'Nem található',
		'Awaiting your approval'             => 'Jóváhagyásodra vár',
		'Changes requested'                  => 'Javítást kértél',
		'Approved'                           => 'Jóváhagyva',
		'Approved.'                          => 'Jóváhagyta.',
		'Published'                          => 'Megjelent',
		'Blog post'                          => 'Blogcikk',
		'Social post'                        => 'Közösségi poszt',
		'Business Profile post'              => 'Cégprofil poszt',
		'Ad'                                 => 'Hirdetés',
		'Web page'                           => 'Weboldal',
		'Document'                           => 'Dokumentum',
		'Other'                              => 'Egyéb',
		'Planned publish date: %s'           => 'Tervezett megjelenés: %s',
		'Thank you — approved. We will take it from here.' => 'Köszönjük, jóváhagytad. Innen mi visszük tovább.',
		'Thank you — we got your notes and will send a new version.' => 'Köszönjük, megkaptuk a megjegyzéseidet, és küldjük a javított változatot.',
		'Version %d'                         => '%d. változat',
		'Open preview'                       => 'Előnézet megnyitása',
		'Your decision'                      => 'A döntésed',
		'Comment (required if you request changes)' => 'Megjegyzés (javításkérésnél kötelező)',
		'Approve'                            => 'Jóváhagyom',
		'Request changes'                    => 'Javítást kérek',
		'Staff preview: the client decides here.' => 'Előnézet: itt dönt az ügyfél.',
		'History and comments'               => 'Előzmények és hozzászólások',
		'Sent for approval.'                 => 'Jóváhagyásra küldve.',
		'Requested changes.'                 => 'Javítást kért.',
		'Add a comment or question…'         => 'Megjegyzés vagy kérdés…',
		'Send'                               => 'Küldés',
		'This item is no longer waiting for your approval.' => 'Ez az anyag már nem vár a jóváhagyásodra.',
		'Please describe what should change.' => 'Kérjük, írd le, min változtassunk.',
		'Reminder: '                         => 'Emlékeztető: ',
		'Please review: %s'                  => 'Jóváhagyásra vár: %s',
		'Ready for your approval'            => 'Jóváhagyásodra vár',
		'Approve it or request changes in your client portal. You can leave a comment either way.' => 'Az ügyfélportálon jóváhagyhatod, vagy javítást kérhetsz; mindkét esetben írhatsz megjegyzést.',
		'Review now'                         => 'Megnézem',
		'Ready for your approval: %s'        => 'Jóváhagyásra vár: %s',
		'%s approved: %s'                    => '%s jóváhagyta: %s',
		'%s requested changes: %s'           => '%s javítást kért: %s',
		'Approve: %s'                        => 'Jóváhagyás: %s',

		// Riportok
		'Reports'                            => 'Riportok',
		'Your monthly results: numbers, work done and next steps.' => 'A havi eredményeid: számok, elvégzett munka és a következő lépések.',
		'Your first monthly report will appear here.' => 'Itt jelenik meg az első havi riportod.',
		'Monthly report'                     => 'Havi riport',
		'Monthly report — %s'                => 'Havi riport — %s',
		'← All reports'                      => '← Összes riport',
		'Results'                            => 'Eredmények',
		'vs. previous month'                 => 'az előző hónaphoz képest',
		'What we did this month'             => 'Amit ebben a hónapban csináltunk',
		'Next month'                         => 'Jövő hónapban',
		'Published content'                  => 'Megjelent tartalmak',
		'Questions about the report? Send us a message in the portal.' => 'Kérdésed van a riporttal kapcsolatban? Írj nekünk a portálon.',
		'Your monthly report: %s'            => 'Havi riport: %s',
		'Your monthly report is ready'       => 'Elkészült a havi riportod',
		'Here is what happened in %s and what comes next.' => 'Így alakult a(z) %s, és ez jön most.',
		'Open the report'                    => 'Riport megnyitása',
		'Monthly report sent: %s'            => 'Havi riport elküldve: %s',
		'New monthly report: %s'             => 'Új havi riport: %s',
		'Numbers, work done and next steps'  => 'Számok, elvégzett munka, következő lépések',

		// Riport mutatók (connectors.php: HPV_METRIC_LABELS)
		'Google Search'                      => 'Google keresés',
		'Clicks from Google'                 => 'Kattintás a Google-ből',
		'Impressions in Google'              => 'Megjelenés a Google-ben',
		'Click-through rate'                 => 'Átkattintási arány',
		'Average position'                   => 'Átlagos helyezés',
		'Website'                            => 'Weboldal',
		'Visitors'                           => 'Látogatók',
		'Sessions'                           => 'Munkamenetek',
		'Visits from Google search'          => 'Látogatás a Google-keresésből',
		'Key events (leads, calls, forms)'   => 'Fontos események (ajánlatkérés, hívás, űrlap)',
		'Google Ads'                         => 'Google Ads',
		'Ad spend'                           => 'Hirdetési költés',
		'Ad clicks'                          => 'Kattintás a hirdetésekre',
		'Conversions'                        => 'Konverziók',
		'Cost per conversion'                => 'Költség / konverzió',
		'Meta Ads'                           => 'Meta hirdetések',
		'Impressions'                        => 'Megjelenések',
		'Link clicks'                        => 'Linkkattintások',
		'Leads'                              => 'Érdeklődők',
		'Cost per lead'                      => 'Költség / érdeklődő',

		// Fájlok
		'Documents and assets shared between you and our team.' => 'A köztünk megosztott dokumentumok és anyagok.',
		'No files yet.'                      => 'Még nincs fájl.',
		'Upload a file'                      => 'Fájl feltöltése',
		'Upload'                             => 'Feltöltés',
		'Project (optional)'                 => 'Projekt (nem kötelező)',
		'— General —'                        => '— Általános —',
		'Note (optional)'                    => 'Megjegyzés (nem kötelező)',
		'Max. %s per file.'                  => 'Legfeljebb %s fájlonként.',
		'File'                               => 'Fájl',
		'Size'                               => 'Méret',
		'Added'                              => 'Hozzáadva',
		'From'                               => 'Feladó',
		'Download'                           => 'Letöltés',
		'Delete'                             => 'Törlés',
		'Delete this file?'                  => 'Törlöd a fájlt?',
		'File uploaded. Our team has been notified.' => 'Feltöltve, a csapatot értesítettük.',
		'File deleted.'                      => 'Fájl törölve.',
		'Please choose a file.'              => 'Válassz egy fájlt.',
		'This file is too large (max. %s).'  => 'A fájl túl nagy (legfeljebb %s).',
		'This file type is not allowed.'     => 'Ez a fájltípus nem engedélyezett.',
		'The upload failed. Please try again.' => 'A feltöltés nem sikerült, próbáld újra.',
		'You can only delete files you uploaded.' => 'Csak a saját feltöltésedet törölheted.',
		'File not found.'                    => 'A fájl nem található.',
		'our team'                           => 'csapatunk',
		'you'                                => 'te',
	);

	return $dict;
}
