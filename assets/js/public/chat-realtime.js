/**
 * NEOWEAVER — chat-realtime.js
 *
 * Obsługuje czat gracza:
 *  - wysyłanie wiadomości do tw_ajax_chat_gm (WordPress AJAX)
 *  - wyświetlanie odpowiedzi GM
 *  - parsowanie tagów z odpowiedzi i aktualizacja HUD
 *  - blokowanie UI podczas oczekiwania
 *
 * Wymagane dane przekazane przez wp_localize_script w chat-realtime.php:
 *   window.twChatData = {
 *     ajaxUrl  : '/wp-admin/admin-ajax.php',
 *     nonce    : '...',
 *     charId   : '...',
 *     channelId: '...',
 *     sessionId: '...',      // opcjonalne
 *     campaignId: '...',     // opcjonalne
 *   }
 */

( function () {
	'use strict';

	// ----------------------------------------------------------------
	// Elementy DOM — czekamy na DOMContentLoaded
	// ----------------------------------------------------------------
	document.addEventListener( 'DOMContentLoaded', function () {
		const cfg = window.twChatData;
		if ( ! cfg ) {
			console.warn( '[NeoWeaver] twChatData nie zostało zdefiniowane.' );
			return;
		}

		const form    = document.getElementById( 'tw-chat-form' );
		const input   = document.getElementById( 'tw-chat-input' );
		const log     = document.getElementById( 'tw-chat-log' );
		const spinner = document.getElementById( 'tw-chat-spinner' );

		if ( ! form || ! input || ! log ) {
			console.warn( '[NeoWeaver] Nie znaleziono elementów czatu (#tw-chat-form, #tw-chat-input, #tw-chat-log).' );
			return;
		}

		// ----------------------------------------------------------------
		// Wysyłanie wiadomości
		// ----------------------------------------------------------------
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			const message = input.value.trim();
			if ( ! message ) return;

			// Wyświetl wiadomość gracza natychmiast
			appendMessage( 'player', message );
			input.value = '';
			setLoading( true );

			// Buduj FormData dla wp_ajax
			const data = new FormData();
			data.append( 'action',      'tw_chat_gm' );
			data.append( 'nonce',       cfg.nonce );
			data.append( 'message',     message );
			data.append( 'char_id',     cfg.charId );
			data.append( 'channel_id',  cfg.channelId );
			data.append( 'session_id',  cfg.sessionId  || '' );
			data.append( 'campaign_id', cfg.campaignId || '' );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				body:   data,
			} )
				.then( function ( res ) {
					if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
					return res.json();
				} )
				.then( function ( json ) {
					if ( json.success ) {
						const text     = json.data.text     || '';
						const tags     = json.data.tags     || [];
						const protocol = json.data.protocol || 'UNKNOWN';

						// Wyświetl odpowiedź GM
						appendMessage( 'gm', text, protocol );

						// Przetwórz tagi systemowe
						if ( tags.length > 0 ) {
							processTags( tags );
						}
					} else {
						const errMsg = json.data && json.data.message ? json.data.message : 'Nieznany błąd GM.';
						appendMessage( 'error', errMsg );
					}
				} )
				.catch( function ( err ) {
					console.error( '[NeoWeaver] Chat fetch error:', err );
					appendMessage( 'error', 'Błąd połączenia. Spróbuj ponownie.' );
				} )
				.finally( function () {
					setLoading( false );
				} );
		} );

		// Wyślij przez Enter (bez Shift)
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				form.requestSubmit();
			}
		} );

		// ----------------------------------------------------------------
		// Wyświetlanie wiadomości w logu
		// ----------------------------------------------------------------
		function appendMessage( type, text, protocol ) {
			const wrap = document.createElement( 'div' );
			wrap.classList.add( 'tw-chat-msg', 'tw-msg-' + type );

			if ( protocol && type === 'gm' ) {
				wrap.dataset.protocol = protocol.toLowerCase();
			}

			// Sanitacja: wyświetlamy jako tekst, nie HTML (zapobiega XSS)
			const pre = document.createElement( 'p' );
			pre.textContent = text;
			wrap.appendChild( pre );

			log.appendChild( wrap );
			log.scrollTop = log.scrollHeight;
		}

		// ----------------------------------------------------------------
		// Blokowanie UI
		// ----------------------------------------------------------------
		function setLoading( isLoading ) {
			input.disabled = isLoading;
			const btn = form.querySelector( 'button[type=submit]' );
			if ( btn ) btn.disabled = isLoading;
			if ( spinner ) spinner.hidden = ! isLoading;
		}

		// ----------------------------------------------------------------
		// Przetwarzanie tagów systemowych z odpowiedzi GM
		// Tagi mogą aktualizować HUD (HP, gold itp.) lub
		// przekierować do nowej lokacji.
		// ----------------------------------------------------------------
		function processTags( tags ) {
			tags.forEach( function ( tagObj ) {
				const tag = tagObj.tag || '';
				const val = tagObj.val || null;

				switch ( tag ) {

					case 'HP_CHANGE':
						updateHudValue( 'tw-hud-hp', parseInt( val, 10 ) );
						break;

					case 'GOLD_CHANGE':
						updateHudValue( 'tw-hud-gold', parseInt( val, 10 ) );
						break;

					case 'LOC':
						// Zmiana lokacji — opcjonalny callback dla głównego silnika
						document.dispatchEvent( new CustomEvent( 'tw:location_change', { detail: { locId: val } } ) );
						break;

					case 'ENTROPY_UP':
						updateHudDelta( 'tw-hud-entropy', parseInt( val, 10 ) );
						break;

					case 'STATUS_ADD':
						document.dispatchEvent( new CustomEvent( 'tw:status_add', { detail: { status: val } } ) );
						break;

					case 'SESSION_END':
						document.dispatchEvent( new CustomEvent( 'tw:session_end' ) );
						break;

					case 'ITEM_GET':
						document.dispatchEvent( new CustomEvent( 'tw:item_get', { detail: { itemId: val } } ) );
						break;

					default:
						// Nieznany tag — loguj tylko w trybie dev
						if ( window.twDebug ) {
							console.log( '[NeoWeaver] Tag nieobsługiwany:', tag, val );
						}
				}
			} );
		}

		// ----------------------------------------------------------------
		// Pomocniki HUD
		// ----------------------------------------------------------------

		/** Ustawia wartość bezwzględną w elemencie HUD (.tw-hud-val) */
		function updateHudValue( elId, delta ) {
			const el = document.getElementById( elId );
			if ( ! el ) return;
			const valEl = el.querySelector( '.tw-hud-val' );
			if ( ! valEl ) return;
			const current = parseInt( valEl.textContent, 10 ) || 0;
			valEl.textContent = current + delta;
		}

		/** Zmienia wartość numeryczną w elemencie HUD o delta */
		function updateHudDelta( elId, delta ) {
			updateHudValue( elId, delta );
		}

	} );
} )();
