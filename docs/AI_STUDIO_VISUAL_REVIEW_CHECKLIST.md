# Manual visual review checklist — AI Studio panel + parity modules

**Branch:** `feature/manager-experience` · **HEAD:** `f522c4c` · **When:** before merge, after
functionality is complete (owner plan: one comprehensive review, then one staging cycle).

Run against a real WordPress instance with the plugin + theme active, migration 2.4.0 applied,
and at least one course + course run + a couple of enrolled students + a few sessions + some
attendance marks. Check **every** row at **desktop and mobile widths**, in **Persian RTL**, in
**both light and dark** themes. For each screen note: no page-level horizontal scrollbar
(`document.documentElement.scrollWidth === clientWidth`), Vazirmatn renders, mixed Persian/English
text has correct bidi, focus order is RTL-correct, dark mode has no unreadable contrast.

## 1. Manager / administrator (`/panel/`)

| # | Screen / action | Expect |
|---|---|---|
| 1.1 | `/panel/` dashboard | KPI row shows **real** counts (published courses, featured, active runs, active students, new consultations, waiting tickets). No fake "۲۰+ سال" / "۱۵K+". "تعریف دورهٔ جدید" opens the WP course editor. |
| 1.2 | "مرکز عملیات" cards | One card per module the manager can use; each navigates correctly. Audit aside present. |
| 1.3 | Sidebar | All module entries visible (courses, featured, consultations, certificates, materials, support, settings) + site + logout. Active item highlighted. On mobile the sidebar collapses and stays usable. |
| 1.4 | `?view=courses` | Real course table; search filters; "فقط دوره‌های ویژه" filter works; **ویژه** and **انتشار** pills toggle and show a success notice; homepage featured row reflects the change. Table collapses to cards < 620px. |
| 1.5 | `?view=featured` | Grid with live `count / 8`; try to feature a 9th → Persian "حداکثر ۸ دوره" error. |
| 1.6 | `?view=consultations` | Submit the public `/consult/` form first (see 5.1); it appears here as "جدید". Status buttons move it new → contacted → closed. Internal note saves. Phone is a tappable `tel:` link. Search + status filter work. |
| 1.7 | `?view=certificates` | Issue by an enrollment id → success + code shown in the list. Re-issue the same enrollment → "قبلاً … صادر شده". "استعلام" opens `/verify/` in a new tab showing valid. "ابطال" (with confirm) → status becomes باطل‌شده; `/verify/` now shows revoked. Search + status filter work. |
| 1.8 | `?view=support` | A student ticket (see 4.5) appears in the queue. Open it → thread renders; reply → ticket moves to "در انتظار دانشجو"; status dropdown changes work. "‹ بازگشت به صف" works. |
| 1.9 | `?view=settings` | Form pre-filled from current settings; change institute name / a phone / Tehran address → save → success notice; reload shows the new values; the site footer / `/contact/` reflect them. |
| 1.10 | `?view=materials` | Lists the manager's runs with a material count; each links into the run view's materials section. |
| 1.11 | `?view=run&run_id=<id>` | Roster (names only for the manager too is fine), sessions + attendance grid, "پیشرفت دوره: X از Y جلسه", and a "منابع و جزوات" section: add a **link**, a **note**, and a **file** (PDF) material; each appears; delete works; the file "دانلود فایل" link downloads (not a bare uploads URL). |

## 2. Reception (`/panel/`)

| # | Screen | Expect |
|---|---|---|
| 2.1 | `/panel/` home | The simple card home (NOT the manager dashboard). Cards for پذیرش و دانشجویان, consultations, support. No courses / featured / certificates / settings card. |
| 2.2 | Sidebar | Shows پذیرش و دانشجویان + درخواست‌های مشاوره + تیکت‌های پشتیبانی only (of the module set). No courses/featured/certificates/settings. |
| 2.3 | `?view=students` | Search, create student (temp password shown once), enroll, national-ID intake, document upload, initiate verification — all unchanged and working. |
| 2.4 | `?view=consultations` | Full queue access (same as manager). |
| 2.5 | `?view=support` | Full queue access; can reply + change status. |
| 2.6 | Direct URL `?view=courses` / `?view=certificates` / `?view=settings` | 403 "دسترسی مجاز نیست." (the guard denies before render). |

## 3. Teacher (`/panel/`)

| # | Screen | Expect |
|---|---|---|
| 3.1 | `/panel/` home | "کلاس‌های من" list of assigned runs only. |
| 3.2 | `?view=run&run_id=<assigned>` | Roster (names only), sessions, attendance grid (teacher can mark), run progress line, and the "منابع و جزوات" section (teacher can add/delete link/note/file). |
| 3.3 | `?view=run&run_id=<NOT assigned>` | 403. |
| 3.4 | `?view=materials` | Lists **only** the teacher's assigned runs. |
| 3.5 | Direct URL `?view=courses` / `?view=consultations` / `?view=certificates` / `?view=support` / `?view=settings` | 403 each. |
| 3.6 | `?view=students` | 403 (no `hedayati_lookup_students`). |

## 4. Teaching assistant (`/panel/`)

| # | Screen | Expect |
|---|---|---|
| 4.1 | `/panel/` home | "کلاس‌های من" list of assigned runs only. |
| 4.2 | `?view=run&run_id=<assigned>` | Roster (names only). **No** attendance grid, **no** "new session" form, **no** "منابع و جزوات" add form (TA lacks `hedayati_manage_session_materials`). |
| 4.3 | Direct URL `?view=courses` / `?view=materials` / `?view=consultations` / `?view=certificates` / `?view=support` / `?view=settings` | 403 each. |

## 5. Student (`/account/`)

| # | Screen | Expect |
|---|---|---|
| 5.1 | Public `/consult/` (logged out) | Form: name, phone, optional topic + message, consent line. Submit with a bad phone → Persian error. Submit valid → "درخواست شما ثبت شد". Submit 6× fast → rate-limit message. |
| 5.2 | Public `/verify/` (logged out) | Empty → hint. Unknown code → "یافت نشد". A valid code (from 1.7) → "معتبر است" + only name/course/date/institute/code (no phone, no national ID, no attendance). A revoked code → "باطل شده". Print preview (Ctrl+P) hides the header/footer/form. |
| 5.3 | Forced first-login password change | Still intercepts before any `/account/` view; only logo + theme toggle visible; completes and clears. |
| 5.4 | `/account/` dashboard | Real verification / enrolment / document KPIs; "دوره‌های فعال شما"; "جلسهٔ بعدی شما" (real next session or empty state). |
| 5.5 | `?view=enrollments` | Per course: enrolment status + Shamsi start date; **"پیشرفت دوره"** bar + "X جلسه از Y جلسه"; **"حضور شما"** bar + "X حضور از Y" (or "— / هنوز حضور و غیابی ثبت نشده" when none); the run's **materials** listed (link opens, file downloads); session list. A zero-session run shows "—", never 0%. |
| 5.6 | `?view=schedule` | Read-only future sessions from this student's active enrolments only; past sessions and other students' sessions absent. |
| 5.7 | `?view=certificates` | The student's own certificates only (empty state otherwise); "مشاهده و چاپ" opens `/verify/`. |
| 5.8 | `?view=support` | List of own tickets; open one → thread; reply works while open; a closed ticket shows no reply box for the student. New-ticket form (subject, category, body) creates a ticket. |
| 5.9 | `?view=notifications` | Real notifications from events (enrolment/cert/support). Unread rows marked; "علامت‌گذاری همه…" clears the count; sidebar badge disappears. |
| 5.10 | Sidebar unread badge | Shows the unread count next to "اعلان‌ها" when > 0; Persian digits. |
| 5.11 | `?view=verification` / `?view=documents` / `?view=profile` | Unchanged; national ID never shown. |
| 5.12 | Direct URL `/panel/?view=support&ticket=<another student's id>` while logged in as a student | Not reachable — `/panel/` denies the student entirely (no staff cap). `/account/?view=support&ticket=<not yours>` → "تیکت یافت نشد". |

## 6. Cross-cutting

| # | Check |
|---|---|
| 6.1 | Every new list/table/form: no page-level horizontal scroll at 360px, 768px, 1280px. |
| 6.2 | Dark mode: consultation cards, progress bars, support message bubbles, certificate cards, verify result box, notification rows all readable. |
| 6.3 | RTL: progress bar fills from the correct side; the `‹` chevrons point the right way; the support "my message" bubble aligns to the correct edge. |
| 6.4 | Homepage / course archive / category archive / single course / 404 / generic Page — **not regressed** by the new `page.php` consult/verify branches. |
| 6.5 | WordPress admin bar stays hidden on `/panel/` and `/account/`. |
| 6.6 | `/verify/` and `/consult/` work while logged in as any role, and while logged out. |
