/**
 * NeoWeaver Admin — Status Tags
 * Handles: load list, add/edit form, save, toggle active, delete
 * Depends on: jQuery, NW_ST (wp_localize_script)
 *
 * NW_ST.ajax_url  — admin-ajax.php URL
 * NW_ST.nonce     — nonce for all requests
 */

( function ( $ ) {
	'use strict';

	/* ---------------------------------------------------------------- */
	/*  State                                                            */
	/* ---------------------------------------------------------------- */

	let currentId   = null;
	let allTags     = [];

	/* ---------------------------------------------------------------- */
	/*  DOM refs                                                         */
	/* ---------------------------------------------------------------- */

	const $formWrap   = $( '#nw-status-tag-form-wrap' );
	const $tableWrap  = $( '#nw-status-tag-table-wrap' );
	const $formTitle  = $( '#nw-form-title' );
	const $notice     = $( '#nw-form-notice' );
	const $fieldName  = $( '#nw-field-name' );
	const $fieldDesc  = $( '#nw-field-description' );
	const $fieldColor = $( '#nw-field-color' );
	const $fieldIcon  = $( '#nw-field-icon' );

	/* ---------------------------------------------------------------- */
	/*  Helpers                                                          */
	/* ---------------------------------------------------------------- */

	function showNotice( msg, type ) {
		$notice
			.removeClass( 'nw-success nw-error' )
			.addClass( type === 'success' ? 'nw-success' : 'nw-error' )
			.text( msg );
	}

	function hideNotice() {
		$notice.removeClass( 'nw-success nw-error' ).text( '' ).hide();
	}

	function escHtml( str ) {
		if ( ! str ) return '';
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function resetForm() {
		currentId = null;
		$fieldName.val( '' );
		$fieldDesc.val( '' );
		$fieldColor.val( '#adff00' );
		$fieldIcon.val( '' );
		$formTitle.text( 'Add Status Tag' );
		hideNotice();
	}

	function populateForm( tag ) {
		currentId = tag.id;
		$fieldName.val( tag.name        || '' );
		$fieldDesc.val( tag.description || '' );
		$fieldColor.val( tag.color      || '#adff00' );
		$fieldIcon.val( tag.icon        || '' );
		$formTitle.text( 'Edit Status Tag' );
		hideNotice();
	}

	/* ---------------------------------------------------------------- */
	/*  Render table                                                     */
	/* ---------------------------------------------------------------- */

	function renderTable( tags ) {
		if ( ! tags || ! tags.length ) {
			$tableWrap.html( '<p style="color:#888;">No status tags found. Add your first one above.</p>' );
			return;
		}

		const rows = tags.map( function ( t ) {
			const activeClass = t.is_active ? 'nw-toggle-active' : 'nw-toggle-inactive';
			const activeLabel = t.is_active ? 'Active' : 'Inactive';
			const swatch      = '<span class="nw-color-swatch" style="background:' + escHtml( t.color ) + ';" title="' + escHtml( t.color ) + '"></span>';
			return (
				'<tr data-id="' + escHtml( t.id ) + '">' +
				'<td>' + swatch + ' ' + escHtml( t.name ) + '</td>' +
				'<td>' + escHtml( t.description || '—' ) + '</td>' +
				'<td>' + escHtml( t.color || '—' ) + '</td>' +
				'<td>' + escHtml( t.icon  || '—' ) + '</td>' +
				'<td><span class="' + activeClass + '" data-id="' + escHtml( t.id ) + '" data-state="' + ( t.is_active ? '1' : '0' ) + '">' + activeLabel + '</span></td>' +
				'<td class="nw-row-actions">' +
				'<button class="nw-row-edit" data-id="' + escHtml( t.id ) + '" title="Edit">✏</button>' +
				'<button class="nw-row-delete" data-id="' + escHtml( t.id ) + '" title="Delete">🗑</button>' +
				'</td>' +
				'</tr>'
			);
		} ).join( '' );

		$tableWrap.html(
			'<table>' +
			'<thead><tr>' +
			'<th>Name</th><th>Description</th><th>Color</th><th>Icon</th><th>Status</th><th>Actions</th>' +
			'</tr></thead>' +
			'<tbody>' + rows + '</tbody>' +
			'</table>'
		);
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX: load                                                       */
	/* ---------------------------------------------------------------- */

	function loadTags() {
		$tableWrap.html( '<p style="color:#888;">Loading…</p>' );
		$.post(
			NW_ST.ajax_url,
			{ action: 'nw_status_tags_load', nonce: NW_ST.nonce },
			function ( res ) {
				if ( res.success ) {
					allTags = res.data || [];
					renderTable( allTags );
				} else {
					$tableWrap.html( '<p style="color:#ff5050;">Error: ' + escHtml( res.data ) + '</p>' );
				}
			}
		);
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX: save                                                       */
	/* ---------------------------------------------------------------- */

	function saveTag() {
		const name = $.trim( $fieldName.val() );
		if ( ! name ) {
			showNotice( 'Tag Name is required.', 'error' );
			$fieldName.focus();
			return;
		}

		$( '#nw-save-tag-btn' ).prop( 'disabled', true ).text( 'Saving…' );

		$.post(
			NW_ST.ajax_url,
			{
				action:      'nw_status_tags_save',
				nonce:       NW_ST.nonce,
				id:          currentId || '',
				name:        name,
				description: $.trim( $fieldDesc.val() ),
				color:       $fieldColor.val(),
				icon:        $.trim( $fieldIcon.val() ),
			},
			function ( res ) {
				$( '#nw-save-tag-btn' ).prop( 'disabled', false ).text( 'Save Tag' );
				if ( res.success ) {
					showNotice( 'Tag saved successfully!', 'success' );
					resetForm();
					$formWrap.slideUp( 200 );
					loadTags();
				} else {
					showNotice( 'Error: ' + ( res.data || 'Unknown error' ), 'error' );
				}
			}
		);
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX: toggle active                                              */
	/* ---------------------------------------------------------------- */

	function toggleTag( id, currentState ) {
		const newState = currentState === '1' ? false : true;
		$.post(
			NW_ST.ajax_url,
			{ action: 'nw_status_tags_toggle', nonce: NW_ST.nonce, id: id, value: newState ? '1' : '0' },
			function ( res ) {
				if ( res.success ) {
					loadTags();
				} else {
					alert( 'Toggle failed: ' + ( res.data || 'Unknown error' ) );
				}
			}
		);
	}

	/* ---------------------------------------------------------------- */
	/*  AJAX: delete                                                     */
	/* ---------------------------------------------------------------- */

	function deleteTag( id ) {
		if ( ! window.confirm( 'Delete this status tag? This cannot be undone.' ) ) return;
		$.post(
			NW_ST.ajax_url,
			{ action: 'nw_status_tags_delete', nonce: NW_ST.nonce, id: id },
			function ( res ) {
				if ( res.success ) {
					loadTags();
				} else {
					alert( 'Delete failed: ' + ( res.data || 'Unknown error' ) );
				}
			}
		);
	}

	/* ---------------------------------------------------------------- */
	/*  Event bindings                                                   */
	/* ---------------------------------------------------------------- */

	// Show form for new tag
	$( '#nw-add-tag-btn' ).on( 'click', function () {
		resetForm();
		$formWrap.slideDown( 200 );
		$fieldName.focus();
	} );

	// Cancel
	$( '#nw-cancel-tag-btn' ).on( 'click', function () {
		resetForm();
		$formWrap.slideUp( 200 );
	} );

	// Save
	$( '#nw-save-tag-btn' ).on( 'click', saveTag );

	// Enter in name field triggers save
	$fieldName.on( 'keydown', function ( e ) {
		if ( e.key === 'Enter' ) { e.preventDefault(); saveTag(); }
	} );

	// Delegated: edit button
	$tableWrap.on( 'click', '.nw-row-edit', function () {
		const id  = $( this ).data( 'id' );
		const tag = allTags.find( function ( t ) { return t.id === id; } );
		if ( ! tag ) return;
		populateForm( tag );
		$formWrap.slideDown( 200 );
		$fieldName.focus();
	} );

	// Delegated: delete button
	$tableWrap.on( 'click', '.nw-row-delete', function () {
		deleteTag( $( this ).data( 'id' ) );
	} );

	// Delegated: toggle active pill
	$tableWrap.on( 'click', '.nw-toggle-active, .nw-toggle-inactive', function () {
		toggleTag( $( this ).data( 'id' ), String( $( this ).data( 'state' ) ) );
	} );

	/* ---------------------------------------------------------------- */
	/*  Init                                                             */
	/* ---------------------------------------------------------------- */

	loadTags();

} )( jQuery );
