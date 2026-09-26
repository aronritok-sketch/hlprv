<?php
/**
 * Belső CRM (WordPress admin, magyar felület).
 * Egy útválasztó oldal (page=hpv-crm): ügyféllista, ügyfél adatlap, szerkesztő (&action=edit&entity=…).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'hpv_p_admin_menu' );
add_action( 'admin_init', 'hpv_p_admin_register_settings' );
add_action( 'admin_enqueue_scripts', 'hpv_p_admin_assets' );
add_action( 'admin_post_hpv_crm_save', 'hpv_p_admin_save' );
add_action( 'admin_post_hpv_crm_delete', 'hpv_p_admin_delete' );
add_action( 'admin_post_hpv_crm_invite', 'hpv_p_admin_invite' );
add_action( 'admin_post_hpv_crm_revoke', 'hpv_p_admin_revoke' );

function hpv_p_admin_menu() {
	add_menu_page( 'CRM', 'CRM', 'hpv_manage_crm', 'hpv-crm', 'hpv_p_admin_page', 'dashicons-groups', 3 );
	add_submenu_page( 'hpv-crm', 'Ügyfelek', 'Ügyfelek', 'hpv_manage_crm', 'hpv-crm', 'hpv_p_admin_page' );
	$unread = hpv_chat_total_unread( get_current_user_id() );
	add_submenu_page( 'hpv-crm', 'Chat', 'Chat' . ( $unread ? ' <span class="awaiting-mod">' . (int) $unread . '</span>' : '' ), 'hpv_manage_crm', 'hpv-crm-chat', 'hpv_p_admin_chat_page' );
	add_submenu_page( 'hpv-crm', 'Projektek', 'Projektek', 'hpv_manage_crm', 'hpv-crm-projects', fn() => hpv_p_admin_list_page( 'project' ) );
	add_submenu_page( 'hpv-crm', 'Számlák', 'Számlák', 'hpv_invoices', 'hpv-crm-invoices', fn() => hpv_p_admin_list_page( 'invoice' ) );
	add_submenu_page( 'hpv-crm', 'Szerződések', 'Szerződések', 'hpv_contracts', 'hpv-crm-contracts', fn() => hpv_p_admin_list_page( 'contract' ) );
	add_submenu_page( 'hpv-crm', 'Szolgáltatás-katalógus', 'Szolgáltatások', 'hpv_invoices', 'hpv-crm-services', fn() => hpv_p_admin_list_page( 'service' ) );
	add_submenu_page( 'hpv-crm', 'CRM beállítások', 'Beállítások', 'manage_options', 'hpv-crm-settings', 'hpv_p_admin_settings_page' );
}

function hpv_p_admin_chat_page() {
	if ( ! hpv_p_is_staff() ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	$channel = absint( $_GET['channel'] ?? 0 );
	if ( ! empty( $_GET['client'] ) ) {
		$client_channel = hpv_chat_client_channel( absint( $_GET['client'] ), true );
		if ( $client_channel ) {
			hpv_chat_add_member( (int) $client_channel['id'], get_current_user_id() );
			$channel = (int) $client_channel['id'];
		}
	}
	hpv_chat_enqueue( 'hu', $channel );
	echo '<div class="wrap hpv-crm hpv-crm-chat"><div id="hpv-chat" class="hpv-chat hpv-chat--crm"></div></div>';
}

function hpv_p_admin_assets( $hook ) {
	if ( false === strpos( (string) $hook, 'hpv-crm' ) ) {
		return;
	}
	$base = plugins_url( 'assets/', HPV_PORTAL_FILE );
	wp_enqueue_style( 'hpv-crm', $base . 'admin.css', array(), HPV_PORTAL_VERSION );
	wp_enqueue_script( 'hpv-crm', $base . 'admin.js', array(), HPV_PORTAL_VERSION, true );
}

/* ─── Segédfüggvények ─────────────────────────────────────── */

function hpv_p_admin_url( array $args = array() ): string {
	return add_query_arg( $args, admin_url( 'admin.php?page=hpv-crm' ) );
}

function hpv_p_edit_url( string $entity, int $id = 0, array $extra = array() ): string {
	return hpv_p_admin_url( array_merge( array( 'action' => 'edit', 'entity' => $entity, 'id' => $id ?: null ), $extra ) );
}

function hpv_p_delete_url( string $entity, int $id ): string {
	return wp_nonce_url( admin_url( 'admin-post.php?action=hpv_crm_delete&entity=' . $entity . '&id=' . $id ), 'hpv_crm_delete_' . $entity . '_' . $id );
}

function hpv_p_badge( string $entity, string $field, string $value ): string {
	return '<span class="hpv-badge hpv-badge--' . esc_attr( $value ) . '">' . esc_html( hpv_p_option_label( $entity, $field, $value ) ) . '</span>';
}

function hpv_p_client_name( int $id ): string {
	static $cache = array();
	if ( ! isset( $cache[ $id ] ) ) {
		$client       = hpv_p_get( 'client', $id );
		$cache[ $id ] = $client ? $client['name'] : '—';
	}

	return $cache[ $id ];
}

function hpv_p_date( ?string $value ): string {
	return $value ? esc_html( mysql2date( 'Y. m. d.', $value ) ) : '—';
}

function hpv_p_admin_notice() {
	$messages = array(
		'saved'    => array( 'success', 'Mentve.' ),
		'sent'     => array( 'success', 'Mentve és kiküldve az ügyfélnek.' ),
		'paid'     => array( 'success', 'Fizetettnek jelölve.' ),
		'deleted'  => array( 'success', 'Törölve.' ),
		'voided'   => array( 'warning', 'Aláírt szerződést és kiküldött számlát nem lehet törölni — érvénytelenítve lett.' ),
		'invited'  => array( 'success', 'Portál meghívó elküldve.' ),
		'revoked'  => array( 'success', 'Portál hozzáférés visszavonva.' ),
		'required' => array( 'error', 'Hiányzó kötelező mező: ' . sanitize_text_field( wp_unslash( $_GET['fields'] ?? '' ) ) ),
		'locked'   => array( 'error', 'Aláírt szerződés szövege nem módosítható.' ),
		'error'    => array( 'error', sanitize_text_field( wp_unslash( $_GET['error'] ?? 'Hiba történt.' ) ) ),
		'daily_ok' => array( 'success', 'A Daily kapcsolat működik.' ),
		'voided_ok' => array( 'success', 'Érvénytelenítve.' ),
		'payment'  => array( 'success', 'Befizetés rögzítve.' ),
		'synced'   => array( 'success', 'Szinkronizálva.' ),
		'qbo_ok'   => array( 'success', 'QuickBooks összekapcsolva.' ),
		'conn_ok'  => array( 'success', 'A kapcsolat működik.' ),
		'webhook'  => array( 'success', 'A Daily webhook regisztrálva.' ),
	);
	$key      = sanitize_key( $_GET['hpv_msg'] ?? '' );
	if ( isset( $messages[ $key ] ) ) {
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
	}
}

function hpv_p_redirect( string $url, string $msg, array $extra = array() ) {
	wp_safe_redirect( add_query_arg( array_merge( array( 'hpv_msg' => $msg ), $extra ), $url ) );
	exit;
}

/* ─── Útválasztó ──────────────────────────────────────────── */

function hpv_p_admin_page() {
	if ( ! hpv_p_is_staff() ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	$action = sanitize_key( $_GET['action'] ?? '' );
	$entity = sanitize_key( $_GET['entity'] ?? '' );

	echo '<div class="wrap hpv-crm">';
	hpv_p_admin_notice();

	if ( 'edit' === $action && isset( hpv_p_entities()[ $entity ] ) ) {
		if ( ! hpv_p_can_entity( $entity ) ) {
			echo '<p>Ehhez nincs jogosultságod. Az adminisztrátor a CRM → Csapat oldalon adhat hozzáférést.</p></div>';
			return;
		}
		hpv_p_admin_edit( $entity, absint( $_GET['id'] ?? 0 ) );
	} elseif ( ! empty( $_GET['client'] ) ) {
		hpv_p_admin_client( absint( $_GET['client'] ) );
	} else {
		hpv_p_admin_clients();
	}

	echo '</div>';
}

/* ─── Ügyféllista és kulcsszámok ──────────────────────────── */

function hpv_p_admin_clients() {
	$search  = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$status  = sanitize_key( $_GET['status'] ?? '' );
	$where   = $status ? array( 'status' => $status ) : array();
	$clients = hpv_p_find( 'client', $where, array( 'search' => $search, 'orderby' => 'name', 'order' => 'ASC' ) );
	$s       = hpv_p_settings();
	$today   = current_time( 'Y-m-d' );

	$money   = hpv_p_can( 'invoices' );
	$open    = $money ? hpv_p_find( 'invoice', array( 'status' => 'sent' ), array( 'limit' => 2000 ) ) : array();
	$overdue = array_filter( $open, fn( $i ) => hpv_p_invoice_is_overdue( $i, $today ) );
	$mrr     = $money ? hpv_p_mrr_by_currency( hpv_p_find( 'subscription', array( 'status' => 'active' ), array( 'limit' => 2000 ) ) ) : array();
	$active  = count( hpv_p_find( 'client', array( 'status' => 'active' ), array( 'limit' => 2000 ) ) );
	$waiting = count( hpv_p_find( 'contract', array( 'status' => 'sent' ), array( 'limit' => 2000 ) ) );

	$balance_by_client = array();
	foreach ( $open as $invoice ) {
		$cur = hpv_p_invoice_currency( $invoice );
		$balance_by_client[ $invoice['client_id'] ][ $cur ] = ( $balance_by_client[ $invoice['client_id'] ][ $cur ] ?? 0 ) + hpv_p_invoice_balance( $invoice );
	}
	?>
	<h1 class="wp-heading-inline">Ügyfelek</h1>
	<a href="<?php echo esc_url( hpv_p_edit_url( 'client' ) ); ?>" class="page-title-action">Új ügyfél</a>
	<a href="<?php echo esc_url( hpv_p_scheme() . '://' . hpv_p_crm_host() . '/' ); ?>" class="page-title-action">Projektek és chat (új CRM) →</a>
	<hr class="wp-header-end">

	<div class="hpv-kpis">
		<div class="hpv-kpi"><span>Aktív ügyfelek</span><strong><?php echo (int) $active; ?></strong></div>
		<?php if ( $money ) : ?>
			<div class="hpv-kpi"><span>Havi ismétlődő bevétel</span><strong><?php echo esc_html( hpv_p_money_multi( $mrr, $s['currency'] ) ); ?></strong></div>
			<div class="hpv-kpi"><span>Kintlévőség</span><strong><?php echo esc_html( hpv_p_money_multi( hpv_p_outstanding_by_currency( $open ), $s['currency'] ) ); ?></strong></div>
			<div class="hpv-kpi<?php echo $overdue ? ' is-alert' : ''; ?>"><span>Lejárt számla</span><strong><?php echo count( $overdue ); ?></strong></div>
		<?php endif; ?>
		<?php if ( hpv_p_can( 'contracts' ) ) : ?>
			<div class="hpv-kpi"><span>Aláírásra vár</span><strong><?php echo (int) $waiting; ?></strong></div>
		<?php endif; ?>
	</div>

	<form method="get" class="hpv-filter">
		<input type="hidden" name="page" value="hpv-crm">
		<select name="status">
			<option value="">Minden státusz</option>
			<?php foreach ( hpv_p_entity( 'client' )['fields']['status']['options'] as $value => $labels ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $labels[0] ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Keresés név, e-mail…">
		<button class="button">Szűrés</button>
	</form>

	<table class="widefat striped hpv-table">
		<thead><tr><th>Cégnév</th><th>Ország</th><th>Kapcsolattartó</th><th>E-mail</th><th>Státusz</th><?php echo $money ? '<th>Nyitott számlák</th>' : ''; ?></tr></thead>
		<tbody>
		<?php if ( ! $clients ) : ?>
			<tr><td colspan="6">Nincs ügyfél. <a href="<?php echo esc_url( hpv_p_edit_url( 'client' ) ); ?>">Az első ügyfél felvétele →</a></td></tr>
		<?php endif; ?>
		<?php foreach ( $clients as $c ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( hpv_p_admin_url( array( 'client' => $c['id'] ) ) ); ?>"><strong><?php echo esc_html( $c['name'] ); ?></strong></a></td>
				<td><?php echo 'HU' === $c['country'] ? 'HU' : 'US'; ?></td>
				<td><?php echo esc_html( $c['contact_name'] ); ?></td>
				<td><?php echo esc_html( $c['email'] ); ?></td>
				<td><?php echo hpv_p_badge( 'client', 'status', $c['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				<?php if ( $money ) : ?>
					<td><?php echo isset( $balance_by_client[ $c['id'] ] ) ? esc_html( hpv_p_money_multi( $balance_by_client[ $c['id'] ] ) ) : '—'; ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/* ─── Ügyfél adatlap ──────────────────────────────────────── */

function hpv_p_admin_client( int $id ) {
	$client = hpv_p_get( 'client', $id );
	if ( ! $client ) {
		echo '<p>Az ügyfél nem található.</p>';
		return;
	}
	$s        = hpv_p_settings();
	$today    = current_time( 'Y-m-d' );
	$subs     = hpv_p_find( 'subscription', array( 'client_id' => $id ) );
	$projects = hpv_p_find( 'project', array( 'client_id' => $id ) );
	$invoices = hpv_p_find( 'invoice', array( 'client_id' => $id ) );
	$contracts = hpv_p_find( 'contract', array( 'client_id' => $id ) );
	$activity = hpv_p_find( 'activity', array( 'client_id' => $id ), array( 'limit' => 50 ) );
	$users    = hpv_p_client_users( $id );
	$money    = hpv_p_can( 'invoices' );
	$currency = hpv_p_client_currency( $id );
	$new      = fn( $entity ) => hpv_p_edit_url( $entity, 0, array( 'client_id' => $id ) );
	?>
	<p><a href="<?php echo esc_url( hpv_p_admin_url() ); ?>">← Ügyfelek</a></p>
	<div class="hpv-client-head">
		<div>
			<h1><?php echo esc_html( $client['name'] ); ?> <?php echo hpv_p_badge( 'client', 'status', $client['status'] ); // phpcs:ignore ?></h1>
			<p class="hpv-muted">
				<?php echo 'HU' === $client['country'] ? '🇭🇺 Magyarország · Számlázz.hu + Teya · ' : '🇺🇸 USA · QuickBooks + Stripe · '; ?>
				<?php echo esc_html( implode( ' · ', array_filter( array( $client['contact_name'], $client['email'], $client['phone'] ) ) ) ); ?>
				<?php if ( $client['website'] ) : ?> · <a href="<?php echo esc_url( $client['website'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $client['website'], PHP_URL_HOST ) ); ?></a><?php endif; ?>
			</p>
		</div>
		<div class="hpv-actions">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=hpv-crm-chat&client=' . $id ) ); ?>">Chat</a>
			<a class="button" href="<?php echo esc_url( hpv_p_portal_url( array( 'preview_client' => $id ) ) ); ?>" target="_blank">Portál előnézet</a>
			<a class="button button-primary" href="<?php echo esc_url( hpv_p_edit_url( 'client', $id ) ); ?>">Adatok szerkesztése</a>
		</div>
	</div>

	<div class="hpv-kpis">
		<?php if ( $money ) : ?>
			<div class="hpv-kpi"><span>Nyitott egyenleg</span><strong><?php echo esc_html( hpv_p_money_multi( hpv_p_outstanding_by_currency( $invoices ), $currency ) ); ?></strong></div>
			<div class="hpv-kpi"><span>Havi díj (MRR)</span><strong><?php echo esc_html( hpv_p_money( hpv_p_mrr( $subs ), $currency ) ); ?></strong></div>
		<?php endif; ?>
		<div class="hpv-kpi"><span>Aktív projektek</span><strong><?php echo count( array_filter( $projects, fn( $p ) => in_array( $p['status'], array( 'planning', 'in_progress', 'review' ), true ) ) ); ?></strong></div>
		<div class="hpv-kpi"><span>Portál felhasználók</span><strong><?php echo count( $users ); ?></strong></div>
	</div>

	<?php if ( $client['notes'] ) : ?>
		<div class="hpv-card hpv-note"><strong>Belső megjegyzés:</strong> <?php echo nl2br( esc_html( $client['notes'] ) ); ?></div>
	<?php endif; ?>

	<div class="hpv-grid">
		<div class="hpv-col">
			<?php if ( $money ) : ?>
			<section class="hpv-card">
				<header><h2>Szolgáltatások</h2><a class="button button-small" href="<?php echo esc_url( $new( 'subscription' ) ); ?>">+ Szolgáltatás</a></header>
				<?php hpv_p_admin_table( 'subscription', $subs ); ?>
			</section>
			<?php endif; ?>

			<section class="hpv-card">
				<header><h2>Projektek</h2><a class="button button-small" href="<?php echo esc_url( $new( 'project' ) ); ?>">+ Projekt</a></header>
				<?php hpv_p_admin_table( 'project', $projects, array( 'progress' => true ) ); ?>
			</section>

			<?php if ( $money ) : ?>
			<section class="hpv-card">
				<header><h2>Számlák</h2><a class="button button-small" href="<?php echo esc_url( $new( 'invoice' ) ); ?>">+ Számla</a></header>
				<?php hpv_p_admin_table( 'invoice', $invoices, array( 'today' => $today ) ); ?>
			</section>
			<?php endif; ?>

			<?php if ( hpv_p_can( 'contracts' ) ) : ?>
			<section class="hpv-card">
				<header><h2>Szerződések</h2><a class="button button-small" href="<?php echo esc_url( $new( 'contract' ) ); ?>">+ Szerződés</a></header>
				<?php hpv_p_admin_table( 'contract', $contracts ); ?>
			</section>
			<?php endif; ?>
		</div>

		<div class="hpv-col hpv-col--side">
			<section class="hpv-card">
				<header><h2>Portál hozzáférés</h2></header>
				<?php if ( ! $users ) : ?>
					<p class="hpv-muted">Még senki nem fér hozzá a portálhoz.</p>
				<?php endif; ?>
				<ul class="hpv-users">
					<?php foreach ( $users as $u ) : ?>
						<li>
							<span><strong><?php echo esc_html( $u->display_name ); ?></strong><br><span class="hpv-muted"><?php echo esc_html( $u->user_email ); ?></span></span>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hpv_crm_revoke&client=' . $id . '&user=' . $u->ID ), 'hpv_crm_revoke_' . $u->ID ) ); ?>" onclick="return confirm('Visszavonod a hozzáférést?');">Visszavonás</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hpv-inline-form">
					<input type="hidden" name="action" value="hpv_crm_invite">
					<input type="hidden" name="client_id" value="<?php echo (int) $id; ?>">
					<?php wp_nonce_field( 'hpv_crm_invite_' . $id ); ?>
					<input type="text" name="name" placeholder="Név" required>
					<input type="email" name="email" placeholder="E-mail" required value="<?php echo $users ? '' : esc_attr( $client['email'] ); ?>">
					<button class="button">Meghívó küldése</button>
				</form>
			</section>

			<section class="hpv-card">
				<header><h2>Tevékenység</h2></header>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hpv-activity-form">
					<input type="hidden" name="action" value="hpv_crm_save">
					<input type="hidden" name="entity" value="activity">
					<input type="hidden" name="data[client_id]" value="<?php echo (int) $id; ?>">
					<?php wp_nonce_field( 'hpv_crm_save_activity' ); ?>
					<input type="hidden" name="data[type]" value="note">
					<textarea name="data[body]" rows="3" placeholder="Belső jegyzet (az ügyfél nem látja)…" required></textarea>
					<div class="hpv-activity-form__row">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=hpv-crm-chat&client=' . $id ) ); ?>">Üzenet az ügyfélnek a chatben →</a>
						<button class="button button-primary">Jegyzet hozzáadása</button>
					</div>
				</form>
				<ol class="hpv-timeline">
					<?php foreach ( $activity as $a ) : ?>
						<?php $author = $a['user_id'] ? get_userdata( (int) $a['user_id'] ) : null; ?>
						<li class="is-<?php echo esc_attr( $a['type'] ); ?><?php echo $a['visible'] ? '' : ' is-internal'; ?>">
							<div class="hpv-timeline__meta">
								<?php echo esc_html( hpv_p_option_label( 'activity', 'type', $a['type'] ) ); ?>
								<?php echo $author ? ' · ' . esc_html( $author->display_name ) : ''; ?>
								· <?php echo esc_html( get_date_from_gmt( $a['created_at'], 'Y. m. d. H:i' ) ); ?>
								<?php echo $a['visible'] ? '' : ' · <em>belső</em>'; ?>
							</div>
							<div><?php echo nl2br( esc_html( $a['body'] ) ); ?></div>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		</div>
	</div>
	<?php
}

/**
 * Lista táblázat a séma „list” mezőiből.
 */
function hpv_p_admin_table( string $entity, array $rows, array $opts = array() ) {
	$def     = hpv_p_entity( $entity );
	$columns = array_filter( $def['fields'], fn( $f ) => ! empty( $f['list'] ) );
	$s       = hpv_p_settings();

	if ( ! $rows ) {
		echo '<p class="hpv-muted">Még nincs ' . esc_html( mb_strtolower( $def['singular'] ) ) . '.</p>';
		return;
	}

	echo '<table class="widefat striped hpv-table"><thead><tr>';
	if ( ! empty( $opts['client_col'] ) ) {
		echo '<th>Ügyfél</th>';
	}
	foreach ( $columns as $field ) {
		echo '<th>' . esc_html( preg_replace( '/ \(.*\)$/', '', $field['label'] ) ) . '</th>';
	}
	if ( ! empty( $opts['progress'] ) ) {
		echo '<th>Haladás</th>';
	}
	echo '<th></th></tr></thead><tbody>';

	foreach ( $rows as $row ) {
		echo '<tr>';
		if ( ! empty( $opts['client_col'] ) ) {
			echo '<td><a href="' . esc_url( hpv_p_admin_url( array( 'client' => $row['client_id'] ) ) ) . '">' . esc_html( hpv_p_client_name( (int) $row['client_id'] ) ) . '</a></td>';
		}
		$first = true;
		foreach ( $columns as $key => $field ) {
			$value = (string) ( $row[ $key ] ?? '' );
			switch ( $field['type'] ) {
				case 'select':
					$out = hpv_p_badge( $entity, $key, $value );
					if ( 'invoice' === $entity && 'status' === $key && hpv_p_invoice_is_overdue( $row, $opts['today'] ?? current_time( 'Y-m-d' ) ) ) {
						$out .= ' <span class="hpv-badge hpv-badge--overdue">Lejárt</span>';
					}
					break;
				case 'money':
					$out = esc_html( hpv_p_money( $value, hpv_p_row_currency( $entity, $row, $s['currency'] ) ) );
					break;
				case 'date':
				case 'datetime':
					$out = hpv_p_date( $value );
					break;
				case 'bool':
					$out = $value ? 'Igen' : 'Nem';
					break;
				default:
					$out = esc_html( '' === $value ? '—' : $value );
			}
			if ( $first ) {
				$out   = '<a href="' . esc_url( hpv_p_edit_url( $entity, (int) $row['id'] ) ) . '"><strong>' . $out . '</strong></a>';
				$first = false;
			}
			echo '<td>' . $out . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fent escapelve
		}
		if ( ! empty( $opts['progress'] ) ) {
			$progress = hpv_p_project_progress( hpv_p_find( 'task', array( 'project_id' => $row['id'] ) ) );
			echo '<td><span class="hpv-progress"><span style="width:' . (int) $progress . '%"></span></span> ' . (int) $progress . '%</td>';
		}
		echo '<td class="hpv-row-actions"><a href="' . esc_url( hpv_p_edit_url( $entity, (int) $row['id'] ) ) . '">Szerkesztés</a></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

/* ─── Listák (összes ügyfélre) ────────────────────────────── */

function hpv_p_admin_list_page( string $entity ) {
	if ( ! hpv_p_can_entity( $entity ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	$def    = hpv_p_entity( $entity );
	$status = sanitize_key( $_GET['status'] ?? '' );
	$where  = $status && isset( $def['fields']['status'] ) ? array( 'status' => $status ) : array();
	$rows   = hpv_p_find( $entity, $where, array( 'search' => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ) );
	?>
	<div class="wrap hpv-crm">
		<?php hpv_p_admin_notice(); ?>
		<h1 class="wp-heading-inline"><?php echo esc_html( $def['label'] ); ?></h1>
		<?php if ( 'service' === $entity ) : ?>
			<a href="<?php echo esc_url( hpv_p_edit_url( 'service' ) ); ?>" class="page-title-action">Új szolgáltatás</a>
		<?php endif; ?>
		<hr class="wp-header-end">
		<?php if ( isset( $def['fields']['status'] ) ) : ?>
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( remove_query_arg( 'status' ) ); ?>" <?php echo $status ? '' : 'class="current"'; ?>>Összes</a></li>
				<?php foreach ( $def['fields']['status']['options'] as $value => $labels ) : ?>
					<li> | <a href="<?php echo esc_url( add_query_arg( 'status', $value ) ); ?>" <?php echo $status === $value ? 'class="current"' : ''; ?>><?php echo esc_html( preg_replace( '/ \(.*\)$/', '', $labels[0] ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<br class="clear">
		<?php endif; ?>
		<?php if ( 'service' !== $entity ) : ?>
			<p class="hpv-muted">Új <?php echo esc_html( mb_strtolower( $def['singular'] ) ); ?> az ügyfél adatlapján vehető fel.</p>
		<?php endif; ?>
		<?php hpv_p_admin_table( $entity, $rows, array( 'client_col' => isset( $def['fields']['client_id'] ), 'progress' => 'project' === $entity ) ); ?>
	</div>
	<?php
}

/* ─── Szerkesztő ──────────────────────────────────────────── */

function hpv_p_ref_options( string $ref, array $row ): array {
	switch ( $ref ) {
		case 'client':
			$rows = hpv_p_find( 'client', array(), array( 'orderby' => 'name', 'order' => 'ASC', 'limit' => 2000 ) );
			return array_column( $rows, 'name', 'id' );
		case 'service':
			$rows = hpv_p_find( 'service', array( 'active' => 1 ), array( 'orderby' => 'name', 'order' => 'ASC' ) );
			$out  = array();
			foreach ( $rows as $r ) {
				$out[ $r['id'] ] = $r['name'] . ' — ' . hpv_p_money( $r['price'] ) . ' / ' . hpv_p_option_label( 'service', 'billing', $r['billing'] );
			}
			return $out;
		case 'project':
			$where = ! empty( $row['client_id'] ) ? array( 'client_id' => $row['client_id'] ) : array();
			return array_column( hpv_p_find( 'project', $where ), 'name', 'id' );
	}

	return array();
}

function hpv_p_admin_field( string $key, array $field, $value, array $row ) {
	$name = 'data[' . $key . ']';
	$id   = 'hpv-f-' . $key;

	if ( ! empty( $field['readonly'] ) ) {
		$shown = (string) $value;
		if ( 'money' === $field['type'] ) {
			$shown = hpv_p_money( $value, hpv_p_row_currency( (string) ( $row['_entity'] ?? '' ), $row, 'USD' ) );
		} elseif ( in_array( $field['type'], array( 'date', 'datetime' ), true ) ) {
			$shown = $value ? get_date_from_gmt( (string) $value, 'Y. m. d. H:i' ) . ' (helyi idő)' : '';
		}
		echo '<span class="hpv-readonly">' . esc_html( '' === $shown ? '—' : $shown ) . '</span>';
		return;
	}

	switch ( $field['type'] ) {
		case 'textarea':
			printf( '<textarea id="%s" name="%s" rows="4" class="large-text">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( (string) $value ) );
			break;
		case 'html':
			wp_editor(
				(string) $value,
				'hpvf' . $key,
				array(
					'textarea_name' => $name,
					'textarea_rows' => 20,
					'media_buttons' => false,
				)
			);
			break;
		case 'select':
			printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
			foreach ( $field['options'] as $opt => $labels ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $opt ), selected( (string) $value, (string) $opt, false ), esc_html( $labels[0] ) );
			}
			echo '</select>';
			break;
		case 'ref':
			printf( '<select id="%s" name="%s"><option value="0">—</option>', esc_attr( $id ), esc_attr( $name ) );
			foreach ( hpv_p_ref_options( $field['ref'], $row ) as $opt => $label ) {
				printf( '<option value="%d" %s>%s</option>', (int) $opt, selected( (int) $value, (int) $opt, false ), esc_html( $label ) );
			}
			echo '</select>';
			break;
		case 'bool':
			printf( '<input type="hidden" name="%s" value="0"><label><input type="checkbox" id="%s" name="%s" value="1" %s> Igen</label>', esc_attr( $name ), esc_attr( $id ), esc_attr( $name ), checked( (int) $value, 1, false ) );
			break;
		case 'money':
		case 'decimal':
			printf( '<input type="number" step="0.01" id="%s" name="%s" value="%s" class="small-text hpv-num">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
			break;
		case 'int':
			printf( '<input type="number" step="1" id="%s" name="%s" value="%s" class="small-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
			break;
		case 'date':
			printf( '<input type="date" id="%s" name="%s" value="%s">', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ) );
			break;
		default:
			$type = array(
				'email' => 'email',
				'url'   => 'url',
				'tel'   => 'tel',
			)[ $field['type'] ] ?? 'text';
			printf( '<input type="%s" id="%s" name="%s" value="%s" class="regular-text"%s>', esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $value ), ! empty( $field['required'] ) ? ' required' : '' );
	}

	if ( ! empty( $field['help'] ) ) {
		echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
	}
}

function hpv_p_admin_edit( string $entity, int $id ) {
	$def = hpv_p_entity( $entity );
	$row = $id ? hpv_p_get( $entity, $id ) : null;
	if ( $id && ! $row ) {
		echo '<p>Nem található.</p>';
		return;
	}

	// Új rekord alapértékei (az ügyfél adatlapról érkezve az ügyfél előre kitöltve).
	if ( ! $row ) {
		$row = hpv_p_defaults( $entity );
		foreach ( array( 'client_id', 'project_id' ) as $fk ) {
			if ( isset( $def['fields'][ $fk ] ) && ! empty( $_GET[ $fk ] ) ) {
				$row[ $fk ] = absint( $_GET[ $fk ] );
			}
		}
		if ( 'invoice' === $entity ) {
			$row['issue_date'] = current_time( 'Y-m-d' );
			$row['due_date']   = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . (int) hpv_p_settings()['payment_terms'] . ' days' ) );
		}
		if ( 'subscription' === $entity ) {
			$row['start_date'] = current_time( 'Y-m-d' );
		}
	}

	$back      = ! empty( $row['client_id'] ) ? hpv_p_admin_url( array( 'client' => $row['client_id'] ) ) : hpv_p_admin_url();
	$locked    = 'contract' === $entity && 'signed' === ( $row['status'] ?? '' );
	$row['_entity'] = $entity;
	$is_hu     = 'invoice' === $entity && ! empty( $row['client_id'] ) && 'HU' === hpv_p_client_country( (int) $row['client_id'] );
	$hu_issued = $is_hu && '' !== (string) ( $row['external_id'] ?? '' );
	?>
	<p><a href="<?php echo esc_url( $back ); ?>">← Vissza<?php echo ! empty( $row['client_id'] ) ? ': ' . esc_html( hpv_p_client_name( (int) $row['client_id'] ) ) : ''; ?></a></p>
	<h1><?php echo esc_html( ( $id ? '' : 'Új ' ) . ( $id ? $def['singular'] : mb_strtolower( $def['singular'] ) ) ); ?><?php echo 'invoice' === $entity && ! empty( $row['number'] ) ? ' ' . esc_html( $row['number'] ) : ''; ?></h1>

	<?php if ( $locked ) : ?>
		<?php hpv_p_admin_signed_contract( $row ); ?>
		<?php return; ?>
	<?php endif; ?>
	<?php if ( $hu_issued ) : ?>
		<?php hpv_p_admin_invoice_panel( $row ); ?>
		<?php return; ?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hpv-form">
		<input type="hidden" name="action" value="hpv_crm_save">
		<input type="hidden" name="entity" value="<?php echo esc_attr( $entity ); ?>">
		<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
		<?php wp_nonce_field( 'hpv_crm_save_' . $entity ); ?>

		<table class="form-table" role="presentation">
			<?php foreach ( $def['fields'] as $key => $field ) : ?>
				<?php
				if ( 'invoice_item' === $entity || ( 'activity' === $entity && 'user_id' === $key ) || ! empty( $field['hidden'] ) ) {
					continue;
				}
				if ( 'invoice' === $entity && ( in_array( $key, array( 'status', 'paid_amount', 'external_id', 'sync_status', 'sync_error', 'pdf_file' ), true ) || ( $is_hu ? 'tax_rate' : 'vat_key' ) === $key ) ) {
					continue; // lent, a fizetés és szinkron panelen / csak az egyik országban értelmes
				}
				if ( 'client' === $entity && 'external_customer_id' === $key && empty( $row[ $key ] ) ) {
					continue;
				}
				if ( ! empty( $field['readonly'] ) && ! $id ) {
					continue;
				}
				if ( 'invoice' === $entity && in_array( $key, array( 'subtotal', 'tax', 'total' ), true ) ) {
					continue; // a tételek alatt látszik
				}
				if ( 'contract' === $entity && 0 === strpos( $key, 'signer_' ) || ( 'contract' === $entity && 'body_hash' === $key ) ) {
					continue;
				}
				?>
				<tr>
					<th><label for="hpv-f-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?><?php echo ! empty( $field['required'] ) ? ' *' : ''; ?></label></th>
					<td><?php hpv_p_admin_field( $key, $field, $row[ $key ] ?? '', $row ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>

		<?php if ( 'invoice' === $entity ) : ?>
			<?php hpv_p_admin_invoice_items( $id, $row ); ?>
		<?php endif; ?>

		<p class="submit hpv-submit">
			<button class="button button-primary" name="do" value="save">Mentés</button>
			<?php if ( 'invoice' === $entity && $is_hu && 'draft' === ( $row['status'] ?? 'draft' ) ) : ?>
				<button class="button" name="do" value="send" onclick="return confirm('A számlát a Számlázz.hu kiállítja, jelenti a NAV-nak és e-mailben elküldi az ügyfélnek. Utána már nem módosítható, csak sztornózható. Mehet?');">Mentés és kiállítás (Számlázz.hu)</button>
			<?php elseif ( 'invoice' === $entity && in_array( $row['status'] ?? 'draft', array( 'draft', 'sent' ), true ) ) : ?>
				<button class="button" name="do" value="send" onclick="return confirm('Mented és elküldöd az ügyfélnek?');"><?php echo 'sent' === ( $row['status'] ?? '' ) ? 'Mentés és újraküldés' : 'Mentés és kiküldés az ügyfélnek'; ?></button>
			<?php endif; ?>
			<?php if ( 'contract' === $entity && in_array( $row['status'] ?? 'draft', array( 'draft', 'sent' ), true ) ) : ?>
				<button class="button" name="do" value="send" onclick="return confirm('Mented és elküldöd aláírásra?');"><?php echo 'sent' === ( $row['status'] ?? '' ) ? 'Mentés és emlékeztető küldése' : 'Mentés és kiküldés aláírásra'; ?></button>
			<?php endif; ?>
			<?php if ( $id ) : ?>
				<a class="hpv-delete" href="<?php echo esc_url( hpv_p_delete_url( $entity, $id ) ); ?>" onclick="return confirm('Biztosan törlöd? A kapcsolódó adatok is törlődnek.');">Törlés</a>
			<?php endif; ?>
		</p>
	</form>

	<?php if ( 'invoice' === $entity && $id && 'draft' !== $row['status'] ) : ?>
		<?php hpv_p_admin_invoice_panel( $row ); ?>
	<?php endif; ?>

	<?php if ( 'project' === $entity && $id ) : ?>
		<?php hpv_p_admin_project_tasks( $id ); ?>
	<?php endif; ?>
	<?php
}

/**
 * Pénznem egy sorhoz (számla: a sajátja; ügyfélhez tartozó: az ügyfél országa szerint).
 */
function hpv_p_row_currency( string $entity, array $row, string $fallback ): string {
	if ( 'invoice' === $entity && ! empty( $row['client_id'] ) ) {
		return hpv_p_invoice_currency( $row );
	}
	if ( 'invoice_item' === $entity && ! empty( $row['invoice_id'] ) ) {
		$invoice = hpv_p_get( 'invoice', (int) $row['invoice_id'] );
		return $invoice ? hpv_p_invoice_currency( $invoice ) : $fallback;
	}
	if ( ! empty( $row['client_id'] ) ) {
		return hpv_p_client_currency( (int) $row['client_id'] );
	}

	return $fallback;
}

/**
 * Kiküldött számla: befizetések, szinkron (QuickBooks / Számlázz.hu), PDF, befizetés rögzítése, érvénytelenítés.
 * Kiállított magyar számlánál ez a teljes nézet (a számla már nem módosítható).
 */
function hpv_p_admin_invoice_panel( array $invoice ) {
	$id       = (int) $invoice['id'];
	$is_hu    = hpv_p_is_hu_invoice( $invoice );
	$currency = hpv_p_invoice_currency( $invoice );
	$payments = hpv_p_find( 'payment', array( 'invoice_id' => $id ), array( 'orderby' => 'id', 'order' => 'ASC' ) );
	$items    = hpv_p_find( 'invoice_item', array( 'invoice_id' => $id ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
	$post     = esc_url( admin_url( 'admin-post.php' ) );
	$hidden   = function ( string $do ) use ( $id ) {
		echo '<input type="hidden" name="action" value="hpv_crm_save"><input type="hidden" name="entity" value="invoice"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'hpv_crm_save_invoice' );
	};
	?>
	<div class="hpv-invoice-panel">
		<?php if ( hpv_p_invoice_locked( $invoice ) ) : ?>
			<p class="hpv-muted">Kiállítva a Számlázz.hu-ban (<?php echo esc_html( hpv_p_client_name( (int) $invoice['client_id'] ) ); ?>): a számla jogilag nem módosítható. Javításhoz sztornózd, és állíts ki újat.</p>
			<table class="widefat striped hpv-table">
				<thead><tr><th>Tétel</th><th>Mennyiség</th><th>Egységár</th><th>Összeg</th></tr></thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<tr><td><?php echo esc_html( $item['description'] ); ?></td><td><?php echo esc_html( rtrim( rtrim( $item['quantity'], '0' ), '.' ) ); ?></td><td><?php echo esc_html( hpv_p_money( $item['unit_price'], $currency ) ); ?></td><td><?php echo esc_html( hpv_p_money( $item['amount'], $currency ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr><td colspan="3" class="hpv-right">Nettó</td><td><?php echo esc_html( hpv_p_money( $invoice['subtotal'], $currency ) ); ?></td></tr>
					<tr><td colspan="3" class="hpv-right">ÁFA (<?php echo esc_html( hpv_p_hu_vat_key( $invoice ) ); ?>)</td><td><?php echo esc_html( hpv_p_money( $invoice['tax'], $currency ) ); ?></td></tr>
					<tr class="hpv-total"><td colspan="3" class="hpv-right">Bruttó</td><td><?php echo esc_html( hpv_p_money( $invoice['total'], $currency ) ); ?></td></tr>
				</tfoot>
			</table>
		<?php endif; ?>

		<div class="hpv-grid">
			<section class="hpv-card">
				<header><h2>Befizetések</h2><strong><?php echo esc_html( hpv_p_money( $invoice['paid_amount'], $currency ) . ' / ' . hpv_p_money( $invoice['total'], $currency ) ); ?></strong></header>
				<?php if ( ! $payments ) : ?>
					<p class="hpv-muted">Még nincs befizetés.<?php echo ( ! $is_hu && hpv_stripe_enabled() ) ? ' Az ügyfél a portálon Stripe-pal fizethet; a befizetés magától megjelenik itt.' : ''; ?></p>
				<?php else : ?>
					<ul class="hpv-users">
						<?php foreach ( $payments as $p ) : ?>
							<li><span><strong><?php echo esc_html( hpv_p_money( $p['amount'], $currency ) ); ?></strong> · <?php echo esc_html( hpv_p_option_label( 'payment', 'provider', $p['provider'] ) ); ?> · <?php echo esc_html( (string) $p['paid_on'] ); ?><br><span class="hpv-muted"><?php echo esc_html( trim( $p['reference'] . ' ' . $p['note'] ) ); ?><?php echo $p['external_ref'] ? ' · könyvelve' : ( '' !== (string) $invoice['external_id'] ? ' · még nincs könyvelve' : '' ); ?></span></span></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( 'sent' === $invoice['status'] ) : ?>
					<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="hpv-inline-form">
						<?php $hidden( 'paid' ); ?>
						<input type="number" step="0.01" min="0.01" name="amount" value="<?php echo esc_attr( hpv_p_cents_to_decimal( hpv_p_invoice_balance( $invoice ) ) ); ?>" aria-label="Összeg">
						<input type="date" name="paid_on" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" aria-label="Dátum">
						<input type="text" name="note" placeholder="Megjegyzés (pl. átutalás)">
						<button class="button">Befizetés rögzítése</button>
					</form>
				<?php endif; ?>
			</section>

			<section class="hpv-card">
				<header><h2><?php echo $is_hu ? 'Számlázz.hu' : 'QuickBooks'; ?></h2></header>
				<?php if ( 'error' === $invoice['sync_status'] ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( (string) $invoice['sync_error'] ); ?></p></div>
				<?php endif; ?>
				<?php if ( $is_hu ) : ?>
					<p><?php echo $invoice['external_id'] ? 'Számlaszám: <strong>' . esc_html( $invoice['external_id'] ) . '</strong>' : 'Még nincs kiállítva.'; ?></p>
					<?php if ( $invoice['pdf_file'] ) : ?><p><a class="button" href="<?php echo esc_url( hpv_p_invoice_pdf_url( $invoice ) ); ?>" target="_blank">Számla PDF</a></p><?php endif; ?>
					<?php if ( 'sent' === $invoice['status'] ) : ?>
						<form method="post" action="<?php echo $post; // phpcs:ignore ?>" class="hpv-inline-form">
							<?php $hidden( 'link' ); ?>
							<input type="url" name="payment_url" value="<?php echo esc_attr( (string) $invoice['payment_url'] ); ?>" placeholder="Teya fizetési link (https://…)" style="min-width:260px">
							<button class="button">Link mentése</button>
						</form>
						<p class="description">A Teya appban készült link. A portálon „Pay now” gombként jelenik meg; a befizetést a fenti „Befizetés rögzítése” gombbal kell jelölni, amíg a Teya API nincs bekötve.</p>
					<?php endif; ?>
				<?php elseif ( ! hpv_qbo_connected() ) : ?>
					<p class="hpv-muted">A QuickBooks nincs összekapcsolva (Beállítások).</p>
				<?php else : ?>
					<p><?php echo $invoice['external_id'] ? 'Átküldve (QuickBooks azonosító: ' . esc_html( $invoice['external_id'] ) . ').' : 'Még nincs átküldve.'; ?></p>
				<?php endif; ?>
				<?php if ( 'error' === $invoice['sync_status'] || ( ! $is_hu && hpv_qbo_connected() && ! $invoice['external_id'] ) ) : ?>
					<form method="post" action="<?php echo $post; // phpcs:ignore ?>"><?php $hidden( 'sync' ); ?><button class="button">Újrapróbálás</button></form>
				<?php endif; ?>
				<?php if ( in_array( $invoice['status'], array( 'sent', 'paid' ), true ) ) : ?>
					<form method="post" action="<?php echo $post; // phpcs:ignore ?>" onsubmit="return confirm('<?php echo $is_hu && $invoice['external_id'] ? 'Sztornó számlát állítasz ki a Számlázz.hu-ban. Mehet?' : 'Érvényteleníted a számlát?'; ?>');" style="margin-top:12px">
						<?php $hidden( 'void' ); ?>
						<button class="button-link hpv-delete"><?php echo $is_hu && $invoice['external_id'] ? 'Sztornózás' : 'Érvénytelenítés'; ?></button>
					</form>
				<?php endif; ?>
			</section>
		</div>
	</div>
	<?php
}

function hpv_p_admin_invoice_items( int $invoice_id, array $invoice ) {
	$items = $invoice_id ? hpv_p_find( 'invoice_item', array( 'invoice_id' => $invoice_id ), array( 'orderby' => 'sort', 'order' => 'ASC' ) ) : array();
	if ( ! $items ) {
		$items = array(
			array(
				'description' => '',
				'quantity'    => '1',
				'unit_price'  => '',
			),
		);
	}
	$services = hpv_p_find( 'service', array( 'active' => 1 ), array( 'orderby' => 'name', 'order' => 'ASC' ) );
	?>
	<h2>Tételek</h2>
	<?php
	$currency = ! empty( $invoice['client_id'] ) ? hpv_p_invoice_currency( $invoice ) : 'USD';
	$vat      = hpv_p_settings()['hu_vat_key'];
	?>
	<table class="widefat hpv-items" data-hpv-items data-currency="<?php echo esc_attr( $currency ); ?>" data-default-vat="<?php echo esc_attr( $vat ); ?>">
		<thead><tr><th>Tétel (az ügyfél nyelvén)</th><th style="width:110px">Mennyiség</th><th style="width:140px">Egységár</th><th style="width:130px">Összeg</th><th style="width:40px"></th></tr></thead>
		<tbody>
		<?php foreach ( array_values( $items ) as $i => $item ) : ?>
			<tr>
				<td><input type="text" name="items[<?php echo (int) $i; ?>][description]" value="<?php echo esc_attr( $item['description'] ); ?>" class="widefat" list="hpv-services"></td>
				<td><input type="number" step="0.01" name="items[<?php echo (int) $i; ?>][quantity]" value="<?php echo esc_attr( $item['quantity'] ); ?>" class="widefat" data-qty></td>
				<td><input type="number" step="0.01" name="items[<?php echo (int) $i; ?>][unit_price]" value="<?php echo esc_attr( $item['unit_price'] ); ?>" class="widefat" data-price></td>
				<td data-amount></td>
				<td><button type="button" class="button-link hpv-remove" data-remove aria-label="Tétel törlése">✕</button></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr><td colspan="5"><button type="button" class="button" data-add-item>+ Tétel</button></td></tr>
			<tr><td colspan="3" class="hpv-right">Nettó</td><td data-subtotal></td><td></td></tr>
			<tr><td colspan="3" class="hpv-right">Adó</td><td data-tax></td><td></td></tr>
			<tr class="hpv-total"><td colspan="3" class="hpv-right">Végösszeg</td><td data-total></td><td></td></tr>
		</tfoot>
	</table>
	<datalist id="hpv-services">
		<?php foreach ( $services as $service ) : ?>
			<option value="<?php echo esc_attr( $service['name'] ); ?>" data-price="<?php echo esc_attr( $service['price'] ); ?>"></option>
		<?php endforeach; ?>
	</datalist>
	<?php
}

function hpv_p_admin_project_tasks( int $project_id ) {
	$tasks = hpv_p_find( 'task', array( 'project_id' => $project_id ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
	?>
	<h2>Feladatok <span class="hpv-muted">(<?php echo (int) hpv_p_project_progress( $tasks ); ?>% kész)</span></h2>
	<?php hpv_p_admin_table( 'task', $tasks ); ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hpv-inline-form">
		<input type="hidden" name="action" value="hpv_crm_save">
		<input type="hidden" name="entity" value="task">
		<input type="hidden" name="data[project_id]" value="<?php echo (int) $project_id; ?>">
		<input type="hidden" name="data[sort]" value="<?php echo count( $tasks ); ?>">
		<input type="hidden" name="data[visible]" value="1">
		<?php wp_nonce_field( 'hpv_crm_save_task' ); ?>
		<input type="text" name="data[title]" placeholder="Új feladat (angolul)" required class="regular-text">
		<input type="date" name="data[due_date]">
		<select name="data[status]">
			<?php foreach ( hpv_p_entity( 'task' )['fields']['status']['options'] as $value => $labels ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $labels[0] ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button">Hozzáadás</button>
	</form>
	<?php
}

function hpv_p_admin_signed_contract( array $row ) {
	$intact = hpv_p_contract_hash( $row['body'] ) === $row['body_hash'];
	?>
	<div class="hpv-card">
		<p><?php echo hpv_p_badge( 'contract', 'status', 'signed' ); // phpcs:ignore ?> Az aláírt szerződés nem szerkeszthető. Módosításhoz készíts új szerződést.</p>
		<table class="widefat hpv-audit">
			<tr><th>Aláíró</th><td><?php echo esc_html( $row['signer_name'] ); ?> (<?php echo esc_html( $row['signer_email'] ); ?>)</td></tr>
			<tr><th>Időpont (UTC)</th><td><?php echo esc_html( $row['signed_at'] ); ?></td></tr>
			<tr><th>IP cím</th><td><?php echo esc_html( $row['signer_ip'] ); ?></td></tr>
			<tr><th>Böngésző</th><td><?php echo esc_html( $row['signer_agent'] ); ?></td></tr>
			<tr><th>Dokumentum lenyomat</th><td><code><?php echo esc_html( $row['body_hash'] ); ?></code> <?php echo $intact ? '<span class="hpv-badge hpv-badge--paid">Egyezik</span>' : '<span class="hpv-badge hpv-badge--overdue">NEM egyezik</span>'; ?></td></tr>
		</table>
	</div>
	<div class="hpv-card hpv-contract-body"><?php echo wp_kses_post( $row['body'] ); ?></div>
	<?php
}

/* ─── Mentés, törlés, meghívás ────────────────────────────── */

function hpv_p_admin_save() {
	$entity = sanitize_key( $_POST['entity'] ?? '' );
	if ( ! isset( hpv_p_entities()[ $entity ] ) || ! hpv_p_can_entity( $entity ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_crm_save_' . $entity );

	$id   = absint( $_POST['id'] ?? 0 );
	$do   = sanitize_key( $_POST['do'] ?? 'save' );
	$data = hpv_p_sanitize( $entity, (array) ( $_POST['data'] ?? array() ) );
	$old  = $id ? hpv_p_get( $entity, $id ) : null;
	$edit = hpv_p_edit_url( $entity, $id );

	if ( 'contract' === $entity && $old && 'signed' === $old['status'] ) {
		hpv_p_redirect( $edit, 'locked' );
	}
	if ( 'invoice' === $entity && $old && in_array( $do, array( 'paid', 'void', 'sync', 'link' ), true ) ) {
		hpv_p_admin_invoice_action( $old, $do );
	}
	if ( 'invoice' === $entity && $old && hpv_p_invoice_locked( $old ) ) {
		hpv_p_redirect( $edit, 'error', array( 'error' => 'A kiállított számla nem módosítható.' ) );
	}
	if ( 'invoice' === $entity ) {
		unset( $data['status'] ); // a státuszt a kiküldés, befizetés és érvénytelenítés állítja
		$client_id = (int) ( $data['client_id'] ?? $old['client_id'] ?? 0 );
		if ( 'HU' === hpv_p_client_country( $client_id ) ) {
			$data['tax_rate'] = hpv_p_vat_rate( (string) ( ( $data['vat_key'] ?? '' ) ?: hpv_p_settings()['hu_vat_key'] ) );
		}
	}

	// Előfizetés katalógusból: ami üres, azt a szolgáltatásból töltjük ki.
	if ( 'subscription' === $entity && ! empty( $data['service_id'] ) ) {
		$service = hpv_p_get( 'service', (int) $data['service_id'] );
		if ( $service ) {
			foreach ( array( 'name', 'description', 'billing' ) as $key ) {
				if ( empty( $data[ $key ] ) || ( 'billing' === $key && ! $old && empty( $_POST['data']['billing'] ) ) ) {
					$data[ $key ] = $service[ $key ];
				}
			}
			if ( '0.00' === ( $data['price'] ?? '0.00' ) ) {
				$data['price'] = $service['price'];
			}
		}
	}
	if ( 'subscription' === $entity && empty( $data['next_invoice_date'] ) && ! empty( $data['start_date'] ) ) {
		$data['next_invoice_date'] = $data['start_date'];
	}

	if ( 'activity' === $entity ) {
		$data['user_id'] = get_current_user_id();
		$data['type']    = 'note';
		$data['visible'] = 0;
	}

	$merged  = array_merge( $old ?: array(), $data );
	$missing = hpv_p_validate( $entity, $merged );
	if ( $missing ) {
		hpv_p_redirect( $old ? $edit : wp_get_referer(), 'required', array( 'fields' => implode( ', ', $missing ) ) );
	}

	if ( $old ) {
		hpv_p_update( $entity, $id, $data );
	} else {
		$id = hpv_p_insert( $entity, $data );
		if ( ! $id ) {
			hpv_p_redirect( wp_get_referer(), 'error', array( 'error' => 'Az adatbázisba írás nem sikerült.' ) );
		}
	}
	$row = hpv_p_get( $entity, $id );
	$msg = 'saved';

	if ( 'invoice' === $entity ) {
		hpv_p_save_invoice_items( $id, (array) ( $_POST['items'] ?? array() ) );
		if ( ! hpv_p_is_hu_invoice( $row ) ) {
			hpv_p_assign_invoice_number( $id ); // magyar számlánál a Számlázz.hu adja
		}
		$row = hpv_p_get( 'invoice', $id );

		if ( 'send' === $do ) {
			$sent = hpv_bill_send( $id, get_current_user_id() );
			if ( is_wp_error( $sent ) ) {
				hpv_p_redirect( hpv_p_edit_url( 'invoice', $id ), 'error', array( 'error' => $sent->get_error_message() ) );
			}
			$row = $sent;
			$msg = 'sent';
		}
	}

	if ( 'contract' === $entity && 'send' === $do ) {
		hpv_p_update( 'contract', $id, array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ) );
		$row = hpv_p_get( 'contract', $id );
		hpv_p_event_contract_sent( $row );
		hpv_p_log_client( (int) $row['client_id'], '"%s" is ready for your signature.', array( $row['title'] ), get_current_user_id() );
		$msg = 'sent';
	}

	// Vissza oda, ahonnan jött: feladat → projekt, tevékenység → ügyfél, egyéb → szerkesztő.
	if ( 'task' === $entity ) {
		hpv_p_redirect( hpv_p_edit_url( 'project', (int) $row['project_id'] ), $msg );
	}
	if ( 'invoice' === $entity ) {
		hpv_p_redirect( hpv_p_edit_url( 'invoice', $id ), $msg ); // a kiküldés után a számla panelje (PDF, befizetés, szinkron)
	}
	if ( 'activity' === $entity || ( 'client' !== $entity && ! empty( $row['client_id'] ) && 'save' !== $do ) ) {
		hpv_p_redirect( hpv_p_admin_url( array( 'client' => $row['client_id'] ) ), $msg );
	}
	if ( 'client' === $entity ) {
		hpv_p_redirect( hpv_p_admin_url( array( 'client' => $id ) ), $msg );
	}
	hpv_p_redirect( hpv_p_edit_url( $entity, $id ), $msg );
}

function hpv_p_admin_delete() {
	$entity = sanitize_key( $_GET['entity'] ?? '' );
	$id     = absint( $_GET['id'] ?? 0 );
	if ( ! isset( hpv_p_entities()[ $entity ] ) || ! hpv_p_can_entity( $entity ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_crm_delete_' . $entity . '_' . $id );

	$row = hpv_p_get( $entity, $id );
	if ( ! $row ) {
		hpv_p_redirect( hpv_p_admin_url(), 'deleted' );
	}

	// Jogi / könyvelési nyom: aláírt szerződés és kiküldött számla nem törölhető, csak érvényteleníthető.
	if ( 'invoice' === $entity && 'draft' !== $row['status'] ) {
		$void = hpv_bill_void( $id, get_current_user_id() );
		if ( is_wp_error( $void ) ) {
			hpv_p_redirect( hpv_p_edit_url( 'invoice', $id ), 'error', array( 'error' => $void->get_error_message() ) );
		}
		hpv_p_redirect( hpv_p_admin_url( array( 'client' => $row['client_id'] ) ), 'voided' );
	}
	if ( 'contract' === $entity && 'signed' === $row['status'] ) {
		hpv_p_update( $entity, $id, array( 'status' => 'void' ) );
		hpv_p_redirect( hpv_p_admin_url( array( 'client' => $row['client_id'] ) ), 'voided' );
	}

	hpv_p_delete( $entity, $id );

	if ( 'task' === $entity ) {
		hpv_p_redirect( hpv_p_edit_url( 'project', (int) $row['project_id'] ), 'deleted' );
	}
	hpv_p_redirect( ! empty( $row['client_id'] ) && 'client' !== $entity ? hpv_p_admin_url( array( 'client' => $row['client_id'] ) ) : hpv_p_admin_url(), 'deleted' );
}

/**
 * Számla panel műveletei: befizetés, érvénytelenítés/sztornó, szinkron újrapróbálása, Teya link.
 */
function hpv_p_admin_invoice_action( array $invoice, string $do ) {
	$id   = (int) $invoice['id'];
	$edit = hpv_p_edit_url( 'invoice', $id );
	$msg  = 'saved';
	switch ( $do ) {
		case 'paid':
			$date = sanitize_text_field( wp_unslash( $_POST['paid_on'] ?? '' ) );
			$res  = hpv_bill_mark_paid(
				$id,
				array(
					'provider' => 'manual',
					'amount'   => hpv_p_to_cents( wp_unslash( $_POST['amount'] ?? '' ) ) ?: hpv_p_invoice_balance( $invoice ),
					'paid_on'  => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' ),
					'note'     => wp_unslash( $_POST['note'] ?? '' ),
					'user_id'  => get_current_user_id(),
				)
			);
			$msg  = ! is_wp_error( $res ) && 'paid' === $res['status'] ? 'paid' : 'payment';
			break;
		case 'void':
			$res = hpv_bill_void( $id, get_current_user_id() );
			$msg = 'voided_ok';
			break;
		case 'sync':
			$res = hpv_bill_sync_invoice( $id );
			$msg = 'synced';
			break;
		default:
			$res = hpv_p_update( 'invoice', $id, array( 'payment_url' => esc_url_raw( wp_unslash( $_POST['payment_url'] ?? '' ) ) ) );
	}
	if ( is_wp_error( $res ) ) {
		hpv_p_redirect( $edit, 'error', array( 'error' => $res->get_error_message() ) );
	}
	hpv_p_redirect( $edit, $msg );
}

function hpv_p_admin_invite() {
	$client_id = absint( $_POST['client_id'] ?? 0 );
	if ( ! hpv_p_is_staff() ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_crm_invite_' . $client_id );

	$user = hpv_p_invite_user( $client_id, sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) );
	$back = hpv_p_admin_url( array( 'client' => $client_id ) );
	if ( is_wp_error( $user ) ) {
		hpv_p_redirect( $back, 'error', array( 'error' => $user->get_error_message() ) );
	}
	hpv_p_redirect( $back, 'invited' );
}

function hpv_p_admin_revoke() {
	$client_id = absint( $_GET['client'] ?? 0 );
	$user_id   = absint( $_GET['user'] ?? 0 );
	if ( ! hpv_p_is_staff() ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_crm_revoke_' . $user_id );

	if ( hpv_p_user_client_id( $user_id ) === $client_id ) {
		delete_user_meta( $user_id, 'hpv_client_id' );
		$user = get_userdata( $user_id );
		// Ügyfél-felhasználónál a be nem lépő fiókot is letiltjuk: új véletlen jelszó.
		if ( $user && in_array( 'hpv_client', (array) $user->roles, true ) ) {
			wp_set_password( wp_generate_password( 32 ), $user_id );
		}
		hpv_p_log( $client_id, 'system', sprintf( 'Portal access removed for %s.', $user ? $user->user_email : '#' . $user_id ), false, get_current_user_id() );
		do_action( 'hpv_p_user_revoked', $client_id, $user_id );
	}
	hpv_p_redirect( hpv_p_admin_url( array( 'client' => $client_id ) ), 'revoked' );
}

/* ─── Beállítások ─────────────────────────────────────────── */

function hpv_p_admin_register_settings() {
	register_setting( 'hpv_portal', HPV_PORTAL_OPTION, array( 'sanitize_callback' => 'hpv_p_sanitize_settings' ) );
}

function hpv_p_sanitize_settings( $input ): array {
	$input    = is_array( $input ) ? $input : array();
	$defaults = hpv_p_default_settings();
	$email    = sanitize_email( $input['notify_email'] ?? '' );

	return array(
		'company_name'    => sanitize_text_field( $input['company_name'] ?? '' ) ?: $defaults['company_name'],
		'company_legal'   => sanitize_text_field( $input['company_legal'] ?? '' ),
		'company_address' => sanitize_textarea_field( $input['company_address'] ?? '' ),
		'company_email'   => sanitize_email( $input['company_email'] ?? '' ) ?: $defaults['company_email'],
		'company_phone'   => sanitize_text_field( $input['company_phone'] ?? '' ),
		'currency'        => in_array( $input['currency'] ?? '', array( 'USD', 'EUR', 'HUF' ), true ) ? $input['currency'] : 'USD',
		'invoice_prefix'  => preg_replace( '/[^A-Za-z0-9\-_\/]/', '', (string) ( $input['invoice_prefix'] ?? '' ) ),
		'invoice_start'   => max( 1, absint( $input['invoice_start'] ?? 1 ) ),
		'payment_terms'   => min( 120, absint( $input['payment_terms'] ?? 15 ) ),
		'notify_email'    => is_email( $email ) ? $email : $defaults['notify_email'],
		'portal_page_id'  => absint( $input['portal_page_id'] ?? 0 ),
		'use_subdomains'  => ! empty( $input['use_subdomains'] ),
		'hu_vat_key'      => '' !== (string) ( $input['hu_vat_key'] ?? '' ) && isset( hpv_p_entity( 'invoice' )['fields']['vat_key']['options'][ $input['hu_vat_key'] ] ) ? $input['hu_vat_key'] : '27',
		'hu_fizmod'       => in_array( $input['hu_fizmod'] ?? '', array( 'Bankkártya', 'Átutalás', 'Készpénz' ), true ) ? $input['hu_fizmod'] : 'Bankkártya',
		'qbo_item_name'   => sanitize_text_field( $input['qbo_item_name'] ?? '' ) ?: 'Services',
		'report_auto'     => ! empty( $input['report_auto'] ),
		'payment_reminders' => ! empty( $input['payment_reminders'] ),
		'review_request'  => ! empty( $input['review_request'] ),
		'review_delay_days' => min( 60, absint( $input['review_delay_days'] ?? 3 ) ),
		'review_countries' => implode( ',', array_intersect( array( 'US', 'HU' ), array_map( 'trim', explode( ',', strtoupper( (string) ( $input['review_countries'] ?? 'US' ) ) ) ) ) ),
		'reminder_days'   => implode( ',', array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) ( $input['reminder_days'] ?? '3,7,14' ) ) ) ) ) ), 0, 5 ) ) ?: '3,7,14',
		'hourly_rate_usd' => hpv_p_cents_to_decimal( hpv_p_to_cents( $input['hourly_rate_usd'] ?? 0 ) ),
		'hourly_rate_huf' => hpv_p_cents_to_decimal( hpv_p_to_cents( $input['hourly_rate_huf'] ?? 0 ) ),
		'report_day'      => min( 28, max( 1, absint( $input['report_day'] ?? 3 ) ) ),
		'recurring_mode'  => in_array( $input['recurring_mode'] ?? '', array( 'off', 'draft', 'send' ), true ) ? $input['recurring_mode'] : 'draft',
	);
}

function hpv_p_admin_settings_page() {
	$s = hpv_p_settings();
	$n = HPV_PORTAL_OPTION;
	$f = function ( $key, $label, $type = 'text', $help = '' ) use ( $s, $n ) {
		echo '<tr><th><label for="hpv-s-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea id="hpv-s-' . esc_attr( $key ) . '" name="' . esc_attr( $n ) . '[' . esc_attr( $key ) . ']" rows="3" class="regular-text">' . esc_textarea( (string) $s[ $key ] ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" id="hpv-s-' . esc_attr( $key ) . '" name="' . esc_attr( $n ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '" class="regular-text">';
		}
		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	};
	?>
	<div class="wrap hpv-crm">
		<h1>CRM beállítások</h1>
		<?php settings_errors(); ?>
		<?php hpv_p_admin_notice(); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'hpv_portal' ); ?>
			<h2 class="title">Cégadatok (számlán, e-mailben)</h2>
			<table class="form-table" role="presentation">
				<?php
				$f( 'company_name', 'Márkanév' );
				$f( 'company_legal', 'Cégjogi név' );
				$f( 'company_address', 'Cím', 'textarea' );
				$f( 'company_email', 'E-mail', 'email', 'Válaszcím az ügyfeleknek küldött levelekben.' );
				$f( 'company_phone', 'Telefon' );
				?>
			</table>
			<h2 class="title">Számlázás</h2>
			<table class="form-table" role="presentation">
				<tr><th><label for="hpv-s-currency">Pénznem</label></th><td>
					<select id="hpv-s-currency" name="<?php echo esc_attr( $n ); ?>[currency]">
						<?php foreach ( array( 'USD', 'EUR', 'HUF' ) as $c ) : ?>
							<option <?php selected( $s['currency'], $c ); ?>><?php echo esc_html( $c ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<?php
				$f( 'invoice_prefix', 'Számlaszám előtag', 'text', 'Pl. HPV- → HPV-1001' );
				$f( 'invoice_start', 'Első számlaszám', 'number', 'Csak akkor számít, ha még nem készült számla.' );
				$f( 'payment_terms', 'Fizetési határidő (nap)', 'number' );
				?>
				<tr><th><label for="hpv-s-vat">Magyar számla: alap ÁFA</label></th><td>
					<select id="hpv-s-vat" name="<?php echo esc_attr( $n ); ?>[hu_vat_key]">
						<?php foreach ( hpv_p_entity( 'invoice' )['fields']['vat_key']['options'] as $k => $labels ) : ?>
							<?php if ( '' !== $k ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['hu_vat_key'], $k ); ?>><?php echo esc_html( $labels[0] ); ?></option><?php endif; ?>
						<?php endforeach; ?>
					</select>
					<p class="description">Alanyi adómentes vállalkozásnál: AAM. Számlánként felülírható.</p>
				</td></tr>
				<tr><th><label for="hpv-s-fizmod">Magyar számla: fizetési mód</label></th><td>
					<select id="hpv-s-fizmod" name="<?php echo esc_attr( $n ); ?>[hu_fizmod]">
						<?php foreach ( array( 'Bankkártya', 'Átutalás', 'Készpénz' ) as $m ) : ?>
							<option <?php selected( $s['hu_fizmod'], $m ); ?>><?php echo esc_html( $m ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
				<?php $f( 'qbo_item_name', 'QuickBooks tétel neve', 'text', 'Ezzel a QuickBooks termékkel/szolgáltatással kerülnek át a számlatételek (Sales → Products and services).' ); ?>
				<tr><th><label for="hpv-s-recurring">Ismétlődő számlák</label></th><td>
					<select id="hpv-s-recurring" name="<?php echo esc_attr( $n ); ?>[recurring_mode]">
						<?php foreach ( array( 'draft' => 'Piszkozat készül, mi küldjük ki (javasolt)', 'send' => 'Automatikus kiküldés', 'off' => 'Kikapcsolva' ) as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['recurring_mode'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Az aktív előfizetésekből a „Következő számla” napján reggel 7-kor ügyfelenként egy számla készül, a csapat összefoglaló e-mailt kap.
						<?php $last = get_option( 'hpv_recurring_last_run' ); echo $last ? esc_html( sprintf( 'Utolsó futás: %s, %d számla.', get_date_from_gmt( $last['at'], 'Y-m-d H:i' ), $last['count'] ) ) : ''; ?></p>
				</td></tr>
				<tr><th><label for="hpv-s-reminder_days">Fizetési emlékeztetők</label></th><td>
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[payment_reminders]" value="1" <?php checked( ! empty( $s['payment_reminders'] ) ); ?>> Emlékeztető a lejárt számlákról az ügyfélnek (az ügyfél nyelvén)</label><br>
					a lejárat után ennyi nappal: <input type="text" id="hpv-s-reminder_days" name="<?php echo esc_attr( $n ); ?>[reminder_days]" value="<?php echo esc_attr( $s['reminder_days'] ); ?>" style="width:100px"> <span class="description">(vesszővel, pl. 3,7,14; az utolsó „végső emlékeztető”)</span>
				</td></tr>
				<tr><th><label for="hpv-s-review_delay_days">Google értékelés kérése</label></th><td>
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[review_request]" value="1" <?php checked( ! empty( $s['review_request'] ) ); ?>> Kész projekt után automatikusan (a Reviews bővítményen keresztül)</label><br>
					a lezárás után <input type="number" min="0" max="60" id="hpv-s-review_delay_days" name="<?php echo esc_attr( $n ); ?>[review_delay_days]" value="<?php echo (int) $s['review_delay_days']; ?>" style="width:60px"> nappal, ezeknek az országoknak: <input type="text" name="<?php echo esc_attr( $n ); ?>[review_countries]" value="<?php echo esc_attr( $s['review_countries'] ); ?>" style="width:70px"> <span class="description">(US, HU)</span>
					<p class="description">Külön telepítésnél a wp-config.php-ba: HPV_SITE_URL és HPV_BRIDGE_SECRET (a marketing oldalon is ugyanez a titok). Ugyanaz a titok viszi a Website Grader érdeklődőit is a CRM-be. <?php echo hpv_bridge_secret() ? '✔ titok beállítva' : '✘ nincs HPV_BRIDGE_SECRET'; ?></p>
				</td></tr>
				<tr><th>Óradíj (munkaidő-számlázás)</th><td>
					USD <input type="text" name="<?php echo esc_attr( $n ); ?>[hourly_rate_usd]" value="<?php echo esc_attr( $s['hourly_rate_usd'] ); ?>" style="width:90px">
					&nbsp; HUF <input type="text" name="<?php echo esc_attr( $n ); ?>[hourly_rate_huf]" value="<?php echo esc_attr( $s['hourly_rate_huf'] ); ?>" style="width:110px">
					<p class="description">Ügyfelenként felülírható az adatlapon („Óradíj”).</p>
				</td></tr>
				<tr><th><label for="hpv-s-report_day">Havi riportok</label></th><td>
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[report_auto]" value="1" <?php checked( ! empty( $s['report_auto'] ) ); ?>> Az előző hónap riport-piszkozata magától elkészül a havidíjas / marketinges ügyfeleknek</label><br>
					minden hónap <input type="number" min="1" max="28" id="hpv-s-report_day" name="<?php echo esc_attr( $n ); ?>[report_day]" value="<?php echo (int) $s['report_day']; ?>" style="width:60px">. napján (kiküldés a CRM appból, átnézés után).
				</td></tr>
			</table>
			<h2 class="title">Portál</h2>
			<table class="form-table" role="presentation">
				<tr><th>Aldomainek</th><td>
					<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[use_subdomains]" value="1" <?php checked( ! empty( $s['use_subdomains'] ) ); ?>>
						Aldomainek használata (<code><?php echo esc_html( hpv_p_crm_host() ); ?></code>, <code><?php echo esc_html( hpv_p_portal_host() ); ?></code>)</label>
					<p class="description">Élesben kapcsold be: így az e-mailekben lévő linkek akkor is a portál aldomainre mutatnak, ha a levél háttérfolyamatból (cron, WP-CLI) megy ki.</p>
				</td></tr>
				<tr><th><label for="hpv-s-page">Portál oldal</label></th><td>
					<?php
					wp_dropdown_pages(
						array(
							'name'              => $n . '[portal_page_id]',
							'id'                => 'hpv-s-page',
							'selected'          => (int) $s['portal_page_id'],
							'show_option_none'  => '— válassz —',
							'option_none_value' => 0,
						)
					);
					?>
					<p class="description">Csak aldomainek nélkül (fejlesztéskor) kell. Az oldal tartalma: <code>[hpv_portal]</code></p>
				</td></tr>
				<?php $f( 'notify_email', 'Értesítések címe', 'email', 'Ügyfélüzenetek és aláírások értesítője.' ); ?>
			</table>
			<?php submit_button( 'Mentés' ); ?>
		</form>
		<?php hpv_p_billing_admin_section(); ?>
		<?php hpv_video_admin_section(); ?>
		<?php hpv_conn_admin_section(); ?>
	</div>
	<?php
}

/* ─── Beállítások: számlázás és fizetés ───────────────────── */

function hpv_p_billing_admin_section() {
	$ok   = '<span style="color:#1a7f37">✔ rendben</span>';
	$no   = '<span style="color:#b32d2e">✘ nincs beállítva</span>';
	$rows = array(
		array( 'Számlázz.hu (Magyarország)', hpv_szamlazz_enabled() ? $ok : $no, 'HPV_SZAMLAZZ_AGENT_KEY' ),
		array( 'Teya (Magyarország)', '<span style="color:#9a6700">kézi fizetési link</span>', 'Az API bekötése a fejlesztői dokumentációban' ),
		array( 'Stripe (USA)', hpv_stripe_enabled() ? $ok : $no, 'HPV_STRIPE_SECRET_KEY' ),
		array( 'Stripe webhook', '' !== hpv_stripe_webhook_secret() ? $ok : $no, 'HPV_STRIPE_WEBHOOK_SECRET · cím: ' . hpv_stripe_webhook_url() ),
		array( 'QuickBooks (USA)', hpv_qbo_connected() ? $ok : ( hpv_qbo_configured() ? '<span style="color:#9a6700">nincs összekapcsolva</span>' : $no ), 'HPV_QBO_CLIENT_ID, HPV_QBO_CLIENT_SECRET' . ( defined( 'HPV_QBO_SANDBOX' ) && HPV_QBO_SANDBOX ? ' · SANDBOX' : '' ) ),
	);
	?>
	<h2 class="title">Számlázás és fizetés</h2>
	<p>A kulcsok a <code>wp-config.php</code>-ban vannak, nem itt. Az ügyfél országa dönti el, melyik rendszer dolgozik.</p>
	<table class="widefat striped" style="max-width:860px">
		<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr><td><?php echo esc_html( $r[0] ); ?></td><td><?php echo $r[1]; // phpcs:ignore WordPress.Security.EscapeOutput -- fix HTML ?></td><td class="hpv-muted"><code><?php echo esc_html( $r[2] ); ?></code></td></tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( hpv_qbo_configured() ) : ?>
		<p style="display:flex;gap:8px;align-items:center">
			<?php $action = hpv_qbo_connected() ? 'hpv_qbo_disconnect' : 'hpv_qbo_connect'; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<?php wp_nonce_field( $action ); ?>
				<button class="button<?php echo hpv_qbo_connected() ? '' : ' button-primary'; ?>"><?php echo hpv_qbo_connected() ? 'QuickBooks leválasztása' : 'QuickBooks összekapcsolása'; ?></button>
			</form>
			<span class="description">Az Intuit appban beállítandó Redirect URI: <code><?php echo esc_html( hpv_qbo_redirect_uri() ); ?></code></span>
		</p>
	<?php endif; ?>
	<?php
}
