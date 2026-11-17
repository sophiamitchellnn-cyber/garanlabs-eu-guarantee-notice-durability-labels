/**
 * GARAN Label block — years/brand/model fields with an instant client-side
 * preview, updating the same official SVG markup used on the front end (no
 * server round-trip). window.EGGL_GARAN_TEMPLATE is supplied by PHP
 * (EGGL_Frontend::enqueue_block_editor_assets()) via wp_add_inline_script, and
 * is the exact same template EGGL_Frontend::garan_svg_markup() renders,
 * with placeholder text ids so this can find and replace it — same technique
 * as assets/js/eggl-admin-product.js uses for the per-product editor preview.
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var __ = wp.i18n.__;

	function fillPreview(container, attributes) {
		if (!container || !window.EGGL_GARAN_TEMPLATE) return;

		if (!container.hasChildNodes()) {
			container.innerHTML = window.EGGL_GARAN_TEMPLATE;
			// Capture the design's own placeholders once, so clearing a field can restore them.
			var brandEl = container.querySelector('[data-eggl-svg="brand"]');
			var modelEl = container.querySelector('[data-eggl-svg="model"]');
			var yearsEl = container.querySelector('[data-eggl-svg="years"]');
			container._egglDefaults = {
				brand: brandEl ? brandEl.innerHTML : '',
				model: modelEl ? modelEl.innerHTML : '',
				years: yearsEl ? yearsEl.innerHTML : '',
			};
		}

		var defaults = container._egglDefaults || { brand: '', model: '', years: '' };
		var brandEl = container.querySelector('[data-eggl-svg="brand"]');
		var modelEl = container.querySelector('[data-eggl-svg="model"]');
		var yearsEl = container.querySelector('[data-eggl-svg="years"]');

		if (brandEl) {
			if (attributes.brand) brandEl.textContent = attributes.brand;
			else brandEl.innerHTML = defaults.brand;
		}
		if (modelEl) {
			if (attributes.model) modelEl.textContent = attributes.model;
			else modelEl.innerHTML = defaults.model;
		}
		if (yearsEl) {
			if (attributes.years) yearsEl.textContent = String(attributes.years);
			else yearsEl.innerHTML = defaults.years;
		}
	}

	wp.blocks.registerBlockType('eggl/garan', {
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var previewRef = useRef(null);
			var blockProps = wp.blockEditor.useBlockProps({ className: 'eggl-block-garan' });

			var useProductData = attributes.useProductData !== false;

			useEffect(function () {
				if (useProductData) return;
				fillPreview(previewRef.current, attributes);
			}, [useProductData, attributes.years, attributes.brand, attributes.model]);

			var toggle = el(ToggleControl, {
				label: __("Use this product's saved EU Guarantee data", 'garanlabs-eu-guarantee-notice-durability-labels'),
				checked: useProductData,
				onChange: function (value) {
					setAttributes({ useProductData: value });
				},
			});

			if (useProductData) {
				return el(
					'div',
					blockProps,
					toggle,
					el(
						'p',
						{ className: 'eggl-block-garan__note' },
						__(
							"Duration, brand, and model are pulled from this product's saved EU Guarantee data (Product data → EU Guarantee tab) when this block renders on that product. Only shows if the product qualifies. No preview here — check the front end after publishing, or turn this off to set your own values with a live preview.",
							'garanlabs-eu-guarantee-notice-durability-labels'
						)
					)
				);
			}

			return el(
				'div',
				blockProps,
				toggle,
				el(
					'div',
					{ className: 'eggl-block-garan__body' },
					el(
						'div',
						{ className: 'eggl-block-garan__fields' },
						el(TextControl, {
							label: __('Guarantee duration (years)', 'garanlabs-eu-guarantee-notice-durability-labels'),
							type: 'number',
							min: 0,
							value: attributes.years || '',
							onChange: function (value) {
								setAttributes({ years: parseInt(value, 10) || 0 });
							},
						}),
						el(TextControl, {
							label: __('Brand/Trademark', 'garanlabs-eu-guarantee-notice-durability-labels'),
							value: attributes.brand || '',
							onChange: function (value) {
								setAttributes({ brand: value });
							},
						}),
						el(TextControl, {
							label: __('Model identifier', 'garanlabs-eu-guarantee-notice-durability-labels'),
							value: attributes.model || '',
							onChange: function (value) {
								setAttributes({ model: value });
							},
						})
					),
					el('div', { className: 'eggl-block-garan__preview', ref: previewRef })
				)
			);
		},
		save: function () {
			return null;
		},
	});
})(window.wp);
