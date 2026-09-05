<?php
/**
 * Phase 2C — Private document filesystem layer (D14/D38).
 *
 * The abstract "storage_backend + storage_key" promise from D14 lives here:
 * `Hedayati_Document_Service` (the DB-facing layer) never touches a filesystem
 * path directly. This class has no idea who is allowed to see what — capability
 * and ownership checks happen in the caller, same boundary as every other
 * Hedayati_*_Service.
 *
 * Storage root resolution fails closed outside a local/Docker-CI environment
 * unless HEDAYATI_PRIVATE_UPLOADS_DIR is explicitly configured to a real path
 * outside the webroot — there is no silent "just use wp-content" fallback in
 * production/staging.
 *
 * Every stream()/delete() call re-resolves and verifies the on-disk path is
 * canonically contained within the storage root before touching the
 * filesystem — a corrupted or tampered storage_key can never escape the root
 * (no ../ traversal, no absolute paths, no symlink escape).
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Document_Storage {

	/**
	 * MIME => allowed extension. Order matters only for readability.
	 */
	private const ALLOWED_TYPES = [
		'application/pdf' => 'pdf',
		'image/jpeg'       => 'jpg',
		'image/png'        => 'png',
	];

	private const DEFAULT_MAX_BYTES = 8 * 1024 * 1024;

	private const STORAGE_KEY_PATTERN = '/^[0-9]+\/[A-Za-z0-9]{32}\.(pdf|jpg|png)$/';

	/**
	 * Validate, sniff, and persist an uploaded file's bytes under a randomized
	 * name. Never trusts the client-declared MIME type or the original filename.
	 *
	 * @param array $php_file_upload one element of $_FILES (already validated to
	 *                                be a successful single-file upload by the caller)
	 * @return array{storage_key:string, mime:string, size:int}|WP_Error
	 */
	public static function save( int $user_id, array $php_file_upload ): array|WP_Error {
		$root = self::resolve_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$tmp_path = (string) ( $php_file_upload['tmp_name'] ?? '' );

		if ( '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
			return new WP_Error( 'invalid_upload', esc_html__( 'فایل ارسالی نامعتبر است.', 'hedayati-core' ) );
		}

		$size = (int) ( $php_file_upload['size'] ?? 0 );

		return self::process_and_store( $user_id, $root, $tmp_path, $size );
	}

	/**
	 * Everything after "this really came from an HTTP upload" is validated.
	 * Split out from save() so it stays a single, independently testable
	 * pure(-ish) function — `is_uploaded_file()` is only ever true for a real
	 * HTTP upload, which a CLI/test harness cannot fabricate, so the Docker
	 * acceptance suite exercises sniffing/randomization/storage/path-hardening
	 * through THIS method via reflection while still relying on save() (the
	 * only method wired to real $_FILES handling) to enforce the upload-origin
	 * check in every real request.
	 *
	 * @return array{storage_key:string, mime:string, size:int}|WP_Error
	 */
	private static function process_and_store( int $user_id, string $root, string $tmp_path, int $size ): array|WP_Error {
		$max_bytes = (int) apply_filters( 'hedayati_document_max_bytes', self::DEFAULT_MAX_BYTES );

		if ( $size <= 0 || $size > $max_bytes || $size !== filesize( $tmp_path ) ) {
			return new WP_Error( 'file_too_large', esc_html__( 'حجم فایل مجاز نیست.', 'hedayati-core' ) );
		}

		$sniffed = self::sniff_real_type( $tmp_path );
		if ( is_wp_error( $sniffed ) ) {
			return $sniffed;
		}

		[ $real_mime, $ext ] = $sniffed;

		$storage_key = self::generate_storage_key( $user_id, $ext );
		$dest_path   = self::path_for_key( $root, $storage_key );

		$dest_dir = dirname( $dest_path );
		if ( ! is_dir( $dest_dir ) && ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'storage_write_failed', esc_html__( 'ذخیره‌سازی فایل ناموفق بود.', 'hedayati-core' ) );
		}

		if ( ! copy( $tmp_path, $dest_path ) ) {
			return new WP_Error( 'storage_write_failed', esc_html__( 'ذخیره‌سازی فایل ناموفق بود.', 'hedayati-core' ) );
		}

		chmod( $dest_path, 0640 );

		return [
			'storage_key' => $storage_key,
			'mime'        => $real_mime,
			'size'        => $size,
		];
	}

	/**
	 * The only read path. Streams the file with a generic filename and no-cache,
	 * no-sniff headers. Caller MUST have already checked capability + ownership.
	 *
	 * @return true|WP_Error
	 */
	public static function stream( string $storage_key ): true|WP_Error {
		$path = self::resolve_and_verify_path( $storage_key );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$mime = self::mime_for_extension( pathinfo( $path, PATHINFO_EXTENSION ) );

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="document.' . pathinfo( $path, PATHINFO_EXTENSION ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		readfile( $path );

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete( string $storage_key ): true|WP_Error {
		$path = self::resolve_and_verify_path( $storage_key );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! file_exists( $path ) ) {
			return true;
		}

		if ( ! unlink( $path ) ) {
			return new WP_Error( 'storage_delete_failed', esc_html__( 'حذف فایل ناموفق بود.', 'hedayati-core' ) );
		}

		return true;
	}

	// ── Storage root resolution ─────────────────────────────────────────────

	/**
	 * @return string|WP_Error Absolute, real, writable storage root.
	 */
	public static function resolve_root(): string|WP_Error {
		if ( defined( 'HEDAYATI_PRIVATE_UPLOADS_DIR' ) && '' !== (string) HEDAYATI_PRIVATE_UPLOADS_DIR ) {
			$configured = (string) HEDAYATI_PRIVATE_UPLOADS_DIR;

			if ( ! is_dir( $configured ) && ! wp_mkdir_p( $configured ) ) {
				return new WP_Error( 'storage_root_invalid', esc_html__( 'مسیر ذخیره‌سازی خصوصی معتبر نیست.', 'hedayati-core' ) );
			}

			$real_root    = realpath( $configured );
			$real_webroot = realpath( ABSPATH );

			if ( false === $real_root || false === $real_webroot ) {
				return new WP_Error( 'storage_root_invalid', esc_html__( 'مسیر ذخیره‌سازی خصوصی معتبر نیست.', 'hedayati-core' ) );
			}

			if ( self::is_within( $real_webroot, $real_root ) ) {
				return new WP_Error( 'storage_root_invalid', esc_html__( 'مسیر ذخیره‌سازی خصوصی نباید داخل ریشهٔ وب باشد.', 'hedayati-core' ) );
			}

			if ( ! is_writable( $real_root ) ) {
				return new WP_Error( 'storage_root_invalid', esc_html__( 'مسیر ذخیره‌سازی خصوصی قابل نوشتن نیست.', 'hedayati-core' ) );
			}

			return $real_root;
		}

		if ( 'local' === wp_get_environment_type() ) {
			return self::bootstrap_local_fallback();
		}

		return new WP_Error(
			'storage_not_configured',
			esc_html__( 'این ویژگی نیازمند پیکربندی مسیر ذخیره‌سازی خصوصی در محیط میزبان است.', 'hedayati-core' )
		);
	}

	/**
	 * Local/Docker-CI ONLY fallback: wp-content/uploads/hedayati-private/,
	 * protected with a Deny-all .htaccess + a silence index.php. Defense in
	 * depth only — the real control is that stream()/delete() are the only
	 * read/write paths and every caller checks capability + ownership first.
	 *
	 * @return string|WP_Error
	 */
	private static function bootstrap_local_fallback(): string|WP_Error {
		$upload_dir = wp_upload_dir();
		$root       = trailingslashit( $upload_dir['basedir'] ) . 'hedayati-private';

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'storage_root_invalid', esc_html__( 'مسیر ذخیره‌سازی خصوصی معتبر نیست.', 'hedayati-core' ) );
		}

		$htaccess = trailingslashit( $root ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}

		$index = trailingslashit( $root ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$real_root = realpath( $root );

		return false !== $real_root ? $real_root : new WP_Error( 'storage_root_invalid', esc_html__( 'مسیر ذخیره‌سازی خصوصی معتبر نیست.', 'hedayati-core' ) );
	}

	// ── Path containment (traversal/symlink hardening) ──────────────────────

	/**
	 * Validate a storage_key against a strict allowlist pattern, then resolve
	 * it to a canonical path and require that path to be contained within the
	 * storage root. Rejects traversal, absolute keys, and symlink escapes
	 * BEFORE any filesystem call is made against untrusted input.
	 *
	 * @return string|WP_Error
	 */
	private static function resolve_and_verify_path( string $storage_key ): string|WP_Error {
		if ( 1 !== preg_match( self::STORAGE_KEY_PATTERN, $storage_key ) ) {
			return new WP_Error( 'invalid_storage_key', esc_html__( 'شناسهٔ ذخیره‌سازی نامعتبر است.', 'hedayati-core' ) );
		}

		$root = self::resolve_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$candidate = self::path_for_key( $root, $storage_key );
		$real      = realpath( $candidate );

		if ( false === $real ) {
			return new WP_Error( 'invalid_storage_key', esc_html__( 'فایل مورد نظر یافت نشد.', 'hedayati-core' ) );
		}

		if ( ! self::is_within( $root, $real ) ) {
			return new WP_Error( 'invalid_storage_key', esc_html__( 'شناسهٔ ذخیره‌سازی نامعتبر است.', 'hedayati-core' ) );
		}

		return $real;
	}

	private static function path_for_key( string $root, string $storage_key ): string {
		return rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $storage_key );
	}

	/**
	 * True if $path is canonically contained within $root (path-separator-aware,
	 * not a naive string prefix — requires a boundary at a directory separator).
	 */
	private static function is_within( string $root, string $path ): bool {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		$path = str_replace( '\\', '/', $path ) . '/';

		return str_starts_with( $path, $root );
	}

	// ── Content validation ──────────────────────────────────────────────────

	/**
	 * Real, content-derived MIME classification. `wp_check_filetype_and_ext()`
	 * alone is not sufficient content-sniffing for this threat model (it largely
	 * trusts the extension) — this uses finfo on the actual bytes, plus a magic
	 * header check for PDF and a structural image-parse check for JPEG/PNG.
	 * Anything that cannot be CONFIDENTLY classified into the allowlist fails
	 * closed rather than "best guess accept."
	 *
	 * @return array{0:string,1:string}|WP_Error [mime, extension]
	 */
	private static function sniff_real_type( string $tmp_path ): array|WP_Error {
		if ( ! function_exists( 'finfo_open' ) ) {
			return new WP_Error( 'mime_check_unavailable', esc_html__( 'بررسی نوع فایل ممکن نیست.', 'hedayati-core' ) );
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return new WP_Error( 'mime_check_unavailable', esc_html__( 'بررسی نوع فایل ممکن نیست.', 'hedayati-core' ) );
		}

		$sniffed_mime = finfo_file( $finfo, $tmp_path );
		finfo_close( $finfo );

		if ( false === $sniffed_mime || ! isset( self::ALLOWED_TYPES[ $sniffed_mime ] ) ) {
			return new WP_Error( 'invalid_file_type', esc_html__( 'نوع فایل مجاز نیست.', 'hedayati-core' ) );
		}

		if ( 'application/pdf' === $sniffed_mime ) {
			$header = file_get_contents( $tmp_path, false, null, 0, 5 );
			if ( false === $header || '%PDF-' !== $header ) {
				return new WP_Error( 'invalid_file_type', esc_html__( 'فایل PDF معتبر نیست.', 'hedayati-core' ) );
			}
		} else {
			$image_info = @getimagesize( $tmp_path );
			if ( false === $image_info || ( $image_info['mime'] ?? '' ) !== $sniffed_mime ) {
				return new WP_Error( 'invalid_file_type', esc_html__( 'فایل تصویری معتبر نیست.', 'hedayati-core' ) );
			}
		}

		// Secondary, WordPress-idiomatic check — belt and suspenders, not
		// authoritative on its own for this threat model.
		$wp_check = wp_check_filetype_and_ext( $tmp_path, 'upload.' . self::ALLOWED_TYPES[ $sniffed_mime ] );
		if ( ( $wp_check['type'] ?? '' ) !== $sniffed_mime ) {
			return new WP_Error( 'invalid_file_type', esc_html__( 'نوع فایل مجاز نیست.', 'hedayati-core' ) );
		}

		return [ $sniffed_mime, self::ALLOWED_TYPES[ $sniffed_mime ] ];
	}

	private static function mime_for_extension( string $ext ): string {
		$flipped = array_flip( self::ALLOWED_TYPES );
		return $flipped[ strtolower( $ext ) ] ?? 'application/octet-stream';
	}

	private static function generate_storage_key( int $user_id, string $ext ): string {
		return $user_id . '/' . wp_generate_password( 32, false, false ) . '.' . $ext;
	}
}
