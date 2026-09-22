<?php
/**
 * D58 runtime acceptance — AI-Studio app shell navigation.
 *
 * The redesign moved the /panel/ sidebar into Hedayati_Staff_Portal::nav_groups().
 * These checks prove, per real role, that (a) every nav item is a view the
 * user is actually allowed to open, (b) every view the user may open is still
 * reachable from the nav, (c) every view still renders, and (d) the new staff
 * notification inbox is strictly owner-scoped.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

/** The capability that gates $slug for the current user's view resolution. */
function hdit_d58_gate_ok( string $slug ): bool {
	if ( '' === $slug ) {
		return Hedayati_Staff_Portal::allowed();
	}
	if ( in_array( $slug, [ 'courses', 'featured' ], true ) ) {
		return current_user_can( 'hedayati_manage_courses' ) && Hedayati_Staff_Portal::is_manager_workspace();
	}
	if ( 'students' === $slug ) {
		return current_user_can( 'hedayati_lookup_students' );
	}
	return Hedayati_Staff_Portal::can_view_module( $slug );
}

/** @return string[] every slug in the current user's nav */
function hdit_d58_nav_slugs(): array {
	$slugs = [];
	foreach ( Hedayati_Staff_Portal::nav_groups() as $group ) {
		foreach ( $group['items'] as $item ) {
			$slugs[] = (string) $item['slug'];
		}
	}
	return $slugs;
}

function hdit_run_d58(): void {
	HDIT::section( 'D58 — panel navigation respects capabilities and keeps every module reachable' );

	$users = [
		'administrator'     => HDIT_Env::make_user( 'd58_admin', 'administrator' ),
		'hedayati_manager'  => HDIT_Env::make_user( 'd58_mgr', 'hedayati_manager' ),
		'reception'         => HDIT_Env::make_user( 'd58_rcpt', 'reception' ),
		'teacher'           => HDIT_Env::make_user( 'd58_tchr', 'teacher' ),
		'teacher_assistant' => HDIT_Env::make_user( 'd58_ta', 'teacher_assistant' ),
	];
	$stu = HDIT_Env::make_user( 'd58_stu', 'student' );

	$all_core = [ 'courses', 'featured', 'students' ];

	foreach ( $users as $role => $uid ) {
		wp_set_current_user( $uid );
		$slugs = hdit_d58_nav_slugs();

		$bad = array_values( array_filter( $slugs, static fn( $s ) => ! hdit_d58_gate_ok( $s ) ) );
		HDIT::eq( "{$role}: every nav item is a view this role may open", [], $bad );

		$reachable = array_merge( $all_core, array_keys( Hedayati_Staff_Portal::module_views() ) );
		$missing   = [];
		foreach ( $reachable as $slug ) {
			$module = Hedayati_Staff_Portal::module_views()[ $slug ] ?? null;
			if ( null !== $module && empty( $module['nav'] ) ) {
				continue; // non-nav helper views (e.g. editors) are reached from their parent screen
			}
			if ( hdit_d58_gate_ok( $slug ) && ! in_array( $slug, $slugs, true ) ) {
				$missing[] = $slug;
			}
		}
		HDIT::eq( "{$role}: every view this role may open is reachable from the nav", [], $missing );
		HDIT::eq( "{$role}: nav has no duplicate items", count( $slugs ), count( array_unique( $slugs ) ) );
		HDIT::ok( "{$role}: nav never links into wp-admin", ! str_contains( wp_json_encode( Hedayati_Staff_Portal::nav_groups() ), 'wp-admin' ) );
	}

	wp_set_current_user( $users['hedayati_manager'] );
	$mgr_slugs = hdit_d58_nav_slugs();
	foreach ( [ 'courses', 'featured', 'academic', 'teachers', 'materials', 'certificates', 'students', 'consultations', 'support', 'content', 'navigation', 'settings', 'notifications', 'audit', 'security' ] as $slug ) {
		HDIT::ok( "manager nav keeps module «{$slug}»", in_array( $slug, $mgr_slugs, true ) );
	}

	// Every nav view still renders for the manager (no fatal, real markup).
	foreach ( $mgr_slugs as $slug ) {
		$_GET['view'] = $slug;
		ob_start();
		try {
			Hedayati_Staff_Portal::render();
			$html = (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$html = '';
		}
		HDIT::ok( 'manager view «' . ( '' === $slug ? 'home' : $slug ) . '» renders', strlen( trim( $html ) ) > 50 );
	}
	unset( $_GET['view'] );

	ob_start();
	Hedayati_Staff_Portal::render();
	$home = (string) ob_get_clean();
	HDIT::eq( 'manager home shows exactly four KPI tiles', 4, substr_count( $home, 'class="hd-manager-kpi"' ) );
	HDIT::ok( 'manager home quick actions: between one and four', substr_count( $home, 'class="hd-manager-action"' ) >= 1 && substr_count( $home, 'class="hd-manager-action"' ) <= 4 );
	HDIT::ok( 'manager home keeps KPIs + operations and adds the overview/status layout', str_contains( $home, 'hd-manager-kpis' ) && str_contains( $home, 'مرکز عملیات' ) && str_contains( $home, 'hd-manager-overview' ) && str_contains( $home, 'hd-manager-status-card' ) );

	$_GET['view'] = 'course-edit';
	HDIT::eq( 'course editor highlights «courses» in the nav', 'courses', Hedayati_Staff_Portal::nav_active_slug() );
	$_GET['view'] = 'run';
	HDIT::eq( 'a run page highlights «academic» for the manager', 'academic', Hedayati_Staff_Portal::nav_active_slug() );
	unset( $_GET['view'] );

	wp_set_current_user( $users['reception'] );
	$r = hdit_d58_nav_slugs();
	HDIT::ok( 'reception nav: students, consultations, support present', ! array_diff( [ 'students', 'consultations', 'support' ], $r ) );
	HDIT::ok( 'reception nav: no courses/featured/settings/certificates/audit/navigation/content', ! array_intersect( [ 'courses', 'featured', 'settings', 'certificates', 'audit', 'navigation', 'content' ], $r ) );
	HDIT::eq( 'reception role label', 'پذیرش و ثبت‌نام', Hedayati_Staff_Portal::role_label( wp_get_current_user() ) );

	wp_set_current_user( $users['teacher'] );
	$t = hdit_d58_nav_slugs();
	HDIT::ok( 'teacher nav: home + materials, never students/courses/consultations', in_array( '', $t, true ) && in_array( 'materials', $t, true ) && ! array_intersect( [ 'students', 'courses', 'consultations', 'settings' ], $t ) );
	$_GET['view'] = 'run';
	HDIT::eq( 'a run page highlights the home item for a teacher', '', Hedayati_Staff_Portal::nav_active_slug() );
	unset( $_GET['view'] );

	wp_set_current_user( $users['administrator'] );
	HDIT::eq( 'administrator role label', 'مدیر سیستم', Hedayati_Staff_Portal::role_label( wp_get_current_user() ) );

	// ── Staff notification inbox is owner-scoped ─────────────────────────
	HDIT::section( 'D58 — staff notification inbox (/panel/?view=notifications) is owner-scoped' );

	$mine   = Hedayati_Notification_Service::notify( $users['hedayati_manager'], 'test', 'HDIT D58 manager-only subject' );
	$theirs = Hedayati_Notification_Service::notify( $users['reception'], 'test', 'HDIT D58 reception-only subject' );
	HDIT::ok( 'two synthetic notifications stored', false !== $mine && false !== $theirs );

	wp_set_current_user( $users['hedayati_manager'] );
	HDIT::ok( 'notifications module is visible to staff', Hedayati_Staff_Portal::can_view_module( 'notifications' ) );
	$badge = 0;
	foreach ( Hedayati_Staff_Portal::nav_groups() as $group ) {
		foreach ( $group['items'] as $item ) {
			if ( 'notifications' === $item['slug'] ) {
				$badge = (int) $item['badge'];
			}
		}
	}
	HDIT::eq( 'nav badge equals the real unread count', Hedayati_Notification_Service::unread_count( $users['hedayati_manager'] ), $badge );
	ob_start();
	Hedayati_Notification_Service::render_panel();
	$inbox = (string) ob_get_clean();
	HDIT::ok( 'manager inbox lists the manager notification', str_contains( $inbox, 'HDIT D58 manager-only subject' ) );
	HDIT::ok( 'manager inbox never shows another user\'s notification', ! str_contains( $inbox, 'HDIT D58 reception-only subject' ) );
	HDIT::ok( 'inbox read actions are nonce-protected', str_contains( $inbox, 'hedayati_notif_read' ) && str_contains( $inbox, 'name="_wpnonce"' ) );
	HDIT::ok( 'marking a foreign notification as read is refused', false === Hedayati_Notification_Service::mark_read( (int) $theirs, $users['hedayati_manager'] ) );

	// ── Student account views all still render ─────────────────────────────
	HDIT::section( 'D58 — every student account view still renders inside the new shell' );
	wp_set_current_user( $stu );
	foreach ( Hedayati_Student_Portal::VIEWS as $view ) {
		$_GET['view'] = $view;
		try {
			$html = (string) Hedayati_Student_Portal::render_current_view();
		} catch ( \Throwable $e ) {
			$html = '';
		}
		HDIT::ok( "student view «{$view}» renders", strlen( trim( $html ) ) > 50 );
	}
	unset( $_GET['view'] );

	// Dashboard progress rows reflect real sessions (and nothing without a basis).
	$course = HDIT_Env::make_course( 'D58 progress course' );
	$run    = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'running' ] );
	HDIT::not_wp_error( 'synthetic running course run created', $run );
	$now = current_time( 'timestamp' );
	foreach ( [ -3, 4 ] as $i => $days ) {
		Hedayati_Session_Service::create( [
			'run_id'         => (int) $run,
			'session_number' => $i + 1,
			'starts_at'      => wp_date( 'Y-m-d', $now + $days * DAY_IN_SECONDS ) . ' 10:00:00',
			'topic'          => 'D58 topic ' . $i,
		] );
	}
	HDIT::not_wp_error( 'student enrolled in a run with one held + one upcoming session', Hedayati_Enrollment_Service::enroll( (int) $run, $stu ) );
	$_GET['view'] = 'dashboard';
	$dash         = (string) Hedayati_Student_Portal::render_current_view();
	HDIT::ok( 'student dashboard shows a course progress row', str_contains( $dash, 'hd-student-course-row' ) );
	HDIT::ok( 'progress is the real held/total ratio (50%)', str_contains( $dash, 'aria-valuenow="50"' ) );
	HDIT::ok( 'next-class card shows the real upcoming topic', str_contains( $dash, 'D58 topic 1' ) );
	HDIT::ok( 'no certificate → honest empty state, no "verified" chip', ! str_contains( $dash, 'hd-student-verified' ) );
	unset( $_GET['view'] );
	wp_set_current_user( 0 );
}
