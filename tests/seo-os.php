<?php
/**
 * WordPress nélkül futtatható teszt: php tests/seo-os.php
 * Az SEO OS bővítmény tiszta függvényei: aláírás (a Python oldallal közös tesztvektorral), szerepkör, útvonal, lekérdezés.
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/plugins/helloprovision-seo-os/includes/core.php';

echo "Aláírás\n";
$h = hpv_seo_signed_headers( str_repeat( 's', 40 ), 'post', '/projects?status=draft', '{"name":"Imperial"}', 7, 'seo_manager', 'olivia@example.com', 'Olívia Kovács', 1800000000 );
check( 'e-mail URL-kódolva', 'olivia%40example.com' === $h['X-HPV-Email'] );
check( 'név URL-kódolva (ékezet)', 'Ol%C3%ADvia%20Kov%C3%A1cs' === $h['X-HPV-Name'] );
check( 'időbélyeg', '1800000000' === $h['X-HPV-Timestamp'] );
// Ugyanez a vektor a FastAPI oldalon (app.auth.sign + canonical) ezt adja:
check( 'azonos a Python aláírással', 'd80fc25856490572efa1049cd346d4b2f3feeb2babd4cb37e57e8108cd06c12e' === $h['X-HPV-Signature'] );
$h2 = hpv_seo_signed_headers( str_repeat( 's', 40 ), 'POST', '/projects?status=draft', '{"name":"Imperial!"}', 7, 'seo_manager', 'olivia@example.com', 'Olívia Kovács', 1800000000 );
check( 'más törzs → más aláírás', $h2['X-HPV-Signature'] !== $h['X-HPV-Signature'] );
$h3 = hpv_seo_signed_headers( str_repeat( 's', 40 ), 'POST', '/projects?status=draft', '{"name":"Imperial"}', 7, 'admin', 'olivia@example.com', 'Olívia Kovács', 1800000000 );
check( 'más szerepkör → más aláírás', $h3['X-HPV-Signature'] !== $h['X-HPV-Signature'] );

echo "Szerepkör\n";
check( 'WP admin → admin', 'admin' === hpv_seo_resolve_role( true, '' ) );
check( 'WP admin, más meta → admin', 'admin' === hpv_seo_resolve_role( true, 'designer' ) );
check( 'kiosztott szerepkör', 'seo_manager' === hpv_seo_resolve_role( false, 'seo_manager' ) );
check( 'ismeretlen → nincs hozzáférés', '' === hpv_seo_resolve_role( false, 'hacker' ) );
check( 'üres → nincs hozzáférés', '' === hpv_seo_resolve_role( false, '' ) );

echo "Útvonal\n";
check( 'projekt útvonal', hpv_seo_valid_path( 'projects/12/keywords' ) );
check( 'fájlnév ponttal', hpv_seo_valid_path( 'documents/3/file/pdf' ) );
check( '.. tiltva', ! hpv_seo_valid_path( 'projects/../settings' ) );
check( 'dupla perjel tiltva', ! hpv_seo_valid_path( 'projects//x' ) );
check( 'séma tiltva', ! hpv_seo_valid_path( 'http://evil' ) );
check( 'üres tiltva', ! hpv_seo_valid_path( '' ) );
check( 'szóköz tiltva', ! hpv_seo_valid_path( 'projects/1 x' ) );

echo "Lekérdezés\n";
check( 'WP paraméterek kiszűrve', 'status=draft&q=konyha%20naples' === hpv_seo_query_string( array( 'status' => 'draft', '_wpnonce' => 'x', 'rest_route' => '/y', 'q' => 'konyha naples' ) ) );
check( 'üres értékek kihagyva', 'a=1' === hpv_seo_query_string( array( 'a' => '1', 'b' => '', 'c' => null ) ) );
check( 'üres lekérdezés', '' === hpv_seo_query_string( array() ) );

echo "Aldomain\n";
check( 'egyezik', hpv_seo_host_matches( 'seo.helloprovision.com', 'seo.helloprovision.com' ) );
check( 'port nélkül is', hpv_seo_host_matches( 'SEO.helloprovision.com:443', 'seo.helloprovision.com' ) );
check( 'más aldomain nem', ! hpv_seo_host_matches( 'crm.helloprovision.com', 'seo.helloprovision.com' ) );
check( 'üres beállítás nem', ! hpv_seo_host_matches( 'seo.helloprovision.com', '' ) );

finish();
