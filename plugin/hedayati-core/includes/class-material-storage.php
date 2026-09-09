<?php
/**
 * AI Studio parity — private byte storage for course/session materials (D49).
 *
 * Deliberately a THIN wrapper over Hedayati_Document_Storage: it reuses the
 * hardened private-directory primitive (outside the webroot, `.htaccess` deny,
 * traversal/symlink-safe `stream()`) but is a separate class so the material
 * access *policy* (enrollment-scoped, in Hedayati_Material_Service) never shares
 * code with the identity-document access policy (privileged, audited). The
 * identity-document TABLE and capability are untouched.
 *
 * A leaked material storage_key is not directly streamable by a visitor: the
 * download handler resolves the key from a material row it has already
 * authorised, never from caller input.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Material_Storage {

	/**
	 * @param array $php_file_upload one $_FILES element (caller already checked it is a real upload)
	 * @return array{storage_key:string, mime:string, size:int}|WP_Error
	 */
	public static function save( int $run_id, array $php_file_upload ): array|WP_Error {
		// The int prefix on the storage key is the run id (namespacing only).
		return Hedayati_Document_Storage::save( $run_id, $php_file_upload );
	}

	/** Caller MUST have authorised the viewer (enrollment or staff-on-run) first. */
	public static function stream( string $storage_key ): true|WP_Error {
		return Hedayati_Document_Storage::stream( $storage_key );
	}

	public static function delete( string $storage_key ): true|WP_Error {
		return Hedayati_Document_Storage::delete( $storage_key );
	}
}
