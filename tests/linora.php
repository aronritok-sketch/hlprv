<?php
/**
 * WordPress nélkül futtatható teszt: php tests/linora.php
 */

require __DIR__ . '/bootstrap.php';

function remove_accents( $text ) {
	return strtr( $text, array(
		'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
		'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ö' => 'O', 'Ő' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ű' => 'U',
	) );
}

$linora = dirname( __DIR__ ) . '/linora/linora-booking';
require $linora . '/includes/catalog.php';
require $linora . '/includes/checkout.php';

echo "Kategória- és szolgáltatásnevek\n";
check( '"Női – Arc" szétválasztva', array( 'Női', 'Arc' ) === LNR_Booking_Catalog::split_category_name( 'Női – Arc' ) );
check( 'sima kötőjel is jó', array( 'Férfi', 'Kombinált' ) === LNR_Booking_Catalog::split_category_name( 'Férfi - Kombinált' ) );
check( 'elválasztó nélküli kategória kimarad', null === LNR_Booking_Catalog::split_category_name( 'Női' ) );
check( '(női) utótag levéve', 'Bajusz' === LNR_Booking_Catalog::display_name( 'Bajusz (női)' ) );
check( '(férfi) utótag levéve', 'Kézfej + ujjak' === LNR_Booking_Catalog::display_name( 'Kézfej + ujjak (férfi)' ) );
check( 'más zárójel megmarad', 'Hát (felső)' === LNR_Booking_Catalog::display_name( 'Hát (felső)' ) );
check( 'kulcs ékezet nélkül', 'noi' === LNR_Booking_Catalog::key( 'Női' ) && 'felsotest' === LNR_Booking_Catalog::key( 'Felsőtest' ) );

echo "Foglalt idő az Amelia idősávjához igazítva\n";
check( '15 perc → 30 perc', 1800 === LNR_Booking_Catalog::booked_duration( 900, 1800 ) );
check( '30 perc marad', 1800 === LNR_Booking_Catalog::booked_duration( 1800, 1800 ) );
check( '45 perc → 60 perc', 3600 === LNR_Booking_Catalog::booked_duration( 2700, 1800 ) );
check( '15 perces sávnál 45 marad', 2700 === LNR_Booking_Catalog::booked_duration( 2700, 900 ) );

$rows = array(
	'categories' => array(
		array( 'id' => 1, 'name' => 'Default', 'status' => 'visible', 'position' => 1 ),
		array( 'id' => 5, 'name' => 'Női – Felsőtest', 'status' => 'visible', 'position' => 3 ),
		array( 'id' => 4, 'name' => 'Női – Arc', 'status' => 'visible', 'position' => 2 ),
		array( 'id' => 6, 'name' => 'Férfi – Arc', 'status' => 'visible', 'position' => 4 ),
		array( 'id' => 7, 'name' => 'Női – Rejtett', 'status' => 'hidden', 'position' => 5 ),
		array( 'id' => 8, 'name' => 'Férfi – Üres', 'status' => 'visible', 'position' => 6 ),
	),
	'services' => array(
		array( 'id' => 1, 'name' => 'Teszt', 'price' => 1, 'status' => 'visible', 'categoryId' => 1, 'duration' => 3600, 'position' => 0, 'show' => 1 ),
		array( 'id' => 10, 'name' => 'Orca (női)', 'price' => 8000, 'status' => 'visible', 'categoryId' => 4, 'duration' => 1800, 'position' => 3, 'show' => 1 ),
		array( 'id' => 11, 'name' => 'Bajusz (női)', 'price' => 8000, 'status' => 'visible', 'categoryId' => 4, 'duration' => 900, 'position' => 1, 'show' => 1 ),
		array( 'id' => 12, 'name' => 'Áll (női)', 'price' => 8000, 'status' => 'hidden', 'categoryId' => 4, 'duration' => 1800, 'position' => 2, 'show' => 1 ),
		array( 'id' => 13, 'name' => 'Hónalj (női)', 'price' => 13000, 'status' => 'visible', 'categoryId' => 5, 'duration' => 1800, 'position' => 1, 'show' => 1 ),
		array( 'id' => 14, 'name' => 'Bajusz (férfi)', 'price' => 9000, 'status' => 'visible', 'categoryId' => 6, 'duration' => 1800, 'position' => 1, 'show' => 1 ),
		array( 'id' => 15, 'name' => 'Rejtett', 'price' => 1, 'status' => 'visible', 'categoryId' => 7, 'duration' => 1800, 'position' => 1, 'show' => 1 ),
		array( 'id' => 16, 'name' => 'Nem mutatott', 'price' => 1, 'status' => 'visible', 'categoryId' => 6, 'duration' => 1800, 'position' => 2, 'show' => 0 ),
	),
	'packages' => array(
		array( 'id' => 100, 'name' => 'Bajusz (női) – 4 alkalmas bérlet', 'price' => 28800, 'status' => 'visible' ),
		array( 'id' => 101, 'name' => 'Bajusz (női) – 8 alkalmas bérlet', 'price' => 51200, 'status' => 'visible' ),
		array( 'id' => 102, 'name' => 'Rejtett bérlet', 'price' => 1, 'status' => 'hidden' ),
		array( 'id' => 103, 'name' => 'Vegyes csomag', 'price' => 1, 'status' => 'visible' ),
		array( 'id' => 104, 'name' => 'Egy alkalmas csomag', 'price' => 1, 'status' => 'visible' ),
	),
	'package_services' => array(
		array( 'packageId' => 101, 'serviceId' => 11, 'quantity' => 8 ),
		array( 'packageId' => 100, 'serviceId' => 11, 'quantity' => 4 ),
		array( 'packageId' => 102, 'serviceId' => 13, 'quantity' => 4 ),
		array( 'packageId' => 103, 'serviceId' => 13, 'quantity' => 4 ),
		array( 'packageId' => 103, 'serviceId' => 10, 'quantity' => 4 ),
		array( 'packageId' => 104, 'serviceId' => 10, 'quantity' => 1 ),
	),
	'provider_services' => array(
		array( 'userId' => 1, 'serviceId' => 13, 'price' => 12500 ),
	),
);
$catalog = LNR_Booking_Catalog::build( $rows, 1800 );

echo "Katalógus az Amelia adataiból\n";
check( 'két nem, sorrendben', array( 'noi', 'ferfi' ) === array_column( $catalog['genders'], 'key' ) );
$noi = $catalog['genders'][0];
check( 'testtájak pozíció szerint', array( 'Arc', 'Felsőtest' ) === array_column( $noi['groups'], 'label' ) );
check( 'rejtett kategória és üres kategória kimarad', 1 === count( $catalog['genders'][1]['groups'] ) );
check( 'Default kategória kimarad', ! LNR_Booking_Catalog::find( $catalog, 1 ) );
check( 'rejtett szolgáltatás kimarad, sorrend pozíció szerint', array( 'Bajusz', 'Orca' ) === array_column( $noi['groups'][0]['items'], 'name' ) );
check( 'nem mutatott szolgáltatás kimarad', ! LNR_Booking_Catalog::find( $catalog, 16 ) );
$bajusz = $noi['groups'][0]['items'][0];
check( 'bajusz: 1 / 4 / 8 alkalom', array( 1, 4, 8 ) === array_column( $bajusz['options'], 'n' ) );
check( 'bérlet ára a csomagé', 28800.0 === $bajusz['options'][1]['price'] && 100 === $bajusz['options'][1]['packageId'] );
check( 'foglalt idő kerekítve', 1800 === $bajusz['duration'] );
$orca = $noi['groups'][0]['items'][1];
check( 'vegyes és 1 alkalmas csomag nem bérlet', 1 === count( $orca['options'] ) );
$honalj = LNR_Booking_Catalog::find( $catalog, 13 );
check( 'munkatárs ára számít', 12500.0 === $honalj['option']['price'] );
check( 'rejtett bérlet kimarad', 1 === count( $honalj['item']['options'] ) );
check( 'keresés bérlettel', 8 === LNR_Booking_Catalog::find( $catalog, 11, 101 )['option']['n'] );
check( 'más szolgáltatás bérlete nem fogadható el', null === LNR_Booking_Catalog::find( $catalog, 13, 100 ) );
check( 'ismeretlen szolgáltatás', null === LNR_Booking_Catalog::find( $catalog, 999 ) );

echo "Kosár ellenőrzése\n";
$now  = new DateTimeImmutable( '2026-09-25 12:00', new DateTimeZone( 'Europe/Budapest' ) );
$item = function ( $uid, $service, $start, $package = null ) {
	return array( 'uid' => $uid, 'serviceId' => $service, 'packageId' => $package, 'start' => $start, 'providerId' => 1 );
};
$ok = LNR_Booking_Checkout::validate_items( array(
	$item( 'a', 11, '2026-09-28 10:00', 100 ),
	$item( 'b', 13, '2026-09-28 10:30' ),
), $catalog, $now );
check( 'egymás utáni kezelések elfogadva', 2 === count( $ok ) && ! isset( $ok['error'] ) );
check( 'címke a rendeléshez', 'Bajusz (női) – 4 alkalmas bérlet' === $ok[0]['label'] && 'Hónalj (női) – 1 alkalom' === $ok[1]['label'] );
check( 'az ár nem a klienstől jön', ! isset( $ok[0]['price'] ) );
$overlap = LNR_Booking_Checkout::validate_items( array(
	$item( 'a', 13, '2026-09-28 10:15' ),
	$item( 'b', 11, '2026-09-28 10:00' ),
), $catalog, $now );
check( 'átfedés elutasítva, a későbbi tétel jelölve', 'a' === ( $overlap['error']['uid'] ?? '' ) );
$past = LNR_Booking_Checkout::validate_items( array( $item( 'p', 11, '2026-09-25 11:30' ) ), $catalog, $now );
check( 'múltbeli időpont elutasítva', 'p' === ( $past['error']['uid'] ?? '' ) );
$bad = LNR_Booking_Checkout::validate_items( array( $item( 'x', 11, '2026-02-30 10:00' ) ), $catalog, $now );
check( 'nem létező dátum elutasítva', isset( $bad['error'] ) );
$bad = LNR_Booking_Checkout::validate_items( array( $item( 'x', 11, '2026-09-28T10:00' ) ), $catalog, $now );
check( 'hibás formátum elutasítva', isset( $bad['error'] ) );
$bad = LNR_Booking_Checkout::validate_items( array( array( 'uid' => 'x', 'serviceId' => 11, 'start' => '2026-09-28 10:00' ) ), $catalog, $now );
check( 'munkatárs nélkül elutasítva', isset( $bad['error'] ) );
$bad = LNR_Booking_Checkout::validate_items( array( $item( 'x', 12, '2026-09-28 10:00' ) ), $catalog, $now );
check( 'rejtett szolgáltatás elutasítva', isset( $bad['error'] ) );
$bad = LNR_Booking_Checkout::validate_items( array( $item( 'x', 13, '2026-09-28 10:00', 100 ) ), $catalog, $now );
check( 'idegen bérlet elutasítva', isset( $bad['error'] ) );
check( 'üres kosár elutasítva', isset( LNR_Booking_Checkout::validate_items( array(), $catalog, $now )['error'] ) );
$many = array();
for ( $i = 0; $i < 11; $i++ ) {
	$many[] = $item( 'm' . $i, 11, sprintf( '2026-10-%02d 10:00', $i + 1 ) );
}
check( 'túl sok tétel elutasítva', isset( LNR_Booking_Checkout::validate_items( $many, $catalog, $now )['error'] ) );

echo "Vendég adatai\n";
$customer = array( 'lastName' => 'Teszt', 'firstName' => 'Elek', 'email' => 'teszt@example.com', 'phone' => '+36 30 123 4567' );
check( 'helyes adatok', is_array( LNR_Booking_Checkout::validate_customer( $customer ) ) );
check( 'név nélkül', is_string( LNR_Booking_Checkout::validate_customer( array_merge( $customer, array( 'firstName' => ' ' ) ) ) ) );
check( 'hibás e-mail', is_string( LNR_Booking_Checkout::validate_customer( array_merge( $customer, array( 'email' => 'nem-email' ) ) ) ) );
check( 'hibás telefonszám', is_string( LNR_Booking_Checkout::validate_customer( array_merge( $customer, array( 'phone' => 'abc' ) ) ) ) );
check( 'hiányzó adatok', is_string( LNR_Booking_Checkout::validate_customer( null ) ) );

echo "Árlista (LINORA_lezer_arlista_vegleges.xlsx)\n";
$pricelist = require $linora . '/data/pricelist.php';
$count     = 0;
$logic     = true;
$grid      = true;
foreach ( $pricelist as $gender => $groups ) {
	foreach ( $groups as $group => $items ) {
		foreach ( $items as list( $name, $minutes, $prices ) ) {
			$count++;
			$grid = $grid && 0 === $minutes % 30;
			if ( 'Kombinált' !== $group ) {
				// Árlogika: 4 alkalom 10%, 8 alkalom 20% kedvezmény.
				$logic = $logic && $prices[4] == $prices[1] * 4 * 0.9 && $prices[8] == $prices[1] * 8 * 0.8;
			} else {
				$logic = $logic && ! isset( $prices[8] ) && $prices[4] < $prices[1] * 4;
			}
		}
	}
}
check( '66 terület és csomag', 66 === $count );
check( 'bérletárak az árlogika szerint', $logic );
check( 'kezelési idők a 30 perces sávban', $grid );
check( 'női és férfi, 4-4 testtáj', array( 'Női', 'Férfi' ) === array_keys( $pricelist ) && 4 === count( $pricelist['Női'] ) && 4 === count( $pricelist['Férfi'] ) );

finish();
