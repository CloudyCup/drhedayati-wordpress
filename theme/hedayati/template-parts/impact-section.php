<?php
/**
 * Impact Section — "چرا مجتمع دکتر هدایتی؟"
 *
 * Dark background section with editorial copy and institutional bullet points.
 * Stat numbers are intentionally omitted in Phase 1 — they must come from
 * a verified data source (future Customizer options or plugin settings),
 * not prototype mock values.
 *
 * @package Hedayati
 */
?>

<section class="impact-section redesigned-impact" aria-labelledby="impact-heading">
	<div class="container impact-grid">

		<!-- Copy column -->
		<div class="impact-copy">
			<span class="eyebrow light">
				<?php esc_html_e( 'کیفیت و نتیجه آموزش', 'hedayati' ); ?>
			</span>

			<h2 id="impact-heading">
				<?php esc_html_e( 'آموزش هدفمند، مسیر شفاف، نتیجه قابل اتکا', 'hedayati' ); ?>
			</h2>

			<p>
				<?php esc_html_e( 'تفاوت مجتمع آموزشی دکتر هدایتی در حذف حواشی و تمرکز روی مهارت‌هایی است که در پروژه‌ها، مصاحبه‌های فنی و بازار کار واقعی از شما انتظار می‌رود.', 'hedayati' ); ?>
			</p>

			<ul class="impact-points" role="list">
				<li>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
					<?php esc_html_e( 'اساتید باتجربه بازار کار', 'hedayati' ); ?>
				</li>
				<li>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
					<?php esc_html_e( 'کارگاه‌های مجهز و عملی', 'hedayati' ); ?>
				</li>
				<li>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
					<?php esc_html_e( 'پشتیبانی آموزشی در طول دوره', 'hedayati' ); ?>
				</li>
				<li>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
					<?php esc_html_e( 'گواهینامه معتبر پایان دوره', 'hedayati' ); ?>
				</li>
			</ul>

			<a
				href="<?php echo esc_url( home_url( '/about/' ) ); ?>"
				class="white-btn"
			>
				<?php esc_html_e( 'آشنایی بیشتر با مجتمع', 'hedayati' ); ?>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
			</a>
		</div><!-- .impact-copy -->

		<!--
		 Stats panel intentionally omitted in Phase 1.
		 Stats (years of operation, graduate count, etc.) must be entered by
		 the institute team via Appearance → Customize before being displayed.
		 This prevents publishing unverified prototype numbers.

		 To re-enable: add theme_mod calls here and render .stats-grid once
		 Customizer options are wired up.
		-->

	</div><!-- .impact-grid -->
</section>
