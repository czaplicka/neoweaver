/**
 * campaign-creator.js  — 8-step Deployment wizard
 *
 * Agent eligibility rule (Step 7):
 *   Bound agents:  character → cyber_campaign_characters → cyber_campaign
 *                  → cyber_campaign_worlds → selected world_id
 *   Free agents:   characters with NO entry in cyber_campaign_characters at all.
 */
( function () {
	'use strict';

	// ── Shared audio engine (Web Audio API — no external files needed) ────────
	const NW_SFX = ( () => {
		let ctx = null;
		const get = () => ctx || ( ctx = new ( window.AudioContext || window.webkitAudioContext )() );

		function beep( freq = 440, type = 'square', duration = 0.08, vol = 0.18 ) {
			try {
				const ac = get(), o = ac.createOscillator(), g = ac.createGain();
				o.type = type;
				o.frequency.value = freq;
				g.gain.setValueAtTime( vol, ac.currentTime );
				g.gain.exponentialRampToValueAtTime( 0.001, ac.currentTime + duration );
				o.connect( g ); g.connect( ac.destination );
				o.start(); o.stop( ac.currentTime + duration );
			} catch ( e ) {}
		}

		return {
			nav:    () => beep( 660, 'square',   0.06, 0.15 ),
			select: () => beep( 880, 'sine',     0.10, 0.20 ),
			back:   () => beep( 330, 'sawtooth', 0.08, 0.12 ),
			deploy: () => { beep( 440, 'square', 0.1, 0.2 ); setTimeout( () => beep( 660, 'sine', 0.15, 0.25 ), 120 ); },
			error:  () => beep( 180, 'sawtooth', 0.18, 0.20 ),
		};
	} )();

	// ── Shared spinner factory ────────────────────────────────────────────────
	function makeSpinner( id, title, subtitle ) {
		const el = document.createElement( 'div' );
		el.id = id;
		el.innerHTML =
			'<div class="tw-spinner-inner">' +
				'<div class="tw-spinner-ring"></div>' +
				'<div class="tw-spinner-ring tw-spinner-ring--2"></div>' +
				'<p class="tw-spinner-text">' + title + '</p>' +
				'<p class="tw-spinner-sub">' + subtitle + '</p>' +
			'</div>';
		document.body.appendChild( el );
		return {
			show: () => el.classList.add( 'active' ),
			hide: () => el.classList.remove( 'active' ),
		};
	}

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
			world_id      : null,
			character_id  : null,
		};

		const gmStyleLabels    = { cinematic_heroic: 'Cinematic Heroic', harsh_grounded: 'Harsh Grounded', fast_tactical: 'Fast Tactical' };
		const gameModeLabels   = { 1: 'Solo', 2: 'Team' };
		const gameLengthLabels = { 1: 'Short', 2: 'Medium', 3: 'Standard', 4: 'Epic', 5: 'Endless' };
		const worldTypeLabels  = { 1: 'Easy', 2: 'Casual', 3: 'Standard', 4: 'Hardcore', 5: 'Nightmare' };
		const priorityLabels   = { 1: 'Combat', 2: 'Wealth', 3: 'Discovery', 4: 'Relations', 5: 'Mix' };

		// ── Spinner ───────────────────────────────────────────────────────────
		const spinner = makeSpinner(
			'tw-camp-spinner',
			'// UPLINK IN PROGRESS…',
			'Binding deployment to the NeoWeave grid.'
		);

		// ── Inline error helpers ──────────────────────────────────────────────
		function showFieldError( stepEl, msg ) {
			clearFieldError( stepEl );
			const err = document.createElement( 'p' );
			err.className = 'tw-field-error';
			err.textContent = '⚠ ' + msg;
			const nav = stepEl.querySelector( '.tw-nav-row' );
			nav ? nav.before( err ) : stepEl.appendChild( err );
			const target = stepEl.querySelector( '.tw-option-grid, #tw-camp-name' );
			if ( target ) {
				target.classList.remove( 'tw-shake' );
				void target.offsetWidth;
				target.classList.add( 'tw-shake' );
				target.addEventListener( 'animationend', () => target.classList.remove( 'tw-shake' ), { once: true } );
			}
			stepEl.querySelectorAll( '.tw-card-visual' ).forEach( v => v.classList.add( 'tw-card--error' ) );
			NW_SFX.error();
		}

		function clearFieldError( stepEl ) {
			stepEl.querySelectorAll( '.tw-field-error' ).forEach( e => e.remove() );
			stepEl.querySelectorAll( '.tw-card--error' ).forEach( v => v.classList.remove( 'tw-card--error' ) );
		}

		function setStatus( msg, isError ) {
			if ( ! statusEl ) return;
			statusEl.textContent = msg;
			statusEl.style.color = isError ? '#ff4444' : 'var(--neon-green, #adff00)';
		}

		function updateProgress( idx ) {
			const num  = idx + 1;
			const pct  = Math.round( ( num / totalSteps ) * 100 );
			const phase = ( steps[ idx ] && steps[ idx ].dataset.phase ) || '';
			if ( progressFill )    progressFill.style.width    = pct + '%';
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
			const phase = ( step.dataset.phase || '' ).trim();

			clearFieldError( step );

			if ( idx === 0 ) {
				const nameInput = wrapper.querySelector( '#tw-camp-name' );
				if ( ! nameInput || ! nameInput.value.trim() ) {
					if ( nameInput ) nameInput.focus();
					showFieldError( step, 'Deployment name is required.' );
					if ( nameInput ) {
						nameInput.classList.add( 'tw-input--error' );
						nameInput.addEventListener( 'input', () => {
							nameInput.classList.remove( 'tw-input--error' );
							clearFieldError( step );
						}, { once: true } );
					}
					return false;
				}
				formState.campaign_name = nameInput.value.trim();
				formState.customize     = ( wrapper.querySelector( '#tw-camp-notes' ) || {} ).value || '';
				return true;
			}

			const radioFields = {
				'GM PROTOCOL'        : { key: 'gm_style',    labels: gmStyleLabels,    msg: 'Select a GM Protocol to continue.'      },
				'OPERATIVE MODE'     : { key: 'game_mode',   labels: gameModeLabels,   msg: 'Select an Operative Mode to continue.'  },
				'OPERATION SCOPE'    : { key: 'game_length',  labels: gameLengthLabels, msg: 'Select an Operation Scope to continue.' },
				'THREAT CALIBRATION' : { key: 'world_type',  labels: worldTypeLabels,  msg: 'Select a Threat Level to continue.'     },
				'MISSION PRIORITY'   : { key: 'priority',    labels: priorityLabels,   msg: 'Select a Mission Priority to continue.' },
			};

			if ( radioFields[ phase ] ) {
				const { key, labels, msg } = radioFields[ phase ];
				const checked = step.querySelector( 'input[type="radio"]:checked' );
				if ( ! checked ) {
					showFieldError( step, msg );
					step.querySelectorAll( 'input[type="radio"]' ).forEach(
						r => r.addEventListener( 'change', () => clearFieldError( step ), { once: true } )
					);
					return false;
				}
				formState[ key ] = { value: checked.value, label: labels[ checked.value ] || checked.value };
				return true;
			}

			return true;
		}

		// ── Supabase helper ───────────────────────────────────────────────────
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
				NW_SFX.select();
				onSelect( id, name );
			} );
			return div;
		}

		const gridsLoaded = { nodes: false };

		function loadNodes() {
			gridsLoaded.nodes = true;
			const grid = document.getElementById( 'tw-camp-node-grid' );
			if ( ! grid ) return;
			const params = { select: 'id,name,description', order: 'name.asc' };
			if ( userId ) params.wp_user_id = 'eq.' + userId;
			sbGet( 'cyber_worlds', params )
				.then( function ( rows ) {
					grid.innerHTML = '';
					if ( ! rows || ! rows.length ) {
						grid.innerHTML = '<p class="tw-error-msg">No Nodes found. <a href="/new-node/" class="tw-link">Deploy one first →</a></p>';
						return;
					}
					rows.forEach( function ( row ) {
						const sub = row.description ? row.description.slice( 0, 80 ) + ( row.description.length > 80 ? '…' : '' ) : null;
						grid.appendChild( makeCard(
							row.id, row.name, sub, '🌐',
							formState.world_id ? formState.world_id.id : null,
							function ( id, name ) {
								// Reset agent selection whenever the world changes.
								formState.world_id     = { id, name };
								formState.character_id = null;
								setStatus( '', false );
								loadAgents( id );
							}
						) );
					} );
					// Show the agent grid empty state until a world is chosen.
					loadAgents( null );
				} )
				.catch( function ( err ) {
					console.error( 'NeoWeaver: loadNodes error', err );
					grid.innerHTML = '<p class="tw-error-msg">Failed to load Nodes.</p>';
				} );
		}

		// ── loadAgents ────────────────────────────────────────────────────────────────
		// Logic:
		//   1. Find all campaigns linked to the selected world (cyber_campaign_worlds)
		//   2. Find characters assigned to those campaigns (cyber_campaign_characters)
		//      → these are "bound to this world" agents
		//   3. Find characters with NO entry in cyber_campaign_characters at all
		//      → these are "free agents" (no campaign = no world)
		//   4. Display both groups; exclude dead agents from the user's own characters

		function loadAgents( worldId ) {
			const grid = document.getElementById( 'tw-camp-agent-grid' );
			const hint = document.getElementById( 'tw-agent-hint' );
			if ( ! grid ) return;

			if ( ! worldId ) {
				grid.innerHTML = '';
				if ( hint ) {
					hint.style.display = '';
					hint.textContent   = '← Select a Node first to see its available agents.';
				}
				return;
			}

			if ( hint ) hint.style.display = 'none';
			grid.innerHTML = '<div class="tw-loading-state"><span class="tw-loading-dot"></span>FETCHING AGENTS…</div>';

			// Step 1: get campaign IDs linked to this world
			const worldCampaignsUrl = sbBase + 'cyber_campaign_worlds'
				+ '?select=campaign_id'
				+ '&world_id=eq.' + encodeURIComponent( worldId );

			// Step 2: get ALL character IDs that have ANY campaign assignment
			//         (to know who is a "free agent" — not in any campaign)
			const allAssignedUrl = sbBase + 'cyber_campaign_characters'
				+ '?select=character_id,campaign_id';

			// Step 3: get all user's active characters with class/race info
			let allCharsUrl = sbBase + 'cyber_characters'
				+ '?select=id,name,class_id,race_id,status,cyber_classes(name),cyber_races(name)'
				+ '&status=neq.STATUS_DEAD'
				+ '&order=name.asc';
			if ( userId ) allCharsUrl += '&wp_user_id=eq.' + userId;

			Promise.all( [
				fetch( worldCampaignsUrl, { headers: { 'apikey': sbKey, 'Authorization': 'Bearer ' + sbKey } } ).then( r => r.json() ),
				fetch( allAssignedUrl,    { headers: { 'apikey': sbKey, 'Authorization': 'Bearer ' + sbKey } } ).then( r => r.json() ),
				fetch( allCharsUrl,       { headers: { 'apikey': sbKey, 'Authorization': 'Bearer ' + sbKey } } ).then( r => r.json() ),
			] )
			.then( function ( [ worldCampaigns, allAssigned, allChars ] ) {

				// campaign IDs belonging to this world
				const worldCampaignIds = new Set(
					( worldCampaigns || [] ).map( r => r.campaign_id )
				);

				// character IDs that are assigned to a campaign in THIS world
				const boundCharIds = new Set(
					( allAssigned || [] )
						.filter( r => worldCampaignIds.has( r.campaign_id ) )
						.map( r => r.character_id )
				);

				// character IDs that have ANY campaign assignment (regardless of world)
				const anyAssignedCharIds = new Set(
					( allAssigned || [] ).map( r => r.character_id )
				);

				// Split characters into two groups
				const boundAgents = [];
				const freeAgents  = [];

				( allChars || [] ).forEach( function ( char ) {
					if ( boundCharIds.has( char.id ) ) {
						boundAgents.push( char );
					} else if ( ! anyAssignedCharIds.has( char.id ) ) {
						// Not in ANY campaign → true free agent
						freeAgents.push( char );
					}
					// If assigned to campaigns in other worlds → don't show
					// (game rule: one character plays in only one world)
				} );

				grid.innerHTML = '';

				if ( ! boundAgents.length && ! freeAgents.length ) {
					grid.innerHTML =
						'<p class="tw-error-msg">No agents available for this Node. ' +
						'<a href="/new-agent/" class="tw-link">Create a Field Agent first →</a></p>';
					return;
				}

				function renderAgentCard( row, badge ) {
					const className = ( row.cyber_classes && row.cyber_classes.name ) || '—';
					const raceName  = ( row.cyber_races   && row.cyber_races.name   ) || '—';
					const card = makeCard(
						row.id,
						row.name,
						raceName + ' · ' + className,
						'🕵️',
						formState.character_id ? formState.character_id.id : null,
						function ( id, name ) { formState.character_id = { id, name }; setStatus( '', false ); }
					);
					if ( badge ) {
						const strong = card.querySelector( 'strong' );
						if ( strong ) strong.insertAdjacentHTML( 'afterend', badge );
					}
					grid.appendChild( card );
				}

				// ── Group header: bound to this world ─────────────────────────
				if ( boundAgents.length ) {
					const header = document.createElement( 'p' );
					header.className = 'tw-agents-group-label';
					header.textContent = '// ASSIGNED TO THIS NODE';
					grid.appendChild( header );
					boundAgents.forEach( row => renderAgentCard( row, null ) );
				}

				// ── Group header: free agents ──────────────────────────────────
				if ( freeAgents.length ) {
					const header = document.createElement( 'p' );
					header.className = 'tw-agents-group-label tw-agents-group-label--free';
					header.textContent = '// FREE AGENTS — no active deployment';
					grid.appendChild( header );
					freeAgents.forEach( row =>
						renderAgentCard( row, ' <span class="tw-badge-free">FREE</span>' )
					);
				}
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

		// ── Single delegated click handler ────────────────────────────────────
		wrapper.addEventListener( 'click', function ( e ) {
			const btn = e.target.closest( 'button' );
			if ( ! btn ) return;

			if ( btn.classList.contains( 'tw-btn-next' ) || btn.id === 'tw-camp-step1-next' ) {
				e.preventDefault();
				if ( validateStep( current ) ) {
					setStatus( '', false );
					NW_SFX.nav();
					showStep( current + 1 );
				}
				return;
			}
			if ( btn.classList.contains( 'tw-btn-prev' ) ) {
				e.preventDefault();
				if ( steps[ current ] ) clearFieldError( steps[ current ] );
				setStatus( '', false );
				NW_SFX.back();
				showStep( current - 1 );
				return;
			}
			if ( btn.classList.contains( 'tw-summary-edit' ) ) {
				const goto = parseInt( btn.dataset.goto, 10 );
				if ( ! isNaN( goto ) ) {
					const idx = steps.findIndex( s => parseInt( s.dataset.step, 10 ) === goto );
					if ( idx >= 0 ) { setStatus( '', false ); NW_SFX.nav(); showStep( idx ); }
				}
				return;
			}
		} );

		wrapper.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.type === 'radio' ) NW_SFX.select();
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
			if ( ! payload.name )        { setStatus( 'ERROR: Deployment name is required.', true ); NW_SFX.error(); return; }
			if ( ! payload.gm_style )    { setStatus( 'ERROR: GM Protocol is required.', true );     NW_SFX.error(); return; }
			if ( ! payload.game_mode )   { setStatus( 'ERROR: Operative mode is required.', true );  NW_SFX.error(); return; }
			if ( ! payload.game_length ) { setStatus( 'ERROR: Operation scope is required.', true ); NW_SFX.error(); return; }
			if ( ! payload.world_type )  { setStatus( 'ERROR: Threat calibration is required.', true ); NW_SFX.error(); return; }
			if ( ! payload.priority )    { setStatus( 'ERROR: Mission priority is required.', true ); NW_SFX.error(); return; }

			submitBtn.disabled    = true;
			submitBtn.textContent = 'UPLINK IN PROGRESS…';
			setStatus( '', false );
			NW_SFX.deploy();
			spinner.show();

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
						spinner.hide();
						if ( json.success ) {
							setStatus( '// DEPLOYMENT ONLINE: ' + ( json.data.campaign_id || '' ), false );
							setTimeout( () => { window.location.href = campaignsUrl; }, 1800 );
						} else {
							const msg = ( json.data && json.data.message ) || json.message || 'Unknown error';
							setStatus( 'ERROR: ' + msg, true );
							NW_SFX.error();
							submitBtn.disabled    = false;
							submitBtn.textContent = '▶ UPLINK DEPLOYMENT';
						}
					}, wait );
				} )
				.catch( function ( err ) {
					spinner.hide();
					setStatus( 'ERROR: Network failure — ' + err.message, true );
					NW_SFX.error();
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
