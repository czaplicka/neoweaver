/* global nwDeckConfig */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', initDeckBuilder );

	function initDeckBuilder() {
		const root = document.querySelector( '[data-deck-builder-root]' );
		if ( ! root ) return;

		const activeContainer  = document.getElementById( 'active-deck' );
		const libraryContainer = document.getElementById( 'library-deck' );
		const warningBox       = document.getElementById( 'deck-warning' );
		const saveBtn          = document.getElementById( 'save-deck-btn' );

		if ( ! activeContainer || ! libraryContainer || ! saveBtn ) return;

		const cfg = ( typeof nwDeckConfig !== 'undefined' ) ? nwDeckConfig : {};
		const MIN = cfg.limits?.minActive ?? 20;
		const MAX = cfg.limits?.maxActive ?? 50;

		// Karty już w bufferze (IN GAME) — liczymy je do limitu, ale nie ruszamy
		function countInGame() {
			return activeContainer.querySelectorAll( '.cyber-card[data-buffer-id]' ).length;
		}

		// Karty przeciągnięte z library do active (nowe, mają data-instance-id)
		function countPending() {
			return activeContainer.querySelectorAll( '.cyber-card[data-instance-id]' ).length;
		}

		function countActive() {
			return activeContainer.querySelectorAll( '.cyber-card' ).length;
		}

		// ── Drag & drop ───────────────────────────────────────────────────────
		let dragged = null;

		root.addEventListener( 'dragstart', function ( e ) {
			const card = e.target.closest( '.cyber-card' );
			if ( ! card ) return;
			// Karty IN GAME (buffer) są read-only
			if ( card.dataset.bufferId ) {
				e.preventDefault();
				return;
			}
			dragged = card;
			setTimeout( () => card.classList.add( 'is-dragging' ), 0 );
			e.dataTransfer.effectAllowed = 'move';
		} );

		root.addEventListener( 'dragend', function ( e ) {
			const card = e.target.closest( '.cyber-card' );
			if ( card ) card.classList.remove( 'is-dragging' );
			[ activeContainer, libraryContainer ].forEach( c => c.classList.remove( 'drag-over' ) );
			dragged = null;
		} );

		[ activeContainer, libraryContainer ].forEach( function ( container ) {
			container.addEventListener( 'dragover', function ( e ) {
				if ( dragged ) {
					e.preventDefault();
					container.classList.add( 'drag-over' );
				}
			} );

			container.addEventListener( 'dragleave', function () {
				container.classList.remove( 'drag-over' );
			} );

			container.addEventListener( 'drop', function ( e ) {
				e.preventDefault();
				container.classList.remove( 'drag-over' );
				if ( ! dragged ) return;
				if ( dragged.parentElement === container ) return;

				if ( container === activeContainer ) {
					if ( countActive() >= MAX ) {
						showWarning( 'Active deck is full (' + MAX + ' cards max).' );
						return;
					}
				}

				container.appendChild( dragged );
				dragged.dataset.cardLocation = container === activeContainer ? 'active' : 'library';
				updateEmptyNotes();
				validateDeck( false );
			} );
		} );

		// ── Double-click to move ──────────────────────────────────────────────
		root.addEventListener( 'dblclick', function ( e ) {
			const card = e.target.closest( '.cyber-card' );
			if ( ! card ) return;
			// Buffer cards are read-only
			if ( card.dataset.bufferId ) return;

			const isInActive = card.parentElement === activeContainer;

			if ( ! isInActive ) {
				if ( countActive() >= MAX ) {
					showWarning( 'Active deck is full (' + MAX + ' cards max).' );
					return;
				}
				activeContainer.appendChild( card );
				card.dataset.cardLocation = 'active';
			} else {
				libraryContainer.appendChild( card );
				card.dataset.cardLocation = 'library';
			}

			updateEmptyNotes();
			validateDeck( false );
		} );

		// ── Save ──────────────────────────────────────────────────────────────
		saveBtn.addEventListener( 'click', function () {
			if ( ! validateDeck( false ) ) return;

			const characterId = root.dataset.characterId;

			// Wysyłamy WSZYSTKIE instance-id z active (przeciągnięte z library)
			// + buffer-id już w grze (żeby handler wiedział co zostawić)
			const newActiveIds    = collectByAttr( activeContainer, 'data-instance-id' );
			const existingBufIds  = collectByAttr( activeContainer, 'data-buffer-id' );

			saveBtn.classList.add( 'is-saving' );
			saveBtn.textContent = 'SYNCING...';
			saveBtn.disabled = true;

			fetch( cfg.ajaxUrl, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( {
					action:           'tw_save_deck',
					nonce:            cfg.nonce,
					character_id:     characterId,
					active:           newActiveIds.join( ',' ),
					keep_buffer_ids:  existingBufIds.join( ',' ),
				} ),
			} )
				.then( r => r.json() )
				.then( function ( data ) {
					if ( data.success ) {
						showSuccess( 'SYNCED ✓' );
						setTimeout( () => {
							saveBtn.textContent = 'SYNC WITH TERMINAL';
							hideWarning();
						}, 2500 );
					} else {
						showWarning( data.data || 'Sync failed. Try again.' );
						saveBtn.textContent = 'SYNC WITH TERMINAL';
					}
				} )
				.catch( function () {
					showWarning( 'Connection error. Try again.' );
					saveBtn.textContent = 'SYNC WITH TERMINAL';
				} )
				.finally( function () {
					saveBtn.classList.remove( 'is-saving' );
					saveBtn.disabled = false;
				} );
		} );

		// ── Helpers ───────────────────────────────────────────────────────────

		function collectByAttr( container, attr ) {
			return Array.from(
				container.querySelectorAll( '.cyber-card[' + attr + ']' )
			).map( c => c.getAttribute( attr ) ).filter( Boolean );
		}

		function validateDeck( silent ) {
			const count = countActive();
			if ( count < MIN ) {
				if ( ! silent ) showWarning( 'Need at least ' + MIN + ' cards in active deck. (' + count + '/' + MIN + ')' );
				saveBtn.disabled = true;
				return false;
			}
			if ( count > MAX ) {
				if ( ! silent ) showWarning( 'Too many cards (' + count + '/' + MAX + ').' );
				saveBtn.disabled = true;
				return false;
			}
			saveBtn.disabled = false;
			hideWarning();
			return true;
		}

		function showWarning( msg ) {
			warningBox.style.color = '#ff4444';
			warningBox.textContent = msg;
			warningBox.classList.add( 'is-visible' );
		}

		function showSuccess( msg ) {
			warningBox.style.color = '#adff00';
			warningBox.textContent = msg;
			warningBox.classList.add( 'is-visible' );
		}

		function hideWarning() {
			warningBox.classList.remove( 'is-visible' );
			warningBox.textContent = '';
		}

		function updateEmptyNotes() {
			updateNote( activeContainer, 'No cards in active play.' );
			updateNote( libraryContainer, 'Library is empty.' );
		}

		function updateNote( container, text ) {
			const hasCards = container.querySelector( '.cyber-card' );
			let note = container.querySelector( '.deck-empty-note' );
			if ( hasCards ) {
				if ( note ) note.remove();
			} else {
				if ( ! note ) {
					note = document.createElement( 'p' );
					note.className = 'deck-empty-note';
					note.textContent = text;
					container.appendChild( note );
				}
			}
		}

		validateDeck( true );
	}
} )();
