<?php
/**
 * HelloProVision SEO OS – tárhely-ellenőrzés terminál nélkül.
 *
 * Használat: töltsd fel FileZillával abba a mappába, amit a seo aldomain kiszolgál (pl. webroot/seo), majd nyisd meg:
 *   https://seo.helloprovision.com/hpv-check.php
 * A feltöltés után 2 óráig működik, utána magától letiltja magát. Jelszót, kulcsot nem ír ki. Használat után töröld.
 */

header( 'Content-Type: text/plain; charset=utf-8' );
header( 'X-Robots-Tag: noindex' );

if ( time() - filemtime( __FILE__ ) > 2 * 3600 ) {
	http_response_code( 410 );
	exit( "Ez az ellenőrző fájl lejárt (2 óra). Töltsd fel újra, vagy töröld.\n" );
}

function line( $label, $value ) {
	$pad = 34 - ( function_exists( 'mb_strlen' ) ? mb_strlen( $label, 'UTF-8' ) : strlen( $label ) );
	echo $label . str_repeat( ' ', max( 1, $pad ) ) . ( is_bool( $value ) ? ( $value ? 'IGEN' : 'NEM' ) : $value ) . "\n";
}
function section( $title ) {
	echo "\n===== $title =====\n";
}
function probe( $url ) {
	if ( ! function_exists( 'curl_init' ) ) {
		return 'nincs curl';
	}
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => false, CURLOPT_USERAGENT => 'hpv-check' ) );
	curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$err  = curl_error( $ch );
	curl_close( $ch );
	return $code ? "HTTP $code" : "HIBA: $err";
}

section( 'Kérés' );
line( 'Cím (host)', $_SERVER['HTTP_HOST'] ?? '?' );
line( 'HTTPS', ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] );
line( 'Ez a mappa', __DIR__ );
line( 'Valódi útvonal', realpath( __DIR__ ) );
line( 'Document root', $_SERVER['DOCUMENT_ROOT'] ?? '?' );
line( 'Szerver szoftver', $_SERVER['SERVER_SOFTWARE'] ?? '?' );

section( 'PHP' );
line( 'Verzió', PHP_VERSION . ' (' . PHP_SAPI . ')' );
foreach ( array( 'curl', 'openssl', 'json', 'mbstring', 'mysqli', 'zip', 'gd', 'imagick', 'intl', 'dom', 'fileinfo' ) as $ext ) {
	line( "  kiterjesztés: $ext", extension_loaded( $ext ) );
}
foreach ( array( 'memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'allow_url_fopen' ) as $ini ) {
	line( "  $ini", (string) ini_get( $ini ) );
}
$disabled = array_filter( array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
line( '  tiltott függvények', $disabled ? implode( ', ', array_slice( $disabled, 0, 15 ) ) . ( count( $disabled ) > 15 ? ' …' : '' ) : '–' );
line( '  shell futtatás (proc_open)', function_exists( 'proc_open' ) && ! in_array( 'proc_open', $disabled, true ) );

section( 'Szomszéd mappák (webroot)' );
foreach ( array_unique( array( dirname( __DIR__ ), dirname( realpath( __DIR__ ) ) ) ) as $base ) {
	echo "-- $base\n";
	foreach ( (array) @scandir( $base ) as $e ) {
		if ( '.' === $e || '..' === $e ) {
			continue;
		}
		$p = "$base/$e";
		if ( is_link( $p ) ) {
			$t = readlink( $p );
			line( "  $e", "LINK -> $t" . ( file_exists( $p ) ? '' : '  !!! HIBÁS (a cél nem létezik)' ) );
		} elseif ( is_dir( $p ) ) {
			line( "  $e", 'mappa' );
		}
	}
}

section( 'WordPress' );
$wp = null;
foreach ( array( __DIR__, dirname( __DIR__ ), realpath( __DIR__ ) ) as $d ) {
	if ( $d && file_exists( "$d/wp-config.php" ) || ( $d && file_exists( dirname( $d ) . '/wp-config.php' ) && file_exists( "$d/wp-settings.php" ) ) ) {
		$wp = $d;
		break;
	}
}
if ( ! $wp ) {
	echo "Nincs WordPress ebben a mappában (nincs wp-config.php). Ha a WordPress még nincs feltelepítve, ez rendben van.\n";
	line( 'wp-settings.php (fájlok feltöltve?)', file_exists( __DIR__ . '/wp-settings.php' ) );
} else {
	line( 'WordPress mappa', $wp );
	$ver = @file_get_contents( "$wp/wp-includes/version.php" );
	line( 'WordPress verzió', preg_match( "/wp_version = '([^']+)'/", (string) $ver, $m ) ? $m[1] : '?' );
	$cfg = (string) @file_get_contents( file_exists( "$wp/wp-config.php" ) ? "$wp/wp-config.php" : dirname( $wp ) . '/wp-config.php' );
	foreach ( array( 'WP_HOME', 'WP_SITEURL', 'HPV_SEO_OS_SECRET', 'HPV_SEO_OS_API_URL', 'HPV_SEO_HOST', 'HPV_CRM_HOST', 'HPV_PORTAL_HOST', 'DISABLE_WP_CRON', 'WP_DEBUG' ) as $c ) {
		line( "  wp-config: $c", false !== strpos( $cfg, "'$c'" ) ? 'beállítva' : '–' );
	}
	line( '  adatbázis host', preg_match( "/'DB_HOST',\s*'([^']*)'/", $cfg, $m ) ? $m[1] : '?' );
	foreach ( array( 'wp-content', 'wp-content/plugins', 'wp-content/uploads' ) as $d ) {
		line( "  írható: $d", is_writable( "$wp/$d" ) );
	}
	echo "  bővítmények:\n";
	foreach ( (array) @scandir( "$wp/wp-content/plugins" ) as $p ) {
		if ( '.' !== $p[0] && is_dir( "$wp/wp-content/plugins/$p" ) ) {
			echo "    - $p\n";
		}
	}
}

section( 'Kimenő kapcsolatok (a WordPress innen hívja az API-t és az AI-szolgáltatásokat)' );
line( 'github.com', probe( 'https://api.github.com/zen' ) );
line( 'api.openai.com', probe( 'https://api.openai.com/v1/models' ) );
line( 'api.anthropic.com', probe( 'https://api.anthropic.com/v1/models' ) );
line( 'onrender.com', probe( 'https://render.com/' ) );
echo "\n(A 401 / 403 válasz itt jó jel: a kapcsolat létrejött, csak nincs kulcs megadva.)\n";

section( 'Nginx (webszerver) – be van-e töltve a módosított beállítás?' );
$conf   = '/home/container/etc/nginx/nginx.conf';
$pidf   = '/home/container/logs/nginx/nginx.pid';
$marker = '/home/container/tmp/hpv-nginx-reloaded';
$pid    = @is_readable( $pidf ) ? (int) trim( (string) @file_get_contents( $pidf ) ) : 0;
$conf_t = @is_readable( $conf ) ? filemtime( $conf ) : 0;
$load_t = max( $pid ? (int) @filemtime( $pidf ) : 0, @file_exists( $marker ) ? (int) @filemtime( $marker ) : 0 );
$fmt    = function ( $t ) { return $t ? date( 'Y-m-d H:i:s', $t ) : '?'; };
line( 'nginx.conf módosítva', $conf_t ? $fmt( $conf_t ) : 'nem olvasható (' . $conf . ')' );
line( 'nginx elindítva / újratöltve', $pid ? $fmt( $load_t ) . " (folyamat: $pid)" : 'nem található (' . $pidf . ')' );
$stale = $conf_t && $load_t && $conf_t > $load_t;
line( 'Állapot', ! $conf_t || ! $pid ? 'nem megállapítható' : ( $stale ? 'A MÓDOSÍTÁS MÉG NINCS BETÖLTVE' : 'a jelenlegi beállítás van betöltve' ) );
$me = function_exists( 'posix_geteuid' ) ? ( posix_getpwuid( posix_geteuid() )['name'] ?? posix_geteuid() ) : '?';
line( 'PHP felhasználó', (string) $me );
line( 'nginx felhasználó', $pid && function_exists( 'posix_getpwuid' ) && @file_exists( "/proc/$pid" ) ? ( posix_getpwuid( fileowner( "/proc/$pid" ) )['name'] ?? '?' ) : '?' );

$can_exec = function_exists( 'exec' ) && ! in_array( 'exec', $disabled, true );
$bin      = '';
foreach ( array( '/usr/local/openresty/bin/openresty', '/usr/local/openresty/nginx/sbin/nginx', '/usr/bin/openresty', '/usr/sbin/nginx', '/usr/bin/nginx' ) as $b ) {
	if ( @is_executable( $b ) ) {
		$bin = $b;
		break;
	}
}
line( 'nginx program', $bin ?: 'nem található' );

$token = (string) filemtime( __FILE__ );
if ( isset( $_GET['reload'] ) && hash_equals( $token, (string) $_GET['reload'] ) ) {
	echo "\n--- Újratöltés ---\n";
	$ok_test = null;
	if ( $bin && $can_exec ) {
		$out = array();
		exec( escapeshellarg( $bin ) . ' -t -c ' . escapeshellarg( $conf ) . ' 2>&1', $out, $rc );
		echo "Beállítás ellenőrzése:\n  " . implode( "\n  ", $out ) . "\n";
		$ok_test = 0 === $rc;
	} else {
		echo "Ellenőrzés: nem futtatható ezen a tárhelyen – az nginx hibás beállításnál a régit tartja meg.\n";
	}
	if ( false === $ok_test ) {
		echo "\nA beállításban HIBA van – nem töltöttem újra. Javítsd a fenti hibát (vagy töltsd vissza a mentett nginx.conf-ot).\n";
	} elseif ( ! $pid ) {
		echo "\nNem találom az nginx folyamatot – újratöltés nem lehetséges innen.\n";
	} else {
		$sent = function_exists( 'posix_kill' ) ? @posix_kill( $pid, 1 ) : false;
		if ( ! $sent && $can_exec ) {
			exec( 'kill -HUP ' . (int) $pid . ' 2>&1', $o, $rc2 );
			$sent = 0 === $rc2;
		}
		if ( $sent ) {
			@touch( $marker );
			echo "\nKÉSZ: az nginx újratöltötte a beállítást. Most nyisd meg: https://seo.helloprovision.com/hpv-check.php\n";
		} else {
			echo "\nNem sikerült jelezni az nginx-nek (nincs jogosultság). Ilyenkor a tárhely-szolgáltató tudja újraindítani.\n";
		}
	}
} elseif ( $stale ) {
	$self = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '/hpv-check.php' ), '?' );
	echo "\n>>> Újratöltéshez nyisd meg ezt a címet:\n    https://" . ( $_SERVER['HTTP_HOST'] ?? 'helloprovision.com' ) . $self . "?reload=$token\n";
}

section( 'Ütemezés' );
line( 'Most (szerveridő)', date( 'Y-m-d H:i:s T' ) );

echo "\nKész. Másold ki a teljes szöveget, majd töröld ezt a fájlt a szerverről.\n";
