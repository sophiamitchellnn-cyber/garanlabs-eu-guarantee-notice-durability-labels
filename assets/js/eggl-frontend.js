// EU Guarantee Notice & GARAN Labels
// Front-end enhancement: opens the tabbed modal with the official graphics.
// Everything renders server-side; this only toggles visibility. Loaded via
// wp_enqueue_script (no inline scripts).
(function () {
  var lastTrigger = null;

  function openModal(modal, tab) {
    if (!modal) return;
    modal.removeAttribute('hidden');
    document.body.classList.add('eggl-modal-open');
    if (tab) switchTab(modal, tab);
    var closeBtn = modal.querySelector('.eggl-modal__close');
    if (closeBtn) closeBtn.focus();
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.setAttribute('hidden', '');
    document.body.classList.remove('eggl-modal-open');
    if (lastTrigger) { lastTrigger.focus(); lastTrigger = null; }
  }

  function switchTab(modal, tab) {
    var buttons = modal.querySelectorAll('[data-eggl-tab-btn]');
    var panels = modal.querySelectorAll('[data-eggl-panel]');
    buttons.forEach(function (btn) {
      var active = btn.getAttribute('data-eggl-tab-btn') === tab;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    panels.forEach(function (panel) {
      if (panel.getAttribute('data-eggl-panel') === tab) panel.removeAttribute('hidden');
      else panel.setAttribute('hidden', '');
    });
  }

  function toggleNested(trigger) {
    var panel = document.getElementById(trigger.getAttribute('data-eggl-toggle'));
    if (!panel) return;
    var expanded = panel.hasAttribute('hidden');
    if (expanded) panel.removeAttribute('hidden');
    else panel.setAttribute('hidden', '');
    trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  document.addEventListener('click', function (e) {
    var toggle = e.target.closest && e.target.closest('[data-eggl-toggle]');
    if (toggle) {
      toggleNested(toggle);
      return;
    }

    var opener = e.target.closest && e.target.closest('[data-eggl-open]');
    if (opener) {
      if (opener.tagName === 'A') e.preventDefault(); // menu-link variant: don't jump to the #anchor
      var modal = document.getElementById(opener.getAttribute('data-eggl-open'));
      lastTrigger = opener;
      openModal(modal, opener.getAttribute('data-eggl-tab'));
      return;
    }

    var closer = e.target.closest && e.target.closest('[data-eggl-close]');
    if (closer) {
      closeModal(closer.closest('[data-eggl-modal]'));
      return;
    }

    var tabBtn = e.target.closest && e.target.closest('[data-eggl-tab-btn]');
    if (tabBtn) {
      var wrap = tabBtn.closest('[data-eggl-modal]');
      if (wrap) switchTab(wrap, tabBtn.getAttribute('data-eggl-tab-btn'));
    }
  });

  // Variable products: swap the GARAN label to the chosen variation's (see
  // EGGL_Frontend::render_garan()). WooCommerce fires these as jQuery events.
  function showGaran(form, variationId) {
    var scope = (form.closest && form.closest('.product')) || document;
    var wrap = scope.querySelector('[data-eggl-garan-variations]') || document.querySelector('[data-eggl-garan-variations]');
    if (!wrap) return;
    var current = wrap.querySelector('[data-eggl-garan-current]');
    var tpl = variationId
      ? wrap.querySelector('template[data-eggl-garan-variation="' + variationId + '"]')
      : null;
    if (!tpl) tpl = wrap.querySelector('template[data-eggl-garan-default]');
    if (!current || !tpl) return;
    current.innerHTML = tpl.innerHTML;
  }

  if (window.jQuery) {
    window.jQuery(document)
      .on('found_variation', '.variations_form', function (e, variation) {
        showGaran(this, variation && variation.variation_id);
      })
      .on('reset_data', '.variations_form', function () {
        showGaran(this, 0);
      });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('[data-eggl-modal]:not([hidden])');
    if (open) closeModal(open);
  });
})();
