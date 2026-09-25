<?php
/**
 * WordPress nélkül futtatható teszt: php tests/seo.php
 *
 * A fixtures/home-schema.json az élő főoldal Yoast schemája (2026-09), ezen ellenőrizzük a javításokat.
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/mu-plugins/helloprovision-seo.php';

function find_node( array $graph, string $type ) {
	foreach ( $graph as $node ) {
		if ( hpv_seo_has_type( $node, $type ) ) {
			return $node;
		}
	}
	return null;
}

function home_graph(): array {
	$data = json_decode( file_get_contents( __DIR__ . '/fixtures/home-schema.json' ), true );
	return $data['@graph'];
}

echo "Főoldal schema\n";
$graph   = hpv_seo_fix_graph( home_graph(), (object) array( 'canonical' => 'https://helloprovision.com/' ) );
$org     = find_node( $graph, 'Organization' );
$website = find_node( $graph, 'WebSite' );
$crumbs  = find_node( $graph, 'BreadcrumbList' );
$address = find_node( $graph, 'PostalAddress' );

check( 'Organization neve HelloProVision', 'HelloProVision' === $org['name'] );
check( 'legalName tiszta', 'Arovia Group LLC' === $org['legalName'] );
check( 'telefon nemzetközi formátumban', '+1-239-955-1655' === $org['telephone'] );
check( 'meglévő Facebook sameAs megmarad, üres sorok kimaradnak', array( 'https://www.facebook.com/helloprovision' ) === $org['sameAs'] );
check( 'areaServed: 3 város', array( 'Fort Myers', 'Cape Coral', 'Naples' ) === array_column( $org['areaServed'], 'name' ) );
check( 'nyitvatartás érintetlen, ha nincs beállítva', 7 === count( $org['openingHoursSpecification'][0]['dayOfWeek'] ) );
check( 'cím hivatkozás megmarad', isset( $org['address']['@id'] ) );
check( 'WebSite neve HelloProVision (nem "Hello Provision")', 'HelloProVision' === $website['name'] );
check( 'WebSite leírás kitöltve', '' !== $website['description'] );
check( 'breadcrumb: Home, nem Kezdőlap', 'Home' === $crumbs['itemListElement'][0]['name'] );
check( 'utcacím város nélkül', '12557 New Brittany Blvd, Suite 3' === $address['streetAddress'] );
check( 'állam: FL', 'FL' === $address['addressRegion'] );
check( 'főoldalon nincs Service', null === find_node( $graph, 'Service' ) );
check( 'a graph valid JSON-ná alakítható', false !== json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ) ) );

echo "Utcacím tisztítás (nem fő telephely)\n";
check( 'nagybetűs cím + város levágva', '12557 New Brittany Blvd, Suite 3' === hpv_seo_clean_street( '12557 NEW BRITTANY BLVD, SUITE 3 FORT MYERS, FL', 'Fort Myers' ) );
check( 'rendes cím változatlan', '1 Main St' === hpv_seo_clean_street( '1 Main St', 'Cape Coral' ) );

echo "Cape Coral oldal\n";
$url   = 'https://helloprovision.com/markets/cape-coral-digital-marketing/';
$page  = home_graph();
$page[0]['@id'] = $url;
$graph   = hpv_seo_fix_graph( $page, (object) array( 'canonical' => $url ) );
$service = find_node( $graph, 'Service' );
check( 'Service jelölés hozzáadva', null !== $service );
check( 'Service @id', $url . '#service' === $service['@id'] );
check( 'Service területe csak Cape Coral', array( 'Cape Coral' ) === array_column( $service['areaServed'], 'name' ) );
check( 'provider a szervezet', 'https://helloprovision.com/#organization' === $service['provider']['@id'] );
check( 'mainEntityOfPage a WebPage', $url === $service['mainEntityOfPage']['@id'] );
$again = hpv_seo_fix_graph( $graph, (object) array( 'canonical' => $url ) );
check( 'kétszeri futtatás nem duplikál', count( $graph ) === count( $again ) );

echo "Service-area mód (hide_address)\n";
add_filter(
	'hpv_seo_config',
	function ( $cfg ) {
		$cfg['hide_address']  = true;
		$cfg['opening_hours'] = array(
			array(
				'dayOfWeek' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ),
				'opens'     => '09:00',
				'closes'    => '17:00',
			),
		);
		$cfg['same_as'][] = 'https://www.linkedin.com/company/helloprovision';
		return $cfg;
	}
);
$graph = hpv_seo_fix_graph( home_graph(), (object) array( 'canonical' => 'https://helloprovision.com/' ) );
$org   = find_node( $graph, 'Organization' );
check( 'PostalAddress kikerül a graphból', null === find_node( $graph, 'PostalAddress' ) );
check( 'Organization address mező törölve', ! isset( $org['address'] ) );
check( 'areaServed megmarad', 3 === count( $org['areaServed'] ) );
check( 'nyitvatartás felülírva (H–P)', 5 === count( $org['openingHoursSpecification'][0]['dayOfWeek'] ) );
check( 'nyitvatartás @type', 'OpeningHoursSpecification' === $org['openingHoursSpecification'][0]['@type'] );
check( 'extra sameAs hozzáadva', in_array( 'https://www.linkedin.com/company/helloprovision', $org['sameAs'], true ) );
remove_all_filters( 'hpv_seo_config' );

echo "Twitter cím\n";
$front             = new WP_Post();
$front->post_title = 'Home';
$GLOBALS['hpv_test_queried'] = $front;
$presentation      = (object) array( 'open_graph_title' => 'Southwest Florida Digital Marketing & Web Design | HelloProVision' );
check( '"Home" helyett az OG cím', $presentation->open_graph_title === hpv_seo_fix_twitter_title( 'Home', $presentation ) );
check( 'egyedi twitter cím érintetlen', 'Custom' === hpv_seo_fix_twitter_title( 'Custom', $presentation ) );

echo "Látható breadcrumb\n";
$links = array(
	array( 'text' => 'Kezdőlap', 'url' => 'https://helloprovision.com/' ),
	array( 'text' => 'SEO', 'url' => 'https://helloprovision.com/seo/' ),
);
check( 'első elem Home', 'Home' === hpv_seo_fix_breadcrumb_links( $links )[0]['text'] );
check( 'ha nincs főoldal morzsa, nem nyúl hozzá', 'SEO' === hpv_seo_fix_breadcrumb_links( array( $links[1] ) )[0]['text'] );

echo "Beállítások: nyitvatartás, útvonalak\n";
$hours = hpv_seo_default_settings()['hours'];
$specs = hpv_seo_hours_to_specs( $hours );
check( 'alap: H–P 9–17 egy csoportban', 1 === count( $specs ) && 5 === count( $specs[0]['dayOfWeek'] ) );
$hours['Saturday'] = array( 'open' => true, 'opens' => '10:00', 'closes' => '14:00' );
$specs             = hpv_seo_hours_to_specs( $hours );
check( 'eltérő szombat külön csoport', 2 === count( $specs ) && array( 'Saturday' ) === $specs[1]['dayOfWeek'] );
check( 'teljes URL → útvonal', '/markets/cape-coral-seo/' === hpv_seo_normalize_path( 'https://helloprovision.com/markets/cape-coral-seo' ) );
check( 'perjel nélküli útvonal', '/seo/' === hpv_seo_normalize_path( 'seo' ) );
check( 'üres útvonal', '' === hpv_seo_normalize_path( '  ' ) );

echo "Beállítások mentése\n";
$GLOBALS['hpv_test_errors'] = array();
$clean = hpv_seo_sanitize_settings(
	array(
		'brand_name'      => '',
		'alternate_names' => "HelloProvision\n\n Hello ProVision \nHelloProvision",
		'telephone'       => '+1-239-955-1655',
		'hours_override'  => '1',
		'hours'           => array(
			'Monday'  => array( 'open' => '1', 'opens' => '09:00', 'closes' => '17:00' ),
			'Tuesday' => array( 'open' => '1', 'opens' => '25:00', 'closes' => 'x' ),
			'Friday'  => array( 'open' => '1', 'opens' => '18:00', 'closes' => '08:00' ),
		),
		'same_as'         => "https://www.linkedin.com/company/helloprovision\nnem link\nhttps://www.linkedin.com/company/helloprovision",
		'area_served'     => array(
			array( 'name' => 'Fort Myers', 'wiki' => 'https://en.wikipedia.org/wiki/Fort_Myers,_Florida' ),
			array( 'name' => 'Cape Coral', 'wiki' => 'https://example.com/cape' ),
			array( 'name' => '', 'wiki' => '' ),
		),
		'services'        => array(
			array( 'path' => 'https://helloprovision.com/markets/cape-coral-seo', 'name' => 'SEO in Cape Coral', 'type' => '', 'areas' => 'Cape Coral, Tampa' ),
			array( 'path' => '/markets/cape-coral-seo/', 'name' => 'Duplikátum', 'type' => '', 'areas' => '' ),
			array( 'path' => '/valami/', 'name' => '', 'type' => '', 'areas' => '' ),
			array( 'path' => '', 'name' => '', 'type' => '', 'areas' => '' ),
		),
	)
);
check( 'üres márkanév → alapérték', 'HelloProVision' === $clean['brand_name'] );
check( 'írásmódok soronként, duplikáció nélkül', array( 'HelloProvision', 'Hello ProVision' ) === $clean['alternate_names'] );
check( 'hibás idő → alapérték', '09:00' === $clean['hours']['Tuesday']['opens'] && '17:00' === $clean['hours']['Tuesday']['closes'] );
check( 'be nem küldött nap zárva', false === $clean['hours']['Sunday']['open'] );
check( 'fordított nyitvatartásra figyelmeztet', in_array( 'hours_Friday', $GLOBALS['hpv_test_errors'], true ) );
check( 'érvénytelen profil link kihagyva', array( 'https://www.linkedin.com/company/helloprovision' ) === $clean['same_as'] && in_array( 'same_as', $GLOBALS['hpv_test_errors'], true ) );
check( 'nem Wikipedia link törölve, város marad', array( 'name' => 'Cape Coral', 'wiki' => '' ) === $clean['area_served'][1] );
check( 'üres város sor kimarad', 2 === count( $clean['area_served'] ) );
check( 'szolgáltatás: URL → útvonal, típus = név', array( 'path' => '/markets/cape-coral-seo/', 'name' => 'SEO in Cape Coral', 'type' => 'SEO in Cape Coral', 'areas' => 'Cape Coral' ) === $clean['services'][0] );
check( 'ismeretlen város (Tampa) jelezve', in_array( 'service_area', $GLOBALS['hpv_test_errors'], true ) );
check( 'duplikált és hiányos sor kimarad', 1 === count( $clean['services'] ) );
check( 'kétszeri tisztítás ugyanazt adja', $clean === hpv_seo_sanitize_settings( $clean ) );
check( 'visszaállítás', hpv_seo_default_settings() === hpv_seo_sanitize_settings( array( 'reset' => '1' ) ) );

echo "Mentett beállítások → schema\n";
$GLOBALS['hpv_test_options'][ HPV_SEO_OPTION ] = $clean;
$cfg = hpv_seo_config();
check( 'nyitvatartás felülírás bekapcsolva', is_array( $cfg['opening_hours'] ) && 'Monday' === $cfg['opening_hours'][0]['dayOfWeek'][0] );
check( 'szolgáltatás a configban', array( 'Cape Coral' ) === $cfg['services']['/markets/cape-coral-seo/']['area'] );
$url     = 'https://helloprovision.com/markets/cape-coral-seo/';
$page    = home_graph();
$page[0]['@id'] = $url;
$service = find_node( hpv_seo_fix_graph( $page, (object) array( 'canonical' => $url ) ), 'Service' );
check( 'új város-oldal Service jelölést kap', 'SEO in Cape Coral' === $service['name'] );
$GLOBALS['hpv_test_options'] = array();

echo "Szerkesztő oldal\n";
set_error_handler(
	function ( $no, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $no, $file, $line );
	}
);
ob_start();
hpv_seo_render_settings_page();
$html = ob_get_clean();
restore_error_handler();
check( 'az oldal hiba nélkül megjelenik', false !== strpos( $html, '<h1>HelloProVision SEO</h1>' ) );
check( 'oldalválasztó a meglévő oldalakkal', false !== strpos( $html, '<option value="/markets/cape-coral-digital-marketing/">' ) );

/**
 * A böngésző módjára összegyűjti az űrlap mezőit (bepipált checkboxok, szövegmezők), és PHP tömbbé alakítja.
 */
function submit_form( string $html ): array {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	$pairs = array();
	foreach ( $doc->getElementsByTagName( 'form' )->item( 0 )->getElementsByTagName( '*' ) as $el ) {
		$name = $el->getAttribute( 'name' );
		if ( '' === $name || 'submit' === $name ) {
			continue;
		}
		if ( 'input' === $el->nodeName ) {
			if ( 'checkbox' === $el->getAttribute( 'type' ) && ! $el->hasAttribute( 'checked' ) ) {
				continue;
			}
			$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( $el->getAttribute( 'value' ) );
		} elseif ( 'textarea' === $el->nodeName ) {
			$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( $el->textContent );
		}
	}
	parse_str( implode( '&', $pairs ), $post );
	return $post;
}

$post = submit_form( $html );
check( 'az űrlap a helyes beállítás-csoportot küldi', 'hpv_seo' === $post['option_page'] );
check( 'változtatás nélküli mentés = alapértékek', hpv_seo_default_settings() === hpv_seo_sanitize_settings( $post[ HPV_SEO_OPTION ] ) );

$GLOBALS['hpv_test_options'][ HPV_SEO_OPTION ] = $clean;
ob_start();
hpv_seo_render_settings_page();
$post = submit_form( ob_get_clean() );
check( 'módosított beállítások: újramentés = ugyanaz', $clean === hpv_seo_sanitize_settings( $post[ HPV_SEO_OPTION ] ) );
$GLOBALS['hpv_test_options'] = array();

finish();
