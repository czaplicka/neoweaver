/**
 * tw-character-creator.js
 *
 * Drives the 7-step Field Agent creation wizard rendered by
 * [tale_weaver_character_creator] shortcode.
 *
 * Responsibilities:
 *  - Multi-step navigation with progress bar + phase label.
 *  - Dynamic fetch of race / class cards from Supabase (Steps 2 & 3).
 *  - Dynamic fetch of user's Nodes for Node Binding (Step 5).
 *  - Attribute stepper logic (pool of 12 pts, min 1 / max 5 per attr).
 *  - Avatar drag-and-drop + preview (Step 6).
 *  - Summary population (Step 7).
 *  - JSON POST to /wp-json/neoweaver/v1/character/create with spinner.
 *  - Redirect to /agents/ on success.
 *
 * Config (injected via wp_localize_script as twCharCreatorConfig):
 *   nonce      — tw_character_nonce for the body JSON
 *   restNonce  — wp_rest nonce for X-WP-Nonce header
 *   restUrl    — full URL to character/create endpoint
 *   agentsUrl  — redirect target after success
 *   supabaseUrl — Supabase project URL
 *   supabaseKey — anon key for reading public race/class/world data
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		const wrapper = document.getElementById( 'tw-char-creator-wrapper' );
		if ( ! wrapper ) return;

		const config       = window.twCharCreatorConfig || {};
		const sbUrl        = ( config.supabaseUrl || '' ).replace( /\/$/, '' ) + '/rest/v1/';
		const sbKey        = config.supabaseKey || '';
		const restUrl      = config.restUrl || '/wp-json/neoweaver/v1/character/create';
		const agentsUrl    = config.agentsUrl || '/agents/';

		const steps          = Array.from( wrapper.querySelectorAll( '.tw-step' ) );
		const totalSteps     = parseInt( wrapper.dataset.totalSteps, 10 ) || steps.length;
		const progressFill   = document.getElementById( 'tw-char-progress-fill' );
		const progressCurrent= document.getElementById( 'tw-char-step-current' );
		const progressPhase  = document.getElementById( 'tw-char-progress-phase' );
		const statusEl       = wrapper.querySelector( '.tw-char-status' );

		let current     = 0;
		let avatarFile  = null; // File object from input

		// Form state — collected progressively as the user moves forward.
		const formState = {
			character_name : '',
			pronouns       : '',
			backstory      : '',
			race           : null,   // { id, name }
			class          : null,   // { id, name }
			attr_body      : 1,
			attr_reflex    : 1,
			attr_mind      : 1,
			attr_spirit    : 1,
			node_id        : null,   // { id, name }
		};

		// Attribute pool constants (mirror of shortcode PHP).
		const ATTR_POOL = parseInt( ( document.getElementById( 'tw-attr-pool' ) || {} ).value || '12', 10 );
		const ATTR_MIN  = 1;
		const ATTR_MAX  = 5;
		const ATTR_KEYS = [ 'body', 'reflex', 'mind', 'spirit' ];

		// ── Spinner ──────────────────────────────────────────────────────────
		const spinner = document.createElement( 'div' );
		spinner.id = 'tw-char-spinner';
		spinner.innerHTML =
			'<div class="tw-spinner-inner">' +
				'<div class="tw-spinner-ring"></div>' +
				'<div class="tw-spinner-ring tw-spinner-ring--2"></div>' +
				'<p class="tw-spinner-text">// SYNCHRONIZING AGENT…</p>' +
				'<p class="tw-spinner-sub">Writing to the NeoWeave grid.</p>' +
			'</div>';
		document.body.appendChild( spinner );
		const showSpinner = () => spinner.classList.add( 'active' );
		const hideSpinner = () => spinner.classList.remove( 'active' );

		// ── Status helper ─────────────────────────────────────────────────────
		function setStatus( msg, isError ) {
			if ( ! statusEl ) return;
			statusEl.textContent = msg;
			statusEl.style.color = isError ? '#ff4444' : 'var(--neon-green)';
		}

		// ── Progress bar ──────────────────────────────────────────────────────
		function updateProgress( idx ) {
			const num   = idx + 1;
			const pct   = Math.round( ( num / totalSteps ) * 100 );
			const phase = ( steps[ idx ] && steps[ idx ].dataset.phase ) || '';

			if ( progressFill )    progressFill.style.width    = pct + '%';
			if ( progressCurrent ) progressCurrent.textContent  = num;
			if ( progressPhase )   progressPhase.textContent    = phase;

			wrapper.querySelectorAll( '.tw-progress-tick' ).forEach( function ( tick ) {
				const t = parseInt( tick.dataset.tick, 10 );
				tick.classList.toggle( 'active',  t <= num );
				tick.classList.toggle( 'current', t === num );
			} );
		}

		// ── Show a specific step ──────────────────────────────────────────────
		function showStep( idx ) {
			idx = Math.max( 0, Math.min( steps.length - 1, idx ) );
			steps.forEach( ( s, i ) => s.classList.toggle( 'active', i === idx ) );
			current = idx;
			updateProgress( idx );

			if ( steps[ idx ] && steps[ idx ].classList.contains( 'tw-step--summary' ) ) {
				populateSummary();
			}

			// Lazy-load dynamic grids when entering their step for the first time.
			const phase = steps[ idx ] ? steps[ idx ].dataset.phase : '';
			if ( phase === 'RACE PROTOCOL'   && ! gridsLoaded.race   ) loadRaces();
			if ( phase === 'CLASS MATRIX'    && ! gridsLoaded.class  ) loadClasses();
			if ( phase === 'NODE BINDING'    && ! gridsLoaded.nodes  ) loadNodes();

			wrapper.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		// ── Validation ────────────────────────────────────────────────────────
		function validateStep( idx ) {
			const step = steps[ idx ];
			if ( ! step ) return true;

			if ( idx === 0 ) {
				// Step 1 — identity
				const nameInput = wrapper.querySelector( '#tw-char-name' );
				if ( ! nameInput || ! nameInput.value.trim() ) {
					nameInput && nameInput.focus();
					setStatus( 'ERROR: Agent designation is required.', true );
					return false;
				}
				// Persist values so summary can read them.
				formState.character_name = nameInput.value.trim();
				formState.pronouns       = ( wrapper.querySelector( '#tw-char-pronouns' ) || {} ).value || '';
				formState.backstory      = ( wrapper.querySelector( '#tw-char-backstory' ) || {} ).value || '';
				return true;
			}

			if ( step.dataset.phase === 'RACE PROTOCOL' ) {
				if ( ! formState.race ) {
					setStatus( 'ERROR: Select a race to continue.', true );
					return false;
				}
				return true;
			}

			if ( step.dataset.phase === 'CLASS MATRIX' ) {
				if ( ! formState.class ) {
					setStatus( 'ERROR: Select a class to continue.', true );
					return false;
				}
				return true;
			}

			if ( step.dataset.phase === 'BIOMETRIC CALIBRATION' ) {
				const used = ATTR_KEYS.reduce( ( sum, k ) => sum + ( formState[ 'attr_' + k ] || ATTR_MIN ), 0 );
				if ( used !== ATTR_POOL ) {
					setStatus( 'ERROR: Distribute all ' + ATTR_POOL + ' attribute points (' + used + '/' + ATTR_POOL + ' used).', true );
					return false;
				}
				return true;
			}

			if ( step.dataset.phase === 'NODE BINDING' ) {
				if ( ! formState.node_id ) {
					setStatus( 'ERROR: Bind the agent to a Node before continuing.', true );
					return false;
				}
				return true;
			}

			// Avatar and summary steps are always valid.
			return true;
		}

		// ── Dynamic grid state ────────────────────────────────────────────────
		const gridsLoaded = { race: false, class: false, nodes: false };

		// Build a Supabase GET URL with anon key headers as query params.
		// We inject the key as a query arg because fetch() headers alone aren't
		// enough when CORS is the concern, and the anon key is public by design.
		function sbGet( table, params ) {
			const url = new URL( sbUrl + table );
			Object.entries( params || {} ).forEach( ( [ k, v ] ) => url.searchParams.set( k, v ) );
			return fetch( url.toString(), {
				headers: {
					'apikey'        : sbKey,
					'Authorization' : 'Bearer ' + sbKey,
				},
			} ).then( r => r.json() );
		}

		// Render a single selection card inside a dynamic grid.
		// `onSelect( id, name )` is called when the card is clicked.
		function makeCard( id, name, desc, emoji, selectedId, onSelect ) {
			const div  = document.createElement( 'div' );
			div.className = 'tw-dyn-card' + ( selectedId === id ? ' selected' : '' );
			div.dataset.id   = id;
			div.innerHTML =
				'<span class="tw-dyn-icon">' + ( emoji || '◈' ) + '</span>' +
				'<strong>' + esc( name ) + '</strong>' +
				( desc ? '<span>' + esc( desc ) + '</span>' : '' );
			div.addEventListener( 'click', function () {
				div.closest( '.tw-dynamic-grid' ).querySelectorAll( '.tw-dyn-card' ).forEach( c => c.classList.remove( 'selected' ) );
				div.classList.add( 'selected' );
				onSelect( id, name );
			} );
			return div;
		}

		// Minimal HTML escaping.
		function esc( str ) {
			return String( str || '' )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' );
		}

		// ── Step 2: Races ─────────────────────────────────────────────────────
		function loadRaces() {
			gridsLoaded.race = true;
			const grid = document.getElementById( 'tw-race-grid' );
			if ( ! grid || ! sbUrl || ! sbKey ) return;

			sbGet( 'cyber_races', { select: 'id,name,description,icon', order: 'name.asc' } )
				.then( function ( rows ) {
					grid.innerHTML = '';
					if ( ! rows || ! rows.length ) {
						grid.innerHTML = '<p class="tw-error-msg">No race data available.</p>';
						return;
					}
					rows.forEach( function ( row ) {
						grid.appendChild( makeCard(
							row.id, row.name, row.description, row.icon || '👤',
							formState.race ? formState.race.id : null,
							function ( id, name ) { formState.race = { id, name }; setStatus( '', false ); }
						) );
					} );
				} )
				.catch( function () {
					grid.innerHTML = '<p class="tw-error-msg">Failed to load races.</p>';
				} );
		}

		// ── Step 3: Classes ───────────────────────────────────────────────────
		function loadClasses() {
			gridsLoaded.class = true;
			const grid = document.getElementById( 'tw-class-grid' );
			if ( ! grid || ! sbUrl || ! sbKey ) return;

			sbGet( 'cyber_classes', { select: 'id,name,description,icon', order: 'name.asc' } )
				.then( function ( rows ) {
					grid.innerHTML = '';
					if ( ! rows || ! rows.length ) {
						grid.innerHTML = '<p class="tw-error-msg">No class data available.</p>';
						return;
					}
					rows.forEach( function ( row ) {
						grid.appendChild( makeCard(
							row.id, row.name, row.description, row.icon || '⚡',
							formState.class ? formState.class.id : null,
							function ( id, name ) { formState.class = { id, name }; setStatus( '', false ); }
						) );
					} );
				} )
				.catch( function () {
					grid.innerHTML = '<p class="tw-error-msg">Failed to load classes.</p>';
				} );
		}

		// ── Step 5: Nodes ─────────────────────────────────────────────────────
		function loadNodes() {
			gridsLoaded.nodes = true;
			const grid = document.getElementById( 'tw-node-grid' );
			if ( ! grid || ! sbUrl || ! sbKey ) return;

			// We know the user's WP user ID via the nonce cookie session —
			// Supabase RLS will filter to their worlds automatically.
			// Fallback: fetch all worlds ordered by name (RLS on cyber_worlds
			// filters by wp_user_id on the Supabase side).
			sbGet( 'cyber_worlds', { select: 'id,name,description,difficulty,entropy', order: 'name.asc' } )
				.then( function ( rows ) {
					grid.innerHTML = '';
					if ( ! rows || ! rows.length ) {
						grid.innerHTML = '<p class="tw-error-msg">No Nodes found. <a href="/create-world/" class="tw-link">Deploy one first →</a></p>';
						return;
					}
					rows.forEach( function ( row ) {
						// Skip hard-reset nodes (Entropy >= 100).
						if ( parseInt( row.entropy, 10 ) >= 100 ) return;

						const entropy  = parseInt( row.entropy, 10 ) || 0;
						const diffLabel = [ '', 'Coherent', 'Stable', 'Unstable', 'Critical', 'Catastrophic' ][ parseInt( row.difficulty, 10 ) ] || '—';
						const desc = 'Diff: ' + diffLabel + ' · Entropy: ' + entropy + '%';

						grid.appendChild( makeCard(
							row.id, row.name, row.description || desc, '🌐',
							formState.node_id ? formState.node_id.id : null,
							function ( id, name ) { formState.node_id = { id, name }; setStatus( '', false ); }
						) );
					} );
					if ( ! grid.querySelector( '.tw-dyn-card' ) ) {
						grid.innerHTML = '<p class="tw-error-msg">No playable Nodes found. <a href="/create-world/" class="tw-link">Deploy one first →</a></p>';
					}
				} )
				.catch( function () {
					grid.innerHTML = '<p class="tw-error-msg">Failed to load Nodes.</p>';
				} );
		}

		// ── Step 4: Attribute stepper ─────────────────────────────────────────
		( function initAttrSteppers() {
			// Initialise formState from the rendered input values.
			ATTR_KEYS.forEach( function ( k ) {
				const inp = document.getElementById( 'tw-attr-' + k );
				formState[ 'attr_' + k ] = inp ? parseInt( inp.value, 10 ) : ATTR_MIN;
			} );

			function usedPoints() {
				return ATTR_KEYS.reduce( ( s, k ) => s + ( formState[ 'attr_' + k ] || ATTR_MIN ), 0 );
			}

			function updateRemainingLabel() {
				const el = document.getElementById( 'tw-attr-remaining' );
				if ( el ) el.textContent = ATTR_POOL - usedPoints();
			}

			function updatePips( key, val ) {
				const row = wrapper.querySelector( '.tw-attr-row[data-attr="' + key + '"]' );
				if ( ! row ) return;
				row.querySelectorAll( '.tw-pip' ).forEach( function ( pip ) {
					const p = parseInt( pip.dataset.pip, 10 );
					pip.classList.toggle( 'active', p <= val );
				} );
			}

			function applyChange( key, delta ) {
				const current = formState[ 'attr_' + key ] || ATTR_MIN;
				const next    = current + delta;

				if ( next < ATTR_MIN || next > ATTR_MAX ) return;
				if ( delta > 0 && usedPoints() >= ATTR_POOL ) {
					setStatus( 'ERROR: No attribute points remaining.', true );
					return;
				}

				formState[ 'attr_' + key ] = next;

				const inp = document.getElementById( 'tw-attr-' + key );
				if ( inp ) inp.value = next;

				updatePips( key, next );
				updateRemainingLabel();
				setStatus( '', false );
			}

			// Delegate clicks on +/- buttons.
			wrapper.addEventListener( 'click', function ( e ) {
				const btn = e.target.closest( '.tw-attr-btn' );
				if ( ! btn ) return;
				const key   = btn.dataset.attr;
				const delta = btn.classList.contains( 'tw-attr-plus' ) ? 1 : -1;
				if ( key ) applyChange( key, delta );
			} );

			updateRemainingLabel();
		} )();

		// ── Step 6: Avatar ────────────────────────────────────────────────────
		( function initAvatar() {
			const dropZone  = document.getElementById( 'tw-avatar-drop' );
			const fileInput = document.getElementById( 'tw-char-avatar' );
			const preview   = document.getElementById( 'tw-avatar-preview' );
			const selected  = document.getElementById( 'tw-avatar-selected' );
			const img       = document.getElementById( 'tw-avatar-img' );
			const clearBtn  = document.getElementById( 'tw-avatar-clear' );
			const trigger   = wrapper.querySelector( '.tw-upload-trigger' );

			if ( ! dropZone || ! fileInput ) return;

			function showFile( file ) {
				if ( file && file.type.startsWith( 'image/' ) ) {
					avatarFile = file;
					const reader = new FileReader();
					reader.onload = function ( ev ) {
						if ( img ) img.src = ev.target.result;
						if ( preview  ) preview.style.display  = 'none';
						if ( selected ) selected.style.display = '';
					};
					reader.readAsDataURL( file );
				}
			}

			function clearFile() {
				avatarFile = null;
				if ( fileInput ) fileInput.value = '';
				if ( img )      img.src          = '';
				if ( selected ) selected.style.display = 'none';
				if ( preview )  preview.style.display  = '';
			}

			if ( trigger )  trigger.addEventListener( 'click', () => fileInput.click() );
			if ( clearBtn ) clearBtn.addEventListener( 'click', clearFile );

			fileInput.addEventListener( 'change', function () {
				showFile( this.files[0] || null );
			} );

			dropZone.addEventListener( 'dragover', e => { e.preventDefault(); dropZone.classList.add( 'drag-over' ); } );
			dropZone.addEventListener( 'dragleave', () => dropZone.classList.remove( 'drag-over' ) );
			dropZone.addEventListener( 'drop', function ( e ) {
				e.preventDefault();
				dropZone.classList.remove( 'drag-over' );
				showFile( e.dataTransfer.files[0] || null );
			} );
		} )();

		// ── Summary population ────────────────────────────────────────────────
		function populateSummary() {
			function set( field, val ) {
				const el = document.getElementById( 'tw-summary-' + field );
				if ( el ) el.textContent = val || '—';
			}
			set( 'character_name', formState.character_name );
			set( 'pronouns',       formState.pronouns || '—' );
			set( 'backstory',      formState.backstory || '—' );
			set( 'race',           formState.race  ? formState.race.name  : '—' );
			set( 'class',          formState.class ? formState.class.name : '—' );

			const attrStr = ATTR_KEYS.map( k =>
				k.toUpperCase().slice( 0, 3 ) + ':' + ( formState[ 'attr_' + k ] || ATTR_MIN )
			).join( ' · ' );
			set( 'attrs',    attrStr );
			set( 'node_id',  formState.node_id ? formState.node_id.name : '—' );
			set( 'avatar',   avatarFile ? avatarFile.name : 'None' );
		}

		// ── Navigation ────────────────────────────────────────────────────────
		const firstNext = wrapper.querySelector( '#tw-char-step1-next' );
		if ( firstNext ) {
			firstNext.addEventListener( 'click', function () {
				if ( validateStep( 0 ) ) { setStatus( '', false ); showStep( 1 ); }
			} );
		}

		wrapper.addEventListener( 'click', function ( e ) {
			const btn = e.target.closest( 'button' );
			if ( ! btn ) return;

			if ( btn.classList.contains( 'tw-btn-next' ) ) {
				if ( validateStep( current ) ) { setStatus( '', false ); showStep( current + 1 ); }
				return;
			}
			if ( btn.classList.contains( 'tw-btn-prev' ) ) {
				setStatus( '', false );
				showStep( current - 1 );
				return;
			}
			if ( btn.classList.contains( 'tw-summary-edit' ) ) {
				const goto = parseInt( btn.dataset.goto, 10 );
				if ( ! isNaN( goto ) ) {
					const idx = steps.findIndex( s => parseInt( s.dataset.step, 10 ) === goto );
					if ( idx >= 0 ) { setStatus( '', false ); showStep( idx ); }
				}
				return;
			}
		} );

		// ── Submit / deploy ───────────────────────────────────────────────────
		const submitBtn = wrapper.querySelector( '#tw-char-submit' );
		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', doSubmit );
		}

		function buildPayload() {
			return {
				nonce          : config.nonce        || '',
				character_name : formState.character_name,
				pronouns       : formState.pronouns,
				backstory      : formState.backstory,
				race           : formState.race  ? formState.race.id  : '',
				class          : formState.class ? formState.class.id : '',
				node_id        : formState.node_id ? formState.node_id.id : '',
				attr_body      : formState.attr_body   || ATTR_MIN,
				attr_reflex    : formState.attr_reflex || ATTR_MIN,
				attr_mind      : formState.attr_mind   || ATTR_MIN,
				attr_spirit    : formState.attr_spirit || ATTR_MIN,
			};
		}

		function doSubmit() {
			const payload = buildPayload();

			// Final validation before send.
			if ( ! payload.character_name ) { setStatus( 'ERROR: Agent name is required.', true ); return; }
			if ( ! payload.race )           { setStatus( 'ERROR: Race selection is required.', true ); return; }
			if ( ! payload.class )          { setStatus( 'ERROR: Class selection is required.', true ); return; }
			if ( ! payload.node_id )        { setStatus( 'ERROR: Node binding is required.', true ); return; }

			submitBtn.disabled    = true;
			submitBtn.textContent = 'SYNCHRONIZING…';
			setStatus( '', false );
			showSpinner();

			const t0 = Date.now();

			// Use FormData when an avatar file is present so we can send multipart.
			// Otherwise send plain JSON (same pattern as world creator).
			let fetchOptions;
			if ( avatarFile ) {
				const fd = new FormData();
				Object.entries( payload ).forEach( ( [ k, v ] ) => fd.append( k, v ) );
				fd.append( 'avatar', avatarFile, avatarFile.name );
				fetchOptions = {
					method      : 'POST',
					headers     : { 'X-WP-Nonce': config.restNonce || '' },
					body        : fd,
					credentials : 'same-origin',
				};
			} else {
				fetchOptions = {
					method      : 'POST',
					headers     : {
						'Content-Type' : 'application/json',
						'X-WP-Nonce'   : config.restNonce || '',
					},
					body        : JSON.stringify( payload ),
					credentials : 'same-origin',
				};
			}

			fetch( restUrl, fetchOptions )
				.then( r => r.json() )
				.then( function ( json ) {
					const wait = Math.max( 0, 2500 - ( Date.now() - t0 ) );
					setTimeout( function () {
						hideSpinner();
						if ( json.success ) {
							setStatus( '// AGENT SYNCHRONIZED: ' + ( json.data.agent_id || '' ), false );
							setTimeout( () => { window.location.href = agentsUrl; }, 1800 );
						} else {
							const msg = ( json.data && json.data.message ) || json.message || 'Unknown error';
							setStatus( 'ERROR: ' + msg, true );
							submitBtn.disabled    = false;
							submitBtn.textContent = '▶ SYNCHRONIZE AGENT';
						}
					}, wait );
				} )
				.catch( function ( err ) {
					hideSpinner();
					setStatus( 'ERROR: Network failure — ' + err.message, true );
					submitBtn.disabled    = false;
					submitBtn.textContent = '▶ SYNCHRONIZE AGENT';
				} );
		}

		// ── Boot ──────────────────────────────────────────────────────────────
		// Position tick marks evenly across the progress track (JS, not CSS,
		// because we don't know totalSteps at CSS time).
		wrapper.querySelectorAll( '.tw-progress-tick' ).forEach( function ( tick ) {
			const t = parseInt( tick.dataset.tick, 10 );
			tick.style.left = ( ( t / totalSteps ) * 100 ) + '%';
		} );

		// Lazy-load races immediately since Step 2 is close.
		loadRaces();

		updateProgress( 0 );

	} );
} )();
