<?php
/**
 * CTA Band — full-width consultation call-to-action.
 *
 * Phone number is read from Hedayati Core settings.
 * No phone number is shown if not configured.
 *
 * @package Hedayati
 */

$cta_phone = class_exists( 'Hedayati_Settings' ) ? Hedayati_Settings::get( 'phone_consult' ) : '';
$cta_tel   = class_exists( 'Hedayati_Settings' ) ? Hedayati_Settings::tel_uri( 'phone_consult' ) : '';
?>

<section class="cta-band" aria-labelledby="cta-heading">
	<div class="container">
		<div class="cta-band-text">
			<span><?php esc_html_e( 'نیاز به راهنمایی دارید؟', 'hedayati' ); ?></span>
			<h2 id="cta-heading">
				<?php esc_html_e( 'مشاوره تخصصی برای انتخاب دوره و شروع مسیر یادگیری', 'hedayati' ); ?>
			</h2>
			<?php if ( '' !== $cta_phone && '' !== $cta_tel ) : ?>
				<p class="cta-phone">
					<a href="tel:<?php echo esc_attr( $cta_tel ); ?>" dir="ltr">
						<?php echo esc_html( $cta_phone ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<a
			href="<?php echo esc_url( home_url( '/consult/' ) ); ?>"
			class="cta-band-btn"
		>
			<?php esc_html_e( 'درخواست تماس مشاوره', 'hedayati' ); ?>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
		</a>
	</div>
</section>
