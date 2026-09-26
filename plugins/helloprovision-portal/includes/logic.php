<?php
/**
 * Üzleti logika WordPress-függvények nélkül (önállóan tesztelhető).
 * A pénzösszegeket centben (egész szám) számoljuk, hogy ne legyen kerekítési hiba.
 */

defined( 'ABSPATH' ) || exit;

/**
 * "1,234.50" | "1234.5" | 1234.5 → 123450
 */
function hpv_p_to_cents( $value ): int {
	$value = preg_replace( '/[^0-9.\-]/', '', (string) $value );
	if ( '' === $value || '-' === $value || '.' === $value ) {
		return 0;
	}

	return (int) round( (float) $value * 100 );
}

function hpv_p_cents_to_decimal( int $cents ): string {
	$sign = $cents < 0 ? '-' : '';
	$abs  = abs( $cents );

	return $sign . intdiv( $abs, 100 ) . '.' . str_pad( (string) ( $abs % 100 ), 2, '0', STR_PAD_LEFT );
}

function hpv_p_money( $amount, string $currency = 'USD' ): string {
	$cents = is_int( $amount ) ? $amount : hpv_p_to_cents( $amount );
	$sign  = $cents < 0 ? '-' : '';
	$abs   = abs( $cents );

	// Forint: magyar írásmód, fillér nélkül ha kerek (12 700 Ft).
	if ( 'HUF' === $currency ) {
		$decimals = 0 === $abs % 100 ? 0 : 2;
		return $sign . number_format( $abs / 100, $decimals, ',', "\u{00A0}" ) . "\u{00A0}Ft";
	}
	$symbol = array(
		'USD' => '$',
		'EUR' => '€',
	)[ $currency ] ?? $currency . ' ';

	return $sign . $symbol . number_format( $abs / 100, 2 );
}

/**
 * Pénznemenkénti összegek egy sorban: „$4,075.00 · 1 250 000 Ft”. Üresen a megadott pénznem 0-ja.
 *
 * @param array $by_currency [ 'USD' => cent, 'HUF' => cent ]
 */
function hpv_p_money_multi( array $by_currency, string $empty_currency = 'USD' ): string {
	$by_currency = array_filter( $by_currency );
	if ( ! $by_currency ) {
		return hpv_p_money( 0, $empty_currency );
	}
	ksort( $by_currency );
	$by_currency = array_merge( array_intersect_key( $by_currency, array( 'USD' => 0 ) ), $by_currency ); // USD elöl

	return implode( ' · ', array_map( fn( $cur, $cents ) => hpv_p_money( $cents, $cur ), array_keys( $by_currency ), $by_currency ) );
}

/**
 * Szabad szöveges cím → mezők. Felismeri az USA („City, ST 12345”) és a magyar („1234 Budapest”) formát.
 */
function hpv_p_parse_address( string $address ): array {
	$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $address ) ) ) );
	$out   = array(
		'street' => $lines[0] ?? '',
		'city'   => '',
		'state'  => '',
		'zip'    => '',
	);
	$rest = $lines[1] ?? '';
	if ( 1 === count( $lines ) && preg_match( '/^(.+?),\s*(.+)$/', $lines[0], $m ) ) {
		$out['street'] = $m[1];
		$rest          = $m[2];
	}
	if ( preg_match( '/^(.+?),\s*([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/', $rest, $m ) ) {
		$out['city']  = $m[1];
		$out['state'] = $m[2];
		$out['zip']   = $m[3];
	} elseif ( preg_match( '/^(\d{4})\s+(.+)$/u', $rest, $m ) ) {
		$out['zip']  = $m[1];
		$out['city'] = $m[2];
	} else {
		$out['city'] = $rest;
	}

	return $out;
}

/**
 * Tételek és adókulcs → tételösszegek, nettó, adó, bruttó (centben).
 *
 * @param array $items [ ['quantity' => '2', 'unit_price' => '150.00'], ... ]
 */
function hpv_p_invoice_totals( array $items, $tax_rate ): array {
	$subtotal = 0;
	foreach ( $items as $i => $item ) {
		$qty_hundredths         = hpv_p_to_cents( $item['quantity'] ?? '1' ); // mennyiség 2 tizedesig
		$amount                 = (int) round( $qty_hundredths * hpv_p_to_cents( $item['unit_price'] ?? '0' ) / 100 );
		$items[ $i ]['amount']  = $amount;
		$subtotal              += $amount;
	}
	$tax = (int) round( $subtotal * (float) $tax_rate / 100 );

	return array(
		'items'    => $items,
		'subtotal' => $subtotal,
		'tax'      => $tax,
		'total'    => $subtotal + $tax,
	);
}

function hpv_p_invoice_number( string $prefix, int $number, int $pad = 4 ): string {
	return $prefix . str_pad( (string) $number, $pad, '0', STR_PAD_LEFT );
}

/**
 * Lejárt-e egy kiküldött, még nem fizetett számla (a „sent” státuszból számolt „overdue”).
 */
function hpv_p_invoice_is_overdue( array $invoice, string $today ): bool {
	return 'sent' === ( $invoice['status'] ?? '' ) && ! empty( $invoice['due_date'] ) && $invoice['due_date'] < $today;
}

/**
 * A szerződés szövegének lenyomata. Aláíráskor ezt rögzítjük; ha a szöveg később változna, látszik.
 */
function hpv_p_contract_hash( string $body ): string {
	return hash( 'sha256', trim( str_replace( "\r\n", "\n", $body ) ) );
}

/**
 * Kész feladatok aránya (0–100) a megadott feladatok alapján.
 */
function hpv_p_project_progress( array $tasks ): int {
	if ( ! $tasks ) {
		return 0;
	}
	$done = count( array_filter( $tasks, fn( $t ) => 'done' === ( $t['status'] ?? '' ) ) );

	return (int) round( 100 * $done / count( $tasks ) );
}

/**
 * Következő számlázási dátum egy előfizetésnél.
 */
function hpv_p_next_billing_date( string $date, string $billing ): string {
	$months = array(
		'monthly'   => 1,
		'quarterly' => 3,
		'yearly'    => 12,
	)[ $billing ] ?? 0;
	if ( ! $months || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return '';
	}

	$d = new DateTimeImmutable( $date );
	// A hónap végi dátumoknál ne ugorjon át a következő hónapba (jan 31 → feb 28).
	$target = $d->modify( 'first day of +' . $months . ' month' );
	$day    = min( (int) $d->format( 'j' ), (int) $target->format( 't' ) );

	return $target->setDate( (int) $target->format( 'Y' ), (int) $target->format( 'n' ), $day )->format( 'Y-m-d' );
}

/**
 * Havi ismétlődő bevétel (MRR) centben az aktív előfizetésekből.
 */
function hpv_p_mrr( array $subscriptions ): int {
	$divisor = array(
		'monthly'   => 1,
		'quarterly' => 3,
		'yearly'    => 12,
	);
	$total   = 0;
	foreach ( $subscriptions as $sub ) {
		if ( 'active' === ( $sub['status'] ?? '' ) && isset( $divisor[ $sub['billing'] ?? '' ] ) ) {
			$total += (int) round( hpv_p_to_cents( $sub['price'] ?? 0 ) / $divisor[ $sub['billing'] ] );
		}
	}

	return $total;
}
