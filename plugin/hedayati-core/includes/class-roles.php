<?php
/**
 * Application Roles and Granular Capabilities Manager.
 *
 * Manages registration and capability assignment for Hedayati institute roles:
 *   - student
 *   - teacher
 *   - teacher_assistant
 *   - reception
 *   - hedayati_manager
 *   - administrator (WordPress native, augmented with all Hedayati capabilities)
 *
 * Provides future-safe capability synchronization by tracking managed capabilities
 * in a persistent option (`hedayati_core_managed_capabilities`). Automatically cleans up
 * obsolete capabilities when updated across plugin versions without touching core WP or
 * third-party capabilities.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Roles {

	/**
	 * Version tracker for roles/capabilities synchronization.
	 */
	public const ROLES_VERSION = '2.4.0';
	public const OPTION_ROLES_VERSION = 'hedayati_core_roles_version';

	/**
	 * Option name storing the previously managed list of Hedayati capabilities.
	 */
	public const OPTION_MANAGED_CAPS = 'hedayati_core_managed_capabilities';

	/**
	 * Bootstrap roles initialization.
	 */
	public static function init(): void {
		// Only check role sync in admin to keep frontend lightweight
		add_action( 'admin_init', [ self::class, 'maybe_sync_roles' ] );
	}

	/**
	 * Synchronize roles and capabilities if version has updated.
	 */
	public static function maybe_sync_roles(): void {
		$installed_version = get_option( self::OPTION_ROLES_VERSION, '1.0.0' );

		if ( version_compare( (string) $installed_version, self::ROLES_VERSION, '<' ) ) {
			self::register_roles();
		}
	}

	/**
	 * Return the map of custom roles and their baseline capabilities.
	 *
	 * @return array<string, array{display_name: string, capabilities: array<string, bool>}>
	 */
	public static function get_roles_definition(): array {
		return [
			'student' => [
				'display_name' => 'دانشجو',
				'capabilities' => [
					'read'                          => true,
					'hedayati_view_own_portal'      => true,
					'hedayati_edit_own_profile'     => true,
					'hedayati_view_own_enrollments' => true,
					'hedayati_upload_own_documents' => true,
					'hedayati_view_own_certificates' => true,
					'hedayati_use_support_tickets'  => true,
				],
			],
			'teacher_assistant' => [
				'display_name' => 'استادیار / پشتیبان آموزشی',
				'capabilities' => [
					'read'                          => true,
					'hedayati_view_assigned_runs'   => true,
					'hedayati_view_assigned_roster' => true,
				],
			],
			'teacher' => [
				'display_name' => 'مدرس',
				'capabilities' => [
					'read'                               => true,
					'hedayati_view_assigned_runs'        => true,
					'hedayati_view_assigned_roster'      => true,
					'hedayati_manage_assigned_sessions'  => true,
					'hedayati_record_attendance'         => true,
					'hedayati_manage_session_materials'  => true,
				],
			],
			'reception' => [
				'display_name' => 'پذیرش و ثبت‌نام',
				'capabilities' => [
					'hedayati_create_students'               => true,
					'read'                                   => true,
					'hedayati_lookup_students'               => true,
					'hedayati_create_enrollments'            => true,
					'hedayati_edit_enrollments_basic'        => true,
					'hedayati_view_student_profiles_basic'   => true,
					'hedayati_initiate_verification'         => true,
					'hedayati_upload_student_documents'      => true,
					'hedayati_manage_consultations'          => true,
					'hedayati_manage_support_tickets'        => true,
				],
			],
			'hedayati_manager' => [
				'display_name' => 'مدیر آموزش مجتمع',
				'capabilities' => [
					'hedayati_create_students'               => true,
					'read'                                   => true,
					'hedayati_lookup_students'               => true,
					'hedayati_create_enrollments'            => true,
					'hedayati_edit_enrollments_basic'        => true,
					'hedayati_view_student_profiles_basic'   => true,
					'hedayati_initiate_verification'         => true,
					'hedayati_upload_student_documents'      => true,
					'hedayati_manage_courses'                => true,
					'hedayati_manage_teachers'               => true,
					'hedayati_manage_course_runs'            => true,
					'hedayati_assign_staff'                  => true,
					'hedayati_verify_students'               => true,
					'hedayati_view_private_documents'        => true,
					'hedayati_view_audit_logs'               => true,
					'hedayati_manage_enrollments'            => true,
					'hedayati_manage_settings'               => true,
					'hedayati_manage_consultations'          => true,
					'hedayati_manage_certificates'           => true,
					'hedayati_manage_session_materials'      => true,
					'hedayati_manage_support_tickets'        => true,
				],
			],
		];
	}

	/**
	 * Return the list of all managed Hedayati capabilities.
	 *
	 * @return string[]
	 */
	public static function get_all_hedayati_capabilities(): array {
		return [
			'hedayati_create_students',
			'hedayati_view_own_portal',
			'hedayati_edit_own_profile',
			'hedayati_view_own_enrollments',
			'hedayati_upload_own_documents',
			'hedayati_view_assigned_runs',
			'hedayati_view_assigned_roster',
			'hedayati_manage_assigned_sessions',
			'hedayati_record_attendance',
			'hedayati_lookup_students',
			'hedayati_create_enrollments',
			'hedayati_edit_enrollments_basic',
			'hedayati_view_student_profiles_basic',
			'hedayati_initiate_verification',
			'hedayati_manage_courses',
			'hedayati_manage_teachers',
			'hedayati_manage_course_runs',
			'hedayati_assign_staff',
			'hedayati_verify_students',
			'hedayati_view_private_documents',
			'hedayati_view_audit_logs',
			'hedayati_manage_enrollments',
			'hedayati_manage_settings',
			'hedayati_upload_student_documents',
			'hedayati_view_own_certificates',
			'hedayati_use_support_tickets',
			'hedayati_manage_consultations',
			'hedayati_manage_certificates',
			'hedayati_manage_session_materials',
			'hedayati_manage_support_tickets',
		];
	}

	/**
	 * Register all custom roles and synchronize capabilities.
	 * Explicitly removes obsolete Hedayati capabilities and unassigned capabilities
	 * without touching core WordPress or third-party capabilities.
	 */
	public static function register_roles(): void {
		$roles                  = self::get_roles_definition();
		$current_managed_caps   = self::get_all_hedayati_capabilities();
		$previous_managed_caps  = get_option( self::OPTION_MANAGED_CAPS, [] );

		if ( ! is_array( $previous_managed_caps ) ) {
			$previous_managed_caps = [];
		}

		// Obsolete capabilities = previously managed capabilities no longer present in current version
		$obsolete_caps = array_diff( $previous_managed_caps, $current_managed_caps );

		foreach ( $roles as $role_slug => $role_data ) {
			$role_obj = get_role( $role_slug );

			if ( ! $role_obj ) {
				add_role( $role_slug, $role_data['display_name'], $role_data['capabilities'] );
			} else {
				// 1. Remove obsolete capabilities that were removed from Hedayati Core
				foreach ( $obsolete_caps as $obs_cap ) {
					$role_obj->remove_cap( $obs_cap );
				}

				// 2. Grant intended capabilities
				foreach ( $role_data['capabilities'] as $cap => $grant ) {
					if ( $grant ) {
						$role_obj->add_cap( $cap );
					}
				}

				// 3. Revoke any currently managed Hedayati capability that is NOT assigned to this role
				foreach ( $current_managed_caps as $managed_cap ) {
					if ( empty( $role_data['capabilities'][ $managed_cap ] ) ) {
						$role_obj->remove_cap( $managed_cap );
					}
				}
			}
		}

		// Augment native WordPress administrator with all current Hedayati capabilities
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			// Remove obsolete capabilities from admin
			foreach ( $obsolete_caps as $obs_cap ) {
				$admin_role->remove_cap( $obs_cap );
			}

			// Add all current managed capabilities
			foreach ( $current_managed_caps as $cap ) {
				$admin_role->add_cap( $cap );
			}
		}

		// Persist the current managed capability list for future upgrade cleanup
		update_option( self::OPTION_MANAGED_CAPS, $current_managed_caps );
		update_option( self::OPTION_ROLES_VERSION, self::ROLES_VERSION );
	}
}
