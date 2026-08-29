<?php
/**
 * Single Course Template — primary template for individual course posts.
 *
 * @package Hedayati
 */

get_header();

if ( ! have_posts() ) {
	get_footer();
	return;
}

the_post();

$post_id       = get_the_ID();
$english_name  = (string) get_post_meta( $post_id, '_course_english_name', true );
$teacher       = (string) get_post_meta( $post_id, '_course_teacher', true );
$duration      = (string) get_post_meta( $post_id, '_course_duration', true );
$level         = (string) get_post_meta( $post_id, '_course_level', true );
$prerequisites = (string) get_post_meta( $post_id, '_course_prerequisites', true );
$price         = (string) get_post_meta( $post_id, '_course_price', true );
$reg_state_raw = (string) get_post_meta( $post_id, '_course_registration_state', true ) ?: 'soon';
$start_date    = (string) get_post_meta( $post_id, '_course_next_start_date', true );
$terms         = get_the_terms( $post_id, 'course-category' );
$reg_display   = hedayati_registration_state_display( $reg_state_raw );
$monogram      = hedayati_course_monogram( $post_id );
?>

<main id="site-main" class="single-course-main" role="main">

	<!-- Course hero -->
	<section class="course-detail-hero" aria-labelledby="course-title">
		<div class="container">

			<!-- Breadcrumb -->
			<nav class="breadcrumb" aria-label="<?php esc_attr_e( 'مسیر صفحه', 'hedayati' ); ?>">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'خانه', 'hedayati' ); ?></a>
				<span aria-hidden="true">›</span>
				<a href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>"><?php esc_html_e( 'دوره‌ها', 'hedayati' ); ?></a>
				<?php if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) : ?>
					<span aria-hidden="true">›</span>
					<a href="<?php echo esc_url( get_term_link( $terms[0] ) ); ?>">
						<?php echo esc_html( $terms[0]->name ); ?>
					</a>
				<?php endif; ?>
				<span aria-hidden="true">›</span>
				<span aria-current="page"><?php the_title(); ?></span>
			</nav>

			<div class="course-detail-grid">
				<!-- Left column: main info -->
				<div class="course-detail-main">

					<?php if ( $english_name ) : ?>
						<span class="course-english-badge" dir="ltr"><?php echo esc_html( strtoupper( $english_name ) ); ?></span>
					<?php endif; ?>

					<h1 id="course-title" class="course-detail-title"><?php the_title(); ?></h1>

					<?php if ( has_excerpt() ) : ?>
						<p class="course-detail-excerpt"><?php the_excerpt(); ?></p>
					<?php endif; ?>

					<!-- Course meta pills -->
					<div class="course-detail-meta">
						<?php if ( $level ) : ?>
							<div class="detail-meta-item">
								<span class="detail-meta-label"><?php esc_html_e( 'سطح', 'hedayati' ); ?></span>
								<span class="detail-meta-value"><?php echo esc_html( $level ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( $duration ) : ?>
							<div class="detail-meta-item">
								<span class="detail-meta-label"><?php esc_html_e( 'مدت', 'hedayati' ); ?></span>
								<span class="detail-meta-value"><?php echo esc_html( $duration ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( $teacher ) : ?>
							<div class="detail-meta-item">
								<span class="detail-meta-label"><?php esc_html_e( 'مدرس', 'hedayati' ); ?></span>
								<span class="detail-meta-value"><?php echo esc_html( $teacher ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) : ?>
							<div class="detail-meta-item">
								<span class="detail-meta-label"><?php esc_html_e( 'دپارتمان', 'hedayati' ); ?></span>
								<span class="detail-meta-value"><?php echo esc_html( $terms[0]->name ); ?></span>
							</div>
						<?php endif; ?>
					</div>

					<!-- Course body content -->
					<div class="course-detail-content entry-content">
						<?php the_content(); ?>
					</div>

					<?php if ( $prerequisites ) : ?>
						<div class="course-prerequisites">
							<h2 class="prerequisites-title"><?php esc_html_e( 'پیش‌نیازها', 'hedayati' ); ?></h2>
							<p><?php echo nl2br( esc_html( $prerequisites ) ); ?></p>
						</div>
					<?php endif; ?>

				</div><!-- .course-detail-main -->

				<!-- Right column: registration card -->
				<aside class="course-sidebar" aria-label="<?php esc_attr_e( 'اطلاعات ثبت‌نام', 'hedayati' ); ?>">
					<div class="course-enroll-card">

						<!-- Course art panel -->
						<div class="course-art enroll-art">
							<div class="course-art-bg" aria-hidden="true"></div>
							<span class="course-monogram" aria-hidden="true"><?php echo $monogram; /* already escaped */ ?></span>
						</div>

						<!-- Registration state badge -->
						<div class="enroll-state <?php echo esc_attr( $reg_display['class'] ); ?>">
							<i class="state-dot" aria-hidden="true"></i>
							<?php echo esc_html( $reg_display['label'] ); ?>
						</div>

						<?php if ( $price ) : ?>
							<div class="enroll-price">
								<span class="enroll-price-label"><?php esc_html_e( 'هزینه دوره:', 'hedayati' ); ?></span>
								<strong class="enroll-price-value"><?php echo esc_html( $price ); ?></strong>
							</div>
						<?php endif; ?>

						<?php if ( $start_date ) : ?>
							<div class="enroll-date">
								<span class="enroll-date-label"><?php esc_html_e( 'شروع دوره بعدی:', 'hedayati' ); ?></span>
								<time datetime="<?php echo esc_attr( $start_date ); ?>">
									<?php echo esc_html( $start_date ); ?>
								</time>
							</div>
						<?php endif; ?>

						<a
							href="<?php echo esc_url( home_url( '/consult/' ) ); ?>"
							class="solid-btn enroll-cta-btn"
						>
							<?php esc_html_e( 'مشاوره و ثبت‌نام', 'hedayati' ); ?>
						</a>

					</div><!-- .course-enroll-card -->
				</aside>

			</div><!-- .course-detail-grid -->

		</div><!-- .container -->
	</section>

</main>

<?php get_footer(); ?>
