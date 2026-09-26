<?php
/**
 * HelloProVision – WordPress telepítése a CRM mappába, terminál és tömeges FileZilla-feltöltés nélkül.
 *
 * Használat: töltsd fel a webroot/crm mappába, majd nyisd meg: https://crm.helloprovision.com/hpv-install-wp.php
 * A szerver maga tölti le a WordPress legfrissebb magyar változatát (hu.wordpress.org), és kicsomagolja ebbe a mappába.
 * Csak ÜRES mappában fut (a meglévő weboldalhoz nem nyúl), 2 óra után letiltja magát, végül magát is törli.
 */

header( 'Content-Type: text/html; charset=utf-8' );
header( 'X-Robots-Tag: noindex' );

const HPV_WP_ZIP = 'https://hu.wordpress.org/latest-hu_HU.zip';

function hpv_page( string $title, string $body ): void {
	echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars( $title ) . '</title>'
		. '<body style="font:16px/1.5 -apple-system,Segoe UI,Arial,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;color:#16160f">'
		. '<p style="font-weight:800;letter-spacing:.08em;color:#6b7a2a">HELLOPROVISION</p><h1 style="font-size:24px">' . htmlspecialchars( $title ) . '</h1>' . $body . '</body>';
	exit;
}

function hpv_rrmdir( string $dir ): void {
	foreach ( (array) @scandir( $dir ) as $e ) {
		if ( '.' === $e || '..' === $e || '' === $e ) {
			continue;
		}
		$p = "$dir/$e";
		is_dir( $p ) && ! is_link( $p ) ? hpv_rrmdir( $p ) : @unlink( $p );
	}
	@rmdir( $dir );
}

$dir   = __DIR__;
$token = (string) filemtime( __FILE__ );

if ( time() - (int) $token > 2 * 3600 ) {
	http_response_code( 410 );
	hpv_page( 'Lejárt', '<p>Ez a telepítő fájl 2 óra után letiltja magát. Töltsd fel újra, vagy töröld.</p>' );
}

// Védelem: csak üres mappába, a fő weboldalhoz nem nyúl.
$existing = array_values( array_diff( (array) scandir( $dir ), array( '.', '..', basename( __FILE__ ), 'hpv-check.php', '.well-known' ) ) );
if ( 'wordpress' === basename( $dir ) || file_exists( "$dir/wp-settings.php" ) || file_exists( "$dir/wp-config.php" ) ) {
	hpv_page( 'Itt már van WordPress', '<p>Ebben a mappában (' . htmlspecialchars( $dir ) . ') már van WordPress, ezért nem telepítek. Ha a telepítés már lefutott, folytasd itt: <a href="/wp-admin/install.php">/wp-admin/install.php</a>. Ezt a fájlt töröld.</p>' );
}
if ( $existing ) {
	hpv_page( 'A mappa nem üres', '<p>A(z) <code>' . htmlspecialchars( $dir ) . '</code> mappában más fájlok is vannak (' . htmlspecialchars( implode( ', ', array_slice( $existing, 0, 8 ) ) ) . '). Biztonsági okból csak üres mappába telepítek.</p>' );
}

if ( ! isset( $_GET['go'] ) || ! hash_equals( $token, (string) $_GET['go'] ) ) {
	$free = @disk_free_space( $dir );
	hpv_page(
		'WordPress telepítése a CRM-nek',
		'<p>Mappa: <code>' . htmlspecialchars( $dir ) . '</code><br>Szabad hely: ' . ( $free ? round( $free / 1024 / 1024 / 1024, 1 ) . ' GB' : '?' ) . '</p>'
		. '<p>A szerver letölti a WordPress legfrissebb magyar változatát, és kicsomagolja ebbe a mappába (kb. 30 másodperc). A fő weboldalhoz (webroot/wordpress) nem nyúl.</p>'
		. '<p><a href="?go=' . $token . '" style="display:inline-block;background:#16160f;color:#fff;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:700">WordPress letöltése és kicsomagolása</a></p>'
	);
}

@set_time_limit( 600 );
@ini_set( 'memory_limit', '128M' );
if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'curl_init' ) ) {
	hpv_page( 'Hiányzó PHP kiterjesztés', '<p>A telepítéshez a zip és a curl kiterjesztés kell.</p>' );
}

$zip_path = "$dir/.hpv-wordpress.zip";
$tmp      = "$dir/.hpv-wordpress-tmp";
$fh       = fopen( $zip_path, 'wb' );
$ch       = curl_init( HPV_WP_ZIP );
curl_setopt_array( $ch, array( CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'hpv-install-wp', CURLOPT_FAILONERROR => true ) );
$ok   = curl_exec( $ch );
$err  = curl_error( $ch );
curl_close( $ch );
fclose( $fh );
if ( ! $ok || filesize( $zip_path ) < 1000000 ) {
	@unlink( $zip_path );
	hpv_page( 'A letöltés nem sikerült', '<p>' . htmlspecialchars( $err ?: 'Túl kicsi fájl érkezett.' ) . '</p><p>Próbáld újra később.</p>' );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path ) ) {
	@unlink( $zip_path );
	hpv_page( 'Hibás letöltés', '<p>A letöltött fájl nem olvasható ZIP-ként. Próbáld újra.</p>' );
}
hpv_rrmdir( $tmp );
$zip->extractTo( $tmp );
$zip->close();
@unlink( $zip_path );

$src = "$tmp/wordpress";
if ( ! is_dir( $src ) || ! file_exists( "$src/wp-settings.php" ) ) {
	hpv_rrmdir( $tmp );
	hpv_page( 'Váratlan csomag', '<p>A letöltött csomagban nem találom a WordPresst.</p>' );
}
$moved = 0;
foreach ( scandir( $src ) as $e ) {
	if ( '.' === $e || '..' === $e ) {
		continue;
	}
	if ( rename( "$src/$e", "$dir/$e" ) ) {
		$moved++;
	}
}
hpv_rrmdir( $tmp );
$ver = preg_match( "/wp_version = '([^']+)'/", (string) @file_get_contents( "$dir/wp-includes/version.php" ), $m ) ? $m[1] : '?';
@unlink( __FILE__ );

hpv_page(
	'Kész – WordPress ' . $ver . ' kicsomagolva',
	'<p>' . $moved . ' elem került a mappába. A telepítő fájl törölte magát.</p>'
	. '<p><strong>Következő lépés:</strong> a WordPress beállítása (adatbázis, admin felhasználó).</p>'
	. '<p><a href="/wp-admin/setup-config.php" style="display:inline-block;background:#A4DA4C;color:#16160f;padding:12px 20px;border-radius:999px;text-decoration:none;font-weight:700">Tovább a WordPress beállításához</a></p>'
);
