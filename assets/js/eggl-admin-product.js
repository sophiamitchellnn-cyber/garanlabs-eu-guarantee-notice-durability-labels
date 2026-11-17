/**
 * EU Guarantee Notice & GARAN Labels — live preview for the product data
 * "EU Guarantee" tab and for each variation's GARAN fields. Reflects field
 * values on the label graphic as the merchant types; no network requests,
 * purely client-side. A variation's blank fields preview the parent tab's
 * current (possibly unsaved) values, matching how they're resolved on save.
 */
(function () {
    'use strict';

    var parentEditor = null;
    var variationUpdaters = [];

    var fit = window.egglFit || null;

    /** Rendered width of text in SVG units — mirrors EGGL_Garan_Fit::width(). */
    function textWidth(text, weight, size, trackingEm) {
        if (!fit || !text) return 0;
        var upm = fit.metrics.units_per_em || 2048;
        var table = fit.metrics[weight] || {};
        var chars = Array.from(text);
        var units = 0;
        chars.forEach(function (ch) {
            var adv = table[String(ch.codePointAt(0))];
            units += (adv === undefined) ? 0.6 * upm : adv;
        });
        return units / upm * size + (chars.length - 1) * (trackingEm || 0) * size;
    }

    function widthOf(name, value) {
        if (!fit) return 0;
        return name === 'years'
            ? textWidth(value, '800', fit.sizes.years, fit.sizes.yearsTracking)
            : textWidth(value, '400', fit.sizes.field, 0);
    }

    /** True if value is too wide for its field on the label. */
    function tooWide(name, value) {
        return !!(fit && value && widthOf(name, value) > fit.widths[name] + (fit.tolerance || 0));
    }

    function setSvgText(el, defaultHtml, value, name) {
        if (!el) return;
        if (value) {
            el.textContent = value;
        } else {
            el.innerHTML = defaultHtml;
        }
        // Compress over-long text exactly as the storefront does (EGGL_Garan_Fit::squeeze_attrs()).
        if (value && tooWide(name, value)) {
            el.setAttribute('textLength', fit.widths[name]);
            el.setAttribute('lengthAdjust', 'spacingAndGlyphs');
        } else {
            el.removeAttribute('textLength');
            el.removeAttribute('lengthAdjust');
        }
    }

    function field(editor, name) {
        return editor ? editor.querySelector('[data-eggl-input="' + name + '"]') : null;
    }

    function textValue(editor, name) {
        var input = field(editor, name);
        return input ? input.value.trim() : '';
    }

    /** Parent tab's "Show GARAN label" state — on unless its checkbox exists and is unticked. */
    function parentShows() {
        var box = field(parentEditor, 'show_garan');
        return !box || box.checked;
    }

    function bind(editor) {
        if (editor.getAttribute('data-eggl-bound')) return;
        editor.setAttribute('data-eggl-bound', '1');

        var isVariation = editor.hasAttribute('data-eggl-variation');
        var preview = editor.querySelector('[data-eggl-preview]');
        var hiddenNote = editor.querySelector('[data-eggl-hidden-note]');
        var fitWarning = editor.querySelector('[data-eggl-fit-warning]');

        var svgBrand = editor.querySelector('[data-eggl-svg="brand"]');
        var svgModel = editor.querySelector('[data-eggl-svg="model"]');
        var svgYears = editor.querySelector('[data-eggl-svg="years"]');

        // The server always renders the design's own placeholders here, so
        // whatever is in the DOM right now IS the placeholder to restore.
        var defaults = {
            brand: svgBrand ? svgBrand.innerHTML : '',
            model: svgModel ? svgModel.innerHTML : '',
            years: svgYears ? svgYears.innerHTML : ''
        };

        function value(name) {
            var v = textValue(editor, name);
            return (!v && isVariation) ? textValue(parentEditor, name) : v;
        }

        function updateCount(name) {
            var input = field(editor, name);
            var counter = editor.querySelector('[data-eggl-count="' + name + '"]');
            if (!input || !counter) return;
            var max = input.getAttribute('maxlength') || 40;
            counter.textContent = input.value.length + '/' + max;
        }

        function isOff() {
            var show = field(editor, 'show_garan');
            if (!isVariation) return !!(show && !show.checked);
            var choice = show ? show.value : '';
            return choice === 'no' || (choice === '' && !parentShows());
        }

        function updatePreview() {
            var brand = value('brand');
            var model = value('model');
            var years = parseInt(value('years'), 10);
            var yearsText = years > 0 ? String(years) : '';

            setSvgText(svgBrand, defaults.brand, brand, 'brand');
            setSvgText(svgModel, defaults.model, model, 'model');
            setSvgText(svgYears, defaults.years, yearsText, 'years');

            if (fitWarning) {
                var messages = [];
                if (tooWide('brand', brand)) messages.push(fit.i18n.brand);
                if (tooWide('model', model)) messages.push(fit.i18n.model);
                if (tooWide('years', yearsText)) messages.push(fit.i18n.years);
                fitWarning.innerHTML = '';
                messages.forEach(function (msg) {
                    var li = document.createElement('li');
                    li.textContent = msg;
                    fitWarning.appendChild(li);
                });
                fitWarning.hidden = !messages.length;
            }

            updateCount('brand');
            updateCount('model');

            var off = isOff();
            if (preview) preview.classList.toggle('is-off', off);
            if (hiddenNote) hiddenNote.hidden = !off;

            // Variations inherit blank fields from here, so keep their previews in step.
            if (!isVariation) {
                // Panels replaced by a variations reload/pagination are gone — drop them.
                variationUpdaters = variationUpdaters.filter(function (u) { return document.body.contains(u.editor); });
                variationUpdaters.forEach(function (u) { u.update(); });
            }
        }

        editor.addEventListener('input', updatePreview);
        editor.addEventListener('change', updatePreview);
        if (isVariation) variationUpdaters.push({ editor: editor, update: updatePreview });
        updatePreview();
    }

    function init() {
        parentEditor = document.querySelector('[data-eggl-parent-editor]');
        var editors = document.querySelectorAll('[data-eggl-editor]');
        for (var i = 0; i < editors.length; i++) bind(editors[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Variation panels are loaded over AJAX after the page is ready.
    if (window.jQuery) {
        window.jQuery(document).on('woocommerce_variations_loaded woocommerce_variations_added', '#woocommerce-product-data', init);
    }
})();
