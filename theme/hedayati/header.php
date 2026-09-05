<!DOCTYPE html>
<html <?php language_attributes(); ?> data-theme="light">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="format-detection" content="telephone=no">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="skip-link screen-reader-text" href="#site-main">
	<?php esc_html_e( 'رفتن به محتوای اصلی', 'hedayati' ); ?>
</a>

<header class="site-header" id="site-header" role="banner">
	<div class="container header-inner">

		<!-- Logo / Brand -->
		<div class="header-brand">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="brand-logo" rel="home" aria-label="<?php bloginfo( 'name' ); ?>">
					<div class="brand-mark-wrapper" aria-hidden="true">
						<svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" class="site-logo-svg">
							<circle cx="20" cy="20" r="20" fill="#c52232"/>
							<text x="20" y="26" text-anchor="middle" fill="#fff" font-size="16" font-weight="900" font-family="Tahoma, Arial, sans-serif">H</text>
						</svg>
					</div>
					<div class="brand-copy">
						<b><?php bloginfo( 'name' ); ?></b>
						<small><?php esc_html_e( 'مجتمع آموزشی', 'hedayati' ); ?></small>
					</div>
				</a>
			<?php endif; ?>
		</div>

		<!-- Primary navigation -->
		<nav
			class="header-nav"
			id="header-nav"
			role="navigation"
			aria-label="<?php esc_attr_e( 'منوی اصلی', 'hedayati' ); ?>"
		>
			<?php
			wp_nav_menu( [
				'theme_location'  => 'primary',
				'menu_class'      => 'primary-menu',
				'container'       => false,
				'fallback_cb'     => 'hedayati_primary_menu_fallback',
				'items_wrap'      => '<ul id="%1$s" class="%2$s">%3$s</ul>',
				'walker'          => null,
			] );
			?>
		</nav>

		<!-- Header CTA actions -->
		<div class="header-cta">
			<a
				href="<?php echo esc_url( home_url( '/consult/' ) ); ?>"
				class="outline-btn header-consult-btn"
			>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.29 6.29l1.9-1.9a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
				</svg>
				<span><?php esc_html_e( 'مشاوره ثبت‌نام', 'hedayati' ); ?></span>
			</a>

			<!-- Dark mode toggle -->
			<a class="outline-btn hd-account-link" href="<?php echo esc_url( is_user_logged_in() ? ( class_exists( 'Hedayati_Staff_Portal' ) && Hedayati_Staff_Portal::allowed() ? Hedayati_Staff_Portal::url() : home_url( '/account/' ) ) : wp_login_url() ); ?>"><?php echo is_user_logged_in() ? esc_html__( 'حساب من', 'hedayati' ) : esc_html__( 'ورود', 'hedayati' ); ?></a>
			<button
				class="theme-toggle-btn"
				id="theme-toggle"
				type="button"
				aria-label="<?php esc_attr_e( 'تغییر حالت روشن/تیره', 'hedayati' ); ?>"
				aria-pressed="false"
			>
				<svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<circle cx="12" cy="12" r="5"/>
					<line x1="12" y1="1" x2="12" y2="3"/>
					<line x1="12" y1="21" x2="12" y2="23"/>
					<line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
					<line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
					<line x1="1" y1="12" x2="3" y2="12"/>
					<line x1="21" y1="12" x2="23" y2="12"/>
					<line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
					<line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
				</svg>
				<svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
				</svg>
			</button>
		</div>

		<!-- Mobile menu button -->
		<button
			class="mobile-menu-btn"
			id="mobile-menu-btn"
			type="button"
			aria-label="<?php esc_attr_e( 'باز کردن منو', 'hedayati' ); ?>"
			aria-expanded="false"
			aria-controls="header-nav"
		>
			<span class="hamburger-bar"></span>
			<span class="hamburger-bar"></span>
			<span class="hamburger-bar"></span>
		</button>

	</div><!-- .header-inner -->
</header><!-- .site-header -->
