/**
 * Node.js static & logic verification for Phase 2C: the foundation slice
 * (student profile address fields) PLUS the full identity/verification/
 * private-document build (national-ID encryption, verification workflow,
 * document storage, staff-only admin UI, roles/capabilities, audit vocabulary).
 *
 * Real WordPress-runtime behaviour (DB writes, capability enforcement, upload
 * round-trips) is verified by the Docker acceptance suite
 * (docker/wp-tests/test-phase-2c.php), not here — this file is static/
 * structural only.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
let passed = 0;
let failed = 0;
const assert = (d, c) => { if (c) { console.log(`  [PASS] ${d}`); passed++; } else { console.error(`  [FAIL] ${d}`); failed++; } };
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');

// ── Pure-logic port of the postal-code rules ─────────────────────────────────

const DIGIT_MAP = {
	'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
	'٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9',
};
const toAscii = (s) => String(s).replace(/[۰-۹٠-٩]/g, (c) => DIGIT_MAP[c] || c);
const sanitizePostal = (v) => toAscii(v).replace(/\D/g, '');
const postalValid = (v) => v === '' || /^\d{10}$/.test(v);

console.log('=== NODE.JS PHASE 2C (FOUNDATION) VERIFICATION ===\n');

console.log('1. Postal-code sanitize + validate:');
assert("Persian digits normalize", sanitizePostal('۵۱۶۶۶۱۳۷۶۹') === '5166613769');
assert("separators / letters stripped", sanitizePostal('51666-1376 9x') === '5166613769');
assert("empty stays empty (optional field)", sanitizePostal('') === '' && postalValid(''));
assert("exactly 10 digits is valid", postalValid('5166613769'));
assert("9 digits rejected", !postalValid('516661376'));
assert("11 digits rejected", !postalValid('51666137690'));
assert("normalized-then-checked Persian code is valid", postalValid(sanitizePostal('۵۱۶۶۶۱۳۷۶۹')));

// ── Structure / wiring ──────────────────────────────────────────────────────

console.log('\n2. class-student-profile.php structure & wiring:');
const sp = read('includes/class-student-profile.php');
const boot = read('hedayati-core.php');

assert("declares strict_types", sp.includes('declare( strict_types=1 );'));
assert("has ABSPATH guard", sp.includes("if ( ! defined( 'ABSPATH' ) ) {"));
const ob = (sp.match(/{/g) || []).length, cb = (sp.match(/}/g) || []).length;
assert(`braces balanced (${ob}/${cb})`, ob === cb);

assert("stores address in usermeta (not a custom table, per ROADMAP P1.2)", sp.includes("register_meta( 'user'"));
const spCode = sp
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/(^|[^:])\/\/[^\n]*/g, '$1');
assert("no national-ID meta registered (blocked on D15 key)", !/national|melli|کد ملی/i.test(spCode));
assert("no verification-state meta registered (blocked on reset rules)", !/verification|is_verified/i.test(spCode));
assert("postal code normalized via Hedayati_Text", sp.includes('Hedayati_Text::digits_to_ascii'));
assert("self-edit gated on hedayati_edit_own_profile", sp.includes("current_user_can( 'hedayati_edit_own_profile' )"));
assert("other-user access gated on hedayati_view_student_profiles_basic + edit_user", sp.includes("hedayati_view_student_profiles_basic") && sp.includes("current_user_can( 'edit_user', $target_user_id )"));
assert("validation blocks save via user_profile_update_errors", sp.includes("'user_profile_update_errors'") && sp.includes('$errors->add('));
assert("save re-checks the Hedayati capability", /function save\([\s\S]*?current_user_can_edit\(/.test(sp));
assert("save only accepts string POST values", sp.includes("is_string( $_POST[ $field['meta'] ] )"));
assert("output is escaped (esc_attr/esc_html/esc_textarea)", sp.includes('esc_textarea(') && sp.includes('esc_html__('));
assert("extensible via hedayati_student_profile_fields filter", sp.includes("apply_filters( 'hedayati_student_profile_fields'"));
assert("exposes a read API Hedayati_Student_Profile::get()", /public static function get\( int \$user_id \): array/.test(sp));

assert("plugin requires the class", boot.includes('includes/class-student-profile.php'));
assert("plugin boots Hedayati_Student_Profile::init()", boot.includes('Hedayati_Student_Profile::init()'));
assert("plugin version >= 1.3.0 (student profile present)", /HEDAYATI_CORE_VERSION', '1\.[3-9]\.\d+'/.test(boot));

// ── 3. Hedayati_Crypto ───────────────────────────────────────────────────────

console.log('\n3. class-crypto.php (Hedayati_Crypto):');
const crypto = read('includes/class-crypto.php');
assert("declares strict_types", crypto.includes('declare( strict_types=1 );'));
assert("has ABSPATH guard", crypto.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const ob2 = (crypto.match(/{/g) || []).length, cb2 = (crypto.match(/}/g) || []).length;
	assert(`braces balanced (${ob2}/${cb2})`, ob2 === cb2);
}
assert("uses AES-256-GCM", crypto.includes("'aes-256-gcm'"));
assert("keys are strictly base64-decoded (true = strict mode)", /base64_decode\(\s*\$value,\s*true\s*\)/.test(crypto));
assert("requires exactly 32 raw bytes", crypto.includes('KEY_BYTES   = 32'));
assert("is_configured() checks both the encryption key and the HMAC key", /is_configured[\s\S]{0,200}resolve_key[\s\S]{0,100}resolve_hmac_key/.test(crypto));
assert("never reads SECURE_AUTH_KEY or any WP salt", !/defined\(\s*'(SECURE_AUTH_KEY|LOGGED_IN_KEY|AUTH_KEY|NONCE_KEY)'|constant\(\s*'(SECURE_AUTH_KEY|LOGGED_IN_KEY|AUTH_KEY|NONCE_KEY)'/.test(crypto));
assert("supports key-version rotation via HEDAYATI_DATA_ENCRYPTION_KEY_V{n}", crypto.includes("'HEDAYATI_DATA_ENCRYPTION_KEY_V' . \$version"));
assert("fingerprint() uses hash_hmac sha256", crypto.includes("hash_hmac( 'sha256'"));

// ── 4. Migration 2.3.0 (class-db-schema.php) ────────────────────────────────

console.log('\n4. Migration 2.3.0 (class-db-schema.php):');
const db = read('includes/class-db-schema.php');
assert("CURRENT_DB_VERSION bumped to 2.3.0", db.includes("CURRENT_DB_VERSION = '2.3.0'"));
assert("MIGRATIONS registers '2.3.0' => 'migrate_2_3_0'", /'2\.3\.0'\s*=>\s*'migrate_2_3_0'/.test(db));
assert("migrate_2_3_0() defined", db.includes('private static function migrate_2_3_0()'));
assert("prior migrations still present (no regression)", ['migrate_2_0_0','migrate_2_1_0','migrate_2_2_0'].every(m => db.includes(m)));
assert("hedayati_student_verification has UNIQUE(user_id)", db.includes('UNIQUE KEY uq_user_id (user_id)') && db.includes('national_id_hmac'));
assert("hedayati_student_verification has UNIQUE(national_id_hmac)", db.includes('UNIQUE KEY uq_national_id_hmac (national_id_hmac)'));
assert("status column is varchar, not ENUM", !/status\s+ENUM/i.test(db));
assert("get_table_student_verification() accessor, dynamic prefix", db.includes("\$wpdb->prefix . 'hedayati_student_verification'"));
assert("get_table_documents() accessor, dynamic prefix", db.includes("\$wpdb->prefix . 'hedayati_documents'"));
assert("migrate_2_3_0 verifies both tables before returning true", /migrate_2_3_0[\s\S]*?SHOW TABLES LIKE %s[\s\S]*?return false;[\s\S]*?return true;/.test(db));
assert("no hardcoded 'wp_hedayati_' literal", !db.includes('wp_hedayati_'));

// ── 5. Roles / capabilities (class-roles.php) ───────────────────────────────

console.log('\n5. Roles schema 2.2.0 (class-roles.php):');
const roles = read('includes/class-roles.php');
assert("ROLES_VERSION bumped to 2.2.0", roles.includes("ROLES_VERSION = '2.2.0'"));
assert("new capability hedayati_upload_student_documents registered", roles.includes("'hedayati_upload_student_documents'"));
{
	const capListMatch = roles.match(/get_all_hedayati_capabilities\(\): array \{\s*return \[([\s\S]*?)\];/);
	assert("get_all_hedayati_capabilities() present", !!capListMatch);
	if (capListMatch) {
		const count = (capListMatch[1].match(/'hedayati_[a-z_]+'/g) || []).length;
		assert("managed capability count is exactly 23", count === 23);
	}
}
assert("reception grants hedayati_upload_student_documents", /'reception'\s*=>[\s\S]*?'hedayati_upload_student_documents'\s*=>\s*true/.test(roles));
assert("hedayati_manager grants hedayati_upload_student_documents", /'hedayati_manager'\s*=>[\s\S]*?'hedayati_upload_student_documents'\s*=>\s*true/.test(roles));
{
	const studentBlock = (roles.match(/'student'\s*=>\s*\[[\s\S]*?\],\s*\n\s*\],/) || [''])[0];
	assert("student role does NOT grant hedayati_upload_student_documents", !studentBlock.includes('hedayati_upload_student_documents'));
}
{
	const teacherBlock = (roles.match(/'teacher'\s*=>\s*\[[\s\S]*?\],\s*\n\s*\],/) || [''])[0];
	assert("teacher role does NOT grant hedayati_upload_student_documents", !teacherBlock.includes('hedayati_upload_student_documents'));
}
assert("future-safe managed-cap tracking retained", roles.includes('OPTION_MANAGED_CAPS'));

// ── 6. Audit log vocabulary (class-audit-log.php) ───────────────────────────

console.log('\n6. Audit log vocabulary extension (class-audit-log.php):');
const audit = read('includes/class-audit-log.php');
const newObjectTypes = ['student_identity', 'document'];
const newActions = ['identity.set', 'identity.viewed', 'verification.initiated', 'verification.approved', 'verification.rejected', 'verification.reset', 'user.identity_purged', 'document.uploaded', 'document.download_started', 'document.archived', 'document.purged', 'document.purged_for_user'];
newObjectTypes.forEach(t => assert(`object_types() includes '${t}'`, audit.includes(`'${t}',`) || audit.includes(`'${t}'`)));
newActions.forEach(a => assert(`actions() includes '${a}'`, audit.includes(`'${a}',`) || audit.includes(`'${a}'`)));
{
	const auditCodeOnly = audit.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
	assert("no ip/user_agent column added in code (Q13 stays closed)", !/\bip\b|user_agent/i.test(auditCodeOnly));
}
assert("download action is 'download_started', not a delivery-confirmed name", audit.includes("'document.download_started'") && !audit.includes("'document.downloaded'"));

// ── 7. Hedayati_Verification_Service ────────────────────────────────────────

console.log('\n7. class-verification-service.php:');
const vs = read('includes/class-verification-service.php');
assert("declares strict_types", vs.includes('declare( strict_types=1 );'));
assert("has ABSPATH guard", vs.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const ob3 = (vs.match(/{/g) || []).length, cb3 = (vs.match(/}/g) || []).length;
	assert(`braces balanced (${ob3}/${cb3})`, ob3 === cb3);
}
assert("set_national_id() fails closed without configured crypto", /set_national_id[\s\S]{0,400}Hedayati_Crypto::is_configured\(\)/.test(vs));
assert("national ID is fingerprinted for duplicate detection", vs.includes('Hedayati_Crypto::fingerprint('));
assert("national ID is encrypted before storage", vs.includes('Hedayati_Crypto::encrypt('));
assert("get_national_id_decrypted() enforces hedayati_verify_students itself (defense in depth)", /get_national_id_decrypted[\s\S]{0,300}user_can\(\s*\$viewer_id,\s*'hedayati_verify_students'\s*\)/.test(vs));
assert("no owner/self bypass in the decrypt method", !/get_national_id_decrypted[\s\S]{0,600}\$user_id\s*===\s*get_current_user_id\(\)/.test(vs));
assert("initiate() refuses when already pending", vs.includes("'already_pending'"));
assert("initiate() refuses when already verified", vs.includes("'already_verified'"));
assert("approve()/reject() require status === pending", (vs.match(/'not_pending'/g) || []).length >= 2);
assert("verified only exits via reset_for_identity_change", vs.includes('function reset_for_identity_change'));
{
	const vsCodeOnly = vs.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
	assert("legal-name change hooked via update_user_meta (NOT profile_update — see the class docblock for why)", vsCodeOnly.includes("'update_user_meta'") && vsCodeOnly.includes('legal_name_changed') && !vsCodeOnly.includes("'profile_update'"));
}
assert("phone/address/email are NOT wired to a reset (only first_name/last_name meta keys watched)", /'first_name'\s*!==\s*\$meta_key\s*&&\s*'last_name'\s*!==\s*\$meta_key/.test(vs) && !/user_email|hedayati_address|hedayati_city/.test(vs.split('on_update_user_meta')[1] || ''));
assert("deleted_user cleanup hooked", vs.includes("'deleted_user'"));
assert("addresses its table only via Hedayati_DB_Schema (never literal wp_)", vs.includes('Hedayati_DB_Schema::get_table_student_verification()') && !vs.includes("'wp_hedayati_"));
assert("audit note never contains the national ID value (fixed safe strings only)", !/Hedayati_Audit_Log::record\(\s*'identity\.set'[\s\S]{0,150}\$raw_value/.test(vs) && !/Hedayati_Audit_Log::record\(\s*'identity\.set'[\s\S]{0,150}\$normalized/.test(vs));

// ── 8. Hedayati_Document_Storage / Hedayati_Document_Service ───────────────

console.log('\n8. class-document-storage.php / class-document-service.php:');
const ds = read('includes/class-document-storage.php');
const doc = read('includes/class-document-service.php');
[['storage', ds], ['service', doc]].forEach(([label, src]) => {
	assert(`${label}: declares strict_types`, src.includes('declare( strict_types=1 );'));
	assert(`${label}: has ABSPATH guard`, src.includes("if ( ! defined( 'ABSPATH' ) ) {"));
	const ob4 = (src.match(/{/g) || []).length, cb4 = (src.match(/}/g) || []).length;
	assert(`${label}: braces balanced (${ob4}/${cb4})`, ob4 === cb4);
});
assert("storage_key is randomized via wp_generate_password, never derived from user input", ds.includes('wp_generate_password( 32, false, false )'));
assert("no public URL is ever constructed (no wp_upload_dir url usage for documents)", !/echo.*\$upload_dir\['url'\]/.test(ds));
assert("environment-gated: local fallback only under wp_get_environment_type() === 'local'", ds.includes("bootstrap_local_fallback"));
assert("HEDAYATI_PRIVATE_UPLOADS_DIR path validated outside ABSPATH", ds.includes('realpath( ABSPATH )'));
assert("local fallback bootstraps a Deny-all .htaccess", ds.includes('Deny from all'));
assert("upload() saves bytes before inserting metadata (bytes-then-metadata ordering)", /Storage::save[\s\S]{0,400}wpdb->insert/.test(doc));
assert("orphaned bytes deleted on failed metadata insert", /false === \$inserted[\s\S]{0,200}Storage::delete/.test(doc));
assert("download() audits document.download_started before streaming", /document\.download_started[\s\S]{0,200}Storage::stream/.test(doc));
assert("purge() never sets deleted_at before a successful filesystem delete", /is_wp_error\( \$deleted \)[\s\S]{0,150}purge_failed/.test(doc));
assert("mark_archived()/purge_eligible()/purge() present", ['mark_archived', 'purge_eligible', 'function purge('].every(m => doc.includes(m)));
assert("deleted_user cleanup hooked", doc.includes("'deleted_user'"));
assert("addresses its table only via Hedayati_DB_Schema (never literal wp_)", doc.includes('Hedayati_DB_Schema::get_table_documents()') && !doc.includes("'wp_hedayati_"));

// ── 9. Hedayati_Student_Admin (staff-only UI) ───────────────────────────────

console.log('\n9. class-student-admin.php (staff-only admin UI):');
const admin = read('includes/class-student-admin.php');
assert("declares strict_types", admin.includes('declare( strict_types=1 );'));
assert("has ABSPATH guard", admin.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const ob5 = (admin.match(/{/g) || []).length, cb5 = (admin.match(/}/g) || []).length;
	assert(`braces balanced (${ob5}/${cb5})`, ob5 === cb5);
}
assert("reveal action is registered on admin_post (POST-routed)", admin.includes("add_action( 'admin_post_hedayati_identity_reveal'"));
assert("reveal handler checks a nonce from $_POST, not $_GET", /handle_identity_reveal[\s\S]{0,300}_POST\['_wpnonce'\]/.test(admin));
assert("reveal handler requires hedayati_verify_students at the controller", /handle_identity_reveal[\s\S]{0,600}current_user_can\(\s*'hedayati_verify_students'\s*\)/.test(admin));
assert("reveal handler sends no-store/no-cache headers", admin.includes('no-store'));
assert("reveal handler audits identity.viewed with a fixed note, not the value", admin.includes("'identity.viewed', 'student_identity', \$user_id, 'revealed by reviewer'"));
assert("reveal value is never written into a transient/notice helper", !/handle_identity_reveal[\s\S]{0,2000}set_transient/.test(admin));
assert("staff-assisted national-ID entry gated on hedayati_upload_student_documents", /handle_identity_set[\s\S]{0,200}hedayati_upload_student_documents/.test(admin));
assert("staff-assisted document upload gated on hedayati_upload_student_documents", /handle_document_upload[\s\S]{0,200}hedayati_upload_student_documents/.test(admin));
assert("staff-assisted actions enforce a target-is-student scope check", admin.includes('require_student_scope'));
assert("document download gated on hedayati_view_private_documents", /handle_document_download[\s\S]{0,600}hedayati_view_private_documents/.test(admin));
assert("archive/purge gated on hedayati_view_private_documents", (admin.match(/'hedayati_view_private_documents'/g) || []).length >= 3);
assert("no student-facing self-entry form exists in this file (staff-only UI)", !/hedayati_edit_own_profile/.test(admin));
assert("every admin-post handler starts with a nonce+capability check via verify()", (admin.match(/self::verify\(/g) || []).length >= 5);

// ── 10. Plugin bootstrap wiring ─────────────────────────────────────────────

console.log('\n10. hedayati-core.php bootstrap wiring:');
assert("requires class-crypto.php", boot.includes('includes/class-crypto.php'));
assert("requires class-verification-service.php", boot.includes('includes/class-verification-service.php'));
assert("requires class-document-storage.php", boot.includes('includes/class-document-storage.php'));
assert("requires class-document-service.php", boot.includes('includes/class-document-service.php'));
assert("requires class-student-admin.php", boot.includes('includes/class-student-admin.php'));
assert("boots Hedayati_Verification_Service::init()", boot.includes('Hedayati_Verification_Service::init()'));
assert("boots Hedayati_Document_Service::init()", boot.includes('Hedayati_Document_Service::init()'));
assert("boots Hedayati_Student_Admin::init()", boot.includes('Hedayati_Student_Admin::init()'));
assert("plugin version bumped to 1.6.0", boot.includes("HEDAYATI_CORE_VERSION', '1.6.0'"));

console.log(`\n========================================`);
console.log(`PHASE 2C SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);
if (failed > 0) process.exit(1);
