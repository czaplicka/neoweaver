/* ==========================================================================
   NeoWeaver Admin — World Tag Defs
   Depends on: jQuery, NW_WTD (ajax_url, nonce)
   ========================================================================== */

( function ( $ ) {
    'use strict';

    /* ----------------------------------------------------------------
       State
    ---------------------------------------------------------------- */
    let allTags    = [];
    let editingId  = null;

    /* ----------------------------------------------------------------
       DOM refs
    ---------------------------------------------------------------- */
    const $notice      = $( '#nw-notice' );
    const $tbody       = $( '#nw-wtd-tbody' );
    const $overlay     = $( '#nw-modal-overlay' );
    const $modalTitle  = $( '#nw-modal-title' );
    const $deleteBtn   = $( '#nw-delete-btn' );
    const $saveLabel   = $( '#nw-save-label' );
    const $colorPicker = $( '#nw-field-color-picker' );
    const $colorText   = $( '#nw-field-color' );

    /* ---- Filter refs ---- */
    const $filterCat    = $( '#nw-filter-category' );
    const $filterSource = $( '#nw-filter-source' );
    const $filterActive = $( '#nw-filter-active' );
    const $filterSearch = $( '#nw-filter-search' );

    /* ---- Stat refs ---- */
    const $total        = $( '#nw-total' );
    const $activeCount  = $( '#nw-active' );
    const $inactiveCount = $( '#nw-inactive' );
    const $countSystem  = $( '#nw-count-system' );
    const $countCustom  = $( '#nw-count-custom' );

    /* ----------------------------------------------------------------
       Notice helper
    ---------------------------------------------------------------- */
    function showNotice( msg, type ) {
        $notice
            .removeClass( 'nw-notice-success nw-notice-error' )
            .addClass( 'nw-notice-' + type )
            .text( msg )
            .fadeIn( 200 );
        clearTimeout( showNotice._timer );
        showNotice._timer = setTimeout( () => $notice.fadeOut( 400 ), 4000 );
    }

    /* ----------------------------------------------------------------
       Load all
    ---------------------------------------------------------------- */
    function loadAll() {
        $tbody.html( '<tr class="nw-loading-row"><td colspan="9"><div class="nw-spinner"></div> Loading tag defs&hellip;</td></tr>' );

        $.post( NW_WTD.ajax_url, {
            action: 'nw_wtd_get_all',
            nonce:  NW_WTD.nonce,
        } )
        .done( function ( res ) {
            if ( ! res.success ) { showNotice( res.data || 'Load failed.', 'error' ); return; }
            allTags = res.data || [];
            updateStats();
            populateCategoryFilter();
            renderTable();
        } )
        .fail( function () { showNotice( 'AJAX error — could not load tags.', 'error' ); } );
    }

    /* ----------------------------------------------------------------
       Stats
    ---------------------------------------------------------------- */
    function updateStats() {
        const active   = allTags.filter( t => t.is_active ).length;
        const inactive = allTags.length - active;
        $total.text( allTags.length );
        $activeCount.text( active );
        $inactiveCount.text( inactive );
        $countSystem.text( allTags.filter( t => t.source === 'system' ).length );
        $countCustom.text(  allTags.filter( t => t.source === 'custom' ).length );
    }

    /* ----------------------------------------------------------------
       Category filter population
    ---------------------------------------------------------------- */
    function populateCategoryFilter() {
        const current = $filterCat.val();
        const cats    = [ ...new Set( allTags.map( t => t.category ).filter( Boolean ) ) ].sort();

        $filterCat.find( 'option:not(:first)' ).remove();
        cats.forEach( c => $filterCat.append( $( '<option>' ).val( c ).text( c ) ) );
        if ( current ) $filterCat.val( current );
    }

    /* ----------------------------------------------------------------
       Filter & render
    ---------------------------------------------------------------- */
    function renderTable() {
        const cat    = $filterCat.val();
        const src    = $filterSource.val();
        const active = $filterActive.val();
        const search = $filterSearch.val().toLowerCase().trim();

        const filtered = allTags.filter( t => {
            if ( cat    && t.category !== cat )                        return false;
            if ( src    && t.source   !== src )                        return false;
            if ( active !== '' && String( t.is_active ? 1 : 0 ) !== active ) return false;
            if ( search ) {
                const haystack = [ t.code, t.label, t.description ].join( ' ' ).toLowerCase();
                if ( ! haystack.includes( search ) ) return false;
            }
            return true;
        } );

        if ( ! filtered.length ) {
            $tbody.html( '<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--nw-text-muted);">No tag defs found.</td></tr>' );
            return;
        }

        $tbody.empty();
        filtered.forEach( t => $tbody.append( buildRow( t ) ) );
    }

    /* ----------------------------------------------------------------
       Build table row
    ---------------------------------------------------------------- */
    function buildRow( t ) {
        const color     = t.color || '#888';
        const sourceMap = { system: 'nw-badge-system', custom: 'nw-badge-custom', imported: 'nw-badge-imported' };
        const badgeCls  = sourceMap[ t.source ] || '';
        const dimCls    = t.is_active ? '' : ' nw-inactive';

        return $( '<tr>' )
            .attr( 'data-id', t.id )
            .html( `
                <td class="${ dimCls }"><code>${ esc( t.code ) }</code></td>
                <td class="${ dimCls }">${ esc( t.label ) }</td>
                <td>
                    <div class="nw-icon-cell">
                        <span class="nw-color-dot" style="background:${ esc( color ) };"></span>
                        ${ t.icon ? esc( t.icon ) : '<span style="color:var(--nw-text-muted)">—</span>' }
                    </div>
                </td>
                <td class="${ dimCls }">${ t.category ? esc( t.category ) : '—' }</td>
                <td><span class="nw-badge ${ badgeCls }">${ esc( t.source || '—' ) }</span></td>
                <td class="${ dimCls }">${ t.impact != null ? t.impact : '—' }</td>
                <td class="${ dimCls }">${ t.sort_order != null ? t.sort_order : '—' }</td>
                <td>
                    <button class="nw-row-toggle nw-toggle-active-btn"
                            data-id="${ t.id }" data-active="${ t.is_active ? 1 : 0 }"
                            title="Toggle active">${ t.is_active ? '✅' : '⭕' }</button>
                </td>
                <td>
                    <div class="nw-row-actions">
                        <button class="nw-row-btn nw-edit-btn" data-id="${ t.id }">Edit</button>
                    </div>
                </td>
            ` );
    }

    /* ----------------------------------------------------------------
       Open modal
    ---------------------------------------------------------------- */
    function openModal( tag ) {
        editingId = tag ? tag.id : null;
        $modalTitle.text( tag ? 'Edit World Tag Def' : 'New World Tag Def' );
        $saveLabel.text( tag ? 'Save Tag Def' : 'Create Tag Def' );
        $deleteBtn.toggle( !! tag );

        // Populate fields
        $( '#nw-field-id' ).val(          tag ? tag.id          : '' );
        $( '#nw-field-code' ).val(        tag ? tag.code        : '' );
        $( '#nw-field-label' ).val(       tag ? tag.label       : '' );
        $( '#nw-field-description' ).val( tag ? ( tag.description || '' ) : '' );
        $( '#nw-field-icon' ).val(        tag ? ( tag.icon      || '' ) : '' );
        $( '#nw-field-category' ).val(    tag ? ( tag.category  || '' ) : '' );
        $( '#nw-field-source' ).val(      tag ? ( tag.source    || 'system' ) : 'system' );
        $( '#nw-field-sort_order' ).val(  tag ? ( tag.sort_order != null ? tag.sort_order : '' ) : '' );
        $( '#nw-field-impact' ).val(      tag ? ( tag.impact    != null ? tag.impact : '' ) : '' );
        $( '#nw-field-is_active' ).prop( 'checked', tag ? !! tag.is_active : true );

        const colorVal = ( tag && tag.color ) ? tag.color : '#adff00';
        $colorText.val( colorVal );
        $colorPicker.val( colorVal );

        $overlay.fadeIn( 160 );
        $( '#nw-field-code' ).trigger( 'focus' );
    }

    function closeModal() {
        $overlay.fadeOut( 160 );
        editingId = null;
    }

    /* ----------------------------------------------------------------
       Save
    ---------------------------------------------------------------- */
    function saveTag() {
        const $btn = $( '#nw-save-btn' ).prop( 'disabled', true );

        const payload = {
            id:          $( '#nw-field-id' ).val().trim(),
            code:        $( '#nw-field-code' ).val().trim(),
            label:       $( '#nw-field-label' ).val().trim(),
            description: $( '#nw-field-description' ).val().trim(),
            icon:        $( '#nw-field-icon' ).val().trim(),
            color:       $colorText.val().trim() || '#adff00',
            category:    $( '#nw-field-category' ).val().trim(),
            source:      $( '#nw-field-source' ).val(),
            sort_order:  $( '#nw-field-sort_order' ).val().trim(),
            impact:      $( '#nw-field-impact' ).val().trim(),
            is_active:   $( '#nw-field-is_active' ).is( ':checked' ) ? 1 : 0,
        };

        if ( ! payload.code )  { showNotice( 'Code is required.',  'error' ); $btn.prop( 'disabled', false ); return; }
        if ( ! payload.label ) { showNotice( 'Label is required.', 'error' ); $btn.prop( 'disabled', false ); return; }

        $.post( NW_WTD.ajax_url, {
            action: 'nw_wtd_save',
            nonce:  NW_WTD.nonce,
            tag:    payload,
        } )
        .done( function ( res ) {
            $btn.prop( 'disabled', false );
            if ( ! res.success ) { showNotice( res.data || 'Save failed.', 'error' ); return; }
            showNotice( editingId ? 'Tag def updated.' : 'Tag def created.', 'success' );
            closeModal();
            loadAll();
        } )
        .fail( function () {
            $btn.prop( 'disabled', false );
            showNotice( 'AJAX error — save failed.', 'error' );
        } );
    }

    /* ----------------------------------------------------------------
       Toggle active (table row)
    ---------------------------------------------------------------- */
    function toggleActive( id, currentActive ) {
        const newState = currentActive ? 0 : 1;

        $.post( NW_WTD.ajax_url, {
            action:    'nw_wtd_toggle',
            nonce:     NW_WTD.nonce,
            tag_id:    id,
            is_active: newState,
        } )
        .done( function ( res ) {
            if ( ! res.success ) { showNotice( res.data || 'Toggle failed.', 'error' ); return; }
            const tag = allTags.find( t => t.id === id );
            if ( tag ) tag.is_active = !! newState;
            updateStats();
            renderTable();
        } )
        .fail( function () { showNotice( 'AJAX error — toggle failed.', 'error' ); } );
    }

    /* ----------------------------------------------------------------
       Delete
    ---------------------------------------------------------------- */
    function deleteTag() {
        if ( ! editingId ) return;
        if ( ! confirm( 'Delete this tag def? This cannot be undone.' ) ) return;

        $.post( NW_WTD.ajax_url, {
            action: 'nw_wtd_delete',
            nonce:  NW_WTD.nonce,
            tag_id: editingId,
        } )
        .done( function ( res ) {
            if ( ! res.success ) { showNotice( res.data || 'Delete failed.', 'error' ); return; }
            showNotice( 'Tag def deleted.', 'success' );
            closeModal();
            loadAll();
        } )
        .fail( function () { showNotice( 'AJAX error — delete failed.', 'error' ); } );
    }

    /* ----------------------------------------------------------------
       Escape helper
    ---------------------------------------------------------------- */
    function esc( str ) {
        return $( '<span>' ).text( str || '' ).html();
    }

    /* ----------------------------------------------------------------
       Events
    ---------------------------------------------------------------- */
    $( '#nw-add-btn' ).on( 'click', () => openModal( null ) );
    $( '#nw-refresh-btn' ).on( 'click', loadAll );
    $( '#nw-cancel-btn, #nw-modal-close' ).on( 'click', closeModal );
    $( '#nw-save-btn' ).on( 'click', saveTag );
    $( '#nw-delete-btn' ).on( 'click', deleteTag );

    // Close on overlay backdrop click
    $overlay.on( 'click', function ( e ) {
        if ( $( e.target ).is( $overlay ) ) closeModal();
    } );

    // Color picker ↔ text sync
    $colorPicker.on( 'input', () => $colorText.val( $colorPicker.val() ) );
    $colorText.on( 'input', function () {
        const v = $( this ).val().trim();
        if ( /^#[0-9a-fA-F]{6}$/.test( v ) ) $colorPicker.val( v );
    } );

    // Filter changes
    $( '#nw-filter-category, #nw-filter-source, #nw-filter-active' ).on( 'change', renderTable );
    $filterSearch.on( 'input', renderTable );

    // Table: edit row
    $tbody.on( 'click', '.nw-edit-btn', function () {
        const id  = $( this ).data( 'id' );
        const tag = allTags.find( t => t.id === id );
        if ( tag ) openModal( tag );
    } );

    // Table: toggle active
    $tbody.on( 'click', '.nw-toggle-active-btn', function () {
        const id     = $( this ).data( 'id' );
        const active = parseInt( $( this ).data( 'active' ), 10 );
        toggleActive( id, active );
    } );

    // Keyboard: Esc closes modal
    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' ) closeModal();
    } );

    /* ----------------------------------------------------------------
       Init
    ---------------------------------------------------------------- */
    loadAll();

} )( jQuery );
