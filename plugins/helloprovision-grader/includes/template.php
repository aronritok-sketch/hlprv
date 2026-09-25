<?php
/**
 * A [hpv_grader] shortcode markupja. Változók: $atts, $heading, $settings, $classes, $button.
 * Az eredményeket a grader.js rajzolja ki; a gombok és űrlapok itt vannak, hogy a téma
 * (btn-pill, lottie nyíl) a szerver oldali HTML-re ráinicializáljon.
 */

defined( 'ABSPATH' ) || exit;

$hpv_arrow = $settings['theme_buttons']
	? '<div class="btn-arrow-lottie" aria-hidden="true"></div>'
	: '<svg class="hpv-g-btn__arrow" width="20" height="14" viewBox="0 0 20 14" fill="none" aria-hidden="true"><path d="M1 7h17M12 1l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-hpv-grader>

	<div class="hpv-g-hero">
		<p class="hpv-g-eyebrow">Free tool · Fort Myers · Cape Coral · Naples</p>
		<<?php echo $heading; // phpcs:ignore WordPress.Security.EscapeOutput -- h1|h2 ?> class="hpv-g-title"><?php echo esc_html( $atts['title'] ); ?></<?php echo $heading; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
		<p class="hpv-g-sub"><?php echo esc_html( $atts['subtitle'] ); ?></p>

		<form class="hpv-g-scan" data-hpv-scan novalidate>
			<label class="hpv-g-sr" for="hpv-g-url">Your website address</label>
			<div class="hpv-g-scan__row">
				<input id="hpv-g-url" class="hpv-g-scan__input" name="url" type="text" inputmode="url" autocomplete="url" autocapitalize="off" spellcheck="false" placeholder="yourbusiness.com" required>
				<button type="submit" class="<?php echo esc_attr( $button ); ?>"><?php echo $hpv_arrow; // phpcs:ignore WordPress.Security.EscapeOutput ?><span>Run free audit</span></button>
			</div>
			<p class="hpv-g-error" role="alert" hidden></p>
			<ul class="hpv-g-points">
				<li>Score in about 30 seconds</li>
				<li>No signup to see your results</li>
				<li>Built for Southwest Florida businesses</li>
			</ul>
		</form>
	</div>

	<div class="hpv-g-progress" data-hpv-progress hidden>
		<p class="hpv-g-progress__site" data-hpv-progress-site></p>
		<ol class="hpv-g-steps" aria-live="polite">
			<li>Loading your homepage</li>
			<li>Reading titles, headings &amp; meta tags</li>
			<li>Checking schema markup</li>
			<li>Looking for local business signals</li>
			<li>Testing mobile speed with Google</li>
		</ol>
	</div>

	<div class="hpv-g-results" data-hpv-results hidden tabindex="-1"></div>

	<div class="hpv-g-gate" data-hpv-gate hidden>
		<div class="hpv-g-gate__text">
			<p class="hpv-g-eyebrow">Your fix list is ready</p>
			<h3 class="hpv-g-gate__title">Unlock step-by-step fixes for every issue</h3>
			<p>Enter your email and we'll reveal how to fix each problem below — and send you a copy of the full report to share with your team or web developer.</p>
		</div>
		<form class="hpv-g-gate__form" data-hpv-unlock novalidate>
			<div class="hpv-g-gate__fields">
				<label class="hpv-g-field"><span>First name*</span><input name="name" type="text" autocomplete="given-name" required></label>
				<label class="hpv-g-field"><span>Email*</span><input name="email" type="email" autocomplete="email" required></label>
				<label class="hpv-g-field hpv-g-field--wide"><span>Business name</span><input name="business" type="text" autocomplete="organization"></label>
				<label class="hpv-g-hp" aria-hidden="true">Website<input name="website" type="text" tabindex="-1" autocomplete="off"></label>
			</div>
			<label class="hpv-g-consent">
				<input name="consent" type="checkbox" value="1" required>
				<span>I agree to the <a href="<?php echo esc_url( $settings['privacy_url'] ); ?>" target="_blank" rel="noopener">Privacy Policy</a> and to HelloProVision emailing me this report and occasional follow-up about it.</span>
			</label>
			<p class="hpv-g-error" role="alert" hidden></p>
			<button type="submit" class="<?php echo esc_attr( $button ); ?>"><?php echo $hpv_arrow; // phpcs:ignore WordPress.Security.EscapeOutput ?><span>Unlock full report</span></button>
		</form>
	</div>

	<div class="hpv-g-cta" data-hpv-cta hidden>
		<div>
			<p class="hpv-g-eyebrow">Full report unlocked</p>
			<p class="hpv-g-cta__sent" data-hpv-cta-note hidden></p>
			<h3 class="hpv-g-cta__title">Want these fixed for you?</h3>
			<p>We help Fort Myers, Cape Coral and Naples businesses turn audits like this into more calls and quote requests — website, local SEO and Google Business Profile in one plan.</p>
		</div>
		<a href="<?php echo esc_url( $settings['cta_url'] ); ?>" class="<?php echo esc_attr( $button ); ?>"><?php echo $hpv_arrow; // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php echo esc_html( $settings['cta_label'] ); ?></span></a>
	</div>

	<div class="hpv-g-info">
		<h2 class="hpv-g-h2">What the audit checks</h2>
		<div class="hpv-g-cards">
			<div class="hpv-g-card"><span class="hpv-g-card__num">01</span><h3>Speed</h3><p>Google's own mobile speed test (PageSpeed Insights): loading time, visual stability, responsiveness and page weight.</p></div>
			<div class="hpv-g-card"><span class="hpv-g-card__num">02</span><h3>Mobile experience</h3><p>Phone-friendly layout, tap-to-call, responsive images and site icon — most local searches happen on a phone.</p></div>
			<div class="hpv-g-card"><span class="hpv-g-card__num">03</span><h3>SEO basics</h3><p>Title, meta description, headings, HTTPS, indexing, canonical URL, image alt text, robots.txt and XML sitemap.</p></div>
			<div class="hpv-g-card"><span class="hpv-g-card__num">04</span><h3>Schema markup</h3><p>The hidden structured data that tells Google your business type, address, phone, hours, profiles and service area.</p></div>
			<div class="hpv-g-card"><span class="hpv-g-card__num">05</span><h3>Local business info</h3><p>Phone, address and city names on the page, map and Google review links, and whether your details match everywhere.</p></div>
		</div>
	</div>

	<div class="hpv-g-faq">
		<h2 class="hpv-g-h2">Questions</h2>
		<details class="hpv-g-faq__item">
			<summary>Is the audit really free?<span class="hpv-g-faq__icon" aria-hidden="true"></span></summary>
			<p>Yes. You see your scores right away without signing up. If you'd like the step-by-step fixes and an emailed copy of the report, we ask for your name and email.</p>
		</details>
		<details class="hpv-g-faq__item">
			<summary>Why does my score differ from other tools?<span class="hpv-g-faq__icon" aria-hidden="true"></span></summary>
			<p>This audit weighs what matters most for local businesses in Southwest Florida — local business signals and mobile speed — rather than generic technical checks. Speed results come directly from Google PageSpeed Insights and can vary a few points between runs.</p>
		</details>
		<details class="hpv-g-faq__item">
			<summary>Which page is checked?<span class="hpv-g-faq__icon" aria-hidden="true"></span></summary>
			<p>The address you enter — usually your homepage, since it is the page Google connects to your Business Profile. You can also check a service or city page by entering its full address.</p>
		</details>
		<details class="hpv-g-faq__item">
			<summary>Will a good score get me to #1 on Google Maps?<span class="hpv-g-faq__icon" aria-hidden="true"></span></summary>
			<p>A strong website is the foundation, but map rankings also depend on your Google Business Profile, reviews, distance and links from other local sites. The report shows what to fix on the website side; we're happy to look at the rest with you.</p>
		</details>
	</div>
</section>
