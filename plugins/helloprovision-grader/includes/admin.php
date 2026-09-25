<?php
/**
 * Admin: érdeklődők listája, riport nézet, CSV export, beállítások.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'hpv_grader_admin_menu' );
add_action( 'admin_init', 'hpv_grader_admin_init' );
add_action( 'admin_post_hpv_grader_export', 'hpv_grader_export_csv' );
add_action( 'admin_post_hpv_grader_delete', 'hpv_grader_delete_lead' );

function hpv_grader_admin_menu() {
	add_menu_page( 'Website Grader', 'Website Grader', 'manage_options', HPV_GRADER_PAGE, 'hpv_grader_render_leads_page', 'dashicons-performance', 59 );
	add_submenu_page( HPV_GRADER_PAGE, 'Érdeklődők', 'Érdeklődők', 'manage_options', HPV_GRADER_PAGE, 'hpv_grader_render_leads_page' );
	add_submenu_page( HPV_GRADER_PAGE, 'Website Grader beállítások', 'Beállítások', 'manage_options', HPV_GRADER_SETTINGS, 'hpv_grader_render_settings_page' );
}

function hpv_grader_admin_init() {
	register_setting( 'hpv_grader', HPV_GRADER_OPTION, array( 'sanitize_callback' => 'hpv_grader_sanitize_settings' ) );
}

function hpv_grader_lead( int $id ): array {
	$report = json_decode( (string) get_post_meta( $id, '_hpv_report', true ), true );

	return array(
		'id'       => $id,
		'date'     => get_post_field( 'post_date', $id ),
		'name'     => (string) get_post_field( 'post_title', $id ),
		'email'    => (string) get_post_meta( $id, '_hpv_email', true ),
		'business' => (string) get_post_meta( $id, '_hpv_business', true ),
		'url'      => (string) get_post_meta( $id, '_hpv_url', true ),
		'overall'  => get_post_meta( $id, '_hpv_overall', true ),
		'report'   => is_array( $report ) ? $report : null,
	);
}

function hpv_grader_lead_ids( int $limit = 500 ): array {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => HPV_GRADER_CPT,
				'post_status'    => 'private',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
			)
		)
	);
}

function hpv_grader_render_leads_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$lead_id = absint( $_GET['lead'] ?? 0 );
	if ( $lead_id && HPV_GRADER_CPT === get_post_type( $lead_id ) ) {
		hpv_grader_render_lead( hpv_grader_lead( $lead_id ) );
		return;
	}

	$leads    = array_map( 'hpv_grader_lead', hpv_grader_lead_ids() );
	$settings = hpv_grader_settings();
	$page_url = admin_url( 'admin.php?page=' . HPV_GRADER_PAGE );
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Website Grader – érdeklődők</h1>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hpv_grader_export' ), 'hpv_grader_export' ) ); ?>" class="page-title-action">Export CSV</a>
		<hr class="wp-header-end">

		<?php if ( isset( $_GET['deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Törölve.</p></div>
		<?php endif; ?>
		<?php if ( '' === $settings['psi_key'] ) : ?>
			<div class="notice notice-warning"><p>Nincs megadva Google PageSpeed API kulcs — kulcs nélkül a sebességmérés gyakran nem fut le. <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . HPV_GRADER_SETTINGS ) ); ?>">Beállítások →</a></p></div>
		<?php endif; ?>

		<p>Az eszköz megjelenítése: tedd a <code>[hpv_grader]</code> shortcode-ot egy oldalra (pl. <code>/website-grader/</code>).</p>

		<table class="widefat striped">
			<thead><tr><th>Dátum</th><th>Név</th><th>E-mail</th><th>Cég</th><th>Weboldal</th><th>Pontszám</th><th></th></tr></thead>
			<tbody>
			<?php if ( ! $leads ) : ?>
				<tr><td colspan="7">Még nincs érdeklődő.</td></tr>
			<?php endif; ?>
			<?php foreach ( $leads as $lead ) : ?>
				<tr>
					<td><?php echo esc_html( mysql2date( 'Y-m-d H:i', $lead['date'] ) ); ?></td>
					<td><a href="<?php echo esc_url( add_query_arg( 'lead', $lead['id'], $page_url ) ); ?>"><strong><?php echo esc_html( $lead['name'] ); ?></strong></a></td>
					<td><a href="mailto:<?php echo esc_attr( $lead['email'] ); ?>"><?php echo esc_html( $lead['email'] ); ?></a></td>
					<td><?php echo esc_html( $lead['business'] ); ?></td>
					<td><a href="<?php echo esc_url( $lead['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_parse_url( $lead['url'], PHP_URL_HOST ) ); ?></a></td>
					<td><?php echo '' === $lead['overall'] ? '—' : (int) $lead['overall'] . '/100'; ?></td>
					<td><a href="<?php echo esc_url( add_query_arg( 'lead', $lead['id'], $page_url ) ); ?>">Riport</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function hpv_grader_render_lead( array $lead ) {
	$report = $lead['report'];
	?>
	<div class="wrap">
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . HPV_GRADER_PAGE ) ); ?>">← Vissza az érdeklődőkhöz</a></p>
		<h1><?php echo esc_html( $lead['name'] ); ?><?php echo $lead['business'] ? ' – ' . esc_html( $lead['business'] ) : ''; ?></h1>
		<p>
			<a href="mailto:<?php echo esc_attr( $lead['email'] ); ?>"><?php echo esc_html( $lead['email'] ); ?></a> ·
			<a href="<?php echo esc_url( $lead['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $lead['url'] ); ?></a> ·
			<?php echo esc_html( mysql2date( 'Y-m-d H:i', $lead['date'] ) ); ?>
		</p>

		<?php if ( ! $report ) : ?>
			<p>A riport nem érhető el.</p>
		<?php else : ?>
			<h2 style="font-size:2em"><?php echo (int) $report['overall']; ?>/100 – <?php echo esc_html( $report['grade'] ); ?></h2>
			<table class="widefat" style="max-width:520px">
				<?php foreach ( $report['categories'] as $cat ) : ?>
					<tr><td><?php echo esc_html( $cat['label'] ); ?></td><td style="text-align:right"><strong><?php echo null === $cat['score'] ? 'nem mért' : (int) $cat['score'] . '/100'; ?></strong></td></tr>
				<?php endforeach; ?>
			</table>

			<?php foreach ( HPV_GRADER_CATEGORIES as $key => $cat ) : ?>
				<h2><?php echo esc_html( $cat['label'] ); ?></h2>
				<table class="widefat striped">
					<?php foreach ( array_filter( $report['checks'], fn( $c ) => $c['category'] === $key ) as $check ) : ?>
						<?php list( $icon, $color ) = hpv_grader_status_label( $check['status'] ); ?>
						<tr>
							<td style="width:28px;color:<?php echo esc_attr( $color ); ?>;font-weight:bold"><?php echo esc_html( $icon ); ?></td>
							<td style="width:260px"><strong><?php echo esc_html( $check['title'] ); ?></strong></td>
							<td><?php echo esc_html( $check['detail'] ); ?><?php if ( $check['fix'] ) : ?><br><em><?php echo esc_html( $check['fix'] ); ?></em><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endforeach; ?>
		<?php endif; ?>

		<p style="margin-top:32px">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hpv_grader_delete&lead=' . $lead['id'] ), 'hpv_grader_delete_' . $lead['id'] ) ); ?>" class="button" onclick="return confirm('Biztosan törlöd ezt az érdeklődőt?');">Érdeklődő törlése</a>
		</p>
	</div>
	<?php
}

function hpv_grader_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_grader_export' );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=grader-leads-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // Excel UTF-8
	fputcsv( $out, array( 'date', 'name', 'email', 'business', 'url', 'score', 'speed', 'mobile', 'seo', 'schema', 'local' ) );
	foreach ( hpv_grader_lead_ids( 5000 ) as $id ) {
		$lead = hpv_grader_lead( $id );
		$row  = array( $lead['date'], $lead['name'], $lead['email'], $lead['business'], $lead['url'], $lead['overall'] );
		foreach ( array_keys( HPV_GRADER_CATEGORIES ) as $key ) {
			$row[] = $lead['report']['categories'][ $key ]['score'] ?? '';
		}
		// Excel képlet-injekció ellen: az =, +, -, @ kezdetű cellák elé aposztróf.
		fputcsv( $out, array_map( fn( $v ) => preg_match( '/^[=+\-@]/', (string) $v ) ? "'" . $v : $v, $row ) );
	}
	fclose( $out );
	exit;
}

function hpv_grader_delete_lead() {
	$id = absint( $_GET['lead'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_grader_delete_' . $id );

	if ( $id && HPV_GRADER_CPT === get_post_type( $id ) ) {
		wp_delete_post( $id, true );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=' . HPV_GRADER_PAGE . '&deleted=1' ) );
	exit;
}

function hpv_grader_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = hpv_grader_settings();
	$n = HPV_GRADER_OPTION;
	?>
	<div class="wrap">
		<h1>Website Grader beállítások</h1>
		<?php settings_errors(); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'hpv_grader' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="hpv-g-key">Google PageSpeed API kulcs</label></th>
					<td><input id="hpv-g-key" name="<?php echo esc_attr( $n ); ?>[psi_key]" value="<?php echo esc_attr( $s['psi_key'] ); ?>" class="regular-text" autocomplete="off">
					<p class="description">Ingyenes: <a href="https://developers.google.com/speed/docs/insights/v5/get-started#APIKey" target="_blank" rel="noopener">Google Cloud → „Get a Key”</a>. Kulcs nélkül a Google gyakran elutasítja a mérést (napi 25 000 mérés jár ingyen kulccsal).</p></td>
				</tr>
				<tr>
					<th><label for="hpv-g-notify">Értesítési e-mail</label></th>
					<td><input id="hpv-g-notify" type="email" name="<?php echo esc_attr( $n ); ?>[notify_email]" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="regular-text">
					<p class="description">Ide érkezik az értesítő minden új érdeklődőről, a hibák listájával.</p></td>
				</tr>
				<tr>
					<th><label for="hpv-g-from">Feladó neve</label></th>
					<td><input id="hpv-g-from" name="<?php echo esc_attr( $n ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="hpv-g-cta">Konzultáció gomb</label></th>
					<td><input id="hpv-g-cta" name="<?php echo esc_attr( $n ); ?>[cta_label]" value="<?php echo esc_attr( $s['cta_label'] ); ?>" class="regular-text" aria-label="Gomb szövege">
					<input type="url" name="<?php echo esc_attr( $n ); ?>[cta_url]" value="<?php echo esc_attr( $s['cta_url'] ); ?>" class="regular-text" aria-label="Gomb linkje">
					<p class="description">A riport alatt és az e-mailben megjelenő gomb szövege és linkje.</p></td>
				</tr>
				<tr>
					<th><label for="hpv-g-privacy">Adatvédelmi tájékoztató</label></th>
					<td><input id="hpv-g-privacy" type="url" name="<?php echo esc_attr( $n ); ?>[privacy_url]" value="<?php echo esc_attr( $s['privacy_url'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="hpv-g-limit">Korlát</label></th>
					<td><input id="hpv-g-limit" type="number" min="1" max="100" name="<?php echo esc_attr( $n ); ?>[rate_limit]" value="<?php echo (int) $s['rate_limit']; ?>" class="small-text"> elemzés óránként, látogatónként</td>
				</tr>
				<tr>
					<th>Megjelenés</th>
					<td>
						<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[color_scheme]" value="dark" <?php checked( 'dark', $s['color_scheme'] ); ?>> Sötét háttér</label>&nbsp;&nbsp;
						<label><input type="radio" name="<?php echo esc_attr( $n ); ?>[color_scheme]" value="light" <?php checked( 'light', $s['color_scheme'] ); ?>> Világos háttér</label>
						<p style="margin-top:8px"><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[theme_buttons]" value="1" <?php checked( $s['theme_buttons'] ); ?>> A téma gombstílusát használja (<code>btn-pill</code>)</label></p>
						<p class="description">Ha a gombok nem jól néznek ki, kapcsold ki: akkor saját lime gombot kapnak.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Mentés' ); ?>
		</form>
	</div>
	<?php
}
