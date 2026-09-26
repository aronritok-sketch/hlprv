<?php
/**
 * HelloProVision bővítmények automatikus frissítése a GitHub kiadásaiból.
 *
 * Minden HelloProVision bővítményben ugyanez a fájl van (az osztály egyszer töltődik be). A GitHub Actions minden
 * verzióemeléskor kiadást készít „<bővítmény>-v<verzió>” címkével és „<bővítmény>.zip” csatolmánnyal
 * (.github/workflows/plugin-releases.yml). A WordPress ezt látja frissítésként, és magától telepíti.
 *
 * A repó privát, ezért a wp-config.php-ba egy csak olvasásra jogosult token kell:
 *   define( 'HPV_GITHUB_TOKEN', 'github_pat_…' );   // Fine-grained token: Contents = Read-only, csak a hlprv repó
 * Nem kötelező: HPV_UPDATE_REPO (alap: aronritok-sketch/hlprv), HPV_AUTO_UPDATE = false (csak jelez, nem telepít).
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'HPV_GitHub_Updater' ) ) {
	final class HPV_GitHub_Updater {
		const CACHE = 'hpv_gh_releases';

		/** @var array<string,array{file:string,basename:string,version:string,name:string}> */
		private static $plugins = array();

		public static function register( string $file, string $slug ): void {
			if ( ! function_exists( 'get_file_data' ) || ! function_exists( 'plugin_basename' ) || ! function_exists( 'add_filter' ) ) {
				return; // WordPress nélküli egységtesztek
			}
			$data = get_file_data( $file, array( 'version' => 'Version', 'name' => 'Plugin Name' ) );
			self::$plugins[ $slug ] = array(
				'file'     => $file,
				'basename' => plugin_basename( $file ),
				'version'  => (string) $data['version'],
				'name'     => (string) $data['name'],
			);
			if ( 1 === count( self::$plugins ) ) {
				add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
				add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
				add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
				add_filter( 'auto_update_plugin', array( __CLASS__, 'auto' ), 20, 2 );
				add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
			}
		}

		public static function repo(): string {
			return defined( 'HPV_UPDATE_REPO' ) ? (string) HPV_UPDATE_REPO : 'aronritok-sketch/hlprv';
		}

		public static function token(): string {
			return defined( 'HPV_GITHUB_TOKEN' ) ? (string) HPV_GITHUB_TOKEN : '';
		}

		/** Állapot a rendszeroldalakhoz. */
		public static function status(): array {
			$releases = self::releases();
			$out      = array(
				'configured' => '' !== self::token(),
				'auto'       => ! defined( 'HPV_AUTO_UPDATE' ) || HPV_AUTO_UPDATE,
				'error'      => (string) get_site_transient( self::CACHE . '_error' ),
				'plugins'    => array(),
			);
			foreach ( self::$plugins as $slug => $p ) {
				$latest                  = self::latest( $slug, $releases );
				$out['plugins'][ $slug ] = array( 'installed' => $p['version'], 'latest' => $latest ? $latest['version'] : '' );
			}

			return $out;
		}

		/** A kiadások listája (3 órás gyorsítótár, hibánál 30 perc). */
		public static function releases( bool $fresh = false ): array {
			if ( '' === self::token() ) {
				return array();
			}
			$cached = $fresh ? false : get_site_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			$res  = wp_remote_get(
				'https://api.github.com/repos/' . self::repo() . '/releases?per_page=100',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization'        => 'Bearer ' . self::token(),
						'Accept'               => 'application/vnd.github+json',
						'X-GitHub-Api-Version' => '2022-11-28',
						'User-Agent'           => 'helloprovision-updater',
					),
				)
			);
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$list = 200 === $code ? json_decode( (string) wp_remote_retrieve_body( $res ), true ) : null;
			if ( ! is_array( $list ) ) {
				$error = is_wp_error( $res ) ? $res->get_error_message() : ( 401 === $code || 403 === $code || 404 === $code ? 'A GitHub token érvénytelen, vagy nincs olvasási joga a repóhoz.' : 'GitHub válasz: HTTP ' . $code );
				set_site_transient( self::CACHE . '_error', $error, 30 * MINUTE_IN_SECONDS );
				set_site_transient( self::CACHE, array(), 30 * MINUTE_IN_SECONDS );
				return array();
			}
			delete_site_transient( self::CACHE . '_error' );
			$releases = array();
			foreach ( $list as $r ) {
				if ( ! empty( $r['draft'] ) || ! empty( $r['prerelease'] ) || ! preg_match( '/^([a-z0-9-]+)-v(\d+\.\d+\.\d+)$/', (string) ( $r['tag_name'] ?? '' ), $m ) ) {
					continue;
				}
				foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
					if ( $m[1] . '.zip' === ( $a['name'] ?? '' ) ) {
						$releases[] = array(
							'slug'      => $m[1],
							'version'   => $m[2],
							'asset'     => (string) $a['url'],
							'url'       => (string) ( $r['html_url'] ?? '' ),
							'notes'     => (string) ( $r['body'] ?? '' ),
							'published' => (string) ( $r['published_at'] ?? '' ),
						);
					}
				}
			}
			set_site_transient( self::CACHE, $releases, 3 * HOUR_IN_SECONDS );

			return $releases;
		}

		private static function latest( string $slug, array $releases ): ?array {
			$best = null;
			foreach ( $releases as $r ) {
				if ( $r['slug'] === $slug && ( ! $best || version_compare( $r['version'], $best['version'], '>' ) ) ) {
					$best = $r;
				}
			}

			return $best;
		}

		public static function inject( $transient ) {
			if ( ! is_object( $transient ) ) {
				$transient = new stdClass();
			}
			$releases = self::releases();
			foreach ( self::$plugins as $slug => $p ) {
				$latest = self::latest( $slug, $releases );
				$item   = (object) array(
					'id'          => 'github.com/' . self::repo() . '/' . $slug,
					'slug'        => $slug,
					'plugin'      => $p['basename'],
					'new_version' => $latest ? $latest['version'] : $p['version'],
					'url'         => $latest ? $latest['url'] : 'https://github.com/' . self::repo(),
					'package'     => $latest ? $latest['asset'] : '',
				);
				if ( $latest && version_compare( $latest['version'], $p['version'], '>' ) ) {
					$transient->response[ $p['basename'] ] = $item;
				} else {
					$transient->no_update[ $p['basename'] ] = $item; // így az automatikus frissítés kapcsolója is megjelenik
				}
			}

			return $transient;
		}

		public static function info( $result, $action, $args ) {
			if ( 'plugin_information' !== $action || empty( $args->slug ) || ! isset( self::$plugins[ $args->slug ] ) ) {
				return $result;
			}
			$p      = self::$plugins[ $args->slug ];
			$latest = self::latest( $args->slug, self::releases() );

			return (object) array(
				'name'          => $p['name'],
				'slug'          => $args->slug,
				'version'       => $latest ? $latest['version'] : $p['version'],
				'author'        => 'HelloProVision',
				'homepage'      => 'https://helloprovision.com',
				'last_updated'  => $latest ? $latest['published'] : '',
				'download_link' => $latest ? $latest['asset'] : '',
				'sections'      => array( 'changelog' => $latest ? wpautop( esc_html( $latest['notes'] ) ) : '' ),
			);
		}

		/**
		 * A csatolmány letöltése tokennel. A GitHub egy aláírt címre irányít át, oda már token nélkül kell menni
		 * (különben a tárhely elutasítja a kettős hitelesítést).
		 */
		public static function download( $reply, $package, $upgrader ) {
			$prefix = 'https://api.github.com/repos/' . self::repo() . '/releases/assets/';
			if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, $prefix ) ) {
				return $reply;
			}
			$res = wp_remote_get(
				$package,
				array(
					'timeout'     => 30,
					'redirection' => 0,
					'headers'     => array(
						'Authorization' => 'Bearer ' . self::token(),
						'Accept'        => 'application/octet-stream',
						'User-Agent'    => 'helloprovision-updater',
					),
				)
			);
			$location = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_header( $res, 'location' );
			if ( '' === $location ) {
				return new WP_Error( 'hpv_update', 'A frissítés nem tölthető le a GitHubról (ellenőrizd a HPV_GITHUB_TOKEN-t).' );
			}

			return download_url( $location, 300 );
		}

		public static function auto( $update, $item ) {
			if ( defined( 'HPV_AUTO_UPDATE' ) && ! HPV_AUTO_UPDATE ) {
				return $update;
			}
			$basename = is_object( $item ) ? (string) ( $item->plugin ?? '' ) : '';
			foreach ( self::$plugins as $p ) {
				if ( $p['basename'] === $basename && '' !== self::token() ) {
					return true;
				}
			}

			return $update;
		}

		public static function flush(): void {
			delete_site_transient( self::CACHE );
		}
	}
}
