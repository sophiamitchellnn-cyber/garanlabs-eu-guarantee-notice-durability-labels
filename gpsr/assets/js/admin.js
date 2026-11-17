/* EU Guarantee & GARAN — "Product Safety (GPSR)" product data tab: section switches and document pickers. */
jQuery(function ($) {
    var cfg = window.egglGpsr || { mimeTypes: [], i18n: {} };
    var $panel = $('#eggl_gpsr_data');

    $panel.on('change', '[data-eggl-gpsr-toggle]', function () {
        $(this).closest('[data-eggl-gpsr-section]').find('[data-eggl-gpsr-body]').prop('hidden', !this.checked);
    });

    $panel.on('click', '[data-eggl-gpsr-doc-pick]', function (e) {
        e.preventDefault();
        var $doc = $(this).closest('[data-eggl-gpsr-doc]');
        var frame = wp.media({
            title: cfg.i18n.title,
            button: { text: cfg.i18n.button },
            library: { type: cfg.mimeTypes },
            multiple: false
        });

        frame.on('select', function () {
            var file = frame.state().get('selection').first().toJSON();
            if (cfg.mimeTypes.indexOf(file.mime) === -1) {
                window.alert(cfg.i18n.invalid);
                return;
            }
            $doc.find('[data-eggl-gpsr-doc-input]').val(file.id);
            $doc.find('[data-eggl-gpsr-doc-name]').text(file.filename);
            $doc.find('[data-eggl-gpsr-doc-remove]').prop('hidden', false);
        });

        frame.open();
    });

    $panel.on('click', '[data-eggl-gpsr-doc-remove]', function (e) {
        e.preventDefault();
        var $doc = $(this).closest('[data-eggl-gpsr-doc]');
        $doc.find('[data-eggl-gpsr-doc-input]').val('');
        $doc.find('[data-eggl-gpsr-doc-name]').text('');
        $(this).prop('hidden', true);
    });
});
