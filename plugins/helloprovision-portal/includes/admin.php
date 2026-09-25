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
	add_submenu_page( 'hpv-crm', 'Számlák', 'Számlák', 'hpv_manage_crm', 'hpv-crm-invoices', fn() => hpv_p_admin_list_page( 'invoice' ) );
	add_submenu_page( 'hpv-crm', 'Szerződések', 'Szerződések', 'hpv_manage_crm', 'hpv-crm-contracts', fn() => hpv_p_admin_list_page( 'contract' ) );
	add_submenu_page( 'hpv-crm', 'Szolgáltatás-katalógus', 'Szolgáltatások', 'hpv_manage_crm', 'hpv-crm-services', fn() => hpv_p_admin_list_page( 'service' ) );
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

	$open    = hpv_p_find( 'invoice', array( 'status' => 'sent' ), array( 'limit' => 2000 ) );
	$overdue = array_filter( $open, fn( $i ) => hpv_p_invoice_is_overdue( $i, $today ) );
	$mrr     = hpv_p_mrr( hpv_p_find( 'subscription', array( 'status' => 'active' ), array( 'limit' => 2000 ) ) );
	$active  = count( hpv_p_find( 'client', array( 'status' => 'active' ), array( 'limit' => 2000 ) ) );
	$waiting = count( hpv_p_find( 'contract', array( 'status' => 'sent' ), array( 'limit' => 2000 ) ) );

	$balance_by_client = array();
	foreach ( $open as $invoice ) {
		$balance_by_client[ $invoice['client_id'] ] = ( $balance_by_client[ $invoice['client_id'] ] ?? 0 ) + hpv_p_to_cents( $invoice['total'] );
	}
	?>
	<h1 class="wp-heading-inline">Ügyfelek</h1>
	<a href="<?php echo esc_url( hpv_p_edit_url( 'client' ) ); ?>" class="page-title-action">Új ügyfél</a>
	<hr class="wp-header-end">

	<div class="hpv-kpis">
		<div class="hpv-kpi"><span>Aktív ügyfelek</span><strong><?php echo (int) $active; ?></strong></div>
		<div class="hpv-kpi"><span>Havi ismétlődő bevétel</span><strong><?php echo esc_html( hpv_p_money( $mrr, $s['currency'] ) ); ?></strong></div>
		<div class="hpv-kpi"><span>Kintlévőség</span><strong><?php echo esc_html( hpv_p_money( array_sum( $balance_by_client ), $s['currency'] ) ); ?></strong></div>
		<div class="hpv-kpi<?php echo $overdue ? ' is-alert' : ''; ?>"><span>Lejárt számla</span><strong><?php echo count( $overdue ); ?></strong></div>
		<div class="hpv-kpi"><span>Aláírásra vár</span><strong><?php echo (int) $waiting; ?></strong></div>
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
		<thead><tr><th>Cégnév</th><th>Kapcsolattartó</th><th>E-mail</th><th>Státusz</th><th>Nyitott számlák</th></tr></thead>
		<tbody>
		<?php if ( ! $clients ) : ?>
			<tr><td colspan="5">Nincs ügyfél. <a href="<?php echo esc_url( hpv_p_edit_url( 'client' ) ); ?>">Az első ügyfél felvétele →</a></td></tr>
		<?php endif; ?>
		<?php foreach ( $clients as $c ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( hpv_p_admin_url( array( 'client' => $c['id'] ) ) ); ?>"><strong><?php echo esc_html( $c['name'] ); ?></strong></a></td>
				<td><?php echo esc_html( $c['contact_name'] ); ?></td>
				<td><?php echo esc_html( $c['email'] ); ?></td>
				<td><?php echo hpv_p_badge( 'client', 'status', $c['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				<td><?php echo isset( $balance_by_client[ $c['id'] ] ) ? esc_html( hpv_p_money( $balance_by_client[ $c['id'] ], $s['currency'] ) ) : '—'; ?></td>
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
	$open     = array_filter( $invoices, fn( $i ) => 'sent' === $i['status'] );
	$balance  = array_sum( array_map( fn( $i ) => hpv_p_to_cents( $i['total'] ), $open ) );
	$new      = fn( $entity ) => hpv_p_edit_url( $entity, 0, array( 'client_id' => $id ) );
	?>
	<p><a href="<?php echo esc_url( hpv_p_admin_url() ); ?>">← Ügyfelek</a></p>
	<div class="hpv-client-head">
		<div>
			<h1><?php echo esc_html( $client['name'] ); ?> <?php echo hpv_p_badge( 'client', 'status', $client['status'] ); // phpcs:ignore ?></h1>
			<p class="hpv-muted">
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
		<div class="hpv-kpi"><span>Nyitott egyenleg</span><strong><?php echo esc_html( hpv_p_money( $balance, $s['currency'] ) ); ?></strong></div>
		<div class="hpv-kpi"><span>Havi díj (MRR)</span><strong><?php echo esc_html( hpv_p_money( hpv_p_mrr( $subs ), $s['currency'] ) ); ?></strong></div>
		<div class="hpv-kpi"><span>Aktív projektek</span><strong><?php echo count( array_filter( $projects, fn( $p ) => in_array( $p['status'], array( 'planning', 'in_progress', 'review' ), true ) ) ); ?></strong></div>
		<div class="hpv-kpi"><span>Portál felhasználók</span><strong><?php echo count( $users ); ?></strong></div>
	</div>

	<?php if ( $client['notes'] ) : ?>
		<div class="hpv-card hpv-note"><strong>Belső megjegyzés:</strong> <?php echo nl2br( esc_html( $client['notes'] ) ); ?></div>
	<?php endif; ?>

	<div class="hpv-grid">
		<div class="hpv-col">
			<section class="hpv-card">
				<header><h2>Szolgáltatások</h2><a class="button button-small" href="<?php echo esc_url( $new( 'subscription' ) ); ?>">+ Szolgáltatás</a></header>
				<?php hpv_p_admin_table( 'subscription', $subs ); ?>
			</section>

			<section class="hpv-card">
				<header><h2>Projektek</h2><a class="button button-small" href="<?php echo esc_url( $new( 'project' ) ); ?>">+ Projekt</a></header>
				<?php hpv_p_admin_table( 'project', $projects, array( 'progress' => true ) ); ?>
			</section>

			<section class="hpv-card">
				<header><h2>Számlák</h2><a class="button button-small" href="<?php echo esc_url( $new( 'invoice' ) ); ?>">+ Számla</a></header>
				<?php hpv_p_admin_table( 'invoice', $invoices, array( 'today' => $today ) ); ?>
			</section>

			<section class="hpv-card">
				<header><h2>Szerződések</h2><a class="button button-small" href="<?php echo esc_url( $new( 'contract' ) ); ?>">+ Szerződés</a></header>
				<?php hpv_p_admin_table( 'contract', $contracts ); ?>
			</section>
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
					$out = esc_html( hpv_p_money( $value, $s['currency'] ) );
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
	if ( ! hpv_p_is_staff() ) {
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
			$shown = hpv_p_money( $value );
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

	$back   = ! empty( $row['client_id'] ) ? hpv_p_admin_url( array( 'client' => $row['client_id'] ) ) : hpv_p_admin_url();
	$locked = 'contract' === $entity && 'signed' === ( $row['status'] ?? '' );
	?>
	<p><a href="<?php echo esc_url( $back ); ?>">← Vissza<?php echo ! empty( $row['client_id'] ) ? ': ' . esc_html( hpv_p_client_name( (int) $row['client_id'] ) ) : ''; ?></a></p>
	<h1><?php echo esc_html( ( $id ? '' : 'Új ' ) . ( $id ? $def['singular'] : mb_strtolower( $def['singular'] ) ) ); ?><?php echo 'invoice' === $entity && ! empty( $row['number'] ) ? ' ' . esc_html( $row['number'] ) : ''; ?></h1>

	<?php if ( $locked ) : ?>
		<?php hpv_p_admin_signed_contract( $row ); ?>
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
				if ( 'invoice_item' === $entity || ( 'activity' === $entity && 'user_id' === $key ) ) {
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
			<?php if ( 'invoice' === $entity && in_array( $row['status'] ?? 'draft', array( 'draft', 'sent' ), true ) ) : ?>
				<button class="button" name="do" value="send" onclick="return confirm('Mented és elküldöd az ügyfélnek?');"><?php echo 'sent' === ( $row['status'] ?? '' ) ? 'Mentés és újraküldés' : 'Mentés és kiküldés az ügyfélnek'; ?></button>
			<?php endif; ?>
			<?php if ( 'invoice' === $entity && 'sent' === ( $row['status'] ?? '' ) ) : ?>
				<button class="button" name="do" value="paid">Fizetettnek jelölés</button>
			<?php endif; ?>
			<?php if ( 'contract' === $entity && in_array( $row['status'] ?? 'draft', array( 'draft', 'sent' ), true ) ) : ?>
				<button class="button" name="do" value="send" onclick="return confirm('Mented és elküldöd aláírásra?');"><?php echo 'sent' === ( $row['status'] ?? '' ) ? 'Mentés és emlékeztető küldése' : 'Mentés és kiküldés aláírásra'; ?></button>
			<?php endif; ?>
			<?php if ( $id ) : ?>
				<a class="hpv-delete" href="<?php echo esc_url( hpv_p_delete_url( $entity, $id ) ); ?>" onclick="return confirm('Biztosan törlöd? A kapcsolódó adatok is törlődnek.');">Törlés</a>
			<?php endif; ?>
		</p>
	</form>

	<?php if ( 'project' === $entity && $id ) : ?>
		<?php hpv_p_admin_project_tasks( $id ); ?>
	<?php endif; ?>
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
	<table class="widefat hpv-items" data-hpv-items>
		<thead><tr><th>Tétel (angolul)</th><th style="width:110px">Mennyiség</th><th style="width:140px">Egységár</th><th style="width:130px">Összeg</th><th style="width:40px"></th></tr></thead>
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
	if ( ! hpv_p_is_staff() || ! isset( hpv_p_entities()[ $entity ] ) ) {
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
		hpv_p_assign_invoice_number( $id );
		$row = hpv_p_get( 'invoice', $id );

		if ( 'send' === $do ) {
			hpv_p_update( 'invoice', $id, array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ) );
			$row = hpv_p_get( 'invoice', $id );
			hpv_p_event_invoice_sent( $row );
			hpv_p_log( (int) $row['client_id'], 'system', sprintf( 'Invoice %s issued: %s.', $row['number'], hpv_p_money( $row['total'] ) ), true, get_current_user_id() );
			$msg = 'sent';
		} elseif ( 'paid' === $do ) {
			hpv_p_update( 'invoice', $id, array( 'status' => 'paid', 'paid_at' => current_time( 'mysql', true ) ) );
			hpv_p_log( (int) $row['client_id'], 'system', sprintf( 'Payment received for invoice %s. Thank you!', $row['number'] ), true, get_current_user_id() );
			$msg = 'paid';
		}
	}

	if ( 'contract' === $entity && 'send' === $do ) {
		hpv_p_update( 'contract', $id, array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ) );
		$row = hpv_p_get( 'contract', $id );
		hpv_p_event_contract_sent( $row );
		hpv_p_log( (int) $row['client_id'], 'system', sprintf( '"%s" is ready for your signature.', $row['title'] ), true, get_current_user_id() );
		$msg = 'sent';
	}

	// Vissza oda, ahonnan jött: feladat → projekt, tevékenység → ügyfél, egyéb → szerkesztő.
	if ( 'task' === $entity ) {
		hpv_p_redirect( hpv_p_edit_url( 'project', (int) $row['project_id'] ), $msg );
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
	if ( ! hpv_p_is_staff() || ! isset( hpv_p_entities()[ $entity ] ) ) {
		wp_die( 'Nincs jogosultságod.' );
	}
	check_admin_referer( 'hpv_crm_delete_' . $entity . '_' . $id );

	$row = hpv_p_get( $entity, $id );
	if ( ! $row ) {
		hpv_p_redirect( hpv_p_admin_url(), 'deleted' );
	}

	// Jogi / könyvelési nyom: aláírt szerződés és kiküldött számla nem törölhető, csak érvényteleníthető.
	if ( ( 'contract' === $entity && 'signed' === $row['status'] ) || ( 'invoice' === $entity && 'draft' !== $row['status'] ) ) {
		hpv_p_update( $entity, $id, array( 'status' => 'void' ) );
		hpv_p_redirect( hpv_p_admin_url( array( 'client' => $row['client_id'] ) ), 'voided' );
	}

	hpv_p_delete( $entity, $id );

	if ( 'task' === $entity ) {
		hpv_p_redirect( hpv_p_edit_url( 'project', (int) $row['project_id'] ), 'deleted' );
	}
	hpv_p_redirect( ! empty( $row['client_id'] ) && 'client' !== $entity ? hpv_p_admin_url( array( 'client' => $row['client_id'] ) ) : hpv_p_admin_url(), 'deleted' );
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
			</table>
			<h2 class="title">Portál</h2>
			<table class="form-table" role="presentation">
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
					<p class="description">Az oldal tartalma: <code>[hpv_portal]</code></p>
				</td></tr>
				<?php $f( 'notify_email', 'Értesítések címe', 'email', 'Ügyfélüzenetek és aláírások értesítője.' ); ?>
			</table>
			<?php submit_button( 'Mentés' ); ?>
		</form>
	</div>
	<?php
}
