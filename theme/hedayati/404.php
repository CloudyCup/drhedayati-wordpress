<?php
/**
 * 404 Error Page Template.
 *
 * @package Hedayati
 */

get_header();
?>

<main id="site-main" class="error-404 section" role="main">
	<div class="container">
		<div class="error-404-inner">
			<span class="error-404-code" aria-hidden="true">404</span>
			<h1 class="error-404-title"><?php esc_html_e( 'صفحه مورد نظر یافت نشد', 'hedayati' ); ?></h1>
			<p class="error-404-desc">
				<?php esc_html_e( 'آدرس صفحه اشتباه است یا صفحه‌ای که دنبالش می‌گردید وجود ندارد.', 'hedayati' ); ?>
			</p>
			<div class="error-404-actions">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="solid-btn">
					<?php esc_html_e( 'بازگشت به صفحه اصلی', 'hedayati' ); ?>
				</a>
				<a href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>" class="outline-btn">
					<?php esc_html_e( 'مشاهده دوره‌های آموزشی', 'hedayati' ); ?>
				</a>
			</div>
		</div>
	</div>
</main>

<?php get_footer(); ?>
