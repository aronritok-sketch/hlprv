<?php
/**
 * Számlázz.hu Számla Agent (XML API): magyar számla kiállítása, kifizetés rögzítése, sztornó.
 * Kulcs: define( 'HPV_SZAMLAZZ_AGENT_KEY', '…' ); (Számlázz.hu → Beállítások → Számla Agent kulcsok)
 * A számla a Számlázz.hu fiók eladói adataival, bankszámlájával és számlaszám-előtagjával készül,
 * az e-számlát a Számlázz.hu küldi el a vevőnek (benne a portál fizetési linkjével), és jelenti a NAV-nak.
 */

defined( 'ABSPATH' ) || exit;

const HPV_SZAMLAZZ_URL = 'https://www.szamlazz.hu/szamla/';

function hpv_szamlazz_key(): string {
	return defined( 'HPV_SZAMLAZZ_AGENT_KEY' ) ? (string) HPV_SZAMLAZZ_AGENT_KEY : '';
}

function hpv_szamlazz_enabled(): bool {
	return '' !== hpv_szamlazz_key();
}

function hpv_szamlazz_x( $value ): string {
	return htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * Egy XML elem (üres értéknél kihagyható).
 */
function hpv_szamlazz_el( string $name, $value, bool $skip_empty = false ): string {
	if ( $skip_empty && '' === (string) $value ) {
		return '';
	}
	if ( is_bool( $value ) ) {
		$value = $value ? 'true' : 'false';
	}

	return '<' . $name . '>' . hpv_szamlazz_x( $value ) . '</' . $name . '>';
}

/**
 * Összeg a Számlázz.hu-nak: forintnál egész, egyébként 2 tizedes.
 */
function hpv_szamlazz_amount( int $cents, string $currency ): string {
	return 'HUF' === $currency ? (string) (int) round( $cents / 100 ) : hpv_p_cents_to_decimal( $cents );
}

/**
 * A számla XML-je (xmlszamla). Az elemek sorrendjét az XSD rögzíti.
 * A tételek ÁFÁ-ja soronként számolt; forintnál egész forintra kerekítve.
 */
function hpv_szamlazz_invoice_xml( array $invoice, array $client, array $items ): string {
	$s        = hpv_p_settings();
	$currency = hpv_p_invoice_currency( $invoice );
	$vat_key  = hpv_p_hu_vat_key( $invoice );
	$rate     = (float) hpv_p_vat_rate( $vat_key );
	$today    = current_time( 'Y-m-d' );
	$pay_link = hpv_p_portal_url( array( 'view' => 'invoices', 'id' => (int) $invoice['id'] ) );
	$email    = hpv_p_client_billing_email( $client );

	$lines = '';
	foreach ( $items as $item ) {
		$qty   = hpv_p_to_cents( $item['quantity'] ?: '1' );                       // századokban
		$unit  = hpv_p_to_cents( $item['unit_price'] );
		$net   = (int) round( $qty * $unit / 100 );
		$vat   = (int) round( $net * $rate / 100 );
		if ( 'HUF' === $currency ) {
			$net = (int) round( $net / 100 ) * 100;
			$vat = (int) round( $vat / 100 ) * 100;
		}
		$lines .= '<tetel>'
			. hpv_szamlazz_el( 'megnevezes', $item['description'] )
			. hpv_szamlazz_el( 'mennyiseg', rtrim( rtrim( hpv_p_cents_to_decimal( $qty ), '0' ), '.' ) )
			. hpv_szamlazz_el( 'mennyisegiEgyseg', 'db' )
			. hpv_szamlazz_el( 'nettoEgysegar', hpv_p_cents_to_decimal( $unit ) )
			. hpv_szamlazz_el( 'afakulcs', $vat_key )
			. hpv_szamlazz_el( 'nettoErtek', hpv_szamlazz_amount( $net, $currency ) )
			. hpv_szamlazz_el( 'afaErtek', hpv_szamlazz_amount( $vat, $currency ) )
			. hpv_szamlazz_el( 'bruttoErtek', hpv_szamlazz_amount( $net + $vat, $currency ) )
			. '</tetel>';
	}

	$foreign = 'HUF' !== $currency;

	return '<?xml version="1.0" encoding="UTF-8"?>'
		. '<xmlszamla xmlns="http://www.szamlazz.hu/xmlszamla" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.szamlazz.hu/xmlszamla https://www.szamlazz.hu/szamla/docs/xsds/agent/xmlszamla.xsd">'
		. '<beallitasok>'
		. hpv_szamlazz_el( 'szamlaagentkulcs', hpv_szamlazz_key() )
		. hpv_szamlazz_el( 'eszamla', true )
		. hpv_szamlazz_el( 'szamlaLetoltes', true )
		. hpv_szamlazz_el( 'valaszVerzio', '2' )
		. '</beallitasok>'
		. '<fejlec>'
		. hpv_szamlazz_el( 'keltDatum', $today )
		. hpv_szamlazz_el( 'teljesitesDatum', $invoice['issue_date'] ?: $today )
		. hpv_szamlazz_el( 'fizetesiHataridoDatum', $invoice['due_date'] ?: $today )
		. hpv_szamlazz_el( 'fizmod', $s['hu_fizmod'] ?: 'Bankkártya' )
		. hpv_szamlazz_el( 'penznem', 'HUF' === $currency ? 'Ft' : $currency )
		. hpv_szamlazz_el( 'szamlaNyelve', 'hu' )
		. hpv_szamlazz_el( 'megjegyzes', (string) $invoice['notes'] )
		. ( $foreign ? hpv_szamlazz_el( 'arfolyamBank', 'MNB' ) . hpv_szamlazz_el( 'arfolyam', '0' ) : '' )
		. hpv_szamlazz_el( 'rendelesSzam', 'HPV-' . (int) $invoice['id'] )
		. '</fejlec>'
		. '<elado>'
		. hpv_szamlazz_el( 'emailReplyto', $s['company_email'], true )
		. hpv_szamlazz_el( 'emailTargy', 'Számla — ' . $s['company_name'] )
		. hpv_szamlazz_el( 'emailSzoveg', "Köszönjük, hogy minket választott!\nA számlát bankkártyával is kifizetheti az ügyfélportálon: " . $pay_link )
		. '</elado>'
		. '<vevo>'
		. hpv_szamlazz_el( 'nev', $client['billing_name'] ?: $client['name'] )
		. hpv_szamlazz_el( 'irsz', $client['zip'] )
		. hpv_szamlazz_el( 'telepules', $client['city'] )
		. hpv_szamlazz_el( 'cim', $client['street'] )
		. hpv_szamlazz_el( 'email', $email, true )
		. hpv_szamlazz_el( 'sendEmail', '' !== $email )
		. hpv_szamlazz_el( 'adoalany', '' !== (string) $client['tax_number'] ? '7' : '-1' )
		. hpv_szamlazz_el( 'adoszam', (string) $client['tax_number'], true )
		. '</vevo>'
		. '<tetelek>' . $lines . '</tetelek>'
		. '</xmlszamla>';
}

/**
 * Kérés a Számla Agentnek (multipart/form-data, az XML fájlként).
 *
 * @return array|WP_Error {headers, body}
 */
function hpv_szamlazz_post( string $field, string $xml ) {
	$boundary = 'hpv' . wp_generate_password( 24, false, false );
	$body     = "--$boundary\r\n"
		. 'Content-Disposition: form-data; name="' . $field . "\"; filename=\"request.xml\"\r\n"
		. "Content-Type: text/xml\r\n\r\n"
		. $xml . "\r\n"
		. "--$boundary--\r\n";

	$res = wp_remote_post(
		HPV_SZAMLAZZ_URL,
		array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			'body'    => $body,
		)
	);
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'szamlazz_http', 'A Számlázz.hu nem érhető el: ' . $res->get_error_message() );
	}
	$raw     = wp_remote_retrieve_headers( $res );
	$headers = array_change_key_case( is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw, CASE_LOWER );
	$flat    = array_map( fn( $v ) => is_array( $v ) ? (string) reset( $v ) : (string) $v, $headers );
	$body    = (string) wp_remote_retrieve_body( $res );

	$error = $flat['szlahu_error'] ?? '';
	if ( '' === $error && preg_match( '#<sikeres>\s*false\s*</sikeres>#', $body ) ) {
		$error = hpv_szamlazz_tag( $body, 'hibauzenet' ) ?: 'ismeretlen hiba';
		$flat['szlahu_error_code'] = hpv_szamlazz_tag( $body, 'hibakod' );
	}
	if ( '' !== $error ) {
		$code = $flat['szlahu_error_code'] ?? '';
		return new WP_Error( 'szamlazz_error', 'Számlázz.hu: ' . urldecode( $error ) . ( $code ? ' (' . $code . ')' : '' ) );
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return new WP_Error( 'szamlazz_http', 'Számlázz.hu HTTP ' . wp_remote_retrieve_response_code( $res ) );
	}

	return array(
		'headers' => $flat,
		'body'    => $body,
	);
}

function hpv_szamlazz_tag( string $xml, string $tag ): string {
	return preg_match( '#<(?:\w+:)?' . $tag . '>(.*?)</(?:\w+:)?' . $tag . '>#s', $xml, $m ) ? html_entity_decode( trim( $m[1] ), ENT_XML1 | ENT_QUOTES, 'UTF-8' ) : '';
}

/**
 * Összeg a válaszból centben („12700” vagy „12700.50”).
 */
function hpv_szamlazz_parse_amount( string $value ): ?int {
	return '' === trim( $value ) ? null : hpv_p_to_cents( $value );
}

/**
 * Számla kiállítása.
 *
 * @return array|WP_Error {number, pdf, net, gross}
 */
function hpv_szamlazz_issue( array $invoice ) {
	$client = hpv_p_get( 'client', (int) $invoice['client_id'] );
	$items  = hpv_p_find( 'invoice_item', array( 'invoice_id' => (int) $invoice['id'] ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
	$errors = array();
	foreach ( array( 'zip' => 'irányítószám', 'city' => 'város', 'street' => 'utca, házszám' ) as $key => $label ) {
		if ( '' === trim( (string) $client[ $key ] ) ) {
			$errors[] = $label;
		}
	}
	if ( $errors ) {
		return new WP_Error( 'address', 'Hiányzik az ügyfél számlázási címéből: ' . implode( ', ', $errors ) . '.' );
	}

	// Kettős kiállítás ellen: ha egy korábbi kérés még fut (vagy időtúllépéssel járt le), nem küldjük újra.
	$lock = 'hpv_szamlazz_issue_' . (int) $invoice['id'];
	if ( get_transient( $lock ) ) {
		return new WP_Error( 'busy', 'A számla kiállítása folyamatban van. Egy perc múlva nézd meg a Számlázz.hu-ban, mielőtt újra próbálod.' );
	}
	set_transient( $lock, 1, MINUTE_IN_SECONDS );

	$res = hpv_szamlazz_post( 'action-xmlagentxmlfile', hpv_szamlazz_invoice_xml( $invoice, $client, $items ) );
	if ( is_wp_error( $res ) ) {
		delete_transient( $lock ); // a Számlázz.hu elutasította: biztonságosan újrapróbálható
		return $res;
	}
	$body   = $res['body'];
	$number = hpv_szamlazz_tag( $body, 'szamlaszam' ) ?: urldecode( $res['headers']['szlahu_szamlaszam'] ?? '' );
	if ( '' === $number ) {
		return new WP_Error( 'szamlazz_format', 'A Számlázz.hu válaszában nincs számlaszám. Ellenőrizd a Számlázz.hu-ban, hogy elkészült-e a számla.' );
	}
	$pdf = '';
	if ( 0 === strpos( $body, '%PDF' ) ) {
		$pdf = $body;
	} elseif ( '' !== hpv_szamlazz_tag( $body, 'pdf' ) ) {
		$pdf = (string) base64_decode( hpv_szamlazz_tag( $body, 'pdf' ), true );
	}
	delete_transient( $lock );

	return array(
		'number' => $number,
		'pdf'    => $pdf,
		'net'    => hpv_szamlazz_parse_amount( hpv_szamlazz_tag( $body, 'szamlanetto' ) ?: ( $res['headers']['szlahu_nettovegosszeg'] ?? '' ) ),
		'gross'  => hpv_szamlazz_parse_amount( hpv_szamlazz_tag( $body, 'szamlabrutto' ) ?: ( $res['headers']['szlahu_bruttovegosszeg'] ?? '' ) ),
	);
}

/**
 * Kifizetés rögzítése a számlán (a Számlázz.hu „fizetve” lesz, ha a teljes összeg befolyt).
 *
 * @return string|WP_Error Hivatkozás (a számlaszám + dátum).
 */
function hpv_szamlazz_register_payment( array $invoice, array $payment ) {
	$currency = hpv_p_invoice_currency( $invoice );
	$title    = array(
		'stripe' => 'Bankkártya',
		'teya'   => 'Bankkártya',
	)[ $payment['provider'] ] ?? 'Átutalás';
	$xml      = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<xmlszamlakifiz xmlns="http://www.szamlazz.hu/xmlszamlakifiz" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.szamlazz.hu/xmlszamlakifiz https://www.szamlazz.hu/szamla/docs/xsds/agentkifiz/xmlszamlakifiz.xsd">'
		. '<beallitasok>'
		. hpv_szamlazz_el( 'szamlaagentkulcs', hpv_szamlazz_key() )
		. hpv_szamlazz_el( 'szamlaszam', $invoice['external_id'] )
		. hpv_szamlazz_el( 'additiv', true )
		. '</beallitasok>'
		. '<kifizetes>'
		. hpv_szamlazz_el( 'datum', $payment['paid_on'] ?: current_time( 'Y-m-d' ) )
		. hpv_szamlazz_el( 'jogcim', $title )
		. hpv_szamlazz_el( 'osszeg', hpv_szamlazz_amount( hpv_p_to_cents( $payment['amount'] ), $currency ) )
		. hpv_szamlazz_el( 'leiras', trim( ucfirst( $payment['provider'] ) . ' ' . $payment['reference'] ), true )
		. '</kifizetes>'
		. '</xmlszamlakifiz>';

	$res = hpv_szamlazz_post( 'action-szamla_agent_kifiz', $xml );

	return is_wp_error( $res ) ? $res : $invoice['external_id'] . '/' . ( $payment['paid_on'] ?: current_time( 'Y-m-d' ) );
}

/**
 * Sztornó számla.
 *
 * @return array|WP_Error {number}
 */
function hpv_szamlazz_storno( array $invoice ) {
	$client = hpv_p_get( 'client', (int) $invoice['client_id'] );
	$today  = current_time( 'Y-m-d' );
	$email  = $client ? hpv_p_client_billing_email( $client ) : '';
	$xml    = '<?xml version="1.0" encoding="UTF-8"?>'
		. '<xmlszamlast xmlns="http://www.szamlazz.hu/xmlszamlast" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.szamlazz.hu/xmlszamlast https://www.szamlazz.hu/szamla/docs/xsds/agentst/xmlszamlast.xsd">'
		. '<beallitasok>'
		. hpv_szamlazz_el( 'szamlaagentkulcs', hpv_szamlazz_key() )
		. hpv_szamlazz_el( 'eszamla', true )
		. hpv_szamlazz_el( 'szamlaLetoltes', false )
		. '</beallitasok>'
		. '<fejlec>'
		. hpv_szamlazz_el( 'szamlaszam', $invoice['external_id'] )
		. hpv_szamlazz_el( 'keltDatum', $today )
		. hpv_szamlazz_el( 'teljesitesDatum', $today )
		. hpv_szamlazz_el( 'tipus', 'SS' )
		. '</fejlec>'
		. ( '' !== $email ? '<vevo>' . hpv_szamlazz_el( 'email', $email ) . '</vevo>' : '' )
		. '</xmlszamlast>';

	$res = hpv_szamlazz_post( 'action-szamla_agent_st', $xml );
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	return array( 'number' => hpv_szamlazz_tag( $res['body'], 'szamlaszam' ) ?: urldecode( $res['headers']['szlahu_szamlaszam'] ?? '' ) );
}
