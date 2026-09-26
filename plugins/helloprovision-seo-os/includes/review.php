<?php
/**
 * Ügyfél-jóváhagyó oldal: https://seo.helloprovision.com/review/{token}
 *
 * Belépés nélkül, a titkos linkkel érhető el (a link az ügyfélportál e-mailjében és chatjében érkezik). Az ügyfél
 * letöltheti a dokumentumot, kérdezhet, jóváhagyhatja vagy módosítást kérhet. Az adatokat a WordPress szerveroldalon,
 * „system” aláírással kéri le; a böngésző sosem kapja meg a SEO OS API-t.
 */

defined( 'ABSPATH' ) || exit;

function hpv_seo_review_match(): ?array {
	$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	if ( preg_match( '#^/review/([A-Za-z0-9_\-]{20,80})(?:/(pdf|docx|xlsx))?/?$#', $path, $m ) ) {
		return array( 'token' => $m[1], 'file' => $m[2] ?? '' );
	}
	return null;
}

function hpv_seo_review_url( string $token, string $suffix = '' ): string {
	return hpv_seo_app_url() . 'review/' . $token . '/' . $suffix;
}

function hpv_seo_review_strings( string $lang ): array {
	$hu = array(
		'title'     => 'Dokumentum jóváhagyása',
		'intro'     => 'A HelloProVision csapata elkészítette az alábbi dokumentumot. Kérjük, nézd át, és jelezd, ha jóváhagyod, vagy ha módosítást kérsz.',
		'download'  => 'Letöltés',
		'contents'  => 'Tartalom',
		'name'      => 'Neved',
		'note'      => 'Megjegyzés',
		'note_ph'   => 'Kérdés, észrevétel vagy a kért módosítás…',
		'approve'   => 'Jóváhagyom',
		'changes'   => 'Módosítást kérek',
		'ask'       => 'Csak kérdezek',
		'approved'  => 'Köszönjük, a dokumentumot jóváhagytad.',
		'requested' => 'Köszönjük, a módosítási kérést megkaptuk – hamarosan jelentkezünk.',
		'sent'      => 'Köszönjük, az üzenetet megkaptuk.',
		'decided'   => 'Döntés',
		'history'   => 'Korábbi megjegyzések',
		'invalid'   => 'A link érvénytelen, lejárt vagy visszavonták. Kérj újat a HelloProVision csapatától.',
		'error'     => 'Hiba történt',
	);
	$en = array(
		'title'     => 'Document approval',
		'intro'     => 'The HelloProVision team has prepared the document below. Please review it and let us know whether you approve it or would like changes.',
		'download'  => 'Download',
		'contents'  => 'Contents',
		'name'      => 'Your name',
		'note'      => 'Comment',
		'note_ph'   => 'Question, feedback or the change you would like…',
		'approve'   => 'Approve',
		'changes'   => 'Request changes',
		'ask'       => 'Just a question',
		'approved'  => 'Thank you – you have approved this document.',
		'requested' => 'Thank you – we received your change request and will get back to you shortly.',
		'sent'      => 'Thank you – we received your message.',
		'decided'   => 'Decision',
		'history'   => 'Previous comments',
		'invalid'   => 'This link is invalid, expired or has been withdrawn. Please ask the HelloProVision team for a new one.',
		'error'     => 'Something went wrong',
	);
	return 'en' === $lang ? $en : $hu;
}

function hpv_seo_review_handle( array $match ) {
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	$token = $match['token'];
	$path  = 'review/' . $token;

	if ( $match['file'] ) {
		$result = hpv_seo_api( 'GET', $path . '/file/' . $match['file'], '', '', array(), HPV_SEO_SYSTEM, 60 );
		if ( is_wp_error( $result ) || 200 !== $result['status'] ) {
			status_header( 404 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: ' . $result['headers']['content-type'] );
		header( 'Content-Disposition: ' . ( $result['headers']['content-disposition'] ?? 'attachment' ) );
		echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- bináris fájl
		exit;
	}

	$flash = '';
	$error = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		$nonce_ok = wp_verify_nonce( (string) ( $_POST['_hpv_nonce'] ?? '' ), 'hpv_seo_review_' . $token );
		$rate_key = 'hpv_seo_review_' . md5( $token );
		$count    = (int) get_transient( $rate_key );
		if ( ! $nonce_ok ) {
			$error = 'nonce';
		} elseif ( $count > 20 ) {
			$error = 'rate';
		} else {
			set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );
			$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );
			$res      = hpv_seo_api_json(
				'POST',
				$path,
				array(
					'decision' => in_array( $decision, array( 'approved', 'changes_requested', 'comment' ), true ) ? $decision : 'comment',
					'name'     => sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ),
					'note'     => sanitize_textarea_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) ),
				),
				HPV_SEO_SYSTEM
			);
			if ( is_wp_error( $res ) ) {
				$error = $res->get_error_message();
			} else {
				wp_safe_redirect( add_query_arg( 'done', $decision, hpv_seo_review_url( $token ) ) );
				exit;
			}
		}
	}

	$data = hpv_seo_api_json( 'GET', $path, null, HPV_SEO_SYSTEM );
	$lang = is_array( $data ) && 'en' === ( $data['language'] ?? '' ) ? 'en' : 'hu';
	$t    = hpv_seo_review_strings( $lang );
	$done = sanitize_key( (string) ( $_GET['done'] ?? '' ) );
	if ( $done ) {
		$flash = 'approved' === $done ? $t['approved'] : ( 'changes_requested' === $done ? $t['requested'] : $t['sent'] );
	}
	status_header( is_wp_error( $data ) ? 404 : 200 );
	hpv_seo_review_render( $token, $data, $t, $lang, $flash, $error );
	exit;
}

function hpv_seo_review_render( string $token, $data, array $t, string $lang, string $flash, string $error ) {
	$accent = '#A4DA4C';
	?>
<!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $t['title'] ); ?> – HelloProVision</title>
	<style>
		:root { --ink: #16160f; --muted: #6b6b63; --line: #e4e3da; --accent: <?php echo esc_html( $accent ); ?>; }
		* { box-sizing: border-box; }
		body { margin: 0; background: #f4f4f1; color: var(--ink); font: 16px/1.55 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
		header { background: #121210; color: #f2f1ea; padding: 22px 16px; }
		.wrap { max-width: 760px; margin: 0 auto; padding: 0 16px; }
		header .brand { font-weight: 800; letter-spacing: .08em; font-size: 13px; color: var(--accent); }
		header h1 { margin: 8px 0 0; font-size: 26px; line-height: 1.2; }
		header p { margin: 6px 0 0; color: #c9c7ba; }
		main { padding: 24px 0 48px; }
		.card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 22px; margin-bottom: 16px; }
		.btns { display: flex; gap: 10px; flex-wrap: wrap; }
		.btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--ink); background: #fff; color: var(--ink); padding: 10px 16px; border-radius: 999px; font-weight: 700; text-decoration: none; cursor: pointer; font-size: 15px; }
		.btn--primary { background: var(--accent); border-color: var(--accent); }
		.btn--dark { background: var(--ink); color: #fff; }
		label { display: block; font-weight: 600; font-size: 14px; margin: 14px 0 6px; }
		input[type=text], textarea { width: 100%; border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; font: inherit; }
		textarea { min-height: 110px; }
		.muted { color: var(--muted); font-size: 14px; }
		.flash { background: #eef7dd; border: 1px solid #cfe6a3; padding: 14px 16px; border-radius: 12px; margin-bottom: 16px; font-weight: 600; }
		.err { background: #fbe9e6; border-color: #f0c4bc; }
		ol { padding-left: 20px; margin: 8px 0 0; } li { margin: 2px 0; }
		.comment { border-top: 1px solid var(--line); padding: 10px 0; } .comment:first-of-type { border-top: 0; }
	</style>
</head>
<body>
<?php if ( is_wp_error( $data ) ) : ?>
	<header><div class="wrap"><div class="brand">HELLOPROVISION</div><h1><?php echo esc_html( $t['title'] ); ?></h1></div></header>
	<main class="wrap"><div class="card"><p><?php echo esc_html( $t['invalid'] ); ?></p></div></main>
<?php else : ?>
	<header><div class="wrap">
		<div class="brand">HELLOPROVISION</div>
		<h1><?php echo esc_html( $data['type_label'] . ' – ' . $data['domain'] ); ?></h1>
		<p><?php echo esc_html( $data['client'] . ' · v' . $data['version'] ); ?></p>
	</div></header>
	<main class="wrap">
		<?php if ( $flash ) : ?><div class="flash"><?php echo esc_html( $flash ); ?></div><?php endif; ?>
		<?php if ( $error ) : ?><div class="flash err"><?php echo esc_html( $t['error'] . ( 'nonce' === $error || 'rate' === $error ? '' : ': ' . $error ) ); ?></div><?php endif; ?>
		<div class="card">
			<p><?php echo esc_html( $t['intro'] ); ?></p>
			<?php if ( $data['message'] ) : ?><p class="muted">„<?php echo esc_html( $data['message'] ); ?>”</p><?php endif; ?>
			<div class="btns">
				<?php foreach ( $data['formats'] as $fmt ) : ?>
					<a class="btn <?php echo 'pdf' === $fmt ? 'btn--dark' : ''; ?>" href="<?php echo esc_url( hpv_seo_review_url( $token, $fmt ) ); ?>">↓ <?php echo esc_html( $t['download'] . ' ' . strtoupper( $fmt ) ); ?></a>
				<?php endforeach; ?>
			</div>
			<?php if ( $data['lead'] ) : ?><p style="margin-top:18px"><?php echo esc_html( $data['lead'] ); ?></p><?php endif; ?>
			<?php if ( $data['sections'] ) : ?>
				<p class="muted" style="margin:16px 0 0"><?php echo esc_html( $t['contents'] ); ?></p>
				<ol><?php foreach ( $data['sections'] as $s ) : ?><li><?php echo esc_html( $s['title'] ); ?></li><?php endforeach; ?></ol>
			<?php endif; ?>
		</div>

		<?php if ( 'pending' !== $data['status'] ) : ?>
			<div class="card"><strong><?php echo esc_html( $t['decided'] ); ?>:</strong>
				<?php echo esc_html( 'approved' === $data['status'] ? $t['approve'] : $t['changes'] ); ?> – <?php echo esc_html( $data['decided_by'] ); ?>
				<?php if ( $data['decision_note'] ) : ?><p class="muted"><?php echo esc_html( $data['decision_note'] ); ?></p><?php endif; ?>
			</div>
		<?php endif; ?>

		<form class="card" method="post">
			<?php $nonce = wp_create_nonce( 'hpv_seo_review_' . $token ); ?>
			<input type="hidden" name="_hpv_nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<label for="name"><?php echo esc_html( $t['name'] ); ?></label>
			<input type="text" id="name" name="name" required maxlength="200">
			<label for="note"><?php echo esc_html( $t['note'] ); ?></label>
			<textarea id="note" name="note" placeholder="<?php echo esc_attr( $t['note_ph'] ); ?>" maxlength="5000"></textarea>
			<div class="btns" style="margin-top:16px">
				<?php if ( 'pending' === $data['status'] ) : ?>
					<button class="btn btn--primary" name="decision" value="approved"><?php echo esc_html( '✓ ' . $t['approve'] ); ?></button>
					<button class="btn" name="decision" value="changes_requested"><?php echo esc_html( $t['changes'] ); ?></button>
				<?php endif; ?>
				<button class="btn" name="decision" value="comment"><?php echo esc_html( $t['ask'] ); ?></button>
			</div>
		</form>

		<?php if ( $data['comments'] ) : ?>
			<div class="card"><strong><?php echo esc_html( $t['history'] ); ?></strong>
				<?php foreach ( $data['comments'] as $c ) : ?>
					<div class="comment"><span class="muted"><?php echo esc_html( $c['author'] . ' · ' . mysql2date( 'Y.m.d. H:i', get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $c['created_at'] ) ) ) ) ); ?></span><br><?php echo nl2br( esc_html( $c['body'] ) ); ?></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</main>
<?php endif; ?>
</body>
</html>
	<?php
}
