/**
 * NEOWEAVER — CHAT ENGINE
 *
 * Jeden plik obsługuje:
 *   - Supabase Realtime (listener odpowiedzi GM)
 *   - Dispatcher (wysyłanie wiadomości do WP REST API)
 *   - Renderer (wyświetlanie wiadomości w czacie)
 *   - HUD updater (HP, MP, Gold, Entropy bez reload)
 *   - Tag parser (wyciąganie #TAGÓW z odpowiedzi GM)
 *
 * Wymagane dane z PHP (wp_localize_script lub data-* na #nw-chat):
 *   nwChat.supabaseUrl    — URL projektu Supabase
 *   nwChat.supabaseKey    — anon/publishable key
 *   nwChat.restUrl        — URL endpointu: /wp-json/neoweaver/v1/ai-chat
 *   nwChat.nonce          — wp_create_nonce('wp_rest')
 *   nwChat.sessionId      — UUID sesji
 *   nwChat.charId         — UUID postaci
 *   nwChat.channelId      — UUID kanału / rozmowy
 *
 * Zależności: @supabase/supabase-js (CDN lub bundler)
 */

( function () {
	'use strict';

	// ============================================================
	// KONFIGURACJA — dane z PHP
	// ============================================================

	const cfg = window.nwChat || {};

	if ( ! cfg.supabaseUrl || ! cfg.supabaseKey ) {
		console.error( '[NW Chat] Brak nwChat.supabaseUrl / nwChat.supabaseKey' );
		return;
	}

	// ============================================================
	// ELEMENTY DOM
	// ============================================================

	const chatEl    = document.getElementById( 'nw-chat-messages' );
	const formEl    = document.getElementById( 'nw-chat-form' );
	const inputEl   = document.getElementById( 'nw-chat-input' );
	const sendBtn   = document.getElementById( 'nw-chat-send' );

	if ( ! chatEl || ! formEl || ! inputEl ) {
		console.error( '[NW Chat] Brakujące elementy DOM (#nw-chat-messages, #nw-chat-form, #nw-chat-input)' );
		return;
	}

	// ============================================================
	// SUPABASE CLIENT
	// ============================================================

	const { createClient } = window.supabase;
	const sb = createClient( cfg.supabaseUrl, cfg.supabaseKey );

	// ============================================================
	// REALTIME — jeden channel, wiele event listenerów
	// ============================================================

	const channelName = 'game:' + cfg.sessionId;

	const realtimeChannel = sb.channel( channelName )

		// Odpowiedź GM — narracja
		.on( 'broadcast', { event: 'gm_response' }, function ( payload ) {
			const data = payload.payload || {};
			renderMessage( 'gm', data.text || '' );
			if ( data.tags && data.tags.length ) {
				processTags( data.tags );
			}
			setInputEnabled( true );
		} )

		// Aktualizacja HUD (HP, MP, Gold, Entropy)
		.on( 'broadcast', { event: 'hud_update' }, function ( payload ) {
			updateHUD( payload.payload || {} );
		} )

		// Błąd po stronie PHP / GPT
		.on( 'broadcast', { event: 'gm_error' }, function ( payload ) {
			const data = payload.payload || {};
			renderMessage( 'error', data.message || 'Wystąpił błąd. Spróbuj ponownie.' );
			setInputEnabled( true );
		} )

		.subscribe( function ( status ) {
			if ( status === 'SUBSCRIBED' ) {
				console.log( '[NW Chat] Realtime connected:', channelName );
			}
			if ( status === 'CHANNEL_ERROR' || status === 'TIMED_OUT' ) {
				console.warn( '[NW Chat] Realtime error:', status );
			}
		} );

	// ============================================================
	// DISPATCHER — wysyłanie wiadomości
	// ============================================================

	formEl.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		const text = inputEl.value.trim();
		if ( ! text ) { return; }

		renderMessage( 'player', text );
		setInputEnabled( false );
		inputEl.value = '';

		sendMessage( text );
	} );

	function sendMessage( text ) {
		fetch( cfg.restUrl, {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   cfg.nonce,
			},
			body: JSON.stringify( {
				message:    text,
				session_id: cfg.sessionId,
				char_id:    cfg.charId,
				channel_id: cfg.channelId,
			} ),
		} )
		.then( function ( res ) {
			if ( ! res.ok ) {
				// Błąd HTTP — re-enable od razu, bo Realtime push nie przyjdzie
				res.json().then( function ( err ) {
					renderMessage( 'error', err.message || 'Błąd serwera (' + res.status + ')' );
					setInputEnabled( true );
				} ).catch( function () {
					renderMessage( 'error', 'Błąd serwera (' + res.status + ')' );
					setInputEnabled( true );
				} );
			}
			// Jeśli ok (202 processing) — czekamy na Realtime push
		} )
		.catch( function ( err ) {
			console.error( '[NW Chat] Fetch error:', err );
			renderMessage( 'error', 'Brak połączenia z serwerem.' );
			setInputEnabled( true );
		} );
	}

	// ============================================================
	// RENDERER — wyświetlanie wiadomości
	// ============================================================

	function renderMessage( role, text ) {
		if ( ! text ) { return; }

		const bubble = document.createElement( 'div' );
		bubble.classList.add( 'nw-message', 'nw-message--' + role );
		bubble.textContent = text;

		if ( role === 'gm' ) {
			const label = document.createElement( 'span' );
			label.classList.add( 'nw-message__label' );
			label.textContent = 'GM';
			bubble.prepend( label );
		}

		chatEl.appendChild( bubble );
		chatEl.scrollTop = chatEl.scrollHeight;
	}

	// ============================================================
	// TAG PROCESSOR — aktualizacje stanu gry
	// ============================================================

	/**
	 * Przetwarza tagi zwrócone przez tw_ai_gm() po stronie PHP.
	 * Tagi są już wyciągnięte z tekstu przez PHP — tutaj tylko
	 * aktualizujemy UI na podstawie ich listy.
	 *
	 * @param {Array} tags  [ { tag: 'HP_CHANGE', val: '-5' }, ... ]
	 */
	function processTags( tags ) {
		tags.forEach( function ( item ) {
			switch ( item.tag ) {

				case 'HP_CHANGE':
					updateHUDValue( 'hp', parseInt( item.val, 10 ), true );
					break;

				case 'MP_CHANGE':
					updateHUDValue( 'mp', parseInt( item.val, 10 ), true );
					break;

				case 'GOLD_CHANGE':
					updateHUDValue( 'gold', parseInt( item.val, 10 ), true );
					break;

				case 'ENTROPY_UP':
					updateHUDValue( 'entropy', parseInt( item.val, 10 ), true );
					break;

				case 'LOC':
					// Zmiana lokacji — możesz tu np. odświeżyć minimapy lub nazwę
					document.dispatchEvent( new CustomEvent( 'nw:location_change', { detail: { location_id: item.val } } ) );
					break;

				case 'STATUS_ADD':
					document.dispatchEvent( new CustomEvent( 'nw:status_add', { detail: { status: item.val } } ) );
					break;

				case 'SESSION_END':
					setInputEnabled( false );
					renderMessage( 'system', 'Sesja zakończona.' );
					break;

				default:
					// Nieznany tag — dispatch jako custom event dla innych modułów
					document.dispatchEvent( new CustomEvent( 'nw:tag', { detail: item } ) );
			}
		} );
	}

	// ============================================================
	// HUD UPDATER
	// ============================================================

	/**
	 * Aktualizuje wartość w HUD.
	 * @param {string}  key    'hp' | 'mp' | 'gold' | 'entropy'
	 * @param {number}  value  Wartość bezwzględna lub delta
	 * @param {boolean} isDelta  true = dodaj do aktualnej wartości
	 */
	function updateHUDValue( key, value, isDelta ) {
		const el = document.querySelector( '[data-nw-hud="' + key + '"]' );
		if ( ! el ) { return; }
		if ( isDelta ) {
			value = ( parseInt( el.textContent, 10 ) || 0 ) + value;
		}
		el.textContent = value;
	}

	/**
	 * Batch aktualizacja HUD (z Realtime hud_update).
	 * @param {Object} data  { hp, maxhp, mp, gold, entropy, ... }
	 */
	function updateHUD( data ) {
		Object.keys( data ).forEach( function ( key ) {
			updateHUDValue( key, data[ key ], false );
		} );
	}

	// ============================================================
	// HELPERS
	// ============================================================

	function setInputEnabled( enabled ) {
		inputEl.disabled = ! enabled;
		if ( sendBtn ) { sendBtn.disabled = ! enabled; }
		if ( enabled ) { inputEl.focus(); }
	}

	// Cleanup przy zamknięciu strony
	window.addEventListener( 'beforeunload', function () {
		sb.removeChannel( realtimeChannel );
	} );

} )();
