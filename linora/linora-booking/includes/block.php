<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The "Időpontfoglaló" block, registered with iucb_add_block. It can sit on any page
 * (homepage booking section) and in the header's booking modal at the same time; the
 * instances share one basket.
 */
class LNR_Booking_Block {

	const NAME = 'linora/booking';

	private static $data_printed = false;

	static function register() {
		if (!function_exists('iucb_add_block')) return;

		iucb_add_block(self::NAME, [
			'title' => 'Időpontfoglaló (konfigurátor)',
			'description' => 'Kezelés összeállítása és időpontfoglalás: nem, terület, alkalmak, időpont. Az időpontok az Ameliából jönnek, a fizetés WooCommerce-szel történik.',
			'category' => 'widgets',
			'icon' => 'calendar-alt',
			'attributes' => [
				'gender' => [
					'type' => 'string',
					'default' => '',
				],
			],
			'fields' => [
				[
					'panel' => 'Beállítások',
					'fields' => [
						'gender' => [
							'type' => 'select',
							'label' => 'Kezdő lépés',
							'options' => [
								['label' => 'Nem kiválasztása', 'value' => ''],
								['label' => 'Női kezelések', 'value' => 'noi'],
								['label' => 'Férfi kezelések', 'value' => 'ferfi'],
							],
						],
					],
				],
			],
			'editJS' => '
				return el("div", {
					style: { padding: "2rem", border: "1px dashed var(--wp--preset--color--border, #ccc)", textAlign: "center", background: "var(--wp--preset--color--bg-light, #fbf6f2)" }
				},
					el("strong", {}, "Időpontfoglaló konfigurátor"),
					el("p", { style: { margin: "0.5rem 0 0" } }, "Nem → terület → alkalmak → időpont → összegzés. Az oldalon jelenik meg.")
				);
			',
			'saveJS' => 'return null;',
			'template' => [__CLASS__, 'render'],
		]);
	}

	static function register_assets() {
		wp_register_style('lnr-booking', plugins_url('assets/booking.css', LNR_BOOKING_FILE), [], LNR_BOOKING_VERSION);
		wp_register_script('lnr-booking', plugins_url('assets/booking.js', LNR_BOOKING_FILE), [], LNR_BOOKING_VERSION, true);
	}

	static function render($attributes) {
		$gender = isset($attributes['gender']) ? sanitize_key($attributes['gender']) : '';

		if (!is_admin() && !wp_is_serving_rest_request()) {
			self::enqueue();
		}

		ob_start();
		?>
		<div class="lnr-booking" data-lnr-booking data-gender="<?php echo esc_attr($gender); ?>">
			<noscript>Az online foglaláshoz engedélyezze a JavaScriptet, vagy hívjon minket telefonon.</noscript>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function enqueue() {
		if (!wp_style_is('lnr-booking', 'registered')) {
			self::register_assets();
		}
		wp_enqueue_style('lnr-booking');
		wp_enqueue_script('lnr-booking');

		if (self::$data_printed) return;
		self::$data_printed = true;

		$now = new DateTimeImmutable('now', wp_timezone());
		wp_add_inline_script('lnr-booking', 'window.lnrBookingData = ' . wp_json_encode([
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'catalog' => LNR_Booking_Catalog::get(),
			'today' => $now->format('Y-m-d'),
			'now' => $now->format('Y-m-d H:i'),
		]) . ';', 'before');
	}
}
