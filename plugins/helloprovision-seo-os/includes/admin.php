<?php
/**
 * Beállítások → SEO OS: szerverkapcsolat, kapcsolatteszt, csapat szerepkörei.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'hpv_seo_admin_menu' );

function hpv_seo_admin_menu() {
	add_options_page( 'SEO OS', 'SEO OS', 'manage_options', 'hpv-seo-os', 'hpv_seo_admin_page' );
}

add_filter(
	'plugin_action_links_' . plugin_basename( HPV_SEO_FILE ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=hpv-seo-os' ) ) . '">Beállítások</a>' );
		return $links;
	}
);

add_action( 'admin_init', 'hpv_seo_register_settings' );

function hpv_seo_register_settings() {
	register_setting(
		'hpv_seo_os',
		HPV_SEO_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'hpv_seo_sanitize_settings',
		)
	);
}

function hpv_seo_sanitize_settings( $input ): array {
	$old   = hpv_seo_settings();
	$input = is_array( $input ) ? $input : array();
	$out   = array(
		'api_url' => esc_url_raw( trim( (string) ( $input['api_url'] ?? $old['api_url'] ) ) ),
		'host'    => strtolower( sanitize_text_field( (string) ( $input['host'] ?? $old['host'] ) ) ),
		'secret'  => $old['secret'],
	);
	$secret = trim( (string) ( $input['secret'] ?? '' ) );
	if ( '' !== $secret ) {
		if ( strlen( $secret ) < 32 ) {
			add_settings_error( HPV_SEO_OPTION, 'secret', 'A közös titok legalább 32 karakter legyen. A régi titok maradt érvényben.' );
		} else {
			$out['secret'] = $secret;
		}
	}

	return $out;
}

add_action( 'admin_post_hpv_seo_roles', 'hpv_seo_admin_save_roles' );

function hpv_seo_admin_save_roles() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_seo_roles' );
	$roles = isset( $_POST['role'] ) && is_array( $_POST['role'] ) ? wp_unslash( $_POST['role'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	foreach ( $roles as $user_id => $role ) {
		$user_id = absint( $user_id );
		$role    = sanitize_key( (string) $role );
		if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
			continue;
		}
		if ( (string) get_user_meta( $user_id, HPV_SEO_META, true ) !== $role ) {
			hpv_seo_set_role( $user_id, $role );
		}
	}
	wp_safe_redirect( admin_url( 'options-general.php?page=hpv-seo-os&saved=roles' ) );
	exit;
}

function hpv_seo_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s          = hpv_seo_settings();
	$secret_cfg = defined( 'HPV_SEO_OS_SECRET' );
	$url_cfg    = defined( 'HPV_SEO_OS_API_URL' );
	$test       = null;
	if ( isset( $_GET['test'] ) && check_admin_referer( 'hpv_seo_test' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$health = hpv_seo_api( 'GET', 'health' );
		$me     = hpv_seo_api_json( 'GET', 'me' );
		$test   = array(
			'health' => is_wp_error( $health ) ? $health->get_error_message() : ( 200 === $health['status'] ? 'OK' : 'HTTP ' . $health['status'] . ' ' . $health['body'] ),
			'me'     => is_wp_error( $me ) ? $me->get_error_message() : 'OK – ' . ( $me['name'] ?? '' ) . ' (' . ( $me['role_label'] ?? '' ) . ')',
		);
	}
	?>
	<div class="wrap">
		<h1>HelloProVision SEO OS</h1>
		<p>Az alkalmazás címe: <a href="<?php echo esc_url( hpv_seo_app_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( hpv_seo_app_url() ); ?></a></p>
		<?php settings_errors( HPV_SEO_OPTION ); ?>

		<h2>Szerverkapcsolat</h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'hpv_seo_os' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="hpv-seo-api">API cím</label></th>
					<td>
						<?php if ( $url_cfg ) : ?>
							<code><?php echo esc_html( hpv_seo_api_url() ); ?></code> <span class="description">(wp-config.php: HPV_SEO_OS_API_URL)</span>
						<?php else : ?>
							<input id="hpv-seo-api" class="regular-text" name="<?php echo esc_attr( HPV_SEO_OPTION ); ?>[api_url]" value="<?php echo esc_attr( $s['api_url'] ); ?>">
							<p class="description">A FastAPI szolgáltatás belső címe, pl. <code>http://127.0.0.1:8100</code>. Ne legyen nyilvános.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="hpv-seo-secret">Közös titok</label></th>
					<td>
						<?php if ( $secret_cfg ) : ?>
							<span class="description">A wp-config.php-ban van megadva (HPV_SEO_OS_SECRET).</span>
						<?php else : ?>
							<input id="hpv-seo-secret" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr( HPV_SEO_OPTION ); ?>[secret]" placeholder="<?php echo $s['secret'] ? esc_attr( 'Beállítva – üresen hagyva marad' ) : ''; ?>">
							<p class="description">Ugyanaz, mint a szerveren a <code>SEO_OS_HMAC_SECRET</code>. Legalább 32 karakter.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="hpv-seo-host">Aldomain</label></th>
					<td><input id="hpv-seo-host" class="regular-text" name="<?php echo esc_attr( HPV_SEO_OPTION ); ?>[host]" value="<?php echo esc_attr( $s['host'] ); ?>"></td>
				</tr>
			</table>
			<?php submit_button( 'Mentés' ); ?>
		</form>

		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=hpv-seo-os&test=1' ), 'hpv_seo_test' ) ); ?>">Kapcsolat tesztelése</a>
		</p>
		<?php if ( $test ) : ?>
			<table class="widefat striped" style="max-width:640px">
				<tr><td>Szerver elérhető</td><td><?php echo esc_html( $test['health'] ); ?></td></tr>
				<tr><td>Aláírás és belépés</td><td><?php echo esc_html( $test['me'] ); ?></td></tr>
			</table>
		<?php endif; ?>

		<h2>Csapat</h2>
		<p>Ki milyen szerepkörrel dolgozik az SEO OS-ben. A WordPress adminisztrátorok automatikusan Adminok. A szerepkör a CRM munkatárs szerepkör mellett érvényes.</p>
		<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success"><p>Szerepkörök mentve.</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="hpv_seo_roles">
			<?php wp_nonce_field( 'hpv_seo_roles' ); ?>
			<table class="widefat striped" style="max-width:820px">
				<thead><tr><th>Felhasználó</th><th>E-mail</th><th>WordPress szerepkör</th><th>SEO OS szerepkör</th></tr></thead>
				<tbody>
				<?php foreach ( get_users( array( 'role__not_in' => array( 'hpv_client', 'subscriber', 'customer' ), 'orderby' => 'display_name' ) ) as $u ) : ?>
					<tr>
						<td><?php echo esc_html( $u->display_name ); ?></td>
						<td><?php echo esc_html( $u->user_email ); ?></td>
						<td><?php echo esc_html( implode( ', ', $u->roles ) ); ?></td>
						<td>
							<?php if ( user_can( $u, 'manage_options' ) ) : ?>
								Admin
							<?php else : ?>
								<?php $cur = (string) get_user_meta( $u->ID, HPV_SEO_META, true ); ?>
								<select name="role[<?php echo (int) $u->ID; ?>]">
									<option value="">Nincs hozzáférés</option>
									<?php foreach ( HPV_SEO_ROLES as $key => $label ) : ?>
										<?php if ( 'admin' === $key ) { continue; } ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cur, $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Szerepkörök mentése' ); ?>
		</form>
	</div>
	<?php
}
