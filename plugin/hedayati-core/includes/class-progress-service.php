<?php
/**
 * AI Studio parity — objective student/run progress (owner decision D47).
 *
 * NO invented percentages. Two clearly separated concepts, both derived live
 * from existing Phase 2B data (sessions + attendance):
 *
 *   RUN PROGRESS       held/past sessions ÷ total scheduled sessions
 *                      → "how far the course run itself has progressed"
 *
 *   ATTENDANCE RATE    the student's 'present' (or 'late') marks ÷ sessions for
 *                      which that student has any recorded attendance
 *                      → "the student's participation so far"
 *
 * These are never combined into a single "completion" number, and no grade /
 * score / exam / pass-fail is implied — the data model does not carry those.
 * Zero-session runs return null ratios (callers render "—", never 0%).
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Progress_Service {

	/** Attendance marks that count as "participated" for the attendance rate. */
	private const PRESENT_STATUSES = [ 'present', 'late', 'excused' ];

	/**
	 * Progress of a course run itself.
	 *
	 * @return array{total:int, held:int, ratio:?float, upcoming:int}
	 */
	public static function run_progress( int $run_id ): array {
		$sessions = array_filter(
			Hedayati_Session_Service::list_for_run( $run_id ),
			static fn( array $s ): bool => 'cancelled' !== $s['status']
		);
		$total = count( $sessions );

		if ( 0 === $total ) {
			return [ 'total' => 0, 'held' => 0, 'ratio' => null, 'upcoming' => 0 ];
		}

		$now  = current_time( 'mysql' );
		$held = 0;

		foreach ( $sessions as $session ) {
			$is_past = (string) $session['starts_at'] <= $now;
			if ( 'held' === $session['status'] || $is_past ) {
				$held++;
			}
		}

		return [
			'total'    => $total,
			'held'     => $held,
			'ratio'    => $total > 0 ? round( $held / $total, 4 ) : null,
			'upcoming' => max( 0, $total - $held ),
		];
	}

	/**
	 * One student's attendance summary within a run.
	 *
	 * @return array{recorded:int, present:int, absent:int, ratio:?float}
	 */
	public static function attendance_summary( int $run_id, int $user_id ): array {
		$enrollment = Hedayati_Enrollment_Service::get_by_run_user( $run_id, $user_id );

		$empty = [ 'recorded' => 0, 'present' => 0, 'absent' => 0, 'ratio' => null ];
		if ( null === $enrollment ) {
			return $empty;
		}

		$recorded = 0;
		$present  = 0;
		$absent   = 0;

		foreach ( Hedayati_Session_Service::list_for_run( $run_id ) as $session ) {
			foreach ( Hedayati_Attendance_Service::list_for_session( (int) $session['id'] ) as $mark ) {
				if ( (int) $mark['enrollment_id'] !== (int) $enrollment['id'] ) {
					continue;
				}
				$recorded++;
				if ( in_array( $mark['status'], self::PRESENT_STATUSES, true ) ) {
					$present++;
				} elseif ( 'absent' === $mark['status'] ) {
					$absent++;
				}
			}
		}

		return [
			'recorded' => $recorded,
			'present'  => $present,
			'absent'   => $absent,
			'ratio'    => $recorded > 0 ? round( $present / $recorded, 4 ) : null,
		];
	}

	/** Whole-number percent for display, or null when there is no basis. */
	public static function percent( ?float $ratio ): ?int {
		return null === $ratio ? null : (int) round( $ratio * 100 );
	}

	/**
	 * Combined per-enrollment view used by the student portal.
	 *
	 * @return array{run_progress:array, attendance:array}
	 */
	public static function for_enrollment( int $run_id, int $user_id ): array {
		return [
			'run_progress' => self::run_progress( $run_id ),
			'attendance'   => self::attendance_summary( $run_id, $user_id ),
		];
	}
}
