/* NeoWeaver — Admin menu Lucide icons
 * Szuka elementów <span data-lucide-menu="icon-name"> w tytułach submenu
 * i zastępuje je SVG z Lucide (stroke-width 0.75).
 */
( function () {
	'use strict';

	function renderMenuIcons() {
		if ( ! window.lucide ) {
			return;
		}

		document.querySelectorAll( '#adminmenu [data-lucide-menu]' ).forEach( function ( el ) {
			var name = el.getAttribute( 'data-lucide-menu' );
			if ( ! name ) { return; }

			// Zamień atrybut na data-lucide żeby lucide.createIcons() go przetworzyło
			el.setAttribute( 'data-lucide', name );
			el.removeAttribute( 'data-lucide-menu' );
			el.style.cssText = 'display:inline-block;vertical-align:middle;margin-right:5px;width:14px;height:14px;';
		} );

		lucide.createIcons( {
			attrs: {
				'stroke-width': '0.75',
				'width':        '14',
				'height':       '14',
			},
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', renderMenuIcons );
	} else {
		renderMenuIcons();
	}
}() );
