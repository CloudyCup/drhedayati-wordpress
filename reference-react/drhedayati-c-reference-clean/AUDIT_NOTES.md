# Dr Hedayati React Prototype — Audit Notes

This package is a sanitized visual-reference copy of the uploaded prototype.

Removed intentionally:
- `.env` (contained local development credentials/secrets)
- `.git/` history
- `node_modules/` (stale/mixed dependency state from different branches)
- `public/favicon.png` and `public/assets/logo.png` (both were corrupt binary files and were not referenced by the running app)

Kept:
- all React source files
- complete CSS
- valid `public/logo.png`
- package manifests/config files

The approved C direction is `NavigatorHome` in `src/components/Concepts.jsx` plus shared components/styles.
The prototype still contains A/B concepts and the preview toolbar; these should not be ported to production WordPress.
