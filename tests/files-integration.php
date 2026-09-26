<?php
/**
 * Fájlmegosztás integrációs tesztje valódi WordPressen: tárolás, típusszűrés, jogosultság, értesítések, portál.
 * Futtatás (a portál bővítmény aktív egy TESZT WordPressen):
 *   wp eval-file tests/files-integration.php
 * Kifelé semmi nem megy, levelet sem küld (pre_wp_mail).
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'hpv_files_store' ) ) {
	echo "A bővítmény nincs betöltve.\n";
	exit( 1 );
}

$GLOBALS['hpv_it_fail'] = 0;
$GLOBALS['hpv_it_mail'] = array();

function it( $label, $ok ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$GLOBALS['hpv_it_fail']++;
	}
}

add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['hpv_it_mail'][] = $atts;
		return true;
	},
	10,
	2
);
add_filter( 'pre_http_request', fn() => new WP_Error( 'offline', 'offline' ), 10, 3 );
// A teszt nem valódi HTTP feltöltés: az is_uploaded_file ellenőrzést itt átengedjük.
add_filter( 'hpv_files_is_uploaded', '__return_true' );

function mails_to( string $email ): array {
	return array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
}

$GLOBALS['tmpdir'] = get_temp_dir() . 'hpv-files-test-' . wp_generate_password( 6, false, false );
wp_mkdir_p( $GLOBALS['tmpdir'] );
function tmp_upload( string $name, string $bytes ): array {
	$path = $GLOBALS['tmpdir'] . '/' . wp_generate_password( 8, false, false );
	file_put_contents( $path, $bytes ); // phpcs:ignore
	return array( 'name' => $name, 'tmp_name' => $path, 'size' => strlen( $bytes ), 'error' => UPLOAD_ERR_OK );
}
function portal_as( WP_User $u, array $get ): string {
	wp_set_current_user( $u->ID );
	$_GET = $get;
	$html = hpv_p_portal_app();
	$_GET = array();
	wp_set_current_user( 1 );
	return $html;
}

$suffix = wp_generate_password( 6, false, false );
wp_set_current_user( 1 );
$hu      = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Kovács Kert Kft ' . $suffix, 'country' => 'HU', 'email' => "iroda_$suffix@kovacs.test", 'status' => 'active' ) ) );
$us      = hpv_p_insert( 'client', hpv_p_sanitize( 'client', array( 'name' => 'Sun Pools LLC ' . $suffix, 'email' => "office_$suffix@sunpools.test", 'status' => 'active' ) ) );
$maria   = hpv_p_invite_user( $hu, 'Kovács Mária', "maria_$suffix@kovacs.test" );
$mia     = hpv_p_invite_user( $us, 'Mia Sun', "mia_$suffix@sunpools.test" );
$p_open  = hpv_p_insert( 'project', array( 'client_id' => $hu, 'name' => 'Weboldal', 'status' => 'in_progress', 'visible' => 1 ) );
$p_intern = hpv_p_insert( 'project', array( 'client_id' => $hu, 'name' => 'Belső audit', 'status' => 'in_progress', 'visible' => 0 ) );
$staff_id = wp_insert_user( array( 'user_login' => 'fstaff_' . $suffix, 'user_email' => "fstaff_$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'hpv_staff', 'display_name' => 'Peter Project' ) );
$GLOBALS['hpv_it_mail'] = array();

function rest_upload( int $client_id, array $upload, array $params = array() ) {
	$req = new WP_REST_Request( 'POST', '/hpv/v1/files' );
	$req->set_body_params( array_merge( array( 'client_id' => $client_id ), $params ) );
	$req->set_file_params( array( 'file' => $upload ) );
	return rest_do_request( $req );
}

echo "Munkatárs feltölt (REST)\n";
wp_set_current_user( $staff_id );
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
$res = rest_upload( $hu, tmp_upload( 'Árajánlat terv.pdf', $pdf ), array( 'project_id' => $p_open, 'note' => 'Első változat' ) );
$f1  = $res->get_data();
it( 'feltöltve', 200 === $res->get_status() && ! empty( $f1['id'] ) );
$row = hpv_p_get( 'file', (int) $f1['id'] );
it( 'privát mappában, véletlen névvel', (bool) preg_match( '#^files/' . $hu . '/[a-f0-9]{32}\.pdf$#', $row['storage_key'] ) && is_file( hpv_files_path( $row ) ) );
it( 'a privát mappa védett (.htaccess)', is_file( hpv_p_private_dir() . '/.htaccess' ) );
it( 'eredeti név, típus, méret, projekt', 'Arajanlat-terv.pdf' === $row['name'] || 'Árajánlat-terv.pdf' === $row['name'] );
it( 'típus és méret', 'application/pdf' === $row['mime'] && strlen( $pdf ) === (int) $row['size'] && $p_open === (int) $row['project_id'] && 'staff' === $row['source'] );
$mail = mails_to( "maria_$suffix@kovacs.test" );
it( 'a magyar ügyfél magyar e-mailt kap', 1 === count( $mail ) && 0 === strpos( $mail[0]['subject'], 'Új fájl:' ) && false !== strpos( $mail[0]['message'], 'Első változat' ) );
$log = hpv_p_find( 'activity', array( 'client_id' => $hu, 'visible' => 1 ), array( 'limit' => 1 ) );
it( 'az idővonalon is megjelenik (magyarul)', $log && false !== strpos( $log[0]['body'], 'megosztott egy fájlt' ) );

$GLOBALS['hpv_it_mail'] = array();
$res = rest_upload( $hu, tmp_upload( 'belso-jegyzet.txt', "csak nekünk\n" ), array( 'visible' => '0' ) );
$f2  = hpv_p_get( 'file', (int) $res->get_data()['id'] );
it( 'belső fájl: nem látható, nincs e-mail', 200 === $res->get_status() && ! (int) $f2['visible'] && ! mails_to( "maria_$suffix@kovacs.test" ) );
$res = rest_upload( $hu, tmp_upload( 'no-notify.txt', "x\n" ), array( 'notify' => '0' ) );
it( 'e-mail nélkül is megosztható', 200 === $res->get_status() && ! mails_to( "maria_$suffix@kovacs.test" ) );
$f3 = (int) $res->get_data()['id'];

echo "Tiltott és hibás fájlok\n";
foreach ( array( 'shell.php' => '<?php echo 1;', 'page.html' => '<script>alert(1)</script>', 'logo.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'fake.jpg' => '<?php system($_GET[1]);' ) as $name => $bytes ) {
	$res = rest_upload( $hu, tmp_upload( $name, $bytes ) );
	it( "elutasítva: $name", 400 === $res->get_status() );
}
it( 'üres feltöltés', 400 === rest_upload( $hu, array( 'error' => UPLOAD_ERR_NO_FILE ) )->get_status() );
add_filter( 'hpv_files_max_size', fn() => 10 );
$res = rest_upload( $hu, tmp_upload( 'big.txt', str_repeat( 'x', 11 ) ) );
it( 'túl nagy fájl: érthető hiba', 400 === $res->get_status() && false !== strpos( $res->get_data()['message'], 'too large' ) );
remove_all_filters( 'hpv_files_max_size' );
it( 'másik ügyfél projektjéhez nem köthető', 400 === rest_upload( $us, tmp_upload( 'a.txt', "a\n" ), array( 'project_id' => $p_open ) )->get_status() );

echo "Jogosultság\n";
wp_set_current_user( $maria->ID );
it( 'ügyfél nem éri el a REST-et', 403 === rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/files' ) )->get_status() && 403 === rest_upload( $hu, tmp_upload( 'x.txt', "x\n" ) )->get_status() );
wp_set_current_user( 1 );
it( 'ügyfél látja a saját, látható fájlját', hpv_files_can_view( $row, $maria->ID ) );
it( 'ügyfél nem látja a belső fájlt', ! hpv_files_can_view( $f2, $maria->ID ) );
it( 'másik ügyfél nem látja', ! hpv_files_can_view( $row, $mia->ID ) );
it( 'munkatárs mindent lát', hpv_files_can_view( $f2, $staff_id ) );
it( 'kézzel átírt útvonal nem vezet ki a mappából', '' === hpv_files_path( array_merge( $row, array( 'storage_key' => '../../wp-config.php' ) ) ) && '' === hpv_files_path( array_merge( $row, array( 'storage_key' => 'files/1/../../x.php' ) ) ) );
$list = rest_do_request( new WP_REST_Request( 'GET', '/hpv/v1/files' ) );
it( 'lista', 200 === $list->get_status() && count( array_filter( $list->get_data()['files'], fn( $f ) => $f['client_id'] === $hu ) ) >= 3 && $list->get_data()['max'] > 0 );

echo "Ügyfél feltölt (portál)\n";
$GLOBALS['hpv_it_mail'] = array();
$cf = hpv_files_store( tmp_upload( 'logo-final.png', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ), array( 'client_id' => $hu, 'project_id' => $p_open, 'source' => 'client', 'user_id' => $maria->ID ) );
it( 'feltöltve, látható', ! is_wp_error( $cf ) && 'client' === $cf['source'] && (int) $cf['visible'] && 'image/png' === $cf['mime'] );
$staff_mail = array_values( array_filter( $GLOBALS['hpv_it_mail'], fn( $m ) => 0 === strpos( $m['subject'], 'Új fájl: logo-final.png' ) ) );
it( 'a csapat e-mailt kap, projekt névvel', 1 === count( $staff_mail ) && false !== strpos( $staff_mail[0]['message'], 'Weboldal' ) );
it( 'az ügyfél nem kap levelet a saját feltöltéséről', ! mails_to( "maria_$suffix@kovacs.test" ) );
$internal = hpv_files_store( tmp_upload( 'audit.txt', "a\n" ), array( 'client_id' => $hu, 'project_id' => $p_intern, 'source' => 'staff', 'visible' => 1, 'user_id' => $staff_id ) );
$names    = wp_list_pluck( hpv_files_portal_list( $hu ), 'name' );
it( 'a portál listája: látható fájlok, belső projekt nélkül', in_array( 'logo-final.png', $names, true ) && ! in_array( 'belso-jegyzet.txt', $names, true ) && ! in_array( 'audit.txt', $names, true ) );

echo "Portál\n";
$page = portal_as( $maria, array( 'view' => 'files' ) );
it( 'magyar felület, feltöltő űrlap', false !== strpos( $page, 'Fájl feltöltése' ) && false !== strpos( $page, 'enctype="multipart/form-data"' ) && false !== strpos( $page, 'Weboldal' ) );
it( 'a fájlok letöltési linkkel', false !== strpos( $page, 'hpv_file=' . $f1['id'] ) && false === strpos( $page, 'hpv_file=' . $f2['id'] . '"' ) );
it( 'csak a saját feltöltése törölhető', 1 === substr_count( $page, 'name="hpv_portal_action" value="delete_file"' ) );
it( 'új fájl az áttekintés teendői között', false !== strpos( portal_as( $maria, array() ), 'Új fájl:' ) );
it( 'a menüben', false !== strpos( $page, '>Fájlok<' ) );
$prev = portal_as( get_userdata( $staff_id ), array( 'view' => 'files', 'preview_client' => $hu ) );
it( 'munkatárs előnézet: nincs feltöltő űrlap', false === strpos( $prev, 'upload_file' ) && false !== strpos( $prev, 'logo-final.png' ) );

echo "Láthatóság utólag\n";
wp_set_current_user( $staff_id );
$GLOBALS['hpv_it_mail'] = array();
$req = new WP_REST_Request( 'POST', '/hpv/v1/files/' . $f2['id'] );
$req->set_body_params( array( 'visible' => true, 'notify' => true ) );
$res = rest_do_request( $req );
it( 'megosztás utólag, e-maillel', 200 === $res->get_status() && true === $res->get_data()['visible'] && 1 === count( mails_to( "maria_$suffix@kovacs.test" ) ) );
$req = new WP_REST_Request( 'POST', '/hpv/v1/files/' . $f2['id'] );
$req->set_body_params( array( 'project_id' => $p_open ) );
it( 'projekthez rendelés', $p_open === rest_do_request( $req )->get_data()['project_id'] );

echo "Törlés\n";
$path = hpv_files_path( hpv_p_get( 'file', $f3 ) );
it( 'REST törlés: a fájl is törlődik', 200 === rest_do_request( new WP_REST_Request( 'DELETE', '/hpv/v1/files/' . $f3 ) )->get_status() && ! is_file( $path ) && ! hpv_p_get( 'file', $f3 ) );
wp_set_current_user( 1 );
hpv_p_delete( 'project', $p_open );
it( 'projekt törlésekor a fájlok megmaradnak (általános)', 0 === (int) hpv_p_get( 'file', (int) $f1['id'] )['project_id'] );
$paths = array_map( 'hpv_files_path', hpv_p_find( 'file', array( 'client_id' => $hu ) ) );
hpv_p_delete( 'client', $hu );
hpv_p_delete( 'client', $us );
it( 'ügyfél törlésével a fájlok is törlődnek', ! array_filter( $paths, 'is_file' ) && ! hpv_p_find( 'file', array( 'client_id' => $hu ) ) );

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $maria->ID, $mia->ID, $staff_id ) as $uid ) {
	wp_delete_user( $uid );
}
array_map( 'wp_delete_file', glob( $GLOBALS['tmpdir'] . '/*' ) );
rmdir( $GLOBALS['tmpdir'] );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
