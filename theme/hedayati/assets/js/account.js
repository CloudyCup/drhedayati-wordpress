/**
 * Hedayati Theme — Student Account Portal JavaScript
 *
 * Vanilla JS, no framework, no jQuery dependency, deferred, loaded only on
 * the account page. Same single-IIFE + getElementById-guard convention as
 * main.js. Progressive enhancement only — every form here already works as a
 * plain HTML POST without this file; this only prevents an accidental
 * double-submit while a mutation is in flight.
 */

(function () {
  'use strict';

  var forms = document.querySelectorAll('.hd-portal-form');

  forms.forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = form.querySelector('button[type="submit"]');
      if (button) {
        // Let the submit proceed; only prevent a second click while it does.
        window.setTimeout(function () {
          button.disabled = true;
        }, 0);
      }
    });
  });

  /*
   * D58 — app-shell rail as an off-canvas drawer below 1024px. The rail is a
   * plain list of links, fully usable without this code on desktop; on small
   * screens CSS hides it until the menu button opens it.
   */
  var app = document.getElementById('hd-app');
  var rail = document.getElementById('hd-app-rail');
  var menuBtn = app ? app.querySelector('.hd-app-menu-btn') : null;
  var backdrop = app ? app.querySelector('.hd-app-backdrop') : null;

  if (app && rail && menuBtn) {
    var setOpen = function (open) {
      app.classList.toggle('is-rail-open', open);
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (backdrop) {
        backdrop.hidden = !open;
      }
      if (open) {
        var first = rail.querySelector('a, button');
        if (first) {
          first.focus();
        }
      } else {
        menuBtn.focus();
      }
    };

    menuBtn.addEventListener('click', function () {
      setOpen(!app.classList.contains('is-rail-open'));
    });

    app.querySelectorAll('[data-hd-app-close]').forEach(function (el) {
      el.addEventListener('click', function () {
        setOpen(false);
      });
    });

    document.addEventListener('keydown', function (event) {
      if ('Escape' === event.key && app.classList.contains('is-rail-open')) {
        setOpen(false);
      }
    });
  }
}());
