<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The two admin-ajax endpoints of the configurator (free times, and moving the chosen items
 * into the WooCommerce cart), plus the cart and order labels of those items.
 */
class LNR_Booking_Checkout {

	const MAX_ITEMS = 10;

	/** Label of the item being added, picked up by tag_cart_item(). */
	private static $current_label = null;

	static function ajax_slots() {
		$service_id = isset($_GET['service']) ? absint($_GET['service']) : 0;
		$month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : '';

		$found = LNR_Booking_Catalog::find(LNR_Booking_Catalog::get(), $service_id);
		if (!$found || !preg_match('/^(\d{4})-(\d{2})$/', $month, $m) || !checkdate((int)$m[2], 1, (int)$m[1])) {
			wp_send_json_error(['message' => 'Érvénytelen kérés.'], 400);
		}

		$first = new DateTimeImmutable($month . '-01', wp_timezone());
		$now = new DateTimeImmutable('now', wp_timezone());
		if ($first > $now->modify('+13 months') || $first < $now->modify('first day of this month')->setTime(0, 0)) {
			wp_send_json_success(['slots' => new stdClass()]);
		}

		try {
			$slots = LNR_Amelia::slots(
				$service_id,
				$found['item']['duration'],
				$first->format('Y-m-d'),
				$first->modify('last day of this month')->format('Y-m-d')
			);
		} catch (Throwable $e) {
			wp_send_json_error(['message' => 'Az időpontokat most nem sikerült betölteni. Kérjük, próbálja újra.'], 500);
		}

		nocache_headers();
		wp_send_json_success(['slots' => $slots ?: new stdClass()]);
	}

	/**
	 * No nonce on purpose: the form sits on cacheable public pages, and the request can only
	 * fill the visitor's own cart. Amelia validates every item again before it books.
	 */
	static function ajax_checkout() {
		$payload = isset($_POST['payload']) ? json_decode(wp_unslash($_POST['payload']), true) : null;
		if (!is_array($payload)) {
			wp_send_json_error(['message' => 'Érvénytelen kérés.'], 400);
		}

		$customer = self::validate_customer(isset($payload['customer']) ? $payload['customer'] : null);
		if (is_string($customer)) {
			wp_send_json_error(['message' => $customer], 400);
		}

		$items = self::validate_items(
			isset($payload['items']) ? $payload['items'] : null,
			LNR_Booking_Catalog::get(),
			new DateTimeImmutable('now', wp_timezone())
		);
		if (isset($items['error'])) {
			wp_send_json_error($items['error'], 400);
		}

		if (!function_exists('WC') || !WC()->cart || !LNR_Amelia::is_active()) {
			wp_send_json_error(['message' => 'Az online foglalás most nem elérhető. Kérjük, hívjon minket telefonon.'], 503);
		}

		// The cart mirrors the configurator: earlier booking items go, other products stay.
		self::remove_booking_items();

		foreach ($items as $item) {
			self::$current_label = $item['label'];
			$added = LNR_Amelia::add_to_wc_cart($item, $customer);
			self::$current_label = null;

			if ($added !== true) {
				self::remove_booking_items();
				wp_send_json_error([
					'uid' => $item['uid'],
					'message' => sprintf('%s: %s', $item['label'], wp_strip_all_tags($added)),
				], 409);
			}
		}

		wp_send_json_success(['redirect' => wc_get_checkout_url()]);
	}

	/**
	 * @return array|string the cleaned customer, or an error message
	 */
	static function validate_customer($customer) {
		if (!is_array($customer)) return 'Kérjük, adja meg az adatait.';

		$clean = [];
		foreach (['lastName', 'firstName', 'email', 'phone'] as $field) {
			$clean[$field] = isset($customer[$field]) && is_string($customer[$field]) ? trim(sanitize_text_field($customer[$field])) : '';
		}

		if ($clean['lastName'] === '' || $clean['firstName'] === '') return 'Kérjük, adja meg a nevét.';
		if (!is_email($clean['email'])) return 'Kérjük, adjon meg egy érvényes e-mail címet.';
		if (!preg_match('/^\+?[0-9 ()\/-]{7,20}$/', $clean['phone'])) return 'Kérjük, adjon meg egy érvényes telefonszámot.';

		$clean['email'] = sanitize_email($clean['email']);
		return $clean;
	}

	/**
	 * Checks the client's items against the catalog and each other. Prices are not taken from
	 * the client: Amelia prices every item itself.
	 *
	 * @return array the items, or ['error' => ['message' => ..., 'uid' => ...]]
	 */
	static function validate_items($items, array $catalog, DateTimeImmutable $now) {
		if (!is_array($items) || !$items) {
			return ['error' => ['message' => 'Válasszon legalább egy kezelést.']];
		}
		if (count($items) > self::MAX_ITEMS) {
			return ['error' => ['message' => sprintf('Egyszerre legfeljebb %d kezelés foglalható.', self::MAX_ITEMS)]];
		}

		$clean = [];
		foreach (array_values($items) as $index => $item) {
			$uid = isset($item['uid']) ? substr(preg_replace('/[^a-z0-9]/i', '', (string)$item['uid']), 0, 32) : (string)$index;
			$service_id = isset($item['serviceId']) ? (int)$item['serviceId'] : 0;
			$package_id = !empty($item['packageId']) ? (int)$item['packageId'] : null;
			$start = isset($item['start']) ? (string)$item['start'] : '';

			$found = LNR_Booking_Catalog::find($catalog, $service_id, $package_id);
			if (!$found) {
				return ['error' => ['uid' => $uid, 'message' => 'Az egyik kiválasztott kezelés már nem elérhető. Kérjük, válassza ki újra.']];
			}

			$start_time = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $start)
				? DateTimeImmutable::createFromFormat('Y-m-d H:i', $start, $now->getTimezone())
				: false;
			if (
				!$start_time || $start_time->format('Y-m-d H:i') !== $start || $start_time <= $now ||
				empty($item['providerId'])
			) {
				return ['error' => ['uid' => $uid, 'message' => sprintf('%s: kérjük, válasszon új időpontot.', $found['item']['name'])]];
			}

			$clean[] = [
				'uid' => $uid,
				'serviceId' => $service_id,
				'packageId' => $package_id,
				'providerId' => isset($item['providerId']) ? absint($item['providerId']) : 0,
				'start' => $start,
				'duration' => $found['item']['duration'],
				'begin' => $start_time->getTimestamp(),
				'end' => $start_time->getTimestamp() + $found['item']['duration'],
				'label' => sprintf(
					'%s (%s) – %s',
					$found['item']['name'],
					mb_strtolower($found['gender']),
					LNR_Booking_Catalog::option_label($found['option']['n'])
				),
			];
		}

		$sorted = $clean;
		usort($sorted, function ($a, $b) {
			return $a['begin'] <=> $b['begin'];
		});
		for ($i = 1; $i < count($sorted); $i++) {
			if ($sorted[$i]['begin'] < $sorted[$i - 1]['end']) {
				return ['error' => [
					'uid' => $sorted[$i]['uid'],
					'message' => sprintf('%s időpontja ütközik egy másik kezelésével. Kérjük, válasszon másik időpontot.', $sorted[$i]['label']),
				]];
			}
		}

		return $clean;
	}

	private static function remove_booking_items() {
		foreach (WC()->cart->get_cart() as $key => $cart_item) {
			if (isset($cart_item['ameliabooking'])) {
				WC()->cart->remove_cart_item($key);
			}
		}
	}

	static function tag_cart_item($data) {
		if (self::$current_label !== null && is_array($data)) {
			$data['lnrLabel'] = self::$current_label;
		}
		return $data;
	}

	static function cart_item_name($name, $cart_item) {
		if (!empty($cart_item['ameliabooking']['lnrLabel'])) {
			return esc_html($cart_item['ameliabooking']['lnrLabel']);
		}
		return $name;
	}

	static function order_item_name($item, $cart_item_key, $values) {
		if (!empty($values['ameliabooking']['lnrLabel'])) {
			$item->set_name($values['ameliabooking']['lnrLabel']);
		}
	}

	/**
	 * After the order the configurator starts empty again.
	 */
	static function clear_client_cart() {
		echo '<script>try{sessionStorage.removeItem("lnrBooking")}catch(e){}</script>';
	}
}
