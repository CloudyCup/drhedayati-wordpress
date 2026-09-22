<?php
/**
 * Small dependency-free line icons for the panel/account application shell.
 *
 * One consistent 24×24, 1.8-stroke, currentColor icon language (the AI Studio
 * reference uses lucide; these are hand-written equivalents so the theme ships
 * zero icon libraries). Output is static trusted markup — callers echo it as-is.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Icons {

	private const PATHS = [
		'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
		'book'      => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v15H6.5A2.5 2.5 0 0 0 4 20.5zM20 5.5A2.5 2.5 0 0 0 17.5 3H13v15h4.5a2.5 2.5 0 0 1 2.5 2.5z"/>',
		'star'      => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
		'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
		'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
		'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
		'teacher'   => '<path d="M22 10 12 5 2 10l10 5 10-5zM6 12.5V17c3 2.5 9 2.5 12 0v-4.5M22 10v6"/>',
		'folder'    => '<path d="M4 5h5l2 3h9v11H4z"/>',
		'file'      => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
		'id'        => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16a3.4 3.4 0 0 1 6.4 0M14 10h4M14 13.5h3"/>',
		'award'     => '<circle cx="12" cy="8" r="5"/><path d="M8.2 12.3 7 22l5-3 5 3-1.2-9.7"/>',
		'chat'      => '<path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.6 8.6 0 0 1-4-1L3 20l1-4.5a8.4 8.4 0 0 1-1-4A8.4 8.4 0 0 1 11.5 3h.5a8.4 8.4 0 0 1 9 8z"/>',
		'lifebuoy'  => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="m6.5 6.5 3 3M14.5 14.5l3 3M17.5 6.5l-3 3M9.5 14.5l-3 3"/>',
		'bell'      => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
		'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.4-2.4 1A8 8 0 0 0 15 6.2L14.7 4h-4L10.4 6.2A8 8 0 0 0 8.8 7l-2.3-1-2 3.5 2 1.5a7 7 0 0 0 0 2l-2 1.5 2 3.5 2.3-1a8 8 0 0 0 1.6.8l.3 2.2h4l.3-2.2a8 8 0 0 0 1.5-.8l2.4 1 2-3.5-2-1.5a7 7 0 0 0 .1-1z"/>',
		'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4"/>',
		'lock'      => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
		'list'      => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
		'link'      => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
		'layout'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
		'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 11h6M9 15h4"/>',
		'check'     => '<path d="M20 6 9 17l-5-5"/>',
		'external'  => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		'logout'    => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11"/>',
		'wordpress' => '<circle cx="12" cy="12" r="9"/><path d="M4.5 8.5 9 20M8 7.5h4M10 7.5l4 11 2.5-7.5c.5-1.6.3-3.5-1-3.5M13.6 7.5h1.9"/>',
		'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'close'     => '<path d="M6 6l12 12M18 6 6 18"/>',
		'chevron'   => '<path d="m15 18-6-6 6-6"/>',
		'plus'      => '<path d="M12 5v14M5 12h14"/>',
		'home'      => '<path d="M3 11 12 4l9 7M5 10v10h14V10"/>',
	];

	/** Inline SVG for $name (unknown names fall back to "book"), decorative by default. */
	public static function svg( string $name, int $size = 18 ): string {
		$path = self::PATHS[ $name ] ?? self::PATHS['book'];

		return sprintf(
			'<svg class="hd-icon" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
			$size,
			$path
		);
	}

	public static function exists( string $name ): bool {
		return isset( self::PATHS[ $name ] );
	}
}
