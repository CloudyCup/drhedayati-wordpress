<?php
/**
 * Public "upcoming runs" for a single course — only staff-opted-in runs, and
 * only the date / fee / registration-status projection (see D43 /
 * Hedayati_Public_Content::runs()). Rendered by single-course.php.
 *
 * @package Hedayati
 */

if ( ! class_exists( 'Hedayati_Public_Content' ) ) {
	return;
}

$hd_runs = Hedayati_Public_Content::runs( get_the_ID() );

if ( ! $hd_runs ) {
	return;
}

$hd_status_labels = [
	'open'   => __( 'ثبت‌نام باز', 'hedayati' ),
	'closed' => __( 'ثبت‌نام بسته', 'hedayati' ),
	'soon'   => __( 'ثبت‌نام به‌زودی', 'hedayati' ),
];
?>
<section class="section container hd-course-runs">
	<header class="section-heading">
		<span><?php esc_html_e( 'برنامهٔ برگزاری', 'hedayati' ); ?></span>
		<h2><?php esc_html_e( 'کلاس‌های پیش رو', 'hedayati' ); ?></h2>
	</header>

	<div class="hd-public-grid">
		<?php foreach ( $hd_runs as $hd_run ) :
			$hd_state = isset( $hd_run['registration_status'] ) ? (string) $hd_run['registration_status'] : 'soon';
			?>
			<article class="hd-public-card">
				<h3>
					<?php
					echo esc_html(
						$hd_run['start_date'] && class_exists( 'Hedayati_Jalali' )
							? Hedayati_Jalali::format( $hd_run['start_date'] )
							: __( 'تاریخ متعاقباً اعلام می‌شود', 'hedayati' )
					);
					?>
				</h3>

				<span class="hd-run-status hd-run-status--<?php echo esc_attr( $hd_state ); ?>">
					<?php echo esc_html( $hd_status_labels[ $hd_state ] ?? $hd_status_labels['soon'] ); ?>
				</span>

				<?php if ( null !== $hd_run['tuition_rial'] ) : ?>
					<p class="hd-run-fee">
						<bdi><?php echo esc_html( Hedayati_Text::digits_to_persian( number_format( (int) $hd_run['tuition_rial'] ) ) ); ?></bdi>
						<?php esc_html_e( 'ریال', 'hedayati' ); ?>
					</p>
				<?php endif; ?>

				<a class="outline-btn" href="<?php echo esc_url( home_url( '/consult/' ) ); ?>">
					<?php esc_html_e( 'پرسش دربارهٔ این کلاس', 'hedayati' ); ?>
				</a>
			</article>
		<?php endforeach; ?>
	</div>
</section>
