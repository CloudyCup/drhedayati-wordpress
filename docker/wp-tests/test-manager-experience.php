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

	// Every panel mutation handler requires a POST request method (guard_action()
	// / verify()); WP-CLI leaves $_SERVER['REQUEST_METHOD'] unset.
	$_SERVER['REQUEST_METHOD'] = 'POST';

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
	foreach ( [ 'student', 'teacher', 'teacher_assistant', 'reception', 'hedayati_manager' ] as $role ) {
		HDIT::ok( "D53 fully enforced: enforced set includes {$role}", in_array( $role, $roles, true ) );
	}

	$pred = static function ( int $uid ): bool {
		wp_set_current_user( $uid );
		$r = Hedayati_Admin_Access::redirect_is_enforced_for_current_user();
		wp_set_current_user( 0 );
		return $r;
	};
	HDIT::ok( 'redirect ENFORCED for a student-only user', $pred( $stu ) );
	HDIT::ok( 'redirect ENFORCED for a teacher-only user', $pred( $tchr ) );
	HDIT::ok( 'redirect ENFORCED for a teacher_assistant-only user', $pred( $ta ) );
	HDIT::ok( 'redirect ENFORCED for a hedayati_manager (Phase E complete)', $pred( $mgr ) );
	HDIT::ok( 'redirect ENFORCED for a reception user (Phase E complete)', $pred( $rcpt ) );
	HDIT::ok( 'redirect NEVER enforced for administrator', ! $pred( $adm ) );

	// The filter can still NARROW the policy per deployment.
	$narrow = static fn( array $r ): array => array_values( array_diff( $r, [ 'hedayati_manager' ] ) );
	add_filter( 'hedayati_admin_redirect_roles', $narrow );
	HDIT::ok( 'filter can remove hedayati_manager from the enforced set', ! in_array( 'hedayati_manager', Hedayati_Admin_Access::enforced_roles(), true ) );
	HDIT::ok( 'with the filter narrowing it, the manager predicate goes back to not-enforced', ! $pred( $mgr ) );
	HDIT::ok( 'administrator still never enforced regardless of the filter', ! $pred( $adm ) );
	remove_filter( 'hedayati_admin_redirect_roles', $narrow );

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

	// A WordPress nonce is bound to the current user — it must be minted while the
	// acting user is set (matching how HDIT_AdminPost::run re-sets that same user).
	$nonce_as = static function ( int $uid, string $action ): string {
		wp_set_current_user( $uid );
		$n = wp_create_nonce( $action );
		wp_set_current_user( 0 );
		return $n;
	};
	$SAVE  = 'hedayati_teacher_panel_save';
	$TRASH = 'hedayati_teacher_panel_trash';

	$all_teachers = static fn(): array => get_posts( [
		'post_type'   => 'teacher',
		'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
		'numberposts' => -1,
		'fields'      => 'ids',
	] );
	$find_teacher_by_title = static function ( string $title ): ?WP_Post {
		$hits = get_posts( [
			'post_type'   => 'teacher',
			'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'numberposts' => 1,
			'title'       => $title,
		] );
		return $hits[0] ?? null;
	};

	// Create (manager, valid) — success path redirects, so assert the record.
	$before = count( $all_teachers() );
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce'   => $nonce_as( $mgr, $SAVE ),
		'teacher_id' => 0,
		'title'      => 'استاد آزمایشی مدیریت',
		'headline'   => 'مدرس شبکه',
		'biography'  => 'زیست‌نامهٔ کوتاه.',
		'linked_user' => '0',
		'published'  => '1',
	], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'manager create adds exactly one teacher CPT post', $before + 1, count( $all_teachers() ) );
	$new_teacher = $find_teacher_by_title( 'استاد آزمایشی مدیریت' );
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
	$count_now = count( $all_teachers() );
	HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $nonce_as( $mgr, $SAVE ), 'teacher_id' => 0, 'title' => '' ], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'empty title creates no teacher post', $count_now, count( $all_teachers() ) );

	// No nonce -> 403.
	HDIT_AdminPost::run( $mgr, [ 'teacher_id' => 0, 'title' => 'x' ], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'handle_save without a nonce -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// Wrong role -> 403 (student).
	HDIT_AdminPost::run( $stu, [ '_wpnonce' => $nonce_as( $stu, $SAVE ), 'teacher_id' => 0, 'title' => 'x' ], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'student POST to handle_save -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// 1:1 WP-user link conflict — mirrors Hedayati_Teacher::save().
	$link_target = HDIT_Env::make_user( 'mx_linkme', 'teacher' );
	$owner_id    = $new_teacher ? (int) $new_teacher->ID : 0;
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $nonce_as( $mgr, $SAVE ), 'teacher_id' => (string) $owner_id, 'title' => 'استاد آزمایشی مدیریت',
		'linked_user' => (string) $link_target,
	], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'first teacher successfully links the WP user', $link_target, (int) get_post_meta( $owner_id, Hedayati_Teacher::META_USER_ID, true ) );

	$second_teacher = HDIT_Env::make_teacher( 'دومین استاد' );
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $nonce_as( $mgr, $SAVE ), 'teacher_id' => (string) $second_teacher, 'title' => 'دومین استاد',
		'linked_user' => (string) $link_target,
	], [ 'Hedayati_Teacher_Panel', 'handle_save' ] );
	HDIT::eq( 'a second teacher CANNOT claim an already-linked WP user (stays 0)', 0, (int) get_post_meta( $second_teacher, Hedayati_Teacher::META_USER_ID, true ) );
	HDIT::eq( 'the original link is untouched by the rejected claim', $link_target, (int) get_post_meta( $owner_id, Hedayati_Teacher::META_USER_ID, true ) );

	// Trash — manager can, student cannot.
	HDIT_AdminPost::run( $stu, [ '_wpnonce' => $nonce_as( $stu, $TRASH ), 'teacher_id' => (string) $second_teacher ], [ 'Hedayati_Teacher_Panel', 'handle_trash' ] );
	HDIT::eq( 'student POST to handle_trash -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );
	HDIT::ok( 'teacher post survives the unauthorised trash attempt (not trashed)', 'trash' !== get_post_status( $second_teacher ) );

	HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $nonce_as( $mgr, $TRASH ), 'teacher_id' => (string) $second_teacher ], [ 'Hedayati_Teacher_Panel', 'handle_trash' ] );
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

	// ── D53.D — Phase C: in-panel course create/edit ────────────────────────
	HDIT::section( 'D53.D — Hedayati_Course_Panel (Phase C, canonical course CPT)' );

	$views = Hedayati_Staff_Portal::module_views();
	HDIT::ok( 'panel registers course-new + course-edit views', isset( $views['course-new'], $views['course-edit'] ) );
	HDIT::eq( 'course editor gated on hedayati_manage_courses', 'hedayati_manage_courses', $views['course-edit']['capability'] ?? '' );

	$CSAVE = 'hedayati_course_panel_save';
	$cat   = wp_insert_term( 'hdit-cat-' . wp_generate_password( 6, false ), 'course-category' );
	$cat_id = is_wp_error( $cat ) ? 0 : (int) $cat['term_id'];

	$course_titles = static fn(): array => get_posts( [ 'post_type' => 'course', 'post_status' => [ 'publish', 'draft', 'pending' ], 'numberposts' => -1, 'fields' => 'ids' ] );
	$before_c = count( $course_titles() );

	HDIT_AdminPost::run( $mgr, [
		'_wpnonce'            => $nonce_as( $mgr, $CSAVE ),
		'course_id'          => '0',
		'title'              => 'دورهٔ آزمایشی پنل',
		'content'            => '<p>متن معرفی.</p>',
		'excerpt'            => 'خلاصه.',
		'english_name'       => 'Panel Test Course',
		'duration'           => '۳۰ ساعت',
		'registration_state' => 'open',
		'next_start_date'    => '۱۴۰۵/۰۶/۱۵',
		'syllabus'           => "ماژول یک\nماژول دو\n\nماژول سه",
		'menu_order'         => '5',
		'published'          => '1',
		'course_category'    => [ (string) $cat_id ],
	], [ 'Hedayati_Course_Panel', 'handle_save' ] );

	HDIT::eq( 'manager create adds exactly one course post', $before_c + 1, count( $course_titles() ) );
	$new_course = get_posts( [ 'post_type' => 'course', 'post_status' => 'any', 'numberposts' => 1, 'title' => 'دورهٔ آزمایشی پنل' ] )[0] ?? null;
	if ( $new_course ) {
		update_post_meta( $new_course->ID, HDIT::POST_MARKER, 1 );
	}
	HDIT::ok( 'created post is a course', $new_course && 'course' === $new_course->post_type );
	HDIT::eq( 'published checkbox -> publish', 'publish', $new_course ? $new_course->post_status : '' );
	$cid = $new_course ? (int) $new_course->ID : 0;
	HDIT::eq( 'english_name meta stored via the canonical key', 'Panel Test Course', (string) get_post_meta( $cid, '_course_english_name', true ) );
	HDIT::eq( 'registration_state stored + allowlist-sanitised', 'open', (string) get_post_meta( $cid, '_course_registration_state', true ) );
	HDIT::eq( 'Shamsi start date normalised to canonical Gregorian ISO', '2026-09-06', (string) get_post_meta( $cid, '_course_next_start_date', true ) );
	$syl = get_post_meta( $cid, '_course_syllabus', true );
	HDIT::eq( 'syllabus lines -> clean string array (blank line dropped)', [ 'ماژول یک', 'ماژول دو', 'ماژول سه' ], is_array( $syl ) ? array_values( $syl ) : $syl );
	HDIT::eq( 'menu_order applied', 5, (int) get_post_field( 'menu_order', $cid ) );
	HDIT::ok( 'category assigned on the canonical taxonomy', $cat_id > 0 && has_term( $cat_id, 'course-category', $cid ) );

	// Edit the same course.
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce'            => $nonce_as( $mgr, $CSAVE ),
		'course_id'          => (string) $cid,
		'title'              => 'دورهٔ آزمایشی پنل — ویرایش‌شده',
		'registration_state' => 'closed',
		'published'          => '1',
	], [ 'Hedayati_Course_Panel', 'handle_save' ] );
	HDIT::eq( 'edit updates the same post (no new post)', $before_c + 1, count( $course_titles() ) );
	HDIT::eq( 'edit changes the title', 'دورهٔ آزمایشی پنل — ویرایش‌شده', get_post_field( 'post_title', $cid ) );
	HDIT::eq( 'edit changes registration_state', 'closed', (string) get_post_meta( $cid, '_course_registration_state', true ) );

	// No nonce -> 403. Student -> 403.
	HDIT_AdminPost::run( $mgr, [ 'course_id' => '0', 'title' => 'x' ], [ 'Hedayati_Course_Panel', 'handle_save' ] );
	HDIT::eq( 'course handle_save without a nonce -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );
	HDIT_AdminPost::run( $stu, [ '_wpnonce' => $nonce_as( $stu, $CSAVE ), 'course_id' => '0', 'title' => 'x' ], [ 'Hedayati_Course_Panel', 'handle_save' ] );
	HDIT::eq( 'student POST to course handle_save -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// Administrator still edits courses natively.
	wp_set_current_user( $adm );
	HDIT::ok( 'administrator retains native edit_post on a course (Gutenberg path intact)', current_user_can( 'edit_post', $cid ) );
	wp_set_current_user( 0 );

	$course_audit = Hedayati_Audit_Log::query( [ 'object_type' => 'course', 'per_page' => 20, 'page' => 1 ] );
	HDIT::ok( 'course.created / course.updated audit rows exist', array_reduce( $course_audit, static fn( $c, $r ) => $c || in_array( $r['action'], [ 'course.created', 'course.updated' ], true ), false ) );

	// Synthetic taxonomy term is not namespaced by HDIT_Env — remove it here.
	if ( $cat_id > 0 ) {
		wp_delete_term( $cat_id, 'course-category' );
	}

	// ── D53.E — Phase E: academic operations in the panel ───────────────────
	HDIT::section( 'D53.E — Hedayati_Academic_Panel (Phase E, reuses Phase 2B services)' );

	$views = Hedayati_Staff_Portal::module_views();
	HDIT::ok( 'panel registers the "academic" module view', isset( $views['academic'] ) );
	HDIT::eq( 'academic view gated on hedayati_manage_course_runs', 'hedayati_manage_course_runs', $views['academic']['capability'] ?? '' );

	$ap_course = HDIT_Env::make_course( 'دورهٔ عملیات آزمایشی' );
	update_post_meta( $ap_course, HDIT::POST_MARKER, 1 );
	$ap_instr   = HDIT_Env::make_user( 'ap_instr', 'teacher' ); // has hedayati_record_attendance (manager does NOT)
	$ap_teacher = HDIT_Env::make_teacher( 'استاد عملیات', $ap_instr ); // CPT linked to the instructor account
	$ap_stu1    = HDIT_Env::make_user( 'ap_stu1', 'student' );
	$ap_stu2    = HDIT_Env::make_user( 'ap_stu2', 'student' );
	$other_run  = Hedayati_Course_Run_Service::create( [ 'course_id' => $ap_course, 'label' => 'کلاس دیگر' ] );
	$other_run  = is_wp_error( $other_run ) ? 0 : (int) $other_run;

	$AP = static fn( string $a ): string => $nonce_as( $mgr, 'hedayati_apanel_' . $a );

	// Create a run through the panel.
	$runs_before = count( Hedayati_Course_Run_Service::query( [ 'limit' => 500 ] ) );
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $AP( 'run_save' ), 'run_id' => '0',
		'course_id' => (string) $ap_course, 'label' => 'پاییز آزمایشی',
	], [ 'Hedayati_Academic_Panel', 'handle_run_save' ] );
	$runs_after = Hedayati_Course_Run_Service::query( [ 'limit' => 500, 'orderby' => 'created_at', 'order' => 'DESC' ] );
	HDIT::eq( 'manager creates a run via the panel', $runs_before + 1, count( $runs_after ) );
	$run = null;
	foreach ( $runs_after as $r ) {
		if ( 'پاییز آزمایشی' === $r['label'] ) { $run = $r; break; }
	}
	$run_id = $run ? (int) $run['id'] : 0;
	HDIT::ok( 'new run belongs to the chosen catalog course', $run && (int) $run['course_id'] === $ap_course );

	// Edit the run: status + Shamsi start date.
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $AP( 'run_save' ), 'run_id' => (string) $run_id,
		'run_status' => 'scheduled', 'registration_status' => 'open',
		'start_date' => '۱۴۰۵/۰۷/۰۱', 'capacity' => '2',
	], [ 'Hedayati_Academic_Panel', 'handle_run_save' ] );
	$run = Hedayati_Course_Run_Service::get( $run_id );
	HDIT::eq( 'run status updated', 'scheduled', $run['run_status'] );
	HDIT::eq( 'Shamsi start date stored as Gregorian ISO', '2026-09-23', (string) $run['start_date'] );
	HDIT::eq( 'capacity stored', 2, (int) $run['capacity'] );

	// Staff assignment.
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $AP( 'staff_assign' ), 'run_id' => (string) $run_id,
		'staff_role' => 'primary_instructor', 'teacher_id' => (string) $ap_teacher, 'user_id' => '0',
	], [ 'Hedayati_Academic_Panel', 'handle_staff_assign' ] );
	HDIT::ok( 'primary instructor assigned to the run', Hedayati_Run_Staff_Service::has_primary_instructor( $run_id ) );

	// Session create (Shamsi date + time).
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $AP( 'session_save' ), 'run_id' => (string) $run_id, 'session_id' => '0',
		'session_number' => '1', 'date' => '۱۴۰۵/۰۷/۰۳', 'time' => '18:00', 'topic' => 'جلسهٔ اول', 'status' => 'scheduled',
	], [ 'Hedayati_Academic_Panel', 'handle_session_save' ] );
	$sessions = Hedayati_Session_Service::list_for_run( $run_id );
	HDIT::eq( 'one session created via the panel', 1, count( $sessions ) );
	$session_id = $sessions ? (int) $sessions[0]['id'] : 0;
	HDIT::ok( 'session start stored as Gregorian datetime', str_starts_with( (string) $sessions[0]['starts_at'], '2026-09-25 18:00' ) );

	// Enrollment + capacity.
	HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $AP( 'enroll_add' ), 'run_id' => (string) $run_id, 'user_id' => (string) $ap_stu1 ], [ 'Hedayati_Academic_Panel', 'handle_enroll_add' ] );
	HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $AP( 'enroll_add' ), 'run_id' => (string) $run_id, 'user_id' => (string) $ap_stu2 ], [ 'Hedayati_Academic_Panel', 'handle_enroll_add' ] );
	HDIT::eq( 'both students enrolled (capacity 2)', 2, Hedayati_Enrollment_Service::count_active( $run_id ) );
	$stu3 = HDIT_Env::make_user( 'ap_stu3', 'student' );
	HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $AP( 'enroll_add' ), 'run_id' => (string) $run_id, 'user_id' => (string) $stu3 ], [ 'Hedayati_Academic_Panel', 'handle_enroll_add' ] );
	HDIT::eq( 'capacity is enforced by the service — a 3rd enrollment is refused', 2, Hedayati_Enrollment_Service::count_active( $run_id ) );

	// IDOR: an enrollment id from another run must be rejected for status change.
	$foreign_enr = $other_run > 0 ? Hedayati_Enrollment_Service::enroll( $other_run, $ap_stu1 ) : 0;
	$foreign_enr = is_wp_error( $foreign_enr ) ? 0 : (int) $foreign_enr;
	if ( $foreign_enr > 0 ) {
		HDIT_AdminPost::run( $mgr, [
			'_wpnonce' => $AP( 'enroll_status' ), 'run_id' => (string) $run_id,
			'enrollment_id' => (string) $foreign_enr, 'status' => 'withdrawn',
		], [ 'Hedayati_Academic_Panel', 'handle_enroll_status' ] );
		$fe = Hedayati_Enrollment_Service::get( $foreign_enr );
		HDIT::eq( 'IDOR: an enrollment from a different run cannot be mutated through this run', 'active', $fe['status'] );
	}

	// Attendance is recorded by the assigned INSTRUCTOR (hedayati_record_attendance);
	// the manager intentionally lacks that capability, matching the wp-admin class.
	HDIT::ok( 'the linked instructor account counts as staff on the run', Hedayati_Run_Staff_Service::user_is_staff_on_run( $ap_instr, $run_id ) );
	$att_nonce = static fn(): string => $nonce_as( $ap_instr, 'hedayati_apanel_attendance_save' );
	$my_enr = Hedayati_Enrollment_Service::get_by_run_user( $run_id, $ap_stu1 );
	$my_enr_id = $my_enr ? (int) $my_enr['id'] : 0;
	HDIT_AdminPost::run( $ap_instr, [
		'_wpnonce' => $att_nonce(), 'session_id' => (string) $session_id,
		'mark' => [ (string) $my_enr_id => 'present' ], 'note' => [ (string) $my_enr_id => 'به‌موقع' ],
	], [ 'Hedayati_Academic_Panel', 'handle_attendance_save' ] );
	$att = Hedayati_Attendance_Service::list_for_session( $session_id );
	HDIT::eq( 'attendance recorded for the enrolled student', 'present', $att[ $my_enr_id ]['status'] ?? '' );

	HDIT_AdminPost::run( $ap_instr, [
		'_wpnonce' => $att_nonce(), 'session_id' => (string) $session_id,
		'mark' => [ (string) ( $foreign_enr ?: 999999 ) => 'absent' ],
	], [ 'Hedayati_Academic_Panel', 'handle_attendance_save' ] );
	HDIT::eq( 'attendance batch with a foreign/forged enrollment id -> 400', 400, HDIT_AdminPost::$result['status'] ?? 0 );

	// The manager (no hedayati_record_attendance) is denied — faithful to the wp-admin policy.
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $AP( 'attendance_save' ), 'session_id' => (string) $session_id,
		'mark' => [ (string) $my_enr_id => 'late' ],
	], [ 'Hedayati_Academic_Panel', 'handle_attendance_save' ] );
	HDIT::eq( 'manager POST to attendance_save -> 403 (lacks hedayati_record_attendance)', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// Public opt-in toggle writes the canonical course meta allow-list.
	HDIT_AdminPost::run( $mgr, [
		'_wpnonce' => $AP( 'run_public' ), 'run_id' => (string) $run_id, 'make_public' => '1',
	], [ 'Hedayati_Academic_Panel', 'handle_run_public' ] );
	$approved = array_map( 'intval', (array) get_post_meta( $ap_course, Hedayati_Public_Content::META_PUBLIC_RUN_IDS, true ) );
	HDIT::ok( 'public opt-in adds the run id to _hedayati_public_run_ids', in_array( $run_id, $approved, true ) );

	// Role matrix: student and reception (lacks manage_course_runs) are denied.
	HDIT_AdminPost::run( $stu, [ '_wpnonce' => $nonce_as( $stu, 'hedayati_apanel_run_save' ), 'run_id' => '0', 'course_id' => (string) $ap_course ], [ 'Hedayati_Academic_Panel', 'handle_run_save' ] );
	HDIT::eq( 'student POST to academic run_save -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );
	HDIT_AdminPost::run( $rcpt, [ '_wpnonce' => $nonce_as( $rcpt, 'hedayati_apanel_session_save' ), 'run_id' => (string) $run_id, 'session_id' => '0' ], [ 'Hedayati_Academic_Panel', 'handle_session_save' ] );
	HDIT::eq( 'reception POST to academic session_save -> 403 (lacks hedayati_manage_course_runs)', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// Administrator keeps the native wp-admin academic screen.
	wp_set_current_user( $adm );
	HDIT::ok( 'administrator retains hedayati_manage_course_runs (native screen intact)', current_user_can( 'hedayati_manage_course_runs' ) );
	wp_set_current_user( 0 );

	// Run delete cascades via the service.
	HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $AP( 'run_delete' ), 'run_id' => (string) $run_id ], [ 'Hedayati_Academic_Panel', 'handle_run_delete' ] );
	HDIT::eq( 'manager deletes the run through the panel (service cascade)', null, Hedayati_Course_Run_Service::get( $run_id ) );

	// ── D53.F — Phase E: in-panel verification / private-document reviewer ──
	HDIT::section( 'D53.F — Hedayati_Verification_Panel (Phase E, Phase 2C invariants preserved)' );

	$vp_stu = HDIT_Env::make_user( 'vp_stu', 'student' );
	HDIT::not_wp_error( 'national ID set for the reviewer test (encrypted at rest by the service)', Hedayati_Verification_Service::set_national_id( $vp_stu, '0451739442', $mgr ) );
	HDIT::not_wp_error( 'verification initiated', Hedayati_Verification_Service::initiate( $vp_stu, $mgr ) );

	// Reception can never decrypt — invariant unchanged, checked at the service AND the panel.
	HDIT::is_wp_error( 'service: reception viewer cannot decrypt the national ID', Hedayati_Verification_Service::get_national_id_decrypted( $vp_stu, $rcpt ), 'forbidden' );
	HDIT_AdminPost::run( $rcpt, [ '_wpnonce' => $nonce_as( $rcpt, 'hedayati_vpanel_reveal_' . $vp_stu ), 'user_id' => (string) $vp_stu ], [ 'Hedayati_Verification_Panel', 'handle_reveal' ] );
	HDIT::eq( 'panel: reception POST to reveal -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// Manager (hedayati_verify_students) reveal: value rendered once, audited, PII-free note.
	$views_before = Hedayati_Audit_Log::count( [ 'action' => 'identity.viewed', 'object_id' => $vp_stu ] );
	ob_start();
	try {
		HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $nonce_as( $mgr, 'hedayati_vpanel_reveal_' . $vp_stu ), 'user_id' => (string) $vp_stu ], [ 'Hedayati_Verification_Panel', 'handle_reveal' ] );
	} finally {
		$reveal_out = ob_get_clean();
	}
	HDIT::ok( 'manager reveal: not 403', 403 !== ( HDIT_AdminPost::$result['status'] ?? 0 ) );
	HDIT::ok( 'manager reveal: response body contains the decrypted value', str_contains( (string) $reveal_out, '0451739442' ) );
	HDIT::eq( 'manager reveal: exactly one identity.viewed audit row added', $views_before + 1, Hedayati_Audit_Log::count( [ 'action' => 'identity.viewed', 'object_id' => $vp_stu ] ) );
	$last_view = Hedayati_Audit_Log::query( [ 'action' => 'identity.viewed', 'object_id' => $vp_stu, 'per_page' => 1 ] );
	HDIT::ok( 'the identity.viewed note carries no national-ID value', ! empty( $last_view ) && ! str_contains( $last_view[0]['note'], '0451739442' ) );

	// Approve / reject.
	HDIT_AdminPost::run( $rcpt, [ '_wpnonce' => $nonce_as( $rcpt, 'hedayati_vpanel_approve_' . $vp_stu ), 'user_id' => (string) $vp_stu ], [ 'Hedayati_Verification_Panel', 'handle_approve' ] );
	HDIT::eq( 'reception POST to approve -> 403 (lacks hedayati_verify_students)', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	HDIT_AdminPost::run( $mgr, [ '_wpnonce' => $nonce_as( $mgr, 'hedayati_vpanel_approve_' . $vp_stu ), 'user_id' => (string) $vp_stu, 'note' => 'مدارک کامل بود' ], [ 'Hedayati_Verification_Panel', 'handle_approve' ] );
	HDIT::eq( 'manager approves the pending verification via the panel', 'verified', Hedayati_Verification_Service::get_status( $vp_stu )['status'] );

	// The reviewer section self-gates: reception sees nothing, manager sees it.
	wp_set_current_user( $rcpt );
	ob_start();
	Hedayati_Verification_Panel::render_reviewer_section( $vp_stu );
	$rcpt_section = ob_get_clean();
	wp_set_current_user( $mgr );
	ob_start();
	Hedayati_Verification_Panel::render_reviewer_section( $vp_stu );
	$mgr_section = ob_get_clean();
	wp_set_current_user( 0 );
	HDIT::eq( 'reception: reviewer section renders nothing', '', trim( (string) $rcpt_section ) );
	HDIT::ok( 'manager: reviewer section renders the verification/documents block', str_contains( (string) $mgr_section, 'بررسی احراز هویت و مدارک' ) );
	HDIT::ok( 'reviewer section never emits a public document URL (download only via the nonced handler)', ! str_contains( (string) $mgr_section, 'wp-content/uploads' ) );

	// Administrator keeps the native wp-admin verification screen.
	wp_set_current_user( $adm );
	HDIT::ok( 'administrator retains hedayati_verify_students + hedayati_view_private_documents (native screen intact)', current_user_can( 'hedayati_verify_students' ) && current_user_can( 'hedayati_view_private_documents' ) );
	wp_set_current_user( 0 );
}
