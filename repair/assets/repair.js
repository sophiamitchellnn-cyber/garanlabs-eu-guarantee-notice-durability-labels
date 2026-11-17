/* Right to repair claim form: shows the chosen photo file names in the drop area. */
( function () {
	'use strict';
	document.querySelectorAll( '.eggl-rr__dropzone input[type=file]' ).forEach( function ( input ) {
		var zone = input.closest( '.eggl-rr__dropzone' );
		var text = zone.querySelector( '[data-eggl-rr-files]' );
		input.addEventListener( 'change', function () {
			var names = Array.prototype.map.call( input.files, function ( f ) { return f.name; } );
			text.textContent = names.length ? names.join( ', ' ) : text.getAttribute( 'data-empty' );
			zone.classList.toggle( 'has-files', names.length > 0 );
		} );
	} );

	// "Choose an order": load the claim form as soon as an order is picked.
	document.querySelectorAll( '[data-eggl-rr-order-select]' ).forEach( function ( form ) {
		form.classList.add( 'is-js' );
		var select = form.querySelector( 'select' );
		select.addEventListener( 'change', function () {
			if ( select.value ) {
				form.classList.add( 'is-loading' );
				form.submit();
			}
		} );
	} );
} )();
