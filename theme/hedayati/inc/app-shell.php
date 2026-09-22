<?php
/**
 * D58 — AI-Studio-style application shell for /panel/ and /account/.
 *
 * Presentation only. Navigation items arrive already capability-filtered
 * (Hedayati_Staff_Portal::nav_groups() for staff; the student view whitelist
 * for students) — this file never decides who may see what. Every string and
 * URL is escaped here at output.
 *
 * Layout (RTL), matching the AI Studio reference: a fixed dark rail on the
 * inline-start (right) edge — institute logo, role chip, a short flat primary
 * list, then collapsible secondary groups (native <details>, no JS) holding
 * every other module — and a compact top bar (role + workspace/name, bell,
 * quick actions, theme toggle, role avatar) above a dense content column.
 * Below 1024px the rail becomes an off-canvas drawer (assets/js/account.js).
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
 * The institute logo mark: the site's WordPress Custom Logo when one is set
 * (Appearance → Customize), otherwise the bundled institute emblem.
 */
function hedayati_app_logo_html(): string {
	$logo_id = (int) get_theme_mod( 'custom_logo' );
	$src     = $logo_id > 0 ? wp_get_attachment_image_url( $logo_id, 'thumbnail' ) : false;

	if ( $src ) {
		return sprintf( '<img class="hd-app-brand-logo" src="%s" width="40" height="40" alt="" decoding="async">', esc_url( $src ) );
	}

	return sprintf(
		'<img class="hd-app-brand-logo" src="%1$s" srcset="%1$s 1x, %2$s 2x" width="40" height="40" alt="" decoding="async">',
		esc_url( HEDAYATI_URI . '/assets/images/logo-mark-96.png' ),
		esc_url( HEDAYATI_URI . '/assets/images/logo-mark-192.png' )
	);
}

function hedayati_app_nav_item( array $item ): void {
	?>
	<li>
		<a class="hd-app-nav-link<?php echo ! empty( $item['active'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>"<?php echo ! empty( $item['active'] ) ? ' aria-current="page"' : ''; ?>>
			<?php echo hedayati_app_icon( (string) $item['icon'] ); // phpcs:ignore -- static SVG ?>
			<span><?php echo esc_html( $item['label'] ); ?></span>
			<?php if ( (int) $item['badge'] > 0 ) : ?>
				<b class="hd-nav-badge hd-app-badge"><?php echo esc_html( hedayati_app_digits( (int) $item['badge'] ) ); ?></b>
			<?php endif; ?>
		</a>
	</li>
	<?php
}

/**
 * @param array{
 *   kind:string, chip:string, rail_label:string, eyebrow:string, title:string,
 *   avatar:string, groups:array, bottom:array, notif_url:string, notif_count:int,
 *   quick?:array
 * } $a
 */
function hedayati_app_shell_open( array $a ): void {
	$count = (int) $a['notif_count'];
	$quick = $a['quick'] ?? [];
	?>
<div class="hd-app hd-app--<?php echo esc_attr( $a['kind'] ); ?>" id="hd-app">
	<aside class="hd-app-rail" id="hd-app-rail" aria-label="<?php echo esc_attr( $a['rail_label'] ); ?>">
		<div class="hd-app-brand">
			<a class="hd-app-brand-link" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'مجتمع آموزشی دکتر هدایتی — صفحهٔ نخست', 'hedayati' ); ?>">
				<?php echo hedayati_app_logo_html(); // phpcs:ignore -- escaped inside ?>
				<span class="hd-app-brand-copy"><b><?php esc_html_e( 'دکتر هدایتی', 'hedayati' ); ?></b><small><?php esc_html_e( 'مجتمع آموزشی تخصصی', 'hedayati' ); ?></small></span>
			</a>
			<button class="hd-app-rail-close" type="button" data-hd-app-close aria-label="<?php esc_attr_e( 'بستن منو', 'hedayati' ); ?>"><?php echo hedayati_app_icon( 'close', 20 ); // phpcs:ignore -- static SVG ?></button>
		</div>
		<span class="hd-app-role"><?php echo esc_html( $a['chip'] ); ?></span>

		<nav class="hd-app-nav" aria-label="<?php echo esc_attr( $a['rail_label'] ); ?>">
			<?php foreach ( $a['groups'] as $group ) : ?>
				<?php if ( ! empty( $group['collapsible'] ) ) : ?>
					<details class="hd-app-nav-group"<?php echo ! empty( $group['open'] ) ? ' open' : ''; ?>>
						<summary class="hd-app-nav-summary">
							<span><?php echo esc_html( $group['label'] ); ?></span>
							<?php
							$hd_group_badge = array_sum( array_map( static fn( $i ) => (int) $i['badge'], $group['items'] ) );
							if ( $hd_group_badge > 0 ) :
								?>
								<b class="hd-app-badge hd-app-badge--quiet"><?php echo esc_html( hedayati_app_digits( $hd_group_badge ) ); ?></b>
							<?php endif; ?>
							<?php echo hedayati_app_icon( 'chevron', 14 ); // phpcs:ignore -- static SVG ?>
						</summary>
						<ul class="hd-app-nav-list">
							<?php foreach ( $group['items'] as $item ) { hedayati_app_nav_item( $item ); } ?>
						</ul>
					</details>
				<?php else : ?>
					<ul class="hd-app-nav-list hd-app-nav-primary">
						<?php foreach ( $group['items'] as $item ) { hedayati_app_nav_item( $item ); } ?>
					</ul>
				<?php endif; ?>
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
					</a>
				<?php endif; ?>
				<?php if ( ! empty( $quick ) ) : ?>
					<details class="hd-app-quick">
						<summary class="hd-app-icon-btn" aria-label="<?php esc_attr_e( 'دسترسی سریع', 'hedayati' ); ?>"><?php echo hedayati_app_icon( 'sparkle' ); // phpcs:ignore -- static SVG ?></summary>
						<div class="hd-app-popover">
							<b><?php esc_html_e( 'دسترسی سریع', 'hedayati' ); ?></b>
							<?php foreach ( $quick as $q ) : ?>
								<a href="<?php echo esc_url( $q['url'] ); ?>"><?php echo hedayati_app_icon( (string) $q['icon'], 15 ); // phpcs:ignore -- static SVG ?><span><?php echo esc_html( $q['label'] ); ?></span></a>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endif; ?>
				<button class="hd-app-icon-btn theme-toggle-btn" id="theme-toggle" type="button" aria-label="<?php esc_attr_e( 'تغییر حالت روشن/تیره', 'hedayati' ); ?>" aria-pressed="false">
					<svg class="icon-sun" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4.5"/><path d="M12 1.5v2.5M12 20v2.5M4.2 4.2l1.8 1.8M18 18l1.8 1.8M1.5 12H4M20 12h2.5M4.2 19.8 6 18M18 6l1.8-1.8"/></svg>
					<svg class="icon-moon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
				</button>
				<span class="hd-app-avatar" title="<?php echo esc_attr( wp_get_current_user()->display_name ); ?>"><?php echo esc_html( $a['avatar'] ); ?></span>
			</div>
		</header>
	<?php
}

function hedayati_app_shell_close(): void {
	echo "\n\t</div><!-- .hd-app-main -->\n</div><!-- .hd-app -->\n";
}
