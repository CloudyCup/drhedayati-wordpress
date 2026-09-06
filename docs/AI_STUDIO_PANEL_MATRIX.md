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
edited in wp-admin · `PARTIAL` started · `NOT PORTED` deliberately excluded (unsafe/no analogue).

**Update 2026-09-06:** the owner brought §E fully in scope. All seven modules are implemented
(D46–D52); node static **876/0**, Docker real-WordPress acceptance **576/0 PASS, cleanup
verified** (`docker/wp-tests/test-ai-studio.php`, run `34025229061` on `f6ad232`). §E now records
resolutions, not blockers. Nothing is presented with AI Studio mock data.

---

## A. AI Studio → WordPress (manager panel — `AdminPanel`)

| AI Studio option | Purpose | WordPress equivalent | Backend / service | Capability | Status |
|---|---|---|---|---|---|
| Sidebar shell + role chip + topbar | Custom-software navigation model | `/panel/` manager shell (`page-panel.php`, `Hedayati_Staff_Portal`) with role-aware sidebar | — | `hedayati_manage_course_runs` + `hedayati_manage_courses` (`is_manager_workspace()`) | DONE |
| Topbar notifications popover | New-consultation badge | Manager dashboard KPI "درخواست مشاورهٔ جدید" / "تیکت در انتظار پاسخ" (real counts) + staff notification rows (`hedayati_notifications`) | `Hedayati_Notification_Service`, `Hedayati_Consultation_Service::count_new()` | manager/reception caps | DONE (D50) |
| Topbar quick-actions popover | Shortcuts to new course / issue cert / pending calls | Manager dashboard "تعریف دورهٔ جدید" primary action + "مرکز عملیات" cards link to every module incl. certificates + consultations | `course` CPT + module registry | per-card capability | DONE |
| **Dashboard** — KPI tiles | دوره‌های فعال / ویژه / درخواست جدید / دانشجویان فعال | `render_manager_home()` KPI row: published courses, featured count, active runs, active students — all real counts | `wp_count_posts`, `WP_Query` featured, `Hedayati_Course_Run_Service::count_active()`, `Hedayati_Enrollment_Service::count_active_students()` | manager caps | DONE (consultation KPI replaced with real data; no fake numbers) |
| Dashboard — "عملیات سریع مدیریتی" cards | Jump into course / featured / requests / certs | Manager dashboard "مرکز عملیات" capability-gated action cards | — | per-card capability | DONE (requests/certs cards omitted until §C) |
| Dashboard — "وضعیت صفحه اصلی X/8" card | Featured slot usage | In-panel Featured view header shows `count / 8` | `_course_is_featured` meta | `hedayati_manage_courses` | DONE |
| **Courses** — table (title, dept, duration, featured star, publish, edit, delete) | Manage catalogue | In-panel `?view=courses` table: real course CPT rows, search, featured-only filter, star toggle, publish toggle, edit link | `course` CPT, `_course_english_name`, `_course_duration`, `course-category`, `_course_is_featured` | `hedayati_manage_courses` (+ `edit_post`) | DONE for list + featured/publish toggles; **field editing stays in the Gutenberg editor** (mature, structured meta boxes) — opened from the row |
| Courses — "بازنشانی به حالت پیش‌فرض" | Reset all courses to factory | *Intentionally not ported* — destructive bulk reset of real catalogue data | — | — | OWNER (won't build without explicit request; unsafe on live data) |
| Courses — delete course | Remove a course | Row "حذف در ویرایشگر" link → wp-admin trash (native, undoable) | `course` CPT `delete_posts` | `hedayati_manage_courses` | WP-ADMIN (deliberate — native trash/restore, not a one-click front-end hard delete) |
| Course edit drawer (title, english, dept, duration, level, seats, summary, tags, featured toggle) | Create/edit a course | wp-admin course editor (Gutenberg + `Hedayati_Meta_Box` structured fields) reached from the row / "دورهٔ جدید" | `course` CPT + `Hedayati_Course_Meta` | `hedayati_manage_courses` | WP-ADMIN (the editor already covers every field plus syllabus/audience/outcomes the drawer lacks) |
| **Featured curation** — pick up to 8 | Choose homepage featured courses | In-panel `?view=featured` grid: toggle `_course_is_featured`, 8-cap enforced server-side, live count | `_course_is_featured`, `Hedayati_Query::get_featured_courses()` | `hedayati_manage_courses` | DONE |
| **Requests** — consultation list, "ثبت تماس گرفته شد" | Track consultation / level-test requests | Public `/consult/` form → `?view=consultations` queue (`new`/`contacted`/`closed`, search, internal note); nonce + honeypot + per-IP rate limit; phone → E.164 | `Hedayati_Consultation_Service`, `hedayati_consultations` table | `hedayati_manage_consultations` (reception + manager) | DONE (D46) |
| **Students** — list with course + progress %, "مشاهده سوابق" | Student roster & progress | Reception workspace `?view=students` (POST search, account creation, enrollment, verification, secure documents, audited national-ID reveal). **Progress %** now real: `Hedayati_Progress_Service` shows run progress + attendance rate per enrollment in `/account/` | `Hedayati_Student_Profile`, `Hedayati_Verification_Service`, `Hedayati_Progress_Service`, `Hedayati_Document_Service`, `Hedayati_Audit_Log` | `hedayati_lookup_students` + `hedayati_view_student_profiles_basic` | DONE (exceeds the mock; progress is objective, D47) |
| **Certificates** — issue + recent + copy code | Issue/verify certificates | `?view=certificates`: issue (one per enrollment), revoke, list/filter; public `/verify/?code=` page (IP rate-limited, minimal fields); student `/account/?view=certificates` | `Hedayati_Certificate_Service`, `hedayati_certificates` table | `hedayati_manage_certificates` (manager/admin only) | DONE (D48) |
| **Institute settings** — name, phones, branch addresses | Site contact info | `?view=settings` in-panel form → canonical `Hedayati_Settings` option + sanitizer (adds institute name + Tehran address); wp-admin screen unchanged as fallback | `Hedayati_Panel_Settings` + `Hedayati_Settings` | `hedayati_manage_settings` | DONE (D52) |
| Teachers (dashboard card only in AI Studio) | Teacher profiles + public opt-in | wp-admin `edit.php?post_type=teacher`, reached from panel nav + dashboard card | `teacher` CPT, `_hedayati_public_*` opt-in | `hedayati_manage_teachers` | WP-ADMIN |
| Audit history (not in AI Studio; WP addition) | Sensitive-event log | wp-admin `hedayati-academic-audit`, reached from panel "گزارش فعالیت‌های مدیریتی" aside | `Hedayati_Audit_Log` | `hedayati_view_audit_logs` | WP-ADMIN |

## B. AI Studio → WordPress (student panel — `StudentPanel`)

| AI Studio option | WordPress equivalent | Status |
|---|---|---|
| Sidebar + topbar shell, role chip | `/account/` student shell (`page-account.php`) with AI-Studio-style sidebar/brand | DONE |
| **Overview** — learning dashboard, active courses, "جلسه بعدی شما" | `render_dashboard_view()` — real verification/enrollment/document KPIs, active-course list, real next session | DONE |
| Overview — progress % bars | `?view=enrollments` shows run progress + attendance rate bars per course; "—" when there is no basis (never a fake 0%) | DONE (D47) |
| **My courses** — cards with progress + "دانلود جزوه/سورس" | `?view=enrollments` — Shamsi-dated sessions + progress + **materials** (`Hedayati_Material_Service::render_student_run`) for the enrolled run | DONE (D47/D49) |
| **Calendar** — weekly sessions | `?view=schedule` — real, read-only, ownership-scoped future sessions from active enrolments | DONE |
| **Certificates** — "دانلود نسخه دیجیتال" | `?view=certificates` — the student's own issued certificates + print-friendly `/verify/` view | DONE (D48) |
| **Support** — ticket form + support phones | `?view=support` — open/read/reply to the student's own tickets (IDOR-safe); support phones on `/contact/` | DONE (D51) |
| **Notifications** (topbar bell) | `?view=notifications` + unread badge in the sidebar | DONE (D50) |
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
| Consultation requests queue | `/panel/?view=consultations` | `hedayati_manage_consultations` |
| Certificates: issue / revoke / list | `/panel/?view=certificates` | `hedayati_manage_certificates` |
| Course/session materials | `/panel/?view=run&run_id=#materials` (manage) + `?view=materials` (index) | `hedayati_manage_session_materials` |
| Support ticket queue / reply / status | `/panel/?view=support` | `hedayati_manage_support_tickets` |
| Institute settings (in-panel) | `/panel/?view=settings` | `hedayati_manage_settings` |
| Student progress + attendance | shown in `/account/?view=enrollments`; staff see run progress in `?view=run` | — (read, ownership/role-scoped) |
| Student certificates / support / notifications | `/account/?view=certificates` `?view=support` `?view=notifications` | `hedayati_view_own_certificates` / `hedayati_use_support_tickets` / — |
| Public certificate verification | `/verify/?code=` (no login) | — (IP rate-limited, minimal fields) |
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
| Courses `?view=courses` / Featured / feature+publish toggle | ✅ | ✅ | ✗ | ✗ | ✗ | ✗ |
| Academic operations (runs/sessions/enrol/attendance admin) | ✅ | ✅ | ✗ (no `manage_course_runs`) | ✗ | ✗ | ✗ |
| Reception workspace `?view=students` (create / intake / verify-initiate / doc upload) | ✅ | ✅ | ✅ | ✗ | ✗ | ✗ |
| National-ID reveal | ✅ | ✅ (if `hedayati_verify_students`) | ✗ | ✗ | ✗ | ✗ |
| **Consultations** `?view=consultations` | ✅ | ✅ | ✅ | ✗ | ✗ | ✗ |
| **Certificates** `?view=certificates` (issue/revoke) | ✅ | ✅ | ✗ | ✗ | ✗ | ✗ |
| **Materials** manage (in run view) | ✅ | ✅ | ✗ | ✅ assigned runs | ✗ | ✗ |
| **Support tickets** queue `?view=support` | ✅ | ✅ | ✅ | ✗ | ✗ | ✗ |
| **In-panel settings** `?view=settings` | ✅ | ✅ | ✗ | ✗ | ✗ | ✗ |
| Teachers (wp-admin, from panel) | ✅ | ✅ | ✗ | ✗ | ✗ | ✗ |
| Assigned-run view `?view=run` (roster/sessions/progress) | ✅ | ✅ | ✗ | ✅ assigned only | ✅ assigned only (roster only) | ✗ |
| Record attendance | ✅ | ✅ | ✗ | ✅ assigned | ✗ | ✗ |
| Audit-log viewer | ✅ | ✅ | ✗ | ✗ | ✗ | ✗ |
| Student `/account/` — dashboard/enrolments(+progress+materials)/schedule/certificates/support/notifications/verification/documents/profile | ✗ (own) | — | — | — | — | ✅ own only |

Backend/controller capability + object-scope checks are enforced regardless of which nav
items render — the sidebar only hides what a role cannot use.

## E. Owner decisions — RESOLVED 2026-09-06 (D46–D52)

| # | Decision | Resolution |
|---|---|---|
| 1 | Consultation requests | **Build (D46).** Fields = name, phone, optional topic + message. Public form, no auth, nonce + honeypot + per-IP rate limit. Statuses `new`/`contacted`/`closed`. Reception + manager. One internal note. No auto SMS/email. Audit carries no phone/body. |
| 2 | Student progress % | **Objective only (D47).** Run progress = held ÷ total non-cancelled sessions. Attendance rate = present+late+excused ÷ recorded marks. Kept separate; never called "completion". Zero-session → "—". No grades/scores. |
| 3 | Certificates + verification | **Build (D48).** Manager/admin issue, one per enrollment (`UNIQUE`), `DH-<jyear>-<10 crypto-random>` code (not the national ID). Revoke supported. Public `/verify/` shows only name/course/date/institute/code; IP rate-limited; revoked/unknown → clear non-sensitive status. Print-friendly HTML (no PDF dependency). |
| 4 | Course materials store | **Build (D49).** Per run/session; link / note / file. Files → `Hedayati_Material_Storage` (own namespace over the Phase 2C hardened private store), enrollment-scoped nonced download handler. Identity-document store untouched. |
| 5 | Support tickets | **Build (D51).** Student opens/reads/replies to own tickets only (ownership on every read/write). Staff queue, 4 statuses. No attachments in v1. No email/SMS. Audit = reply kind / status only. |
| 6 | Notifications | **Build, internal only (D50).** On-site rows for a deliberate event set (consultation received, support reply/close, certificate issued/revoked). Per-user unread count + mark read. No email/SMS/push. Purged on user deletion. |
| 7 | In-panel settings form | **Build (D52).** `/panel/?view=settings` writes through the canonical `Hedayati_Settings` option + sanitizer; wp-admin screen kept as an admin fallback. Added `institute_name` + `address_tehran`. |

### Remaining smaller follow-ups (not blockers, not owner-blocking)

- Support-ticket **attachments** — deferred to v2 (needs the same private-storage + scoping
  treatment as materials; low demand at launch).
- Certificate **PDF** export — HTML print view ships now; a PDF path can be added later without a
  schema change if a safe generator is chosen.
- Consultation **assignment to a named staff member** — the schema has `handled_by`; a UI to
  reassign is a small future addition, not required for the workflow.
- Notification **digest/email** — explicitly out of scope per D50.

## F. Completeness verdict

**AI Studio manager panel (`AdminPanel`) — every legitimate option accounted for:**

| AI Studio tab | Final WordPress location | Status |
|---|---|---|
| Dashboard (KPIs + quick actions + featured card) | `/panel/` `render_manager_home()` | ✅ real data |
| Courses (table, feature, publish, edit, delete) | `?view=courses` + Gutenberg editor + native trash | ✅ |
| Featured curation | `?view=featured` | ✅ |
| Requests (consultations) | `?view=consultations` | ✅ D46 |
| Students (+ progress) | `?view=students` + progress in `/account/` | ✅ D47 |
| Certificates | `?view=certificates` + `/verify/` | ✅ D48 |
| Institute settings | `?view=settings` | ✅ D52 |
| Courses "reset to factory" | — | ⛔ NOT PORTED (destructive bulk reset of live data; would only build on an explicit, guarded request) |
| Course one-click hard delete | native trash/restore from the row | ⛔ replaced with the safe WP native flow |

**AI Studio student panel (`StudentPanel`) — every tab accounted for:** overview ✅, my courses
(+progress +materials) ✅, calendar/schedule ✅, certificates ✅ (D48), support ✅ (D51),
notifications ✅ (D50), profile ✅.

**Inverse check — every WordPress/Hedayati management function has a panel home:** see §C (fully
populated). No pre-existing capability lost a route; wp-admin remains a fallback only.

No legitimate AI Studio option is silently omitted. No AI Studio demo/mock datum (fake students,
capacities, "۲۰+ سال", "۱۵K+", seeded certificates/requests) is reproduced anywhere.
