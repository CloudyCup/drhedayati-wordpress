# PROJECT.md

## Product

The public website and future operational platform for **مجتمع آموزشی دکتر هدایتی**
(Dr. Hedayati Educational Complex) — a Persian-language **computer and information-technology
training institute** in Iran, with locations in **Tabriz** and **Tehran**. It is an education
business, **not** a medical practice.

The institute teaches a broad catalog: networking, security, programming and web, mobile, data /
machine learning, financial-markets analysis, 3D modeling / rendering, graphics and content
creation, ICDL, children's computer education, and accounting software.

Two goals drive the rebuild:

1. **Replace the dated public site** with a modern, elegant, easy-to-navigate Persian/RTL site
   that looks professionally designed and stays editable by institute staff without a developer.
2. **Gradually add a secure operational layer** — real accounts, phone login, student profiles,
   identity verification, private documents, teachers, course runs, sessions, enrollments, staff
   panels, and auditability.

The finished system should let staff manage public content and operational education data on their
own, while protecting private student information and keeping the current production business
running throughout the rebuild.

## Organization

- **Brand identity:** Dr. Hedayati red (`#c52232`), white / warm white, black / charcoal — derived
  from the institute's existing identity and Instagram presence.
- **Tone:** premium, restrained, modern technology institute. Not childish, not a generic dashboard.
- **Language:** Persian throughout; native RTL. Mixed Persian/English technical terms
  (`CCNA`, `IPv4`, `Python`, command strings) must render correctly, not reversed or mangled.

## Users / audiences

| Audience | Needs |
|---|---|
| Prospective students & visitors | Understand the institute, browse courses and categories, find a course, request consultation |
| Enrolled students | Account, profile, verification status, private document upload, view enrolled courses/runs and sessions |
| Teachers | See only assigned course runs, rosters, sessions; record attendance |
| Teacher assistants (TAs) | See only assigned runs and rosters; no attendance by default |
| Reception staff | Look up students, create/basic-edit enrollments, view basic profiles, initiate verification |
| Institute managers | Operate courses, runs, staff assignments, enrollments, verification, private documents, audit logs, settings |
| Technical WordPress administrator | System owner; all capabilities |

## Goals

- A Persian-first, RTL, accessible, responsive public site with light and dark modes.
- Staff-editable course catalog and institute settings, no developer needed for ordinary edits.
- A secure, WordPress-native identity layer (username **or** Iranian phone + password).
- A phased path to full academic operations (course runs, sessions, enrollments) and student
  identity/document management with least-privilege roles and audit trails.
- Preserve the legacy site's worthwhile content, URLs, and SEO value through a planned migration.

## Scope

### In scope

- Custom `hedayati` theme and `hedayati-core` plugin on standard WordPress.
- Public content: homepage, course catalog, course categories, course detail, About, Contact,
  consultation, and (later) blog/articles migrated from the old site.
- Identity & operations: phone/username login, student accounts and profiles, verification,
  private documents, teachers, course runs, sessions, enrollments, staff panels, audit logs.
- Localization: Persian UI, RTL, Shamsi date input/display layer over Gregorian storage.

### Out of scope / undecided

- Online payment gateway (possible future).
- Separate media host for guides/videos (possible future).
- SMS/OTP (planned via a provider abstraction; provider not chosen; **not** required for
  password login).
- Automated ~48-hour offsite transfer of sensitive documents (desired workflow, protocol not
  specified).
- Google / third-party social login — explicitly **not wanted**.

## Environments

- **Staging:** `mystik.ir` — fresh WordPress on ParsPack hosting (cPanel), PHP 8.3, LiteSpeed cache.
- **Production:** `drhedayati.com` — legacy custom ASP.NET / MSSQL application, still live. Untouched
  until an approved cutover with backups and a rollback plan.

## Major functionality (current)

- **Course catalog** — `course` custom post type, hierarchical `course-category` taxonomy, rich
  per-course metadata, staff authoring UI, featured-course selection for the homepage.
- **Institute settings** — consultation / Tabriz / Tehran phone numbers and Tabriz address, edited
  under Settings → Hedayati, surfaced in the footer and CTAs.
- **Public site** — Navigator-style homepage, course archive with category filter, per-category
  archives, data-driven single-course landing pages, branded 404, light/dark toggle, responsive
  RTL layout.
- **Identity foundation (backend only)** — Iranian phone normalization, dual username-or-phone
  login, unique phone-identity table, versioned DB migrations, five custom roles with 21 granular
  capabilities, authentication rate limiting.

See `docs/CURRENT_STATE.md` for exactly what is verified vs pending.
