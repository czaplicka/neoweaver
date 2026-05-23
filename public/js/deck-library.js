/* global nwDeckConfig */
( function () {
	'use strict';

	// ── Wait for DOM ──────────────────────────────────────────────────────────
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
		const MIN = ( cfg.limits && cfg.limits.minActive ) ? cfg.limits.minActive : 20;
		const MAX = ( cfg.limits && cfg.limits.maxActive ) ? cfg.limits.maxActive : 50;

		// ── Drag & drop ───────────────────────────────────────────────────────
		let dragged = null;

		root.addEventListener( 'dragstart', function ( e ) {
			const card = e.target.closest( '.cyber-card' );
			if ( ! card ) return;
			// Block dragging IN-GAME cards (they're read-only in this view)
			if ( card.dataset.cardLocation === 'active' ) {
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
				if ( dragged.parentElement === container ) return; // already here

				// Enforce MAX when moving to active
				if ( container === activeContainer ) {
					const count = activeContainer.querySelectorAll( '.cyber-card' ).length;
					if ( count >= MAX ) {
						showWarning( 'Active deck is full (' + MAX + ' cards max).' );
						return;
					}
				}

				// Move card
				container.appendChild( dragged );
				dragged.dataset.cardLocation = container === activeContainer ? 'active' : 'library';
				updateEmptyNotes();
				validateDeck();
			} );
		} );

		// ── Double-click to move ──────────────────────────────────────────────
		root.addEventListener( 'dblclick', function ( e ) {
			const card = e.target.closest( '.cyber-card' );
			if ( ! card ) return;
			if ( card.dataset.cardLocation === 'active' ) return; // no moving in-game cards

			const isInActive = card.parentElement === activeContainer;

			if ( ! isInActive ) {
				// Library → Active: check MAX
				const count = activeContainer.querySelectorAll( '.cyber-card' ).length;
				if ( count >= MAX ) {
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
			validateDeck();
		} );

		// ── Save ──────────────────────────────────────────────────────────────
		saveBtn.addEventListener( 'click', function () {
			if ( ! validateDeck( true ) ) return;

			const characterId = root.dataset.characterId;

			// Collect only library-section cards (IN GAME cards are not moved via this UI)
			const activeIds  = collectIds( activeContainer );
			const libraryIds = collectIds( libraryContainer );

			saveBtn.classList.add( 'is-saving' );
			saveBtn.textContent = 'SYNCING...';

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( {
					action:       'nw_save_deck',
					nonce:        cfg.nonce,
					character_id: characterId,
					active_ids:   JSON.stringify( activeIds ),
					library_ids:  JSON.stringify( libraryIds ),
				} ),
			} )
				.then( r => r.json() )
				.then( function ( data ) {
					if ( data.success ) {
						saveBtn.textContent = 'SYNCED ✓';
						setTimeout( () => ( saveBtn.textContent = 'SYNC WITH TERMINAL' ), 2500 );
						hideWarning();
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
				} );
		} );

		// ── Helpers ───────────────────────────────────────────────────────────

		function collectIds( container ) {
			return Array.from(
				container.querySelectorAll( '.cyber-card[data-instance-id]' )
			).map( c => c.dataset.instanceId );
		}

		function validateDeck( silent ) {
			const count = activeContainer.querySelectorAll( '.cyber-card' ).length;
			if ( count < MIN ) {
				if ( ! silent ) showWarning( 'Add at least ' + MIN + ' cards to active deck. (' + count + '/' + MIN + ')' );
				saveBtn.disabled = true;
				return false;
			}
			if ( count > MAX ) {
				if ( ! silent ) showWarning( 'Too many cards in active deck (' + count + '/' + MAX + ').' );
				saveBtn.disabled = true;
				return false;
			}
			saveBtn.disabled = false;
			hideWarning();
			return true;
		}

		function showWarning( msg ) {
			warningBox.textContent = msg;
			warningBox.classList.add( 'is-visible' );
		}

		function hideWarning() {
			warningBox.classList.remove( 'is-visible' );
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

		// Initial validation (disable save if deck too small on load)
		validateDeck( false );
	}
} )();
