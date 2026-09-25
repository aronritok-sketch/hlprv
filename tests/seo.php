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

finish();
