<?php
/**
 * Generic Page template.
 *
 * Before Phase 3 the theme shipped no `page.php`, so every Page fell through to
 * `singular.php`. This template takes over for Pages and adds the shared
 * contact / teacher / consultation sections for the plugin-provisioned pages
 * (`about` / `contact` / `consult` / `teachers`), while still rendering an
 * ordinary staff-authored Page (title + editor content) correctly — the
 * `.entry-content` class is kept so the existing block-content styling in
 * `main.css` continues to apply, matching `singular.php`.
 *
 * @package Hedayati
 */

get_header();
?>
<main id="site-main" class="section hd-public-page" role="main" tabindex="-1">
	<div class="container">
	<?php
	while ( have_posts() ) :
		the_post();
		$hd_slug = get_post_field( 'post_name', get_the_ID() );
		?>
		<article <?php post_class( 'entry' ); ?> id="post-<?php the_ID(); ?>">
			<header class="section-heading">
				<span><?php esc_html_e( 'مجتمع آموزشی دکتر هدایتی', 'hedayati' ); ?></span>
				<h1 class="entry-title"><?php the_title(); ?></h1>
			</header>

			<?php if ( has_post_thumbnail() && ! in_array( $hd_slug, [ 'about', 'contact', 'consult', 'teachers' ], true ) ) : ?>
				<div class="entry-thumbnail"><?php the_post_thumbnail( 'course-hero' ); ?></div>
			<?php endif; ?>

			<div class="hd-page-copy entry-content"><?php the_content(); ?></div>
		</article>

		<?php if ( 'about' === $hd_slug ) : ?>
			<div class="hd-public-grid">
				<section class="hd-public-card">
					<h2><?php esc_html_e( 'دوره‌های آموزشی', 'hedayati' ); ?></h2>
					<p><?php esc_html_e( 'دوره‌های کامپیوتر و فناوری اطلاعات را بررسی کنید و مسیر آموزشی متناسب با علاقه و هدف خود را انتخاب کنید.', 'hedayati' ); ?></p>
					<a class="solid-btn" href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>"><?php esc_html_e( 'مشاهده دوره‌ها', 'hedayati' ); ?></a>
				</section>
				<section class="hd-public-card">
					<h2><?php esc_html_e( 'انتخاب مسیر یادگیری', 'hedayati' ); ?></h2>
					<p><?php esc_html_e( 'برای پرسش دربارهٔ پیش‌نیازها و انتخاب دوره با مجتمع تماس بگیرید.', 'hedayati' ); ?></p>
					<a class="outline-btn" href="<?php echo esc_url( home_url( '/consult/' ) ); ?>"><?php esc_html_e( 'مشاورهٔ انتخاب دوره', 'hedayati' ); ?></a>
				</section>
			</div>
		<?php endif; ?>

		<?php if ( in_array( $hd_slug, [ 'contact', 'consult' ], true ) && class_exists( 'Hedayati_Settings' ) ) : ?>
			<?php if ( 'consult' === $hd_slug ) : ?>
				<p class="hd-page-lead"><?php esc_html_e( 'برای انتخاب دوره، بررسی پیش‌نیازها و اطلاع از زمان ثبت‌نام با مجتمع تماس بگیرید.', 'hedayati' ); ?></p>
			<?php endif; ?>
			<div class="hd-public-grid">
				<?php
				foreach ( [ 'phone_consult' => __( 'مشاوره و ثبت‌نام', 'hedayati' ), 'phone_tabriz' => __( 'مجتمع تبریز', 'hedayati' ), 'phone_tehran' => __( 'مجتمع تهران', 'hedayati' ) ] as $hd_key => $hd_label ) :
					$hd_phone = Hedayati_Settings::get( $hd_key );
					if ( '' === $hd_phone ) {
						continue;
					}
					?>
					<section class="hd-public-card">
						<h2><?php echo esc_html( $hd_label ); ?></h2>
						<a class="hd-contact-phone" href="tel:<?php echo esc_attr( Hedayati_Settings::tel_uri( $hd_key ) ); ?>" dir="ltr"><?php echo esc_html( $hd_phone ); ?></a>
					</section>
				<?php endforeach; ?>
				<?php $hd_address = Hedayati_Settings::get( 'address_tabriz' ); ?>
				<?php if ( '' !== $hd_address ) : ?>
					<section class="hd-public-card">
						<h2><?php esc_html_e( 'نشانی تبریز', 'hedayati' ); ?></h2>
						<address><?php echo nl2br( esc_html( $hd_address ) ); ?></address>
					</section>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( 'teachers' === $hd_slug && class_exists( 'Hedayati_Public_Content' ) ) : ?>
			<?php $hd_teachers = Hedayati_Public_Content::teachers(); ?>
			<?php if ( $hd_teachers ) : ?>
				<div class="hd-public-grid">
					<?php foreach ( $hd_teachers as $hd_teacher ) : ?>
						<article class="hd-public-card" id="teacher-<?php echo esc_attr( (string) $hd_teacher['id'] ); ?>">
							<?php echo $hd_teacher['image']; // phpcs:ignore -- get_the_post_thumbnail() output, already safe. ?>
							<h2><?php echo esc_html( $hd_teacher['name'] ); ?></h2>
							<?php if ( '' !== $hd_teacher['headline'] ) : ?>
								<p><?php echo esc_html( $hd_teacher['headline'] ); ?></p>
							<?php endif; ?>
							<div><?php echo wpautop( $hd_teacher['bio'] ); // phpcs:ignore -- wp_kses_post()'d in the projection. ?></div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="hd-page-lead"><?php esc_html_e( 'معرفی مدرسان به‌زودی در این صفحه منتشر می‌شود.', 'hedayati' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	<?php endwhile; ?>
	</div>
</main>
<?php
get_footer();
