# DESIGN_SYSTEM.md

The active visual direction is **Concept C / "Navigator"** (`NavigatorHome` in
`reference-react/src/components/Concepts.jsx`), evolved from the "Precision" family. It is
customer-oriented: it helps a visitor quickly answer *"چه می‌خواهی یاد بگیری؟"* ("what do you want
to learn?"). The Persian motif **«چارچوب»** (framework / structure / ordered grids) informs the
visual language.

Two sources of truth in the repo:

- **Design tokens** — `theme/hedayati/theme.json` (block-editor presets) and the CSS custom
  properties at the top of `theme/hedayati/assets/css/main.css` (`:root` and `[data-theme="dark"]`).
- **Component styles** — the rest of `main.css` (~2400 lines, sectioned 1–20) plus
  `assets/css/rtl.css`.

> Rejected alternatives (do not resurrect): Editorial Redline, Geometric Identity, Concept A
> "چارچوب/Framework", Concept B "محور/Axis", and pre-Precision prototype directions.

---

## Brand

- **Character:** premium, restrained, modern technology institute. Not childish, not "graphic
  design portfolio", not generic admin dashboard.
- **Red** (`#c52232`) is used deliberately for emphasis, CTAs, active states, and small marks —
  never as a large flat fill on content areas (only the CTA band and buttons).
- **Both themes are fully designed**, not mechanically inverted. Dark mode shifts red slightly
  brighter (`#e24a57`) for contrast on dark surfaces.
- **Geometry:** frames, thin lines, ordered grids, strong composition — but content clarity always
  wins over decoration (e.g. the impact section's rotated square is a `::before` at 7% opacity).

---

## Color tokens

### Light (`:root` in `main.css`) — also mirrored as `theme.json` palette slugs

| CSS var | Value | `theme.json` slug | Role |
|---|---|---|---|
| `--hd-red` | `#c52232` | `brand-red` | Primary brand / CTA / accents |
| `--hd-red-dark` | `#a81827` | `brand-red-dark` | Hover/pressed red |
| `--hd-red-light` | `#f8e9eb` | `brand-red-light` | Red-tinted chip/badge background |
| `--hd-red-border` | `#ecc9ce` | — | Border for red-tinted chips |
| `--hd-ink` | `#1a1b1f` | `ink` | Primary text |
| `--hd-muted` | `#6e7077` | `muted` | Secondary text |
| `--hd-bg` | `#f6f4f1` | `bg` | Page background (warm off-white) |
| `--hd-surface` | `#fffdfa` | `surface` | Card / panel surface |
| `--hd-surface2` | `#efede9` | `surface-2` | Recessed / secondary surface |
| `--hd-line` | `#dedbd5` | — | Hairline borders / dividers |
| `--hd-black` | `#191a1d` | — | Impact section background |
| `--hd-deep` | `#151619` | `deep` | Console meta bar |
| `--hd-white` | `#ffffff` | `white` | Text on dark, white buttons |

### Dark (`[data-theme="dark"]` overrides)

`--hd-red` `#e24a57` · `--hd-red-dark` `#c93745` · `--hd-red-light` `#321a20` · `--hd-red-border`
`#563039` · `--hd-ink` `#f4f1ec` · `--hd-muted` `#aaa7a3` · `--hd-bg` `#101113` · `--hd-surface`
`#18191c` · `--hd-surface2` `#202226` · `--hd-line` `#2d2f34` · `--hd-black` `#0e0f11` · `--hd-deep`
`#0d0e10`. Shadows deepen. Footer/impact go near-black (`#090a0c`).

### Semantic status colors (hardcoded, not tokenized)

Open/success green `#16a34a` (dot `#22c55e`), "soon"/warning amber `#d97706` (dot `#f59e0b`),
closed = `--hd-muted`. Used by `.seats-badge` / `.status-badge` / `.course-state-indicator` and the
`hedayati_registration_state_display()` helper (`is-open` / `is-closed` / `is-soon`).

### Other

- `--hd-shadow` `0 12px 34px rgba(35,28,30,.065)` · `--hd-shadow-lg` `0 24px 60px rgba(35,28,30,.11)`
- `color-scheme` is set (`light` / `dark`) so form controls and scrollbars match.
- `::selection` → red background, white text.

---

## Typography

- **Family (token `--hd-font`):** `'Vazirmatn', 'B Yekan', 'Yekan Bakh', Tahoma, Arial, sans-serif`.
  **Vazirmatn is not yet shipped** — no WOFF2 files in the repo and `functions.php` deliberately
  does not enqueue a font, so the site currently renders in the fallback (Tahoma / system Persian
  fonts). Planned: self-hosted WOFF2, `font-display: swap`, weights **400** (body), **500**
  (secondary emphasis), **600** (nav/buttons/UI), **700** (card/section headings), **800**
  (major headings/hero). Avoid 900 except the existing decorative monogram / 404 code.
- **Mono (token `--hd-font-mono`):** `'Courier New', Courier, monospace` — English technical marks
  only (course English tag, department English label, brandline, syllabus numbers, category icon,
  monogram).
- **Base:** `body` 15px / line-height 1.7, `direction: rtl`, `text-align: right`,
  antialiased.
- **Headings:** weight 800, `letter-spacing: -0.02em`, line-height 1.3.
  `h1` `clamp(32px, 4.5vw, 54px)` · `h2` `clamp(24px, 3.2vw, 36px)` · `h3` `clamp(18px, 2vw, 22px)`.
  (`theme.json` sets slightly different h1/h2 weights of 800 and `--wp--preset--font-size--hero`
  = `clamp(34px, 4.5vw, 54px)`.)
- **`theme.json` font sizes:** `small` 12 · `normal` 14 · `medium` 16 · `large` 20 ·
  `x-large` `clamp(24px,3.2vw,36px)` · `hero` `clamp(34px,4.5vw,54px)`.
- **Paragraphs:** line-height 1.75–1.9 for Persian readability.

### Bidi / mixed script

- `[dir="ltr"]` and `.ltr-text` → `direction: ltr; unicode-bidi: isolate`.
- `rtl.css` additionally forces `direction: ltr; unicode-bidi: embed` on `.course-monogram`,
  `.course-english-tag`, `.course-english-badge`, `.nav-brandline`, `time[datetime]`, and footer
  phone spans.
- Course English names are `strtoupper()`-ed and wrapped in `dir="ltr"` spans in templates.
- Dates render inside `<time datetime="YYYY-MM-DD" dir="ltr">` (machine-readable Gregorian).
- **Admin (Phase 2B «عملیات آموزشی»):** dates show the Gregorian value **plus** the Shamsi
  equivalent with Persian digits in parentheses, e.g. `2026-03-21 (۱۴۰۴/۱۲/۳۰)`, via
  `Hedayati_Jalali::format()`. Storage stays Gregorian ISO / ASCII (D9). Public-site Shamsi
  rendering and Shamsi *input* fields are follow-on work (ROADMAP P1.6).

---

## Spacing & layout

- **Container:** `--hd-container: min(1240px, calc(100% - 44px))`; on mobile (`≤768px`)
  `min(100%, calc(100% - 32px))`. Single-course page uses a wider `min(1300px, 100% - 44px)`.
- **Section rhythm:** `.section { padding: 72px 0 }` (→ 40px on mobile). Hero/impact/CTA have
  bespoke padding.
- **Radii:** `--hd-radius` 15px (cards/panels), `--hd-radius-sm` 8px (buttons/pills), plus a few
  local values (console 19px, chips/pills 5–7px, filter chips 20px).
- **`theme.json` spacing:** 7-step geometric scale, unit `rem`, increment 1.5.
- **`theme.json` layout:** `contentSize` 780px, `wideSize` 1240px, root-padding-aware alignments.
- **Grid patterns:**
  - Navigator hero: `0.92fr 1.08fr` → 1 col ≤1024px.
  - Department console: 2×2 → 1 col ≤900px.
  - Category strip: `repeat(auto-fill, minmax(240px, 300px))` centered → 2 col ≤1024px → 1 col ≤768px.
  - Featured grid: `repeat(auto-fill, minmax(260px, 300px))` centered → wider tiles ≤1024px →
    single centered `minmax(0, 420px)` ≤768px.
  - Archive `.courses-grid`: 3 col → 2 col ≤900px → 1 col ≤768px.
  - Related courses: 3 col → 2 col ≤1024px → 1 col ≤768px.
  - Single-course body: `1fr 320px` → 1 col ≤1024px (sidebar becomes static).
  - Footer: `1.5fr 0.7fr 1fr 1.2fr` → 2 col ≤1024px → 1 col ≤768px.

---

## Components

| Component | Class(es) | Notes |
|---|---|---|
| Primary button | `.solid-btn` (`.large`) | Red fill, white text; hover → `--hd-red-dark` + `translateY(-1px)` |
| Secondary button | `.outline-btn` | Surface bg, hairline border; hover → red border + red text |
| On-dark button | `.white-btn` | White fill (impact section) |
| Link button | `.link-btn` | Underline-style, no fill |
| Card CTA | `.card-action-btn` | Small; turns solid red on card hover |
| CTA band button | `.cta-band-btn` | White on red band |
| Filter chip | `.filter-chip` (`.active`) | Pill; active → red fill |
| Section label | `.section-heading > span` | Red text on `--hd-red-light`, 6px radius rectangle |
| Eyebrow (on dark) | `.eyebrow.light` | Pill, translucent white |
| Status badge | `.seats-badge` / `.status-badge` + `.is-open`/`.is-closed`/`.is-soon` | Colored dot; open dot pulses (`@keyframes pulse-dot`, disabled under reduced-motion) |
| Course card | `.course-card` | Topline (category + English tag) → art panel (thumbnail or dark monogram panel with red dot-grid) → body (meta pills, title, excerpt) → footer (status + CTA). Hover → lift + red border |
| Department console tile | `.console-dept-btn` | Inline-SVG icon keyed by term slug (`network`/`security`/`programming`/`data`/`design`/`default`) |
| Category strip item | `.category-strip-item` | Icon (term meta `course_cat_icon` or first char) + Persian name + optional English label + chevron |
| Quick-fact card | `.fact-card` | Icon + label + value; only rendered for non-empty facts |
| Sticky enrollment card | `.course-sticky-card` | `position: sticky; top: 94px` (126px with admin bar); static ≤1024px |
| Sidebar/skip link | `.skip-link` | Hidden until `:focus`, targets `#site-main` |
| Focus ring | `:focus-visible` | `2px solid var(--hd-red)`, `outline-offset: 2px` |
| Scrollbar | `::-webkit-scrollbar*` + `scrollbar-width/color` | 8px, transparent track, translucent thumb, red on hover |

Icons are **inline SVG** in the markup (Feather-style, `stroke="currentColor"`). No icon font, no
sprite, no external request.

---

## Homepage sections (`front-page.php`, in order)

1. **`hero-navigator.php`** — two columns. Left: LTR mono brandline "NAVIGATE YOUR TECH CAREER",
   `h1` with red `<b>` emphasis, lead paragraph, "جستجوی همه دوره‌ها" + "مشاوره و تعیین سطح"
   actions. Right: "console" panel — header row, 2×2 grid of up to 4 top-level categories (slug →
   SVG icon), and a dark meta bar with two checkmarked claims. Admin-only empty state links to the
   term editor when no categories exist.
2. **`category-strip.php`** — up to 8 top-level categories as a bordered grid of icon + Persian
   name + English label + chevron. Renders **nothing** if no terms exist (no hardcoded fallback).
3. **`featured-courses.php`** — section heading + "مشاهده همه دوره‌ها" link + grid of up to 8
   featured courses (`course-card`). If none: public visitors see nothing; editors see a dashed
   admin hint linking to the course list.
4. **`impact-section.php`** — "چرا مجتمع دکتر هدایتی؟" dark editorial band: eyebrow, headline,
   paragraph, 4 institutional bullet chips, "آشنایی بیشتر با مجتمع" white button. **Stat numbers
   (years, graduates, …) are intentionally omitted** — they must come from institute-verified data
   via a future mechanism; the single-column layout and the commented-out `.stats-grid` are ready
   for it.
5. **`cta-band.php`** — full-width red band: label, headline, consultation phone (only if
   configured in settings), "درخواست تماس مشاوره" button linking to `/consult/`.

Header (`header.php`) and footer (`footer.php`) wrap every page — see `docs/ARCHITECTURE.md`.

---

## Responsive behavior

Breakpoints (from `main.css` §19): **1024px** (tablet — hero/console/single-course collapse to one
column, footer to 2 columns), **900px** (course grid → 2 col, console → 1 col, CTA stacks),
**768px** (mobile — fixed full-screen nav panel, all grids → 1 col, sections 40px padding),
**420px** (hide brand text, shrink hero `h1` to 28px).

- Desktop course grids target ~4 tiles where width allows (`auto-fill minmax`), then 2, then 1.
- Sparse featured/category sets stay **centered and width-capped** (`justify-content: center` +
  `minmax` max), never stretched to the RTL edge.
- Mobile nav is a fixed panel (`inset: 64px 0 0 0`, blurred background); `main.js` handles open/
  close, Escape, outside-click, link-click, and resize auto-close, with focus moved into and
  returned from the panel.
- Logical reading/tab order is preserved (grids reflow, not just visually reverse).

---

## Dark / light behavior (active)

- Initial theme set **before first paint** by the inline script in `header.php` (`wp_head` @1):
  explicit `localStorage['hedayati-theme']` (`light`/`dark`) wins, else `prefers-color-scheme`,
  else `light`. Sets `data-theme` on `<html>`.
- `main.js` `applyTheme(theme, isUserAction)` — only a real toggle click writes `localStorage`.
  OS scheme changes are followed live **only while no explicit choice is stored**.
- Toggle button (`#theme-toggle`) swaps sun/moon SVGs via
  `[data-theme="light"] .icon-moon { display:none }` etc., and keeps `aria-pressed` / `aria-label`
  in sync (Persian labels).
- `<html>` ships with `data-theme="light"` as a static default in `header.php`.

---

## Interaction principles

- Motion is subtle and functional (hover lifts of 1–3px, 0.2s ease transitions). All animation and
  smooth scroll are disabled under `@media (prefers-reduced-motion: reduce)`.
- Every interactive element has a visible `:focus-visible` ring.
- Disabled buttons (e.g. closed/soon enrollment) use the real `disabled` attribute, not just
  styling.
- Navigation is semantic `<nav><ul><li><a>` — no `role="menubar"`, no fake application patterns.
- Empty states are explicit and, where useful, show an **admin-only** hint with a link to the
  relevant admin screen; public visitors never see broken or decorative empty boxes.
- `format-detection: telephone=no` in `<head>`; phone links are explicit `tel:` anchors built from
  `Hedayati_Settings::tel_uri()`.

---

## Planned design work (not yet implemented)

- Ship and load self-hosted Vazirmatn WOFF2 (token already points at it).
- Real institute logo asset + CSS glow wrapper (SVG "H" is a placeholder).
- Impact-section statistics — verified numbers + an editing mechanism (Customizer options or
  plugin settings). Neither the mechanism nor the numbers exist yet.
- Templates/styles for the not-yet-built pages: login/account, student portal, staff panels,
  About, Contact, consultation, blog.
- Improved course-authoring editor layout (structured fields currently sit below a large Gutenberg
  canvas).
- Consider changing the `course_cat_order` default so unordered terms don't sort to the front.
