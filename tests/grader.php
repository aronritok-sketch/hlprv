<?php
/**
 * WordPress nélkül futtatható teszt: php tests/grader.php
 *
 * fixtures/home.html: a helloprovision.com főoldala (2026-09, admin sáv és szkriptek nélkül).
 * fixtures/psi-mobile.json: PageSpeed Insights v5 mobil válasz szerkezete.
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/plugins/helloprovision-grader/helloprovision-grader.php';

function by_id( array $checks, string $id ) {
	foreach ( $checks as $check ) {
		if ( $check['id'] === $id ) {
			return $check;
		}
	}
	return null;
}

function page( string $body, string $head = '' ): string {
	return '<!doctype html><html><head>' . $head . '</head><body>' . $body . '</body></html>';
}

echo "URL normalizálás\n";
check( 'domain → https', 'https://example.com/' === hpv_grader_normalize_url( 'example.com' ) );
check( 'útvonal és query megmarad', 'https://example.com/services/?a=1' === hpv_grader_normalize_url( 'https://Example.com/services/?a=1' ) );
check( 'http megmarad', 'http://example.com/' === hpv_grader_normalize_url( 'http://example.com' ) );
check( 'fragment eldobva', 'https://example.com/page' === hpv_grader_normalize_url( 'example.com/page#top' ) );
check( 'ftp elutasítva', '' === hpv_grader_normalize_url( 'ftp://example.com' ) );
check( 'IP cím elutasítva', '' === hpv_grader_normalize_url( 'http://127.0.0.1/' ) );
check( 'localhost elutasítva', '' === hpv_grader_normalize_url( 'localhost' ) );
check( 'felhasználónév elutasítva', '' === hpv_grader_normalize_url( 'https://user:pass@example.com' ) );
check( 'szóköz elutasítva', '' === hpv_grader_normalize_url( 'my site.com' ) );
check( 'javascript: elutasítva', '' === hpv_grader_normalize_url( 'javascript:alert(1)' ) );

echo "Segédfüggvények\n";
check( 'telefonszám formátumok', array( '2399551655', '2395550123' ) === hpv_grader_find_phones( 'Call (239) 955-1655 or +1 239.555.0123 today' ) );
check( 'irányítószám, dátum nem telefonszám', array() === hpv_grader_find_phones( 'Founded 2019, FL 33907, order #12345678901234' ) );
check( 'tel: szám normalizálás', '2399551655' === hpv_grader_phone_digits( '+1-239-955-1655' ) );
check( 'utcacím felismerés', hpv_grader_has_street_address( 'Visit us: 12557 New Brittany Blvd, Suite 3 Fort Myers, FL 33907' ) );
check( 'rövidített cím', hpv_grader_has_street_address( '1415 SE 47th St, Cape Coral, FL 33904' ) );
check( 'szöveg cím nélkül', ! hpv_grader_has_street_address( 'We serve Fort Myers, FL and Naples.' ) );
check( '"Fort Myers Beach" nem számít "Fort Myers"-nek is', array( 'Fort Myers Beach' ) === hpv_grader_find_places( 'Bars in Fort Myers Beach' ) );

echo "HelloProVision főoldal\n";
$ctx    = array(
	'url'     => 'https://helloprovision.com/',
	'headers' => array(),
	'time'    => 0.62,
	'robots'  => array(
		'found' => true,
		'body'  => "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://helloprovision.com/sitemap_index.xml\n",
	),
	'sitemap' => true,
);
$checks = hpv_grader_analyze_page( file_get_contents( __DIR__ . '/fixtures/home.html' ), $ctx );
check( 'minden kategóriában van ellenőrzés', array( 'local', 'mobile', 'schema', 'seo', 'speed' ) === array_values( array_unique( array_map( fn( $c ) => $c['category'], $checks ) ) ) || 5 === count( array_unique( array_column( $checks, 'category' ) ) ) );
check( 'csonka H1 figyelmeztetés', 'warn' === by_id( $checks, 'seo_h1' )['status'] );
check( 'csak régió a címben → figyelmeztetés', 'warn' === by_id( $checks, 'local_city' )['status'] );
check( 'Yoast @id címhivatkozás feloldva (schema NAP rendben)', 'pass' === by_id( $checks, 'schema_nap' )['status'] );
check( 'ProfessionalService helyi típus', 'pass' === by_id( $checks, 'schema_business' )['status'] );
check( 'telefon egyezik schema és oldal között', 'pass' === by_id( $checks, 'local_nap_match' )['status'] );
check( 'utcacím az oldalon', 'pass' === by_id( $checks, 'local_address' )['status'] );
check( 'nincs Google-profil link', 'warn' === by_id( $checks, 'local_google' )['status'] );
check( 'tap-to-call', 'pass' === by_id( $checks, 'mobile_tel' )['status'] );
check( 'robots.txt nem tilt', 'pass' === by_id( $checks, 'seo_robots' )['status'] );
check( 'minden nem-pass ellenőrzésnek van javaslata', ! array_filter( $checks, fn( $c ) => 'pass' !== $c['status'] && '' === $c['fix'] ) );
check( 'pass ellenőrzésnek nincs javaslata', ! array_filter( $checks, fn( $c ) => 'pass' === $c['status'] && '' !== $c['fix'] ) );

echo "Rossz oldal\n";
$bad = hpv_grader_analyze_page(
	page( '<h1></h1><p>Welcome to our site.</p><img src="a.jpg"><img src="b.jpg">', '<title>Home</title><meta name="robots" content="noindex,follow">' ),
	array(
		'url'     => 'http://example.com/',
		'headers' => array(),
		'time'    => 2.4,
		'robots'  => array(
			'found' => true,
			'body'  => "User-agent: *\nDisallow: /\n",
		),
		'sitemap' => false,
	)
);
foreach ( array( 'seo_h1', 'seo_indexable', 'seo_https', 'seo_description', 'seo_robots', 'schema_present', 'schema_business', 'local_phone', 'local_city', 'mobile_viewport', 'mobile_tel', 'speed_server', 'seo_alt' ) as $id ) {
	check( "$id → fail", 'fail' === by_id( $bad, $id )['status'] );
}
check( 'rövid cím → warn', 'warn' === by_id( $bad, 'seo_title' )['status'] );
check( 'sitemap hiány → warn', 'warn' === by_id( $bad, 'seo_sitemap' )['status'] );
$sab = hpv_grader_analyze_page(
	page( '<h1>Plumbing in Cape Coral</h1><p>Call 239-555-0123</p>', '<title>Plumber in Cape Coral, FL | Acme</title><script type="application/ld+json">{"@type":"Organization","name":"Acme","telephone":"(239) 555-0199"}</script><script type="application/ld+json">{bad json</script>' ),
	array(
		'url'     => 'https://acme.test/',
		'headers' => array( 'x-robots-tag' => 'noindex' ),
		'time'    => 0.3,
	)
);
check( 'X-Robots-Tag noindex felismerve', 'fail' === by_id( $sab, 'seo_indexable' )['status'] );
check( 'csak Organization → warn', 'warn' === by_id( $sab, 'schema_business' )['status'] );
check( 'hibás JSON-LD blokk → warn', 'warn' === by_id( $sab, 'schema_present' )['status'] );
check( 'eltérő telefonszám → fail', 'fail' === by_id( $sab, 'local_nap_match' )['status'] );
check( 'város a címben → pass', 'pass' === by_id( $sab, 'local_city' )['status'] );
check( 'szám link nélkül → warn', 'warn' === by_id( $sab, 'mobile_tel' )['status'] );
check( 'robots/sitemap adat nélkül nincs ellenőrzés', null === by_id( $sab, 'seo_robots' ) && null === by_id( $sab, 'seo_sitemap' ) );

echo "PageSpeed\n";
$psi = hpv_grader_psi_checks( json_decode( file_get_contents( __DIR__ . '/fixtures/psi-mobile.json' ), true ) );
check( '5 sebesség ellenőrzés', 5 === count( $psi ) );
check( 'pontszám 47 → fail', 'fail' === by_id( $psi, 'speed_score' )['status'] && false !== strpos( by_id( $psi, 'speed_score' )['detail'], '47 / 100' ) );
check( 'LCP 4.6 s → fail, valós adat is', 'fail' === by_id( $psi, 'speed_lcp' )['status'] && false !== strpos( by_id( $psi, 'speed_lcp' )['detail'], 'Real visitors: 3.1 s' ) );
check( 'CLS rendben', 'pass' === by_id( $psi, 'speed_cls' )['status'] );
check( 'TBT 742 ms → fail', 'fail' === by_id( $psi, 'speed_tbt' )['status'] );
check( '3.3 MB → warn', 'warn' === by_id( $psi, 'speed_weight' )['status'] );
check( 'hibás válasz → nincs ellenőrzés', array() === hpv_grader_psi_checks( array( 'error' => array( 'code' => 429 ) ) ) );

echo "Pontozás\n";
$report = hpv_grader_score_report(
	array(
		'id'         => str_repeat( 'a', 20 ),
		'url'        => 'https://helloprovision.com/',
		'host'       => 'helloprovision.com',
		'psi_status' => 'pending',
		'checks'     => $checks,
	)
);
check( 'sebesség függőben', true === $report['categories']['speed']['pending'] && null === $report['categories']['speed']['score'] );
check( 'összpontszám a többi kategóriából', $report['overall'] > 0 && $report['overall'] <= 100 );
$partial = $report['overall'];
$report  = hpv_grader_apply_psi( $report, $psi );
check( 'PageSpeed után a sebesség is számít', 'done' === $report['psi_status'] && is_int( $report['categories']['speed']['score'] ) );
check( 'lassú oldal lehúzza az összpontszámot', $report['overall'] < $partial );
check( 'szerver ellenőrzés megmaradt', null !== by_id( $report['checks'], 'speed_server' ) );
check( 'kétszeri alkalmazás nem duplikál', count( $report['checks'] ) === count( hpv_grader_apply_psi( $report, $psi )['checks'] ) );
$failed = hpv_grader_apply_psi( hpv_grader_score_report( array( 'psi_status' => 'pending', 'checks' => $checks ) ), array() );
check( 'sikertelen mérés: csak a szerveridő számít', 'failed' === $failed['psi_status'] && 100 === $failed['categories']['speed']['score'] );
check( 'minősítés', 'Excellent' === hpv_grader_grade( 90 ) && 'Critical' === hpv_grader_grade( 40 ) );

echo "Zárolás\n";
$report['lead_id']       = 42;
$report['unlock_token']  = 'secret-token';
$report['email_pending'] = true;
$locked                  = hpv_grader_public_report( $report, false );
$json                    = json_encode( $locked );
$with_fix                = array_filter( $locked['checks'], fn( $c ) => '' !== $c['fix'] );
check( 'zárolva csak egy javaslat látszik (minta)', 1 === count( $with_fix ) );
check( 'a minta a legfontosabb hiba', array_values( $with_fix )[0]['top'] && 'fail' === array_values( $with_fix )[0]['status'] );
check( 'a többi hiba zárolva', count( array_filter( $locked['checks'], fn( $c ) => ! empty( $c['locked'] ) ) ) === count( array_filter( $report['checks'], fn( $c ) => 'pass' !== $c['status'] ) ) - 1 );
check( 'javaslat szövege nem szivárog ki', false === strpos( $json, 'Reduce JavaScript' ) );
check( 'token, lead id nem szivárog ki', false === strpos( $json, 'secret-token' ) && ! isset( $locked['lead_id'] ) && ! isset( $locked['email_pending'] ) );
$open = hpv_grader_public_report( $report, true );
check( 'feloldva minden javaslat látszik', count( array_filter( $open['checks'], fn( $c ) => 'pass' !== $c['status'] && '' !== $c['fix'] ) ) === count( array_filter( $report['checks'], fn( $c ) => 'pass' !== $c['status'] ) ) );

echo "E-mail\n";
$html = hpv_grader_email_html(
	$report,
	array(
		'name'     => 'Maria <b>Lopez</b>',
		'email'    => 'maria@example.com',
		'business' => 'Acme',
	)
);
check( 'pontszám az e-mailben', false !== strpos( $html, (string) $report['overall'] ) );
check( 'javaslatok az e-mailben', false !== strpos( $html, 'How to fix' ) && false !== strpos( $html, 'Reduce JavaScript' ) );
check( 'név escapelve', false !== strpos( $html, 'Hi Maria,' ) && false === strpos( $html, '<b>Lopez' ) );
check( 'konzultáció gomb', false !== strpos( $html, 'book-a-consultation' ) );

echo "Shortcode\n";
$out = hpv_grader_shortcode( array() );
check( 'H1 cím', false !== strpos( $out, '<h1 class="hpv-g-title">' ) );
check( 'heading="h2"', false !== strpos( hpv_grader_shortcode( array( 'heading' => 'h2' ) ), '<h2 class="hpv-g-title">' ) );
check( 'érvénytelen heading → h1', false !== strpos( hpv_grader_shortcode( array( 'heading' => 'script' ) ), '<h1 class="hpv-g-title">' ) );
check( 'téma gomb (btn-pill + lottie)', false !== strpos( $out, 'btn-pill hpv-g-btn' ) && false !== strpos( $out, 'btn-arrow-lottie' ) );
check( 'CSS és JS betöltve', 2 <= count( array_filter( $GLOBALS['hpv_test_assets'], fn( $a ) => preg_match( '/grader\.(css|js)$/', $a ) ) ) );
check( 'API cím a JS-nek', false !== strpos( $GLOBALS['hpv_test_inline'], 'wp-json\/hpv-grader\/v1' ) );
check( 'csapda mező', false !== strpos( $out, 'name="website"' ) );
$GLOBALS['hpv_test_options'][ HPV_GRADER_OPTION ] = array(
	'theme_buttons' => false,
	'color_scheme'  => 'light',
);
$own = hpv_grader_shortcode( array() );
check( 'saját gomb, világos téma', false !== strpos( $own, 'hpv-g-btn--own' ) && false === strpos( $own, 'btn-arrow-lottie' ) && false !== strpos( $own, 'hpv-grader--light' ) );
$GLOBALS['hpv_test_options'] = array();

echo "Beállítások\n";
$clean = hpv_grader_sanitize_settings(
	array(
		'psi_key'      => 'AIza-abc_123<script>',
		'notify_email' => 'nem email',
		'rate_limit'   => '0',
		'color_scheme' => 'neon',
		'cta_url'      => '',
	)
);
check( 'API kulcs tisztítva', 'AIza-abc_123script' === $clean['psi_key'] );
check( 'rossz e-mail → alapérték', get_option( 'admin_email' ) === $clean['notify_email'] );
check( 'korlát min 1', 1 === $clean['rate_limit'] );
check( 'ismeretlen színséma → sötét', 'dark' === $clean['color_scheme'] );
check( 'üres CTA link → alapérték', 'https://helloprovision.com/book-a-consultation/' === $clean['cta_url'] );
check( 'kikapcsolt jelölőnégyzet', false === $clean['theme_buttons'] );

finish();
