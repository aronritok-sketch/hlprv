<?php
/**
 * Fájlmegosztás: az ügyfél és a csapat fájljai (ügyfélhez vagy projekthez kötve).
 *
 * A tartalom a privát mappában (uploads/hpv-private/files/<ügyfél>/) van, véletlen névvel; letölteni csak
 * a ?hpv_file=<id> végponton lehet, jogosultság-ellenőrzés után (munkatárs, vagy az ügyfél saját, látható fájlja).
 * Az ügyfél feltöltéséről a csapat, a csapat látható feltöltéséről az ügyfél kap e-mailt.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tiltott kiterjesztések akkor is, ha a WordPress engedné (futtatható vagy böngészőben szkriptet futtató fájlok).
 */
const HPV_FILES_BLOCKED = array( 'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat', 'cmd', 'com', 'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'swf', 'htaccess' );

/**
 * Böngészőben megnyitható típusok (a többi mindig letöltésként megy).
 */
const HPV_FILES_INLINE = array( 'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

function hpv_files_max_size(): int {
	return (int) min( wp_max_upload_size(), apply_filters( 'hpv_files_max_size', 100 * MB_IN_BYTES ) );
}

function hpv_files_path( array $file ): string {
	// Csak a saját formátumunk (files/<ügyfél>/<32 hex>.<kit>) — egy kézzel átírt útvonal sem vezethet ki a mappából.
	if ( ! preg_match( '#^files/\d+/[a-f0-9]{32}\.[a-z0-9]{1,10}$#', (string) $file['storage_key'] ) ) {
		return '';
	}

	return hpv_p_private_dir() . '/' . $file['storage_key'];
}

function hpv_files_unlink( int $id ): void {
	$file = hpv_p_get( 'file', $id );
	$path = $file ? hpv_files_path( $file ) : '';
	if ( $path && is_file( $path ) ) {
		wp_delete_file( $path );
	}
}

function hpv_files_url( array $file ): string {
	return add_query_arg( 'hpv_file', (int) $file['id'], home_url( '/' ) );
}

function hpv_files_can_view( array $file, int $user_id ): bool {
	if ( hpv_p_is_staff( $user_id ) ) {
		return true;
	}

	return (int) $file['visible'] && hpv_p_user_client_id( $user_id ) === (int) $file['client_id'];
}

/**
 * Feltöltés mentése.
 *
 * @param array $upload Egy $_FILES elem (name, tmp_name, size, error).
 * @param array $meta   client_id, project_id, note, visible, source (staff|client), user_id.
 * @return array|WP_Error A fájl sora.
 */
function hpv_files_store( array $upload, array $meta ) {
	$client_id = (int) ( $meta['client_id'] ?? 0 );
	if ( ! $client_id || ! hpv_p_get( 'client', $client_id ) ) {
		return new WP_Error( 'client', 'Client not found.' );
	}
	$project_id = (int) ( $meta['project_id'] ?? 0 );
	if ( $project_id ) {
		$project = hpv_p_get( 'project', $project_id );
		if ( ! $project || (int) $project['client_id'] !== $client_id ) {
			return new WP_Error( 'project', 'Project not found.' );
		}
	}

	$error = (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE );
	if ( UPLOAD_ERR_NO_FILE === $error || empty( $upload['tmp_name'] ) ) {
		return new WP_Error( 'empty', hpv_t( 'Please choose a file.' ) );
	}
	if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || (int) $upload['size'] > hpv_files_max_size() ) {
		return new WP_Error( 'size', hpv_t( 'This file is too large (max. %s).', size_format( hpv_files_max_size() ) ) );
	}
	$tmp = (string) $upload['tmp_name'];
	if ( UPLOAD_ERR_OK !== $error || ! apply_filters( 'hpv_files_is_uploaded', is_uploaded_file( $tmp ), $tmp ) ) {
		return new WP_Error( 'upload', hpv_t( 'The upload failed. Please try again.' ) );
	}

	$name  = sanitize_file_name( wp_basename( (string) $upload['name'] ) );
	$check = wp_check_filetype_and_ext( $tmp, $name, hpv_files_mimes() );
	$ext   = strtolower( (string) ( $check['ext'] ?: '' ) );
	if ( ! $ext || in_array( $ext, HPV_FILES_BLOCKED, true ) ) {
		return new WP_Error( 'type', hpv_t( 'This file type is not allowed.' ) );
	}
	if ( ! empty( $check['proper_filename'] ) ) {
		$name = $check['proper_filename'];
	}

	$rel = 'files/' . $client_id . '/' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
	$abs = hpv_p_private_dir() . '/' . $rel;
	wp_mkdir_p( dirname( $abs ) );
	$moved = is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $abs ) : copy( $tmp, $abs ); // a copy csak a teszt szűrőjével érhető el
	if ( ! $moved ) {
		return new WP_Error( 'upload', hpv_t( 'The upload failed. Please try again.' ) );
	}

	$id = hpv_p_insert(
		'file',
		array(
			'client_id'   => $client_id,
			'project_id'  => $project_id ?: null,
			'name'        => mb_substr( $name, 0, 190 ),
			'storage_key' => $rel,
			'mime'        => (string) $check['type'],
			'size'        => (int) filesize( $abs ),
			'note'        => mb_substr( sanitize_text_field( (string) ( $meta['note'] ?? '' ) ), 0, 250 ),
			'visible'     => 'client' === ( $meta['source'] ?? '' ) || ! empty( $meta['visible'] ) ? 1 : 0,
			'source'      => 'client' === ( $meta['source'] ?? '' ) ? 'client' : 'staff',
			'uploaded_by' => (int) ( $meta['user_id'] ?? 0 ),
		)
	);
	$file = $id ? hpv_p_get( 'file', $id ) : null;
	if ( ! $file ) {
		wp_delete_file( $abs );
		return new WP_Error( 'db', hpv_t( 'The upload failed. Please try again.' ) );
	}
	hpv_files_notify( $file, ! empty( $meta['notify'] ) || 'client' === $file['source'] );

	return $file;
}

/**
 * Engedélyezett típusok: a WordPress listája (a felhasználótól függetlenül), a tiltottak nélkül.
 */
function hpv_files_mimes(): array {
	$mimes = wp_get_mime_types();
	foreach ( $mimes as $exts => $type ) {
		if ( array_intersect( explode( '|', $exts ), HPV_FILES_BLOCKED ) ) {
			unset( $mimes[ $exts ] );
		}
	}

	return $mimes;
}

function hpv_files_notify( array $file, bool $email ): void {
	$client_id = (int) $file['client_id'];
	$who       = hpv_chat_user_label( (int) $file['uploaded_by'] )['name'];
	$project   = $file['project_id'] ? hpv_p_get( 'project', (int) $file['project_id'] ) : null;

	if ( 'client' === $file['source'] ) {
		hpv_p_log_client( $client_id, '%s uploaded a file: %s', array( $who, $file['name'] ), (int) $file['uploaded_by'] );
		hpv_p_notify_staff(
			sprintf( 'Új fájl: %s — %s', $file['name'], hpv_p_client_name_safe( $client_id ) ),
			sprintf(
				'<p><strong>%s</strong> (%s) feltöltött egy fájlt%s: <strong>%s</strong> (%s).</p>%s',
				esc_html( $who ),
				esc_html( hpv_p_client_name_safe( $client_id ) ),
				$project ? ' a(z) <em>' . esc_html( $project['name'] ) . '</em> projekthez' : '',
				esc_html( $file['name'] ),
				esc_html( size_format( (int) $file['size'] ) ),
				$file['note'] ? '<p>' . esc_html( $file['note'] ) . '</p>' : ''
			),
			hpv_p_crm_app_url( '/files?client=' . $client_id )
		);
		return;
	}
	if ( ! (int) $file['visible'] ) {
		return;
	}
	hpv_p_log_client( $client_id, '%s shared a file: %s', array( $who, $file['name'] ), (int) $file['uploaded_by'] );
	if ( $email ) {
		hpv_with_client_lang(
			$client_id,
			fn() => hpv_p_notify_client(
				$client_id,
				hpv_t( 'New file: %s', $file['name'] ),
				hpv_t( 'New file: %s', $file['name'] ),
				'<p>' . hpv_t( '%s shared a file with you: <strong>%s</strong>', esc_html( $who ), esc_html( $file['name'] ) ) . '</p>' . ( $file['note'] ? '<p>' . esc_html( $file['note'] ) . '</p>' : '' ),
				hpv_t( 'Open files' ),
				hpv_p_portal_url( array( 'view' => 'files' ) )
			)
		);
	}
}

/**
 * A fájl adatai a CRM-nek és a portálnak.
 */
function hpv_files_format( array $f ): array {
	$project = $f['project_id'] ? hpv_p_get( 'project', (int) $f['project_id'] ) : null;

	return array(
		'id'         => (int) $f['id'],
		'client_id'  => (int) $f['client_id'],
		'project_id' => (int) $f['project_id'],
		'project'    => $project ? $project['name'] : '',
		'name'       => $f['name'],
		'mime'       => $f['mime'],
		'size'       => (int) $f['size'],
		'size_label' => size_format( (int) $f['size'] ),
		'note'       => (string) $f['note'],
		'visible'    => (bool) (int) $f['visible'],
		'source'     => $f['source'],
		'by'         => hpv_chat_user_label( (int) $f['uploaded_by'] )['name'],
		'created_at' => $f['created_at'],
		'created_ts' => (int) strtotime( $f['created_at'] . ' UTC' ),
		'url'        => hpv_files_url( $f ),
	);
}

/* ─── Letöltés ────────────────────────────────────────────── */

add_action( 'init', 'hpv_files_serve', 5 );

function hpv_files_serve() {
	if ( empty( $_GET['hpv_file'] ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	$file = hpv_p_get( 'file', absint( $_GET['hpv_file'] ) );
	$path = $file ? hpv_files_path( $file ) : '';
	if ( ! $file || ! hpv_files_can_view( $file, get_current_user_id() ) || ! $path || ! is_readable( $path ) ) {
		hpv_set_lang( hpv_doc_client_language( hpv_p_user_client_id( get_current_user_id() ) ) );
		status_header( 404 );
		nocache_headers();
		wp_die( esc_html( hpv_t( 'File not found.' ) ), '', array( 'response' => 404 ) );
	}

	$inline = in_array( $file['mime'], HPV_FILES_INLINE, true ) && empty( $_GET['download'] );
	nocache_headers();
	header( 'Content-Type: ' . ( $file['mime'] ?: 'application/octet-stream' ) );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( array( '"', '\\' ), '', remove_accents( $file['name'] ) ) . '"; filename*=UTF-8\'\'' . rawurlencode( $file['name'] ) );
	header( 'X-Content-Type-Options: nosniff' );
	if ( 'application/pdf' !== $file['mime'] ) {
		// A PDF-nézőt a Chrome homokozóban nem indítja el; minden más típus homokozóban, szkript nélkül.
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
	}
	if ( ob_get_level() ) {
		ob_end_clean();
	}
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/* ─── REST (munkatársak) ──────────────────────────────────── */

add_action( 'rest_api_init', 'hpv_files_routes' );

function hpv_files_routes() {
	$staff = fn() => hpv_p_is_staff();
	register_rest_route(
		'hpv/v1',
		'/files',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $staff,
				'callback'            => 'hpv_files_rest_list',
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $staff,
				'callback'            => 'hpv_files_rest_upload',
			),
		)
	);
	register_rest_route(
		'hpv/v1',
		'/files/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'POST',
				'permission_callback' => $staff,
				'callback'            => 'hpv_files_rest_update',
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $staff,
				'callback'            => 'hpv_files_rest_delete',
			),
		)
	);
}

function hpv_files_rest_list( WP_REST_Request $req ) {
	$where = array();
	if ( $req['client_id'] ) {
		$where['client_id'] = absint( $req['client_id'] );
	}
	if ( $req['project_id'] ) {
		$where['project_id'] = absint( $req['project_id'] );
	}

	return rest_ensure_response(
		array(
			'files'    => array_map( 'hpv_files_format', hpv_p_find( 'file', $where, array( 'limit' => 500 ) ) ),
			'max'      => hpv_files_max_size(),
			'maxLabel' => size_format( hpv_files_max_size() ),
		)
	);
}

function hpv_files_rest_upload( WP_REST_Request $req ) {
	$files = $req->get_file_params();
	$file  = hpv_files_store(
		(array) ( $files['file'] ?? array() ),
		array(
			'client_id'  => absint( $req['client_id'] ),
			'project_id' => absint( $req['project_id'] ),
			'note'       => (string) $req['note'],
			'visible'    => rest_sanitize_boolean( $req['visible'] ?? true ),
			'notify'     => rest_sanitize_boolean( $req['notify'] ?? true ),
			'source'     => 'staff',
			'user_id'    => get_current_user_id(),
		)
	);
	if ( is_wp_error( $file ) ) {
		return new WP_Error( $file->get_error_code(), $file->get_error_message(), array( 'status' => 400 ) );
	}

	return rest_ensure_response( hpv_files_format( $file ) );
}

function hpv_files_rest_update( WP_REST_Request $req ) {
	$file = hpv_p_get( 'file', (int) $req['id'] );
	if ( ! $file ) {
		return new WP_Error( 'not_found', 'Nincs ilyen fájl.', array( 'status' => 404 ) );
	}
	$data = array();
	if ( null !== $req['note'] ) {
		$data['note'] = mb_substr( sanitize_text_field( (string) $req['note'] ), 0, 250 );
	}
	if ( null !== $req['project_id'] ) {
		$pid = absint( $req['project_id'] );
		$p   = $pid ? hpv_p_get( 'project', $pid ) : null;
		if ( $pid && ( ! $p || (int) $p['client_id'] !== (int) $file['client_id'] ) ) {
			return new WP_Error( 'project', 'A projekt nem ehhez az ügyfélhez tartozik.', array( 'status' => 400 ) );
		}
		$data['project_id'] = $pid ?: null;
	}
	$became_visible = false;
	if ( null !== $req['visible'] ) {
		$data['visible'] = rest_sanitize_boolean( $req['visible'] ) ? 1 : 0;
		$became_visible  = $data['visible'] && ! (int) $file['visible'];
	}
	if ( $data ) {
		hpv_p_update( 'file', (int) $file['id'], $data );
	}
	$file = hpv_p_get( 'file', (int) $file['id'] );
	if ( $became_visible && 'staff' === $file['source'] ) {
		hpv_files_notify( $file, rest_sanitize_boolean( $req['notify'] ?? false ) );
	}

	return rest_ensure_response( hpv_files_format( $file ) );
}

function hpv_files_rest_delete( WP_REST_Request $req ) {
	$file = hpv_p_get( 'file', (int) $req['id'] );
	if ( ! $file ) {
		return new WP_Error( 'not_found', 'Nincs ilyen fájl.', array( 'status' => 404 ) );
	}
	hpv_p_delete( 'file', (int) $file['id'] );

	return rest_ensure_response( array( 'deleted' => true ) );
}

/* ─── Portál ──────────────────────────────────────────────── */

add_action( 'init', 'hpv_files_portal_post', 20 );

function hpv_files_portal_post() {
	$action = sanitize_key( $_POST['hpv_portal_action'] ?? '' );
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! in_array( $action, array( 'upload_file', 'delete_file' ), true ) || ! is_user_logged_in() ) {
		return;
	}
	check_admin_referer( 'hpv_files' );
	$back      = hpv_p_portal_url( array( 'view' => 'files' ) );
	$client_id = hpv_p_user_client_id( get_current_user_id() );
	if ( hpv_p_is_staff() || ! $client_id ) {
		wp_safe_redirect( $back );
		exit;
	}
	hpv_set_lang( hpv_doc_client_language( $client_id ) );

	if ( 'delete_file' === $action ) {
		$file = hpv_p_get( 'file', absint( $_POST['file_id'] ?? 0 ) );
		if ( ! $file || (int) $file['client_id'] !== $client_id || 'client' !== $file['source'] || (int) $file['uploaded_by'] !== get_current_user_id() ) {
			wp_safe_redirect( add_query_arg( 'error', rawurlencode( hpv_t( 'You can only delete files you uploaded.' ) ), $back ) );
			exit;
		}
		hpv_p_delete( 'file', (int) $file['id'] );
		wp_safe_redirect( add_query_arg( 'deleted', 1, $back ) );
		exit;
	}

	$project_id = absint( $_POST['project_id'] ?? 0 );
	if ( $project_id && ! hpv_p_portal_get( 'project', $project_id, $client_id ) ) {
		$project_id = 0; // csak az ügyfél látható projektjéhez tölthet fel
	}
	// A POST túl nagy (post_max_size): a PHP üres $_FILES-t ad.
	$upload = $_FILES['file'] ?? array( 'error' => empty( $_POST ) ? UPLOAD_ERR_INI_SIZE : UPLOAD_ERR_NO_FILE ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$file   = hpv_files_store(
		(array) $upload,
		array(
			'client_id'  => $client_id,
			'project_id' => $project_id,
			'note'       => wp_unslash( (string) ( $_POST['note'] ?? '' ) ),
			'source'     => 'client',
			'user_id'    => get_current_user_id(),
		)
	);
	if ( is_wp_error( $file ) ) {
		wp_safe_redirect( add_query_arg( 'error', rawurlencode( $file->get_error_message() ), $back ) );
		exit;
	}
	wp_safe_redirect( add_query_arg( 'uploaded', 1, $back ) );
	exit;
}

function hpv_files_portal_list( int $client_id ): array {
	$visible = array_map( 'intval', wp_list_pluck( hpv_p_portal_projects( $client_id ), 'id' ) );
	$files   = hpv_p_find( 'file', array( 'client_id' => $client_id, 'visible' => 1 ), array( 'limit' => 500 ) );

	// Belső (az ügyfél elől rejtett) projekt fájljai nem látszanak.
	return array_values( array_filter( $files, fn( $f ) => ! (int) $f['project_id'] || in_array( (int) $f['project_id'], $visible, true ) ) );
}

function hpv_pv_files( int $client_id ) {
	$files    = hpv_files_portal_list( $client_id );
	$projects = hpv_p_portal_projects( $client_id );
	$error    = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
	$me       = get_current_user_id();
	hpv_p_portal_header( hpv_t( 'Files' ), hpv_t( 'Documents and assets shared between you and our team.' ) );

	if ( ! empty( $_GET['uploaded'] ) ) {
		echo '<div class="hpv-alert hpv-alert--ok">' . esc_html( hpv_t( 'File uploaded. Our team has been notified.' ) ) . '</div>';
	}
	if ( ! empty( $_GET['deleted'] ) ) {
		echo '<div class="hpv-alert hpv-alert--ok">' . esc_html( hpv_t( 'File deleted.' ) ) . '</div>';
	}
	if ( $error ) {
		echo '<div class="hpv-alert hpv-alert--error">' . esc_html( $error ) . '</div>';
	}

	if ( ! hpv_p_is_staff() ) :
		?>
		<form method="post" enctype="multipart/form-data" class="hpv-panel hpv-upload">
			<h2><?php echo esc_html( hpv_t( 'Upload a file' ) ); ?></h2>
			<input type="hidden" name="hpv_portal_action" value="upload_file">
			<?php wp_nonce_field( 'hpv_files' ); ?>
			<div class="hpv-upload__row">
				<label class="hpv-field hpv-upload__file"><span><?php echo esc_html( hpv_t( 'File' ) ); ?></span><input type="file" name="file" required></label>
				<?php if ( $projects ) : ?>
					<label class="hpv-field"><span><?php echo esc_html( hpv_t( 'Project (optional)' ) ); ?></span>
						<select name="project_id">
							<option value="0"><?php echo esc_html( hpv_t( '— General —' ) ); ?></option>
							<?php foreach ( $projects as $p ) : ?><option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html( $p['name'] ); ?></option><?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<label class="hpv-field"><span><?php echo esc_html( hpv_t( 'Note (optional)' ) ); ?></span><input type="text" name="note" maxlength="250"></label>
			</div>
			<p class="hpv-muted"><?php echo esc_html( hpv_t( 'Max. %s per file.', size_format( hpv_files_max_size() ) ) ); ?></p>
			<button class="hpv-btn"><?php echo esc_html( hpv_t( 'Upload' ) ); ?></button>
		</form>
		<?php
	endif;

	if ( ! $files ) {
		echo '<p class="hpv-empty hpv-panel">' . esc_html( hpv_t( 'No files yet.' ) ) . '</p>';
		return;
	}
	$names = wp_list_pluck( $projects, 'name', 'id' );
	?>
	<div class="hpv-panel hpv-table-wrap">
		<table class="hpv-list hpv-files">
			<thead><tr><th><?php echo esc_html( hpv_t( 'File' ) ); ?></th><th><?php echo esc_html( hpv_t( 'From' ) ); ?></th><th><?php echo esc_html( hpv_t( 'Added' ) ); ?></th><th class="hpv-num"><?php echo esc_html( hpv_t( 'Size' ) ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $files as $f ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( hpv_files_url( $f ) ); ?>" target="_blank" rel="noopener"><strong><?php echo esc_html( $f['name'] ); ?></strong></a>
						<?php if ( $f['project_id'] && isset( $names[ $f['project_id'] ] ) ) : ?><small class="hpv-muted"> · <?php echo esc_html( $names[ $f['project_id'] ] ); ?></small><?php endif; ?>
						<?php if ( $f['note'] ) : ?><br><small class="hpv-muted"><?php echo esc_html( $f['note'] ); ?></small><?php endif; ?>
					</td>
					<td><?php echo esc_html( 'client' === $f['source'] ? hpv_chat_user_label( (int) $f['uploaded_by'] )['name'] : hpv_p_settings()['company_name'] ); ?></td>
					<td><?php echo esc_html( hpv_date( $f['created_at'], 'date', true ) ); ?></td>
					<td class="hpv-num"><?php echo esc_html( size_format( (int) $f['size'] ) ); ?></td>
					<td class="hpv-num hpv-files__actions">
						<a href="<?php echo esc_url( add_query_arg( 'download', 1, hpv_files_url( $f ) ) ); ?>"><?php echo esc_html( hpv_t( 'Download' ) ); ?></a>
						<?php if ( ! hpv_p_is_staff() && 'client' === $f['source'] && (int) $f['uploaded_by'] === $me ) : ?>
							<form method="post" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( hpv_t( 'Delete this file?' ) ) ); ?>)">
								<input type="hidden" name="hpv_portal_action" value="delete_file">
								<input type="hidden" name="file_id" value="<?php echo (int) $f['id']; ?>">
								<?php wp_nonce_field( 'hpv_files' ); ?>
								<button class="hpv-link-btn"><?php echo esc_html( hpv_t( 'Delete' ) ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
