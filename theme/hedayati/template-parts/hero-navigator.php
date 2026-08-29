<?php
/**
 * Hero Section — Concept C / Navigator design.
 *
 * Two-column layout:
 *   Left (copy): brandline, h1, lead paragraph, action buttons.
 *   Right (console): 4-department grid + meta bar.
 *
 * Department grid is driven by the course-category taxonomy.
 * If no terms exist, the console renders a neutral empty state.
 *
 * @package Hedayati
 */

// Department icon map — keyed by term slug.
// Icons are inline SVGs (no external dependency).
// Administrators can populate terms; icon selection follows slug.
$dept_icons = [
	'network'     => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="6" height="6" rx="1"/><rect x="16" y="2" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/><rect x="2" y="16" width="6" height="6" rx="1"/><rect x="16" y="16" width="6" height="6" rx="1"/><path d="M5 8v3m14-3v3M12 15v3M8 19H5m11 0h3m-7-7H5M19 12h-3"/></svg>',
	'security'    => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
	'programming' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
	'data'        => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
	'design'      => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="14.31" y1="8" x2="20.05" y2="17.94"/><line x1="9.69" y1="8" x2="21.17" y2="8"/><line x1="7.38" y1="12" x2="13.12" y2="2.06"/><line x1="9.69" y1="16" x2="3.95" y2="6.06"/><line x1="14.31" y1="16" x2="2.83" y2="16"/><line x1="16.62" y1="12" x2="10.88" y2="21.94"/></svg>',
	'default'     => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>',
];

// Get top-level taxonomy terms for the console grid (max 4)
$console_terms = [];
if ( class_exists( 'Hedayati_Query' ) ) {
	$console_terms = Hedayati_Query::get_nav_categories( 4 );
}
?>

<section class="navigator-hero" aria-labelledby="hero-headline">
	<div class="container navigator-grid">

		<!-- Copy column -->
		<div class="navigator-copy">
			<div class="nav-brandline" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>
				</svg>
				<span>NAVIGATE YOUR TECH CAREER</span>
			</div>

			<h1 id="hero-headline">
				<?php esc_html_e( 'انتخاب هوشمندانه دوره،', 'hedayati' ); ?>
				<b><?php esc_html_e( 'یادگیری عمیق', 'hedayati' ); ?></b>
				<?php esc_html_e( 'و ورود مطمئن به', 'hedayati' ); ?>
				<b><?php esc_html_e( 'بازار کار', 'hedayati' ); ?></b>
			</h1>

			<p>
				<?php esc_html_e( 'مجتمع آموزشی دکتر هدایتی با ارائه دوره‌های تخصصی، کارگاه‌های مجهز و اساتید با تجربه کاری، شما را در ساختن رزومه‌ای قوی و مهارتی واقعی همراهی می‌کند.', 'hedayati' ); ?>
			</p>

			<div class="navigator-actions">
				<a
					href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>"
					class="solid-btn large"
				>
					<?php esc_html_e( 'جستجوی همه دوره‌ها', 'hedayati' ); ?>
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
				</a>
				<a
					href="<?php echo esc_url( home_url( '/consult/' ) ); ?>"
					class="link-btn"
				>
					<?php esc_html_e( 'مشاوره و تعیین سطح', 'hedayati' ); ?>
				</a>
			</div>
		</div><!-- .navigator-copy -->

		<!-- Console column: department grid -->
		<div class="navigator-console" role="complementary" aria-label="<?php esc_attr_e( 'دپارتمان‌های اصلی مجتمع', 'hedayati' ); ?>">
			<div class="console-top">
				<span><?php esc_html_e( 'دپارتمان‌های اصلی مجتمع', 'hedayati' ); ?></span>
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="4" rx="1"/><rect x="2" y="10" width="20" height="4" rx="1"/><rect x="2" y="17" width="20" height="4" rx="1"/></svg>
			</div>

			<div class="console-grid">
				<?php if ( ! empty( $console_terms ) ) :
					foreach ( $console_terms as $term ) :
						$slug = $term->slug;
						$icon = $dept_icons[ $slug ] ?? $dept_icons['default'];
						$term_link = get_term_link( $term );
						if ( is_wp_error( $term_link ) ) continue;
						?>
						<a
							href="<?php echo esc_url( $term_link ); ?>"
							class="console-dept-btn"
						>
							<?php echo $icon; // SVG, already sanitized above ?>
							<b><?php echo esc_html( $term->name ); ?></b>
							<?php if ( $term->description ) : ?>
								<small><?php echo esc_html( $term->description ); ?></small>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				<?php else : ?>
					<!-- Empty state: displayed when no course-category terms exist -->
					<div class="console-empty-state">
						<p><?php esc_html_e( 'دسته‌بندی‌های دوره هنوز تنظیم نشده‌اند.', 'hedayati' ); ?></p>
						<?php if ( current_user_can( 'manage_categories' ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=course-category&post_type=course' ) ); ?>">
								<?php esc_html_e( 'افزودن دسته‌بندی در پنل مدیریت', 'hedayati' ); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="console-meta">
				<span>
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
					<?php esc_html_e( 'گواهینامه‌های رسمی و قابل استعلام', 'hedayati' ); ?>
				</span>
				<span>
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
					<?php esc_html_e( 'کلاس‌های حضوری و آنلاین با کیفیت یکسان', 'hedayati' ); ?>
				</span>
			</div>
		</div><!-- .navigator-console -->

	</div><!-- .navigator-grid -->
</section>
