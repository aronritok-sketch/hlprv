<?php
/**
 * SEO OS szerepkör a felhasználói profilon, és szinkron a FastAPI felé.
 * A szerepkör felhasználói metaadat, így a CRM munkatárs szerepköre mellett is megadható.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'show_user_profile', 'hpv_seo_profile_field' );
add_action( 'edit_user_profile', 'hpv_seo_profile_field' );

function hpv_seo_profile_field( WP_User $user ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$current = (string) get_user_meta( $user->ID, HPV_SEO_META, true );
	?>
	<h2>SEO OS</h2>
	<table class="form-table">
		<tr>
			<th><label for="hpv_seo_role">SEO OS szerepkör</label></th>
			<td>
				<?php if ( user_can( $user, 'manage_options' ) ) : ?>
					<p>Adminisztrátor: az SEO OS-ben automatikusan Admin.</p>
				<?php else : ?>
					<select name="hpv_seo_role" id="hpv_seo_role">
						<option value="">Nincs hozzáférés</option>
						<?php foreach ( HPV_SEO_ROLES as $key => $label ) : ?>
							<?php if ( 'admin' === $key ) { continue; } ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">A szerepkör a CRM munkatárs szerepkör mellett érvényes.</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}

add_action( 'personal_options_update', 'hpv_seo_profile_save' );
add_action( 'edit_user_profile_update', 'hpv_seo_profile_save' );

function hpv_seo_profile_save( int $user_id ) {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['hpv_seo_role'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- a profiloldal saját nonce-a véd
		return;
	}
	hpv_seo_set_role( $user_id, sanitize_key( wp_unslash( $_POST['hpv_seo_role'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
}

function hpv_seo_set_role( int $user_id, string $role ) {
	if ( 'admin' === $role || ( '' !== $role && ! isset( HPV_SEO_ROLES[ $role ] ) ) ) {
		return;
	}
	if ( '' === $role ) {
		delete_user_meta( $user_id, HPV_SEO_META );
	} else {
		update_user_meta( $user_id, HPV_SEO_META, $role );
	}
	hpv_seo_sync_user( $user_id );
}

/**
 * A FastAPI felhasználótükrének frissítése (szerepkör-váltás, kikapcsolás).
 * Hiba esetén nem akad el a mentés: a következő belépéskor a kérés fejléceiből úgyis frissül.
 */
function hpv_seo_sync_user( int $user_id, bool $deleted = false ) {
	$user = get_userdata( $user_id );
	if ( ! $user && ! $deleted ) {
		return;
	}
	$role = $user ? hpv_seo_user_role( $user ) : '';
	$me   = wp_get_current_user();
	$as   = array( (int) $me->ID, 'admin', (string) $me->user_email, (string) $me->display_name );
	hpv_seo_api_json(
		'PUT',
		'users/' . $user_id,
		array(
			'email'        => $user ? (string) $user->user_email : '',
			'display_name' => $user ? (string) $user->display_name : '',
			'role'         => '' !== $role ? $role : 'designer',
			'is_active'    => '' !== $role && ! $deleted,
		),
		$as
	);
}

add_action( 'profile_update', fn( $user_id ) => current_user_can( 'manage_options' ) && hpv_seo_sync_user( (int) $user_id ) );
add_action( 'set_user_role', fn( $user_id ) => current_user_can( 'manage_options' ) && hpv_seo_sync_user( (int) $user_id ) );
add_action( 'delete_user', fn( $user_id ) => current_user_can( 'manage_options' ) && hpv_seo_sync_user( (int) $user_id, true ) );
