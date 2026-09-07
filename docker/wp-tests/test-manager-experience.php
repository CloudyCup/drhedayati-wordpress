<?php
/**
 * Manager Experience (owner decision D53) — WordPress-runtime integration checks.
 *
 * Covers:
 *   - Hedayati_Admin_Access: the front-end workspace URL chosen per role, the
 *     enforced-role set + its filter, and that the redirect predicate is ON for
 *     student/teacher/teacher_assistant and OFF for reception/hedayati_manager/
 *     administrator (the staged D53 rollout).
 *   - Hedayati_Teacher_Panel: the `teachers` panel view registration + capability,
 *     and every mutation handler (create / edit / no-title / no-nonce / wrong
 *     role / 1:1 link conflict / trash) exercised through admin-post.php against
 *     the canonical `teacher` CPT — no second Teacher store.
 *   - Hedayati_Audit_Panel: the `audit` panel view registration + capability, and
 *     that it is strictly read-only (the audit log class exposes no write API).
 *
 * KNOWN HARNESS GAP (documented, not a defect — identical class of limitation to
 * Phase 2D item 3): `Hedayati_Admin_Access::redirect_interactive_admin()` runs on
 * `admin_init` and needs a real interactive wp-admin HTTP request to fire; a bare
 * `wp eval-file` process has no such request. This suite tests the pure decision
 * logic the guard depends on (`workspace_url_for()`, `enforced_roles()`, the
 * enforcement predicate) directly, and the actual browser redirect is a staging
 * acceptance item.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

function hdit_run_manager_experience(): void {
	global $wpdb;

	$mgr  = HDIT_Env::make_user( 'mx_mgr', 'hedayati_manager' );
	$rcpt = HDIT_Env::make_user( 'mx_rcpt', 'reception' );
	$tchr = HDIT_Env::make_user( 'mx_tchr', 'teacher' );
	$ta   = HDIT_Env::make_user( 'mx_ta', 'teacher_assistant' );
	$stu  = HDIT_Env::make_user( 'mx_stu', 'student' );
	$adm  = HDIT_Env::make_user( 'mx_adm', 'administrator' );

	// ── D53.A — wp-admin access policy / front-end routing ────────────────────
	HDIT::section( 'D53.A — Hedayati_Admin_Access routing + staged enforcement' );

	$url_for = static function ( int $uid ): string {
		return Hedayati_Admin_Access::workspace_url_for( new WP_User( $uid ) );
	};

	$panel   = Hedayati_Staff_Portal::url();
	$account = Hedayati_Student_Portal::get_account_url();

	HDIT::eq( 'manager  -> /panel/', $panel, $url_for( $mgr ) );
	HDIT::eq( 'reception -> /panel/', $panel, $url_for( $rcpt ) );
	HDIT::eq( 'teacher  -> /panel/', $panel, $url_for( $tchr ) );
	HDIT::eq( 'teacher_assistant -> /panel/', $panel, $url_for( $ta ) );
	HDIT::eq( 'student  -> /account/', $account, $url_for( $stu ) );
	HDIT::eq( 'administrator -> no forced workspace ("")', '', $url_for( $adm ) );

	$roles = Hedayati_Admin_Access::enforced_roles();
	HDIT::ok( 'enforced set includes student', in_array( 'student', $roles, true ) );
	HDIT::ok( 'enforced set includes teacher', in_array( 'teacher', $roles, true ) );
	HDIT::ok( 'enforced set includes teacher_assistant', in_array( 'teacher_assistant', $roles, true ) );
	HDIT::ok( 'enforced set EXCLUDES hedayati_manager (staged — Phase E gate)', ! in_array( 'hedayati_manager', $roles, true ) );
	HDIT::ok( 'enforced set EXCLUDES reception (staged — Phase E gate)', ! in_array( 'reception', $roles, true ) );

	$pred = static function ( int $uid ): bool {
		wp_set_current_user( $uid );
		$r = Hedayati_Admin_Access::redirect_is_enforced_for_current_user();
		wp_set_current_user( 0 );
		return $r;
	};
	HDIT::ok( 'redirect ENFORCED for a student-only user', $pred( $stu ) );
	HDIT::ok( 'redirect ENFORCED for a teacher-only user', $pred( $tchr ) );
	HDIT::ok( 'redirect ENFORCED for a teacher_assistant-only user', $pred( $ta ) );
	HDIT::ok( 'redirect NOT YET enforced for hedayati_manager (Phase E)', ! $pred( $mgr ) );
	HDIT::ok( 'redirect NOT YET enforced for reception (Phase E)', ! $pred( $rcpt ) );
	HDIT::ok( 'redirect NEVER enforced for administrator', ! $pred( $adm ) );

	// The Phase E gate is a one-line filter flip, provably:
	$flip = static function ( array $r ): array {
		return array_merge( $r, [ 'reception', 'hedayati_manager' ] );
	};
	add_filter( 'hedayati_admin_redirect_roles', $flip );
	HDIT::ok( 'filter can add hedayati_manager to the enforced set', in_array( 'hedayati_manager', Hedayati_Admin_Access::enforced_roles(), true ) );
	HDIT::ok( 'with the filter on, the manager predicate flips to enforced', $pred( $mgr ) );
	HDIT::ok( 'administrator still never enforced even with the filter on', ! $pred( $adm ) );
	remove_filter( 'hedayati_admin_redirect_roles', $flip );

	// ── D53.B — Teachers panel view ──────────────────────────────────────────
	HDIT::section( 'D53.B — Hedayati_Teacher_Panel (canonical teacher CPT, in-panel)' );

	$views = Hedayati_Staff_Portal::module_views();
	HDIT::ok( 'panel registers a "teachers" view', isset( $views['teachers'] ) );
	HDIT::eq( '"teachers" view is gated on hedayati_manage_teachers', 'hedayati_manage_teachers', $views['teachers']['capability'] ?? '' );
	wp_set_current_user( $mgr );
	HDIT::ok( 'manager current_user_can(hedayati_manage_teachers)', current_user_can( 'hedayati_manage_teachers' ) );
	wp_set_current_user( $stu );
	HDIT::ok( 'student cannot (hedayati_manage_teachers)', ! current_user_can( 'hedayati_manage_teachers' ) );
	wp_set_current_user( 0 );

	$save_nonce  = wp_create_nonce( 'hedayati_teacher_panel_save' );
	$trash_nonce = wp_create_nonce( 'hedayati_teacher_panel_trash' );

	// Create (manager, valid) — success path redirects, so assert the record.
	$before = count( get_posts( [ 'post_type' => 'teacher', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) );
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce'   => $save_nonce,
		'teacher_id' => 0,
		'title'      => 'استاد آزمایشی مدیریت',
		'headline'   => 'مدرس شبکه',
		'biography'  => 'زیست‌نامهٔ کوتاه.',
		'linked_user' => 0,
		'published'  => '1',
	], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	$after = get_posts( [ 'post_type' => 'teacher', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'DESC' ] );
	HDIT::eq( 'manager create adds exactly one teacher CPT post', $before + 1, count( $after ) );
	$new_teacher = $after[0] ?? null;
	// Tag every panel-created post with the synthetic marker so HDIT_Env::reset()
	// purges it (the panel handler uses wp_insert_post directly, not make_*).
	if ( $new_teacher ) {
		update_post_meta( $new_teacher->ID, HDIT::POST_MARKER, 1 );
	}
	HDIT::ok( 'created post is post_type=teacher', $new_teacher && 'teacher' === $new_teacher->post_type );
	HDIT::eq( 'created post title stored verbatim', 'استاد آزمایشی مدیریت', $new_teacher ? $new_teacher->post_title : '' );
	HDIT::eq( 'headline stored on the canonical meta key', 'مدرس شبکه', $new_teacher ? (string) get_post_meta( $new_teacher->ID, Hedayati_Teacher::META_HEADLINE, true ) : '' );
	HDIT::eq( 'published checkbox -> post_status publish', 'publish', $new_teacher ? $new_teacher->post_status : '' );

	// Missing title -> WP_Error notice (redirect), no new post.
	$count_now = count( get_posts( [ 'post_type' => 'teacher', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) );
	HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $save_nonce, 'teacher_id' => 0, 'title' => '' ], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'empty title creates no teacher post', $count_now, count( get_posts( [ 'post_type' => 'teacher', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] ) ) );

	// No nonce -> 403.
	HDIT_AdminPost::run( $mgr, [ 'teacher_id' => 0, 'title' => 'x' ], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'handle_save without a nonce -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// Wrong role -> 403 (student).
	HDIT_AdminPost::run( $stu, [ '_wpnonce' => wp_create_nonce( 'hedayati_teacher_panel_save' ), 'teacher_id' => 0, 'title' => 'x' ], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'student POST to handle_save -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// 1:1 WP-user link conflict — mirrors Hedayati_Teacher::save().
	$link_target = HDIT_Env::make_user( 'mx_linkme', 'teacher' );
	$owner_id    = $new_teacher ? (int) $new_teacher->ID : 0;
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $save_nonce, 'teacher_id' => $owner_id, 'title' => 'استاد آزمایشی مدیریت',
		'linked_user' => $link_target,
	], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'first teacher successfully links the WP user', $link_target, (int) get_post_meta( $owner_id, Hedayati_Teacher::META_USER_ID, true ) );

	$second_teacher = HDIT_Env::make_teacher( 'دومین استاد' );
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $save_nonce, 'teacher_id' => $second_teacher, 'title' => 'دومین استاد',
		'linked_user' => $link_target,
	], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'a second teacher CANNOT claim an already-linked WP user (stays 0)', 0, (int) get_post_meta( $second_teacher, Hedayati_Teacher::META_USER_ID, true ) );
	HDIT::eq( 'the original link is untouched by the rejected claim', $link_target, (int) get_post_meta( $owner_id, Hedayati_Teacher::META_USER_ID, true ) );

	// Trash — manager can, student cannot.
	HDIT_AdminPost::run( $stu, [ '_wpnonce' => wp_create_nonce( 'hedayati_teacher_panel_trash' ), 'teacher_id' => $second_teacher ], [ 'Hedayati_Teacher_Panel', 'handle_trash' ] );
	HDIT::eq( 'student POST to handle_trash -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );
	HDIT::ok( 'teacher post survives the unauthorised trash attempt (not trashed)', 'trash' !== get_post_status( $second_teacher ) );

	HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $trash_nonce, 'teacher_id' => $second_teacher ], [ 'Hedayati_Teacher_Panel', 'handle_trash' ] );
	HDIT::eq( 'manager trashes the teacher via the safe lifecycle', 'trash', get_post_status( $second_teacher ) );

	// ── D53.C — Audit panel view (read-only) ─────────────────────────────────
	HDIT::section( 'D53.C — Hedayati_Audit_Panel (read-only, metadata-only)' );

	$views = Hedayati_Staff_Portal::module_views();
	HDIT::ok( 'panel registers an "audit" view', isset( $views['audit'] ) );
	HDIT::eq( '"audit" view is gated on hedayati_view_audit_logs', 'hedayati_view_audit_logs', $views['audit']['capability'] ?? '' );

	wp_set_current_user( $mgr );
	HDIT::ok( 'manager can view audit logs', current_user_can( 'hedayati_view_audit_logs' ) );
	wp_set_current_user( $tchr );
	HDIT::ok( 'teacher cannot view audit logs', ! current_user_can( 'hedayati_view_audit_logs' ) );
	wp_set_current_user( 0 );

	$audit_methods = get_class_methods( 'Hedayati_Audit_Log' );
	$writes = array_filter( $audit_methods, static fn( $m ) => preg_match( '/^(update|delete|edit|set_|remove)/i', $m ) );
	HDIT::ok( 'Hedayati_Audit_Log exposes NO update/delete/edit API (append-only)', [] === $writes );

	$panel_methods = get_class_methods( 'Hedayati_Audit_Panel' );
	$panel_writes  = array_filter( $panel_methods, static fn( $m ) => preg_match( '/^handle_/', $m ) );
	HDIT::ok( 'Hedayati_Audit_Panel registers NO admin-post handler (view is read-only)', [] === $panel_writes );

	// The teacher-panel writes we just did are visible through the same
	// read API the audit view uses — proving the view has real data to show.
	$entries = Hedayati_Audit_Log::query( [ 'object_type' => 'teacher', 'per_page' => 50, 'page' => 1 ] );
	HDIT::ok( 'audit query returns the teacher.* events recorded by the panel', count( $entries ) > 0 );
	HDIT::ok(
		'every returned row is metadata-only (no ip / user_agent key)',
		array_reduce( $entries, static fn( $carry, $row ) => $carry && ! isset( $row['ip'] ) && ! isset( $row['user_agent'] ), true )
	);
}
