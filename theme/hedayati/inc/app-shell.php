<?php
/**
 * D58 — AI-Studio-style application shell for /panel/ and /account/.
 *
 * Presentation only. Navigation items arrive already capability-filtered
 * (Hedayati_Staff_Portal::nav_groups() for staff; the student view whitelist
 * for students) — this file never decides who may see what. Every string and
 * URL is escaped here at output.
 *
 * Layout (RTL): a fixed dark rail on the inline-start (right) edge with grouped,
 * icon-led navigation and work-count badges; a compact sticky top bar with the
 * current section, notifications, theme toggle, site link and the user's
 * avatar; a dense content column. Below 1024px the rail becomes an off-canvas
 * drawer (assets/js/account.js).
 *
 * @package Hedayati
 */

/** True on the two application pages (never during a forced password change). */
function hedayati_is_app_shell(): bool {
	$account_id = class_exists( 'Hedayati_Student_Portal' ) ? Hedayati_Student_Portal::get_account_page_id() : 0;
	$is_app     = ( $account_id > 0 && is_page( $account_id ) ) || is_page( 'account' ) || is_page( 'panel' );

	if ( $is_app && is_user_logged_in() && class_exists( 'Hedayati_Account_Security' ) && Hedayati_Account_Security::must_change( get_current_user_id() ) ) {
		$is_app = false; // the forced-change screen keeps its own minimal chrome
	}

	return $is_app;
}

function hedayati_app_icon( string $name, int $size = 18 ): string {
	return class_exists( 'Hedayati_Icons' ) ? Hedayati_Icons::svg( $name, $size ) : '';
}

function hedayati_app_digits( int $n ): string {
	return class_exists( 'Hedayati_Text' ) ? Hedayati_Text::digits_to_persian( (string) $n ) : (string) $n;
}

/**
 * @param array{
 *   kind:string, rail_title:string, rail_subtitle:string, role:string,
 *   eyebrow:string, title:string, groups:array, bottom:array,
 *   notif_url:string, notif_count:int
 * } $a
 */
function hedayati_app_shell_open( array $a ): void {
	$user    = wp_get_current_user();
	$name    = '' !== trim( (string) $user->display_name ) ? (string) $user->display_name : (string) $user->user_login;
	$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
	$count   = (int) $a['notif_count'];
	?>
<div class="hd-app hd-app--<?php echo esc_attr( $a['kind'] ); ?>" id="hd-app">
	<aside class="hd-app-rail" id="hd-app-rail" aria-label="<?php echo esc_attr( $a['rail_title'] ); ?>">
		<div class="hd-app-brand">
			<a class="hd-app-brand-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="hd-app-brand-mark" aria-hidden="true">هـ</span>
				<span class="hd-app-brand-copy"><b><?php echo esc_html( $a['rail_title'] ); ?></b><small><?php echo esc_html( $a['rail_subtitle'] ); ?></small></span>
			</a>
			<button class="hd-app-rail-close" type="button" data-hd-app-close aria-label="<?php esc_attr_e( 'بستن منو', 'hedayati' ); ?>"><?php echo hedayati_app_icon( 'close', 20 ); // phpcs:ignore -- static SVG ?></button>
		</div>
		<span class="hd-app-role"><?php echo esc_html( $a['role'] ); ?></span>

		<nav class="hd-app-nav" aria-label="<?php echo esc_attr( $a['rail_title'] ); ?>">
			<?php foreach ( $a['groups'] as $group ) : ?>
				<?php if ( '' !== (string) $group['label'] ) : ?>
					<p class="hd-app-nav-label"><?php echo esc_html( $group['label'] ); ?></p>
				<?php endif; ?>
				<ul class="hd-app-nav-list">
					<?php foreach ( $group['items'] as $item ) : ?>
						<li>
							<a class="hd-app-nav-link<?php echo ! empty( $item['active'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>"<?php echo ! empty( $item['active'] ) ? ' aria-current="page"' : ''; ?>>
								<?php echo hedayati_app_icon( (string) $item['icon'] ); // phpcs:ignore -- static SVG ?>
								<span><?php echo esc_html( $item['label'] ); ?></span>
								<?php if ( (int) $item['badge'] > 0 ) : ?>
									<b class="hd-nav-badge hd-app-badge"><?php echo esc_html( hedayati_app_digits( (int) $item['badge'] ) ); ?></b>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</nav>

		<ul class="hd-app-nav-list hd-app-nav-bottom">
			<?php foreach ( $a['bottom'] as $link ) : ?>
				<li><a class="hd-app-nav-link<?php echo ! empty( $link['class'] ) ? ' ' . esc_attr( $link['class'] ) : ''; ?>" href="<?php echo esc_url( $link['url'] ); ?>"><?php echo hedayati_app_icon( (string) $link['icon'] ); // phpcs:ignore -- static SVG ?><span><?php echo esc_html( $link['label'] ); ?></span></a></li>
			<?php endforeach; ?>
		</ul>
	</aside>
	<div class="hd-app-backdrop" data-hd-app-close hidden></div>

	<div class="hd-app-main">
		<header class="hd-app-topbar">
			<button class="hd-app-icon-btn hd-app-menu-btn" type="button" aria-controls="hd-app-rail" aria-expanded="false" aria-label="<?php esc_attr_e( 'باز کردن منو', 'hedayati' ); ?>"><?php echo hedayati_app_icon( 'menu', 20 ); // phpcs:ignore -- static SVG ?></button>
			<div class="hd-app-topbar-title">
				<small><?php echo esc_html( $a['eyebrow'] ); ?></small>
				<b><?php echo esc_html( $a['title'] ); ?></b>
			</div>
			<div class="hd-app-topbar-actions">
				<?php if ( '' !== (string) $a['notif_url'] ) : ?>
					<a class="hd-app-icon-btn<?php echo $count > 0 ? ' has-dot' : ''; ?>" href="<?php echo esc_url( $a['notif_url'] ); ?>" aria-label="<?php echo esc_attr( $count > 0 ? sprintf( /* translators: %s: unread count */ __( 'اعلان‌ها — %s خوانده‌نشده', 'hedayati' ), hedayati_app_digits( $count ) ) : __( 'اعلان‌ها', 'hedayati' ) ); ?>">
						<?php echo hedayati_app_icon( 'bell' ); // phpcs:ignore -- static SVG ?>
						<?php if ( $count > 0 ) : ?><b class="hd-app-count" aria-hidden="true"><?php echo esc_html( hedayati_app_digits( $count ) ); ?></b><?php endif; ?>
					</a>
				<?php endif; ?>
				<button class="hd-app-icon-btn theme-toggle-btn" id="theme-toggle" type="button" aria-label="<?php esc_attr_e( 'تغییر حالت روشن/تیره', 'hedayati' ); ?>" aria-pressed="false">
					<svg class="icon-sun" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4.5"/><path d="M12 1.5v2.5M12 20v2.5M4.2 4.2l1.8 1.8M18 18l1.8 1.8M1.5 12H4M20 12h2.5M4.2 19.8 6 18M18 6l1.8-1.8"/></svg>
					<svg class="icon-moon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
				</button>
				<a class="hd-app-icon-btn hd-app-site-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'مشاهدهٔ وب‌سایت', 'hedayati' ); ?>"><?php echo hedayati_app_icon( 'external', 17 ); // phpcs:ignore -- static SVG ?></a>
				<span class="hd-app-user">
					<span class="hd-app-avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
					<span class="hd-app-user-copy"><b><?php echo esc_html( $name ); ?></b><small><?php echo esc_html( $a['role'] ); ?></small></span>
				</span>
			</div>
		</header>
	<?php
}

function hedayati_app_shell_close(): void {
	echo "\n\t</div><!-- .hd-app-main -->\n</div><!-- .hd-app -->\n";
}
