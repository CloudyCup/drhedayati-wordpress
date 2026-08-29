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
   * Return the active theme ('light' | 'dark').
   * Reads localStorage first, falls back to OS preference.
   * @returns {'light'|'dark'}
   */
  function getActiveTheme() {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'light' || stored === 'dark') return stored;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  /**
   * Apply a theme to the document and persist it.
   * @param {'light'|'dark'} theme
   */
  function applyTheme(theme) {
    html.setAttribute('data-theme', theme);
    localStorage.setItem(STORAGE_KEY, theme);

    // Update toggle button accessible state
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
   * Toggle between light and dark.
   */
  function toggleTheme() {
    const current = getActiveTheme();
    applyTheme(current === 'dark' ? 'light' : 'dark');
  }

  // Wire up the toggle button
  const themeToggleBtn = document.getElementById('theme-toggle');
  if (themeToggleBtn) {
    // Sync initial button state with current theme
    applyTheme(getActiveTheme());

    themeToggleBtn.addEventListener('click', toggleTheme);
  }

  // React to OS-level changes (only when no explicit preference stored)
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
    if (!localStorage.getItem(STORAGE_KEY)) {
      applyTheme(e.matches ? 'dark' : 'light');
    }
  });

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
