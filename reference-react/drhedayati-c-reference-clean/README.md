# Dr Hedayati Redesign Concepts

Interactive React/Vite prototype containing three separate visual directions for drhedayati.com, plus demo admin and student dashboards.

## What is included

- Three owner-review concepts:
  1. Editorial Redline — premium editorial, bold typography, controlled red accents.
  2. Geometric Identity — strong red/white/black geometry inspired by the institute's Instagram identity.
  3. Precision Academy — calmer, highly usable, premium education-platform direction.
- Persian RTL-first layout.
- Light and dark modes.
- Course directory and search/filtering.
- Course detail pages.
- About, certificates, magazine, contact and consultation pages.
- Admin dashboard with working prototype course editing, publish/draft toggles, create/delete, saved to localStorage.
- Student dashboard with course progress, next class, certificate and support areas.
- Fully responsive desktop/tablet/mobile styling.
- No generated photos are required. Abstract course visuals are CSS geometry only.

## Run locally

```bash
npm install
npm run dev
```

## Important: prototype vs production

This package is intentionally a design/UX prototype for choosing the visual direction. Admin edits currently persist only in browser localStorage. Do NOT use it as the live production backend yet.

After the owner chooses a design direction, production should add:

- authenticated admin/student accounts
- server-side data/database
- image/file storage
- real registration/consultation submissions
- role permissions and audit logs
- certificate verification data
- migration of content from the existing ASP.NET site/database
- redirects from legacy URLs
- SEO metadata, schema and sitemap
- rate limiting, CSRF/security controls where relevant
- backup/staging/deployment plan

## Persian font

The CSS looks for `/public/fonts/Vazirmatn.woff2`, but gracefully falls back to installed Persian-capable fonts if absent. Add your licensed/preferred production font later.
