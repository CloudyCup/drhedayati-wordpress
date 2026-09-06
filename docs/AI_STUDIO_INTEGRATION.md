# AI Studio admin and portal integration

**Status:** incremental implementation started on `feature/manager-experience` (2026-09-06).

The owner-supplied AI Studio ZIP matches `reference-react/` byte for byte. It is a useful visual
and workflow reference, but its courses, students, consultation requests, certificates, settings,
and notifications are demo objects held in React state/localStorage. It has no WordPress database,
server-side authorization, audit trail, privacy controls, or production persistence.

The production implementation therefore reuses its layout and task organization while keeping the
WordPress plugin as the source of truth. The React app is never shipped or connected to production.

## Capability map

| AI Studio section | Real WordPress state | Integration plan |
|---|---|---|
| Manager dashboard and quick actions | Existing operations were split between `/panel/` and wp-admin | **Implemented on feature branch:** designed `/panel/` manager home with live, non-sensitive counts and capability-gated links |
| Student dashboard | Existing secure account views had a simpler presentation | **Implemented on feature branch:** AI Studio-inspired learning dashboard while retaining profile, verification, enrollment, and document workflows |
| Courses | Course CPT, fields, categories, publish state | **Implemented in-panel (2026-09-06):** `/panel/?view=courses` — real course table, search, featured filter, nonce + `edit_post`-guarded feature/publish toggles; per-field editing stays in the Gutenberg editor, opened from the row |
| Homepage featured courses | `_course_is_featured`, homepage query, maximum display of eight | **Implemented in-panel (2026-09-06):** `/panel/?view=featured` curation grid; 8-slot cap enforced server-side in `handle_course_feature()` |
| Students and registrations | Student accounts, Course Runs, enrollments, reception workflow | Reached through the real front-end reception workspace and academic operations |
| Institute settings | Capability-gated Settings API page | **Implemented in-panel (D52):** `?view=settings` writes the same canonical option; +institute_name +address_tehran |
| Teachers | Teacher CPT and explicit public opt-in | Reached from the manager home |
| Audit history | Append-only metadata-only audit log | Reached from the manager home |
| Consultation requests | `hedayati_consultations` table | **Implemented (D46):** public form → `?view=consultations` queue; validation + rate limit + honeypot; audit carries no phone/body |
| Certificates and public verification | `hedayati_certificates` table | **Implemented (D48):** manual issue per enrollment, random non-PII code, revoke, IP-rate-limited `/verify/` showing minimal fields |
| Student progress | `Hedayati_Progress_Service` (no new storage) | **Implemented (D47):** run progress + attendance rate shown separately; "—" when there is no basis |
| Student calendar | Real Course Run sessions exist | **Implemented on feature branch:** read-only upcoming sessions for the signed-in student's active enrollments; other students and past sessions are excluded |
| Support tickets | `hedayati_support_tickets` + `_messages` | **Implemented (D51):** student ↔ staff threads, ownership-checked on every read/write, statuses open/waiting_student/waiting_staff/closed. Attachments = v2. |
| Notifications | `hedayati_notifications` | **Implemented (D50):** on-site only, deliberate event set, per-user unread state. No email/SMS/push. |
| Magazine/blog | Native WordPress posts are available; dedicated presentation is not built | Design an editorial archive and single-post presentation after launch content priorities are set |

## Delivery sequence — status 2026-09-06

1. **Portal foundation** — ✅ done (manager + student dashboards, real data, RTL, dark mode,
   owner-scoped schedule).
2. **In-panel courses + featured** — ✅ done (D45).
3. **Consultation workflow** — ✅ done (D46): public form + queue, validation + rate limit +
   honeypot, statuses, internal note. No email/CRM/SMS.
4. **Progress** — ✅ done (D47): objective run progress + attendance rate, never a fake %.
5. **Course/session materials** — ✅ done (D49): link/note/file, enrollment-scoped, private-file
   store separate from identity docs.
6. **Support tickets** — ✅ done (D51): IDOR-safe student ↔ staff threads, 4 statuses.
7. **Internal notifications** — ✅ done (D50): on-site only, deliberate event set.
8. **Certificates + public verification** — ✅ done (D48): manual issue per enrollment, random
   non-PII code, `/verify/` page, revoke.
9. **In-panel settings** — ✅ done (D52): canonical option + sanitizer.
10. **Magazine/blog** — still deferred: an editorial archive + single-post presentation, after
    launch content priorities are set. Native WordPress posts remain available in the meantime.

Node static **876/0**; Docker real-WordPress acceptance **576/0 PASS, cleanup verified**. What
remains before merge: **one comprehensive browser/visual review** (manager / reception / teacher /
TA / student, desktop + mobile, RTL, light + dark), then the **integrated `mystik.ir` staging
cycle**. `drhedayati.com` remains untouched until the production cutover is approved.
