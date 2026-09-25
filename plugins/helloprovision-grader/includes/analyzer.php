<?php
/**
 * A weboldal-elemzés logikája. WordPress-függvényeket nem használ, így önállóan tesztelhető.
 *
 * Minden ellenőrzés egy tömb: id, category, status (pass|warn|fail), weight, title, detail, fix.
 * A „fix” (javítási javaslat) a zárolt rész — csak e-mail cím megadása után adjuk ki.
 */

defined( 'ABSPATH' ) || exit;

const HPV_GRADER_CATEGORIES = array(
	'speed'  => array(
		'label'  => 'Speed',
		'weight' => 25,
	),
	'mobile' => array(
		'label'  => 'Mobile experience',
		'weight' => 15,
	),
	'seo'    => array(
		'label'  => 'SEO basics',
		'weight' => 20,
	),
	'schema' => array(
		'label'  => 'Schema markup',
		'weight' => 15,
	),
	'local'  => array(
		'label'  => 'Local business info',
		'weight' => 25,
	),
);

// Hosszabb nevek előre, hogy a „Fort Myers Beach” ne „Fort Myers”-ként számítson.
const HPV_GRADER_SWFL_PLACES = array(
	'Fort Myers Beach',
	'North Fort Myers',
	'Fort Myers',
	'Cape Coral',
	'Naples',
	'Bonita Springs',
	'Estero',
	'Lehigh Acres',
	'Sanibel',
	'Captiva',
	'Marco Island',
	'Punta Gorda',
	'Port Charlotte',
	'Immokalee',
	'Pine Island',
	'Lee County',
	'Collier County',
	'Charlotte County',
	'Southwest Florida',
	'SWFL',
);

// Régiók: jók a szövegben, de a városnév-keresésekhez konkrét város kell a címben.
const HPV_GRADER_SWFL_REGIONS = array( 'Lee County', 'Collier County', 'Charlotte County', 'Southwest Florida', 'SWFL' );

function hpv_grader_check( string $id, string $category, string $status, int $weight, string $title, string $detail, string $fix = '' ): array {
	return array(
		'id'       => $id,
		'category' => $category,
		'status'   => $status,
		'weight'   => $weight,
		'title'    => $title,
		'detail'   => $detail,
		'fix'      => 'pass' === $status ? '' : $fix,
	);
}

/**
 * "example.com" → "https://example.com/". Érvénytelen bemenetre üres string.
 */
function hpv_grader_normalize_url( string $input ): string {
	$input = trim( $input );
	if ( '' === $input || strlen( $input ) > 2000 || preg_match( '/\s/', $input ) ) {
		return '';
	}
	if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $input ) ) {
		$input = 'https://' . $input;
	}

	$parts = parse_url( $input );
	if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
		return '';
	}

	$host = strtolower( $parts['host'] );
	if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || false === strpos( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return '';
	}
	if ( ! preg_match( '/^[a-z0-9.-]+$/', $host ) || ! preg_match( '/\.[a-z]{2,}$/', $host ) ) {
		return '';
	}

	$url = strtolower( $parts['scheme'] ) . '://' . $host;
	if ( isset( $parts['port'] ) ) {
		$url .= ':' . (int) $parts['port'];
	}
	$url .= $parts['path'] ?? '/';
	if ( isset( $parts['query'] ) ) {
		$url .= '?' . $parts['query'];
	}

	return $url;
}

/* ─── HTML elemzés ────────────────────────────────────────── */

/**
 * @param string $html A letöltött oldal.
 * @param array  $ctx  url (végső URL), headers (kisbetűs kulcsok), time (mp), robots (null|array{found,body}), sitemap (bool|null).
 */
function hpv_grader_analyze_page( string $html, array $ctx ): array {
	$page   = hpv_grader_parse_html( $html );
	$schema = hpv_grader_schema_business( $page['jsonld'] );

	return array_merge(
		array( hpv_grader_server_check( (float) ( $ctx['time'] ?? 0 ) ) ),
		hpv_grader_mobile_checks( $page ),
		hpv_grader_seo_checks( $page, $ctx ),
		hpv_grader_schema_checks( $page, $schema ),
		hpv_grader_local_checks( $page, $schema )
	);
}

function hpv_grader_parse_html( string $html ): array {
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NONET );
	libxml_clear_errors();
	$xp = new DOMXPath( $doc );

	$page = array(
		'title'       => '',
		'meta'        => array(),
		'links'       => array(),
		'lang'        => '',
		'h1'          => array(),
		'images'      => 0,
		'img_no_alt'  => 0,
		'img_resp'    => 0,
		'hrefs'       => array(),
		'iframes'     => array(),
		'jsonld'      => array(),
		'jsonld_bad'  => 0,
		'text'        => '',
	);

	$title = $xp->query( '//title' )->item( 0 );
	if ( $title ) {
		$page['title'] = hpv_grader_clean_text( $title->textContent );
	}

	foreach ( $xp->query( '//meta' ) as $meta ) {
		$key = strtolower( $meta->getAttribute( 'name' ) ?: $meta->getAttribute( 'property' ) );
		if ( '' !== $key && ! isset( $page['meta'][ $key ] ) ) {
			$page['meta'][ $key ] = trim( $meta->getAttribute( 'content' ) );
		}
	}

	foreach ( $xp->query( '//link[@rel]' ) as $link ) {
		foreach ( preg_split( '/\s+/', strtolower( trim( $link->getAttribute( 'rel' ) ) ) ) as $rel ) {
			if ( ! isset( $page['links'][ $rel ] ) ) {
				$page['links'][ $rel ] = $link->getAttribute( 'href' );
			}
		}
	}

	$html_el = $xp->query( '//html' )->item( 0 );
	if ( $html_el ) {
		$page['lang'] = trim( $html_el->getAttribute( 'lang' ) );
	}

	foreach ( $xp->query( '//h1' ) as $h1 ) {
		$page['h1'][] = hpv_grader_clean_text( $h1->textContent );
	}

	foreach ( $xp->query( '//img' ) as $img ) {
		$page['images']++;
		if ( ! $img->hasAttribute( 'alt' ) ) {
			$page['img_no_alt']++;
		}
		$src = strtolower( $img->getAttribute( 'src' ) );
		if ( $img->hasAttribute( 'srcset' ) || 'picture' === strtolower( $img->parentNode->nodeName ) || preg_match( '/\.svg(\?|$)/', $src ) ) {
			$page['img_resp']++;
		}
	}

	foreach ( $xp->query( '//a[@href]' ) as $a ) {
		$page['hrefs'][] = array(
			'href' => trim( $a->getAttribute( 'href' ) ),
			'text' => hpv_grader_clean_text( $a->textContent ),
		);
	}

	foreach ( $xp->query( '//iframe' ) as $iframe ) {
		$page['iframes'][] = $iframe->getAttribute( 'src' ) ?: $iframe->getAttribute( 'data-src' );
	}

	foreach ( $xp->query( '//script' ) as $script ) {
		if ( false !== stripos( $script->getAttribute( 'type' ), 'ld+json' ) ) {
			$data = json_decode( trim( $script->textContent ), true );
			if ( is_array( $data ) ) {
				$page['jsonld'][] = $data;
			} else {
				$page['jsonld_bad']++;
			}
		}
	}

	// A látható szöveghez a szkripteket, stílusokat kivesszük.
	foreach ( iterator_to_array( $xp->query( '//script|//style|//noscript|//template|//svg' ) ) as $node ) {
		$node->parentNode->removeChild( $node );
	}
	$body = $xp->query( '//body' )->item( 0 );
	if ( $body ) {
		$page['text'] = hpv_grader_clean_text( $body->textContent );
	}

	return $page;
}

function hpv_grader_clean_text( string $text ): string {
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

/**
 * Amerikai telefonszámok 10 jegyű formában.
 */
function hpv_grader_find_phones( string $text ): array {
	preg_match_all( '/(?<![\d\/.-])(?:\+?1[\s.\-]?)?\(?([2-9]\d{2})\)?[\s.\-]?([2-9]\d{2})[\s.\-]?(\d{4})(?![\d\/])/', $text, $m, PREG_SET_ORDER );

	$phones = array();
	foreach ( $m as $match ) {
		$phones[] = $match[1] . $match[2] . $match[3];
	}

	return array_values( array_unique( $phones ) );
}

function hpv_grader_phone_digits( string $phone ): string {
	$digits = preg_replace( '/\D/', '', $phone );
	if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
		$digits = substr( $digits, 1 );
	}

	return $digits;
}

function hpv_grader_has_street_address( string $text ): bool {
	return (bool) preg_match(
		'/\b\d{1,6}\s+(?:[NSEW]\.?\s+)?[A-Za-z0-9.\'\- ]{2,50}?\s(?:St|Street|Ave|Avenue|Blvd|Boulevard|Rd|Road|Dr|Drive|Ln|Lane|Way|Pkwy|Parkway|Ct|Court|Cir|Circle|Pl|Place|Hwy|Highway|Trl|Trail|Ter|Terrace|Pkwy|Loop|Plaza|Sq|Square)\b\.?.{0,60}?\b(?:FL|Florida)\b,?\s*\d{5}/i',
		$text
	);
}

function hpv_grader_find_places( string $text ): array {
	$found = array();
	foreach ( HPV_GRADER_SWFL_PLACES as $place ) {
		if ( preg_match( '/\b' . preg_quote( $place, '/' ) . '\b/i', $text ) ) {
			$found[] = $place;
			$text    = preg_replace( '/\b' . preg_quote( $place, '/' ) . '\b/i', ' ', $text );
		}
	}

	return $found;
}

/* ─── Schema ──────────────────────────────────────────────── */

/**
 * Az összes JSON-LD csomópont laposan, @id szerint is kereshetően.
 */
function hpv_grader_schema_nodes( array $blocks ): array {
	$nodes = array();
	$walk  = function ( $value ) use ( &$walk, &$nodes ) {
		if ( ! is_array( $value ) ) {
			return;
		}
		if ( isset( $value['@type'] ) ) {
			$nodes[] = $value;
		}
		foreach ( $value as $child ) {
			$walk( $child );
		}
	};
	$walk( $blocks );

	return $nodes;
}

function hpv_grader_schema_types( array $node ): array {
	return array_map( 'strval', (array) ( $node['@type'] ?? array() ) );
}

/**
 * A cég schema adatai (Yoast-szerű @id hivatkozásokat is feloldva).
 */
function hpv_grader_schema_business( array $blocks ): array {
	$nodes = hpv_grader_schema_nodes( $blocks );
	$ids   = array();
	foreach ( $nodes as $node ) {
		if ( isset( $node['@id'] ) && is_string( $node['@id'] ) && count( $node ) > 2 ) {
			$ids[ $node['@id'] ] = $node;
		}
	}
	$resolve = function ( $value ) use ( $ids ) {
		if ( is_array( $value ) && isset( $value['@id'] ) && 1 === count( $value ) && isset( $ids[ $value['@id'] ] ) ) {
			return $ids[ $value['@id'] ];
		}
		return $value;
	};

	$local_re = '/LocalBusiness|Store|Service|Agency|Contractor|Restaurant|Dentist|Attorney|Physician|Clinic|Plumber|Electrician|HVAC|RealEstate|Hotel|Salon|Spa|Repair|Legal|Accounting|Insurance|Medical|Roofing|Locksmith|Mover|Shop|Dealer|Doctor|Lodging|FoodEstablishment|HealthAndBeauty|HomeAndConstruction|Financial|Automotive|Entertainment|Sports|Veterinary|Optician|Pharmacy|Notary|Travel|ChildCare|Dry|Employment|Library|School/';

	$business = null;
	$is_local = false;
	foreach ( $nodes as $node ) {
		$types = implode( ' ', hpv_grader_schema_types( $node ) );
		if ( preg_match( $local_re, $types ) ) {
			$business = $node;
			$is_local = true;
			break;
		}
		if ( null === $business && preg_match( '/\b(Organization|Corporation)\b/', $types ) ) {
			$business = $node;
		}
	}

	if ( null === $business ) {
		return array(
			'found'    => false,
			'is_local' => false,
			'types'    => array(),
		);
	}

	$address = $resolve( $business['address'] ?? null );
	if ( is_array( $address ) && isset( $address[0] ) ) {
		$address = $resolve( $address[0] );
	}
	$phone = $business['telephone'] ?? '';
	if ( is_array( $phone ) ) {
		$phone = (string) reset( $phone );
	}

	return array(
		'found'    => true,
		'is_local' => $is_local,
		'types'    => hpv_grader_schema_types( $business ),
		'name'     => is_string( $business['name'] ?? null ) ? $business['name'] : '',
		'address'  => is_array( $address ) ? $address : array(),
		'phone'    => (string) $phone,
		'hours'    => ! empty( $business['openingHoursSpecification'] ) || ! empty( $business['openingHours'] ),
		'same_as'  => array_values( array_filter( (array) ( $business['sameAs'] ?? array() ), 'is_string' ) ),
		'area'     => ! empty( $business['areaServed'] ),
		'geo'      => ! empty( $business['geo'] ) || ! empty( $business['hasMap'] ),
	);
}

/* ─── Ellenőrzések kategóriánként ─────────────────────────── */

function hpv_grader_server_check( float $time ): array {
	$ms     = (int) round( $time * 1000 );
	$status = $time <= 0.8 ? 'pass' : ( $time <= 1.8 ? 'warn' : 'fail' );

	return hpv_grader_check(
		'speed_server',
		'speed',
		$status,
		1,
		'Server response time',
		sprintf( 'Your homepage HTML was delivered in %s ms.', number_format( $ms ) ),
		'Slow server responses delay everything else. Enable full-page caching (e.g. a caching plugin or your host\'s cache), use a CDN, and move off overloaded shared hosting if response times stay above one second.'
	);
}

function hpv_grader_mobile_checks( array $page ): array {
	$checks = array();

	$viewport = $page['meta']['viewport'] ?? null;
	if ( null === $viewport ) {
		$checks[] = hpv_grader_check( 'mobile_viewport', 'mobile', 'fail', 3, 'Mobile viewport', 'No viewport meta tag found — phones will show a shrunken desktop page.', 'Add <meta name="viewport" content="width=device-width, initial-scale=1"> to the <head> and make sure the layout is responsive. Google ranks the mobile version of your site.' );
	} elseif ( false === stripos( $viewport, 'width=device-width' ) ) {
		$checks[] = hpv_grader_check( 'mobile_viewport', 'mobile', 'warn', 3, 'Mobile viewport', 'A viewport tag exists but does not use width=device-width.', 'Set the viewport to width=device-width, initial-scale=1 so the page scales correctly on every phone.' );
	} else {
		$checks[] = hpv_grader_check( 'mobile_viewport', 'mobile', 'pass', 3, 'Mobile viewport', 'The page is set up to scale to phone screens.' );
	}

	$tel    = count( array_filter( $page['hrefs'], fn( $a ) => 0 === stripos( $a['href'], 'tel:' ) ) );
	$phones = hpv_grader_find_phones( $page['text'] );
	if ( $tel ) {
		$checks[] = hpv_grader_check( 'mobile_tel', 'mobile', 'pass', 2, 'Tap-to-call phone link', 'Visitors on a phone can call you with one tap.' );
	} elseif ( $phones ) {
		$checks[] = hpv_grader_check( 'mobile_tel', 'mobile', 'warn', 2, 'Tap-to-call phone link', 'Your phone number is shown, but it is not a tap-to-call link.', 'Wrap the phone number in a tel: link (e.g. <a href="tel:+12395550123">). Most local searches happen on phones, and a tap-to-call link turns them into calls.' );
	} else {
		$checks[] = hpv_grader_check( 'mobile_tel', 'mobile', 'fail', 2, 'Tap-to-call phone link', 'No phone number or tap-to-call link found on the homepage.', 'Show your phone number in the header or hero and make it a tel: link. Local customers on mobile expect to call with one tap.' );
	}

	if ( 0 === $page['images'] ) {
		$checks[] = hpv_grader_check( 'mobile_images', 'mobile', 'pass', 1, 'Responsive images', 'No images found on the homepage.' );
	} else {
		$ratio    = $page['img_resp'] / $page['images'];
		$detail   = sprintf( '%d of %d images serve phone-sized versions (srcset, <picture> or SVG).', $page['img_resp'], $page['images'] );
		$checks[] = hpv_grader_check( 'mobile_images', 'mobile', $ratio >= 0.5 ? 'pass' : 'warn', 1, 'Responsive images', $detail, 'Serve smaller images to phones with srcset/<picture> (WordPress does this automatically for images inserted from the Media Library) and use modern formats like WebP or AVIF.' );
	}

	$icon     = isset( $page['links']['apple-touch-icon'] ) || isset( $page['links']['icon'] );
	$checks[] = hpv_grader_check( 'mobile_icon', 'mobile', $icon ? 'pass' : 'warn', 1, 'Site icon', $icon ? 'A site icon is set for browser tabs, bookmarks and home screens.' : 'No site icon (favicon / apple-touch-icon) found.', 'Add a square site icon (at least 512×512 px). In WordPress: Appearance → Customize → Site Identity. Google also shows it next to your result on mobile.' );

	return $checks;
}

function hpv_grader_seo_checks( array $page, array $ctx ): array {
	$checks = array();

	$title = $page['title'];
	$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
	if ( '' === $title ) {
		$checks[] = hpv_grader_check( 'seo_title', 'seo', 'fail', 2, 'Page title', 'The homepage has no <title>.', 'Write a unique title of 50–60 characters: main service + city + brand, e.g. "Roof Repair in Cape Coral, FL | Brand Name".' );
	} elseif ( $len < 30 || $len > 65 ) {
		$checks[] = hpv_grader_check( 'seo_title', 'seo', 'warn', 2, 'Page title', sprintf( '"%s" — %d characters.', $title, $len ), $len < 30 ? 'The title is short. Use 50–60 characters and include your main service and city.' : 'The title is long and will be cut off in Google. Keep it around 50–60 characters, with the service and city first.' );
	} else {
		$checks[] = hpv_grader_check( 'seo_title', 'seo', 'pass', 2, 'Page title', sprintf( '"%s" — %d characters.', $title, $len ) );
	}

	$desc = $page['meta']['description'] ?? '';
	$dlen = function_exists( 'mb_strlen' ) ? mb_strlen( $desc ) : strlen( $desc );
	if ( '' === $desc ) {
		$checks[] = hpv_grader_check( 'seo_description', 'seo', 'fail', 2, 'Meta description', 'No meta description found.', 'Write a 120–155 character description that names your service, your area (e.g. Fort Myers, Cape Coral) and a reason to click. It is your ad text in Google.' );
	} elseif ( $dlen < 70 || $dlen > 160 ) {
		$checks[] = hpv_grader_check( 'seo_description', 'seo', 'warn', 2, 'Meta description', sprintf( '%d characters.', $dlen ), 'Aim for 120–155 characters so Google shows the full description — include the service, the city and a clear call to action.' );
	} else {
		$checks[] = hpv_grader_check( 'seo_description', 'seo', 'pass', 2, 'Meta description', sprintf( '%d characters.', $dlen ) );
	}

	$h1 = array_values( array_filter( $page['h1'], 'strlen' ) );
	if ( ! $h1 ) {
		$checks[] = hpv_grader_check( 'seo_h1', 'seo', 'fail', 2, 'Main heading (H1)', 'No H1 heading with text found.', 'Give the page one H1 that says what you do and where, e.g. "Pool Service in Cape Coral & Fort Myers". Animated or image-only headings often leave the H1 empty for Google.' );
	} elseif ( count( $h1 ) > 1 ) {
		$checks[] = hpv_grader_check( 'seo_h1', 'seo', 'warn', 2, 'Main heading (H1)', sprintf( '%d H1 headings found: "%s".', count( $h1 ), implode( '", "', array_slice( $h1, 0, 3 ) ) ), 'Use a single H1 for the main topic of the page and H2/H3 for sections.' );
	} elseif ( preg_match( '/^(?:\p{Ll}|(?:for|and|with|in|of|to|by|the|a|an)\s)/u', $h1[0] ) ) {
		// Pl. animált címsornál a kulcsszó a H1-en kívül marad, és csak a töredék kerül bele.
		$checks[] = hpv_grader_check( 'seo_h1', 'seo', 'warn', 2, 'Main heading (H1)', sprintf( 'The H1 reads like a fragment: "%s".', $h1[0] ), 'Put the full phrase in the H1 — service and area, e.g. "Web Design & SEO for Southwest Florida Businesses". Words shown by animations or in a separate element next to the H1 do not count as part of it.' );
	} else {
		$checks[] = hpv_grader_check( 'seo_h1', 'seo', 'pass', 2, 'Main heading (H1)', sprintf( '"%s"', $h1[0] ) );
	}

	$robots_meta = strtolower( ( $page['meta']['robots'] ?? '' ) . ' ' . ( $page['meta']['googlebot'] ?? '' ) . ' ' . ( $ctx['headers']['x-robots-tag'] ?? '' ) );
	if ( false !== strpos( $robots_meta, 'noindex' ) ) {
		$checks[] = hpv_grader_check( 'seo_indexable', 'seo', 'fail', 3, 'Visible to Google', 'The page tells search engines not to index it (noindex).', 'Remove the noindex directive. In WordPress check Settings → Reading → "Discourage search engines" and your SEO plugin\'s page settings.' );
	} else {
		$checks[] = hpv_grader_check( 'seo_indexable', 'seo', 'pass', 3, 'Visible to Google', 'The page allows search engines to index it.' );
	}

	$https    = 0 === strpos( (string) ( $ctx['url'] ?? '' ), 'https://' );
	$checks[] = hpv_grader_check( 'seo_https', 'seo', $https ? 'pass' : 'fail', 2, 'Secure connection (HTTPS)', $https ? 'The site loads over HTTPS.' : 'The site loads without HTTPS — browsers mark it "Not secure".', 'Install an SSL certificate (most hosts offer free Let\'s Encrypt certificates) and redirect all http:// traffic to https://.' );

	$canonical = $page['links']['canonical'] ?? '';
	$checks[]  = hpv_grader_check( 'seo_canonical', 'seo', '' !== $canonical ? 'pass' : 'warn', 1, 'Canonical URL', '' !== $canonical ? 'The page declares its preferred URL.' : 'No canonical tag found.', 'Add a canonical link tag so Google knows the preferred version of each page. SEO plugins like Yoast or Rank Math add it automatically.' );

	if ( $page['images'] > 0 ) {
		$missing  = $page['img_no_alt'] / $page['images'];
		$status   = $missing <= 0.1 ? 'pass' : ( $missing <= 0.3 ? 'warn' : 'fail' );
		$checks[] = hpv_grader_check( 'seo_alt', 'seo', $status, 1, 'Image alt text', sprintf( '%d of %d images are missing an alt attribute.', $page['img_no_alt'], $page['images'] ), 'Describe each meaningful image in its alt text (e.g. "Kitchen remodel in Cape Coral with white quartz counters"). It helps accessibility and image search.' );
	}

	$checks[] = hpv_grader_check( 'seo_lang', 'seo', '' !== $page['lang'] ? 'pass' : 'warn', 1, 'Page language', '' !== $page['lang'] ? sprintf( 'Language is set to "%s".', $page['lang'] ) : 'The page does not declare its language.', 'Add lang="en-US" to the <html> tag so search engines and screen readers know the page language.' );

	$og       = ( isset( $page['meta']['og:title'] ) ? 1 : 0 ) + ( isset( $page['meta']['og:image'] ) ? 1 : 0 );
	$checks[] = hpv_grader_check( 'seo_social', 'seo', 2 === $og ? 'pass' : 'warn', 1, 'Social sharing preview', 2 === $og ? 'Title and image are set for Facebook, LinkedIn and text-message previews.' : 'The Open Graph title or image is missing.', 'Add og:title and og:image (1200×630 px) so links shared on Facebook, LinkedIn and in text messages show a proper preview card.' );

	$robots = $ctx['robots'] ?? null;
	if ( is_array( $robots ) && ! empty( $robots['found'] ) ) {
		$blocks_all = (bool) preg_match( '/user-agent:\s*\*\s*(?:\r?\n(?!\s*user-agent:).*)*?\r?\n\s*disallow:\s*\/\s*(?:\r?\n|$)/i', $robots['body'] ?? '' );
		$checks[]   = $blocks_all
			? hpv_grader_check( 'seo_robots', 'seo', 'fail', 2, 'robots.txt', 'robots.txt blocks all search engines ("Disallow: /").', 'Remove "Disallow: /" from robots.txt — right now it tells every search engine to stay away from the whole site.' )
			: hpv_grader_check( 'seo_robots', 'seo', 'pass', 1, 'robots.txt', 'robots.txt is in place and does not block the site.' );
	} elseif ( is_array( $robots ) ) {
		$checks[] = hpv_grader_check( 'seo_robots', 'seo', 'warn', 1, 'robots.txt', 'No robots.txt file found.', 'Add a robots.txt at the site root that allows crawling and lists your XML sitemap (Sitemap: https://yoursite.com/sitemap_index.xml).' );
	}

	if ( isset( $ctx['sitemap'] ) && null !== $ctx['sitemap'] ) {
		$checks[] = hpv_grader_check( 'seo_sitemap', 'seo', $ctx['sitemap'] ? 'pass' : 'warn', 1, 'XML sitemap', $ctx['sitemap'] ? 'An XML sitemap was found.' : 'No XML sitemap found at the usual locations.', 'Create an XML sitemap (Yoast and Rank Math do it automatically), then submit it in Google Search Console so new pages get found faster.' );
	}

	return $checks;
}

function hpv_grader_schema_checks( array $page, array $b ): array {
	$checks = array();

	if ( ! $page['jsonld'] ) {
		$checks[] = hpv_grader_check( 'schema_present', 'schema', 'fail', 2, 'Structured data (schema)', $page['jsonld_bad'] ? 'Schema blocks were found but could not be read (invalid JSON).' : 'No JSON-LD structured data found.', 'Add JSON-LD structured data describing your business. It is how Google connects your website to your Business Profile, reviews and service area.' );
	} elseif ( $page['jsonld_bad'] ) {
		$checks[] = hpv_grader_check( 'schema_present', 'schema', 'warn', 2, 'Structured data (schema)', sprintf( '%d schema block(s) contain invalid JSON and are ignored by Google.', $page['jsonld_bad'] ), 'Fix the broken JSON-LD blocks — test them in Google\'s Rich Results Test (search.google.com/test/rich-results).' );
	} else {
		$checks[] = hpv_grader_check( 'schema_present', 'schema', 'pass', 2, 'Structured data (schema)', sprintf( '%d JSON-LD block(s) found.', count( $page['jsonld'] ) ) );
	}

	if ( ! $b['found'] ) {
		$checks[] = hpv_grader_check( 'schema_business', 'schema', 'fail', 3, 'Business type markup', 'No LocalBusiness or Organization markup found.', 'Add LocalBusiness markup using the most specific type (e.g. Plumber, Dentist, HVACBusiness, ProfessionalService). This is the core signal for local search.' );
		$missing  = 'No business markup to check.';
		$checks[] = hpv_grader_check( 'schema_nap', 'schema', 'fail', 3, 'Name, address & phone in schema', $missing, 'Include name, full address (street, city, ZIP) and phone number in the LocalBusiness markup — exactly as they appear on your Google Business Profile.' );
		$checks[] = hpv_grader_check( 'schema_hours', 'schema', 'warn', 1, 'Opening hours in schema', $missing, 'Add openingHoursSpecification that matches the hours on your Google Business Profile.' );
		$checks[] = hpv_grader_check( 'schema_sameas', 'schema', 'warn', 1, 'Profile links (sameAs)', $missing, 'List your Google Business Profile, Facebook, LinkedIn, Yelp and other official profiles in sameAs so Google ties them to one business.' );
		$checks[] = hpv_grader_check( 'schema_area', 'schema', 'warn', 1, 'Service area / location', $missing, 'Add areaServed (the cities you serve, e.g. Fort Myers, Cape Coral) and geo coordinates for your location.' );
		return $checks;
	}

	$types = implode( ', ', $b['types'] );
	$checks[] = $b['is_local']
		? hpv_grader_check( 'schema_business', 'schema', 'pass', 3, 'Business type markup', sprintf( 'Marked up as: %s.', $types ) )
		: hpv_grader_check( 'schema_business', 'schema', 'warn', 3, 'Business type markup', sprintf( 'Marked up only as a general %s.', $types ), 'Use LocalBusiness or a more specific subtype (e.g. Plumber, Dentist, HVACBusiness, ProfessionalService) instead of a plain Organization, so Google treats you as a local business.' );

	$missing = array();
	if ( '' === $b['name'] ) {
		$missing[] = 'name';
	}
	foreach ( array( 'streetAddress' => 'street', 'addressLocality' => 'city', 'postalCode' => 'ZIP code' ) as $key => $label ) {
		if ( empty( $b['address'][ $key ] ) ) {
			$missing[] = $label;
		}
	}
	if ( '' === $b['phone'] ) {
		$missing[] = 'phone';
	}
	if ( ! $missing ) {
		$checks[] = hpv_grader_check( 'schema_nap', 'schema', 'pass', 3, 'Name, address & phone in schema', 'Name, full address and phone are all included.' );
	} else {
		$service_area = ! empty( $b['address'] ) || $b['area'];
		$checks[]     = hpv_grader_check( 'schema_nap', 'schema', count( $missing ) >= 3 && ! $service_area ? 'fail' : 'warn', 3, 'Name, address & phone in schema', 'Missing: ' . implode( ', ', $missing ) . '.', 'Complete the name, address and phone in your business markup, matching your Google Business Profile character for character. Service-area businesses without a public address should still include city, ZIP and areaServed.' );
	}

	$checks[] = hpv_grader_check( 'schema_hours', 'schema', $b['hours'] ? 'pass' : 'warn', 1, 'Opening hours in schema', $b['hours'] ? 'Opening hours are included.' : 'No opening hours in the markup.', 'Add openingHoursSpecification that matches the hours on your Google Business Profile, including holiday changes.' );

	$n        = count( $b['same_as'] );
	$checks[] = hpv_grader_check( 'schema_sameas', 'schema', $n >= 2 ? 'pass' : 'warn', 1, 'Profile links (sameAs)', sprintf( '%d official profile link(s) listed.', $n ), 'List your Google Business Profile, Facebook, LinkedIn, Yelp, BBB and other official profiles in sameAs so Google ties them all to one business.' );

	$area     = $b['area'] || $b['geo'];
	$checks[] = hpv_grader_check( 'schema_area', 'schema', $area ? 'pass' : 'warn', 1, 'Service area / location', $area ? 'Service area or map location is included.' : 'No areaServed or geo coordinates in the markup.', 'Add areaServed with the cities you serve (e.g. Fort Myers, Cape Coral, Naples) and geo coordinates of your location.' );

	return $checks;
}

function hpv_grader_local_checks( array $page, array $b ): array {
	$checks = array();
	$phones = hpv_grader_find_phones( $page['text'] );
	foreach ( $page['hrefs'] as $a ) {
		if ( 0 === stripos( $a['href'], 'tel:' ) ) {
			$digits = hpv_grader_phone_digits( substr( $a['href'], 4 ) );
			if ( 10 === strlen( $digits ) ) {
				$phones[] = $digits;
			}
		}
	}
	$phones = array_values( array_unique( $phones ) );

	$checks[] = $phones
		? hpv_grader_check( 'local_phone', 'local', 'pass', 3, 'Phone number on the page', 'A phone number is visible on the homepage.' )
		: hpv_grader_check( 'local_phone', 'local', 'fail', 3, 'Phone number on the page', 'No phone number found on the homepage.', 'Show a local phone number (ideally a 239 number) in the header and footer. It is a trust signal for customers and a consistency signal for Google.' );

	if ( hpv_grader_has_street_address( $page['text'] ) ) {
		$checks[] = hpv_grader_check( 'local_address', 'local', 'pass', 2, 'Business address on the page', 'A street address is visible on the homepage.' );
	} else {
		$checks[] = hpv_grader_check( 'local_address', 'local', 'warn', 2, 'Business address on the page', ! empty( $b['address']['streetAddress'] ) ? 'The address is only in the hidden markup, not visible on the page.' : 'No street address found on the homepage.', 'Show your address in the footer exactly as on your Google Business Profile. Service-area businesses without a storefront should list the cities they serve instead.' );
	}

	$in_title = hpv_grader_find_places( $page['title'] . ' ' . implode( ' ', $page['h1'] ) );
	$in_body  = hpv_grader_find_places( $page['text'] );
	$cities = array_values( array_diff( $in_title, HPV_GRADER_SWFL_REGIONS ) );
	if ( $cities ) {
		$checks[] = hpv_grader_check( 'local_city', 'local', 'pass', 3, 'Local area in title & heading', sprintf( 'Mentioned in the title or H1: %s.', implode( ', ', $in_title ) ) );
	} elseif ( $in_title ) {
		$checks[] = hpv_grader_check( 'local_city', 'local', 'warn', 3, 'Local area in title & heading', sprintf( 'Only the region is named in the title or H1 (%s), not a city.', implode( ', ', $in_title ) ), 'People search by city ("web design Fort Myers", "plumber Cape Coral"), not by region. Name your main city in the homepage title or H1, and give every other city you serve its own page.' );
	} elseif ( $in_body ) {
		$checks[] = hpv_grader_check( 'local_city', 'local', 'warn', 3, 'Local area in title & heading', sprintf( 'Your area appears on the page (%s), but not in the title or H1.', implode( ', ', array_slice( $in_body, 0, 4 ) ) ), 'Put your main city in the page title and H1 (e.g. "Pest Control in Fort Myers & Cape Coral"). These are the strongest on-page signals for "near me" and city searches.' );
	} else {
		$checks[] = hpv_grader_check( 'local_city', 'local', 'fail', 3, 'Local area in title & heading', 'No Southwest Florida city or area mentioned on the homepage.', 'Name the cities you serve — in the title, the H1 and the body text — and create a dedicated page for each main city you want to rank in.' );
	}

	$maps = false;
	foreach ( $page['iframes'] as $src ) {
		if ( preg_match( '#google\.[a-z.]+/maps#i', (string) $src ) ) {
			$maps = true;
		}
	}
	$google_profile = false;
	foreach ( $page['hrefs'] as $a ) {
		$href = strtolower( $a['href'] );
		if ( preg_match( '#(google\.[a-z.]+/maps|maps\.app\.goo\.gl|goo\.gl/maps)#', $href ) ) {
			$maps = true;
		}
		if ( preg_match( '#(g\.page/|maps\.app\.goo\.gl|search\.google\.com/local|google\.[a-z.]+/maps.*(cid=|place/)|g\.co/kgs)#', $href ) ) {
			$google_profile = true;
		}
	}
	$checks[] = hpv_grader_check( 'local_map', 'local', $maps ? 'pass' : 'warn', 1, 'Map & directions', $maps ? 'A Google Map or directions link is on the page.' : 'No Google Map or directions link found.', 'Embed a Google Map of your Business Profile on the contact page and link "Get directions" from the homepage.' );
	$checks[] = hpv_grader_check( 'local_google', 'local', $google_profile ? 'pass' : 'warn', 2, 'Link to your Google reviews', $google_profile ? 'The site links to your Google Business Profile or reviews.' : 'No link to your Google Business Profile or Google reviews.', 'Link to your Google Business Profile ("Read our Google reviews" / "Leave a review"). It builds trust and helps Google connect the website with your profile. Reviews are one of the strongest local ranking factors.' );

	$schema_phone = hpv_grader_phone_digits( $b['phone'] ?? '' );
	if ( 10 === strlen( $schema_phone ) && $phones ) {
		$match    = in_array( $schema_phone, $phones, true );
		$checks[] = $match
			? hpv_grader_check( 'local_nap_match', 'local', 'pass', 2, 'Consistent business details', 'The phone number in your markup matches the one on the page.' )
			: hpv_grader_check( 'local_nap_match', 'local', 'fail', 2, 'Consistent business details', 'The phone number in your markup does not match the phone number on the page.', 'Use one phone number everywhere — website, schema, Google Business Profile, Yelp, Facebook. Mismatched details confuse Google and cost map rankings.' );
	} else {
		$checks[] = hpv_grader_check( 'local_nap_match', 'local', 'warn', 2, 'Consistent business details', 'We could not compare your page details with your business markup.', 'Make sure your name, address and phone are identical on the website, in the schema markup and on your Google Business Profile — even small differences ("Ste" vs "Suite") matter.' );
	}

	$contact = false;
	foreach ( $page['hrefs'] as $a ) {
		if ( preg_match( '#contact|get-a-quote|free-estimate|book#i', $a['href'] ) || preg_match( '/contact|free estimate|get a quote|book/i', $a['text'] ) ) {
			$contact = true;
			break;
		}
	}
	$checks[] = hpv_grader_check( 'local_contact', 'local', $contact ? 'pass' : 'warn', 1, 'Contact / quote page', $contact ? 'Visitors can easily reach a contact or booking page.' : 'No clear link to a contact, quote or booking page.', 'Add a prominent "Get a Quote" or "Contact Us" button in the header so ready-to-buy visitors don\'t have to search for it.' );

	return $checks;
}

/* ─── Google PageSpeed Insights ───────────────────────────── */

/**
 * A PageSpeed Insights v5 válaszából (mobil) készít ellenőrzéseket.
 */
function hpv_grader_psi_checks( array $psi ): array {
	$lh     = $psi['lighthouseResult'] ?? array();
	$audits = $lh['audits'] ?? array();
	$score  = $lh['categories']['performance']['score'] ?? null;
	if ( null === $score ) {
		return array();
	}

	$field = $psi['loadingExperience']['metrics'] ?? array();
	$num   = function ( $key ) use ( $audits ) {
		return isset( $audits[ $key ]['numericValue'] ) ? (float) $audits[ $key ]['numericValue'] : null;
	};

	$checks = array();
	$score  = (int) round( $score * 100 );
	$checks[] = hpv_grader_check(
		'speed_score',
		'speed',
		$score >= 90 ? 'pass' : ( $score >= 50 ? 'warn' : 'fail' ),
		4,
		'Google mobile speed score',
		sprintf( '%d / 100 on Google PageSpeed Insights (mobile).', $score ),
		'Compress and resize images (WebP/AVIF), lazy-load below-the-fold media, remove unused plugins and scripts, and use page caching. Heavy sliders, video backgrounds and animation libraries are the usual culprits on mobile.'
	);

	$lcp = $num( 'largest-contentful-paint' );
	if ( null !== $lcp ) {
		$sec    = $lcp / 1000;
		$detail = sprintf( 'Main content appears after %.1f s on a mid-range phone.', $sec );
		if ( isset( $field['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ) ) {
			$detail .= sprintf( ' Real visitors: %.1f s.', $field['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] / 1000 );
		}
		$checks[] = hpv_grader_check( 'speed_lcp', 'speed', $sec <= 2.5 ? 'pass' : ( $sec <= 4 ? 'warn' : 'fail' ), 2, 'Loading speed (LCP)', $detail, 'Make the largest element above the fold (usually the hero image or heading) load first: optimize and preload the hero image, avoid showing it via JavaScript or animation, and keep it under ~200 KB.' );
	}

	$cls = $num( 'cumulative-layout-shift' );
	if ( null !== $cls ) {
		$checks[] = hpv_grader_check( 'speed_cls', 'speed', $cls <= 0.1 ? 'pass' : ( $cls <= 0.25 ? 'warn' : 'fail' ), 1, 'Visual stability (CLS)', sprintf( 'Layout shift score: %.2f.', $cls ), 'Reserve space for images, ads and embeds (set width and height), and avoid inserting banners above existing content after the page loads.' );
	}

	$tbt = $num( 'total-blocking-time' );
	if ( null !== $tbt ) {
		$checks[] = hpv_grader_check( 'speed_tbt', 'speed', $tbt <= 200 ? 'pass' : ( $tbt <= 600 ? 'warn' : 'fail' ), 1, 'Responsiveness (blocking time)', sprintf( 'The page is busy for %s ms before it responds to taps.', number_format( $tbt ) ), 'Reduce JavaScript: remove unused plugins and third-party widgets, delay chat and tracking scripts until interaction, and avoid heavy animation libraries on mobile.' );
	}

	$bytes = $num( 'total-byte-weight' );
	if ( null !== $bytes ) {
		$mb       = $bytes / 1048576;
		$checks[] = hpv_grader_check( 'speed_weight', 'speed', $mb <= 2.5 ? 'pass' : ( $mb <= 5 ? 'warn' : 'fail' ), 1, 'Page size', sprintf( 'The homepage downloads %.1f MB.', $mb ), 'Cut page weight: compress images, replace background videos with a poster image on mobile, and limit web fonts to the weights you use.' );
	}

	return $checks;
}

/* ─── Pontozás, riport ────────────────────────────────────── */

function hpv_grader_status_points( string $status ): float {
	return array(
		'pass' => 1.0,
		'warn' => 0.5,
		'fail' => 0.0,
	)[ $status ] ?? 0.0;
}

function hpv_grader_score_report( array $report ): array {
	$categories = array();
	$total      = 0;
	$weights    = 0;

	foreach ( HPV_GRADER_CATEGORIES as $key => $cat ) {
		$max    = 0;
		$points = 0;
		$counts = array(
			'pass' => 0,
			'warn' => 0,
			'fail' => 0,
		);
		foreach ( $report['checks'] as $check ) {
			if ( $check['category'] !== $key ) {
				continue;
			}
			$max    += $check['weight'];
			$points += $check['weight'] * hpv_grader_status_points( $check['status'] );
			$counts[ $check['status'] ]++;
		}

		$pending = 'speed' === $key && 'pending' === ( $report['psi_status'] ?? '' );
		$score   = $max > 0 ? (int) round( 100 * $points / $max ) : null;

		$categories[ $key ] = array(
			'label'   => $cat['label'],
			'score'   => $pending ? null : $score,
			'pending' => $pending,
			'counts'  => $counts,
		);

		if ( ! $pending && null !== $score ) {
			$total   += $score * $cat['weight'];
			$weights += $cat['weight'];
		}
	}

	$report['categories'] = $categories;
	$report['overall']    = $weights ? (int) round( $total / $weights ) : 0;
	$report['grade']      = hpv_grader_grade( $report['overall'] );

	return $report;
}

function hpv_grader_grade( int $score ): string {
	if ( $score >= 90 ) {
		return 'Excellent';
	}
	if ( $score >= 75 ) {
		return 'Good';
	}
	if ( $score >= 55 ) {
		return 'Needs work';
	}

	return 'Critical';
}

/**
 * A legfontosabb hiba: előbb a „fail”, azon belül a nagyobb súlyú.
 */
function hpv_grader_top_issue( array $checks ): ?string {
	$issues = array_filter( $checks, fn( $c ) => 'pass' !== $c['status'] );
	if ( ! $issues ) {
		return null;
	}
	usort(
		$issues,
		function ( $a, $b ) {
			$rank = array(
				'fail' => 0,
				'warn' => 1,
			);
			return array( $rank[ $a['status'] ], -$a['weight'] ) <=> array( $rank[ $b['status'] ], -$b['weight'] );
		}
	);

	return $issues[0]['id'];
}

/**
 * A böngészőnek küldött riport. Zárolva a javítási javaslatok (fix) rejtve vannak,
 * kivéve a legfontosabb hibát, ami mintaként látszik.
 */
function hpv_grader_public_report( array $report, bool $unlocked ): array {
	$top = hpv_grader_top_issue( $report['checks'] );

	$report['unlocked'] = $unlocked;
	$report['checks']   = array_map(
		function ( $check ) use ( $unlocked, $top ) {
			$check['top'] = $check['id'] === $top;
			if ( ! $unlocked && ! $check['top'] && '' !== $check['fix'] ) {
				$check['fix']    = '';
				$check['locked'] = true;
			}
			return $check;
		},
		$report['checks']
	);

	unset( $report['lead_id'], $report['unlock_token'], $report['email_pending'] );

	return $report;
}

/**
 * A PageSpeed eredményét beteszi a riportba (a szerver válaszidő-ellenőrzés marad).
 */
function hpv_grader_apply_psi( array $report, array $psi_checks ): array {
	$others = array_filter( $report['checks'], fn( $c ) => 'speed' !== $c['category'] || 'speed_server' === $c['id'] );

	$report['checks']     = array_merge( $psi_checks, array_values( $others ) );
	$report['psi_status'] = $psi_checks ? 'done' : 'failed';

	return hpv_grader_score_report( $report );
}
