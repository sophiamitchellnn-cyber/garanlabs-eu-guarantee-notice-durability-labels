/**
 * EU Guarantee Notice & GARAN Labels — draws GARAN label PNGs for order emails
 * in the admin's browser, for servers without PHP's GD extension (see
 * EGGL_Garan_Raster). Each item's SVG is the exact storefront markup; the
 * bundled Inter fonts are embedded so the text renders the same everywhere.
 * Runs quietly in the background; failures just leave the email's text-line
 * fallback in place.
 */
(function (cfg) {
    'use strict';

    if (!cfg || !cfg.items || !cfg.items.length || !window.fetch || !window.FileReader) return;

    function toDataUrl(url) {
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { if (!res.ok) throw new Error('font'); return res.blob(); })
            .then(function (blob) {
                return new Promise(function (resolve, reject) {
                    var reader = new FileReader();
                    reader.onload = function () { resolve(reader.result); };
                    reader.onerror = reject;
                    reader.readAsDataURL(blob);
                });
            });
    }

    function fontStyle(fonts) {
        return '<style>' + Object.keys(fonts).map(function (family) {
            return '@font-face{font-family:"' + family + '";src:url(' + fonts[family] + ') format("truetype");}';
        }).join('') + '</style>';
    }

    function draw(svg, style) {
        // Fonts go first inside the <svg>, so its own CSS (font-family: Inter-Regular, ...) picks them up.
        var markup = svg.replace(/<svg\b([^>]*)>/, function (open) {
            return open.replace('<svg', '<svg width="' + cfg.width + '"') + style;
        });
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () {
                var canvas = document.createElement('canvas');
                canvas.width = cfg.width;
                canvas.height = Math.round(cfg.width * img.naturalHeight / img.naturalWidth) || Math.round(cfg.width * 1.05262);
                var ctx = canvas.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                resolve(canvas.toDataURL('image/png'));
            };
            img.onerror = reject;
            img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(markup);
        });
    }

    function upload(item, png) {
        var body = new URLSearchParams();
        body.append('action', cfg.action);
        body.append('_ajax_nonce', cfg.nonce);
        body.append('brand', item.brand);
        body.append('model', item.model);
        body.append('years', item.years);
        body.append('png', png);
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body });
    }

    function run() {
        var families = Object.keys(cfg.fonts);
        Promise.all(families.map(function (f) { return toDataUrl(cfg.fonts[f]); }))
            .then(function (urls) {
                var embedded = {};
                families.forEach(function (f, i) { embedded[f] = urls[i]; });
                var style = fontStyle(embedded);

                // One at a time: each PNG is a few hundred KB, no need to flood the server.
                return cfg.items.reduce(function (chain, item) {
                    return chain.then(function () {
                        return draw(item.svg, style)
                            .then(function (png) { return upload(item, png); })
                            .catch(function () { /* leave this one for next time */ });
                    });
                }, Promise.resolve());
            })
            .catch(function () { /* fonts unavailable: try again on the next visit */ });
    }

    // Wait until the page is idle so it never competes with the editor loading.
    if (window.requestIdleCallback) {
        window.requestIdleCallback(run, { timeout: 4000 });
    } else {
        window.setTimeout(run, 1500);
    }
})(window.egglGaranRaster);
