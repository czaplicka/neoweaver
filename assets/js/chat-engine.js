/**
 * NEOWEAVER — CHAT ENGINE v2.0
 *
 * Jeden plik = jeden WebSocket. Łączy chat-gm-dispatcher.js + realtime listener.
 *
 * Architektura:
 *   [gracz pisze] → sendMessage() → REST /neoweaver/v1/ai-chat → PHP (ai-engine)
 *   PHP wywołuje GPT, przetwarza tagi, wysyła realtime.send()
 *   Supabase Realtime push → renderMessage() + processTags() + updateHUD()
 *
 * Wymaga w window.nwChat (wp_localize_script):
 *   supabaseUrl   — URL projektu
 *   supabaseKey   — anon/publishable key
 *   restUrl       — /wp-json/neoweaver/v1/ai-chat
 *   nonce         — wp_create_nonce('wp_rest')
 *   sessionId     — UUID bieżącej sesji
 *   charId        — UUID postaci
 *   channelId     — UUID kanału czatu
 *   campaignId    — UUID kampanii (opcjonalnie)
 *
 * Kompatybilność: Chrome 80+, Firefox 75+, Safari 13.1+
 * Zależność: window.supabase (CDN @supabase/supabase-js v2)
 */

( function () {
	'use strict';

	// ============================================================
	// KONFIGURACJA
	// ============================================================

	const cfg = window.nwChat || {};

	// Fallback na starsze zmienne (chat-gm-dispatcher kompatybilność)
	if ( ! cfg.charId && window.twAdventureData ) {
		cfg.charId     = window.twAdventureData.active_character_id || window.twAdventureData.char_id || '';
		cfg.sessionId  = window.twAdventureData.active_session_id   || '';
		cfg.campaignId = window.twAdventureData.active_campaign_id  || '';
	}
	if ( ! cfg.nonce && window.twChatData ) {
		cfg.nonce   = window.twChatData.nonce    || '';
		cfg.restUrl = window.twChatData.ajax_url || '';
	}
	if ( ! cfg.channelId && window.activeChannelId ) {
		cfg.channelId = window.activeChannelId;
	}

	if ( ! cfg.supabaseUrl || ! cfg.supabaseKey ) {
		console.error( '[NW Chat] Brak nwChat.supabaseUrl / supabaseKey' );
		return;
	}
	if ( ! cfg.restUrl ) {
		console.error( '[NW Chat] Brak nwChat.restUrl' );
		return;
	}

	// ============================================================
	// ELEMENTY DOM
	// ============================================================

	// Obsługujemy oba zestawy ID: nowe (nw-*) i stare (chat-input, send-btn)
	const chatEl  = document.getElementById( 'nw-chat-messages' ) || document.getElementById( 'chat-messages' );
	const inputEl = document.getElementById( 'nw-chat-input' )    || document.getElementById( 'chat-input' );
	const sendBtn = document.getElementById( 'nw-chat-send' )     || document.getElementById( 'send-btn' );
	const formEl  = document.getElementById( 'nw-chat-form' );

	if ( ! chatEl || ! inputEl ) {
		console.error( '[NW Chat] Brak elementów DOM czatu' );
		return;
	}

	// ============================================================
	// SUPABASE CLIENT
	// ============================================================

	const { createClient } = window.supabase;
	const sb = createClient( cfg.supabaseUrl, cfg.supabaseKey );

	// Eksportuj dla innych modułów (kompatybilność z window.twSupabase)
	if ( ! window.twSupabase ) { window.twSupabase = sb; }

	// ============================================================
	// REALTIME — jeden channel, wiele eventów
	// ============================================================

	const channelName = 'game:' + ( cfg.sessionId || cfg.channelId || 'global' );

	const realtimeChannel = sb.channel( channelName )

		// Odpowiedź GM — narracja + tagi
		.on( 'broadcast', { event: 'gm_response' }, function ( payload ) {
			const data = payload.payload || {};
			if ( data.text ) { renderMessage( 'gm', data.text ); }
			if ( data.tags && data.tags.length ) { processTags( data.tags ); }
			setInputBusy( false );
		} )

		// Batch HUD update (hp, mp, gold, entropy, satiety, hydration)
		.on( 'broadcast', { event: 'hud_update' }, function ( payload ) {
			updateHUDBatch( payload.payload || {} );
		} )

		// Błąd po stronie PHP / GPT
		.on( 'broadcast', { event: 'gm_error' }, function ( payload ) {
			const data = payload.payload || {};
			showError( data.message || 'GM nie odpowiada — spróbuj ponownie.' );
			setInputBusy( false );
		} )

		// Zmiana lokacji (push z serwera zamiast — lub obok — tagu #LOC)
		.on( 'broadcast', { event: 'location_change' }, function ( payload ) {
			const data = payload.payload || {};
			document.dispatchEvent( new CustomEvent( 'twLocationChange', { detail: { locationId: data.location_id } } ) );
		} )

		.subscribe( function ( status ) {
			if ( status === 'SUBSCRIBED' ) {
				console.log( '[NW Chat] Realtime ✔', channelName );
				// Eksport dla innych modułów
				window.chatSubscription = realtimeChannel;
			}
			if ( status === 'CHANNEL_ERROR' || status === 'TIMED_OUT' ) {
				console.warn( '[NW Chat] Realtime error:', status );
			}
		} );

	// ============================================================
	// DISPATCHER — event listeners (form + stary send-btn)
	// ============================================================

	// Nowy formularz (nw-chat-form)
	if ( formEl ) {
		formEl.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			hanldeSubmit();
		} );
	}

	// Stary przycisk send-btn (capture phase — przed starym handlerem chat-realtime)
	if ( sendBtn && ! formEl ) {
		document.addEventListener( 'click', function ( e ) {
			if ( e.target && ( e.target.id === 'send-btn' || e.target.id === 'nw-chat-send' ) ) {
				e.stopImmediatePropagation();
				hanldeSubmit();
			}
		}, true );
	}

	// Enter w inpucie
	inputEl.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			e.stopImmediatePropagation();
			hanldeSubmit();
		}
	}, true );

	// Flaga żeby nie bindować dwa razy (kompatybilność z initGMDispatcher)
	window._twGMDispatcherBound = true;

	function hanldeSubmit() {
		const text = inputEl.value.trim();
		if ( ! text ) { return; }

		// Optimistic UI: wyświetl wiadomość gracza natychmiast
		renderMessage( 'player', text );
		setInputBusy( true );
		inputEl.value = '';
		if ( inputEl.style ) { inputEl.style.height = 'auto'; }

		sendMessage( text );
	}

	// ============================================================
	// SEND — REST API call
	// ============================================================

	function sendMessage( text ) {
		fetch( cfg.restUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   cfg.nonce,
			},
			body: JSON.stringify( {
				message:     text,
				session_id:  cfg.sessionId  || '',
				char_id:     cfg.charId     || '',
				channel_id:  cfg.channelId  || '',
				campaign_id: cfg.campaignId || '',
			} ),
		} )
		.then( function ( res ) {
			if ( ! res.ok ) {
				res.json()
					.then( function ( err ) { showError( err.message || 'Błąd serwera (' + res.status + ')' ); } )
					.catch( function () { showError( 'Błąd serwera (' + res.status + ')' ); } );
				setInputBusy( false );
			}
			// Jeśli 202 — czekamy na Realtime push (input nadal zablokowany)
		} )
		.catch( function ( err ) {
			console.error( '[NW Chat] Fetch error:', err );
			showError( 'Brak połączenia z serwerem.' );
			setInputBusy( false );
		} );
	}

	// ============================================================
	// RENDERER
	// ============================================================

	/**
	 * Wyświetla babelkę w czacie.
	 * Kompatybilność: jeśli istnieje window.appendToPlayerChat (stary moduł),
	 * używa go zamiast własnego renderera.
	 *
	 * @param {'player'|'gm'|'system'|'error'} role
	 * @param {string} text
	 */
	function renderMessage( role, text ) {
		if ( ! text ) { return; }

		// Fallback do starego appendToPlayerChat jeśli istnieje
		if ( typeof window.appendToPlayerChat === 'function' && ( role === 'gm' || role === 'player' ) ) {
			window.appendToPlayerChat( text, role, { created_at: new Date().toISOString() } );
			return;
		}

		const bubble = document.createElement( 'div' );
		bubble.classList.add( 'nw-message', 'nw-message--' + role );

		if ( role === 'gm' ) {
			const label = document.createElement( 'span' );
			label.className = 'nw-message__label';
			label.textContent = 'GM';
			bubble.appendChild( label );
		}

		const textNode = document.createElement( 'span' );
		textNode.className = 'nw-message__text';
		textNode.textContent = text;
		bubble.appendChild( textNode );

		chatEl.appendChild( bubble );
		chatEl.scrollTop = chatEl.scrollHeight;
	}

	// ============================================================
	// TAG PROCESSOR
	// ============================================================

	/**
	 * Obsługuje tagi z tw_ai_gm() — PHP wyciął je z tekstu,
	 * JS reaguje na nie aktualizacją UI.
	 * Emituje CustomEvent 'twTagsReceived' dla innych modułów.
	 *
	 * @param {Array<{tag:string, val:string|null}>} tags
	 */
	function processTags( tags ) {
		// Emituj dla innych modułów (HUD, mapa, inventory)
		document.dispatchEvent( new CustomEvent( 'twTagsReceived', {
			detail: { tags: tags, charId: cfg.charId }
		} ) );

		tags.forEach( function ( item ) {
			const tag = item.tag;
			const val = item.val || null;

			switch ( tag ) {

				case 'HP_CHANGE':
					updateHUDDelta( 'hp', parseInt( val, 10 ) );
					break;

				case 'MP_CHANGE':
					updateHUDDelta( 'mp', parseInt( val, 10 ) );
					break;

				case 'GOLD_CHANGE':
					updateHUDDelta( 'gold', parseInt( val, 10 ) );
					break;

				case 'ENTROPY_UP':
					updateHUDDelta( 'entropy', parseInt( val, 10 ) );
					break;

				case 'STATUS_ADD':
					if ( val ) { addStatusBadge( val ); }
					break;

				case 'STATUS_REMOVE':
					if ( val ) { removeStatusBadge( val ); }
					break;

				case 'LOC':
					document.dispatchEvent( new CustomEvent( 'twLocationChange', { detail: { locationId: val } } ) );
					break;

				case 'SESSION_END':
					setInputBusy( true ); // permanentnie blokuje
					renderMessage( 'system', 'Sesja zakończona.' );
					document.dispatchEvent( new CustomEvent( 'twSessionEnd' ) );
					break;

				default:
					// Nieznany tag — dispatch, inne moduły mogą obsłużyć
					document.dispatchEvent( new CustomEvent( 'nw:tag', { detail: item } ) );
			}
		} );
	}

	// ============================================================
	// HUD UPDATER
	// ============================================================

	/**
	 * Delta update — dodaje wartość do aktualnej.
	 * Szuka [data-hud="key"] i [data-nw-hud="key"].
	 */
	function updateHUDDelta( key, delta ) {
		if ( isNaN( delta ) ) { return; }
		const el = document.querySelector( '[data-hud="' + key + '"]' )
		       || document.querySelector( '[data-nw-hud="' + key + '"]' );
		if ( ! el ) { return; }

		const current = parseInt( el.dataset.current || el.textContent.replace( /[^\d-]/g, '' ), 10 ) || 0;
		const newVal  = current + delta;

		el.dataset.current = newVal;
		if ( el.tagName === 'PROGRESS' ) { el.value = newVal; }
		else { el.textContent = newVal; }
	}

	/**
	 * Absolute update — ustawia konkretną wartość (z hud_update broadcast).
	 */
	function updateHUDAbsolute( key, value ) {
		const el = document.querySelector( '[data-hud="' + key + '"]' )
		       || document.querySelector( '[data-nw-hud="' + key + '"]' );
		if ( ! el ) { return; }
		el.dataset.current = value;
		if ( el.tagName === 'PROGRESS' ) { el.value = value; }
		else { el.textContent = value; }
	}

	/**
	 * Batch update z Realtime hud_update event.
	 * @param {Object} data  { hp:X, maxhp:Y, mp:Z, gold:W, entropy:E }
	 */
	function updateHUDBatch( data ) {
		Object.keys( data ).forEach( function ( key ) {
			updateHUDAbsolute( key, data[ key ] );
		} );
	}

	// ============================================================
	// STATUS BADGES
	// ============================================================

	function addStatusBadge( status ) {
		const container = document.querySelector( '[data-hud="status-badges"]' );
		if ( ! container ) { return; }
		if ( container.querySelector( '[data-status="' + status + '"]' ) ) { return; } // już istnieje
		const badge = document.createElement( 'span' );
		badge.className  = 'tw-status-badge';
		badge.dataset.status = status;
		badge.textContent = status.toLowerCase().replace( /_/g, ' ' );
		container.appendChild( badge );
	}

	function removeStatusBadge( status ) {
		const badge = document.querySelector( '[data-status="' + status + '"]' );
		if ( badge ) { badge.remove(); }
	}

	// ============================================================
	// HELPERS
	// ============================================================

	function setInputBusy( busy ) {
		inputEl.disabled = busy;
		if ( sendBtn ) { sendBtn.disabled = busy; }
		if ( sendBtn ) { sendBtn.classList.toggle( 'is-loading', busy ); }
		if ( ! busy ) { inputEl.focus(); }
	}

	function showError( msg ) {
		renderMessage( 'error', '⚠️ ' + msg );
	}

	// Cleanup
	window.addEventListener( 'beforeunload', function () {
		sb.removeChannel( realtimeChannel );
	} );

	console.log( '[NW Chat] chat-engine.js v2.0 loaded | channel:', channelName );

} )();
