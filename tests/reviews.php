<?php
/**
 * WordPress nélkül futtatható teszt: php tests/reviews.php
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/mu-plugins/helloprovision-seo.php';
require dirname( __DIR__ ) . '/plugins/helloprovision-reviews/helloprovision-reviews.php';

echo "Értékelő link ellenőrzése\n";
check( 'g.page link elfogadva', hpv_reviews_is_valid_review_url( 'https://g.page/r/CabcDEF123/review' ) );
check( 'search.google.com link elfogadva', hpv_reviews_is_valid_review_url( 'https://search.google.com/local/writereview?placeid=ChIJ123' ) );
check( 'maps.app.goo.gl elfogadva', hpv_reviews_is_valid_review_url( 'https://maps.app.goo.gl/AbC123' ) );
check( 'http elutasítva', ! hpv_reviews_is_valid_review_url( 'http://g.page/r/CabcDEF123/review' ) );
check( 'idegen domain elutasítva', ! hpv_reviews_is_valid_review_url( 'https://evil.example/g.page' ) );
check( 'google.com.evil elutasítva', ! hpv_reviews_is_valid_review_url( 'https://google.com.evil.example/' ) );
check( 'üres elutasítva', ! hpv_reviews_is_valid_review_url( '' ) );
check( 'térkép embed elfogadva', hpv_reviews_is_valid_embed_src( 'https://www.google.com/maps/embed?pb=!1m18' ) );
check( 'más iframe elutasítva', ! hpv_reviews_is_valid_embed_src( 'https://example.com/embed' ) );

echo "Levél sablon\n";
$vars = array(
	'name'    => 'Maria Lopez',
	'project' => 'your new website',
	'link'    => 'https://helloprovision.com/review/?r=abc',
);
$body = hpv_reviews_render_template( hpv_reviews_defaults()['body'], $vars );
check( 'keresztnév', 0 === strpos( $body, 'Hi Maria,' ) );
check( 'projekt szöveg', false !== strpos( $body, 'Thank you for trusting HelloProVision with your new website.' ) );
check( 'link a levélben', false !== strpos( $body, 'https://helloprovision.com/review/?r=abc' ) );
check( 'nem maradt kitöltetlen változó', ! preg_match( '/\{[a-z_]+\}/', $body ) );
$body = hpv_reviews_render_template( hpv_reviews_defaults()['body'], array( 'name' => '', 'project' => '', 'link' => 'x' ) );
check( 'név nélkül: "Hi there,"', 0 === strpos( $body, 'Hi there,' ) );
check( 'projekt nélkül nincs üres "with"', false !== strpos( $body, 'Thank you for trusting HelloProVision. ' ) );
check( 'tárgy', 'Would you share a quick Google review, Maria?' === hpv_reviews_render_template( hpv_reviews_defaults()['subject'], $vars ) );

echo "Rövid link\n";
check( 'alap rövid link', 'https://helloprovision.com/review/' === hpv_reviews_short_link() );
check( 'tokenes link', 'https://helloprovision.com/review/?r=abc123' === hpv_reviews_short_link( 'abc123' ) );

echo "Emlékeztető időzítés (6 nap)\n";
$now  = 1_800_000_000;
$base = array(
	'sent_at'     => $now - 6 * 86400,
	'clicked_at'  => 0,
	'reminded_at' => 0,
);
check( '6 nap után esedékes', hpv_reviews_is_reminder_due( $base, $now, 6 ) );
check( '5 nap után még nem', ! hpv_reviews_is_reminder_due( array( 'sent_at' => $now - 5 * 86400 ) + $base, $now, 6 ) );
check( 'ha megnyitotta, nincs emlékeztető', ! hpv_reviews_is_reminder_due( array( 'clicked_at' => $now - 86400 ) + $base, $now, 6 ) );
check( 'csak egy emlékeztető', ! hpv_reviews_is_reminder_due( array( 'reminded_at' => $now - 86400 ) + $base, $now, 6 ) );
check( 'el nem küldött kérésre nincs', ! hpv_reviews_is_reminder_due( array( 'sent_at' => 0 ) + $base, $now, 6 ) );

echo "Bot szűrés\n";
check( 'Chrome nem bot', ! hpv_reviews_is_bot( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1' ) );
check( 'Microsoft Safe Links bot', hpv_reviews_is_bot( 'Mozilla/5.0 SafeLinks scanner' ) );
check( 'HEAD kérés bot', hpv_reviews_is_bot( 'Mozilla/5.0', 'HEAD' ) );
check( 'üres UA bot', hpv_reviews_is_bot( '' ) );

echo "Beállítások mentése\n";
$GLOBALS['hpv_test_errors'] = array();
$clean = hpv_reviews_sanitize_settings(
	array(
		'review_url'    => 'https://g.page/r/CabcDEF123/review',
		'profile_url'   => 'https://evil.example/',
		'map_embed_src' => '<iframe src="https://www.google.com/maps/embed?pb=!1m18&amp;x=1" width="600"></iframe>',
		'slug'          => 'Google Review',
		'reminder_days' => '500',
		'subject'       => '',
		'body'          => "Hi {first_name}\n\n{link}",
	)
);
check( 'értékelő link mentve', 'https://g.page/r/CabcDEF123/review' === $clean['review_url'] );
check( 'nem Google-os profil link elutasítva', '' === $clean['profile_url'] && in_array( 'profile_url', $GLOBALS['hpv_test_errors'], true ) );
check( 'iframe kódból kivett src', 'https://www.google.com/maps/embed?pb=!1m18&x=1' === $clean['map_embed_src'] );
check( 'slug normalizálva', 'google-review' === $clean['slug'] );
check( 'emlékeztető max 60 nap', 60 === $clean['reminder_days'] );
check( 'üres tárgy → alapértelmezett', hpv_reviews_defaults()['subject'] === $clean['subject'] );
check( 'többsoros szöveg megmarad', "Hi {first_name}\n\n{link}" === $clean['body'] );

echo "Shortcode-ok\n";
$GLOBALS['hpv_test_options'][ HPV_REVIEWS_OPTION ] = array(
	'profile_url'   => 'https://maps.app.goo.gl/AbC123',
	'map_embed_src' => 'https://www.google.com/maps/embed?pb=!1m18',
);
check( 'értékelés link', '<a href="https://helloprovision.com/review/" class="hpv-review-link" rel="nofollow">Leave us a Google review</a>' === hpv_reviews_shortcode_review_link( array() ) );
check( 'profil link', false !== strpos( hpv_reviews_shortcode_profile( array( 'text' => 'Find us' ) ), 'href="https://maps.app.goo.gl/AbC123"' ) );
check( 'térkép iframe', false !== strpos( hpv_reviews_shortcode_map( array( 'height' => '300' ) ), 'height="300"' ) );
$GLOBALS['hpv_test_options'] = array();
check( 'üres beállításnál nincs térkép', '' === hpv_reviews_shortcode_map( array() ) );

echo "Kapcsolat az SEO pluginnal\n";
$GLOBALS['hpv_test_options'][ HPV_REVIEWS_OPTION ] = array( 'profile_url' => 'https://maps.app.goo.gl/AbC123' );
$graph = json_decode( file_get_contents( __DIR__ . '/fixtures/home-schema.json' ), true )['@graph'];
$graph = hpv_seo_fix_graph( $graph, (object) array( 'canonical' => 'https://helloprovision.com/' ) );
foreach ( $graph as $node ) {
	if ( hpv_seo_has_type( $node, 'Organization' ) ) {
		check( 'profil link a schema sameAs-ban', in_array( 'https://maps.app.goo.gl/AbC123', $node['sameAs'], true ) );
	}
}

finish();
