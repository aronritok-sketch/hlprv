<?php
/**
 * Plugin Name: HelloProVision SEO Fixes
 * Description: Javítja a Yoast SEO (+ Local SEO) által generált schema adatokat: egységes márkanév, cím, telefonszám, sameAs, kiszolgált területek, Service jelölés a szolgáltatás- és városoldalakon.
 * Version:     1.1.0
 * Author:      HelloProVision
 *
 * Telepítés: normál bővítményként (zip feltöltés), vagy a wp-content/mu-plugins/ mappába másolva.
 * Beállítás: az admin felületen, Beállítások → HelloProVision SEO.
 */

defined( 'ABSPATH' ) || exit;

const HPV_SEO_OPTION = 'hpv_seo_settings';
const HPV_SEO_PAGE   = 'hpv-seo';
const HPV_SEO_DAYS   = array(
	'Monday'    => 'Hétfő',
	'Tuesday'   => 'Kedd',
	'Wednesday' => 'Szerda',
	'Thursday'  => 'Csütörtök',
	'Friday'    => 'Péntek',
	'Saturday'  => 'Szombat',
	'Sunday'    => 'Vasárnap',
);

/* ─── Beállítások ─────────────────────────────────────────── */

/**
 * Alapértékek. A mentett beállítások ugyanilyen szerkezetűek, mint a szerkesztő űrlapja.
 */
function hpv_seo_default_settings(): array {
	$hours = array();
	foreach ( array_keys( HPV_SEO_DAYS ) as $day ) {
		$hours[ $day ] = array(
			'open'   => ! in_array( $day, array( 'Saturday', 'Sunday' ), true ),
			'opens'  => '09:00',
			'closes' => '17:00',
		);
	}

	return array(
		'brand_name'       => 'HelloProVision',
		'alternate_names'  => array( 'HelloProvision', 'Hello ProVision' ),
		'legal_name'       => 'Arovia Group LLC',
		'site_description' => 'Web design, SEO and local search for Southwest Florida businesses in Fort Myers, Cape Coral and Naples.',
		'telephone'        => '+1-239-955-1655',
		'home_label'       => 'Home',
		'street_address'   => '12557 New Brittany Blvd, Suite 3',
		'hide_address'     => false,
		'hours_override'   => false,
		'hours'            => $hours,
		'same_as'          => array( 'https://www.facebook.com/helloprovision' ),
		'area_served'      => array(
			array(
				'name' => 'Fort Myers',
				'wiki' => 'https://en.wikipedia.org/wiki/Fort_Myers,_Florida',
			),
			array(
				'name' => 'Cape Coral',
				'wiki' => 'https://en.wikipedia.org/wiki/Cape_Coral,_Florida',
			),
			array(
				'name' => 'Naples',
				'wiki' => 'https://en.wikipedia.org/wiki/Naples,_Florida',
			),
		),
		'services'         => array(
			array(
				'path'  => '/markets/fort-myers-digital-marketing/',
				'name'  => 'Digital Marketing & Web Design in Fort Myers',
				'type'  => 'Digital marketing',
				'areas' => 'Fort Myers',
			),
			array(
				'path'  => '/markets/cape-coral-digital-marketing/',
				'name'  => 'Digital Marketing & Web Design in Cape Coral',
				'type'  => 'Digital marketing',
				'areas' => 'Cape Coral',
			),
			array(
				'path'  => '/markets/naples-digital-marketing/',
				'name'  => 'Digital Marketing & Web Design in Naples',
				'type'  => 'Digital marketing',
				'areas' => 'Naples',
			),
			array(
				'path'  => '/webdesign/',
				'name'  => 'Web Design & Development',
				'type'  => 'Web design',
				'areas' => '',
			),
			array(
				'path'  => '/seo/',
				'name'  => 'SEO & Local Search',
				'type'  => 'Search engine optimization',
				'areas' => '',
			),
			array(
				'path'  => '/social-media-marketing/',
				'name'  => 'Social Media Marketing',
				'type'  => 'Social media marketing',
				'areas' => '',
			),
			array(
				'path'  => '/graphic-design/',
				'name'  => 'Graphic Design',
				'type'  => 'Graphic design',
				'areas' => '',
			),
		),
	);
}

function hpv_seo_settings(): array {
	$saved = get_option( HPV_SEO_OPTION, array() );

	return array_merge( hpv_seo_default_settings(), is_array( $saved ) ? $saved : array() );
}

/**
 * A schema-javításhoz használt beállítások (a szerkesztőben mentett adatokból).
 * Kódból felülírható a 'hpv_seo_config' filterrel.
 */
function hpv_seo_config(): array {
	$s = hpv_seo_settings();

	$area = array();
	foreach ( $s['area_served'] as $row ) {
		$area[ $row['name'] ] = $row['wiki'];
	}

	$services = array();
	foreach ( $s['services'] as $row ) {
		$cities                   = hpv_seo_split_list( $row['areas'], ',' );
		$services[ $row['path'] ] = array(
			'name'        => $row['name'],
			'serviceType' => $row['type'],
			'area'        => $cities ? $cities : null,
		);
	}

	$config = array(
		'brand_name'       => $s['brand_name'],
		'alternate_names'  => $s['alternate_names'],
		'legal_name'       => $s['legal_name'],
		'site_description' => $s['site_description'],
		'telephone'        => $s['telephone'],
		'home_label'       => $s['home_label'],
		'street_address'   => $s['street_address'],
		'hide_address'     => (bool) $s['hide_address'],
		// null = marad, ami a Yoast Local SEO-ban be van állítva.
		'opening_hours'    => $s['hours_override'] ? hpv_seo_hours_to_specs( $s['hours'] ) : null,
		'same_as'          => $s['same_as'],
		'area_served'      => $area,
		'services'         => $services,
	);

	return apply_filters( 'hpv_seo_config', $config );
}

/**
 * Napi nyitvatartás => OpeningHoursSpecification lista; az azonos idejű napok egy elembe kerülnek.
 */
function hpv_seo_hours_to_specs( array $hours ): array {
	$groups = array();
	foreach ( array_keys( HPV_SEO_DAYS ) as $day ) {
		if ( empty( $hours[ $day ]['open'] ) ) {
			continue;
		}
		$key = $hours[ $day ]['opens'] . '-' . $hours[ $day ]['closes'];
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'dayOfWeek' => array(),
				'opens'     => $hours[ $day ]['opens'],
				'closes'    => $hours[ $day ]['closes'],
			);
		}
		$groups[ $key ]['dayOfWeek'][] = $day;
	}

	return array_values( $groups );
}

/**
 * Szöveg (soronként / vesszővel) vagy tömb => tiszta lista.
 */
function hpv_seo_split_list( $value, string $separator = "\n" ): array {
	$items = is_array( $value ) ? $value : explode( $separator, (string) $value );

	return array_values( array_unique( array_filter( array_map( 'trim', $items ), 'strlen' ) ) );
}

/**
 * "https://helloprovision.com/seo" vagy "seo" => "/seo/"
 */
function hpv_seo_normalize_path( string $value ): string {
	$value = trim( $value );
	if ( preg_match( '#^https?://#i', $value ) ) {
		$value = (string) parse_url( $value, PHP_URL_PATH );
	}
	$value = trim( $value, "/ \t" );

	return '' === $value ? '' : '/' . $value . '/';
}

/**
 * A szerkesztő űrlapjának tisztítása. Többször is lefuthat ugyanarra az adatra (a WordPress első
 * mentéskor kétszer hívja), ezért a kimenet szerkezete megegyezik a bemenetével.
 */
function hpv_seo_sanitize_settings( $input ): array {
	$defaults = hpv_seo_default_settings();
	$input    = is_array( $input ) ? $input : array();

	if ( ! empty( $input['reset'] ) ) {
		return $defaults;
	}

	$clean = array();

	$clean['brand_name'] = sanitize_text_field( (string) ( $input['brand_name'] ?? '' ) );
	if ( '' === $clean['brand_name'] ) {
		$clean['brand_name'] = $defaults['brand_name'];
	}
	foreach ( array( 'legal_name', 'telephone', 'home_label', 'street_address' ) as $key ) {
		$clean[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
	}
	$clean['site_description'] = sanitize_textarea_field( (string) ( $input['site_description'] ?? '' ) );
	$clean['alternate_names']  = array_map( 'sanitize_text_field', hpv_seo_split_list( $input['alternate_names'] ?? array() ) );
	$clean['hide_address']     = ! empty( $input['hide_address'] );
	$clean['hours_override']   = ! empty( $input['hours_override'] );

	$clean['hours'] = array();
	foreach ( array_keys( HPV_SEO_DAYS ) as $day ) {
		$row    = (array) ( $input['hours'][ $day ] ?? array() );
		$opens  = (string) ( $row['opens'] ?? '' );
		$closes = (string) ( $row['closes'] ?? '' );

		$clean['hours'][ $day ] = array(
			'open'   => ! empty( $row['open'] ),
			'opens'  => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $opens ) ? $opens : '09:00',
			'closes' => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $closes ) ? $closes : '17:00',
		);
		if ( $clean['hours'][ $day ]['open'] && $clean['hours'][ $day ]['closes'] <= $clean['hours'][ $day ]['opens'] ) {
			add_settings_error( HPV_SEO_OPTION, 'hours_' . $day, HPV_SEO_DAYS[ $day ] . ': a zárás ideje a nyitás előtt van.' );
		}
	}

	$clean['same_as'] = array();
	foreach ( hpv_seo_split_list( $input['same_as'] ?? array() ) as $url ) {
		$url = esc_url_raw( $url );
		if ( preg_match( '#^https?://[^/\s]+\.[a-z]{2,}#i', $url ) ) {
			$clean['same_as'][] = $url;
		} else {
			add_settings_error( HPV_SEO_OPTION, 'same_as', 'Érvénytelen profil link, kihagyva: ' . $url );
		}
	}
	$clean['same_as'] = array_values( array_unique( $clean['same_as'] ) );

	$clean['area_served'] = array();
	$cities               = array();
	foreach ( (array) ( $input['area_served'] ?? array() ) as $row ) {
		$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		if ( '' === $name || in_array( $name, $cities, true ) ) {
			continue;
		}
		$wiki = esc_url_raw( trim( (string) ( $row['wiki'] ?? '' ) ) );
		if ( '' !== $wiki && ! preg_match( '#^https://[a-z-]+\.wikipedia\.org/wiki/#', $wiki ) ) {
			add_settings_error( HPV_SEO_OPTION, 'wiki', $name . ': a Wikipedia link https://en.wikipedia.org/wiki/… formájú legyen, kihagyva.' );
			$wiki = '';
		}
		$cities[]               = $name;
		$clean['area_served'][] = array(
			'name' => $name,
			'wiki' => $wiki,
		);
	}

	$clean['services'] = array();
	$paths             = array();
	foreach ( (array) ( $input['services'] ?? array() ) as $row ) {
		$path = hpv_seo_normalize_path( (string) ( $row['path'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		if ( '' === $path && '' === $name ) {
			continue;
		}
		if ( '' === $path || '' === $name ) {
			add_settings_error( HPV_SEO_OPTION, 'service', 'Szolgáltatás oldalnál az oldal és a név is kötelező, kihagyva: ' . ( $name ? $name : $path ) );
			continue;
		}
		if ( in_array( $path, $paths, true ) ) {
			add_settings_error( HPV_SEO_OPTION, 'service_dup', 'Ez az oldal kétszer szerepel, a második kihagyva: ' . $path );
			continue;
		}

		$areas   = array_map( 'sanitize_text_field', hpv_seo_split_list( $row['areas'] ?? '', ',' ) );
		$unknown = array_diff( $areas, $cities );
		if ( $unknown ) {
			add_settings_error( HPV_SEO_OPTION, 'service_area', $name . ': ismeretlen város (előbb vedd fel a Kiszolgált városok közé): ' . implode( ', ', $unknown ) );
			$areas = array_values( array_intersect( $areas, $cities ) );
		}

		$type                = sanitize_text_field( (string) ( $row['type'] ?? '' ) );
		$paths[]             = $path;
		$clean['services'][] = array(
			'path'  => $path,
			'name'  => $name,
			'type'  => '' !== $type ? $type : $name,
			'areas' => implode( ', ', $areas ),
		);
	}

	// Ugyanaz a kulcssorrend, mint az alapértékekben.
	return array_replace( $defaults, $clean );
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
	if ( is_array( $cfg['opening_hours'] ) && ! $cfg['opening_hours'] ) {
		unset( $node['openingHoursSpecification'] );
	} elseif ( is_array( $cfg['opening_hours'] ) ) {
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

/* ─── Admin: Beállítások → HelloProVision SEO ─────────────── */

add_action( 'admin_menu', 'hpv_seo_admin_menu' );
add_action( 'admin_init', 'hpv_seo_admin_init' );

function hpv_seo_admin_menu() {
	add_options_page( 'HelloProVision SEO', 'HelloProVision SEO', 'manage_options', HPV_SEO_PAGE, 'hpv_seo_render_settings_page' );
}

function hpv_seo_admin_init() {
	register_setting( 'hpv_seo', HPV_SEO_OPTION, array( 'sanitize_callback' => 'hpv_seo_sanitize_settings' ) );

	// "Beállítások" link a Bővítmények listában (ha normál bővítményként van telepítve).
	add_filter(
		'plugin_action_links_' . plugin_basename( __FILE__ ),
		function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . HPV_SEO_PAGE ) ) . '">Beállítások</a>' );
			return $links;
		}
	);
}

/**
 * Az oldal választóhoz: az oldalak és a Yoast Local / téma egyedi tartalomtípusainak elérési útjai.
 */
function hpv_seo_known_paths(): array {
	$types = array_values( array_intersect( array( 'page', 'location_page', 'service' ), get_post_types( array(), 'names' ) ) );
	$ids   = get_posts(
		array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 300,
		)
	);

	$paths = array();
	foreach ( $ids as $id ) {
		$path = hpv_seo_normalize_path( (string) get_permalink( $id ) );
		if ( '' !== $path ) {
			$paths[ $path ] = get_the_title( $id );
		}
	}
	ksort( $paths );

	return $paths;
}

function hpv_seo_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$s     = hpv_seo_settings();
	$n     = HPV_SEO_OPTION;
	$field = function ( $key ) use ( $n ) {
		return esc_attr( $n . $key );
	};

	// Üres sorok az új elemekhez.
	$area_rows    = array_merge( $s['area_served'], array_fill( 0, 3, array( 'name' => '', 'wiki' => '' ) ) );
	$service_rows = array_merge( $s['services'], array_fill( 0, 3, array( 'path' => '', 'name' => '', 'type' => '', 'areas' => '' ) ) );
	$city_names   = implode( ', ', array_column( $s['area_served'], 'name' ) );
	?>
	<div class="wrap">
		<h1>HelloProVision SEO</h1>
		<p>Ezek az adatok a Google-nek szóló strukturált adatokba (schema) kerülnek. Egyezzenek pontosan a Google Business Profile-lal, a Yelp, Clutch stb. adatokkal.</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'hpv_seo' ); ?>

			<h2 class="title">Cégadatok</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="hpv-brand">Márkanév</label></th>
					<td><input id="hpv-brand" name="<?php echo $field( '[brand_name]' ); ?>" value="<?php echo esc_attr( $s['brand_name'] ); ?>" class="regular-text" required>
					<p class="description">Pontosan így, mindenhol.</p></td>
				</tr>
				<tr>
					<th><label for="hpv-alt">Egyéb írásmódok</label></th>
					<td><textarea id="hpv-alt" name="<?php echo $field( '[alternate_names]' ); ?>" rows="3" class="regular-text"><?php echo esc_textarea( implode( "\n", $s['alternate_names'] ) ); ?></textarea>
					<p class="description">Soronként egy. Ezekre keresve is titeket találjon a Google.</p></td>
				</tr>
				<tr>
					<th><label for="hpv-legal">Cégjogi név</label></th>
					<td><input id="hpv-legal" name="<?php echo $field( '[legal_name]' ); ?>" value="<?php echo esc_attr( $s['legal_name'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="hpv-desc">Rövid leírás</label></th>
					<td><textarea id="hpv-desc" name="<?php echo $field( '[site_description]' ); ?>" rows="2" class="large-text"><?php echo esc_textarea( $s['site_description'] ); ?></textarea>
					<p class="description">Angolul, 1–2 mondat. A weboldal leírásaként jelenik meg a schemában.</p></td>
				</tr>
				<tr>
					<th><label for="hpv-phone">Telefonszám</label></th>
					<td><input id="hpv-phone" name="<?php echo $field( '[telephone]' ); ?>" value="<?php echo esc_attr( $s['telephone'] ); ?>" class="regular-text" placeholder="+1-239-955-1655">
					<p class="description">Nemzetközi formátumban: +1-239-…</p></td>
				</tr>
				<tr>
					<th><label for="hpv-home">Főoldal neve a morzsamenüben</label></th>
					<td><input id="hpv-home" name="<?php echo $field( '[home_label]' ); ?>" value="<?php echo esc_attr( $s['home_label'] ); ?>" class="regular-text"></td>
				</tr>
			</table>

			<h2 class="title">Cím és nyitvatartás</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="hpv-street">Utca, házszám</label></th>
					<td><input id="hpv-street" name="<?php echo $field( '[street_address]' ); ?>" value="<?php echo esc_attr( $s['street_address'] ); ?>" class="regular-text">
					<p class="description">Város, állam és irányítószám nélkül — azokat a Yoast Local SEO adja.</p></td>
				</tr>
				<tr>
					<th>Cím elrejtése</th>
					<td><label><input type="checkbox" name="<?php echo $field( '[hide_address]' ); ?>" value="1" <?php checked( $s['hide_address'] ); ?>> Kiszállós (service-area) vállalkozás: a cím nem jelenik meg a schemában, csak a kiszolgált városok</label>
					<p class="description">Kapcsold be, ha a Google Business Profile-ban is rejtett a cím.</p></td>
				</tr>
				<tr>
					<th>Nyitvatartás</th>
					<td>
						<label><input type="checkbox" name="<?php echo $field( '[hours_override]' ); ?>" value="1" <?php checked( $s['hours_override'] ); ?>> Az alábbi nyitvatartást használja (kikapcsolva a Yoast Local SEO-ban beállított marad)</label>
						<table style="margin-top:8px">
							<?php foreach ( HPV_SEO_DAYS as $day => $label ) : $h = $s['hours'][ $day ]; ?>
								<tr>
									<td style="padding:4px 12px 4px 0"><label><input type="checkbox" name="<?php echo $field( "[hours][$day][open]" ); ?>" value="1" <?php checked( $h['open'] ); ?>> <?php echo esc_html( $label ); ?></label></td>
									<td style="padding:4px 0"><input type="time" name="<?php echo $field( "[hours][$day][opens]" ); ?>" value="<?php echo esc_attr( $h['opens'] ); ?>" aria-label="<?php echo esc_attr( $label ); ?> nyitás"> – <input type="time" name="<?php echo $field( "[hours][$day][closes]" ); ?>" value="<?php echo esc_attr( $h['closes'] ); ?>" aria-label="<?php echo esc_attr( $label ); ?> zárás"></td>
								</tr>
							<?php endforeach; ?>
						</table>
						<p class="description">Egyezzen a Google Business Profile nyitvatartásával.</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Hivatalos profilok</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="hpv-sameas">Profil linkek</label></th>
					<td><textarea id="hpv-sameas" name="<?php echo $field( '[same_as]' ); ?>" rows="6" class="large-text" placeholder="https://www.linkedin.com/company/..."><?php echo esc_textarea( implode( "\n", $s['same_as'] ) ); ?></textarea>
					<p class="description">Soronként egy: Facebook, LinkedIn, Instagram, Clutch, Yelp, BBB… Csak a saját, HelloProVision profilok. A Google-profil linkjét a Google értékelések bővítmény magától hozzáadja.</p></td>
				</tr>
			</table>

			<h2 class="title">Kiszolgált városok</h2>
			<p>A Wikipedia link segít a Google-nek egyértelműen azonosítani a várost. A sor törléséhez töröld ki a város nevét.</p>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th style="width:35%">Város</th><th>Wikipedia link</th></tr></thead>
				<tbody>
				<?php foreach ( $area_rows as $i => $row ) : ?>
					<tr>
						<td><input name="<?php echo $field( "[area_served][$i][name]" ); ?>" value="<?php echo esc_attr( $row['name'] ); ?>" class="widefat" placeholder="pl. Bonita Springs" aria-label="Város"></td>
						<td><input type="url" name="<?php echo $field( "[area_served][$i][wiki]" ); ?>" value="<?php echo esc_attr( $row['wiki'] ); ?>" class="widefat" placeholder="https://en.wikipedia.org/wiki/Bonita_Springs,_Florida" aria-label="Wikipedia link"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 class="title">Szolgáltatás- és városoldalak</h2>
			<p>Ezek az oldalak „Service” jelölést kapnak: melyik szolgáltatásról szólnak, és melyik városban. Új város-oldal (pl. <code>/markets/cape-coral-seo/</code>) létrehozása után vedd fel ide. A sor törléséhez töröld ki az oldalt és a nevet.</p>
			<table class="widefat striped">
				<thead><tr><th style="width:28%">Oldal</th><th style="width:28%">Szolgáltatás neve (angolul)</th><th style="width:20%">Típus</th><th>Városok</th></tr></thead>
				<tbody>
				<?php foreach ( $service_rows as $i => $row ) : ?>
					<tr>
						<td><input name="<?php echo $field( "[services][$i][path]" ); ?>" value="<?php echo esc_attr( $row['path'] ); ?>" class="widefat" list="hpv-seo-paths" placeholder="/markets/cape-coral-seo/" aria-label="Oldal"></td>
						<td><input name="<?php echo $field( "[services][$i][name]" ); ?>" value="<?php echo esc_attr( $row['name'] ); ?>" class="widefat" placeholder="SEO in Cape Coral" aria-label="Szolgáltatás neve"></td>
						<td><input name="<?php echo $field( "[services][$i][type]" ); ?>" value="<?php echo esc_attr( $row['type'] ); ?>" class="widefat" list="hpv-seo-types" placeholder="Search engine optimization" aria-label="Típus"></td>
						<td><input name="<?php echo $field( "[services][$i][areas]" ); ?>" value="<?php echo esc_attr( $row['areas'] ); ?>" class="widefat" placeholder="üres = mindegyik" aria-label="Városok"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Oldal: kezdd el gépelni, és a listából választhatsz (a teljes URL is bemásolható). Városok: vesszővel elválasztva, a fenti listából (<?php echo esc_html( $city_names ); ?>). Üresen hagyva az összes város.</p>

			<datalist id="hpv-seo-paths">
				<?php foreach ( hpv_seo_known_paths() as $path => $title ) : ?>
					<option value="<?php echo esc_attr( $path ); ?>"><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</datalist>
			<datalist id="hpv-seo-types">
				<option value="Web design"></option>
				<option value="Search engine optimization"></option>
				<option value="Digital marketing"></option>
				<option value="Social media marketing"></option>
				<option value="Graphic design"></option>
				<option value="Pay-per-click advertising"></option>
				<option value="Website maintenance"></option>
			</datalist>

			<?php submit_button( 'Mentés' ); ?>

			<p><label><input type="checkbox" name="<?php echo $field( '[reset]' ); ?>" value="1" onchange="if(this.checked&&!confirm('Minden beállítás visszaáll az alapértékre. Biztos?')){this.checked=false;}"> Alapértékek visszaállítása mentéskor</label></p>
		</form>

		<h2 class="title">Ellenőrzés</h2>
		<p>Mentés után (és a gyorsítótár ürítése után) nézd meg a Google Rich Results Testben:</p>
		<ul>
			<li><a href="<?php echo esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( home_url( '/' ) ) ); ?>" target="_blank" rel="noopener">Főoldal</a></li>
			<?php foreach ( $s['services'] as $row ) : ?>
				<li><a href="<?php echo esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( home_url( $row['path'] ) ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['name'] ); ?></a> <code><?php echo esc_html( $row['path'] ); ?></code></li>
			<?php endforeach; ?>
		</ul>

		<details>
			<summary>Technikai előnézet: a cég adatai a schemában</summary>
			<?php
			$preview = hpv_seo_fix_organization(
				array(
					'@type' => array( 'Organization', 'Place', 'ProfessionalService' ),
					'@id'   => home_url( '/#organization' ),
				),
				hpv_seo_config()
			);
			?>
			<pre style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-width:900px;overflow:auto"><?php echo esc_html( wp_json_encode( $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
		</details>
	</div>
	<?php
}
