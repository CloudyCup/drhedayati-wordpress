/**
 * Hedayati Core — Admin JavaScript
 * Handles accessible repeatable list fields with Move Up / Move Down reordering
 * (Syllabus, Target Audience, Learning Outcomes) in the Course Meta Box.
 */

(function () {
  'use strict';

  /**
   * Update the disabled/enabled states and aria-disabled attributes
   * for Move Up and Move Down buttons across all rows in a list.
   * @param {HTMLElement} list
   */
  function updateButtonStates(list) {
    if (!list) return;

    const rows = Array.from(list.querySelectorAll('.hd-repeater-row'));
    const total = rows.length;

    rows.forEach(function (row, index) {
      const upBtn = row.querySelector('.hd-repeater-move-up');
      const downBtn = row.querySelector('.hd-repeater-move-down');

      if (upBtn) {
        const isFirst = index === 0;
        upBtn.disabled = isFirst;
        upBtn.setAttribute('aria-disabled', isFirst ? 'true' : 'false');
      }

      if (downBtn) {
        const isLast = index === total - 1;
        downBtn.disabled = isLast;
        downBtn.setAttribute('aria-disabled', isLast ? 'true' : 'false');
      }
    });
  }

  /**
   * Create a new repeater row DOM element.
   * @param {string} fieldName
   * @param {string} placeholder
   * @param {string} value
   * @returns {HTMLElement}
   */
  function createRow(fieldName, placeholder, value) {
    const row = document.createElement('div');
    row.className = 'hd-repeater-row';
    row.innerHTML = `
      <div class="hd-repeater-btn-group">
        <button type="button" class="button hd-repeater-move-up" title="انتقال به بالا" aria-label="انتقال این مورد به بالا">▲</button>
        <button type="button" class="button hd-repeater-move-down" title="انتقال به پایین" aria-label="انتقال این مورد به پایین">▼</button>
      </div>
      <input type="text" name="${fieldName}[]" value="${value || ''}" placeholder="${placeholder}" class="hd-repeater-input">
      <button type="button" class="button hd-repeater-remove-btn" title="حذف این مورد" aria-label="حذف این مورد">✕</button>
    `;
    return row;
  }

  function initRepeaters() {
    const metaBox = document.querySelector('.hd-meta-box');
    if (!metaBox) return;

    // Initial button state sync on load
    metaBox.querySelectorAll('.hd-repeater-list').forEach(function (list) {
      updateButtonStates(list);
    });

    // Delegated click handler
    metaBox.addEventListener('click', function (e) {
      // 1. Move Up
      const upBtn = e.target.closest('.hd-repeater-move-up');
      if (upBtn && !upBtn.disabled) {
        e.preventDefault();
        const row = upBtn.closest('.hd-repeater-row');
        if (!row) return;
        const list = row.closest('.hd-repeater-list');
        const prev = row.previousElementSibling;
        if (prev && list) {
          list.insertBefore(row, prev);
          updateButtonStates(list);
          const newUpBtn = row.querySelector('.hd-repeater-move-up');
          if (newUpBtn && !newUpBtn.disabled) {
            newUpBtn.focus();
          } else {
            const input = row.querySelector('.hd-repeater-input');
            if (input) input.focus();
          }
        }
        return;
      }

      // 2. Move Down
      const downBtn = e.target.closest('.hd-repeater-move-down');
      if (downBtn && !downBtn.disabled) {
        e.preventDefault();
        const row = downBtn.closest('.hd-repeater-row');
        if (!row) return;
        const list = row.closest('.hd-repeater-list');
        const next = row.nextElementSibling;
        if (next && list) {
          list.insertBefore(next, row);
          updateButtonStates(list);
          const newDownBtn = row.querySelector('.hd-repeater-move-down');
          if (newDownBtn && !newDownBtn.disabled) {
            newDownBtn.focus();
          } else {
            const input = row.querySelector('.hd-repeater-input');
            if (input) input.focus();
          }
        }
        return;
      }

      // 3. Add Item
      const addBtn = e.target.closest('.hd-repeater-add-btn');
      if (addBtn) {
        e.preventDefault();
        const repeater = addBtn.closest('.hd-repeater-wrapper');
        if (!repeater) return;

        const list = repeater.querySelector('.hd-repeater-list');
        const fieldName = repeater.getAttribute('data-field-name');
        const placeholder = repeater.getAttribute('data-placeholder') || '';

        if (!list || !fieldName) return;

        const row = createRow(fieldName, placeholder, '');
        list.appendChild(row);
        updateButtonStates(list);

        const input = row.querySelector('.hd-repeater-input');
        if (input) input.focus();
        return;
      }

      // 4. Remove Item
      const removeBtn = e.target.closest('.hd-repeater-remove-btn');
      if (removeBtn) {
        e.preventDefault();
        const row = removeBtn.closest('.hd-repeater-row');
        if (row) {
          const list = row.closest('.hd-repeater-list');
          const prev = row.previousElementSibling;
          const next = row.nextElementSibling;
          row.remove();
          if (list) {
            updateButtonStates(list);
            const focusTarget = (prev && prev.querySelector('.hd-repeater-input'))
              || (next && next.querySelector('.hd-repeater-input'))
              || addBtn;
            if (focusTarget) focusTarget.focus();
          }
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRepeaters);
  } else {
    initRepeaters();
  }
}());
