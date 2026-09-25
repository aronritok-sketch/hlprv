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
	$cents  = is_int( $amount ) ? $amount : hpv_p_to_cents( $amount );
	$symbol = array(
		'USD' => '$',
		'EUR' => '€',
		'HUF' => 'Ft ',
	)[ $currency ] ?? $currency . ' ';
	$sign   = $cents < 0 ? '-' : '';

	return $sign . $symbol . number_format( abs( $cents ) / 100, 2 );
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
