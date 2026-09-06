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
| Institute settings | Capability-gated Settings API page | Reached from the manager home |
| Teachers | Teacher CPT and explicit public opt-in | Reached from the manager home |
| Audit history | Append-only metadata-only audit log | Reached from the manager home |
| Consultation requests | Phone/contact CTA only | Build later with explicit fields, retention, spam controls, and a staff workflow |
| Certificates and public verification | No approved policy or data model | Build only after issuer, eligibility, revocation, identifier, and public-data rules are approved |
| Student progress | Enrollments, sessions, attendance exist; no progress formula | Define whether progress means attendance, completed sessions, assessment, or staff-set progress before building |
| Student calendar | Real Course Run sessions exist | **Implemented on feature branch:** read-only upcoming sessions for the signed-in student's active enrollments; other students and past sessions are excluded |
| Support tickets | Not built | Define recipients, attachments, status, retention, and notification behavior before building |
| Notifications | No notification store/delivery channel | Add only with event, recipient, retention, and delivery rules |
| Magazine/blog | Native WordPress posts are available; dedicated presentation is not built | Design an editorial archive and single-post presentation after launch content priorities are set |

## Delivery sequence

1. **Portal foundation:** designed manager and student dashboards, real counts/data, operational
   navigation, RTL, responsive layout, dark mode, and unchanged capability enforcement. Includes
   the owner-scoped student upcoming-class schedule. This is the current branch.
2. **Consultation workflow:** the smallest valuable new data module. Store only required contact
   information, use server-side validation and rate limiting, provide manager/reception statuses,
   and define deletion/retention before release.
3. **Progress:** add a calculation only after the institute defines what the percentage means.
4. **Certificates:** treat issuance and public verification as a separate security-sensitive
   project with revocation and audit rules.
5. **Support and notifications:** add once the institute names the responsible staff and expected
   response workflow.
6. **Magazine/blog:** finish public editorial design and content migration separately from student
   and administrative records.

Each increment must pass static tests, real WordPress runtime tests, and a real browser review at
desktop/mobile widths in Persian RTL and light/dark mode before it is merged. Deployment to
`mystik.ir` remains a separate owner-approved step; `drhedayati.com` remains untouched until the
production cutover is approved.
