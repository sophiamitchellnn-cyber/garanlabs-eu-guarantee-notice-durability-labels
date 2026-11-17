/* Vendor GARAN label upload page: opens the WP Media Library modal to pick/upload one image,
 * and mirrors its id/thumbnail into the hidden field + preview the form submits. */
( function( $ ) {
	'use strict';

	$( function() {
		var $button = $( '#eggl_vendor_image_button' );
		var $clear = $( '#eggl_vendor_image_clear' );
		var $input = $( '#eggl_vendor_image_id' );
		var $preview = $( '#eggl_vendor_image_preview' );
		if ( ! $button.length || typeof wp === 'undefined' || ! wp.media ) return;

		var frame = null;

		function showPreview( url ) {
			$preview.html( '<img src="' + url + '" alt="" style="max-width:160px;max-height:160px;display:block;margin-bottom:8px;">' );
			$clear.prop( 'hidden', false );
		}

		$button.on( 'click', function( e ) {
			e.preventDefault();

			if ( ! frame ) {
				frame = wp.media( {
					title: ( window.egglVendorUpload && egglVendorUpload.chooseTitle ) || 'Select image',
					button: { text: ( window.egglVendorUpload && egglVendorUpload.chooseButton ) || 'Use this image' },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function() {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					$input.val( attachment.id );
					showPreview( attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url );
				} );
			}

			frame.open();
		} );

		$clear.on( 'click', function( e ) {
			e.preventDefault();
			$input.val( '' );
			$preview.empty();
			$clear.prop( 'hidden', true );
		} );
	} );
}( jQuery ) );
