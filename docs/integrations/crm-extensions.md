# CRM bővítés-pont (más bővítmény menüpontot és oldalt tehet a CRM-be)

A CRM webalkalmazás (`crm.helloprovision.com`) a `helloprovision-portal` bővítményé. Más HelloProVision bővítmény
(elsőként a `helloprovision-mail`, Levelezés) így kapcsolódik hozzá, a portál kódjának módosítása nélkül.

## PHP: szkript és stíluslap a CRM oldalba

```php
add_filter( 'hpv_crm_app_extensions', function ( array $ext ) {
	$ext[] = array( 'script' => plugins_url( 'assets/x.js', __FILE__ ), 'style' => plugins_url( 'assets/x.css', __FILE__ ) );
	return $ext;
} );
```

A szkript `type="module"`-ként töltődik be az `app.js` után.

## JavaScript: menüpont, oldal, ikon, számláló

A modul a CRM `ui.js`-ét **ugyanarról a címről** importálja, mint az alkalmazás (így közös az állapot, a `useApp()` is működik):

```js
const ui = await import(window.HPV_APP.ui);
const { html, api, registerExtension, setExtensionBadge } = ui;

registerExtension({
	icons: { mail: 'M3 6h18v12H3zM3 7l9 6 9-6' },               // 24×24-es SVG path
	nav: [{ path: '/mail', label: 'Levelezés', icon: 'mail', after: '/chat' }],
	routes: [{ match: (path) => path === '/mail', render: (path, params) => html`<${MailPage} params=${params} />` }],
});
setExtensionBadge('/mail', 3);                                  // szám a menüpont mellett
```

A regisztráció sorrendje mindegy (a CRM újrarajzol, amikor új bővítés érkezik). A CRM REST címe `CFG.rest`
(`/wp-json/hpv/v1`); a bővítmény ugyanebbe a névtérbe regisztrálhat saját útvonalakat (pl. `hpv/v1/mail/...`).
