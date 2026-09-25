<?php
/**
 * Adatmodell: minden entitás egy helyen leírva. Ebből készül az adatbázis-tábla,
 * az admin űrlap, a lista és a bemenet-tisztítás.
 *
 * Mezőtípusok: text, textarea, html, email, url, tel, select, date, datetime, money,
 * decimal, int, bool, ref (másik entitásra mutat).
 * Státusz opciók: érték => [magyar címke (admin), angol címke (portál)].
 */

defined( 'ABSPATH' ) || exit;

function hpv_p_entities(): array {
	static $entities = null;
	if ( null !== $entities ) {
		return $entities;
	}

	$billing = array(
		'one_time'  => array( 'Egyszeri', 'One-time' ),
		'monthly'   => array( 'Havi', 'Monthly' ),
		'quarterly' => array( 'Negyedéves', 'Quarterly' ),
		'yearly'    => array( 'Éves', 'Yearly' ),
	);

	$entities = array(
		'client'       => array(
			'table'    => 'clients',
			'label'    => 'Ügyfelek',
			'singular' => 'Ügyfél',
			'fields'   => array(
				'name'         => array( 'type' => 'text', 'label' => 'Cégnév', 'required' => true, 'list' => true ),
				'contact_name' => array( 'type' => 'text', 'label' => 'Kapcsolattartó', 'list' => true ),
				'email'        => array( 'type' => 'email', 'label' => 'E-mail', 'list' => true ),
				'phone'        => array( 'type' => 'tel', 'label' => 'Telefon' ),
				'website'      => array( 'type' => 'url', 'label' => 'Weboldal' ),
				'address'      => array( 'type' => 'textarea', 'label' => 'Számlázási cím' ),
				'status'       => array(
					'type'    => 'select',
					'label'   => 'Státusz',
					'list'    => true,
					'default' => 'active',
					'options' => array(
						'lead'   => array( 'Érdeklődő', 'Prospect' ),
						'active' => array( 'Aktív', 'Active' ),
						'paused' => array( 'Szünetel', 'Paused' ),
						'former' => array( 'Korábbi', 'Former' ),
					),
				),
				'notes'        => array( 'type' => 'textarea', 'label' => 'Belső megjegyzés', 'help' => 'Csak a csapat látja.' ),
			),
		),

		'service'      => array(
			'table'    => 'services',
			'label'    => 'Szolgáltatás-katalógus',
			'singular' => 'Szolgáltatás',
			'fields'   => array(
				'name'        => array( 'type' => 'text', 'label' => 'Név (angolul, az ügyfél is látja)', 'required' => true, 'list' => true ),
				'description' => array( 'type' => 'textarea', 'label' => 'Leírás (angolul)' ),
				'price'       => array( 'type' => 'money', 'label' => 'Alapár (USD)', 'list' => true ),
				'billing'     => array( 'type' => 'select', 'label' => 'Számlázás', 'list' => true, 'default' => 'monthly', 'options' => $billing ),
				'active'      => array( 'type' => 'bool', 'label' => 'Aktív (választható)', 'default' => 1, 'list' => true ),
			),
		),

		'subscription' => array(
			'table'    => 'subscriptions',
			'label'    => 'Előfizetések',
			'singular' => 'Előfizetés',
			'parent'   => 'client',
			'fields'   => array(
				'client_id'         => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'service_id'        => array( 'type' => 'ref', 'ref' => 'service', 'label' => 'Katalógus szolgáltatás' ),
				'name'              => array( 'type' => 'text', 'label' => 'Megnevezés (angolul)', 'required' => true, 'list' => true ),
				'description'       => array( 'type' => 'textarea', 'label' => 'Mit tartalmaz (angolul, az ügyfél látja)' ),
				'price'             => array( 'type' => 'money', 'label' => 'Ár (USD)', 'list' => true ),
				'billing'           => array( 'type' => 'select', 'label' => 'Számlázás', 'list' => true, 'default' => 'monthly', 'options' => $billing ),
				'status'            => array(
					'type'    => 'select',
					'label'   => 'Státusz',
					'list'    => true,
					'default' => 'active',
					'options' => array(
						'active'    => array( 'Aktív', 'Active' ),
						'paused'    => array( 'Szünetel', 'Paused' ),
						'cancelled' => array( 'Lemondva', 'Cancelled' ),
					),
				),
				'start_date'        => array( 'type' => 'date', 'label' => 'Kezdés' ),
				'next_invoice_date' => array( 'type' => 'date', 'label' => 'Következő számla', 'list' => true ),
			),
		),

		'project'      => array(
			'table'    => 'projects',
			'label'    => 'Projektek',
			'singular' => 'Projekt',
			'parent'   => 'client',
			'fields'   => array(
				'client_id'   => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'name'        => array( 'type' => 'text', 'label' => 'Projekt neve (angolul)', 'required' => true, 'list' => true ),
				'description' => array( 'type' => 'textarea', 'label' => 'Leírás (angolul, az ügyfél látja)' ),
				'status'      => array(
					'type'    => 'select',
					'label'   => 'Státusz',
					'list'    => true,
					'default' => 'planning',
					'options' => array(
						'planning'    => array( 'Tervezés', 'Planning' ),
						'in_progress' => array( 'Folyamatban', 'In progress' ),
						'review'      => array( 'Ügyfél jóváhagyásra vár', 'Awaiting your review' ),
						'completed'   => array( 'Kész', 'Completed' ),
						'on_hold'     => array( 'Felfüggesztve', 'On hold' ),
					),
				),
				'start_date'  => array( 'type' => 'date', 'label' => 'Kezdés' ),
				'due_date'    => array( 'type' => 'date', 'label' => 'Határidő', 'list' => true ),
				'visible'     => array( 'type' => 'bool', 'label' => 'Látja az ügyfél', 'default' => 1 ),
			),
		),

		'task'         => array(
			'table'    => 'tasks',
			'label'    => 'Feladatok',
			'singular' => 'Feladat',
			'parent'   => 'project',
			'fields'   => array(
				'project_id' => array( 'type' => 'ref', 'ref' => 'project', 'label' => 'Projekt', 'required' => true ),
				'title'      => array( 'type' => 'text', 'label' => 'Feladat (angolul)', 'required' => true, 'list' => true ),
				'status'     => array(
					'type'    => 'select',
					'label'   => 'Státusz',
					'list'    => true,
					'default' => 'todo',
					'options' => array(
						'todo'        => array( 'Teendő', 'To do' ),
						'in_progress' => array( 'Folyamatban', 'In progress' ),
						'client'      => array( 'Ügyfélre vár', 'Waiting on you' ),
						'done'        => array( 'Kész', 'Done' ),
					),
				),
				'due_date'   => array( 'type' => 'date', 'label' => 'Határidő', 'list' => true ),
				'visible'    => array( 'type' => 'bool', 'label' => 'Látja az ügyfél', 'default' => 1, 'list' => true ),
				'sort'       => array( 'type' => 'int', 'label' => 'Sorrend', 'default' => 0 ),
			),
		),

		'invoice'      => array(
			'table'    => 'invoices',
			'label'    => 'Számlák',
			'singular' => 'Számla',
			'parent'   => 'client',
			'fields'   => array(
				'client_id'   => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'number'      => array( 'type' => 'text', 'label' => 'Számlaszám', 'list' => true, 'readonly' => true, 'help' => 'Mentéskor automatikusan kap számot.' ),
				'status'      => array(
					'type'    => 'select',
					'label'   => 'Státusz',
					'list'    => true,
					'default' => 'draft',
					'options' => array(
						'draft' => array( 'Piszkozat (az ügyfél nem látja)', 'Draft' ),
						'sent'  => array( 'Kiküldve', 'Due' ),
						'paid'  => array( 'Fizetve', 'Paid' ),
						'void'  => array( 'Érvénytelen', 'Void' ),
					),
				),
				'issue_date'  => array( 'type' => 'date', 'label' => 'Kiállítás', 'list' => true ),
				'due_date'    => array( 'type' => 'date', 'label' => 'Fizetési határidő', 'list' => true ),
				'tax_rate'    => array( 'type' => 'decimal', 'label' => 'Adó (%)', 'default' => '0' ),
				'subtotal'    => array( 'type' => 'money', 'label' => 'Nettó', 'readonly' => true ),
				'tax'         => array( 'type' => 'money', 'label' => 'Adó', 'readonly' => true ),
				'total'       => array( 'type' => 'money', 'label' => 'Végösszeg', 'readonly' => true, 'list' => true ),
				'notes'       => array( 'type' => 'textarea', 'label' => 'Megjegyzés a számlán (angolul)' ),
				'payment_url' => array( 'type' => 'url', 'label' => 'Fizetési link', 'help' => 'Pl. Stripe Payment Link vagy QuickBooks számla link. A portálon „Pay now” gombként jelenik meg.' ),
				'sent_at'     => array( 'type' => 'datetime', 'label' => 'Kiküldve', 'readonly' => true ),
				'paid_at'     => array( 'type' => 'datetime', 'label' => 'Fizetve', 'readonly' => true ),
			),
		),

		'invoice_item' => array(
			'table'    => 'invoice_items',
			'label'    => 'Számlatételek',
			'singular' => 'Számlatétel',
			'parent'   => 'invoice',
			'fields'   => array(
				'invoice_id'  => array( 'type' => 'ref', 'ref' => 'invoice', 'label' => 'Számla', 'required' => true ),
				'description' => array( 'type' => 'text', 'label' => 'Tétel', 'required' => true ),
				'quantity'    => array( 'type' => 'decimal', 'label' => 'Mennyiség', 'default' => '1' ),
				'unit_price'  => array( 'type' => 'money', 'label' => 'Egységár' ),
				'amount'      => array( 'type' => 'money', 'label' => 'Összeg', 'readonly' => true ),
				'sort'        => array( 'type' => 'int', 'label' => 'Sorrend', 'default' => 0 ),
			),
		),

		'contract'     => array(
			'table'    => 'contracts',
			'label'    => 'Szerződések',
			'singular' => 'Szerződés',
			'parent'   => 'client',
			'fields'   => array(
				'client_id'    => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'title'        => array( 'type' => 'text', 'label' => 'Cím (angolul)', 'required' => true, 'list' => true ),
				'body'         => array( 'type' => 'html', 'label' => 'Szerződés szövege (angolul)', 'required' => true ),
				'status'       => array(
					'type'    => 'select',
					'label'   => 'Státusz',
					'list'    => true,
					'default' => 'draft',
					'options' => array(
						'draft'  => array( 'Piszkozat (az ügyfél nem látja)', 'Draft' ),
						'sent'   => array( 'Aláírásra vár', 'Awaiting signature' ),
						'signed' => array( 'Aláírva', 'Signed' ),
						'void'   => array( 'Visszavonva', 'Void' ),
					),
				),
				'sent_at'      => array( 'type' => 'datetime', 'label' => 'Kiküldve', 'readonly' => true, 'list' => true ),
				'signed_at'    => array( 'type' => 'datetime', 'label' => 'Aláírva', 'readonly' => true, 'list' => true ),
				'signer_name'  => array( 'type' => 'text', 'label' => 'Aláíró neve', 'readonly' => true ),
				'signer_email' => array( 'type' => 'email', 'label' => 'Aláíró e-mail', 'readonly' => true ),
				'signer_ip'    => array( 'type' => 'text', 'label' => 'Aláíró IP', 'readonly' => true ),
				'signer_agent' => array( 'type' => 'text', 'label' => 'Aláíró böngésző', 'readonly' => true ),
				'body_hash'    => array( 'type' => 'text', 'label' => 'Dokumentum lenyomat (SHA-256)', 'readonly' => true ),
			),
		),

		'channel'      => array(
			'table'    => 'chat_channels',
			'label'    => 'Chat csatornák',
			'singular' => 'Csatorna',
			'parent'   => 'client',
			'fields'   => array(
				'name'        => array( 'type' => 'text', 'label' => 'Név', 'required' => true, 'list' => true ),
				'description' => array( 'type' => 'text', 'label' => 'Leírás' ),
				'type'        => array(
					'type'    => 'select',
					'label'   => 'Típus',
					'default' => 'internal',
					'options' => array(
						'internal' => array( 'Belső csoport', 'Team' ),
						'client'   => array( 'Ügyfél csatorna', 'Your team at HelloProVision' ),
					),
				),
				'client_id'   => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél' ),
				'created_by'  => array( 'type' => 'int', 'label' => 'Létrehozta' ),
				'archived'    => array( 'type' => 'bool', 'label' => 'Archivált', 'default' => 0 ),
			),
		),

		'channel_member' => array(
			'table'    => 'chat_members',
			'label'    => 'Csatorna tagok',
			'singular' => 'Tag',
			'parent'   => 'channel',
			'fields'   => array(
				'channel_id' => array( 'type' => 'ref', 'ref' => 'channel', 'label' => 'Csatorna', 'required' => true ),
				'user_id'    => array( 'type' => 'ref', 'ref' => 'user', 'label' => 'Felhasználó', 'required' => true ),
				'last_read'  => array( 'type' => 'int', 'label' => 'Utoljára olvasott üzenet' ),
				'seen_at'    => array( 'type' => 'int', 'label' => 'Utoljára nézte (unix idő)' ),
				'emailed_at' => array( 'type' => 'int', 'label' => 'Utolsó e-mail értesítés (unix idő)' ),
			),
		),

		'chat_message' => array(
			'table'    => 'chat_messages',
			'label'    => 'Chat üzenetek',
			'singular' => 'Üzenet',
			'parent'   => 'channel',
			'fields'   => array(
				'channel_id' => array( 'type' => 'ref', 'ref' => 'channel', 'label' => 'Csatorna', 'required' => true ),
				'user_id'    => array( 'type' => 'ref', 'ref' => 'user', 'label' => 'Felhasználó', 'required' => true ),
				'body'       => array( 'type' => 'textarea', 'label' => 'Üzenet', 'required' => true ),
			),
		),

		'activity'     => array(
			'table'    => 'activity',
			'label'    => 'Tevékenység',
			'singular' => 'Bejegyzés',
			'parent'   => 'client',
			'fields'   => array(
				'client_id' => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'user_id'   => array( 'type' => 'int', 'label' => 'Felhasználó' ),
				'type'      => array(
					'type'    => 'select',
					'label'   => 'Típus',
					'default' => 'note',
					'options' => array(
						'note'   => array( 'Belső jegyzet', 'Note' ),
						'system' => array( 'Rendszer', 'Update' ),
					),
				),
				'body'      => array( 'type' => 'textarea', 'label' => 'Szöveg', 'required' => true ),
				'visible'   => array( 'type' => 'bool', 'label' => 'Látja az ügyfél', 'default' => 0 ),
			),
		),
	);

	return $entities;
}

function hpv_p_entity( string $entity ): array {
	$entities = hpv_p_entities();
	if ( ! isset( $entities[ $entity ] ) ) {
		throw new InvalidArgumentException( "Unknown entity: $entity" );
	}

	return $entities[ $entity ];
}

function hpv_p_column_sql( array $field ): string {
	switch ( $field['type'] ) {
		case 'textarea':
		case 'html':
			return 'longtext';
		case 'date':
			return 'date DEFAULT NULL';
		case 'datetime':
			return 'datetime DEFAULT NULL';
		case 'money':
			return "decimal(12,2) NOT NULL DEFAULT '0.00'";
		case 'decimal':
			return "decimal(10,2) NOT NULL DEFAULT '0.00'";
		case 'int':
		case 'ref':
			return "bigint(20) unsigned NOT NULL DEFAULT '0'";
		case 'bool':
			return "tinyint(1) NOT NULL DEFAULT '0'";
		case 'select':
			return "varchar(32) NOT NULL DEFAULT ''";
		default:
			return "varchar(255) NOT NULL DEFAULT ''";
	}
}

/**
 * CREATE TABLE utasítások a dbDelta() számára (a formázás – két szóköz a PRIMARY KEY után – kötelező).
 */
function hpv_p_schema_sql( string $prefix, string $collate ): array {
	$sql = array();
	foreach ( hpv_p_entities() as $def ) {
		$lines = array( 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT' );
		$keys  = array();
		foreach ( $def['fields'] as $key => $field ) {
			$lines[] = $key . ' ' . hpv_p_column_sql( $field );
			if ( 'ref' === $field['type'] || 'status' === $key ) {
				$keys[] = "KEY $key ($key)";
			}
		}
		$lines[] = 'created_at datetime NOT NULL';
		$lines[] = 'updated_at datetime NOT NULL';
		$lines[] = 'PRIMARY KEY  (id)';
		$lines   = array_merge( $lines, $keys );

		$sql[] = 'CREATE TABLE ' . $prefix . 'hpv_' . $def['table'] . " (\n" . implode( ",\n", $lines ) . "\n) $collate;";
	}

	return $sql;
}

/**
 * Státusz / opció címkéje. $lang: 'hu' (admin) vagy 'en' (portál).
 */
function hpv_p_option_label( string $entity, string $field, string $value, string $lang = 'hu' ): string {
	$options = hpv_p_entity( $entity )['fields'][ $field ]['options'] ?? array();
	if ( ! isset( $options[ $value ] ) ) {
		return $value;
	}

	return $options[ $value ][ 'en' === $lang ? 1 : 0 ];
}
