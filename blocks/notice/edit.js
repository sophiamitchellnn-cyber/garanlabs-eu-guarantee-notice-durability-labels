/**
 * EU Guarantee Notice block — no attributes, so the editor preview is just a
 * server-side render of the same shortcode/automatic-placement output.
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var ServerSideRender = wp.serverSideRender;
	var __ = wp.i18n.__;

	wp.blocks.registerBlockType('eggl/notice', {
		edit: function () {
			var blockProps = wp.blockEditor.useBlockProps({ className: 'eggl-block-notice' });
			return el(
				'div',
				blockProps,
				ServerSideRender
					? el(ServerSideRender, { block: 'eggl/notice' })
					: el('p', {}, __('EU Guarantee Notice (rendered on the front end)', 'garanlabs-eu-guarantee-notice-durability-labels'))
			);
		},
		save: function () {
			return null;
		},
	});
})(window.wp);
