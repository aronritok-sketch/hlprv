<?php
/**
 * Ügyfélportál (angol felület). A clients.* aldomainen saját, téma nélküli keretben fut,
 * fejlesztéskor a [hpv_portal] shortcode-dal bármely oldalon.
 */

defined( 'ABSPATH' ) || exit;

const HPV_PORTAL_VIEWS = array(
	'overview'  => 'Overview',
	'projects'  => 'Projects',
	'messages'  => 'Messages',
	'meetings'  => 'Meetings',
	'files'     => 'Files',
	'proposals' => 'Proposals',
	'invoices'  => 'Invoices',
	'contracts' => 'Contracts',
	'services'  => 'Services',
	'account'   => 'Account',
);

add_shortcode( 'hpv_portal', 'hpv_p_portal_shortcode' );
add_action( 'init', 'hpv_p_portal_handle_post', 20 );

/**
 * Melyik ügyfél adatait mutatjuk: ügyfél-felhasználónál a sajátját;
 * munkatársnál csak előnézetben (?preview_client=ID), csak olvasásra.
 */
function hpv_p_portal_client_id(): int {
	if ( ! is_user_logged_in() ) {
		return 0;
	}
	if ( hpv_p_is_staff() ) {
		return absint( $_GET['preview_client'] ?? 0 );
	}

	return hpv_p_user_client_id( get_current_user_id() );
}

function hpv_p_portal_link( string $view, array $args = array() ): string {
	$args = array_merge( array( 'view' => 'overview' === $view ? null : $view ), $args );
	if ( ! empty( $_GET['preview_client'] ) && hpv_p_is_staff() ) {
		$args['preview_client'] = absint( $_GET['preview_client'] );
	}

	return hpv_p_portal_url( array_filter( $args, fn( $v ) => null !== $v ) );
}

function hpv_p_portal_icon( string $name ): string {
	$paths = array(
		'overview'  => 'M3 12l9-8 9 8M5 10v10h5v-6h4v6h5V10',
		'projects'  => 'M4 5h16v4H4zM4 11h10v4H4zM4 17h7v3H4z',
		'messages'  => 'M4 5h16v11H8l-4 4z',
		'meetings'  => 'M3 7h12v10H3zM15 10l6-3v10l-6-3',
		'files'     => 'M3 6h6l2 2h10v11H3zM3 10h18',
		'proposals' => 'M5 3h10l4 4v14H5zM14 3v5h5M9 13l2 2 4-4',
		'invoices'  => 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6',
		'contracts' => 'M6 3h9l3 3v15H6zM9 10h6M9 14h6M9 18h3',
		'services'  => 'M12 3l8 4v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z',
		'account'   => 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21c1-4 4-6 8-6s7 2 8 6',
	);

	return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . esc_attr( $paths[ $name ] ?? '' ) . '"/></svg>';
}

function hpv_p_portal_badge( string $entity, string $field, string $value, string $extra = '' ): string {
	return '<span class="hpv-pill hpv-pill--' . esc_attr( $extra ?: $value ) . '">' . esc_html( hpv_t( $extra ? ucfirst( $extra ) : hpv_p_option_label( $entity, $field, $value, 'en' ) ) ) . '</span>';
}

function hpv_p_portal_date( ?string $date ): string {
	return $date ? esc_html( hpv_date( $date ) ) : '—';
}

/**
 * Keresztnév a köszönéshez: a profilban megadott, különben a teljes névből (magyarul a vezetéknév áll elöl).
 */
function hpv_p_first_name( WP_User $user ): string {
	$first = trim( (string) $user->first_name );
	if ( '' !== $first && $first !== $user->display_name ) {
		return $first;
	}
	$parts = preg_split( '/\s+/', trim( $user->display_name ) );

	return (string) ( 'hu' === hpv_lang() ? end( $parts ) : $parts[0] );
}

/**
 * A portál nyelve: az ügyfélé (magyar ügyfélnek magyar), bejelentkezés előtt a böngészőé.
 */
function hpv_p_portal_set_lang( ?array $client ): void {
	hpv_set_lang( $client ? hpv_doc_client_language( (int) $client['id'] ) : hpv_guess_lang() );
	if ( $client && ! hpv_p_is_staff() ) {
		hpv_remember_lang( hpv_lang() );
	}
}

/* ─── Aláírás (POST) ──────────────────────────────────────── */

function hpv_p_portal_handle_post() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'sign' !== ( $_POST['hpv_portal_action'] ?? '' ) || ! is_user_logged_in() ) {
		return;
	}
	$contract_id = absint( $_POST['contract_id'] ?? 0 );
	check_admin_referer( 'hpv_sign_' . $contract_id );

	$back = hpv_p_portal_url( array( 'view' => 'contracts', 'id' => $contract_id ) );
	hpv_set_lang( hpv_doc_client_language( hpv_p_user_client_id( get_current_user_id() ) ) );
	if ( hpv_p_is_staff() ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( hpv_t( 'Staff cannot sign on behalf of a client.' ) ), $back ) );
		exit;
	}
	if ( empty( $_POST['agree'] ) ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( hpv_t( 'Please confirm that you agree to sign electronically.' ) ), $back ) );
		exit;
	}

	$client_id = hpv_p_user_client_id( get_current_user_id() );
	$result    = hpv_p_sign_contract( $contract_id, $client_id, wp_get_current_user(), wp_unslash( (string) ( $_POST['signer_name'] ?? '' ) ) );
	if ( is_wp_error( $result ) ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( hpv_t( $result->get_error_message() ) ), $back ) );
		exit;
	}

	$client = hpv_p_get( 'client', $client_id );
	hpv_p_log_client( $client_id, '"%s" was signed by %s.', array( $result['title'], $result['signer_name'] ), get_current_user_id() );
	hpv_p_event_contract_signed( $result, $client );
	wp_safe_redirect( add_query_arg( 'signed', 1, $back ) );
	exit;
}

/* ─── Keret ───────────────────────────────────────────────── */

function hpv_p_portal_enqueue() {
	wp_enqueue_style( 'hpv-portal', plugins_url( 'assets/portal.css', HPV_PORTAL_FILE ), array(), HPV_PORTAL_VERSION );
}

/**
 * Teljes HTML dokumentum a portál aldomainhez (téma nélkül).
 */
function hpv_p_render_portal_document() {
	hpv_p_portal_enqueue();
	$body = hpv_p_portal_app();
	?>
<!doctype html>
<html lang="<?php echo esc_attr( hpv_lang() ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( hpv_p_settings()['company_name'] . ' ' . hpv_t( 'Client Portal' ) ); ?></title>
	<?php wp_print_styles( array( 'hpv-portal', 'hpv-chat' ) ); ?>
</head>
<body class="hpv-portal-body">
	<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- a nézetek escapelnek ?>
	<?php wp_print_scripts( array( 'hpv-chat' ) ); ?>
</body>
</html>
	<?php
}

function hpv_p_portal_shortcode(): string {
	hpv_p_portal_enqueue();

	return hpv_p_portal_app();
}

function hpv_p_portal_app(): string {
	ob_start();

	if ( ! is_user_logged_in() ) {
		hpv_p_portal_set_lang( null );
		hpv_p_portal_login();
		return (string) ob_get_clean();
	}

	$client_id = hpv_p_portal_client_id();
	$client    = $client_id ? hpv_p_get( 'client', $client_id ) : null;
	hpv_p_portal_set_lang( $client );

	if ( ! $client ) {
		hpv_p_portal_no_access();
		return (string) ob_get_clean();
	}

	$view = sanitize_key( $_GET['view'] ?? 'overview' );
	$view = 'call' === $view ? 'meetings' : $view; // a meghívó linkje: ?view=call&id=
	$view = isset( HPV_PORTAL_VIEWS[ $view ] ) ? $view : 'overview';
	$user = wp_get_current_user();
	$s    = hpv_p_settings();

	$counts = array(
		'messages'  => hpv_p_is_staff() ? 0 : hpv_chat_total_unread( $user->ID ),
		'invoices'  => count( array_filter( hpv_p_portal_invoices( $client_id ), fn( $i ) => 'sent' === $i['status'] ) ),
		'contracts' => count( array_filter( hpv_p_portal_contracts( $client_id ), fn( $c ) => 'sent' === $c['status'] ) ),
	);
	?>
	<div class="hpv-portal">
		<?php if ( hpv_p_is_staff() ) : ?>
			<div class="hpv-preview-bar"><?php echo wp_kses( hpv_t( 'Staff preview of %s — read only.', '<strong>' . esc_html( $client['name'] ) . '</strong>' ), array( 'strong' => array() ) ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=hpv-crm&client=' . $client_id ) ); ?>"><?php echo esc_html( hpv_t( 'Back to CRM' ) ); ?></a></div>
		<?php endif; ?>
		<aside class="hpv-side">
			<a class="hpv-brand" href="<?php echo esc_url( hpv_p_portal_link( 'overview' ) ); ?>"><?php echo esc_html( $s['company_name'] ); ?><span><?php echo esc_html( hpv_t( 'Client Portal' ) ); ?></span></a>
			<nav class="hpv-nav" aria-label="Portal">
				<?php foreach ( HPV_PORTAL_VIEWS as $key => $label ) : ?>
					<a href="<?php echo esc_url( hpv_p_portal_link( $key ) ); ?>" class="<?php echo $key === $view ? 'is-active' : ''; ?>" <?php echo $key === $view ? 'aria-current="page"' : ''; ?>>
						<?php echo hpv_p_portal_icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span><?php echo esc_html( hpv_t( $label ) ); ?></span>
						<?php if ( ! empty( $counts[ $key ] ) ) : ?><em class="hpv-count"><?php echo (int) $counts[ $key ]; ?></em><?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="hpv-side__user">
				<span class="hpv-avatar"><?php echo esc_html( hpv_chat_user_label( $user->ID )['initials'] ); ?></span>
				<span><strong><?php echo esc_html( $user->display_name ); ?></strong><small><?php echo esc_html( $client['name'] ); ?></small></span>
				<a href="<?php echo esc_url( wp_logout_url( hpv_p_portal_url() ) ); ?>" class="hpv-logout"><?php echo esc_html( hpv_t( 'Log out' ) ); ?></a>
			</div>
		</aside>

		<main class="hpv-main" id="main">
			<?php
			$id   = absint( $_GET['id'] ?? 0 );
			$need = array( 'invoices' => 'invoices', 'services' => 'invoices', 'contracts' => 'contracts', 'proposals' => 'proposals' )[ $view ] ?? '';
			if ( $need && hpv_p_is_staff() && ! hpv_p_can( $need ) ) {
				$view = 'no_access';
				hpv_p_portal_header( hpv_t( HPV_PORTAL_VIEWS[ sanitize_key( $_GET['view'] ?? '' ) ] ?? '' ), '' );
				echo '<div class="hpv-alert">' . esc_html( hpv_t( 'Staff preview: you do not have access to this section.' ) ) . '</div>';
			}
			switch ( $view ) {
				case 'no_access':
					break;
				case 'projects':
					$id ? hpv_pv_project( $client_id, $id ) : hpv_pv_projects( $client_id );
					break;
				case 'invoices':
					$id ? hpv_pv_invoice( $client, $id ) : hpv_pv_invoices_list( $client_id );
					break;
				case 'contracts':
					$id ? hpv_pv_contract( $client_id, $id ) : hpv_pv_contracts_list( $client_id );
					break;
				case 'services':
					hpv_pv_services( $client_id );
					break;
				case 'messages':
					hpv_pv_messages();
					break;
				case 'meetings':
					$id ? hpv_pv_meeting( $client_id, $id ) : hpv_pv_meetings( $client_id );
					break;
				case 'files':
					hpv_pv_files( $client_id );
					break;
				case 'proposals':
					hpv_pv_proposals( $client_id );
					break;
				case 'account':
					hpv_pv_account( $client );
					break;
				default:
					hpv_pv_overview( $client, $counts );
			}
			?>
		</main>
	</div>
	<?php

	return (string) ob_get_clean();
}

function hpv_p_portal_login() {
	$s = hpv_p_settings();
	?>
	<div class="hpv-login">
		<div class="hpv-login__card">
			<div class="hpv-brand hpv-brand--center"><?php echo esc_html( $s['company_name'] ); ?><span><?php echo esc_html( hpv_t( 'Client Portal' ) ); ?></span></div>
			<h1><?php echo esc_html( hpv_t( 'Welcome back' ) ); ?></h1>
			<p class="hpv-muted"><?php echo esc_html( hpv_t( 'Sign in to see your projects, invoices, contracts and messages.' ) ); ?></p>
			<?php
			wp_login_form(
				array(
					'redirect'       => hpv_p_portal_url(),
					'label_username' => hpv_t( 'Email or username' ),
					'label_password' => hpv_t( 'Password' ),
					'label_remember' => hpv_t( 'Remember me' ),
					'label_log_in'   => hpv_t( 'Sign in' ),
					'remember'       => true,
				)
			);
			?>
			<p class="hpv-login__links"><a href="<?php echo esc_url( wp_lostpassword_url( hpv_p_portal_url() ) ); ?>"><?php echo esc_html( hpv_t( 'Forgot your password?' ) ); ?></a></p>
			<p class="hpv-login__help"><?php echo wp_kses( hpv_t( 'Need access? Email %s', '<a href="mailto:' . esc_attr( $s['company_email'] ) . '">' . esc_html( $s['company_email'] ) . '</a>' ), array( 'a' => array( 'href' => array() ) ) ); ?></p>
			<p class="hpv-login__lang"><a href="<?php echo esc_url( add_query_arg( 'lang', 'en' ) ); ?>" <?php echo 'en' === hpv_lang() ? 'aria-current="true"' : ''; ?>>English</a> · <a href="<?php echo esc_url( add_query_arg( 'lang', 'hu' ) ); ?>" <?php echo 'hu' === hpv_lang() ? 'aria-current="true"' : ''; ?>>Magyar</a></p>
		</div>
	</div>
	<?php
}

function hpv_p_portal_no_access() {
	$s = hpv_p_settings();
	?>
	<div class="hpv-login">
		<div class="hpv-login__card">
			<div class="hpv-brand hpv-brand--center"><?php echo esc_html( $s['company_name'] ); ?><span><?php echo esc_html( hpv_t( 'Client Portal' ) ); ?></span></div>
			<?php if ( hpv_p_is_staff() ) : ?>
				<h1><?php echo esc_html( hpv_t( 'Preview a client' ) ); ?></h1>
				<p class="hpv-muted"><?php echo esc_html( hpv_t( 'You are signed in as staff. Choose a client to preview their portal (read only).' ) ); ?></p>
				<ul class="hpv-client-picker">
					<?php foreach ( hpv_p_find( 'client', array(), array( 'orderby' => 'name', 'order' => 'ASC' ) ) as $c ) : ?>
						<li><a href="<?php echo esc_url( hpv_p_portal_url( array( 'preview_client' => $c['id'] ) ) ); ?>"><?php echo esc_html( $c['name'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<h1><?php echo esc_html( hpv_t( 'No portal access yet' ) ); ?></h1>
				<p class="hpv-muted"><?php echo wp_kses( hpv_t( 'Your account is not linked to a company. Please contact us at %s.', '<a href="mailto:' . esc_attr( $s['company_email'] ) . '">' . esc_html( $s['company_email'] ) . '</a>' ), array( 'a' => array( 'href' => array() ) ) ); ?></p>
			<?php endif; ?>
			<p class="hpv-login__links"><a href="<?php echo esc_url( wp_logout_url( hpv_p_portal_url() ) ); ?>"><?php echo esc_html( hpv_t( 'Log out' ) ); ?></a></p>
		</div>
	</div>
	<?php
}

function hpv_p_portal_header( string $title, string $subtitle = '', string $back = '' ) {
	?>
	<header class="hpv-head">
		<?php if ( $back ) : ?><a class="hpv-back" href="<?php echo esc_url( $back ); ?>"><?php echo esc_html( hpv_t( '← Back' ) ); ?></a><?php endif; ?>
		<h1><?php echo esc_html( $title ); ?></h1>
		<?php if ( $subtitle ) : ?><p class="hpv-muted"><?php echo esc_html( $subtitle ); ?></p><?php endif; ?>
	</header>
	<?php
}

/* ─── Nézetek ─────────────────────────────────────────────── */

function hpv_pv_overview( array $client, array $counts ) {
	$s        = hpv_p_settings();
	$today    = current_time( 'Y-m-d' );
	$invoices = array_filter( hpv_p_portal_invoices( (int) $client['id'] ), fn( $i ) => 'sent' === $i['status'] );
	$balance  = hpv_p_outstanding_by_currency( $invoices );
	$projects = hpv_p_portal_projects( (int) $client['id'] );
	$active   = array_filter( $projects, fn( $p ) => in_array( $p['status'], array( 'planning', 'in_progress', 'review' ), true ) );
	$subs     = array_filter( hpv_p_portal_subscriptions( (int) $client['id'] ), fn( $x ) => 'active' === $x['status'] );
	$first    = hpv_p_first_name( wp_get_current_user() );

	$todo = array();
	foreach ( hpv_video_portal_calls( (int) $client['id'] ) as $call ) {
		if ( 'live' === $call['status'] ) {
			$todo[] = array(
				'label' => hpv_t( 'Video call in progress: %s', $call['title'] ),
				'meta'  => hpv_t( 'Join now' ),
				'url'   => hpv_p_portal_link( 'meetings', array( 'id' => $call['id'] ) ),
				'alert' => true,
			);
		}
	}
	foreach ( hpv_prop_portal_list( (int) $client['id'] ) as $prop ) {
		if ( in_array( $prop['status'], array( 'sent', 'viewed' ), true ) && ! hpv_prop_is_expired( $prop ) ) {
			$todo[] = array(
				'label' => hpv_t( 'Review proposal: %s', $prop['title'] ),
				'meta'  => $prop['valid_until'] ? hpv_t( 'Valid until %s', hpv_date( $prop['valid_until'], 'short' ) ) : '',
				'url'   => hpv_prop_public_url( $prop ),
				'alert' => false,
			);
		}
	}
	$week = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
	foreach ( hpv_files_portal_list( (int) $client['id'] ) as $f ) {
		if ( 'staff' === $f['source'] && $f['created_at'] >= $week ) {
			$todo[] = array(
				'label' => hpv_t( 'New file: %s', $f['name'] ),
				'meta'  => hpv_date( $f['created_at'], 'short', true ),
				'url'   => hpv_p_portal_link( 'files' ),
				'alert' => false,
			);
		}
	}
	foreach ( $invoices as $inv ) {
		$todo[] = array(
			'label' => hpv_t( 'Pay invoice %s — %s', $inv['number'], hpv_p_money( hpv_p_invoice_balance( $inv ), hpv_p_invoice_currency( $inv ) ) ),
			'meta'  => hpv_p_invoice_is_overdue( $inv, $today ) ? hpv_t( 'Overdue since %s', hpv_date( $inv['due_date'], 'short' ) ) : ( $inv['due_date'] ? hpv_t( 'Due %s', hpv_date( $inv['due_date'], 'short' ) ) : '' ),
			'url'   => hpv_p_portal_link( 'invoices', array( 'id' => $inv['id'] ) ),
			'alert' => hpv_p_invoice_is_overdue( $inv, $today ),
		);
	}
	foreach ( hpv_p_portal_contracts( (int) $client['id'] ) as $c ) {
		if ( 'sent' === $c['status'] ) {
			$todo[] = array(
				'label' => hpv_t( 'Review & sign: %s', $c['title'] ),
				'meta'  => hpv_t( 'Awaiting your signature' ),
				'url'   => hpv_p_portal_link( 'contracts', array( 'id' => $c['id'] ) ),
				'alert' => false,
			);
		}
	}
	foreach ( $projects as $p ) {
		foreach ( hpv_p_portal_tasks( (int) $p['id'] ) as $t ) {
			if ( 'client' === $t['status'] ) {
				$todo[] = array(
					'label' => $t['title'],
					'meta'  => $p['name'] . ( $t['due_date'] ? hpv_t( ' · due %s', hpv_date( $t['due_date'], 'short' ) ) : '' ),
					'url'   => hpv_p_portal_link( 'projects', array( 'id' => $p['id'] ) ),
					'alert' => false,
				);
			}
		}
	}
	if ( $counts['messages'] ) {
		$todo[] = array(
			'label' => hpv_tn( '%d unread message', '%d unread messages', (int) $counts['messages'] ),
			'meta'  => hpv_t( 'From your HelloProVision team' ),
			'url'   => hpv_p_portal_link( 'messages' ),
			'alert' => false,
		);
	}
	?>
	<?php hpv_p_portal_header( hpv_t( 'Hi %s', $first ), $client['name'] ); ?>

	<div class="hpv-stats">
		<a class="hpv-stat" href="<?php echo esc_url( hpv_p_portal_link( 'invoices' ) ); ?>"><span><?php echo esc_html( hpv_t( 'Balance due' ) ); ?></span><strong><?php echo esc_html( hpv_p_money_multi( $balance, hpv_p_client_currency( (int) $client['id'] ) ) ); ?></strong></a>
		<a class="hpv-stat" href="<?php echo esc_url( hpv_p_portal_link( 'projects' ) ); ?>"><span><?php echo esc_html( hpv_t( 'Active projects' ) ); ?></span><strong><?php echo count( $active ); ?></strong></a>
		<a class="hpv-stat" href="<?php echo esc_url( hpv_p_portal_link( 'services' ) ); ?>"><span><?php echo esc_html( hpv_t( 'Active services' ) ); ?></span><strong><?php echo count( $subs ); ?></strong></a>
		<a class="hpv-stat" href="<?php echo esc_url( hpv_p_portal_link( 'messages' ) ); ?>"><span><?php echo esc_html( hpv_t( 'Unread messages' ) ); ?></span><strong><?php echo (int) $counts['messages']; ?></strong></a>
	</div>

	<div class="hpv-columns">
		<section class="hpv-panel">
			<h2><?php echo esc_html( hpv_t( 'Needs your attention' ) ); ?></h2>
			<?php if ( ! $todo ) : ?>
				<p class="hpv-empty"><?php echo esc_html( hpv_t( "You're all caught up. Nothing needs your attention right now." ) ); ?></p>
			<?php else : ?>
				<ul class="hpv-todo">
					<?php foreach ( $todo as $item ) : ?>
						<li class="<?php echo $item['alert'] ? 'is-alert' : ''; ?>"><a href="<?php echo esc_url( $item['url'] ); ?>"><strong><?php echo esc_html( $item['label'] ); ?></strong><span><?php echo esc_html( $item['meta'] ); ?></span></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<section class="hpv-panel">
			<h2><?php echo esc_html( hpv_t( 'Projects' ) ); ?></h2>
			<?php if ( ! $active ) : ?>
				<p class="hpv-empty"><?php echo esc_html( hpv_t( 'No active projects.' ) ); ?></p>
			<?php endif; ?>
			<?php foreach ( $active as $p ) : ?>
				<?php $progress = hpv_p_project_progress( hpv_p_portal_tasks( (int) $p['id'] ) ); ?>
				<a class="hpv-mini-project" href="<?php echo esc_url( hpv_p_portal_link( 'projects', array( 'id' => $p['id'] ) ) ); ?>">
					<span class="hpv-mini-project__top"><strong><?php echo esc_html( $p['name'] ); ?></strong><?php echo hpv_p_portal_badge( 'project', 'status', $p['status'] ); // phpcs:ignore ?></span>
					<span class="hpv-bar"><span style="width:<?php echo (int) $progress; ?>%"></span></span>
					<small><?php echo esc_html( hpv_t( '%d%% complete', (int) $progress ) . ( $p['due_date'] ? hpv_t( ' · due %s', hpv_date( $p['due_date'] ) ) : '' ) ); ?></small>
				</a>
			<?php endforeach; ?>
		</section>
	</div>

	<section class="hpv-panel">
		<h2><?php echo esc_html( hpv_t( 'Recent updates' ) ); ?></h2>
		<?php $updates = hpv_p_portal_activity( (int) $client['id'], 8 ); ?>
		<?php if ( ! $updates ) : ?>
			<p class="hpv-empty"><?php echo esc_html( hpv_t( 'Updates from our team will appear here.' ) ); ?></p>
		<?php endif; ?>
		<ol class="hpv-feed">
			<?php foreach ( $updates as $a ) : ?>
				<li><span><?php echo esc_html( $a['body'] ); ?></span><time><?php echo esc_html( hpv_date( $a['created_at'], 'date', true ) ); ?></time></li>
			<?php endforeach; ?>
		</ol>
	</section>
	<?php
}

function hpv_pv_projects( int $client_id ) {
	$projects = hpv_p_portal_projects( $client_id );
	hpv_p_portal_header( hpv_t( 'Projects' ), hpv_t( 'What we are working on for you.' ) );
	if ( ! $projects ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'No projects yet.' ) ) . '</p>';
		return;
	}
	echo '<div class="hpv-cards">';
	foreach ( $projects as $p ) {
		$progress = hpv_p_project_progress( hpv_p_portal_tasks( (int) $p['id'] ) );
		?>
		<a class="hpv-card-link" href="<?php echo esc_url( hpv_p_portal_link( 'projects', array( 'id' => $p['id'] ) ) ); ?>">
			<span class="hpv-mini-project__top"><strong><?php echo esc_html( $p['name'] ); ?></strong><?php echo hpv_p_portal_badge( 'project', 'status', $p['status'] ); // phpcs:ignore ?></span>
			<?php if ( $p['description'] ) : ?><span class="hpv-muted"><?php echo esc_html( wp_trim_words( $p['description'], 22 ) ); ?></span><?php endif; ?>
			<span class="hpv-bar"><span style="width:<?php echo (int) $progress; ?>%"></span></span>
			<small><?php echo esc_html( hpv_t( '%d%% complete', (int) $progress ) . ( $p['due_date'] ? hpv_t( ' · due %s', hpv_date( $p['due_date'] ) ) : '' ) ); ?></small>
		</a>
		<?php
	}
	echo '</div>';
}

function hpv_pv_project( int $client_id, int $id ) {
	$project = hpv_p_portal_get( 'project', $id, $client_id );
	if ( ! $project ) {
		hpv_p_portal_header( hpv_t( 'Project not found' ), '', hpv_p_portal_link( 'projects' ) );
		return;
	}
	$tasks    = hpv_p_portal_tasks( $id );
	$progress = hpv_p_project_progress( $tasks );
	hpv_p_portal_header( $project['name'], '', hpv_p_portal_link( 'projects' ) );
	?>
	<div class="hpv-panel hpv-project-meta">
		<div><span><?php echo esc_html( hpv_t( 'Status' ) ); ?></span><?php echo hpv_p_portal_badge( 'project', 'status', $project['status'] ); // phpcs:ignore ?></div>
		<div><span><?php echo esc_html( hpv_t( 'Start' ) ); ?></span><strong><?php echo hpv_p_portal_date( $project['start_date'] ); // phpcs:ignore ?></strong></div>
		<div><span><?php echo esc_html( hpv_t( 'Due' ) ); ?></span><strong><?php echo hpv_p_portal_date( $project['due_date'] ); // phpcs:ignore ?></strong></div>
		<div class="hpv-project-meta__progress"><span><?php echo esc_html( hpv_t( 'Progress' ) ); ?></span><span class="hpv-bar"><span style="width:<?php echo (int) $progress; ?>%"></span></span><strong><?php echo (int) $progress; ?>%</strong></div>
	</div>
	<?php if ( $project['description'] ) : ?>
		<div class="hpv-panel"><p><?php echo nl2br( esc_html( $project['description'] ) ); ?></p></div>
	<?php endif; ?>

	<div class="hpv-board">
		<?php foreach ( hpv_p_entity( 'task' )['fields']['status']['options'] as $status => $labels ) : ?>
			<?php $col = array_filter( $tasks, fn( $t ) => $t['status'] === $status ); ?>
			<section class="hpv-board__col hpv-board__col--<?php echo esc_attr( $status ); ?>">
				<h2><?php echo esc_html( hpv_t( $labels[1] ) ); ?> <span><?php echo count( $col ); ?></span></h2>
				<?php foreach ( $col as $t ) : ?>
					<div class="hpv-task">
						<strong><?php echo esc_html( $t['title'] ); ?></strong>
						<?php if ( $t['due_date'] ) : ?><small><?php echo esc_html( hpv_t( 'Due %s', hpv_date( $t['due_date'], 'short' ) ) ); ?></small><?php endif; ?>
					</div>
				<?php endforeach; ?>
				<?php if ( ! $col ) : ?><p class="hpv-board__empty">—</p><?php endif; ?>
			</section>
		<?php endforeach; ?>
	</div>
	<?php
}

function hpv_pv_invoices_list( int $client_id ) {
	$s        = hpv_p_settings();
	$today    = current_time( 'Y-m-d' );
	$invoices = hpv_p_portal_invoices( $client_id );
	hpv_p_portal_header( hpv_t( 'Invoices' ) );
	if ( ! $invoices ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'No invoices yet.' ) ) . '</p>';
		return;
	}
	?>
	<div class="hpv-panel hpv-table-wrap">
		<table class="hpv-list">
			<thead><tr><th><?php echo esc_html( hpv_t( 'Invoice' ) ); ?></th><th><?php echo esc_html( hpv_t( 'Issued' ) ); ?></th><th><?php echo esc_html( hpv_t( 'Due' ) ); ?></th><th class="hpv-num"><?php echo esc_html( hpv_t( 'Amount' ) ); ?></th><th><?php echo esc_html( hpv_t( 'Status' ) ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $invoices as $inv ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( hpv_p_portal_link( 'invoices', array( 'id' => $inv['id'] ) ) ); ?>"><strong><?php echo esc_html( $inv['number'] ); ?></strong></a></td>
					<td><?php echo hpv_p_portal_date( $inv['issue_date'] ); // phpcs:ignore ?></td>
					<td><?php echo hpv_p_portal_date( $inv['due_date'] ); // phpcs:ignore ?></td>
					<td class="hpv-num"><?php echo esc_html( hpv_p_money( $inv['total'], hpv_p_invoice_currency( $inv ) ) ); ?></td>
					<td><?php echo hpv_p_invoice_is_overdue( $inv, $today ) ? hpv_p_portal_badge( 'invoice', 'status', 'sent', 'overdue' ) : hpv_p_portal_badge( 'invoice', 'status', $inv['status'] ); // phpcs:ignore ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}

function hpv_pv_invoice( array $client, int $id ) {
	$invoice = hpv_p_portal_get( 'invoice', $id, (int) $client['id'] );
	if ( ! $invoice ) {
		hpv_p_portal_header( hpv_t( 'Invoice not found' ), '', hpv_p_portal_link( 'invoices' ) );
		return;
	}
	// Visszatérés a Stripe fizetőoldalról: a fizetést azonnal ellenőrizzük (a webhook is jelzi).
	if ( ! empty( $_GET['session_id'] ) && ! hpv_p_is_staff() ) {
		hpv_stripe_confirm_return( $invoice, sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) );
		$invoice = hpv_p_portal_get( 'invoice', $id, (int) $client['id'] );
	}
	$s       = hpv_p_settings();
	$cur     = hpv_p_invoice_currency( $invoice );
	$is_hu   = hpv_p_is_hu_invoice( $invoice );
	$items   = hpv_p_find( 'invoice_item', array( 'invoice_id' => $id ), array( 'orderby' => 'sort', 'order' => 'ASC' ) );
	$overdue = hpv_p_invoice_is_overdue( $invoice, current_time( 'Y-m-d' ) );
	$balance = hpv_p_invoice_balance( $invoice );
	$pay     = hpv_p_is_staff() ? '' : hpv_p_pay_url( $invoice );
	$error   = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
	?>
	<div class="hpv-doc-actions">
		<a class="hpv-back" href="<?php echo esc_url( hpv_p_portal_link( 'invoices' ) ); ?>"><?php echo esc_html( hpv_t( '← All invoices' ) ); ?></a>
		<span>
			<?php if ( $invoice['pdf_file'] ) : ?>
				<a class="hpv-btn hpv-btn--ghost" href="<?php echo esc_url( hpv_p_invoice_pdf_url( $invoice ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( hpv_t( 'Download invoice (PDF)' ) ); ?></a>
			<?php else : ?>
				<button type="button" class="hpv-btn hpv-btn--ghost" onclick="window.print()"><?php echo esc_html( hpv_t( 'Download PDF' ) ); ?></button>
			<?php endif; ?>
			<?php if ( $pay ) : ?>
				<a class="hpv-btn" href="<?php echo esc_url( $pay ); ?>"<?php echo 0 === strpos( $pay, hpv_p_portal_url() ) ? '' : ' target="_blank" rel="noopener"'; ?>><?php echo esc_html( hpv_t( 'Pay now · %s', hpv_p_money( $balance, $cur ) ) ); ?></a>
			<?php endif; ?>
		</span>
	</div>
	<?php if ( ! empty( $_GET['paid'] ) ) : ?>
		<div class="hpv-alert hpv-alert--ok"><?php echo esc_html( 'paid' === $invoice['status'] ? hpv_t( 'Payment received — thank you! A receipt is on its way to your inbox.' ) : hpv_t( 'Thank you! Your payment is being processed; this page will show it as paid within a few minutes.' ) ); ?></div>
	<?php endif; ?>
	<?php if ( $error ) : ?>
		<div class="hpv-alert hpv-alert--error"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
	<?php if ( $is_hu && $invoice['pdf_file'] ) : ?>
		<div class="hpv-alert"><?php echo esc_html( hpv_t( 'This is a summary. The official invoice is the PDF issued by Számlázz.hu (also sent to you by email).' ) ); ?></div>
	<?php endif; ?>

	<article class="hpv-paper hpv-invoice">
		<header class="hpv-invoice__head">
			<div>
				<div class="hpv-invoice__brand"><?php echo esc_html( $s['company_name'] ); ?></div>
				<?php if ( ! $is_hu ) : ?>
					<div class="hpv-muted"><?php echo esc_html( $s['company_legal'] ); ?><br><?php echo nl2br( esc_html( $s['company_address'] ) ); ?><br><?php echo esc_html( $s['company_email'] ); ?> · <?php echo esc_html( $s['company_phone'] ); ?></div>
				<?php endif; ?>
			</div>
			<div class="hpv-invoice__title">
				<h1><?php echo esc_html( hpv_t( 'Invoice' ) ); ?></h1>
				<div><?php echo esc_html( $invoice['number'] ); ?></div>
				<div><?php echo $overdue ? hpv_p_portal_badge( 'invoice', 'status', 'sent', 'overdue' ) : hpv_p_portal_badge( 'invoice', 'status', $invoice['status'] ); // phpcs:ignore ?></div>
			</div>
		</header>

		<div class="hpv-invoice__meta">
			<div><span><?php echo esc_html( hpv_t( 'Bill to' ) ); ?></span><strong><?php echo esc_html( $client['billing_name'] ?: $client['name'] ); ?></strong><div class="hpv-muted"><?php echo implode( '<br>', array_map( 'esc_html', hpv_p_client_address_lines( $client ) ) ); // phpcs:ignore ?></div></div>
			<div><span><?php echo esc_html( hpv_t( 'Issued' ) ); ?></span><strong><?php echo hpv_p_portal_date( $invoice['issue_date'] ); // phpcs:ignore ?></strong></div>
			<div><span><?php echo esc_html( hpv_t( 'Due' ) ); ?></span><strong><?php echo hpv_p_portal_date( $invoice['due_date'] ); // phpcs:ignore ?></strong></div>
			<?php if ( 'paid' === $invoice['status'] && $invoice['paid_at'] ) : ?>
				<div><span><?php echo esc_html( hpv_t( 'Paid' ) ); ?></span><strong><?php echo esc_html( hpv_date( $invoice['paid_at'], 'date', true ) ); ?></strong></div>
			<?php endif; ?>
		</div>

		<table class="hpv-invoice__items">
			<thead><tr><th><?php echo esc_html( hpv_t( 'Description' ) ); ?></th><th class="hpv-num"><?php echo esc_html( hpv_t( 'Qty' ) ); ?></th><th class="hpv-num"><?php echo esc_html( hpv_t( 'Unit price' ) ); ?></th><th class="hpv-num"><?php echo esc_html( hpv_t( 'Amount' ) ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $items as $item ) : ?>
				<tr>
					<td><?php echo esc_html( $item['description'] ); ?></td>
					<td class="hpv-num"><?php echo esc_html( rtrim( rtrim( $item['quantity'], '0' ), '.' ) ); ?></td>
					<td class="hpv-num"><?php echo esc_html( hpv_p_money( $item['unit_price'], $cur ) ); ?></td>
					<td class="hpv-num"><?php echo esc_html( hpv_p_money( $item['amount'], $cur ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr><td colspan="3"><?php echo esc_html( hpv_t( 'Subtotal' ) ); ?></td><td class="hpv-num"><?php echo esc_html( hpv_p_money( $invoice['subtotal'], $cur ) ); ?></td></tr>
				<?php if ( $is_hu ) : ?>
					<tr><td colspan="3"><?php echo esc_html( hpv_t( 'VAT (%s)', is_numeric( hpv_p_hu_vat_key( $invoice ) ) ? hpv_p_hu_vat_key( $invoice ) . '%' : hpv_p_hu_vat_key( $invoice ) ) ); ?></td><td class="hpv-num"><?php echo esc_html( hpv_p_money( $invoice['tax'], $cur ) ); ?></td></tr>
				<?php elseif ( hpv_p_to_cents( $invoice['tax'] ) ) : ?>
					<tr><td colspan="3"><?php echo esc_html( hpv_t( 'Tax (%s%%)', rtrim( rtrim( $invoice['tax_rate'], '0' ), '.' ) ) ); ?></td><td class="hpv-num"><?php echo esc_html( hpv_p_money( $invoice['tax'], $cur ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="hpv-invoice__total"><td colspan="3"><?php echo esc_html( hpv_t( 'Total' ) ); ?></td><td class="hpv-num"><?php echo esc_html( hpv_p_money( $invoice['total'], $cur ) ); ?></td></tr>
				<?php if ( hpv_p_to_cents( $invoice['paid_amount'] ) > 0 && 'paid' !== $invoice['status'] ) : ?>
					<tr><td colspan="3"><?php echo esc_html( hpv_t( 'Paid' ) ); ?></td><td class="hpv-num">−<?php echo esc_html( hpv_p_money( $invoice['paid_amount'], $cur ) ); ?></td></tr>
					<tr class="hpv-invoice__total"><td colspan="3"><?php echo esc_html( hpv_t( 'Balance due' ) ); ?></td><td class="hpv-num"><?php echo esc_html( hpv_p_money( $balance, $cur ) ); ?></td></tr>
				<?php endif; ?>
			</tfoot>
		</table>

		<?php if ( $invoice['notes'] ) : ?>
			<p class="hpv-invoice__notes"><?php echo nl2br( esc_html( $invoice['notes'] ) ); ?></p>
		<?php endif; ?>
	</article>
	<?php
}

function hpv_pv_contracts_list( int $client_id ) {
	$contracts = hpv_p_portal_contracts( $client_id );
	hpv_p_portal_header( hpv_t( 'Contracts' ), hpv_t( 'Agreements, proposals and documents to sign.' ) );
	if ( ! $contracts ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'No documents yet.' ) ) . '</p>';
		return;
	}
	echo '<div class="hpv-cards">';
	foreach ( $contracts as $c ) {
		?>
		<a class="hpv-card-link" href="<?php echo esc_url( hpv_p_portal_link( 'contracts', array( 'id' => $c['id'] ) ) ); ?>">
			<span class="hpv-mini-project__top"><strong><?php echo esc_html( $c['title'] ); ?></strong><?php echo hpv_p_portal_badge( 'contract', 'status', $c['status'] ); // phpcs:ignore ?></span>
			<small><?php echo esc_html( 'signed' === $c['status'] ? hpv_t( 'Signed %s by %s', hpv_date( $c['signed_at'], 'date', true ), $c['signer_name'] ) : hpv_t( 'Sent %s', hpv_date( $c['sent_at'], 'date', true ) ) ); ?></small>
		</a>
		<?php
	}
	echo '</div>';
}

function hpv_pv_contract( int $client_id, int $id ) {
	$contract = hpv_p_portal_get( 'contract', $id, $client_id );
	if ( ! $contract ) {
		hpv_p_portal_header( hpv_t( 'Document not found' ), '', hpv_p_portal_link( 'contracts' ) );
		return;
	}
	$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
	?>
	<div class="hpv-doc-actions">
		<a class="hpv-back" href="<?php echo esc_url( hpv_p_portal_link( 'contracts' ) ); ?>"><?php echo esc_html( hpv_t( '← All documents' ) ); ?></a>
		<button type="button" class="hpv-btn hpv-btn--ghost" onclick="window.print()"><?php echo esc_html( hpv_t( 'Download PDF' ) ); ?></button>
	</div>
	<?php if ( ! empty( $_GET['signed'] ) ) : ?>
		<div class="hpv-alert hpv-alert--ok"><?php echo esc_html( hpv_t( 'Thank you — your signature is recorded and a copy was emailed to you.' ) ); ?></div>
	<?php endif; ?>
	<?php if ( $error ) : ?>
		<div class="hpv-alert hpv-alert--error"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<article class="hpv-paper hpv-contract">
		<h1><?php echo esc_html( $contract['title'] ); ?></h1>
		<div class="hpv-contract__body"><?php echo wp_kses_post( $contract['body'] ); ?></div>

		<?php if ( 'signed' === $contract['status'] ) : ?>
			<footer class="hpv-signature">
				<div class="hpv-signature__name"><?php echo esc_html( $contract['signer_name'] ); ?></div>
				<div class="hpv-signature__meta">
					<?php echo esc_html( hpv_t( 'Signed electronically by %s (%s)', $contract['signer_name'], $contract['signer_email'] ) ); ?><br>
					<?php echo esc_html( $contract['signed_at'] ); ?> UTC · IP <?php echo esc_html( $contract['signer_ip'] ); ?><br>
					<?php echo esc_html( hpv_t( 'Document fingerprint (SHA-256):' ) ); ?> <code><?php echo esc_html( $contract['body_hash'] ); ?></code>
				</div>
			</footer>
		<?php endif; ?>
	</article>

	<?php if ( 'sent' === $contract['status'] && ! hpv_p_is_staff() ) : ?>
		<form method="post" class="hpv-panel hpv-sign">
			<h2><?php echo esc_html( hpv_t( 'Sign this document' ) ); ?></h2>
			<input type="hidden" name="hpv_portal_action" value="sign">
			<input type="hidden" name="contract_id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'hpv_sign_' . $id ); ?>
			<label class="hpv-field"><span><?php echo esc_html( hpv_t( 'Type your full name' ) ); ?></span><input type="text" name="signer_name" required autocomplete="name" value="<?php echo esc_attr( wp_get_current_user()->display_name ); ?>"></label>
			<label class="hpv-check"><input type="checkbox" name="agree" value="1" required> <span><?php echo esc_html( hpv_t( 'I have read this document and agree to sign it electronically. I understand my electronic signature is legally binding, the same as a handwritten signature.' ) ); ?></span></label>
			<button class="hpv-btn"><?php echo esc_html( hpv_t( 'Sign document' ) ); ?></button>
		</form>
	<?php elseif ( 'sent' === $contract['status'] ) : ?>
		<div class="hpv-alert"><?php echo esc_html( hpv_t( "Awaiting the client's signature." ) ); ?></div>
	<?php endif; ?>
	<?php
}

function hpv_pv_services( int $client_id ) {
	$s    = hpv_p_settings();
	$subs = hpv_p_portal_subscriptions( $client_id );
	hpv_p_portal_header( hpv_t( 'Services' ), hpv_t( 'Your active plans with us.' ) );
	if ( ! $subs ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'No active services.' ) ) . '</p>';
		return;
	}
	echo '<div class="hpv-cards">';
	foreach ( $subs as $sub ) {
		?>
		<div class="hpv-card-link hpv-service">
			<span class="hpv-mini-project__top"><strong><?php echo esc_html( $sub['name'] ); ?></strong><?php echo hpv_p_portal_badge( 'subscription', 'status', $sub['status'] ); // phpcs:ignore ?></span>
			<?php if ( $sub['description'] ) : ?><span class="hpv-muted"><?php echo nl2br( esc_html( $sub['description'] ) ); ?></span><?php endif; ?>
			<span class="hpv-service__price"><?php echo esc_html( hpv_p_money( $sub['price'], hpv_p_client_currency( $client_id ) ) ); ?> <small><?php echo esc_html( mb_strtolower( hpv_t( hpv_p_option_label( 'subscription', 'billing', $sub['billing'], 'en' ) ) ) ); ?></small></span>
			<?php if ( 'one_time' !== $sub['billing'] && $sub['next_invoice_date'] ) : ?><small><?php echo esc_html( hpv_t( 'Next invoice %s', hpv_date( $sub['next_invoice_date'] ) ) ); ?></small><?php endif; ?>
		</div>
		<?php
	}
	echo '</div>';
}

function hpv_pv_messages() {
	hpv_p_portal_header( hpv_t( 'Messages' ), hpv_t( 'Talk with your HelloProVision team.' ) );
	if ( hpv_p_is_staff() ) {
		echo '<div class="hpv-alert">Staff preview: open the conversation in the <a href="' . esc_url( admin_url( 'admin.php?page=hpv-crm-chat&client=' . absint( $_GET['preview_client'] ?? 0 ) ) ) . '">CRM chat</a>.</div>';
		return;
	}
	hpv_chat_enqueue( hpv_lang(), absint( $_GET['channel'] ?? 0 ) );
	echo '<div id="hpv-chat" class="hpv-chat hpv-chat--portal"></div>';
}

function hpv_pv_account( array $client ) {
	$user = wp_get_current_user();
	hpv_p_portal_header( hpv_t( 'Account' ) );
	?>
	<div class="hpv-columns">
		<section class="hpv-panel">
			<h2><?php echo esc_html( hpv_t( 'Company' ) ); ?></h2>
			<dl class="hpv-dl">
				<dt><?php echo esc_html( hpv_t( 'Name' ) ); ?></dt><dd><?php echo esc_html( $client['name'] ); ?></dd>
				<dt><?php echo esc_html( hpv_t( 'Email' ) ); ?></dt><dd><?php echo esc_html( $client['email'] ?: '—' ); ?></dd>
				<dt><?php echo esc_html( hpv_t( 'Phone' ) ); ?></dt><dd><?php echo esc_html( $client['phone'] ?: '—' ); ?></dd>
				<?php if ( $client['tax_number'] ) : ?><dt><?php echo esc_html( hpv_t( 'Tax number' ) ); ?></dt><dd><?php echo esc_html( $client['tax_number'] ); ?></dd><?php endif; ?>
				<dt><?php echo esc_html( hpv_t( 'Billing address' ) ); ?></dt><dd><?php echo hpv_p_client_address_lines( $client ) ? implode( '<br>', array_map( 'esc_html', hpv_p_client_address_lines( $client ) ) ) : '—'; // phpcs:ignore ?></dd>
			</dl>
			<p class="hpv-muted"><?php echo esc_html( hpv_t( "Need to change something? Send us a message and we'll update it." ) ); ?></p>
		</section>
		<section class="hpv-panel">
			<h2><?php echo esc_html( hpv_t( 'Your login' ) ); ?></h2>
			<dl class="hpv-dl">
				<dt><?php echo esc_html( hpv_t( 'Name' ) ); ?></dt><dd><?php echo esc_html( $user->display_name ); ?></dd>
				<dt><?php echo esc_html( hpv_t( 'Email' ) ); ?></dt><dd><?php echo esc_html( $user->user_email ); ?></dd>
				<dt><?php echo esc_html( hpv_t( 'Username' ) ); ?></dt><dd><?php echo esc_html( $user->user_login ); ?></dd>
			</dl>
			<p><a class="hpv-btn hpv-btn--ghost" href="<?php echo esc_url( wp_lostpassword_url( hpv_p_portal_url() ) ); ?>"><?php echo esc_html( hpv_t( 'Change password' ) ); ?></a></p>
			<h3><?php echo esc_html( hpv_t( 'People with access' ) ); ?></h3>
			<ul class="hpv-people">
				<?php foreach ( hpv_p_client_users( (int) $client['id'] ) as $u ) : ?>
					<li><span class="hpv-avatar"><?php echo esc_html( hpv_chat_user_label( $u->ID )['initials'] ); ?></span><?php echo esc_html( $u->display_name ); ?> <small><?php echo esc_html( $u->user_email ); ?></small></li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>
	<?php
}
