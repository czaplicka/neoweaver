/**
 * tw-campaign-creator.js  — 8-step Deployment wizard
 *
 * Steps:
 *   1. Identity      — name + directives
 *   2. GM Style      — cinematic_heroic / harsh_grounded / fast_tactical
 *   3. Game Mode     — solo / team
 *   4. Game Length   — short / medium / standard / epic / endless
 *   5. World Type    — easy / casual / standard / hardcore / nightmare  (field: world_type)
 *   6. Priority      — combat / wealth / discovery / relations / mix    (field: priority)
 *   7. Node & Agent  — both optional, single screen
 *   8. Summary       — review + UPLINK
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		const wrapper = document.getElementById( 'tw-campaign-creator-wrapper' );
		if ( ! wrapper ) return;

		const config       = window.twCampaignConfig || {};
		const sbBase       = ( config.supabaseUrl || '' ).replace( /\/$/, '' ) + '/rest/v1/';
		const sbKey        = config.supabaseKey || '';
		const userId       = config.userId      || 0;
		const restUrl      = config.restUrl      || '/wp-json/neoweaver/v1/campaign/create';
		const campaignsUrl = config.campaignsUrl || '/campaigns/';

		const steps           = Array.from( wrapper.querySelectorAll( '.tw-step' ) );
		const totalSteps      = parseInt( wrapper.dataset.totalSteps, 10 ) || steps.length;
		const progressFill    = document.getElementById( 'tw-camp-progress-fill' );
		const progressCurrent = document.getElementById( 'tw-camp-step-current' );
		const progressPhase   = document.getElementById( 'tw-camp-progress-phase' );
		const statusEl        = wrapper.querySelector( '.tw-camp-status' );

		let current = 0;

		const formState = {
			campaign_name : '',
			customize     : '',
			gm_style      : null,
			game_mode     : null,
			game_length   : null,
			world_type    : null,
			priority      : null,
			world_id      : null,   // OPTIONAL { id, name }
			character_id  : null,   // OPTIONAL { id, name }
		};

		const gmStyleLabels    = { cinematic_heroic: 'Cinematic Heroic', harsh_grounded: 'Harsh Grounded', fast_tactical: 'Fast Tactical' };
		const gameModeLabels   = { 1: 'Solo', 2: 'Team' };
		const gameLengthLabels = { 1: 'Short', 2: 'Medium', 3: 'Standard', 4: 'Epic', 5: 'Endless' };
		const worldTypeLabels  = { 1: 'Easy', 2: 'Casual', 3: 'Standard', 4: 'Hardcore', 5: 'Nightmare' };
		const priorityLabels   = { 1: 'Combat', 2: 'Wealth', 3: 'Discovery', 4: 'Relations', 5: 'Mix' };

		// ── Spinner ───────────────────────────────────────────────────────────
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

		function setStatus( msg, isError ) {
			if ( ! statusEl ) return;
			statusEl.textContent = msg;
			statusEl.style.color = isError ? '#ff4444' : 'var(--neon-green, #adff00)';
		}

		function updateProgress( idx ) {
			const num   = idx + 1;
			const pct   = Math.round( ( num / totalSteps ) * 100 );
			const phase = ( steps[ idx ] && steps[ idx ].dataset.phase ) || '';
			if ( progressFill )    progressFill.style.width   = pct + '%';
			if ( progressCurrent ) progressCurrent.textContent = num;
			if ( progressPhase )   progressPhase.textContent   = phase;
			wrapper.querySelectorAll( '.tw-progress-tick' ).forEach( function ( tick ) {
				const t = parseInt( tick.dataset.tick, 10 );
				tick.classList.toggle( 'active',  t <= num );
				tick.classList.toggle( 'current', t === num );
			} );
		}

		function showStep( idx ) {
			idx = Math.max( 0, Math.min( steps.length - 1, idx ) );
			steps.forEach( ( s, i ) => s.classList.toggle( 'active', i === idx ) );
			current = idx;
			updateProgress( idx );
			const phase = steps[ idx ] ? steps[ idx ].dataset.phase : '';
			if ( phase === 'NODE & AGENT BINDING' && ! gridsLoaded.nodes ) loadNodes();
			if ( steps[ idx ] && steps[ idx ].classList.contains( 'tw-step--summary' ) ) populateSummary();
			wrapper.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		function validateStep( idx ) {
			const step = steps[ idx ];
			if ( ! step ) return true;
			const phase = step.dataset.phase || '';

			if ( idx === 0 ) {
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

			const radioFields = {
				'GM PROTOCOL'        : { key: 'gm_style',   labels: gmStyleLabels    },
				'OPERATIVE MODE'     : { key: 'game_mode',  labels: gameModeLabels   },
				'OPERATION SCOPE'    : { key: 'game_length', labels: gameLengthLabels },
				'THREAT CALIBRATION' : { key: 'world_type', labels: worldTypeLabels  },
				'MISSION PRIORITY'   : { key: 'priority',   labels: priorityLabels   },
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

			// Step 7: both optional
			if ( phase === 'NODE & AGENT BINDING' ) return true;

			return true;
		}

		function sbGet( table, params ) {
			if ( ! sbBase || ! sbKey ) return Promise.resolve( [] );
			const url = new URL( sbBase + table );
			Object.entries( params || {} ).forEach( ( [ k, v ] ) => url.searchParams.set( k, v ) );
			return fetch( url.toString(), {
				headers: { 'apikey': sbKey, 'Authorization': 'Bearer ' + sbKey },
			} ).then( r => r.json() );
		}

		function esc( str ) {
			return String( str || '' )
				.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
		}

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

		const gridsLoaded = { nodes: false };

		function loadNodes() {
			gridsLoaded.nodes = true;
			const grid = document.getElementById( 'tw-camp-node-grid' );
			if ( ! grid ) return;

			const params = {
				select      : 'id,name,description,difficulty,entropy',
				order       : 'name.asc',
			};
			// Filter by current user
			if ( userId ) params.wp_user_id = 'eq.' + userId;

			sbGet( 'cyber_worlds', params )
				.then( function ( rows ) {
					grid.innerHTML = '';
					if ( ! rows || ! rows.length ) {
						grid.innerHTML = '<p class="tw-error-msg">No Nodes found. <a href="/new-node/" class="tw-link">Deploy one first →</a></p>';
						return;
					}
					rows.forEach( function ( row ) {
						const diff = [ '', 'Coherent', 'Stable', 'Unstable', 'Critical', 'Catastrophic' ][ parseInt( row.difficulty, 10 ) ] || '—';
						const sub  = row.description
							? row.description.slice( 0, 72 ) + ( row.description.length > 72 ? '…' : '' )
							: 'Diff: ' + diff + ' · Entropy: ' + ( row.entropy || 0 ) + '%';
						grid.appendChild( makeCard(
							row.id, row.name, sub, '🌐',
							formState.world_id ? formState.world_id.id : null,
							function ( id, name ) {
								formState.world_id     = { id, name };
								formState.character_id = null;
								setStatus( '', false );
								loadAgents( id );
							}
						) );
					} );
					if ( ! grid.querySelector( '.tw-dyn-card' ) ) {
						grid.innerHTML = '<p class="tw-error-msg">No playable Nodes. <a href="/new-node/" class="tw-link">Deploy one first →</a></p>';
					}
					// Load all user's agents initially (unfiltered)
					loadAgents( null );
				} )
				.catch( function ( err ) {
					console.error( 'NeoWeaver: loadNodes error', err );
					grid.innerHTML = '<p class="tw-error-msg">Failed to load Nodes.</p>';
				} );
		}

		function loadAgents( worldId ) {
			const grid = document.getElementById( 'tw-camp-agent-grid' );
			const hint = document.getElementById( 'tw-agent-hint' );
			if ( ! grid ) return;
			grid.innerHTML = '<div class="tw-loading-state"><span class="tw-loading-dot"></span>FETCHING AGENTS…</div>';
			if ( hint ) hint.style.display = worldId ? 'none' : '';

			const params = {
				select : 'id,name,class_id,race_id,status,world_id,cyber_classes(name),cyber_races(name)',
				status : 'neq.STATUS_DEAD',
				order  : 'name.asc',
			};
			if ( userId   ) params.wp_user_id = 'eq.' + userId;
			if ( worldId  ) params.world_id   = 'eq.' + worldId;

			sbGet( 'cyber_characters', params )
				.then( function ( rows ) {
					grid.innerHTML = '';
					if ( ! rows || ! rows.length ) {
						grid.innerHTML = '<p class="tw-error-msg">No eligible agents found. <a href="/new-agent/" class="tw-link">Create one first →</a></p>';
						return;
					}
					rows.forEach( function ( row ) {
						const className = ( row.cyber_classes && row.cyber_classes.name ) || '—';
						const raceName  = ( row.cyber_races  && row.cyber_races.name  ) || '—';
						grid.appendChild( makeCard(
							row.id, row.name, raceName + ' · ' + className, '🕵️',
							formState.character_id ? formState.character_id.id : null,
							function ( id, name ) { formState.character_id = { id, name }; setStatus( '', false ); }
						) );
					} );
				} )
				.catch( function ( err ) {
					console.error( 'NeoWeaver: loadAgents error', err );
					grid.innerHTML = '<p class="tw-error-msg">Failed to load agents.</p>';
				} );
		}

		function populateSummary() {
			function set( field, val ) {
				const el = document.getElementById( 'tw-summary-' + field );
				if ( el ) el.textContent = val || '—';
			}
			set( 'campaign_name', formState.campaign_name );
			set( 'customize',     formState.customize || '—' );
			set( 'gm_style',      formState.gm_style    ? formState.gm_style.label    : '—' );
			set( 'game_mode',     formState.game_mode   ? formState.game_mode.label   : '—' );
			set( 'game_length',   formState.game_length ? formState.game_length.label : '—' );
			set( 'world_type',    formState.world_type  ? formState.world_type.label  : '—' );
			set( 'priority',      formState.priority    ? formState.priority.label    : '—' );
			set( 'world_id',      formState.world_id    ? formState.world_id.name     : '— (unbound)' );
			set( 'character_id',  formState.character_id ? formState.character_id.name : '— (unassigned)' );
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
				setStatus( '', false ); showStep( current - 1 );
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
				gm_style     : formState.gm_style    ? formState.gm_style.value                    : '',
				game_mode    : formState.game_mode   ? parseInt( formState.game_mode.value,   10 ) : 0,
				game_length  : formState.game_length ? parseInt( formState.game_length.value, 10 ) : 0,
				world_type   : formState.world_type  ? parseInt( formState.world_type.value,  10 ) : 0,
				priority     : formState.priority    ? parseInt( formState.priority.value,    10 ) : 0,
				world_id     : formState.world_id    ? formState.world_id.id     : null,
				character_id : formState.character_id ? formState.character_id.id : null,
			};
		}

		function doSubmit() {
			const payload = buildPayload();
			if ( ! payload.name )        { setStatus( 'ERROR: Deployment name is required.', true ); return; }
			if ( ! payload.gm_style )    { setStatus( 'ERROR: GM Protocol is required.', true ); return; }
			if ( ! payload.game_mode )   { setStatus( 'ERROR: Operative mode is required.', true ); return; }
			if ( ! payload.game_length ) { setStatus( 'ERROR: Operation scope is required.', true ); return; }
			if ( ! payload.world_type )  { setStatus( 'ERROR: Threat calibration is required.', true ); return; }
			if ( ! payload.priority )    { setStatus( 'ERROR: Mission priority is required.', true ); return; }
			// world_id + character_id intentionally NOT required.

			submitBtn.disabled    = true;
			submitBtn.textContent = 'UPLINK IN PROGRESS…';
			setStatus( '', false );
			showSpinner();

			const t0 = Date.now();
			fetch( restUrl, {
				method      : 'POST',
				headers     : { 'Content-Type': 'application/json', 'X-WP-Nonce': config.restNonce || '' },
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

		// Boot
		wrapper.querySelectorAll( '.tw-progress-tick' ).forEach( function ( tick ) {
			const t = parseInt( tick.dataset.tick, 10 );
			tick.style.left = ( ( t / totalSteps ) * 100 ) + '%';
		} );
		updateProgress( 0 );

	} );
} )();
