<?php

if (!defined('ABSPATH')) {
	exit;
}

use AmeliaBooking\Application\Commands\CommandResult;

/**
 * Thin bridge to Amelia: reads its tables and runs its own commands through its command bus,
 * the same handlers Amelia's booking form reaches through admin-ajax.
 */
class LNR_Amelia {

	private static $container = null;

	/** Amelia's booking error flags, in the configurator's words. */
	const ERRORS = [
		'timeSlotUnavailable' => 'ez az időpont közben foglalt lett. Kérjük, válasszon másikat.',
		'customerAlreadyBooked' => 'erre az időpontra már van foglalása.',
		'customerBlocked' => 'az online foglalás ezzel az e-mail címmel nem lehetséges. Kérjük, hívjon minket telefonon.',
		'emailError' => 'kérjük, ellenőrizze az e-mail címet.',
		'phoneError' => 'kérjük, ellenőrizze a telefonszámot.',
	];

	static function is_active() {
		return defined('AMELIA_PATH') && class_exists('AmeliaBooking\Plugin');
	}

	static function table($name) {
		global $wpdb;
		return $wpdb->prefix . 'amelia_' . $name;
	}

	static function catalog_rows() {
		global $wpdb;

		if (!self::is_active()) {
			return ['categories' => [], 'services' => [], 'packages' => [], 'package_services' => [], 'provider_services' => []];
		}

		return [
			'categories' => $wpdb->get_results('SELECT id, name, status, position FROM ' . self::table('categories'), ARRAY_A),
			'services' => $wpdb->get_results('SELECT id, name, price, status, categoryId, duration, position, `show` FROM ' . self::table('services'), ARRAY_A),
			'packages' => $wpdb->get_results('SELECT id, name, price, status FROM ' . self::table('packages'), ARRAY_A),
			'package_services' => $wpdb->get_results('SELECT packageId, serviceId, quantity FROM ' . self::table('packages_to_services'), ARRAY_A),
			'provider_services' => $wpdb->get_results(
				'SELECT ps.userId, ps.serviceId, ps.price FROM ' . self::table('providers_to_services') . ' ps'
				. ' JOIN ' . self::table('users') . " u ON u.id = ps.userId AND u.type = 'provider' AND u.status = 'visible'",
				ARRAY_A
			),
		];
	}

	static function slot_length() {
		$settings = json_decode((string)get_option('amelia_settings'), true);
		return !empty($settings['general']['timeSlotLength']) ? (int)$settings['general']['timeSlotLength'] : 1800;
	}

	private static function container() {
		if (self::$container === null) {
			self::$container = require AMELIA_PATH . '/src/Infrastructure/ContainerConfig/container.php';
		}
		return self::$container;
	}

	/**
	 * @return CommandResult
	 */
	private static function run($command_class, array $fields) {
		$container = self::container();

		$command = new $command_class([]);
		foreach ($fields as $name => $value) {
			$command->setField($name, $value);
		}
		$command->setPermissionService($container->getPermissionsService());
		$command->setUserApplicationService($container->getUserApplicationService());

		return $container->getCommandBus()->handle($command);
	}

	/**
	 * Free start times of a service between two dates (site time zone).
	 *
	 * @return array ['Y-m-d' => ['H:i' => providerId]]
	 */
	static function slots($service_id, $duration, $from, $to) {
		$result = self::run('AmeliaBooking\Application\Commands\Booking\Appointment\GetTimeSlotsCommand', [
			'serviceId' => (int)$service_id,
			'serviceDuration' => (int)$duration,
			'locationId' => 0,
			'locationIds' => [],
			'providerIds' => [],
			'extras' => [],
			'excludeAppointmentId' => null,
			'persons' => 1,
			'group' => 0,
			'page' => 'booking',
			'monthsLoad' => 0,
			'startDateTime' => $from . ' 00:00',
			'endDateTime' => $to . ' 23:59',
			'queryTimeZone' => '',
			'timeZone' => '',
			'structured' => null,
		]);

		if ($result->getResult() !== CommandResult::RESULT_SUCCESS) {
			throw new RuntimeException((string)$result->getMessage());
		}

		$slots = [];
		foreach ($result->getData()['slots'] as $date => $times) {
			foreach ($times as $time => $providers) {
				// [[providerId, locationId], ...]; the first one is the one Amelia would pick.
				$slots[$date][$time] = (int)$providers[0][0];
			}
		}
		return $slots;
	}

	/**
	 * Puts one appointment or pass into the WooCommerce cart through Amelia's WooCommerce
	 * payment command, so Amelia prices it, validates the time and books it with the order.
	 *
	 * @return true|string true, or Amelia's error message
	 */
	static function add_to_wc_cart(array $item, array $customer) {
		$booking = [
			'customerId' => 0,
			'customer' => [
				'id' => null,
				'firstName' => $customer['firstName'],
				'lastName' => $customer['lastName'],
				'email' => $customer['email'],
				'phone' => $customer['phone'],
				'countryPhoneIso' => 'hu',
			],
			'customFields' => [],
			'extras' => [],
			'persons' => 1,
			'duration' => (int)$item['duration'],
			'utcOffset' => null,
			'deposit' => false,
		];

		$fields = [
			'type' => $item['packageId'] ? 'package' : 'appointment',
			'bookings' => [$booking],
			'bookingStart' => $item['start'],
			'notifyParticipants' => 1,
			'serviceId' => (int)$item['serviceId'],
			'providerId' => (int)$item['providerId'],
			'locationId' => null,
			'couponCode' => '',
			'payment' => ['gateway' => 'wc', 'amount' => 0, 'data' => []],
			'recurring' => [],
			'package' => [],
			'isCart' => false,
			'deposit' => false,
			'utcOffset' => null,
			'locale' => get_locale(),
			'timeZone' => wp_timezone_string(),
			'componentProps' => ['appointment' => ['bookings' => [['customFields' => []]]]],
			'returnUrl' => home_url('/'),
		];

		if ($item['packageId']) {
			$fields['packageId'] = (int)$item['packageId'];
			$fields['package'] = [[
				'serviceId' => (int)$item['serviceId'],
				'providerId' => (int)$item['providerId'],
				'locationId' => null,
				'bookingStart' => $item['start'],
				'notifyParticipants' => 1,
				'utcOffset' => null,
			]];
			$fields['packageRules'] = [[
				'serviceId' => (int)$item['serviceId'],
				'providerId' => (int)$item['providerId'],
				'locationId' => null,
			]];
		}

		$result = self::run('AmeliaBooking\Application\Commands\PaymentGateway\WooCommercePaymentCommand', $fields);

		if ($result->getResult() === CommandResult::RESULT_SUCCESS) {
			return true;
		}

		$data = (array)$result->getData();
		foreach (self::ERRORS as $key => $message) {
			if (!empty($data[$key])) return $message;
		}
		$message = !empty($data['message']) ? $data['message'] : $result->getMessage();
		return $message ?: 'Ismeretlen hiba';
	}

	/**
	 * The configurator puts several Amelia items in one WooCommerce cart, which Amelia only
	 * allows with the "book multiple" WooCommerce setting on.
	 */
	static function ensure_settings() {
		$settings = json_decode((string)get_option('amelia_settings'), true);
		if (!is_array($settings) || !isset($settings['payments']['wc'])) return;

		if (empty($settings['payments']['wc']['bookMultiple'])) {
			$settings['payments']['wc']['bookMultiple'] = true;
			update_option('amelia_settings', json_encode($settings));
		}
	}
}
