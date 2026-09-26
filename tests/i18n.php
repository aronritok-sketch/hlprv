<?php
/**
 * WordPress nélkül futtatható teszt: php tests/i18n.php
 *
 * Minden hpv_t()/hpv_tn() szövegnek van magyar fordítása, a helyőrzők (%s, %d) száma egyezik,
 * és a portálon megjelenő státusz- és menücímkék is le vannak fordítva.
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['fail'] = 0;

function check( $label, $ok ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$GLOBALS['fail']++;
	}
}

$plugin = dirname( __DIR__ ) . '/plugins/helloprovision-portal';
require $plugin . '/includes/i18n.php';
$dict = hpv_i18n_hu();

/**
 * A hpv_t( '...' ) és hpv_tn( '...', '...' ) hívások szöveg-literáljai a PHP tokenekből.
 */
function literal_calls( string $file ): array {
	$tokens = token_get_all( file_get_contents( $file ) );
	$out    = array();
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( $tokens[ $i ][1], array( 'hpv_t', 'hpv_tn' ), true ) ) {
			continue;
		}
		$args = 'hpv_tn' === $tokens[ $i ][1] ? 2 : 1;
		$j    = $i + 1;
		while ( $j < $count && ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] || '(' === $tokens[ $j ] ) ) {
			$j++;
		}
		for ( $n = 0; $n < $args && $j < $count; $n++ ) {
			if ( is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0] ) {
				$out[] = array( eval( 'return ' . $tokens[ $j ][1] . ';' ), $tokens[ $j ][2] ); // phpcs:ignore -- saját forráskód literálja
			}
			$j++;
			while ( $j < $count && ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] || ',' === $tokens[ $j ] ) ) {
				$j++;
			}
		}
	}

	return $out;
}

function placeholders( string $s ): array {
	preg_match_all( '/%(?:\d+\$)?[sd]/', $s, $m );
	$types = array_map( fn( $p ) => substr( $p, -1 ), $m[0] );
	sort( $types );

	return $types;
}

echo "Fordítások a kódban\n";
$used    = array();
$missing = array();
$bad     = array();
foreach ( array_merge( glob( $plugin . '/includes/*.php' ), array( $plugin . '/helloprovision-portal.php' ) ) as $file ) {
	foreach ( literal_calls( $file ) as list( $text, $line ) ) {
		$used[ $text ] = true;
		if ( ! isset( $dict[ $text ] ) ) {
			$missing[] = basename( $file ) . ":$line  $text";
		} elseif ( placeholders( $text ) !== placeholders( $dict[ $text ] ) ) {
			$bad[] = basename( $file ) . ":$line  $text";
		}
	}
}
// Az ügyfélnaplóba kerülő minták: hpv_p_log_client( $client, 'minta', … ).
foreach ( glob( $plugin . '/includes/*.php' ) as $file ) {
	preg_match_all( "/hpv_p_log_client\\([^,]+,\\s*'((?:[^'\\\\]|\\\\.)*)'\\s*,/", file_get_contents( $file ), $m );
	foreach ( $m[1] as $text ) {
		$text          = stripslashes( $text );
		$used[ $text ] = true;
		if ( ! isset( $dict[ $text ] ) ) {
			$missing[] = basename( $file ) . "  (napló)  $text";
		}
	}
}
check( sprintf( '%d szöveg a kódban', count( $used ) ), count( $used ) > 150 );
check( 'mindnek van magyar fordítása' . ( $missing ? ":\n         " . implode( "\n         ", $missing ) : '' ), ! $missing );
check( 'a helyőrzők száma egyezik' . ( $bad ? ":\n         " . implode( "\n         ", $bad ) : '' ), ! $bad );

echo "Dinamikus címkék\n";
$schema = file_get_contents( $plugin . '/includes/schema.php' );
$labels = array();
foreach ( array( 'Planning', 'In progress', 'Awaiting your review', 'Completed', 'On hold', 'To do', 'In review', 'Waiting on you', 'Done', 'Draft', 'Due', 'Paid', 'Void', 'Awaiting signature', 'Signed', 'Active', 'Paused', 'Cancelled', 'One-time', 'Monthly', 'Quarterly', 'Yearly' ) as $l ) {
	check( "sémában és szótárban: $l", false !== strpos( $schema, "'$l' )" ) && isset( $dict[ $l ] ) );
}
foreach ( array( 'Overview', 'Projects', 'Messages', 'Meetings', 'Proposals', 'Invoices', 'Contracts', 'Files', 'Services', 'Account', 'Overdue', 'Accepted', 'Declined', 'Expired' ) as $l ) {
	check( "szótárban: $l", isset( $dict[ $l ] ) );
}

// A riport mutatóinak címkéi (connectors.php).
preg_match( "/const HPV_METRIC_LABELS = array\\((.*?)\\);/s", file_get_contents( $plugin . '/includes/connectors.php' ), $mm );
preg_match_all( "/'([^']+)'/", $mm[1] ?? '', $labels );
check( sprintf( 'riport mutatók (%d) lefordítva', count( $labels[1] ) ), count( $labels[1] ) > 15 && ! array_diff( $labels[1], array_keys( $dict ) ) );

echo "Nyelv és dátum\n";
function get_date_from_gmt( $d, $f ) {
	return gmdate( $f, strtotime( $d ) + 2 * 3600 );
}
check( 'alapból angol', 'Pay now · $5.00' === hpv_t( 'Pay now · %s', '$5.00' ) );
hpv_with_lang( 'hu', fn() => check( 'magyarul', 'Fizetés most · 5 Ft' === hpv_t( 'Pay now · %s', '5 Ft' ) ) );
check( 'hpv_with_lang után visszaáll', 'en' === hpv_lang() );
hpv_with_lang( 'hu', fn() => check( 'sorrendcserés fordítás', 'Aláírta: Kovács Mária, 2026. szept. 26.' === hpv_t( 'Signed %s by %s', '2026. szept. 26.', 'Kovács Mária' ) ) );
check( 'angol dátum', 'Sep 26, 2026' === hpv_date( '2026-09-26' ) && 'September 26, 2026' === hpv_date( '2026-09-26', 'long' ) );
hpv_with_lang(
	'hu',
	function () {
		check( 'magyar dátum', '2026. szept. 26.' === hpv_date( '2026-09-26' ) && '2026. szeptember 26.' === hpv_date( '2026-09-26', 'long' ) && 'márc. 3.' === hpv_date( '2027-03-03', 'short' ) );
		check( 'magyar időpont (UTC → helyi)', '2026. szept. 26., szombat · 15:05' === hpv_date( '2026-09-26 13:05:00', 'full', true ) );
		check( 'többes szám magyarul nincs', '3 olvasatlan üzenet' === hpv_tn( '%d unread message', '%d unread messages', 3 ) );
	}
);
check( 'üres dátum', '' === hpv_date( '' ) && '' === hpv_date( '0000-00-00' ) );

echo $GLOBALS['fail'] ? "\n{$GLOBALS['fail']} hiba\n" : "\nMinden teszt sikeres\n";
exit( $GLOBALS['fail'] ? 1 : 0 );
