/* global jQuery, neoweaverAdmin */
( function ( $ ) {
	'use strict';

	$( document ).ready( function () {
		if ( window.neoweaverAdmin && window.neoweaverAdmin.debug ) {
			console.log( 'NeoWeaver Admin loaded.' );
		}
	} );
} )( jQuery );
