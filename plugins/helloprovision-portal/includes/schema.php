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
				'country'      => array(
					'type'    => 'select',
					'label'   => 'Ország',
					'list'    => true,
					'default' => 'US',
					'help'    => 'Ettől függ a pénznem, a számlázó (USA: QuickBooks, Magyarország: Számlázz.hu) és a fizetés (USA: Stripe, Magyarország: Teya).',
					'options' => array(
						'US' => array( 'USA', 'United States' ),
						'HU' => array( 'Magyarország', 'Hungary' ),
					),
				),
				'billing_name' => array( 'type' => 'text', 'label' => 'Számlázási név', 'help' => 'Ha eltér a cégnévtől (pl. a cég hivatalos neve).' ),
				'tax_number'   => array( 'type' => 'text', 'label' => 'Adószám', 'help' => 'Magyar cégnél kötelező (pl. 12345678-1-42). Magánszemélynél üresen marad.' ),
				'zip'          => array( 'type' => 'text', 'label' => 'Irányítószám' ),
				'city'         => array( 'type' => 'text', 'label' => 'Város' ),
				'street'       => array( 'type' => 'text', 'label' => 'Utca, házszám' ),
				'state'        => array( 'type' => 'text', 'label' => 'Állam (USA, pl. FL)' ),
				'billing_email' => array( 'type' => 'email', 'label' => 'Számlázási e-mail', 'help' => 'Ide megy a számla. Üresen a fenti e-mail cím.' ),
				'address'      => array( 'type' => 'textarea', 'label' => 'Számlázási cím (régi, szabad szöveg)', 'hidden' => true ),
				'external_customer_id' => array( 'type' => 'text', 'label' => 'QuickBooks ügyfél azonosító', 'readonly' => true ),
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
				'name'        => array( 'type' => 'text', 'label' => 'Név (az ügyfél nyelvén, ő is látja)', 'required' => true, 'list' => true ),
				'description' => array( 'type' => 'textarea', 'label' => 'Leírás (az ügyfél nyelvén)' ),
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
				'name'              => array( 'type' => 'text', 'label' => 'Megnevezés (az ügyfél nyelvén)', 'required' => true, 'list' => true ),
				'description'       => array( 'type' => 'textarea', 'label' => 'Mit tartalmaz (az ügyfél nyelvén, ő is látja)' ),
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
				'name'        => array( 'type' => 'text', 'label' => 'Projekt neve (az ügyfél nyelvén)', 'required' => true, 'list' => true ),
				'description' => array( 'type' => 'textarea', 'label' => 'Leírás (az ügyfél nyelvén, ő is látja)' ),
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
				'owner_id'    => array( 'type' => 'ref', 'ref' => 'user', 'label' => 'Projektfelelős' ),
				'color'       => array( 'type' => 'text', 'label' => 'Szín', 'default' => '#b8ff34' ),
				'is_template' => array( 'type' => 'bool', 'label' => 'Sablon', 'default' => 0 ),
			),
		),

		'task'         => array(
			'table'    => 'tasks',
			'label'    => 'Feladatok',
			'singular' => 'Feladat',
			'parent'   => 'project',
			'fields'   => array(
				'project_id' => array( 'type' => 'ref', 'ref' => 'project', 'label' => 'Projekt', 'required' => true ),
				'title'      => array( 'type' => 'text', 'label' => 'Feladat (az ügyfél nyelvén)', 'required' => true, 'list' => true ),
				'status'     => array(
					'type'    => 'select',
					'label'   => 'Státusz',
					'list'    => true,
					'default' => 'todo',
					'options' => array(
						'todo'        => array( 'Teendő', 'To do' ),
						'in_progress' => array( 'Folyamatban', 'In progress' ),
						'review'      => array( 'Belső ellenőrzés', 'In review' ),
						'client'      => array( 'Ügyfélre vár', 'Waiting on you' ),
						'done'        => array( 'Kész', 'Done' ),
					),
				),
				'due_date'     => array( 'type' => 'date', 'label' => 'Határidő', 'list' => true ),
				'visible'      => array( 'type' => 'bool', 'label' => 'Látja az ügyfél', 'default' => 1, 'list' => true ),
				'sort'         => array( 'type' => 'int', 'label' => 'Sorrend', 'default' => 0 ),
				'description'  => array( 'type' => 'textarea', 'label' => 'Leírás' ),
				'assignee_id'  => array( 'type' => 'ref', 'ref' => 'user', 'label' => 'Felelős' ),
				'priority'     => array(
					'type'    => 'select',
					'label'   => 'Prioritás',
					'default' => 'normal',
					'options' => array(
						'low'    => array( 'Alacsony', 'Low' ),
						'normal' => array( 'Normál', 'Normal' ),
						'high'   => array( 'Magas', 'High' ),
						'urgent' => array( 'Sürgős', 'Urgent' ),
					),
				),
				'start_date'   => array( 'type' => 'date', 'label' => 'Kezdés' ),
				'parent_id'    => array( 'type' => 'int', 'label' => 'Szülő feladat' ),
				'estimate'     => array( 'type' => 'int', 'label' => 'Becsült idő (perc)' ),
				'created_by'   => array( 'type' => 'int', 'label' => 'Létrehozta' ),
				'completed_at' => array( 'type' => 'datetime', 'label' => 'Lezárva', 'readonly' => true ),
			),
		),

		'task_comment' => array(
			'table'    => 'task_comments',
			'label'    => 'Hozzászólások',
			'singular' => 'Hozzászólás',
			'parent'   => 'task',
			'fields'   => array(
				'task_id' => array( 'type' => 'ref', 'ref' => 'task', 'label' => 'Feladat', 'required' => true ),
				'user_id' => array( 'type' => 'ref', 'ref' => 'user', 'label' => 'Felhasználó', 'required' => true ),
				'body'    => array( 'type' => 'textarea', 'label' => 'Szöveg', 'required' => true ),
			),
		),

		'checklist_item' => array(
			'table'    => 'task_checklist',
			'label'    => 'Ellenőrzőlista',
			'singular' => 'Pont',
			'parent'   => 'task',
			'fields'   => array(
				'task_id' => array( 'type' => 'ref', 'ref' => 'task', 'label' => 'Feladat', 'required' => true ),
				'title'   => array( 'type' => 'text', 'label' => 'Pont', 'required' => true ),
				'done'    => array( 'type' => 'bool', 'label' => 'Kész', 'default' => 0 ),
				'sort'    => array( 'type' => 'int', 'label' => 'Sorrend', 'default' => 0 ),
			),
		),

		'time_entry'   => array(
			'table'    => 'time_entries',
			'label'    => 'Időnapló',
			'singular' => 'Időbejegyzés',
			'parent'   => 'task',
			'fields'   => array(
				'task_id'    => array( 'type' => 'ref', 'ref' => 'task', 'label' => 'Feladat', 'required' => true ),
				'user_id'    => array( 'type' => 'ref', 'ref' => 'user', 'label' => 'Felhasználó', 'required' => true ),
				'minutes'    => array( 'type' => 'int', 'label' => 'Perc' ),
				'work_date'  => array( 'type' => 'date', 'label' => 'Nap' ),
				'note'       => array( 'type' => 'text', 'label' => 'Megjegyzés' ),
				'started_at' => array( 'type' => 'int', 'label' => 'Futó stopper indítása (unix idő)' ),
			),
		),

		'task_link'    => array(
			'table'    => 'task_links',
			'label'    => 'Függőségek',
			'singular' => 'Függőség',
			'parent'   => 'task',
			'fields'   => array(
				'task_id'    => array( 'type' => 'ref', 'ref' => 'task', 'label' => 'Feladat', 'required' => true ),
				'depends_on' => array( 'type' => 'ref', 'ref' => 'task', 'label' => 'Ettől függ', 'required' => true ),
			),
		),

		'invoice'      => array(
			'table'    => 'invoices',
			'label'    => 'Számlák',
			'singular' => 'Számla',
			'parent'   => 'client',
			'fields'   => array(
				'client_id'   => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'number'      => array( 'type' => 'text', 'label' => 'Számlaszám', 'list' => true, 'readonly' => true, 'help' => 'USA: mentéskor kap számot. Magyarország: a Számlázz.hu adja kiállításkor.' ),
				'currency'    => array(
					'type'    => 'select',
					'label'   => 'Pénznem',
					'default' => '',
					'help'    => 'Üresen az ügyfél országa szerint (USA: USD, Magyarország: HUF).',
					'options' => array(
						''    => array( '— az ügyfél országa szerint —', '' ),
						'USD' => array( 'USD', 'USD' ),
						'HUF' => array( 'HUF', 'HUF' ),
						'EUR' => array( 'EUR', 'EUR' ),
					),
				),
				'vat_key'     => array(
					'type'    => 'select',
					'label'   => 'ÁFA (magyar számla)',
					'default' => '',
					'help'    => 'Csak magyar ügyfélnél. Üresen a beállításokban megadott alapérték.',
					'options' => array(
						''        => array( '— alapérték —', '' ),
						'27'      => array( '27%', '27%' ),
						'18'      => array( '18%', '18%' ),
						'5'       => array( '5%', '5%' ),
						'0'       => array( '0%', '0%' ),
						'AAM'     => array( 'AAM – alanyi adómentes', 'AAM' ),
						'TAM'     => array( 'TAM – tárgyi adómentes', 'TAM' ),
						'EUFADE'  => array( 'EU-n belüli, fordított adózás', 'EUFADE' ),
						'HO'      => array( 'Harmadik országba (EU-n kívül)', 'HO' ),
					),
				),
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
				'notes'       => array( 'type' => 'textarea', 'label' => 'Megjegyzés a számlán (az ügyfél nyelvén)' ),
				'payment_url' => array( 'type' => 'url', 'label' => 'Fizetési link (kézi)', 'help' => 'Pl. a Teya appban készült fizetési link. USA-ban üresen hagyható: a Stripe fizetés magától működik.' ),
				'sent_at'     => array( 'type' => 'datetime', 'label' => 'Kiküldve', 'readonly' => true ),
				'paid_at'     => array( 'type' => 'datetime', 'label' => 'Fizetve', 'readonly' => true ),
				'paid_amount' => array( 'type' => 'money', 'label' => 'Befizetve', 'readonly' => true ),
				'external_id' => array( 'type' => 'text', 'label' => 'Külső azonosító (QuickBooks / Számlázz.hu)', 'readonly' => true ),
				'sync_status' => array(
					'type'     => 'select',
					'label'    => 'Szinkron',
					'readonly' => true,
					'default'  => '',
					'options'  => array(
						''       => array( '—', '' ),
						'synced' => array( 'Rendben', '' ),
						'error'  => array( 'Hiba', '' ),
					),
				),
				'sync_error'  => array( 'type' => 'text', 'label' => 'Szinkron hiba', 'readonly' => true ),
				'pdf_file'    => array( 'type' => 'text', 'label' => 'Számla PDF (Számlázz.hu)', 'readonly' => true ),
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

		// Befizetések: minden fizetés egy sor (Stripe, Teya, kézi). A reference egyedi: ugyanaz a fizetés kétszer nem kerül be.
		'payment'      => array(
			'table'    => 'payments',
			'label'    => 'Befizetések',
			'singular' => 'Befizetés',
			'parent'   => 'invoice',
			'fields'   => array(
				'invoice_id'   => array( 'type' => 'ref', 'ref' => 'invoice', 'label' => 'Számla', 'required' => true ),
				'provider'     => array(
					'type'    => 'select',
					'label'   => 'Mód',
					'default' => 'manual',
					'options' => array(
						'stripe' => array( 'Stripe', 'Card (Stripe)' ),
						'teya'   => array( 'Teya', 'Card (Teya)' ),
						'manual' => array( 'Kézi (átutalás, készpénz)', 'Bank transfer' ),
					),
				),
				'amount'       => array( 'type' => 'money', 'label' => 'Összeg', 'required' => true ),
				'currency'     => array( 'type' => 'text', 'label' => 'Pénznem' ),
				'reference'    => array( 'type' => 'text', 'label' => 'Tranzakció azonosító' ),
				'paid_on'      => array( 'type' => 'date', 'label' => 'Dátum' ),
				'external_ref' => array( 'type' => 'text', 'label' => 'Könyvelőprogram azonosító', 'readonly' => true ),
				'user_id'      => array( 'type' => 'int', 'label' => 'Rögzítette' ),
				'note'         => array( 'type' => 'text', 'label' => 'Megjegyzés' ),
			),
		),

		'contract'     => array(
			'table'    => 'contracts',
			'label'    => 'Szerződések',
			'singular' => 'Szerződés',
			'parent'   => 'client',
			'fields'   => array(
				'client_id'    => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'title'        => array( 'type' => 'text', 'label' => 'Cím (az ügyfél nyelvén)', 'required' => true, 'list' => true ),
				'body'         => array( 'type' => 'html', 'label' => 'Szerződés szövege (az ügyfél nyelvén)', 'required' => true ),
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
				'language'     => array(
					'type'    => 'select',
					'label'   => 'Nyelv',
					'default' => 'en',
					'options' => array(
						'en' => array( 'Angol', 'English' ),
						'hu' => array( 'Magyar', 'Hungarian' ),
					),
				),
				'project_id'   => array( 'type' => 'ref', 'ref' => 'project', 'label' => 'Projekt' ),
				'template_id'  => array( 'type' => 'int', 'label' => 'Minta', 'readonly' => true ),
				'proposal_id'  => array( 'type' => 'int', 'label' => 'Ajánlatból', 'readonly' => true ),
				'created_by'   => array( 'type' => 'int', 'label' => 'Készítette', 'readonly' => true ),
			),
		),

		// Szerződés- és ajánlatminták: a saját mintátok, ebből dolgozik az AI.
		'doc_template' => array(
			'table'    => 'doc_templates',
			'label'    => 'Minták',
			'singular' => 'Minta',
			'fields'   => array(
				'type'         => array(
					'type'    => 'select',
					'label'   => 'Típus',
					'default' => 'contract',
					'options' => array(
						'contract' => array( 'Szerződés', 'Contract' ),
						'proposal' => array( 'Ajánlat', 'Proposal' ),
					),
				),
				'name'         => array( 'type' => 'text', 'label' => 'Név', 'required' => true ),
				'language'     => array(
					'type'    => 'select',
					'label'   => 'Nyelv',
					'default' => 'en',
					'options' => array(
						'en' => array( 'Angol', 'English' ),
						'hu' => array( 'Magyar', 'Hungarian' ),
					),
				),
				'body'         => array( 'type' => 'html', 'label' => 'Szöveg' ),
				'instructions' => array( 'type' => 'textarea', 'label' => 'Utasítás az AI-nak (pl. mindig 50% előleg, hangnem)' ),
				'created_by'   => array( 'type' => 'int', 'label' => 'Készítette' ),
			),
		),

		// Árajánlat: márkázott ajánlat oldal, publikus (tokenes) linkkel, elfogadással.
		'proposal'     => array(
			'table'    => 'proposals',
			'label'    => 'Ajánlatok',
			'singular' => 'Ajánlat',
			'parent'   => 'client',
			'fields'   => array(
				'client_id'       => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'project_id'      => array( 'type' => 'ref', 'ref' => 'project', 'label' => 'Projekt' ),
				'number'          => array( 'type' => 'text', 'label' => 'Szám', 'readonly' => true ),
				'title'           => array( 'type' => 'text', 'label' => 'Cím', 'required' => true ),
				'tagline'         => array( 'type' => 'text', 'label' => 'Alcím' ),
				'language'        => array(
					'type'    => 'select',
					'label'   => 'Nyelv',
					'default' => 'en',
					'options' => array(
						'en' => array( 'Angol', 'English' ),
						'hu' => array( 'Magyar', 'Hungarian' ),
					),
				),
				'status'          => array(
					'type'    => 'select',
					'label'   => 'Státusz',
					'default' => 'draft',
					'options' => array(
						'draft'    => array( 'Piszkozat', 'Draft' ),
						'sent'     => array( 'Kiküldve', 'Sent' ),
						'viewed'   => array( 'Megnyitotta', 'Viewed' ),
						'accepted' => array( 'Elfogadva', 'Accepted' ),
						'declined' => array( 'Elutasítva', 'Declined' ),
						'void'     => array( 'Visszavonva', 'Withdrawn' ),
					),
				),
				'sections'        => array( 'type' => 'textarea', 'label' => 'Fejezetek (JSON)' ),
				'timeline'        => array( 'type' => 'textarea', 'label' => 'Ütemterv (JSON)' ),
				'pricing'         => array( 'type' => 'textarea', 'label' => 'Árak (JSON)' ),
				'currency'        => array( 'type' => 'text', 'label' => 'Pénznem' ),
				'valid_until'     => array( 'type' => 'date', 'label' => 'Érvényes' ),
				'token'           => array( 'type' => 'text', 'label' => 'Publikus azonosító', 'readonly' => true ),
				'template_id'     => array( 'type' => 'int', 'label' => 'Minta', 'readonly' => true ),
				'notes'           => array( 'type' => 'textarea', 'label' => 'Belső megjegyzés' ),
				'created_by'      => array( 'type' => 'int', 'label' => 'Készítette', 'readonly' => true ),
				'sent_at'         => array( 'type' => 'datetime', 'label' => 'Kiküldve', 'readonly' => true ),
				'first_viewed_at' => array( 'type' => 'datetime', 'label' => 'Először megnyitva', 'readonly' => true ),
				'last_viewed_at'  => array( 'type' => 'datetime', 'label' => 'Utoljára megnyitva', 'readonly' => true ),
				'view_count'      => array( 'type' => 'int', 'label' => 'Megnyitások', 'readonly' => true ),
				'accepted_at'     => array( 'type' => 'datetime', 'label' => 'Elfogadva', 'readonly' => true ),
				'accepted_name'   => array( 'type' => 'text', 'label' => 'Elfogadó neve', 'readonly' => true ),
				'accepted_email'  => array( 'type' => 'email', 'label' => 'Elfogadó e-mail', 'readonly' => true ),
				'accepted_ip'     => array( 'type' => 'text', 'label' => 'Elfogadó IP', 'readonly' => true ),
				'accepted_agent'  => array( 'type' => 'text', 'label' => 'Elfogadó böngésző', 'readonly' => true ),
				'accepted_hash'   => array( 'type' => 'text', 'label' => 'Lenyomat (SHA-256)', 'readonly' => true ),
				'accepted_items'  => array( 'type' => 'textarea', 'label' => 'Elfogadott tételek (JSON)', 'readonly' => true ),
				'declined_at'     => array( 'type' => 'datetime', 'label' => 'Elutasítva', 'readonly' => true ),
				'decline_reason'  => array( 'type' => 'textarea', 'label' => 'Elutasítás oka', 'readonly' => true ),
				'contract_id'     => array( 'type' => 'int', 'label' => 'Szerződés', 'readonly' => true ),
				'invoice_id'      => array( 'type' => 'int', 'label' => 'Számla', 'readonly' => true ),
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
						'call'   => array( 'Videóhívás', 'Meeting' ),
					),
				),
				'body'      => array( 'type' => 'textarea', 'label' => 'Szöveg', 'required' => true ),
				'visible'   => array( 'type' => 'bool', 'label' => 'Látja az ügyfél', 'default' => 0 ),
			),
		),

		// Megosztott fájl (ügyfél vagy projekt). A tartalom a privát mappában van, csak jogosultsággal tölthető le.
		'file'         => array(
			'table'    => 'files',
			'label'    => 'Fájlok',
			'singular' => 'Fájl',
			'parent'   => 'client',
			'fields'   => array(
				'client_id'   => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'project_id'  => array( 'type' => 'ref', 'ref' => 'project', 'label' => 'Projekt' ),
				'name'        => array( 'type' => 'text', 'label' => 'Fájlnév', 'readonly' => true ),
				'storage_key' => array( 'type' => 'text', 'label' => 'Tárolt útvonal', 'readonly' => true ),
				'mime'        => array( 'type' => 'text', 'label' => 'Típus', 'readonly' => true ),
				'size'        => array( 'type' => 'int', 'label' => 'Méret (bájt)', 'readonly' => true ),
				'note'        => array( 'type' => 'text', 'label' => 'Megjegyzés' ),
				'visible'     => array( 'type' => 'bool', 'label' => 'Látja az ügyfél', 'default' => 1 ),
				'source'      => array( 'type' => 'text', 'label' => 'Feltöltötte (staff/client)', 'readonly' => true ),
				'uploaded_by' => array( 'type' => 'int', 'label' => 'Feltöltő', 'readonly' => true ),
			),
		),

		// Videóhívás (Daily.co): szoba, résztvevők hozzájárulása, leirat, AI-összefoglaló.
		'call'         => array(
			'table'    => 'calls',
			'label'    => 'Videóhívások',
			'singular' => 'Videóhívás',
			'parent'   => 'client',
			'fields'   => array(
				'client_id'    => array( 'type' => 'ref', 'ref' => 'client', 'label' => 'Ügyfél', 'required' => true ),
				'project_id'   => array( 'type' => 'ref', 'ref' => 'project', 'label' => 'Projekt' ),
				'title'        => array( 'type' => 'text', 'label' => 'Téma', 'required' => true ),
				'status'       => array(
					'type'    => 'select',
					'label'   => 'Státusz',
					'default' => 'live',
					'options' => array(
						'live'          => array( 'Folyamatban', 'Live' ),
						'processing'    => array( 'Leirat készül', 'Processing' ),
						'done'          => array( 'Kész', 'Completed' ),
						'no_transcript' => array( 'Nincs leirat', 'Completed' ),
						'failed'        => array( 'Összefoglaló hiba', 'Completed' ),
					),
				),
				'record'       => array( 'type' => 'bool', 'label' => 'Videófelvétel', 'default' => 0 ),
				'shared'       => array( 'type' => 'bool', 'label' => 'Az ügyfél látja az összefoglalót', 'default' => 0 ),
				'room_name'    => array( 'type' => 'text', 'label' => 'Daily szoba', 'readonly' => true ),
				'room_id'      => array( 'type' => 'text', 'label' => 'Daily szoba azonosító', 'readonly' => true ),
				'room_url'     => array( 'type' => 'url', 'label' => 'Daily szoba címe', 'readonly' => true ),
				'started_by'   => array( 'type' => 'int', 'label' => 'Indította', 'readonly' => true ),
				'started_at'   => array( 'type' => 'datetime', 'label' => 'Kezdés (UTC)', 'readonly' => true ),
				'last_join_at' => array( 'type' => 'datetime', 'label' => 'Utolsó csatlakozás (UTC)', 'readonly' => true ),
				'ended_at'     => array( 'type' => 'datetime', 'label' => 'Vége (UTC)', 'readonly' => true ),
				'consents'     => array( 'type' => 'textarea', 'label' => 'Hozzájárulások (JSON)', 'readonly' => true ),
				'transcript'   => array( 'type' => 'textarea', 'label' => 'Leirat', 'readonly' => true ),
				'summary'      => array( 'type' => 'textarea', 'label' => 'Összefoglaló', 'readonly' => true ),
				'ai_data'      => array( 'type' => 'textarea', 'label' => 'AI adatok (JSON)', 'readonly' => true ),
				'attempts'     => array( 'type' => 'int', 'label' => 'Feldolgozási kísérletek', 'readonly' => true ),
				'error'        => array( 'type' => 'text', 'label' => 'Hiba', 'readonly' => true ),
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
