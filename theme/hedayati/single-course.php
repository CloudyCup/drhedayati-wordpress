<?php
/**
 * Single Course Landing Page Template — Concept C / Navigator design.
 *
 * Fully data-driven from WordPress Course post, meta, taxonomy, and Hedayati Settings.
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
$title         = get_the_title();
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
$has_thumb     = has_post_thumbnail( $post_id );

// Structured repeatable arrays
$syllabus_raw  = get_post_meta( $post_id, '_course_syllabus', true );
$syllabus      = is_array( $syllabus_raw ) ? array_filter( $syllabus_raw ) : [];

$audience_raw  = get_post_meta( $post_id, '_course_target_audience', true );
$audience      = is_array( $audience_raw ) ? array_filter( $audience_raw ) : [];

$outcomes_raw  = get_post_meta( $post_id, '_course_learning_outcomes', true );
$outcomes      = is_array( $outcomes_raw ) ? array_filter( $outcomes_raw ) : [];

// Contact phone from settings
$consult_phone = class_exists( 'Hedayati_Settings' ) ? Hedayati_Settings::get( 'phone_consult' ) : '';
$consult_tel   = class_exists( 'Hedayati_Settings' ) ? Hedayati_Settings::tel_uri( 'phone_consult' ) : '';

// Related courses query
$related_query = class_exists( 'Hedayati_Query' ) ? Hedayati_Query::get_related_courses( $post_id, 3 ) : null;
?>

<main id="site-main" class="single-course-main" role="main" tabindex="-1">

	<!-- ── 1. Course Hero ───────────────────────────────────────────── -->
	<header class="course-page-hero">
		<div class="container">

			<!-- Breadcrumb navigation -->
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

			<div class="course-hero-grid">

				<!-- Hero Content Column -->
				<div class="course-hero-content">

					<div class="course-hero-tags">
						<?php if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) : ?>
							<a href="<?php echo esc_url( get_term_link( $terms[0] ) ); ?>" class="course-category-badge">
								<?php echo esc_html( $terms[0]->name ); ?>
							</a>
						<?php endif; ?>

						<?php if ( '' !== $english_name ) : ?>
							<span class="course-english-badge" dir="ltr"><?php echo esc_html( strtoupper( $english_name ) ); ?></span>
						<?php endif; ?>
					</div>

					<h1 class="course-hero-title"><?php the_title(); ?></h1>

					<?php if ( has_excerpt() ) : ?>
						<div class="course-hero-excerpt">
							<?php the_excerpt(); ?>
						</div>
					<?php endif; ?>

					<!-- Hero CTA Buttons -->
					<div class="course-hero-actions">
						<?php if ( 'open' === $reg_state_raw ) : ?>
							<a href="#course-enroll" class="solid-btn large course-hero-cta">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
								<span><?php esc_html_e( 'درخواست ثبت‌نام در دوره', 'hedayati' ); ?></span>
							</a>
						<?php elseif ( 'soon' === $reg_state_raw ) : ?>
							<div class="course-state-indicator is-soon">
								<i class="state-dot" aria-hidden="true"></i>
								<span><?php esc_html_e( 'ثبت‌نام این دوره به‌زودی آغاز می‌شود', 'hedayati' ); ?></span>
							</div>
						<?php else : ?>
							<div class="course-state-indicator is-closed">
								<i class="state-dot" aria-hidden="true"></i>
								<span><?php esc_html_e( 'ثبت‌نام این دوره در حال حاضر بسته است', 'hedayati' ); ?></span>
							</div>
						<?php endif; ?>

						<?php if ( '' !== $consult_tel && '' !== $consult_phone ) : ?>
							<a href="tel:<?php echo esc_attr( $consult_tel ); ?>" class="outline-btn course-consult-cta">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.29 6.29l1.9-1.9a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
								<span><?php esc_html_e( 'مشاوره تلفنی: ', 'hedayati' ); ?></span>
								<strong dir="ltr"><?php echo esc_html( $consult_phone ); ?></strong>
							</a>
						<?php endif; ?>
					</div>

				</div><!-- .course-hero-content -->

				<!-- Hero Visual Column -->
				<div class="course-hero-visual">
					<?php if ( $has_thumb ) : ?>
						<div class="course-hero-thumb-wrapper">
							<?php the_post_thumbnail( 'course-hero', [ 'class' => 'course-hero-img', 'alt' => esc_attr( $title ) ] ); ?>
						</div>
					<?php else : ?>
						<div class="course-art hero-course-art">
							<div class="course-art-bg" aria-hidden="true"></div>
							<span class="course-monogram" aria-hidden="true"><?php echo $monogram; ?></span>
						</div>
					<?php endif; ?>
				</div>

			</div><!-- .course-hero-grid -->

		</div><!-- .container -->
	</header>

	<!-- ── 2. Quick Facts Bar ────────────────────────────────────────── -->
	<section class="course-facts-bar" aria-label="<?php esc_attr_e( 'مشخصات سریع دوره', 'hedayati' ); ?>">
		<div class="container">
			<div class="facts-grid">

				<?php if ( '' !== $teacher ) : ?>
					<div class="fact-card">
						<span class="fact-icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
						</span>
						<div class="fact-data">
							<span class="fact-label"><?php esc_html_e( 'استاد / مدرس', 'hedayati' ); ?></span>
							<strong class="fact-value"><?php echo esc_html( $teacher ); ?></strong>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $duration ) : ?>
					<div class="fact-card">
						<span class="fact-icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
						</span>
						<div class="fact-data">
							<span class="fact-label"><?php esc_html_e( 'طول دوره', 'hedayati' ); ?></span>
							<strong class="fact-value"><?php echo esc_html( $duration ); ?></strong>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $level ) : ?>
					<div class="fact-card">
						<span class="fact-icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
						</span>
						<div class="fact-data">
							<span class="fact-label"><?php esc_html_e( 'سطح دوره', 'hedayati' ); ?></span>
							<strong class="fact-value"><?php echo esc_html( $level ); ?></strong>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $start_date ) : ?>
					<div class="fact-card">
						<span class="fact-icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
						</span>
						<div class="fact-data">
							<span class="fact-label"><?php esc_html_e( 'شروع دوره بعدی', 'hedayati' ); ?></span>
							<strong class="fact-value" dir="ltr"><time datetime="<?php echo esc_attr( $start_date ); ?>"><?php echo esc_html( $start_date ); ?></time></strong>
						</div>
					</div>
				<?php endif; ?>

				<div class="fact-card">
					<span class="fact-icon" aria-hidden="true">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
					</span>
					<div class="fact-data">
						<span class="fact-label"><?php esc_html_e( 'وضعیت ثبت‌نام', 'hedayati' ); ?></span>
						<strong class="fact-value status-badge <?php echo esc_attr( $reg_display['class'] ); ?>">
							<i class="state-dot" aria-hidden="true"></i>
							<?php echo esc_html( $reg_display['label'] ); ?>
						</strong>
					</div>
				</div>

				<?php if ( '' !== $price ) : ?>
					<div class="fact-card price-fact-card">
						<span class="fact-icon" aria-hidden="true">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
						</span>
						<div class="fact-data">
							<span class="fact-label"><?php esc_html_e( 'شهریه دوره', 'hedayati' ); ?></span>
							<strong class="fact-value price-val"><?php echo esc_html( $price ); ?></strong>
						</div>
					</div>
				<?php endif; ?>

			</div>
		</div>
	</section>

	<!-- ── 3. Main Course Layout Grid ────────────────────────────────── -->
	<section class="course-body-section section">
		<div class="container course-layout-grid">

			<!-- Main Column -->
			<div class="course-main-column">

				<!-- 3.1 Course Introduction (Gutenberg Content) -->
				<?php
				$raw_content = get_post_field( 'post_content', $post_id );
				$has_content = is_string( $raw_content ) && '' !== trim( $raw_content );
				if ( $has_content ) :
					?>
					<article class="course-content-block" aria-labelledby="course-intro-title">
						<h2 id="course-intro-title" class="course-block-title"><?php esc_html_e( 'معرفی و اهداف دوره', 'hedayati' ); ?></h2>
						<div class="course-entry-content entry-content">
							<?php the_content(); ?>
						</div>
					</article>
				<?php endif; ?>

				<!-- 3.2 Course Syllabus (Structured Array) -->
				<?php if ( ! empty( $syllabus ) ) : ?>
					<section class="course-content-block course-syllabus-block" aria-labelledby="syllabus-title">
						<h2 id="syllabus-title" class="course-block-title">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
							<?php esc_html_e( 'سرفصل‌های آموزشی دوره', 'hedayati' ); ?>
						</h2>
						<div class="syllabus-list" role="list">
							<?php foreach ( $syllabus as $index => $item ) : ?>
								<div class="syllabus-item" role="listitem">
									<span class="syllabus-number" aria-hidden="true"><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
									<span class="syllabus-text"><?php echo esc_html( $item ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<!-- 3.3 Learning Outcomes (Structured Array) -->
				<?php if ( ! empty( $outcomes ) ) : ?>
					<section class="course-content-block course-outcomes-block" aria-labelledby="outcomes-title">
						<h2 id="outcomes-title" class="course-block-title">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
							<?php esc_html_e( 'دستاوردها و مهارت‌های کسب‌شده', 'hedayati' ); ?>
						</h2>
						<div class="outcomes-grid" role="list">
							<?php foreach ( $outcomes as $item ) : ?>
								<div class="outcome-card" role="listitem">
									<svg class="outcome-check" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
									<span><?php echo esc_html( $item ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<!-- 3.4 Target Audience (Structured Array) -->
				<?php if ( ! empty( $audience ) ) : ?>
					<section class="course-content-block course-audience-block" aria-labelledby="audience-title">
						<h2 id="audience-title" class="course-block-title">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
							<?php esc_html_e( 'این دوره برای چه کسانی مناسب است؟', 'hedayati' ); ?>
						</h2>
						<div class="audience-list" role="list">
							<?php foreach ( $audience as $item ) : ?>
								<div class="audience-item" role="listitem">
									<span class="audience-bullet" aria-hidden="true">▸</span>
									<span><?php echo esc_html( $item ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<!-- 3.5 Prerequisites -->
				<?php if ( '' !== $prerequisites ) : ?>
					<section class="course-content-block course-prerequisites-block" aria-labelledby="prereq-title">
						<h2 id="prereq-title" class="course-block-title">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
							<?php esc_html_e( 'پیش‌نیازهای ورود به دوره', 'hedayati' ); ?>
						</h2>
						<div class="prerequisites-box">
							<p><?php echo nl2br( esc_html( $prerequisites ) ); ?></p>
						</div>
					</section>
				<?php endif; ?>

			</div><!-- .course-main-column -->

			<!-- Sidebar Column: Sticky Enrollment Box -->
			<aside class="course-sidebar-column" id="course-enroll" aria-label="<?php esc_attr_e( 'پنل ثبت‌نام و مشاوره', 'hedayati' ); ?>">
				<div class="course-sticky-card">

					<div class="sticky-card-header">
						<span class="sticky-card-label"><?php esc_html_e( 'مشخصات ثبت‌نام', 'hedayati' ); ?></span>
						<span class="seats-badge <?php echo esc_attr( $reg_display['class'] ); ?>">
							<i class="state-dot" aria-hidden="true"></i>
							<?php echo esc_html( $reg_display['label'] ); ?>
						</span>
					</div>

					<div class="sticky-card-body">
						<?php if ( '' !== $price ) : ?>
							<div class="sticky-card-price">
								<span class="price-label"><?php esc_html_e( 'هزینه دوره:', 'hedayati' ); ?></span>
								<strong class="price-value"><?php echo esc_html( $price ); ?></strong>
							</div>
						<?php endif; ?>

						<?php if ( '' !== $start_date ) : ?>
							<div class="sticky-card-date">
								<span class="date-label"><?php esc_html_e( 'تاریخ برگزاری:', 'hedayati' ); ?></span>
								<strong class="date-value" dir="ltr"><?php echo esc_html( $start_date ); ?></strong>
							</div>
						<?php endif; ?>

						<!-- Action buttons -->
						<div class="sticky-card-actions">
							<?php if ( 'open' === $reg_state_raw ) : ?>
								<?php if ( '' !== $consult_tel ) : ?>
									<a href="tel:<?php echo esc_attr( $consult_tel ); ?>" class="solid-btn large sticky-enroll-btn">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.29 6.29l1.9-1.9a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
										<span><?php esc_html_e( 'درخواست ثبت‌نام و مشاوره', 'hedayati' ); ?></span>
									</a>
								<?php else : ?>
									<button type="button" class="solid-btn large sticky-enroll-btn" disabled>
										<span><?php esc_html_e( 'ثبت‌نام فعال است', 'hedayati' ); ?></span>
									</button>
								<?php endif; ?>
							<?php elseif ( 'soon' === $reg_state_raw ) : ?>
								<button type="button" class="outline-btn large sticky-enroll-btn" disabled>
									<span><?php esc_html_e( 'به‌زودی آغاز می‌شود', 'hedayati' ); ?></span>
								</button>
							<?php else : ?>
								<button type="button" class="outline-btn large sticky-enroll-btn" disabled>
									<span><?php esc_html_e( 'ثبت‌نام این دوره در حال حاضر بسته است', 'hedayati' ); ?></span>
								</button>
							<?php endif; ?>

							<?php if ( '' !== $consult_tel && '' !== $consult_phone ) : ?>
								<div class="sticky-card-consult-box">
									<span><?php esc_html_e( 'پاسخگویی و تعیین سطح:', 'hedayati' ); ?></span>
									<a href="tel:<?php echo esc_attr( $consult_tel ); ?>" class="sticky-consult-link" dir="ltr">
										<?php echo esc_html( $consult_phone ); ?>
									</a>
								</div>
							<?php endif; ?>
						</div>

					</div><!-- .sticky-card-body -->

				</div><!-- .course-sticky-card -->
			</aside>

		</div><!-- .container -->
	</section>

	<!-- ── 4. Related Courses Section ────────────────────────────────── -->
	<?php if ( $related_query && $related_query->have_posts() ) : ?>
		<section class="related-courses-section section" aria-labelledby="related-courses-title">
			<div class="container">
				<div class="section-heading row">
					<div>
						<span><?php esc_html_e( 'مسیرهای آموزشی مرتبط', 'hedayati' ); ?></span>
						<h2 id="related-courses-title"><?php esc_html_e( 'دوره‌های مکمل و پیشنهادی', 'hedayati' ); ?></h2>
					</div>
					<a href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>" class="outline-btn">
						<?php esc_html_e( 'مشاهده همه دوره‌ها', 'hedayati' ); ?>
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
					</a>
				</div>

				<div class="related-courses-grid" role="list">
					<?php while ( $related_query->have_posts() ) : $related_query->the_post(); ?>
						<div role="listitem">
							<?php get_template_part( 'template-parts/course-card' ); ?>
						</div>
					<?php endwhile; wp_reset_postdata(); ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

</main>

<?php get_footer(); ?>
