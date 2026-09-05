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
}());
