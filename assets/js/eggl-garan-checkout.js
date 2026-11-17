/**
 * EU Guarantee Notice & GARAN Labels — block-checkout GARAN label injection.
 * Article 8(2) of the Consumer Rights Directive names the durability label
 * among the information that must be shown directly before the consumer
 * places the order. The classic checkout gets this server-side (see
 * EGGL_Frontend::render_checkout_garan_cart_item()); the block checkout
 * renders its cart items client-side (React), so this uses WooCommerce
 * Blocks' checkout extensibility APIs instead. window.egglGaranCheckout.labels
 * is pre-rendered server-side HTML (the same "nested" GARAN widget markup
 * used elsewhere), keyed by product id.
 */
(function (wp, wc, data) {
	'use strict';

	if (!wp || !wp.element || !wc || !wc.blocksCheckout) return;

	var labels = data && data.labels;
	if (!labels) return;

	var ITEM = 'eggl-garan-checkout-item';
	var SLOT = 'eggl-garan-checkout-slot';
	// Known WooCommerce Blocks thumbnail-container class names (order summary,
	// editable cart-items list, and the shared product-image component). These are
	// flex/grid children with a fixed width set by their layout — nesting the label
	// *inside* one clips/constrains it (increasing the configured badge size can't
	// grow past that fixed column). So this only uses the selector to find where the
	// image sits, then inserts the label as a new full-width row right after the
	// whole item row (in normal flow, free to size itself) instead of inside it.
	var IMAGE_SELECTOR = '.wc-block-components-order-summary-item__image, .wc-block-cart-item__image, .wc-block-components-product-image';

	function idOf(item) {
		if (!item) return 0;
		var id = item.id || item.parent_product_id || 0;
		return labels[id] ? id : 0;
	}

	function fill() {
		var rows = document.getElementsByClassName(ITEM);
		for (var i = 0; i < rows.length; i++) {
			var row = rows[i];
			var found = row.className.match(/eggl-garan-checkout-item--(\d+)/);
			var id = found ? found[1] : '';
			if (!id || !labels[id]) continue;

			var next = row.nextElementSibling;
			if (next && next.classList.contains(SLOT)) continue;

			var slot = document.createElement('div');
			slot.className = SLOT;
			slot.innerHTML = labels[id];

			if (row.querySelector(IMAGE_SELECTOR)) {
				slot.classList.add('eggl-garan-checkout-slot--under-image');
			}

			row.parentNode.insertBefore(slot, row.nextSibling);
		}
	}

	function watch() {
		fill();
		if (!window.MutationObserver) return;
		new window.MutationObserver(fill).observe(document.body, { childList: true, subtree: true });
	}

	// Primary path: newer WooCommerce Blocks expose a checkout filter that lets an
	// extension tag each cart-item row with a class — from there, plain DOM code
	// (above) finds the row and appends the pre-rendered label as its last child.
	if (wc.blocksCheckout.registerCheckoutFilters) {
		wc.blocksCheckout.registerCheckoutFilters('eggl-garan', {
			cartItemClass: function (value, extensions, args) {
				var id = idOf(args && args.cartItem);
				if (!id) return value;
				return (value ? value + ' ' : '') + ITEM + ' ' + ITEM + '--' + id;
			},
		});

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', watch);
		} else {
			watch();
		}
		return;
	}

	// Fallback path: older WooCommerce Blocks without registerCheckoutFilters, but
	// with the ExperimentalOrderMeta slot — shows every qualifying label together
	// in the order summary meta area instead of per cart-item row.
	var metaSlot = wc.blocksCheckout.ExperimentalOrderMeta;
	if (!metaSlot || !wp.plugins) return;

	var el = wp.element.createElement;
	var html = '';
	Object.keys(labels).forEach(function (id) {
		html += labels[id];
	});

	wp.plugins.registerPlugin('eggl-garan-checkout', {
		render: function () {
			return el(metaSlot, null, el('div', {
				className: 'eggl-garan-checkout-meta',
				dangerouslySetInnerHTML: { __html: html },
			}));
		},
		scope: 'woocommerce-checkout',
	});
})(window.wp, window.wc, window.egglGaranCheckout);
