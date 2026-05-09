/**
 * NeoWeaver Admin — Style Dictionary
 * Handles: load, filter, modal open/close, save, toggle, delete
 *
 * Enqueued with wp_localize_script as 'NW_SD':
 *   NW_SD.ajax_url — admin-ajax.php
 *   NW_SD.nonce    — nonce for all requests
 */

jQuery( function ( $ ) {
	'use strict';

	/* ---------------------------------------------------------------- */
	/*  State                                                            */
	/* ---------------------------------------------------------------- */

	var editId  = null;
	var allTags = [];

	var nonce    = typeof NW_SD !== 'undefined' ? NW_SD.nonce    : $( '#nw-nonce' ).val();
	var ajaxurl  = typeof NW_SD !== 'undefined' ? NW_SD.ajax_url : window.ajaxurl;

	/* ---------------------------------------------------------------- */
	/*  Helpers                                                          */
	/* ---------------------------------------------------------------- */

	function escH( s ) {
		return $( '<div>' ).text( String( s || '' ) ).html();
	}

	function badgeHtml( cat ) {
		return '<span class="nw-badge nw-badge-' + escH( cat ) + '">' + escH( cat ) + '</span>';
	}

	function showNotice( type, msg ) {
		var $n = $( '#nw-notice' );
		$n.removeClass( 'nw-notice-success nw-notice-error' )
			.addClass( 'nw-notice-' + type )
			.text( msg )
			.show();
		setTimeout( function () { $n.fadeOut(); }, 4000 );
	}

	/* ---------------------------------------------------------------- */
	/*  Load & render                                                    */
	/* ---------------------------------------------------------------- */

	function loadTags() {
		$( '#nw-sd-tbody' ).html(
			'<tr class="nw-loading-row"><td colspan="5"><div class="nw-spinner"></div> Loading tags…</td></tr>'
		);
		$.post(
			ajaxurl,
			{ action: 'nw_sd_get_all', nonce: nonce },
			function ( r ) {
				if ( ! r.success ) { showNotice( 'error', r.data ); return; }
				allTags = r.data || [];
				renderFiltered();
			}
		);
	}

	function renderFiltered() {
		var catF   = $( '#nw-filter-category' ).val();
		var actF   = $( '#nw-filter-active' ).val();
		var search = $( '#nw-filter-search' ).val().toLowerCase();

		var rows = allTags.filter( function ( t ) {
			if ( catF && t.category !== catF ) return false;
			if ( actF === '1' && ! t.is_active ) return false;
			if ( actF === '0' &&   t.is_active ) return false;
			if ( search && ! (
				t.tag_name.toLowerCase().includes( search ) ||
				t.interpretation_en.toLowerCase().includes( search )
			) ) return false;
			return true;
		} );

		renderTable( rows );
	}

	function renderTable( rows ) {
		var total    = allTags.length;
		var active   = 0;
		var inactive = 0;
		var catCounts = {};

		$.each( allTags, function ( _, t ) {
			if ( t.is_active ) { active++; } else { inactive++; }
			catCounts[ t.category ] = ( catCounts[ t.category ] || 0 ) + 1;
		} );

		$( '#nw-total' ).text( total );
		$( '#nw-active' ).text( active );
		$( '#nw-inactive' ).text( inactive );
		$( '.nw-cat-count' ).each( function () {
			var cat = $( this ).data( 'cat' );
			$( this ).text( catCounts[ cat ] || 0 );
		} );

		var html = '';

		if ( ! rows.length ) {
			html = '<tr><td colspan="5" style="text-align:center;padding:32px;color:#555;">No tags found.</td></tr>';
		} else {
			$.each( rows, function ( _, t ) {
				var interp  = t.interpretation_en || '';
				var preview = interp.length > 90 ? interp.substring( 0, 90 ) + '…' : interp;
				html +=
					'<tr data-id="' + escH( t.id ) + '">' +
					'<td><div class="nw-tag-name">' + escH( t.tag_name ) + '</div></td>' +
					'<td>' + badgeHtml( t.category ) + '</td>' +
					'<td><div class="nw-interp">' + escH( preview ) + '</div></td>' +
					'<td><label class="nw-toggle">' +
					'<input type="checkbox" class="nw-toggle-active" data-id="' + escH( t.id ) + '"' + ( t.is_active ? ' checked' : '' ) + '>' +
					'<span class="nw-toggle-slider"></span></label></td>' +
					'<td><div class="nw-row-actions">' +
					'<button class="nw-action-btn nw-edit-btn" data-id="' + escH( t.id ) + '">Edit</button>' +
					'</div></td>' +
					'</tr>';
			} );
		}

		$( '#nw-sd-tbody' ).html( html );
	}

	/* ---------------------------------------------------------------- */
	/*  Filters                                                          */
	/* ---------------------------------------------------------------- */

	$( '#nw-filter-category, #nw-filter-active' ).on( 'change', renderFiltered );
	$( '#nw-filter-search' ).on( 'input', renderFiltered );

	/* ---------------------------------------------------------------- */
	/*  Modal                                                            */
	/* ---------------------------------------------------------------- */

	function openModal( tag ) {
		editId = tag ? tag.id : null;
		$( '#nw-modal-title' ).text( tag ? 'Edit Style Tag' : 'New Style Tag' );
		$( '#nw-save-label' ).text( tag ? 'Save Tag' : 'Create Tag' );
		$( '#nw-delete-btn' ).toggle( !! tag );
		$( '#nw-field-id' ).val( tag ? tag.id : '' );
		$( '#nw-field-tag_name' ).val( tag ? tag.tag_name : '' );
		$( '#nw-field-category' ).val( tag ? tag.category : 'general' );
		$( '#nw-field-interpretation_en' ).val( tag ? tag.interpretation_en : '' );
		$( '#nw-field-is_active' ).prop( 'checked', tag ? tag.is_active : true );
		$( '#nw-modal-overlay' ).show();
		$( '#nw-field-tag_name' ).focus();
	}

	function closeModal() {
		$( '#nw-modal-overlay' ).hide();
		editId = null;
	}

	/* ---------------------------------------------------------------- */
	/*  Save                                                             */
	/* ---------------------------------------------------------------- */

	function saveTag() {
		var data = { action: 'nw_sd_save', nonce: nonce, tag: {} };
		$( '#nw-sd-form' ).serializeArray().forEach( function ( f ) {
			data.tag[ f.name ] = f.value;
		} );
		data.tag.is_active = $( '#nw-field-is_active' ).is( ':checked' ) ? '1' : '0';

		$( '#nw-save-btn' ).prop( 'disabled', true ).text( 'Saving…' );

		$.post( ajaxurl, data, function ( r ) {
			$( '#nw-save-btn' ).prop( 'disabled', false );
			$( '#nw-save-label' ).text( editId ? 'Save Tag' : 'Create Tag' );
			if ( ! r.success ) { showNotice( 'error', r.data ); return; }
			showNotice( 'success', editId ? 'Tag updated.' : 'Tag created.' );
			closeModal();
			loadTags();
		} );
	}

	/* ---------------------------------------------------------------- */
	/*  Toggle active                                                    */
	/* ---------------------------------------------------------------- */

	$( document ).on( 'change', '.nw-toggle-active', function () {
		var id    = $( this ).data( 'id' );
		var state = $( this ).is( ':checked' );
		$.post(
			ajaxurl,
			{ action: 'nw_sd_toggle', nonce: nonce, tag_id: id, is_active: state ? 1 : 0 },
			function ( r ) {
				if ( ! r.success ) { showNotice( 'error', r.data ); loadTags(); }
			}
		);
	} );

	/* ---------------------------------------------------------------- */
	/*  Delete                                                           */
	/* ---------------------------------------------------------------- */

	$( '#nw-delete-btn' ).on( 'click', function () {
		if ( ! editId || ! confirm( 'Delete this style tag? This cannot be undone.' ) ) return;
		$.post(
			ajaxurl,
			{ action: 'nw_sd_delete', nonce: nonce, tag_id: editId },
			function ( r ) {
				if ( ! r.success ) { showNotice( 'error', r.data ); return; }
				showNotice( 'success', 'Tag deleted.' );
				closeModal();
				loadTags();
			}
		);
	} );

	/* ---------------------------------------------------------------- */
	/*  Event bindings                                                   */
	/* ---------------------------------------------------------------- */

	$( '#nw-add-btn' ).on( 'click', function () { openModal( null ); } );
	$( '#nw-refresh-btn' ).on( 'click', loadTags );
	$( '#nw-modal-close, #nw-cancel-btn' ).on( 'click', closeModal );
	$( '#nw-modal-overlay' ).on( 'click', function ( e ) {
		if ( $( e.target ).is( '#nw-modal-overlay' ) ) closeModal();
	} );
	$( '#nw-save-btn' ).on( 'click', saveTag );

	$( document ).on( 'click', '.nw-edit-btn', function () {
		var id  = $( this ).data( 'id' );
		var tag = null;
		$.each( allTags, function ( _, t ) {
			if ( t.id === id ) { tag = t; return false; }
		} );
		if ( tag ) openModal( tag );
	} );

	/* ---------------------------------------------------------------- */
	/*  Init                                                             */
	/* ---------------------------------------------------------------- */

	loadTags();
} );
