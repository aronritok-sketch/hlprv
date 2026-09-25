<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The configurator catalog, read live from Amelia.
 *
 * Structure comes from Amelia category names: "Női – Arc" means gender "Női", group "Arc".
 * Every visible service in such a category is one treatment area (1 alkalom), and every
 * visible package that holds only that service N times is its "N alkalmas bérlet" option.
 * So a new area or a new pass can be added from the Amelia admin, no code change needed.
 */
class LNR_Booking_Catalog {

	const SEPARATORS = [' – ', ' - ', ' — '];

	private static $cache = null;

	/**
	 * @return array{genders: array}
	 */
	static function get() {
		if (self::$cache === null) {
			self::$cache = self::build(LNR_Amelia::catalog_rows(), LNR_Amelia::slot_length());
		}
		return self::$cache;
	}

	static function reset() {
		self::$cache = null;
	}

	/**
	 * Splits "Női – Arc" into ['Női', 'Arc']; null for categories outside the configurator.
	 */
	static function split_category_name($name) {
		foreach (self::SEPARATORS as $separator) {
			$parts = explode($separator, $name, 2);
			if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
				return [trim($parts[0]), trim($parts[1])];
			}
		}
		return null;
	}

	/**
	 * "Bajusz (női)" → "Bajusz". The suffix only tells the areas apart in the Amelia admin.
	 */
	static function display_name($name) {
		return trim(preg_replace('/\s*\((női|férfi|noi|ferfi)\)\s*$/iu', '', $name));
	}

	static function key($label) {
		$key = strtolower(remove_accents($label));
		return trim(preg_replace('/[^a-z0-9]+/', '-', $key), '-');
	}

	/**
	 * @param array $rows        categories, services, packages, package_services, provider_services
	 *                           as lists of associative arrays (the Amelia table rows).
	 * @param int   $slot_length Amelia's time slot length in seconds.
	 */
	static function build(array $rows, $slot_length = 1800) {
		$provider_prices = [];
		foreach ($rows['provider_services'] as $row) {
			$service_id = (int)$row['serviceId'];
			$provider_prices[$service_id][(int)$row['userId']] = (float)$row['price'];
		}

		$packages = [];
		foreach ($rows['packages'] as $row) {
			if ($row['status'] !== 'visible') continue;
			$packages[(int)$row['id']] = $row;
		}

		// Packages holding exactly one service (quantity > 1) are that service's passes.
		$package_services = [];
		foreach ($rows['package_services'] as $row) {
			$package_services[(int)$row['packageId']][] = $row;
		}
		$passes = [];
		foreach ($package_services as $package_id => $items) {
			if (!isset($packages[$package_id]) || count($items) !== 1) continue;
			$quantity = (int)$items[0]['quantity'];
			if ($quantity < 2) continue;
			$service_id = (int)$items[0]['serviceId'];
			if (isset($passes[$service_id][$quantity])) continue;
			$passes[$service_id][$quantity] = [
				'n' => $quantity,
				'price' => (float)$packages[$package_id]['price'],
				'packageId' => $package_id,
			];
		}

		$services_by_category = [];
		foreach ($rows['services'] as $row) {
			if ($row['status'] !== 'visible' || (isset($row['show']) && !(int)$row['show'])) continue;
			$services_by_category[(int)$row['categoryId']][] = $row;
		}

		$categories = array_values(array_filter($rows['categories'], function ($row) {
			return $row['status'] === 'visible';
		}));
		usort($categories, function ($a, $b) {
			return [(int)$a['position'], (int)$a['id']] <=> [(int)$b['position'], (int)$b['id']];
		});

		$genders = [];
		foreach ($categories as $category) {
			$parts = self::split_category_name($category['name']);
			if (!$parts || empty($services_by_category[(int)$category['id']])) continue;
			list($gender_label, $group_label) = $parts;

			$services = $services_by_category[(int)$category['id']];
			usort($services, function ($a, $b) {
				return [(int)$a['position'], (int)$a['id']] <=> [(int)$b['position'], (int)$b['id']];
			});

			$items = [];
			foreach ($services as $service) {
				$service_id = (int)$service['id'];
				$price = !empty($provider_prices[$service_id])
					? min($provider_prices[$service_id])
					: (float)$service['price'];

				$options = [['n' => 1, 'price' => $price, 'packageId' => null]];
				if (!empty($passes[$service_id])) {
					ksort($passes[$service_id]);
					$options = array_merge($options, array_values($passes[$service_id]));
				}

				$items[] = [
					'id' => $service_id,
					'name' => self::display_name($service['name']),
					'duration' => self::booked_duration((int)$service['duration'], $slot_length),
					'options' => $options,
				];
			}

			$gender_key = self::key($gender_label);
			if (!isset($genders[$gender_key])) {
				$genders[$gender_key] = ['key' => $gender_key, 'label' => $gender_label, 'groups' => []];
			}
			$genders[$gender_key]['groups'][] = [
				'key' => self::key($group_label),
				'label' => $group_label,
				'items' => $items,
			];
		}

		return ['genders' => array_values($genders)];
	}

	/**
	 * Finds a service in the catalog, and the chosen pass when $package_id is set.
	 *
	 * @return array|null ['item' => ..., 'option' => ..., 'gender' => label, 'group' => label]
	 */
	static function find(array $catalog, $service_id, $package_id = null) {
		foreach ($catalog['genders'] as $gender) {
			foreach ($gender['groups'] as $group) {
				foreach ($group['items'] as $item) {
					if ($item['id'] !== (int)$service_id) continue;
					foreach ($item['options'] as $option) {
						if ($option['packageId'] === ($package_id ? (int)$package_id : null)) {
							return [
								'item' => $item,
								'option' => $option,
								'gender' => $gender['label'],
								'group' => $group['label'],
							];
						}
					}
					return null;
				}
			}
		}
		return null;
	}

	/**
	 * Time reserved for a service: its duration rounded up to whole Amelia time slots.
	 *
	 * Amelia starts the next free times where an appointment ends, so a 15 minute booking at
	 * 10:00 would turn a 10:30 start chosen for the next item into an invalid one by the time
	 * the order is booked. Whole slots keep every free time the configurator shows valid.
	 */
	static function booked_duration($duration, $slot_length) {
		$slot_length = max(60, (int)$slot_length);
		return (int)(ceil(max($duration, 60) / $slot_length) * $slot_length);
	}

	static function option_label($n) {
		return $n > 1 ? $n . ' alkalmas bérlet' : '1 alkalom';
	}
}
