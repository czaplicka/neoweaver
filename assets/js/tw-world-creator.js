/**
 * tw-world-creator.js
 *
 * Multi-step World Creator wizard.
 * Config injected by PHP via wp_localize_script as twWorldCreatorConfig:
 *   { nonce, endpoint (unused — we use REST directly), nodesUrl }
 *
 * REST target: /wp-json/neoweaver/v1/world/create
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		const wrapper = document.getElementById( 'tw-creator-wrapper' );
		if ( ! wrapper ) return;

		const steps   = Array.from( wrapper.querySelectorAll( '.tw-step' ) );
		const status  = wrapper.querySelector( '.tw-world-status' );
		const config  = window.twWorldCreatorConfig || {};
		const restUrl = ( window.location.origin ) + '/wp-json/neoweaver/v1/world/create';

		let current = 0;

		// ── helpers ────────────────────────────────────────────────────────────

		function showStep( index ) {
			steps.forEach( ( s, i ) => {
				s.classList.toggle( 'active', i === index );
			} );
			current = index;
			wrapper.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		function setStatus( msg, isError ) {
			if ( ! status ) return;
			status.textContent  = msg;
			status.style.color  = isError ? '#ff4444' : 'var(--neon-green)';
		}

		// Validate current step before advancing:
		// – step 0 (name/description): check text inputs
		// – all other steps: check that a radio in this step is selected
		function validateStep( index ) {
			const step = steps[ index ];

			if ( index === 0 ) {
				const name = wrapper.querySelector( '#tw-world-name' );
				const desc = wrapper.querySelector( '#tw-world-description' );
				if ( ! name || ! name.value.trim() ) {
					name && name.focus();
					setStatus( 'ERROR: Node name is required.', true );
					return false;
				}
				if ( ! desc || ! desc.value.trim() ) {
					desc && desc.focus();
					setStatus( 'ERROR: Description is required.', true );
					return false;
				}
				return true;
			}

			// For choice steps — at least one radio must be checked
			const radios = step.querySelectorAll( 'input[type="radio"]' );
			if ( radios.length === 0 ) return true; // last step (customize) — always ok

			const checked = step.querySelector( 'input[type="radio"]:checked' );
			if ( ! checked ) {
				setStatus( 'ERROR: Select an option to continue.', true );
				return false;
			}
			return true;
		}

		// ── navigation ─────────────────────────────────────────────────────────

		// NEXT on step 1 (first step has its own button id)
		const firstNext = wrapper.querySelector( '#tw-step1-next' );
		if ( firstNext ) {
			firstNext.addEventListener( 'click', function () {
				if ( validateStep( 0 ) ) {
					setStatus( '' );
					showStep( 1 );
				}
			} );
		}

		// Generic NEXT buttons (class tw-btn-next)
		wrapper.addEventListener( 'click', function ( e ) {
			if ( e.target.classList.contains( 'tw-btn-next' ) ) {
				if ( validateStep( current ) ) {
					setStatus( '' );
					showStep( current + 1 );
				}
			}
			if ( e.target.classList.contains( 'tw-btn-prev' ) ) {
				setStatus( '' );
				showStep( Math.max( 0, current - 1 ) );
			}
		} );

		// ── submit ─────────────────────────────────────────────────────────────

		const submitBtn = wrapper.querySelector( '#tw-world-submit' );
		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', function () {
				submit();
			} );
		}

		function collectFormData() {
			const data = {};

			// text fields
			const name = wrapper.querySelector( '#tw-world-name' );
			const desc = wrapper.querySelector( '#tw-world-description' );
			const cust = wrapper.querySelector( '[name="customize"]' );

			data.name        = name ? name.value.trim() : '';
			data.description = desc ? desc.value.trim() : '';
			data.customize   = cust ? cust.value.trim() : '';
			data.nonce       = config.nonce || '';

			// radio fields — each step contributes its checked value
			const radioNames = [ 'size', 'wealth', 'difficulty', 'magic', 'gods', 'technology', 'relations', 'moral' ];
			radioNames.forEach( function ( field ) {
				const checked = wrapper.querySelector( 'input[name="' + field + '"]:checked' );
				data[ field ] = checked ? parseInt( checked.value, 10 ) : null;
			} );

			return data;
		}

		function submit() {
			const data = collectFormData();

			// Validate required radio fields
			const radioNames = [ 'size', 'wealth', 'difficulty', 'magic', 'gods', 'technology', 'relations', 'moral' ];
			for ( const field of radioNames ) {
				if ( ! data[ field ] ) {
					setStatus( 'ERROR: Missing selection for field: ' + field, true );
					return;
				}
			}
			if ( ! data.name ) {
				setStatus( 'ERROR: Node name is required.', true );
				return;
			}

			submitBtn.disabled   = true;
			submitBtn.textContent = 'DEPLOYING…';
			setStatus( '// Establishing uplink to Supabase…', false );

			fetch( restUrl, {
				method:      'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   config.nonce || '',
				},
				body: JSON.stringify( data ),
				credentials: 'same-origin',
			} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( json.success ) {
					setStatus( '// NODE DEPLOYED: ' + ( json.data.worldid || '' ), false );
					setTimeout( function () {
						const redirect = config.nodesUrl || '/';
						window.location.href = redirect;
					}, 1800 );
				} else {
					const msg = ( json.data && json.data.message ) || json.message || 'Unknown error';
					setStatus( 'ERROR: ' + msg, true );
					submitBtn.disabled    = false;
					submitBtn.textContent = 'DEPLOY NODE →';
				}
			} )
			.catch( function ( err ) {
				setStatus( 'ERROR: Network failure — ' + err.message, true );
				submitBtn.disabled    = false;
				submitBtn.textContent = 'DEPLOY NODE →';
			} );
		}

	} );
} )();
