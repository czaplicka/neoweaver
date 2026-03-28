/**
 * tw-campaign-creator.js
 *
 * Drives the 8-step Deployment creation wizard rendered by
 * [tw_create_campaign] shortcode.
 *
 * Steps:
 *   1. Identity    — name + custom directives (static inputs)
 *   2. GM Style    — radio card grid (cinematic_heroic / harsh_grounded / fast_tactical)
 *   3. Game Mode   — radio card grid (solo / co-op)
 *   4. Game Length — radio card grid (short / medium / standard / epic / endless)
 *   5. Difficulty  — radio card grid (easy / casual / standard / hardcore / nightmare)
 *   6. Node Uplink — dynamic grid fetched from Supabase (user's worlds)
 *   7. Agent Assign— dynamic grid, filtered to agents in selected Node (OPTIONAL)
 *   8. Summary     — review + UPLINK DEPLOYMENT
 *
 * Config (injected via wp_localize_script as twCampaignConfig):
 *   nonce        — tw_campaign_nonce for body JSON
 *   restNonce    — wp_rest nonce for X-WP-Nonce header
 *   restUrl      — /wp-json/neoweaver/v1/campaign/create
 *   campaignsUrl — redirect after success
 *   supabaseUrl  — Supabase project URL
 *   supabaseKey  — anon key
 *   userId       — current WP user ID (for agent filtering)
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		const wrapper = document.getElementById( 'tw-campaign-creator-wrapper' );
		if ( ! wrapper ) return;

		const config       = window.twCampaignConfig || {};
		const sbBase       = ( config.supabaseUrl || '' ).replace( /\/$/, '' ) + '/rest/v1/';
		const sbKey        = config.supabaseKey || '';
		const restUrl      = config.restUrl      || '/wp-json/neoweaver/v1/campaign/create';
		const campaignsUrl = config.campaignsUrl || '/campaigns/';

		const steps          = Array.from( wrapper.querySelectorAll( '.tw-step' ) );
		const totalSteps     = parseInt( wrapper.dataset.totalSteps, 10 ) || steps.length;
		const progressFill   = document.getElementById( 'tw-camp-progress-fill' );
		const progressCurrent= document.getElementById( 'tw-camp-step-current' );
		const progressPhase  = document.getElementById( 'tw-camp-progress-phase' );
		const statusEl       = wrapper.querySelector( '.tw-camp-status' );

		let current = 0;

		// Form state — built up step by step, used for summary + submit.
		const formState = {
			campaign_name : '',
			customize     : '',
			gm_style      : null,   // { value, label }
			game_mode     : null,   // { value, label }
			game_length   : null,   // { value, label }
			difficulty    : null,   // { value, label } — maps to 'priority' field in API
			world_id      : null,   // { id, name }
			character_id  : null,   // { id, name } — OPTIONAL
		};

		// Label maps for static radio steps — mirrors PHP option arrays.
		const gmStyleLabels    = { cinematic_heroic: 'Cinematic Heroic', harsh_grounded: 'Harsh Grounded', fast_tactical: 'Fast Tactical' };
		const gameModeLabels   = { 1: 'Solo', 2: 'Co-op' };
		const gameLengthLabels = { 1: 'Short', 2: 'Medium', 3: 'Standard', 4: 'Epic', 5: 'Endless' };
		const difficultyLabels = { 1: 'Easy', 2: 'Casual', 3: 'Standard', 4: 'Hardcore', 5: 'Nightmare' };

		// ── Spinner ──────────────────────────────────────────────────────────
		const spinner = document.createElement( 'div' );
		spinner.id = 'tw-camp-spinner';
		spinner.innerHTML =
			'<div class="tw-spinner-inner">' +
				'<div class="tw-spinner-ring"></div>' +
				'<div class="tw-spinner-ring tw-spinner-ring--2"></div>' +
				'<p class="tw-spinner-text">// UPLINK IN PROGRESS…</p>' +
				'<p class="tw-spinner-sub">Binding deployment to the NeoWeave grid.</p>' +
			'</div>';
		document.body.appendChild( spinner );
		const showSpinner = () => spinner.classList.add( 'active' );
		const hideSpinner = () => spinner.classList.remove( 'active' );

		// ── Status helper ─────────────────────────────────────────────────────
		function setStatus( msg, isError ) {
			if ( ! statusEl ) return;
			statusEl.textContent = msg;
			statusEl.style.color = isError ? '#ff4444' : 'var(--neon-green, #adff00)';
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

			const phase = steps[ idx ] ? steps[ idx ].dataset.phase : '';

			// Lazy-load dynamic grids on first entry.
			if ( phase === 'NODE UPLINK'       && ! gridsLoaded.nodes  ) loadNodes();
			if ( phase === 'AGENT ASSIGNMENT'  && ! gridsLoaded.agents ) loadAgents();

			if ( steps[ idx ] && steps[ idx ].classList.contains( 'tw-step--summary' ) ) {
				populateSummary();
			}

			wrapper.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		// ── Validation ────────────────────────────────────────────────────────
		function validateStep( idx ) {
			const step = steps[ idx ];
			if ( ! step ) return true;

			const phase = step.dataset.phase || '';

			if ( idx === 0 ) {
				// Step 1 — identity
				const nameInput = wrapper.querySelector( '#tw-camp-name' );
				if ( ! nameInput || ! nameInput.value.trim() ) {
					nameInput && nameInput.focus();
					setStatus( 'ERROR: Deployment name is required.', true );
					return false;
				}
				formState.campaign_name = nameInput.value.trim();
				formState.customize     = ( wrapper.querySelector( '#tw-camp-notes' ) || {} ).value || '';
				return true;
			}

			// Static radio steps
			const radioFields = {
				'GM PROTOCOL'        : { key: 'gm_style',    labels: gmStyleLabels    },
				'OPERATIVE MODE'     : { key: 'game_mode',   labels: gameModeLabels   },
				'OPERATION SCOPE'    : { key: 'game_length',  labels: gameLengthLabels },
				'THREAT CALIBRATION' : { key: 'difficulty',  labels: difficultyLabels },
			};

			if ( radioFields[ phase ] ) {
				const { key, labels } = radioFields[ phase ];
				const checked = step.querySelector( 'input[type="radio"]:checked' );
				if ( ! checked ) {
					setStatus( 'ERROR: Select an option to continue.', true );
					return false;
				}
				formState[ key ] = { value: checked.value, label: labels[ checked.value ] || checked.value };
				return true;
			}

			if ( phase === 'NODE UPLINK' ) {
				if ( ! formState.world_id ) {
					setStatus( 'ERROR: Select a Node to bind this deployment.', true );
					return false;
				}
				return true;
			}

			// AGENT ASSIGNMENT is optional — always allow proceeding.
			if ( phase === 'AGENT ASSIGNMENT' ) {
				return true;
			}

			return true;
		}

		// ── Supabase fetch helper ─────────────────────────────────────────────
		function sbGet( table, params ) {
			if ( ! sbBase || ! sbKey ) return Promise.resolve( [] );
			const url = new URL( sbBase + table );
			Object.entries( params || {} ).forEach( ( [ k, v ] ) => url.searchParams.set( k, v ) );
			return fetch( url.toString(), {
				headers: {
					'apikey'        : sbKey,
					'Authorization' : 'Bearer ' + sbKey,
				},
			} ).then( r => r.json() );
		}

		// HTML-escape helper.
		function esc( str ) {
			return String( str || '' )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' );
		}

		// Build a selectable card for a dynamic grid.
		function makeCard( id, name, sub, emoji, selectedId, onSelect ) {
			const div = document.createElement( 'div' );
			div.className = 'tw-dyn-card' + ( selectedId === id ? ' selected' : '' );
			div.dataset.id = id;
			div.innerHTML =
				'<span class="tw-dyn-icon">' + ( emoji || '◈' ) + '</span>' +
				'<strong>' + esc( name ) + '</strong>' +
				( sub ? '<span>' + esc( sub ) + '</span>' : '' );
			div.addEventListener( 'click', function () {
				div.closest( '.tw-dynamic-grid' ).querySelectorAll( '.tw-dyn-card' ).forEach( c => c.classList.remove( 'selected' ) );
				div.classList.add( 'selected' );
				onSelect( id, name );
			} );
			return div;
		}

		// ── Dynamic grid state ────────────────────────────────────────────────
		const gridsLoaded = { nodes: false, agents: false };

		// Step 6: user's playable Nodes
		function loadNodes() {
			gridsLoaded.nodes = true;
			const grid = document.getElementById( 'tw-camp-node-grid' );
			if ( ! grid ) return;

			sbGet( 'cyber_worlds', { select: 'id,name,description,difficulty,entropy', order: 'name.asc' } )
				.then( function ( rows ) {
					grid.innerHTML = '';
					if ( ! rows || ! rows.length ) {
						grid.innerHTML = '<p class="tw-error-msg">No Nodes found. <a href="/create-world/" class="tw-link">Deploy one first →</a></p>';
						return;
					}
					rows.forEach( function ( row ) {
						// Skip Hard Reset nodes.
						if ( parseInt( row.entropy, 10 ) >= 100 ) return;

						const diff  = [ '', 'Coherent', 'Stable', 'Unstable', 'Critical', 'Catastrophic' ][ parseInt( row.difficulty, 10 ) ] || '—';
						const sub   = row.description
							? row.description.slice( 0, 72 ) + ( row.description.length > 72 ? '…' : '' )
							: 'Diff: ' + diff + ' · Entropy: ' + ( row.entropy || 0 ) + '%';

						grid.appendChild( makeCard(
							row.id, row.name, sub, '🌐',
							formState.world_id ? formState.world_id.id : null,
							function ( id, name ) {
								formState.world_id   = { id, name };
								// Reset agent selection when world changes.
								formState.character_id = null;
								gridsLoaded.agents   = false;
								setStatus( '', false );
							}
						) );
					} );
					if ( ! grid.querySelector( '.tw-dyn-card' ) ) {
						grid.innerHTML = '<p class="tw-error-msg">No playable Nodes. <a href="/create-world/" class="tw-link">Deploy one first →</a></p>';
					}
				} )
				.catch( function () {
					grid.innerHTML = '<p class="tw-error-msg">Failed to load Nodes.</p>';
				} );
		}

		// Step 7: user's living agents, filtered to selected Node (OPTIONAL step)
		function loadAgents() {
			gridsLoaded.agents = true;
			const grid = document.getElementById( 'tw-camp-agent-grid' );
			if ( ! grid ) return;

			grid.innerHTML = '<div class="tw-loading-state"><span class="tw-loading-dot"></span>FETCHING AVAILABLE AGENTS…</div>';

			const params = {
				select  : 'id,name,class_id,race_id,status,world_id,cyber_classes(name),cyber_races(name)',
				status  : 'neq.STATUS_DEAD',
				order   : 'name.asc',
			};

			// If a Node was selected, filter agents to that world.
			if ( formState.world_id ) {
				params.world_id = 'eq.' + formState.world_id.id;
			}

			sbGet( 'cyber_characters', params )
				.then( function ( rows ) {
					grid.innerHTML = '';
					if ( ! rows || ! rows.length ) {
						grid.innerHTML = '<p class="tw-error-msg">No eligible agents found. <a href="/create-agent/" class="tw-link">Create one first →</a></p>';
						return;
					}
					rows.forEach( function ( row ) {
						const className = ( row.cyber_classes && row.cyber_classes.name ) || '—';
						const raceName  = ( row.cyber_races  && row.cyber_races.name  ) || '—';
						const sub       = raceName + ' · ' + className;

						grid.appendChild( makeCard(
							row.id, row.name, sub, '🕵️',
							formState.character_id ? formState.character_id.id : null,
							function ( id, name ) { formState.character_id = { id, name }; setStatus( '', false ); }
						) );
					} );
				} )
				.catch( function () {
					grid.innerHTML = '<p class="tw-error-msg">Failed to load agents.</p>';
				} );
		}

		// ── Summary ───────────────────────────────────────────────────────────
		function populateSummary() {
			function set( field, val ) {
				const el = document.getElementById( 'tw-summary-' + field );
				if ( el ) el.textContent = val || '—';
			}
			set( 'campaign_name', formState.campaign_name );
			set( 'customize',     formState.customize || '—' );
			set( 'gm_style',      formState.gm_style     ? formState.gm_style.label     : '—' );
			set( 'game_mode',     formState.game_mode    ? formState.game_mode.label    : '—' );
			set( 'game_length',   formState.game_length  ? formState.game_length.label  : '—' );
			set( 'difficulty',    formState.difficulty   ? formState.difficulty.label   : '—' );
			set( 'world_id',      formState.world_id     ? formState.world_id.name      : '—' );
			set( 'character_id',  formState.character_id ? formState.character_id.name  : '— (unassigned)' );
		}

		// ── Navigation ────────────────────────────────────────────────────────
		const firstNext = wrapper.querySelector( '#tw-camp-step1-next' );
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

		// ── Submit ────────────────────────────────────────────────────────────
		const submitBtn = wrapper.querySelector( '#tw-camp-submit' );
		if ( submitBtn ) submitBtn.addEventListener( 'click', doSubmit );

		function buildPayload() {
			return {
				nonce        : config.nonce || '',
				name         : formState.campaign_name,
				customize    : formState.customize,
				gm_style     : formState.gm_style     ? formState.gm_style.value     : '',
				game_mode    : formState.game_mode    ? parseInt( formState.game_mode.value, 10 )    : 0,
				game_length  : formState.game_length  ? parseInt( formState.game_length.value, 10 )  : 0,
				// 'priority' is the API/DB field name; formState uses 'difficulty' for clarity.
				priority     : formState.difficulty   ? parseInt( formState.difficulty.value, 10 )   : 0,
				world_id     : formState.world_id     ? formState.world_id.id     : '',
				// character_id is optional — send empty string if not chosen.
				character_id : formState.character_id ? formState.character_id.id : '',
			};
		}

		function doSubmit() {
			const payload = buildPayload();

			// Final guards — only required fields block submission.
			if ( ! payload.name )        { setStatus( 'ERROR: Deployment name is required.', true ); return; }
			if ( ! payload.gm_style )    { setStatus( 'ERROR: GM Protocol is required.', true ); return; }
			if ( ! payload.game_mode )   { setStatus( 'ERROR: Operative mode is required.', true ); return; }
			if ( ! payload.game_length ) { setStatus( 'ERROR: Operation scope is required.', true ); return; }
			if ( ! payload.priority )    { setStatus( 'ERROR: Threat calibration is required.', true ); return; }
			if ( ! payload.world_id )    { setStatus( 'ERROR: Node binding is required.', true ); return; }
			// character_id is intentionally NOT required here.

			submitBtn.disabled    = true;
			submitBtn.textContent = 'UPLINK IN PROGRESS…';
			setStatus( '', false );
			showSpinner();

			const t0 = Date.now();

			fetch( restUrl, {
				method      : 'POST',
				headers     : {
					'Content-Type' : 'application/json',
					'X-WP-Nonce'   : config.restNonce || '',
				},
				body        : JSON.stringify( payload ),
				credentials : 'same-origin',
			} )
				.then( r => r.json() )
				.then( function ( json ) {
					const wait = Math.max( 0, 2500 - ( Date.now() - t0 ) );
					setTimeout( function () {
						hideSpinner();
						if ( json.success ) {
							setStatus( '// DEPLOYMENT ONLINE: ' + ( json.data.campaign_id || '' ), false );
							setTimeout( () => { window.location.href = campaignsUrl; }, 1800 );
						} else {
							const msg = ( json.data && json.data.message ) || json.message || 'Unknown error';
							setStatus( 'ERROR: ' + msg, true );
							submitBtn.disabled    = false;
							submitBtn.textContent = '▶ UPLINK DEPLOYMENT';
						}
					}, wait );
				} )
				.catch( function ( err ) {
					hideSpinner();
					setStatus( 'ERROR: Network failure — ' + err.message, true );
					submitBtn.disabled    = false;
					submitBtn.textContent = '▶ UPLINK DEPLOYMENT';
				} );
		}

		// ── Boot ──────────────────────────────────────────────────────────────
		// Position progress ticks evenly across the track.
		wrapper.querySelectorAll( '.tw-progress-tick' ).forEach( function ( tick ) {
			const t = parseInt( tick.dataset.tick, 10 );
			tick.style.left = ( ( t / totalSteps ) * 100 ) + '%';
		} );

		updateProgress( 0 );

	} );
} )();
