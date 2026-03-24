/**
 * tw-world-creator.js
 * Progress bar + summary screen + sounds + spinner.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		const wrapper = document.getElementById( 'tw-creator-wrapper' );
		if ( ! wrapper ) return;

		const steps       = Array.from( wrapper.querySelectorAll( '.tw-step' ) );
		const totalSteps  = parseInt( wrapper.dataset.totalSteps, 10 ) || steps.length;
		const status      = wrapper.querySelector( '.tw-world-status' );
		const config      = window.twWorldCreatorConfig || {};
		const restUrl     = window.location.origin + '/wp-json/neoweaver/v1/world/create';
		const uploads     = ( config.uploadsUrl || window.location.origin + '/wp-content/uploads' ).replace( /\/$/, '' );

		// Progress bar elements
		const progressFill    = document.getElementById( 'tw-progress-fill' );
		const progressCurrent = document.getElementById( 'tw-step-current' );
		const progressPhase   = document.getElementById( 'tw-progress-phase' );

		let current = 0;

		// ── audio ──────────────────────────────────────────────────────────────
		const sndTuning = new Audio( uploads + '/tuning.mp3' );
		const sndDeploy = new Audio( uploads + '/create-world.mp3' );
		sndTuning.preload = 'auto';
		sndDeploy.preload = 'auto';

		function playSound( audio ) {
			try { audio.currentTime = 0; audio.play().catch( function(){} ); } catch(e){}
		}

		// ── spinner ────────────────────────────────────────────────────────────
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

		// ── progress bar ───────────────────────────────────────────────────────
		function updateProgress( index ) {
			const stepNum  = index + 1;
			const pct      = Math.round( ( stepNum / totalSteps ) * 100 );
			const stepEl   = steps[ index ];
			const phase    = ( stepEl && stepEl.dataset.phase ) || '';

			if ( progressFill )    progressFill.style.width    = pct + '%';
			if ( progressCurrent ) progressCurrent.textContent = stepNum;
			if ( progressPhase )   progressPhase.textContent   = phase;

			// Tick highlights
			wrapper.querySelectorAll( '.tw-progress-tick' ).forEach( function( tick ) {
				const t = parseInt( tick.dataset.tick, 10 );
				tick.classList.toggle( 'active',   t <= stepNum );
				tick.classList.toggle( 'current',  t === stepNum );
			} );
		}

		// ── summary screen ─────────────────────────────────────────────────────
		// Build a map of field → { label, options[] } from choice steps
		const fieldMap = {};
		steps.forEach( function( step ) {
			const field = step.dataset.field;
			if ( ! field ) return;
			const cards = Array.from( step.querySelectorAll( '.tw-card-label' ) );
			const opts  = cards.map( function( card ) {
				return card.querySelector( 'strong' ) ? card.querySelector( 'strong' ).textContent : '';
			} );
			fieldMap[ field ] = opts;
		} );

		function populateSummary() {
			// Text fields
			const nameEl = wrapper.querySelector( '#tw-world-name' );
			const descEl = wrapper.querySelector( '#tw-world-description' );
			const custEl = wrapper.querySelector( '[name="customize"]' );

			setSummaryVal( 'name',      nameEl ? nameEl.value.trim() : '' );
			setSummaryVal( 'desc',      descEl ? descEl.value.trim() : '' );
			setSummaryVal( 'customize', custEl && custEl.value.trim() ? custEl.value.trim() : '—' );

			// Radio fields
			Object.keys( fieldMap ).forEach( function( field ) {
				const checked = wrapper.querySelector( 'input[name="' + field + '"]:checked' );
				const idx     = checked ? parseInt( checked.value, 10 ) - 1 : -1;
				const label   = idx >= 0 && fieldMap[ field ][ idx ] ? fieldMap[ field ][ idx ] : '—';
				setSummaryVal( field, label );
			} );
		}

		function setSummaryVal( field, val ) {
			const el = document.getElementById( 'tw-summary-' + field );
			if ( el ) el.textContent = val || '—';
		}

		// ── navigation ─────────────────────────────────────────────────────────
		function showStep( index ) {
			steps.forEach( ( s, i ) => s.classList.toggle( 'active', i === index ) );
			current = index;
			updateProgress( index );
			if ( steps[ index ] && steps[ index ].classList.contains( 'tw-step--summary' ) ) {
				populateSummary();
			}
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
				if ( ! name || ! name.value.trim() ) { name && name.focus(); setStatus( 'ERROR: Node name is required.', true ); return false; }
				if ( ! desc || ! desc.value.trim() ) { desc && desc.focus(); setStatus( 'ERROR: Description is required.', true ); return false; }
				return true;
			}
			const radios  = step.querySelectorAll( 'input[type="radio"]' );
			if ( radios.length === 0 ) return true;
			const checked = step.querySelector( 'input[type="radio"]:checked' );
			if ( ! checked ) { setStatus( 'ERROR: Select an option to continue.', true ); return false; }
			return true;
		}

		// Step 1 NEXT
		const firstNext = wrapper.querySelector( '#tw-step1-next' );
		if ( firstNext ) {
			firstNext.addEventListener( 'click', function () {
				if ( validateStep( 0 ) ) { setStatus( '' ); playSound( sndTuning ); showStep( 1 ); }
			} );
		}

		// Generic NEXT / BACK
		wrapper.addEventListener( 'click', function ( e ) {
			const btn = e.target.closest( 'button' );
			if ( ! btn ) return;

			if ( btn.classList.contains( 'tw-btn-next' ) ) {
				if ( validateStep( current ) ) { setStatus( '' ); playSound( sndTuning ); showStep( current + 1 ); }
			}
			if ( btn.classList.contains( 'tw-btn-prev' ) ) {
				setStatus( '' ); playSound( sndTuning ); showStep( Math.max( 0, current - 1 ) );
			}

			// [ EDIT ] buttons on summary screen
			if ( btn.classList.contains( 'tw-summary-edit' ) ) {
				const goto = parseInt( btn.dataset.goto, 10 );
				if ( ! isNaN( goto ) ) {
					// goto is 1-based step number, find matching step index
					const idx = steps.findIndex( s => parseInt( s.dataset.step, 10 ) === goto );
					if ( idx >= 0 ) { playSound( sndTuning ); showStep( idx ); }
				}
			}
		} );

		// ── submit ─────────────────────────────────────────────────────────────
		const submitBtn = wrapper.querySelector( '#tw-world-submit' );
		if ( submitBtn ) submitBtn.addEventListener( 'click', doSubmit );

		function collectFormData() {
			const data = {};
			const name = wrapper.querySelector( '#tw-world-name' );
			const desc = wrapper.querySelector( '#tw-world-description' );
			const cust = wrapper.querySelector( '[name="customize"]' );
			data.name        = name ? name.value.trim() : '';
			data.description = desc ? desc.value.trim() : '';
			data.customize   = cust ? cust.value.trim() : '';
			data.nonce       = config.nonce || '';
			[ 'size','wealth','difficulty','magic','gods','technology','relations','moral' ].forEach( function( f ) {
				const c = wrapper.querySelector( 'input[name="' + f + '"]:checked' );
				data[ f ] = c ? parseInt( c.value, 10 ) : null;
			} );
			return data;
		}

		function doSubmit() {
			const data = collectFormData();
			for ( const f of [ 'size','wealth','difficulty','magic','gods','technology','relations','moral' ] ) {
				if ( ! data[ f ] ) { setStatus( 'ERROR: Missing selection for: ' + f, true ); return; }
			}
			if ( ! data.name ) { setStatus( 'ERROR: Node name is required.', true ); return; }

			submitBtn.disabled    = true;
			submitBtn.textContent = 'DEPLOYING…';
			showSpinner();
			const t0 = Date.now();

			fetch( restUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '' },
				body: JSON.stringify( data ),
				credentials: 'same-origin',
			} )
			.then( r => r.json() )
			.then( function( json ) {
				const wait = Math.max( 0, 3000 - ( Date.now() - t0 ) );
				setTimeout( function() {
					hideSpinner();
					if ( json.success ) {
						playSound( sndDeploy );
						setStatus( '// NODE DEPLOYED: ' + ( json.data.worldid || '' ), false );
						setTimeout( () => { window.location.href = config.nodesUrl || '/'; }, 2000 );
					} else {
						const msg = ( json.data && json.data.message ) || json.message || 'Unknown error';
						setStatus( 'ERROR: ' + msg, true );
						submitBtn.disabled    = false;
						submitBtn.textContent = '▶ DEPLOY NODE';
					}
				}, wait );
			} )
			.catch( function( err ) {
				hideSpinner();
				setStatus( 'ERROR: Network failure — ' + err.message, true );
				submitBtn.disabled    = false;
				submitBtn.textContent = '▶ DEPLOY NODE';
			} );
		}

		// Init progress
		updateProgress( 0 );

	} );
} )();
