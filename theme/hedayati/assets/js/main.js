/**
 * Hedayati Theme — Main JavaScript
 *
 * Vanilla JS, no framework, no jQuery dependency.
 * Strategy: defer, loaded in footer.
 *
 * Modules:
 *   1. Dark/Light theme toggle (localStorage + prefers-color-scheme)
 *   2. Mobile navigation (accessible, keyboard + Escape)
 *   3. Sticky header scroll enhancement
 *
 * The dark-mode initialisation script in header.php runs BEFORE this
 * file to prevent a flash of the wrong colour scheme. This file only
 * handles the interactive toggle behaviour.
 */

(function () {
  'use strict';

  /* ────────────────────────────────────────────────────────────
   * 1. DARK / LIGHT THEME TOGGLE
   * ─────────────────────────────────────────────────────────── */

  const STORAGE_KEY = 'hedayati-theme';
  const html = document.documentElement;

  /**
   * Safely read stored theme from localStorage.
   * Returns 'light', 'dark', or null if not set or storage is unavailable.
   * @returns {'light'|'dark'|null}
   */
  function getStoredTheme() {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      return (stored === 'light' || stored === 'dark') ? stored : null;
    } catch (e) {
      return null;
    }
  }

  /**
   * Safely persist theme preference to localStorage.
   * @param {'light'|'dark'} theme
   */
  function setStoredTheme(theme) {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (e) {
      // Storage unavailable (e.g. private browsing restriction)
    }
  }

  /**
   * Determine resolved theme based on explicit preference or OS scheme.
   * @returns {'light'|'dark'}
   */
  function getResolvedTheme() {
    const stored = getStoredTheme();
    if (stored) return stored;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  /**
   * Apply theme to <html> and sync toggle button accessibility attributes.
   * Only persists to localStorage when isUserAction is true.
   * @param {'light'|'dark'} theme
   * @param {boolean} isUserAction
   */
  function applyTheme(theme, isUserAction = false) {
    html.setAttribute('data-theme', theme);

    if (isUserAction) {
      setStoredTheme(theme);
    }

    const btn = document.getElementById('theme-toggle');
    if (btn) {
      const isDark = theme === 'dark';
      btn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
      btn.setAttribute(
        'aria-label',
        isDark
          ? 'تغییر به حالت روشن'
          : 'تغییر به حالت تیره'
      );
    }
  }

  /**
   * Toggle between light and dark on button click.
   * Creates an explicit user preference.
   */
  function toggleTheme() {
    const current = getResolvedTheme();
    const next = current === 'dark' ? 'light' : 'dark';
    applyTheme(next, true);
  }

  // Wire up the toggle button
  const themeToggleBtn = document.getElementById('theme-toggle');
  if (themeToggleBtn) {
    // Sync button state on load without writing to localStorage
    applyTheme(getResolvedTheme(), false);
    themeToggleBtn.addEventListener('click', toggleTheme);
  }

  // React to OS-level changes only when NO explicit user preference is stored
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
      if (!getStoredTheme()) {
        applyTheme(e.matches ? 'dark' : 'light', false);
      }
    });
  }

  /* ────────────────────────────────────────────────────────────
   * 2. MOBILE NAVIGATION
   * ─────────────────────────────────────────────────────────── */

  const menuBtn = document.getElementById('mobile-menu-btn');
  const headerNav = document.getElementById('header-nav');

  if (menuBtn && headerNav) {
    /**
     * Open the mobile navigation panel.
     */
    function openNav() {
      menuBtn.setAttribute('aria-expanded', 'true');
      menuBtn.setAttribute('aria-label', 'بستن منو');
      headerNav.classList.add('nav-open');
      document.body.style.overflow = 'hidden';

      // Move focus to first nav link
      const firstLink = headerNav.querySelector('a, button');
      if (firstLink) firstLink.focus();
    }

    /**
     * Close the mobile navigation panel.
     * @param {boolean} returnFocus — return focus to the menu button.
     */
    function closeNav(returnFocus = true) {
      menuBtn.setAttribute('aria-expanded', 'false');
      menuBtn.setAttribute('aria-label', 'باز کردن منو');
      headerNav.classList.remove('nav-open');
      document.body.style.overflow = '';

      if (returnFocus) menuBtn.focus();
    }

    /** Toggle based on current state. */
    function toggleNav() {
      const isOpen = menuBtn.getAttribute('aria-expanded') === 'true';
      isOpen ? closeNav() : openNav();
    }

    // Button click
    menuBtn.addEventListener('click', toggleNav);

    // Escape key closes the nav
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menuBtn.getAttribute('aria-expanded') === 'true') {
        closeNav(true);
      }
    });

    // Close when clicking outside the header
    document.addEventListener('click', function (e) {
      const header = document.getElementById('site-header');
      if (
        header &&
        !header.contains(e.target) &&
        menuBtn.getAttribute('aria-expanded') === 'true'
      ) {
        closeNav(false);
      }
    });

    // Close on nav link click (navigating away)
    headerNav.addEventListener('click', function (e) {
      if (e.target.closest('a')) {
        closeNav(false);
      }
    });

    // Resize: auto-close mobile nav if window becomes desktop-width
    let resizeTimer;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        if (window.innerWidth > 768 && menuBtn.getAttribute('aria-expanded') === 'true') {
          closeNav(false);
        }
      }, 150);
    });
  }

  /* ────────────────────────────────────────────────────────────
   * 3. STICKY HEADER SCROLL ENHANCEMENT
   * ─────────────────────────────────────────────────────────── */

  const siteHeader = document.getElementById('site-header');

  if (siteHeader) {
    let lastScrollY = window.scrollY;

    function updateHeader() {
      const scrollY = window.scrollY;

      if (scrollY > 8) {
        siteHeader.classList.add('scrolled');
      } else {
        siteHeader.classList.remove('scrolled');
      }

      lastScrollY = scrollY;
    }

    // Initial check
    updateHeader();

    // Throttled scroll listener
    let scrollTicking = false;
    window.addEventListener('scroll', function () {
      if (!scrollTicking) {
        window.requestAnimationFrame(function () {
          updateHeader();
          scrollTicking = false;
        });
        scrollTicking = true;
      }
    }, { passive: true });
  }

}());
