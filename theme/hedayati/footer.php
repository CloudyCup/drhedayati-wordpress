<footer class="site-footer" id="site-footer" role="contentinfo">
	<div class="container footer-grid">

		<!-- Brand column -->
		<div class="footer-brand">
			<?php if ( has_custom_logo() ) : ?>
				<div class="footer-custom-logo-wrapper">
					<?php
					$custom_logo_id = get_theme_mod( 'custom_logo' );
					$logo = wp_get_attachment_image_src( $custom_logo_id, 'full' );
					if ( $logo ) : ?>
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="footer-custom-logo-link" rel="home" aria-label="<?php bloginfo( 'name' ); ?>">
							<img src="<?php echo esc_url( $logo[0] ); ?>"
							     alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
							     class="footer-custom-logo"
							     width="<?php echo esc_attr( (string) $logo[1] ); ?>"
							     height="<?php echo esc_attr( (string) $logo[2] ); ?>">
						</a>
					<?php else :
						the_custom_logo();
					endif; ?>
				</div>
			<?php else : ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="brand-logo footer-fallback-logo" aria-label="<?php bloginfo( 'name' ); ?>">
					<div class="brand-mark-wrapper" aria-hidden="true">
						<svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="20" cy="20" r="20" fill="#c52232"/>
							<text x="20" y="26" text-anchor="middle" fill="#fff" font-size="16" font-weight="900" font-family="Tahoma, Arial, sans-serif">H</text>
						</svg>
					</div>
					<div class="brand-copy">
						<b><?php bloginfo( 'name' ); ?></b>
						<small><?php bloginfo( 'description' ); ?></small>
					</div>
				</a>
			<?php endif; ?>
			<p class="footer-tagline">
				<?php esc_html_e( 'مرکز آموزش تخصصی فناوری اطلاعات، شبکه، برنامه‌نویسی و مهارت‌های کاربردی بازار کار.', 'hedayati' ); ?>
			</p>
		</div>

		<!-- Quick links -->
		<div class="footer-col">
			<h3 class="footer-col-title"><?php esc_html_e( 'دسترسی سریع', 'hedayati' ); ?></h3>
			<ul class="footer-links">
				<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'صفحه اصلی', 'hedayati' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/courses/' ) ); ?>"><?php esc_html_e( 'دوره‌های آموزشی', 'hedayati' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php esc_html_e( 'درباره مجتمع', 'hedayati' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'تماس با ما', 'hedayati' ); ?></a></li>
				<li><a href="<?php echo esc_url( home_url( '/consult/' ) ); ?>"><?php esc_html_e( 'مشاوره ثبت‌نام', 'hedayati' ); ?></a></li>
			</ul>
		</div>

		<!-- Departments from taxonomy -->
		<div class="footer-col">
			<h3 class="footer-col-title"><?php esc_html_e( 'دپارتمان‌ها', 'hedayati' ); ?></h3>
			<?php
			if ( class_exists( 'Hedayati_Query' ) ) {
				$terms = Hedayati_Query::get_nav_categories( 5 );
				if ( ! empty( $terms ) ) :
					?>
					<ul class="footer-links">
						<?php foreach ( $terms as $term ) : ?>
							<li>
								<a href="<?php echo esc_url( get_term_link( $term ) ); ?>">
									<?php echo esc_html( $term->name ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif;
			}
			?>
		</div>

		<!-- Contact -->
		<div class="footer-col">
			<h3 class="footer-col-title"><?php esc_html_e( 'تماس با ما', 'hedayati' ); ?></h3>
			<ul class="footer-contact">
				<?php
				$has_contact = false;
				if ( class_exists( 'Hedayati_Settings' ) ) :
					$consult_phone = Hedayati_Settings::get( 'phone_consult' );
					$consult_tel   = Hedayati_Settings::tel_uri( 'phone_consult' );
					$tabriz_phone  = Hedayati_Settings::get( 'phone_tabriz' );
					$tabriz_tel    = Hedayati_Settings::tel_uri( 'phone_tabriz' );
					$tehran_phone  = Hedayati_Settings::get( 'phone_tehran' );
					$tehran_tel    = Hedayati_Settings::tel_uri( 'phone_tehran' );
					$address       = Hedayati_Settings::get( 'address_tabriz' );

					if ( '' !== $consult_phone && '' !== $consult_tel ) :
						$has_contact = true; ?>
						<li>
							<a href="tel:<?php echo esc_attr( $consult_tel ); ?>">
								<?php esc_html_e( 'مشاوره و ثبت‌نام: ', 'hedayati' ); ?>
								<span dir="ltr"><?php echo esc_html( $consult_phone ); ?></span>
							</a>
						</li>
					<?php endif;

					if ( '' !== $tabriz_phone && '' !== $tabriz_tel ) :
						$has_contact = true; ?>
						<li>
							<a href="tel:<?php echo esc_attr( $tabriz_tel ); ?>">
								<?php esc_html_e( 'تبریز: ', 'hedayati' ); ?>
								<span dir="ltr"><?php echo esc_html( $tabriz_phone ); ?></span>
							</a>
						</li>
					<?php endif;

					if ( '' !== $tehran_phone && '' !== $tehran_tel ) :
						$has_contact = true; ?>
						<li>
							<a href="tel:<?php echo esc_attr( $tehran_tel ); ?>">
								<?php esc_html_e( 'تهران: ', 'hedayati' ); ?>
								<span dir="ltr"><?php echo esc_html( $tehran_phone ); ?></span>
							</a>
						</li>
					<?php endif;

					if ( '' !== $address ) :
						$has_contact = true; ?>
						<li class="footer-address">
							<address><?php echo nl2br( esc_html( $address ) ); ?></address>
						</li>
					<?php endif;
				endif;

				if ( ! $has_contact && current_user_can( 'manage_options' ) ) : ?>
					<li class="footer-contact-placeholder">
						<em>
							<?php esc_html_e( 'اطلاعات تماس از منوی تنظیمات ← هدایتی در پنل مدیریت تنظیم می‌شود.', 'hedayati' ); ?>
						</em>
					</li>
				<?php endif; ?>
			</ul>
		</div>

	</div><!-- .footer-grid -->

	<div class="footer-bottom container">
		<span class="copyright">
			&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?>
			<?php bloginfo( 'name' ); ?>
			&mdash; <?php esc_html_e( 'کلیه حقوق محفوظ است.', 'hedayati' ); ?>
		</span>
		<span class="footer-credits">
			<?php esc_html_e( 'طراحی اختصاصی', 'hedayati' ); ?>
		</span>
	</div>

</footer><!-- .site-footer -->

<?php wp_footer(); ?>
</body>
</html>
