<?php
/**
 * Impact Section — "چرا مجتمع دکتر هدایتی؟"
 *
 * Dark background section with editorial copy and institutional bullet points.
 *
 * D55: the stats panel is now wired to Hedayati_Settings (STAT_KEYS,
 * editable at /panel/?view=settings) instead of being omitted outright. Each
 * statistic renders ONLY when the institute has entered a real value — a
 * blank setting hides that stat rather than publishing an invented number
 * (docs/DECISIONS.md D55). If none are set, the layout falls back to the
 * original single-column Phase 1 look.
 *
 * @package Hedayati
 */

$hd_stats = [];
if ( class_exists( 'Hedayati_Settings' ) ) {
	$hd_stat_labels = [
		'stat_years'     => __( 'سال سابقهٔ آموزشی', 'hedayati' ),
		'stat_graduates' => __( 'دانش‌آموختهٔ مجتمع', 'hedayati' ),
		'stat_courses'   => __( 'دورهٔ تخصصی', 'hedayati' ),
	];
	foreach ( $hd_stat_labels as $hd_key => $hd_label ) {
		$hd_value = Hedayati_Settings::get( $hd_key );
		if ( '' !== $hd_value ) {
			$hd_stats[] = [ 'value' => $hd_value, 'label' => $hd_label ];
		}
	}
}
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
					<?php esc_html_e( 'پروژه‌محور و مهارت‌محور', 'hedayati' ); ?>
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

		<?php if ( ! empty( $hd_stats ) ) : ?>
			<div class="stats-grid" role="list">
				<?php foreach ( $hd_stats as $hd_stat ) : ?>
					<div class="stat-item" role="listitem">
						<span class="stat-number" dir="ltr"><?php echo esc_html( Hedayati_Text::digits_to_persian( $hd_stat['value'] ) ); ?></span>
						<span class="stat-label"><?php echo esc_html( $hd_stat['label'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

	</div><!-- .impact-grid -->
</section>
