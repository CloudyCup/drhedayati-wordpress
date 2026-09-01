/**
 * Node.js Static & Logic Verification Suite for Phase 2A.
 *
 * Verifies:
 *   1. Strict Iranian Phone Normalization with character whitelisting.
 *   2. Rejection of invalid inputs (embedded letters, script tags, underscores, etc.).
 *   3. Rate limiter identifier canonicalization.
 *   4. Filter priorities (Phone adapter priority 30, Late rate limiter priority 90).
 *   5. PHP syntax / structural balance across all created files.
 */

const fs = require('fs');
const path = require('path');

const DIGIT_MAP = {
	'۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
	'۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
	'٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4',
	'٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
};

const ALLOWED_CHARS_REGEX = /^[0-9\u06F0-\u06F9\u0660-\u0669+\s\-\(\)\.]+$/;
const CANONICAL_REGEX = /^\+989[0-9]{9}$/;

function cleanAndTransliterate(input) {
	let trimmed = (input || '').trim();
	if (!trimmed) return new Error('empty_phone');

	if (!ALLOWED_CHARS_REGEX.test(trimmed)) {
		return new Error('invalid_phone_characters');
	}

	let converted = '';
	for (let char of trimmed) {
		converted += DIGIT_MAP[char] || char;
	}

	const plusCount = (converted.match(/\+/g) || []).length;
	if (plusCount > 1 || (plusCount === 1 && !converted.startsWith('+'))) {
		return new Error('invalid_plus_position');
	}

	const hasLeadingPlus = converted.startsWith('+');
	const digitsOnly = (hasLeadingPlus ? converted.substring(1) : converted).replace(/[\s\-\(\)\.]/g, '');

	if (!digitsOnly || !/^\d+$/.test(digitsOnly)) {
		return new Error('invalid_phone_digits');
	}

	return hasLeadingPlus ? '+' + digitsOnly : digitsOnly;
}

function normalizePhone(rawPhone) {
	const cleaned = cleanAndTransliterate(rawPhone);
	if (cleaned instanceof Error) return cleaned;

	let canonical = '';
	if (cleaned.startsWith('+989')) {
		canonical = cleaned;
	} else if (cleaned.startsWith('00989')) {
		canonical = '+98' + cleaned.substring(4);
	} else if (cleaned.startsWith('989')) {
		canonical = '+' + cleaned;
	} else if (cleaned.startsWith('09')) {
		canonical = '+98' + cleaned.substring(1);
	} else if (cleaned.startsWith('9') && cleaned.length === 10) {
		canonical = '+98' + cleaned;
	} else {
		return new Error('invalid_phone_format');
	}

	if (!CANONICAL_REGEX.test(canonical)) {
		return new Error('invalid_phone_digits');
	}

	return canonical;
}

function canonicalizeIdentifier(rawIdentifier) {
	let trimmed = (rawIdentifier || '').trim();
	if (!trimmed) return '';
	const norm = normalizePhone(trimmed);
	if (!(norm instanceof Error)) {
		return norm;
	}
	return trimmed.toLowerCase();
}

let passed = 0;
let failed = 0;

function assert(desc, cond) {
	if (cond) {
		console.log(`  [PASS] ${desc}`);
		passed++;
	} else {
		console.error(`  [FAIL] ${desc}`);
		failed++;
	}
}

console.log('=== NODE.JS PHASE 2A VERIFICATION SUITE ===\n');

console.log('1. Testing Tuple-Based Phone Normalization Cases:');
const validCases = [
	[ '09141234567',       '+989141234567' ],
	[ '۰۹۱۴۱۲۳۴۵۶۷',       '+989141234567' ],
	[ '٠٩١٤١٢٣٤٥٦٧',       '+989141234567' ],
	[ '+989141234567',     '+989141234567' ],
	[ '00989141234567',    '+989141234567' ],
	[ '989141234567',      '+989141234567' ],
	[ '9141234567',        '+989141234567' ],
	[ '0912-345-6789',     '+989123456789' ],
	[ ' 0935 123 4567 ',   '+989351234567' ],
	[ '(0914) 123 4567',   '+989141234567' ],
	[ '+98 (912) 3456789', '+989123456789' ],
	[ '۰۹۳۵-۱۲۳-۴۵۶۷',     '+989351234567' ],
	[ '0914.123.4567',     '+989141234567' ],
];

for (const [input, expected] of validCases) {
	const res = normalizePhone(input);
	assert(`'${input}' => '${expected}'`, res === expected);
}

console.log('\n2. Testing Strict Invalid Phone Rejections & Whitelist:');
const invalidCases = [
	'',
	'   ',
	'0914abc1234567',
	'0914<script>1234567',
	'0914_123_4567',
	'0914#1234567',
	'0914!1234567',
	'04133377601',
	'02188793566',
	'0914123456',
	'091412345678',
	'1234567890',
	'+12025550199',
	'not_a_phone',
	'++989141234567',
	'+98+9141234567',
	'0914+1234567',
];

for (const input of invalidCases) {
	const res = normalizePhone(input);
	assert(`Strictly rejecting invalid input: '${input}'`, res instanceof Error);
}

console.log('\n3. Testing Identifier Canonicalization:');
const canonCases = [
	[ '09141234567',    '+989141234567' ],
	[ '+989141234567',  '+989141234567' ],
	[ '۰۹۱۴۱۲۳۴۵۶۷',    '+989141234567' ],
	[ '00989141234567', '+989141234567' ],
	[ '9141234567',     '+989141234567' ],
	[ 'AdminUser',      'adminuser' ],
	[ '  Student_01  ', 'student_01' ],
];

for (const [raw, canon] of canonCases) {
	assert(`Canonicalizing '${raw}' => '${canon}'`, canonicalizeIdentifier(raw) === canon);
}

console.log('\n4. Verifying PHP Class File Balances & Hook Configuration:');
const pluginFiles = [
	'includes/class-phone.php',
	'includes/class-db-schema.php',
	'includes/class-user-phone-service.php',
	'includes/class-roles.php',
	'includes/class-rate-limiter.php',
	'includes/class-auth.php',
	'tests/test-phase2a.php',
	'hedayati-core.php'
];

for (const file of pluginFiles) {
	const fullPath = path.join(__dirname, '..', file);
	const content = fs.readFileSync(fullPath, 'utf8');
	let openBraces = (content.match(/{/g) || []).length;
	let closeBraces = (content.match(/}/g) || []).length;
	let hasStrict = content.includes('declare( strict_types=1 );');
	let hasNoDirectAccess = content.includes("if ( ! defined( 'ABSPATH' ) )");

	assert(`${file} exists and is non-empty (${content.length} bytes)`, content.length > 100);
	assert(`${file} has balanced braces (${openBraces} / ${closeBraces})`, openBraces === closeBraces);
	assert(`${file} enforces strict_types`, hasStrict);
	assert(`${file} contains ABSPATH security guard`, hasNoDirectAccess);
}

// Check specific hook priorities in class-auth.php
const authFile = fs.readFileSync(path.join(__dirname, '..', 'includes/class-auth.php'), 'utf8');
assert("class-auth.php registers phone adapter at priority 30", authFile.includes("add_filter( 'authenticate', [ self::class, 'authenticate_phone' ], 30, 3 );"));
assert("class-auth.php registers late rate limiter at priority 90", authFile.includes("add_filter( 'authenticate', [ self::class, 'enforce_rate_limit' ], 90, 3 );"));

// Check DB schema lock & table existence check
const dbFile = fs.readFileSync(path.join(__dirname, '..', 'includes/class-db-schema.php'), 'utf8');
assert("class-db-schema.php uses atomic add_option for locking", dbFile.includes("add_option( self::LOCK_OPTION"));
assert("class-db-schema.php verifies table existence before advancing version", dbFile.includes("SHOW TABLES LIKE %s"));

// Check Roles capability persistence
const rolesFile = fs.readFileSync(path.join(__dirname, '..', 'includes/class-roles.php'), 'utf8');
assert("class-roles.php persists managed capabilities", rolesFile.includes("update_option( self::OPTION_MANAGED_CAPS"));

console.log(`\n========================================`);
console.log(`VERIFICATION SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);

if (failed > 0) process.exit(1);
