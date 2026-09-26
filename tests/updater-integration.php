<?php
/**
 * Automatikus frissítés a GitHub kiadásaiból (a GitHubot a teszt helyettesíti).
 * Futtatás (valamelyik HelloProVision bővítmény aktív egy TESZT WordPressen):
 *   wp eval-file tests/updater-integration.php
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'HPV_GitHub_Updater' ) ) {
	echo "A frissítő nincs betöltve.\n";
	exit( 1 );
}

$GLOBALS['hpv_it_fail'] = 0;
function it( $label, $ok ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$GLOBALS['hpv_it_fail']++;
	}
}

$plugin = defined( 'HPV_SEO_FILE' ) ? 'helloprovision-seo-os' : 'helloprovision-portal';
$file   = defined( 'HPV_SEO_FILE' ) ? HPV_SEO_FILE : HPV_PORTAL_FILE;
$base   = plugin_basename( $file );
$have   = get_file_data( $file, array( 'v' => 'Version' ) )['v'];
$GLOBALS['gh'] = array( 'calls' => array(), 'status' => 200 );

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( $plugin ) {
		if ( 0 === strpos( $url, 'https://api.github.com/repos/aronritok-sketch/hlprv/releases?' ) ) {
			$GLOBALS['gh']['calls'][] = array( $url, $args['headers'] );
			$body = array(
				array( 'tag_name' => $plugin . '-v9.9.0', 'draft' => false, 'prerelease' => false, 'html_url' => 'https://github.com/x/r/9', 'body' => "Új: e-mail modul\n", 'published_at' => '2026-09-27T10:00:00Z', 'assets' => array( array( 'name' => $plugin . '.zip', 'url' => 'https://api.github.com/repos/aronritok-sketch/hlprv/releases/assets/99' ) ) ),
				array( 'tag_name' => $plugin . '-v10.0.0', 'draft' => true, 'prerelease' => false, 'assets' => array( array( 'name' => $plugin . '.zip', 'url' => 'x' ) ) ),
				array( 'tag_name' => $plugin . '-v9.5.0', 'draft' => false, 'prerelease' => false, 'assets' => array( array( 'name' => $plugin . '.zip', 'url' => 'https://api.github.com/repos/aronritok-sketch/hlprv/releases/assets/95' ) ) ),
				array( 'tag_name' => 'other-plugin-v99.0.0', 'draft' => false, 'prerelease' => false, 'assets' => array( array( 'name' => 'other-plugin.zip', 'url' => 'y' ) ) ),
			);
			return array( 'response' => array( 'code' => $GLOBALS['gh']['status'] ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
		}
		if ( 'https://api.github.com/repos/aronritok-sketch/hlprv/releases/assets/99' === $url ) {
			$GLOBALS['gh']['asset'] = $args;
			return array( 'response' => array( 'code' => 302 ), 'headers' => array( 'location' => 'https://objects.githubusercontent.com/signed/99' ), 'body' => '' );
		}
		if ( 'https://objects.githubusercontent.com/signed/99' === $url ) {
			$GLOBALS['gh']['signed'] = $args;
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], 'PK-zip' );
			}
			return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => '', 'filename' => $args['filename'] ?? '' );
		}
		return $pre;
	},
	10,
	3
);

echo "Token nélkül\n";
HPV_GitHub_Updater::flush();
$t = HPV_GitHub_Updater::inject( new stdClass() );
it( 'nincs token: nem hívja a GitHubot, nincs frissítés', ! $GLOBALS['gh']['calls'] && empty( $t->response[ $base ] ) && isset( $t->no_update[ $base ] ) );
it( 'nincs token: nem frissít automatikusan', null === HPV_GitHub_Updater::auto( null, (object) array( 'plugin' => $base ) ) );

if ( defined( 'HPV_GITHUB_TOKEN' ) ) {
	echo "A teszt WordPressben már van HPV_GITHUB_TOKEN, a többi eset kimarad.\n";
	exit( $GLOBALS['hpv_it_fail'] ? 1 : 0 );
}
define( 'HPV_GITHUB_TOKEN', 'github_pat_test' );

echo "Kiadások\n";
$t = HPV_GitHub_Updater::inject( new stdClass() );
$u = $t->response[ $base ] ?? null;
it( 'a legújabb nem-piszkozat kiadás: 9.9.0', $u && '9.9.0' === $u->new_version && version_compare( '9.9.0', $have, '>' ) );
it( 'csomag = a csatolmány API címe', $u && 'https://api.github.com/repos/aronritok-sketch/hlprv/releases/assets/99' === $u->package );
it( 'Bearer token a kérésben', 'Bearer github_pat_test' === ( $GLOBALS['gh']['calls'][0][1]['Authorization'] ?? '' ) );
HPV_GitHub_Updater::inject( new stdClass() );
it( 'gyorsítótár: nem kérdez újra', 1 === count( $GLOBALS['gh']['calls'] ) );
$info = HPV_GitHub_Updater::info( false, 'plugin_information', (object) array( 'slug' => $plugin ) );
it( 'részletek ablak: verzió és változásnapló', is_object( $info ) && '9.9.0' === $info->version && false !== strpos( $info->sections['changelog'], 'e-mail modul' ) );
it( 'automatikus frissítés be', true === HPV_GitHub_Updater::auto( null, (object) array( 'plugin' => $base ) ) );
it( 'más bővítményt nem érint', null === HPV_GitHub_Updater::auto( null, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );

echo "Letöltés\n";
$tmp = HPV_GitHub_Updater::download( false, 'https://api.github.com/repos/aronritok-sketch/hlprv/releases/assets/99', null );
it( 'tokennel kéri, átirányítás nélkül', 'Bearer github_pat_test' === $GLOBALS['gh']['asset']['headers']['Authorization'] && 0 === $GLOBALS['gh']['asset']['redirection'] && 'application/octet-stream' === $GLOBALS['gh']['asset']['headers']['Accept'] );
it( 'az aláírt címet token nélkül tölti le', is_string( $tmp ) && ! isset( $GLOBALS['gh']['signed']['headers']['Authorization'] ) && 'PK-zip' === @file_get_contents( $tmp ) );
@unlink( is_string( $tmp ) ? $tmp : '' );
it( 'más csomagot nem vesz át', false === HPV_GitHub_Updater::download( false, 'https://downloads.wordpress.org/plugin/akismet.zip', null ) );

echo "Hiba\n";
HPV_GitHub_Updater::flush();
$GLOBALS['gh']['status'] = 401;
$t = HPV_GitHub_Updater::inject( new stdClass() );
$s = HPV_GitHub_Updater::status();
it( 'rossz token: nincs frissítés, érthető hiba', empty( $t->response[ $base ] ) && false !== strpos( $s['error'], 'token' ) );
HPV_GitHub_Updater::flush();
delete_site_transient( HPV_GitHub_Updater::CACHE . '_error' );

echo $GLOBALS['hpv_it_fail'] ? "\n{$GLOBALS['hpv_it_fail']} hiba\n" : "\nMinden teszt sikeres\n";
