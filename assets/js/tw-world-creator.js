/**
 * tw-world-creator.js
 *
 * Multi-step World Creator wizard.
 * Config injected by PHP via wp_localize_script as twWorldCreatorConfig:
 *   { nonce, nodesUrl, uploadsUrl }
 *
 * Sounds (relative URLs built from uploadsUrl):
 *   tuning.mp3      — plays on every NEXT / BACK step change
 *   create-world.mp3 — plays once when node is successfully deployed
 *
 * REST target: /wp-json/neoweaver/v1/world/create
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		const wrapper = document.getElementById( 'tw-creator-wrapper' );
		if ( ! wrapper ) return;

		const steps    = Array.from( wrapper.querySelectorAll( '.tw-step' ) );
		const status   = wrapper.querySelector( '.tw-world-status' );
		const config   = window.twWorldCreatorConfig || {};
		const restUrl  = window.location.origin + '/wp-json/neoweaver/v1/world/create';
		const uploads  = ( config.uploadsUrl || window.location.origin + '/wp-content/uploads' ).replace( /\/$/, '' );

		let current = 0;

		// ── audio ──────────────────────────────────────────────────────────────
		// Pre-load both sounds once so there's no delay on first play.
		// We intentionally avoid adding them to the DOM.

		const sndTuning = new Audio( uploads + '/tuning.mp3' );
		const sndDeploy = new Audio( uploads + '/create-world.mp3' );
		sndTuning.preload = 'auto';
		sndDeploy.preload = 'auto';

		function playSound( audio ) {
			try {
				audio.currentTime = 0;
				audio.play().catch( function () {} ); // silently ignore autoplay policy
			} catch ( e ) {}
		}

		// ── spinner overlay ────────────────────────────────────────────────────

		const spinner = document.createElement( 'div' );
		spinner.id = 'tw-node-spinner';
		spinner.innerHTML =
			'<div class="tw-spinner-inner">' +
				'<div class="tw-spinner-ring"></div>' +
				'<div class="tw-spinner-ring tw-spinner-ring--2"></div>' +
				'<p class="tw-spinner-text">// DEPLOYING NODE…</p>' +
				'<p class="tw-spinner-sub">Uplink established. Writing to the grid.</p>' +
			'</div>';
		document.body.appendChild( spinner );

		function showSpinner() { spinner.classList.add( 'active' ); }
		function hideSpinner() { spinner.classList.remove( 'active' ); }

		// ── helpers ────────────────────────────────────────────────────────────

		function showStep( index ) {
			steps.forEach( ( s, i ) => s.classList.toggle( 'active', i === index ) );
			current = index;
			wrapper.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		function setStatus( msg, isError ) {
			if ( ! status ) return;
			status.textContent = msg;
			status.style.color = isError ? '#ff4444' : 'var(--neon-green)';
		}

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

			const radios  = step.querySelectorAll( 'input[type="radio"]' );
			if ( radios.length === 0 ) return true;

			const checked = step.querySelector( 'input[type="radio"]:checked' );
			if ( ! checked ) {
				setStatus( 'ERROR: Select an option to continue.', true );
				return false;
			}
			return true;
		}

		// ── navigation ─────────────────────────────────────────────────────────

		const firstNext = wrapper.querySelector( '#tw-step1-next' );
		if ( firstNext ) {
			firstNext.addEventListener( 'click', function () {
				if ( validateStep( 0 ) ) {
					setStatus( '' );
					playSound( sndTuning );
					showStep( 1 );
				}
			} );
		}

		wrapper.addEventListener( 'click', function ( e ) {
			if ( e.target.classList.contains( 'tw-btn-next' ) ) {
				if ( validateStep( current ) ) {
					setStatus( '' );
					playSound( sndTuning );
					showStep( current + 1 );
				}
			}
			if ( e.target.classList.contains( 'tw-btn-prev' ) ) {
				setStatus( '' );
				playSound( sndTuning );
				showStep( Math.max( 0, current - 1 ) );
			}
		} );

		// ── submit ─────────────────────────────────────────────────────────────

		const submitBtn = wrapper.querySelector( '#tw-world-submit' );
		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', function () { doSubmit(); } );
		}

		function collectFormData() {
			const data = {};
			const name = wrapper.querySelector( '#tw-world-name' );
			const desc = wrapper.querySelector( '#tw-world-description' );
			const cust = wrapper.querySelector( '[name="customize"]' );

			data.name        = name ? name.value.trim() : '';
			data.description = desc ? desc.value.trim() : '';
			data.customize   = cust ? cust.value.trim() : '';
			data.nonce       = config.nonce || '';

			[ 'size', 'wealth', 'difficulty', 'magic', 'gods', 'technology', 'relations', 'moral' ]
				.forEach( function ( field ) {
					const checked = wrapper.querySelector( 'input[name="' + field + '"]:checked' );
					data[ field ] = checked ? parseInt( checked.value, 10 ) : null;
				} );

			return data;
		}

		function doSubmit() {
			const data = collectFormData();

			for ( const field of [ 'size', 'wealth', 'difficulty', 'magic', 'gods', 'technology', 'relations', 'moral' ] ) {
				if ( ! data[ field ] ) {
					setStatus( 'ERROR: Missing selection for field: ' + field, true );
					return;
				}
			}
			if ( ! data.name ) {
				setStatus( 'ERROR: Node name is required.', true );
				return;
			}

			submitBtn.disabled    = true;
			submitBtn.textContent = 'DEPLOYING…';
			showSpinner();

			// Minimum 3 seconds of spinner before we act on the response
			const spinnerStart = Date.now();

			fetch( restUrl, {
				method:      'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   config.nonce || '',
				},
				body:        JSON.stringify( data ),
				credentials: 'same-origin',
			} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				const elapsed   = Date.now() - spinnerStart;
				const remaining = Math.max( 0, 3000 - elapsed );

				setTimeout( function () {
					hideSpinner();
					if ( json.success ) {
						playSound( sndDeploy );
						setStatus( '// NODE DEPLOYED: ' + ( json.data.worldid || '' ), false );
						setTimeout( function () {
							window.location.href = config.nodesUrl || '/';
						}, 2000 );
					} else {
						const msg = ( json.data && json.data.message ) || json.message || 'Unknown error';
						setStatus( 'ERROR: ' + msg, true );
						submitBtn.disabled    = false;
						submitBtn.textContent = 'DEPLOY NODE →';
					}
				}, remaining );
			} )
			.catch( function ( err ) {
				hideSpinner();
				setStatus( 'ERROR: Network failure — ' + err.message, true );
				submitBtn.disabled    = false;
				submitBtn.textContent = 'DEPLOY NODE →';
			} );
		}

	} );
} )();
