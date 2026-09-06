# AI Studio management-panel completeness matrix

**Owner decision (2026-09-06, supersedes the "manager uses wp-admin only" launch decision):**
the custom AI-Studio-inspired panel is now the authoritative manager/staff UX direction.
wp-admin remains available as an underlying/admin fallback but is no longer the intended
primary manager experience. See `docs/DECISIONS.md` D44 / D45 and `docs/AI_STUDIO_INTEGRATION.md`.

Source inspected: the owner-supplied ZIP `دکتر-هدایتی-—-کانسپت_های-بازطراحی.zip`. Its `src/`
tree is byte-identical to `reference-react/src/` (verified). The management panel is
`src/components/Panels.jsx` → `AdminPanel` (7 tabs) and `StudentPanel` (6 tabs). Every list,
counter, request, certificate and setting in that file is React state / `siteData.js` mock
data — there is no database, server authorization, audit trail or persistence.

Legend for **Status**:
`DONE` implemented in the WordPress panel · `WP-ADMIN` exists, reached from the panel but
edited in wp-admin · `PARTIAL` started · `DEMO→REAL` mock in AI Studio, must use real data ·
`OWNER` needs a real institute policy/data-model decision before it can be built.

---

## A. AI Studio → WordPress (manager panel — `AdminPanel`)

| AI Studio option | Purpose | WordPress equivalent | Backend / service | Capability | Status |
|---|---|---|---|---|---|
| Sidebar shell + role chip + topbar | Custom-software navigation model | `/panel/` manager shell (`page-panel.php`, `Hedayati_Staff_Portal`) with role-aware sidebar | — | `hedayati_manage_course_runs` + `hedayati_manage_courses` (`is_manager_workspace()`) | DONE |
| Topbar notifications popover | New-consultation badge | — (no notification store) | — | — | OWNER (needs a notification model; see §C) |
| Topbar quick-actions popover | Shortcuts to new course / issue cert / pending calls | Manager dashboard "تعریف دورهٔ جدید" primary action + operations cards | `course` CPT | `hedayati_manage_courses` | PARTIAL (course shortcut done; cert/calls are OWNER) |
| **Dashboard** — KPI tiles | دوره‌های فعال / ویژه / درخواست جدید / دانشجویان فعال | `render_manager_home()` KPI row: published courses, featured count, active runs, active students — all real counts | `wp_count_posts`, `WP_Query` featured, `Hedayati_Course_Run_Service::count_active()`, `Hedayati_Enrollment_Service::count_active_students()` | manager caps | DONE (consultation KPI replaced with real data; no fake numbers) |
| Dashboard — "عملیات سریع مدیریتی" cards | Jump into course / featured / requests / certs | Manager dashboard "مرکز عملیات" capability-gated action cards | — | per-card capability | DONE (requests/certs cards omitted until §C) |
| Dashboard — "وضعیت صفحه اصلی X/8" card | Featured slot usage | In-panel Featured view header shows `count / 8` | `_course_is_featured` meta | `hedayati_manage_courses` | DONE |
| **Courses** — table (title, dept, duration, featured star, publish, edit, delete) | Manage catalogue | In-panel `?view=courses` table: real course CPT rows, search, featured-only filter, star toggle, publish toggle, edit link | `course` CPT, `_course_english_name`, `_course_duration`, `course-category`, `_course_is_featured` | `hedayati_manage_courses` (+ `edit_post`) | DONE for list + featured/publish toggles; **field editing stays in the Gutenberg editor** (mature, structured meta boxes) — opened from the row |
| Courses — "بازنشانی به حالت پیش‌فرض" | Reset all courses to factory | *Intentionally not ported* — destructive bulk reset of real catalogue data | — | — | OWNER (won't build without explicit request; unsafe on live data) |
| Courses — delete course | Remove a course | Row "حذف در ویرایشگر" link → wp-admin trash (native, undoable) | `course` CPT `delete_posts` | `hedayati_manage_courses` | WP-ADMIN (deliberate — native trash/restore, not a one-click front-end hard delete) |
| Course edit drawer (title, english, dept, duration, level, seats, summary, tags, featured toggle) | Create/edit a course | wp-admin course editor (Gutenberg + `Hedayati_Meta_Box` structured fields) reached from the row / "دورهٔ جدید" | `course` CPT + `Hedayati_Course_Meta` | `hedayati_manage_courses` | WP-ADMIN (the editor already covers every field plus syllabus/audience/outcomes the drawer lacks) |
| **Featured curation** — pick up to 8 | Choose homepage featured courses | In-panel `?view=featured` grid: toggle `_course_is_featured`, 8-cap enforced server-side, live count | `_course_is_featured`, `Hedayati_Query::get_featured_courses()` | `hedayati_manage_courses` | DONE |
| **Requests** — consultation list, "ثبت تماس گرفته شد" | Track consultation / level-test requests | — (site only has a phone CTA today) | — | — | OWNER — needs fields, retention, spam control, staff workflow before build (§C item 1 in `AI_STUDIO_INTEGRATION.md`) |
| **Students** — list with course + progress %, "مشاهده سوابق" | Student roster & progress | Real reception workspace `?view=students`: POST search, account creation, enrollment, verification, secure documents, audited national-ID reveal | `Hedayati_Student_Profile`, `Hedayati_Verification_Service`, `Hedayati_Enrollment_Service`, `Hedayati_Document_Service`, `Hedayati_Audit_Log` | `hedayati_lookup_students` + `hedayati_view_student_profiles_basic` (+ per-action caps) | DONE (far exceeds the mock) — **progress %** is OWNER (no progress formula defined) |
| **Certificates** — issue + recent + copy code | Issue/verify certificates | — | — | — | OWNER — separate security-sensitive project: issuer, eligibility, revocation, identifier, public-verification data rules (§C item 4) |
| **Institute settings** — name, phones, branch addresses | Site contact info | wp-admin `options-general.php?page=hedayati-settings` (Settings API), reached from panel nav + dashboard card | `Hedayati_Settings` / `option_page_capability_hedayati_institute` | `hedayati_manage_settings` | WP-ADMIN (real, capability-gated; in-panel form is a later polish item) |
| Teachers (dashboard card only in AI Studio) | Teacher profiles + public opt-in | wp-admin `edit.php?post_type=teacher`, reached from panel nav + dashboard card | `teacher` CPT, `_hedayati_public_*` opt-in | `hedayati_manage_teachers` | WP-ADMIN |
| Audit history (not in AI Studio; WP addition) | Sensitive-event log | wp-admin `hedayati-academic-audit`, reached from panel "گزارش فعالیت‌های مدیریتی" aside | `Hedayati_Audit_Log` | `hedayati_view_audit_logs` | WP-ADMIN |

## B. AI Studio → WordPress (student panel — `StudentPanel`)

| AI Studio option | WordPress equivalent | Status |
|---|---|---|
| Sidebar + topbar shell, role chip | `/account/` student shell (`page-account.php`) with AI-Studio-style sidebar/brand | DONE |
| **Overview** — learning dashboard, active courses, "جلسه بعدی شما" | `render_dashboard_view()` — real verification/enrollment/document KPIs, active-course list, real next session | DONE |
| Overview — progress % bars | — | OWNER (no progress formula) |
| **My courses** — cards with progress + "دانلود جزوه/سورس" | `?view=enrollments` read-only Shamsi-dated enrolments/sessions | PARTIAL — list DONE; per-session materials download is OWNER (no materials store) |
| **Calendar** — weekly sessions | `?view=schedule` — real, read-only, ownership-scoped future sessions from active enrolments | DONE |
| **Certificates** — "دانلود نسخه دیجیتال" | — | OWNER (see §A Certificates) |
| **Support** — ticket form + support phones | — (contact page + phones only) | OWNER — needs recipients, attachments, status, retention, notifications (§C item 5) |
| **Profile** — name, phone, national ID, email | `?view=profile` (name/phone/address) + `?view=verification` (verification, encrypted national-ID intake) | DONE — national ID stays write-only / audited-reveal-only, never shown to the student (D36) |

## C. WordPress/Hedayati management functions → location in the new panel

Everything below is already built in WordPress and must remain reachable. None is lost.

| Function | Panel location | Capability |
|---|---|---|
| Student lookup (POST search) | `/panel/?view=students` | `hedayati_lookup_students` |
| Student account creation + temp password + forced first-login change | `/panel/?view=students` create form | `hedayati_create_students` |
| Basic profile (address / city / postal / phone) | reception `?view=students` + wp-admin user edit | `hedayati_view_student_profiles_basic` |
| Verification workflow (initiate / status) | `/panel/?view=students` + wp-admin `hedayati-students` | `hedayati_initiate_verification` / `hedayati_verify_students` |
| Encrypted national-ID intake | `/panel/?view=students` identity form | `hedayati_upload_student_documents` |
| Privileged audited national-ID reveal | wp-admin `hedayati-students` (POST-only, audited) | `hedayati_verify_students` |
| Private document upload / view / archive / purge | `/panel/?view=students` + wp-admin | `hedayati_upload_student_documents` |
| Courses / categories / featured / publish | `/panel/?view=courses` + `?view=featured` + Gutenberg editor | `hedayati_manage_courses` |
| Course runs / teachers-on-run / TA-on-run / sessions / enrolments / attendance / capacity / fees | wp-admin `hedayati-academic`, panel nav + dashboard card; teacher/TA run view `?view=run` | `hedayati_manage_course_runs` / assigned-run caps |
| Public course-run opt-in visibility | wp-admin course editor (`_hedayati_public_catalog_details`) | `hedayati_manage_courses` |
| Teachers CPT + public opt-in | wp-admin `edit.php?post_type=teacher`, panel nav + card | `hedayati_manage_teachers` |
| Institute settings | wp-admin `hedayati-settings`, panel nav + card | `hedayati_manage_settings` |
| Audit-log viewer | wp-admin `hedayati-academic-audit`, panel aside | `hedayati_view_audit_logs` |
| Teacher: assigned runs / roster / sessions / attendance | `/panel/?view=run&run_id=` | `hedayati_view_assigned_runs` + `hedayati_record_attendance` |
| TA: assigned runs / rosters (no attendance) | `/panel/?view=run&run_id=` | `hedayati_view_assigned_roster` |
| Student: `/account/` dashboard / enrolments / schedule / verification / documents / profile | `/account/?view=` | `hedayati_view_own_portal` |

## D. Role → module matrix (panel navigation)

| Module | administrator | hedayati_manager | reception | teacher | teacher_assistant | student |
|---|---|---|---|---|---|---|
| Manager dashboard (`/panel/`) | ✅ | ✅ | ✱ simple home | ✱ my-runs home | ✱ my-runs home | ✗ (`/account/`) |
| Courses `?view=courses` / Featured | ✅ | ✅ | ✗ | ✗ | ✗ | ✗ |
| Course feature/publish toggle | ✅ | ✅ | ✗ | ✗ | ✗ | ✗ |
| Academic operations (runs/sessions/enrol/attendance admin) | ✅ | ✅ | ✗ (no `manage_course_runs`) | ✗ | ✗ | ✗ |
| Reception workspace `?view=students` | ✅ | ✅ | ✅ | ✗ | ✗ | ✗ |
| Create student / intake / verification-initiate / doc upload | ✅ | ✅ | ✅ | ✗ | ✗ | ✗ |
| National-ID reveal | ✅ | ✅ (if `hedayati_verify_students`) | ✗ | ✗ | ✗ | ✗ |
| Teachers / Settings (wp-admin, from panel) | ✅ | ✅ | ✗ | ✗ | ✗ | ✗ |
| Assigned-run view `?view=run` (roster/sessions) | ✅ | ✅ | ✗ | ✅ assigned only | ✅ assigned only (roster only) | ✗ |
| Record attendance | ✅ | ✅ | ✗ | ✅ assigned | ✗ | ✗ |
| Audit-log viewer | ✅ | ✅ | ✗ | ✗ | ✗ | ✗ |
| Student `/account/` portal | ✗ (own) | — | — | — | — | ✅ own only |

Backend/controller capability + object-scope checks are enforced regardless of which nav
items render — the sidebar only hides what a role cannot use.

## E. Outstanding owner decisions (blockers for full AI Studio parity)

1. **Consultation requests** — capture fields, retention window, spam protection, which staff
   act on them, status vocabulary.
2. **Student progress %** — define it: attendance ratio, completed sessions, assessment score,
   or a staff-entered number. Nothing renders a percentage until this is answered.
3. **Certificates & public verification** — issuer authority, eligibility rule, certificate
   identifier scheme, revocation, and exactly which fields are public on a verification page.
4. **Course materials / handouts store** — whether students download per-session files, where
   they live, and access scoping.
5. **Support tickets** — recipients, attachments, statuses, retention, notification behaviour.
6. **Notifications** — event list, recipients, retention, delivery channel.
7. **In-panel institute-settings form** vs. keeping the wp-admin Settings API screen (low risk
   either way; currently wp-admin).

Until each is resolved the corresponding AI Studio tab is represented in this matrix and in
`AI_STUDIO_INTEGRATION.md` but is **not** shown as a working feature in the panel.
