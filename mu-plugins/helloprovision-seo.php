<?php
/**
 * Plugin Name: HelloProVision SEO Fixes
 * Description: Javítja a Yoast SEO (+ Local SEO) által generált schema adatokat: egységes márkanév, cím, telefonszám, sameAs, kiszolgált területek, Service jelölés a szolgáltatás- és városoldalakon.
 * Version:     1.0.0
 * Author:      HelloProVision
 *
 * Telepítés: másold a wp-content/mu-plugins/ mappába (must-use plugin, automatikusan aktív).
 * Beállítás: a hpv_seo_config() tömbje, vagy a 'hpv_seo_config' filter egy másik fájlból.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Központi beállítások. Egy helyen legyen minden cégadat, hogy mindenhol ugyanaz jelenjen meg.
 */
function hpv_seo_config(): array {
	$config = array(
		// Márkanév — pontosan így, mindenhol (Google Business Profile, Yelp, Clutch, lábléc).
		'brand_name'       => 'HelloProVision',
		'alternate_names'  => array( 'HelloProvision', 'Hello ProVision' ),
		'legal_name'       => 'Arovia Group LLC',
		'site_description' => 'Web design, SEO and local search for Southwest Florida businesses in Fort Myers, Cape Coral and Naples.',
		'telephone'        => '+1-239-955-1655',

		// A breadcrumb első eleme (most "Kezdőlap" jelenik meg az angol oldalon).
		'home_label'       => 'Home',

		// A fő telephely utcacíme, város és állam nélkül (azok külön mezőben vannak).
		'street_address'   => '12557 New Brittany Blvd, Suite 3',

		// true = service-area vállalkozás: a cím kikerül a schemából, csak a kiszolgált területek maradnak.
		// Állítsd true-ra, ha a Google Business Profile-ban is rejtett címmel regisztráltok.
		'hide_address'     => false,

		// null = marad, ami a Yoast Local SEO-ban be van állítva. Egyezzen a Google Business Profile-lal!
		// Példa: array( array( 'dayOfWeek' => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ), 'opens' => '09:00', 'closes' => '17:00' ) )
		'opening_hours'    => null,

		// Hivatalos profilok. Töltsd ki, amint megvannak — az üres sorok kimaradnak.
		'same_as'          => array(
			'https://www.facebook.com/helloprovision',
			'', // Google Business Profile (Maps "Share" link vagy https://www.google.com/maps?cid=...)
			'', // LinkedIn céges oldal
			'', // Instagram (saját HelloProVision fiók, nem az ideastyle.hu)
			'', // Clutch profil
			'', // Yelp profil
		),

		// Kiszolgált városok => Wikipedia URL (a Google ebből azonosítja egyértelműen a helyet).
		'area_served'      => array(
			'Fort Myers' => 'https://en.wikipedia.org/wiki/Fort_Myers,_Florida',
			'Cape Coral' => 'https://en.wikipedia.org/wiki/Cape_Coral,_Florida',
			'Naples'     => 'https://en.wikipedia.org/wiki/Naples,_Florida',
		),

		// Oldal elérési út => Service jelölés. 'area' => null esetén az összes kiszolgált város.
		'services'         => array(
			'/markets/fort-myers-digital-marketing/' => array(
				'name'        => 'Digital Marketing & Web Design in Fort Myers',
				'serviceType' => 'Digital marketing',
				'area'        => array( 'Fort Myers' ),
			),
			'/markets/cape-coral-digital-marketing/' => array(
				'name'        => 'Digital Marketing & Web Design in Cape Coral',
				'serviceType' => 'Digital marketing',
				'area'        => array( 'Cape Coral' ),
			),
			'/markets/naples-digital-marketing/'     => array(
				'name'        => 'Digital Marketing & Web Design in Naples',
				'serviceType' => 'Digital marketing',
				'area'        => array( 'Naples' ),
			),
			'/webdesign/'                            => array(
				'name'        => 'Web Design & Development',
				'serviceType' => 'Web design',
				'area'        => null,
			),
			'/seo/'                                  => array(
				'name'        => 'SEO & Local Search',
				'serviceType' => 'Search engine optimization',
				'area'        => null,
			),
			'/social-media-marketing/'               => array(
				'name'        => 'Social Media Marketing',
				'serviceType' => 'Social media marketing',
				'area'        => null,
			),
			'/graphic-design/'                       => array(
				'name'        => 'Graphic Design',
				'serviceType' => 'Graphic design',
				'area'        => null,
			),
		),
	);

	return apply_filters( 'hpv_seo_config', $config );
}

add_filter( 'wpseo_schema_graph', 'hpv_seo_fix_graph', 20, 2 );
add_filter( 'wpseo_twitter_title', 'hpv_seo_fix_twitter_title', 10, 2 );
add_filter( 'wpseo_breadcrumb_links', 'hpv_seo_fix_breadcrumb_links' );

/**
 * A teljes Yoast schema graph utófeldolgozása (a Local SEO darabjai is ide futnak be).
 *
 * @param array  $graph   A schema graph elemei.
 * @param object $context Yoast Meta_Tags_Context (a canonical URL miatt kell).
 */
function hpv_seo_fix_graph( $graph, $context = null ) {
	if ( ! is_array( $graph ) ) {
		return $graph;
	}

	$cfg         = hpv_seo_config();
	$org_id      = null;
	$webpage_id  = null;
	$address_ids = array();

	foreach ( $graph as &$node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}

		if ( hpv_seo_has_type( $node, 'Organization' ) ) {
			if ( null === $org_id && isset( $node['@id'] ) ) {
				$org_id = $node['@id'];
			}
			if ( isset( $node['address']['@id'] ) ) {
				$address_ids[] = $node['address']['@id'];
			}
			$node = hpv_seo_fix_organization( $node, $cfg );
		} elseif ( hpv_seo_has_type( $node, 'WebSite' ) ) {
			$node = hpv_seo_fix_website( $node, $cfg );
		} elseif ( hpv_seo_has_type( $node, 'BreadcrumbList' ) ) {
			$node = hpv_seo_fix_breadcrumb( $node, $cfg );
		} elseif ( hpv_seo_has_type( $node, 'PostalAddress' ) ) {
			$node = hpv_seo_fix_address( $node, $cfg );
		} elseif ( hpv_seo_has_type( $node, 'WebPage' ) && null === $webpage_id && isset( $node['@id'] ) ) {
			$webpage_id = $node['@id'];
		}
	}
	unset( $node );

	// Service-area mód: a szervezet címét teljesen kivesszük a graphból.
	if ( ! empty( $cfg['hide_address'] ) && $address_ids ) {
		$graph = array_values(
			array_filter(
				$graph,
				function ( $node ) use ( $address_ids ) {
					return ! ( is_array( $node ) && isset( $node['@id'] ) && in_array( $node['@id'], $address_ids, true ) );
				}
			)
		);
	}

	$canonical = ( is_object( $context ) && ! empty( $context->canonical ) ) ? $context->canonical : '';
	$service   = hpv_seo_service_node( $canonical, $org_id, $webpage_id, $cfg );
	if ( $service && ! hpv_seo_graph_has_id( $graph, $service['@id'] ) ) {
		$graph[] = $service;
	}

	return $graph;
}

function hpv_seo_fix_organization( array $node, array $cfg ): array {
	$node['name'] = $cfg['brand_name'];

	if ( ! empty( $cfg['alternate_names'] ) ) {
		$node['alternateName'] = array_values( array_diff( $cfg['alternate_names'], array( $cfg['brand_name'] ) ) );
	}
	if ( ! empty( $cfg['legal_name'] ) ) {
		$node['legalName'] = $cfg['legal_name'];
	}
	if ( ! empty( $cfg['telephone'] ) ) {
		$node['telephone'] = $cfg['telephone'];
	}
	if ( is_array( $cfg['opening_hours'] ) ) {
		$node['openingHoursSpecification'] = array_map(
			function ( $spec ) {
				return array( '@type' => 'OpeningHoursSpecification' ) + $spec;
			},
			$cfg['opening_hours']
		);
	}

	$same_as = array_merge( (array) ( $node['sameAs'] ?? array() ), $cfg['same_as'] );
	$same_as = array_values( array_unique( array_filter( array_map( 'trim', $same_as ) ) ) );
	if ( $same_as ) {
		$node['sameAs'] = $same_as;
	}

	$area = hpv_seo_area_nodes( null, $cfg );
	if ( $area ) {
		$node['areaServed'] = $area;
	}

	if ( ! empty( $cfg['hide_address'] ) ) {
		unset( $node['address'] );
	}

	return $node;
}

function hpv_seo_fix_website( array $node, array $cfg ): array {
	$node['name'] = $cfg['brand_name'];

	if ( ! empty( $cfg['alternate_names'] ) ) {
		$node['alternateName'] = array_values( array_diff( $cfg['alternate_names'], array( $cfg['brand_name'] ) ) );
	}
	if ( empty( $node['description'] ) && ! empty( $cfg['site_description'] ) ) {
		$node['description'] = $cfg['site_description'];
	}

	return $node;
}

function hpv_seo_fix_breadcrumb( array $node, array $cfg ): array {
	if ( empty( $cfg['home_label'] ) || empty( $node['itemListElement'] ) || ! is_array( $node['itemListElement'] ) ) {
		return $node;
	}

	foreach ( $node['itemListElement'] as &$item ) {
		if ( isset( $item['position'] ) && 1 === (int) $item['position'] ) {
			$item['name'] = $cfg['home_label'];
		}
	}
	unset( $item );

	return $node;
}

function hpv_seo_fix_address( array $node, array $cfg ): array {
	$is_main = isset( $node['@id'] ) && '#local-main-place-address' === substr( $node['@id'], -strlen( '#local-main-place-address' ) );

	if ( $is_main && ! empty( $cfg['street_address'] ) ) {
		$node['streetAddress'] = $cfg['street_address'];
	} elseif ( ! empty( $node['streetAddress'] ) ) {
		$node['streetAddress'] = hpv_seo_clean_street( $node['streetAddress'], $node['addressLocality'] ?? '' );
	}

	// Az állam rövidítése az amerikai szabvány (FL), így egyezik a Google Business Profile-lal.
	if ( isset( $node['addressRegion'] ) && 'florida' === strtolower( trim( $node['addressRegion'] ) ) ) {
		$node['addressRegion'] = 'FL';
	}

	return $node;
}

/**
 * "12557 NEW BRITTANY BLVD, SUITE 3 FORT MYERS, FL" => "12557 New Brittany Blvd, Suite 3"
 */
function hpv_seo_clean_street( string $street, string $locality ): string {
	$street = trim( $street );

	if ( '' !== $locality ) {
		$pos = stripos( $street, $locality );
		if ( false !== $pos && $pos > 0 ) {
			$street = substr( $street, 0, $pos );
		}
	}
	$street = rtrim( $street, " ,\t\n" );

	if ( $street === strtoupper( $street ) ) {
		$street = ucwords( strtolower( $street ) );
	}

	return $street;
}

function hpv_seo_service_node( string $canonical, $org_id, $webpage_id, array $cfg ) {
	if ( '' === $canonical || ! $org_id || empty( $cfg['services'] ) ) {
		return null;
	}

	$path = (string) parse_url( $canonical, PHP_URL_PATH );
	$path = '/' . trim( $path, '/' ) . '/';
	if ( empty( $cfg['services'][ $path ] ) ) {
		return null;
	}

	$service = $cfg['services'][ $path ];
	$node    = array(
		'@type'       => 'Service',
		'@id'         => $canonical . '#service',
		'name'        => $service['name'],
		'serviceType' => $service['serviceType'],
		'url'         => $canonical,
		'provider'    => array( '@id' => $org_id ),
	);

	$area = hpv_seo_area_nodes( $service['area'] ?? null, $cfg );
	if ( $area ) {
		$node['areaServed'] = $area;
	}
	if ( $webpage_id ) {
		$node['mainEntityOfPage'] = array( '@id' => $webpage_id );
	}

	return $node;
}

/**
 * @param array|null $cities Városnevek, vagy null = az összes kiszolgált város.
 */
function hpv_seo_area_nodes( $cities, array $cfg ): array {
	$nodes = array();

	foreach ( (array) $cfg['area_served'] as $name => $wiki ) {
		if ( null !== $cities && ! in_array( $name, $cities, true ) ) {
			continue;
		}
		$city = array(
			'@type' => 'City',
			'name'  => $name,
		);
		if ( $wiki ) {
			$city['sameAs'] = $wiki;
		}
		$nodes[] = $city;
	}

	return $nodes;
}

function hpv_seo_has_type( array $node, string $type ): bool {
	if ( ! isset( $node['@type'] ) ) {
		return false;
	}

	return in_array( $type, (array) $node['@type'], true );
}

function hpv_seo_graph_has_id( array $graph, string $id ): bool {
	foreach ( $graph as $node ) {
		if ( is_array( $node ) && isset( $node['@id'] ) && $node['@id'] === $id ) {
			return true;
		}
	}

	return false;
}

/**
 * A főoldalon a twitter:title most "Home" (a WordPress oldal neve), nem a valódi SEO cím.
 * Ha a twitter cím csak a nyers oldalnév, az Open Graph címet használjuk helyette.
 */
function hpv_seo_fix_twitter_title( $title, $presentation = null ) {
	$object = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
	$raw    = ( $object instanceof WP_Post ) ? $object->post_title : '';

	if ( '' !== $raw && trim( (string) $title ) === $raw && is_object( $presentation ) && ! empty( $presentation->open_graph_title ) ) {
		return $presentation->open_graph_title;
	}

	return $title;
}

/**
 * A látható breadcrumb első eleme is legyen angol.
 */
function hpv_seo_fix_breadcrumb_links( $links ) {
	$cfg = hpv_seo_config();

	// Csak akkor, ha az első elem tényleg a főoldal (a Yoastban ki lehet kapcsolni a főoldal morzsát).
	if ( ! empty( $cfg['home_label'] ) && isset( $links[0]['text'], $links[0]['url'] )
		&& untrailingslashit( $links[0]['url'] ) === untrailingslashit( home_url( '/' ) ) ) {
		$links[0]['text'] = $cfg['home_label'];
	}

	return $links;
}
