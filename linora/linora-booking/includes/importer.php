<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Loads the agreed price list (data/pricelist.php) into Amelia: one category per gender and
 * body region, one service per area (1 alkalom), one package per pass (4 / 8 alkalom).
 * Safe to run again: existing items are matched by name and only their prices are updated.
 *
 * Eszközök → Linora árlista
 */
class LNR_Booking_Importer {

	const PAGE = 'lnr-booking-import';

	const COLORS = ['Női' => '#D4A0A7', 'Férfi' => '#8FA9BA'];

	/** Test entries on the development site that the configurator replaces. */
	const TEST_SERVICES = ['Teszt', 'Teszt 2'];
	const TEST_PACKAGES = ['Teszt 1 alkalaom', 'Teszt 4 alkalaom', 'Teszt 8 alkalaom'];
	const TEST_CATEGORIES = ['Default', 'Női', 'Férfi'];

	static function init() {
		add_action('admin_menu', function () {
			add_management_page('Linora árlista', 'Linora árlista', 'manage_options', self::PAGE, [__CLASS__, 'page']);
		});
	}

	static function pricelist() {
		return require LNR_BOOKING_DIR . '/data/pricelist.php';
	}

	static function category_name($gender, $group) {
		return $gender . ' – ' . $group;
	}

	static function service_name($gender, $name) {
		return $name . ' (' . mb_strtolower($gender) . ')';
	}

	static function package_name($service_name, $n) {
		return $service_name . ' – ' . LNR_Booking_Catalog::option_label($n);
	}

	/**
	 * @param bool $dry_run only report what would happen
	 * @param bool $update_prices set the price of existing items to the price list
	 *
	 * @return array ['rows' => [...], 'counts' => [...], 'error' => string|null]
	 */
	static function run($dry_run, $update_prices = true) {
		global $wpdb;

		$report = ['rows' => [], 'counts' => ['created' => 0, 'updated' => 0, 'unchanged' => 0], 'error' => null];

		if (!LNR_Amelia::is_active()) {
			$report['error'] = 'Az Amelia bővítmény nincs bekapcsolva.';
			return $report;
		}

		$providers = array_map('intval', $wpdb->get_col(
			'SELECT id FROM ' . LNR_Amelia::table('users') . " WHERE type = 'provider' AND status = 'visible'"
		));
		if (!$providers) {
			$report['error'] = 'Az Ameliában nincs aktív munkatárs, akihez a szolgáltatásokat hozzá lehetne rendelni.';
			return $report;
		}

		$category_position = (int)$wpdb->get_var('SELECT MAX(position) FROM ' . LNR_Amelia::table('categories'));

		foreach (self::pricelist() as $gender => $groups) {
			$color = isset(self::COLORS[$gender]) ? self::COLORS[$gender] : '#D4A0A7';

			foreach ($groups as $group => $items) {
				$category_name = self::category_name($gender, $group);
				$category_id = (int)$wpdb->get_var($wpdb->prepare(
					'SELECT id FROM ' . LNR_Amelia::table('categories') . ' WHERE name = %s ORDER BY id LIMIT 1',
					$category_name
				));
				if (!$category_id && !$dry_run) {
					$wpdb->insert(LNR_Amelia::table('categories'), [
						'status' => 'visible',
						'name' => $category_name,
						'position' => ++$category_position,
						'color' => $color,
					]);
					$category_id = (int)$wpdb->insert_id;
				} elseif ($category_id && !$dry_run) {
					$wpdb->update(LNR_Amelia::table('categories'), ['status' => 'visible'], ['id' => $category_id]);
				}

				foreach ($items as $position => $row) {
					list($name, $minutes, $prices) = $row;
					$service_name = self::service_name($gender, $name);

					$line = [
						'gender' => $gender,
						'group' => $group,
						'name' => $name,
						'minutes' => $minutes,
						'prices' => $prices,
						'status' => [],
					];

					$service = $category_id ? $wpdb->get_row($wpdb->prepare(
						'SELECT id, price FROM ' . LNR_Amelia::table('services') . ' WHERE name = %s AND categoryId = %d ORDER BY id LIMIT 1',
						$service_name,
						$category_id
					), ARRAY_A) : null;

					$service_id = $service ? (int)$service['id'] : 0;
					$line['status'][1] = self::apply($report, $dry_run, $service, $prices[1], $update_prices,
						function () use ($wpdb, $service_name, $prices, $category_id, $minutes, $position, $color) {
							$wpdb->insert(LNR_Amelia::table('services'), [
								'name' => $service_name,
								'description' => '',
								'color' => $color,
								'price' => $prices[1],
								'status' => 'visible',
								'categoryId' => $category_id,
								'minCapacity' => 1,
								'maxCapacity' => 1,
								'duration' => $minutes * 60,
								'timeBefore' => 0,
								'timeAfter' => 0,
								'bringingAnyone' => 0,
								'priority' => 'least_expensive',
								'position' => $position + 1,
								'show' => 1,
								'aggregatedPrice' => 1,
								'recurringCycle' => 'disabled',
								'recurringSub' => 'future',
								'recurringPayment' => 0,
								'depositPayment' => 'disabled',
								'depositPerPerson' => 1,
								'deposit' => 0,
								'fullPayment' => 0,
								'mandatoryExtra' => 0,
								'minSelectedExtras' => 0,
								'customPricing' => '{"enabled":null,"durations":{},"persons":{},"periods":{"default":[],"custom":[]}}',
							]);
							return (int)$wpdb->insert_id;
						},
						function ($id) use ($wpdb, $prices) {
							$wpdb->update(LNR_Amelia::table('services'), ['price' => $prices[1]], ['id' => $id]);
							$wpdb->update(LNR_Amelia::table('providers_to_services'), ['price' => $prices[1]], ['serviceId' => $id]);
						}
					);
					if (!$service_id && !$dry_run) {
						$service_id = (int)$wpdb->get_var($wpdb->prepare(
							'SELECT id FROM ' . LNR_Amelia::table('services') . ' WHERE name = %s AND categoryId = %d ORDER BY id LIMIT 1',
							$service_name,
							$category_id
						));
					}

					if ($service_id && !$dry_run) {
						foreach ($providers as $provider_id) {
							$assigned = $wpdb->get_var($wpdb->prepare(
								'SELECT id FROM ' . LNR_Amelia::table('providers_to_services') . ' WHERE userId = %d AND serviceId = %d',
								$provider_id,
								$service_id
							));
							if (!$assigned) {
								$wpdb->insert(LNR_Amelia::table('providers_to_services'), [
									'userId' => $provider_id,
									'serviceId' => $service_id,
									'price' => $prices[1],
									'minCapacity' => 1,
									'maxCapacity' => 1,
									'customPricing' => '{"enabled":null,"durations":[],"persons":[],"periods":{"default":[],"custom":[]}}',
								]);
							}
						}
					}

					foreach ($prices as $n => $price) {
						if ($n < 2) continue;

						$package_name = self::package_name($service_name, $n);
						$package = $wpdb->get_row($wpdb->prepare(
							'SELECT id, price FROM ' . LNR_Amelia::table('packages') . ' WHERE name = %s ORDER BY id LIMIT 1',
							$package_name
						), ARRAY_A);

						$line['status'][$n] = self::apply($report, $dry_run, $package, $price, $update_prices,
							function () use ($wpdb, $package_name, $price, $color, $position, $service_id, $n, $providers) {
								$wpdb->insert(LNR_Amelia::table('packages'), [
									'name' => $package_name,
									'description' => '',
									'color' => $color,
									'price' => $price,
									'status' => 'visible',
									'position' => $position + 1,
									'calculatedPrice' => 0,
									'discount' => 0,
									'depositPayment' => 'disabled',
									'deposit' => 0,
									'fullPayment' => 0,
									'sharedCapacity' => 0,
									'quantity' => 1,
								]);
								$package_id = (int)$wpdb->insert_id;

								// The first appointment is booked with the purchase, the rest later.
								$wpdb->insert(LNR_Amelia::table('packages_to_services'), [
									'serviceId' => $service_id,
									'packageId' => $package_id,
									'quantity' => $n,
									'minimumScheduled' => 1,
									'maximumScheduled' => 1,
									'allowProviderSelection' => 1,
									'position' => 1,
								]);
								$package_service_id = (int)$wpdb->insert_id;

								foreach ($providers as $provider_id) {
									$wpdb->insert(LNR_Amelia::table('packages_services_to_providers'), [
										'packageServiceId' => $package_service_id,
										'userId' => $provider_id,
									]);
								}
								return $package_id;
							},
							function ($id) use ($wpdb, $price) {
								$wpdb->update(LNR_Amelia::table('packages'), ['price' => $price, 'calculatedPrice' => 0], ['id' => $id]);
							}
						);
					}

					$report['rows'][] = $line;
				}
			}
		}

		if (!$dry_run) {
			self::hide_test_data();
			LNR_Amelia::ensure_settings();
			LNR_Booking_Catalog::reset();
		}

		return $report;
	}

	/**
	 * Creates or updates one service or package and counts it.
	 *
	 * @return string new | update | ok
	 */
	private static function apply(array &$report, $dry_run, $existing, $price, $update_prices, callable $create, callable $update) {
		if (!$existing) {
			if (!$dry_run) $create();
			$report['counts']['created']++;
			return 'new';
		}

		if ($update_prices && (float)$existing['price'] !== (float)$price) {
			if (!$dry_run) $update((int)$existing['id']);
			$report['counts']['updated']++;
			return 'update';
		}

		$report['counts']['unchanged']++;
		return 'ok';
	}

	private static function hide_test_data() {
		global $wpdb;

		foreach (self::TEST_SERVICES as $name) {
			$wpdb->update(LNR_Amelia::table('services'), ['status' => 'hidden'], ['name' => $name]);
		}
		foreach (self::TEST_PACKAGES as $name) {
			$wpdb->update(LNR_Amelia::table('packages'), ['status' => 'hidden'], ['name' => $name]);
		}
		foreach (self::TEST_CATEGORIES as $name) {
			$visible = (int)$wpdb->get_var($wpdb->prepare(
				'SELECT COUNT(*) FROM ' . LNR_Amelia::table('services') . ' s JOIN ' . LNR_Amelia::table('categories')
				. " c ON c.id = s.categoryId WHERE c.name = %s AND s.status = 'visible'",
				$name
			));
			if (!$visible) {
				$wpdb->update(LNR_Amelia::table('categories'), ['status' => 'hidden'], ['name' => $name]);
			}
		}
	}

	static function page() {
		if (!current_user_can('manage_options')) return;

		$result = null;
		if (isset($_POST['lnr_import']) && check_admin_referer('lnr_booking_import')) {
			$result = self::run(false, !empty($_POST['update_prices']));
		}
		$preview = self::run(true, true);

		$labels = ['new' => 'új', 'update' => 'ár frissül', 'ok' => 'rendben'];
		?>
		<div class="wrap">
			<h1>Linora árlista → Amelia</h1>

			<?php if ($result && $result['error']): ?>
				<div class="notice notice-error"><p><?php echo esc_html($result['error']); ?></p></div>
			<?php elseif ($result): ?>
				<div class="notice notice-success"><p><?php echo esc_html(sprintf(
					'Kész: %d új, %d frissített, %d változatlan tétel.',
					$result['counts']['created'], $result['counts']['updated'], $result['counts']['unchanged']
				)); ?></p></div>
			<?php endif; ?>

			<p>
				A megegyezett lézeres árlistát tölti be az Ameliába: nemenként és testtájanként egy kategória
				(pl. <em>Női – Arc</em>), területenként egy szolgáltatás (1 alkalom), és a 4 / 8 alkalmas bérletek csomagként.
				Többször is lefuttatható: a meglévő tételeket név alapján felismeri, újakat nem hoz létre belőlük.
			</p>
			<p>
				A kezelési időket (perc) importálás után az Ameliában lehet pontosítani, az újraimportálás nem írja felül őket.
				A konfigurátor mindig az Amelia aktuális adataiból dolgozik.
			</p>

			<?php if ($preview['error']): ?>
				<div class="notice notice-error"><p><?php echo esc_html($preview['error']); ?></p></div>
			<?php else: ?>
				<form method="post">
					<?php wp_nonce_field('lnr_booking_import'); ?>
					<p>
						<label><input type="checkbox" name="update_prices" value="1" checked> Meglévő tételek árának frissítése az árlista szerint</label>
					</p>
					<p>
						<button class="button button-primary" name="lnr_import" value="1">Importálás az Ameliába</button>
						<span class="description"><?php echo esc_html(sprintf(
							'%d új, %d árváltozás, %d változatlan.',
							$preview['counts']['created'], $preview['counts']['updated'], $preview['counts']['unchanged']
						)); ?></span>
					</p>
				</form>

				<table class="widefat striped" style="max-width: 960px">
					<thead><tr><th>Kategória</th><th>Terület</th><th>Idő</th><th>1 alkalom</th><th>4 alkalom</th><th>8 alkalom</th></tr></thead>
					<tbody>
					<?php foreach ($preview['rows'] as $row): ?>
						<tr>
							<td><?php echo esc_html(self::category_name($row['gender'], $row['group'])); ?></td>
							<td><?php echo esc_html($row['name']); ?></td>
							<td><?php echo esc_html($row['minutes'] . ' perc'); ?></td>
							<?php foreach ([1, 4, 8] as $n): ?>
								<td>
									<?php if (isset($row['prices'][$n])): ?>
										<?php echo esc_html(number_format_i18n($row['prices'][$n]) . ' Ft'); ?>
										<small>(<?php echo esc_html($labels[$row['status'][$n]]); ?>)</small>
									<?php else: ?>–<?php endif; ?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
