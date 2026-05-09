/* global jQuery, NWAch, lucide */
jQuery( function ( $ ) {

    const { ajaxurl, nonce } = NWAch;
    let editId = null;
    let allRows = [];

    /* ---------------------------------------------------------------- */
    /*  Lucide icons init                                                */
    /* ---------------------------------------------------------------- */

    function initIcons() {
        if ( typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function' ) {
            lucide.createIcons();
        }
    }

    /* ---------------------------------------------------------------- */
    /*  Load                                                             */
    /* ---------------------------------------------------------------- */

    function load() {
        const cat    = $( '#nw-filter-category' ).val();
        const scope  = $( '#nw-filter-scope' ).val();

        $( '#nw-achievements-tbody' ).html(
            '<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">'
            + '<div class="nw-spinner"></div> Loading&hellip;</td></tr>'
        );

        $.post( ajaxurl, {
            action          : 'nw_achievements_get_all',
            nonce,
            filter_category : cat,
            filter_scope    : scope,
        }, function ( r ) {
            if ( ! r.success ) { showNotice( 'error', r.data ); return; }
            allRows = r.data || [];
            renderTable( applyClientFilters( allRows ) );
        } );
    }

    /* ---------------------------------------------------------------- */
    /*  Client-side filters (active/inactive, hidden)                   */
    /* ---------------------------------------------------------------- */

    function applyClientFilters( rows ) {
        const active = $( '#nw-filter-active' ).val();
        const hidden = $( '#nw-filter-hidden' ).val();
        const search = $( '#nw-search' ).val().toLowerCase().trim();

        return rows.filter( function ( a ) {
            if ( active === '1'  && ! a.is_active )          return false;
            if ( active === '0'  && a.is_active )            return false;
            if ( hidden === '1'  && ! a.hidden_until_earned ) return false;
            if ( hidden === '0'  && a.hidden_until_earned )  return false;
            if ( search && ! ( a.id + ' ' + a.title ).toLowerCase().includes( search ) ) return false;
            return true;
        } );
    }

    /* ---------------------------------------------------------------- */
    /*  Render table                                                     */
    /* ---------------------------------------------------------------- */

    function renderTable( rows ) {
        let total = 0, active = 0, inactive = 0, account = 0, character = 0, hidden = 0;
        let html  = '';

        if ( ! rows.length ) {
            html = '<tr><td colspan="8" style="text-align:center;padding:32px;color:#555;">No achievements found.</td></tr>';
        }

        $.each( rows, function ( _, a ) {
            total++;
            if ( a.is_active )           active++;    else inactive++;
            if ( a.scope === 'account' )  account++;   else character++;
            if ( a.hidden_until_earned )  hidden++;

            const catCls   = a.category ? ' nw-cat-'   + a.category : '';
            const scopeCls = a.scope    ? ' nw-scope-' + a.scope    : '';
            const catLabel = a.category ? a.category.charAt(0).toUpperCase() + a.category.slice(1) : '—';
            const scpLabel = a.scope    ? a.scope.charAt(0).toUpperCase()    + a.scope.slice(1)    : '—';

            const iconHtml = '<div class="nw-ach-icon" style="background:' + escH( a.bg_color || '#2c3e50' ) + '">'
                + '<i data-lucide="' + escH( a.icon_slug || 'trophy' ) + '"></i></div>';

            html += '<tr data-id="' + escH( a.id ) + '" class="' + ( a.is_active ? '' : 'nw-row-inactive' ) + '">'
                + '<td>' + iconHtml + '</td>'
                + '<td><div class="nw-ach-id">' + escH( a.id ) + '</div>'
                +     '<div class="nw-ach-title">' + escH( a.title ) + '</div></td>'
                + '<td><span class="nw-cat-badge' + catCls + '">' + catLabel + '</span></td>'
                + '<td><span class="nw-scope-badge' + scopeCls + '">' + scpLabel + '</span></td>'
                + '<td>' + ( a.goal || 1 ) + '</td>'
                + '<td>' + ( a.hidden_until_earned ? '<span style="color:#ff9f43">&#128274;</span>' : '<span style="color:#333">—</span>' ) + '</td>'
                + '<td><label class="nw-toggle"><input type="checkbox" class="nw-toggle-active" data-id="' + escH( a.id ) + '"' + ( a.is_active ? ' checked' : '' ) + '>'
                +     '<span class="nw-toggle-slider"></span></label></td>'
                + '<td><div class="nw-row-actions">'
                +   '<button class="nw-action-btn nw-edit-btn" data-id="' + escH( a.id ) + '">Edit</button>'
                + '</div></td>'
                + '</tr>';
        } );

        $( '#nw-achievements-tbody' ).html( html );
        $( '#nw-total' ).text( total );
        $( '#nw-active' ).text( active );
        $( '#nw-inactive' ).text( inactive );
        $( '#nw-count-account' ).text( account );
        $( '#nw-count-character' ).text( character );
        $( '#nw-count-hidden' ).text( hidden );

        initIcons();
    }

    /* ---------------------------------------------------------------- */
    /*  Modal                                                            */
    /* ---------------------------------------------------------------- */

    function openModal( ach ) {
        editId = ach ? ach.id : null;
        $( '#nw-modal-title' ).text( ach ? 'Edit Achievement' : 'New Achievement' );
        $( '#nw-save-label' ).text( ach ? 'Save Achievement' : 'Create Achievement' );
        $( '#nw-delete-btn' ).toggle( !! ach );

        $( '#nw-field-original_id' ).val( ach ? ach.id          : '' );
        $( '#nw-field-id' ).val(          ach ? ach.id          : '' );
        $( '#nw-field-title' ).val(       ach ? ach.title       : '' );
        $( '#nw-field-description' ).val( ach ? ach.description || '' : '' );
        $( '#nw-field-icon_slug' ).val(   ach ? ach.icon_slug   || 'trophy' : 'trophy' );
        $( '#nw-field-bg_color' ).val(    ach ? ach.bg_color    || '#2c3e50' : '#2c3e50' );
        $( '#nw-field-bg_color_picker' ).val( ach ? ach.bg_color || '#2c3e50' : '#2c3e50' );
        $( '#nw-field-scope' ).val(       ach ? ach.scope       || 'account' : 'account' );
        $( '#nw-field-category' ).val(    ach ? ach.category    || '' : '' );
        $( '#nw-field-goal' ).val(        ach ? ach.goal        || 1 : 1 );
        $( '#nw-field-hidden_until_earned' ).prop( 'checked', ach ? !! ach.hidden_until_earned : false );
        $( '#nw-field-is_active' ).prop( 'checked', ach ? !! ach.is_active : true );

        updateBadgePreview();
        $( '#nw-modal-overlay' ).show();
        initIcons();
    }

    function closeModal() {
        $( '#nw-modal-overlay' ).hide();
        editId = null;
    }

    function updateBadgePreview() {
        const title    = $( '#nw-field-title' ).val()    || 'Achievement Title';
        const desc     = $( '#nw-field-description' ).val() || 'Description…';
        const iconSlug = $( '#nw-field-icon_slug' ).val() || 'trophy';
        const bgColor  = $( '#nw-field-bg_color' ).val()  || '#2c3e50';

        $( '#nw-preview-title' ).text( title );
        $( '#nw-preview-desc' ).text( desc );
        $( '#nw-badge-icon' ).css( 'background', bgColor )
            .html( '<i data-lucide="' + escH( iconSlug ) + '"></i>' );
        $( '#nw-icon-preview' ).html( '<i data-lucide="' + escH( iconSlug ) + '"></i>' );

        initIcons();
    }

    /* ---------------------------------------------------------------- */
    /*  Save                                                             */
    /* ---------------------------------------------------------------- */

    function save() {
        const data = { action: 'nw_achievements_save', nonce, achievement: {} };
        $( '#nw-achievement-form' ).serializeArray().forEach( function ( f ) {
            data.achievement[ f.name ] = f.value;
        } );
        data.achievement.is_active           = $( '#nw-field-is_active' ).is( ':checked' ) ? '1' : '0';
        data.achievement.hidden_until_earned = $( '#nw-field-hidden_until_earned' ).is( ':checked' ) ? '1' : '0';

        $( '#nw-save-btn' ).prop( 'disabled', true ).text( 'Saving…' );

        $.post( ajaxurl, data, function ( r ) {
            $( '#nw-save-btn' ).prop( 'disabled', false );
            $( '#nw-save-label' ).text( editId ? 'Save Achievement' : 'Create Achievement' );
            if ( ! r.success ) { showNotice( 'error', r.data ); return; }
            showNotice( 'success', editId ? 'Achievement updated.' : 'Achievement created.' );
            closeModal();
            load();
        } );
    }

    /* ---------------------------------------------------------------- */
    /*  Events                                                           */
    /* ---------------------------------------------------------------- */

    $( '#nw-add-btn' ).on( 'click', function () { openModal( null ); } );
    $( '#nw-refresh-btn' ).on( 'click', load );
    $( '#nw-filter-category, #nw-filter-scope' ).on( 'change', load );
    $( '#nw-filter-active, #nw-filter-hidden' ).on( 'change', function () {
        renderTable( applyClientFilters( allRows ) );
    } );
    $( '#nw-search' ).on( 'input', function () {
        renderTable( applyClientFilters( allRows ) );
    } );

    $( '#nw-modal-close, #nw-cancel-btn' ).on( 'click', closeModal );
    $( '#nw-modal-overlay' ).on( 'click', function ( e ) {
        if ( $( e.target ).is( '#nw-modal-overlay' ) ) closeModal();
    } );

    $( '#nw-save-btn' ).on( 'click', save );

    $( '#nw-field-title, #nw-field-description, #nw-field-icon_slug' ).on( 'input', updateBadgePreview );
    $( '#nw-field-bg_color' ).on( 'input', function () {
        $( '#nw-field-bg_color_picker' ).val( $( this ).val() );
        updateBadgePreview();
    } );
    $( '#nw-field-bg_color_picker' ).on( 'input', function () {
        $( '#nw-field-bg_color' ).val( $( this ).val() );
        updateBadgePreview();
    } );

    $( document ).on( 'change', '.nw-toggle-active', function () {
        const id    = $( this ).data( 'id' );
        const state = $( this ).is( ':checked' );
        $.post( ajaxurl, {
            action         : 'nw_achievements_toggle',
            nonce,
            achievement_id : id,
            is_active      : state ? 1 : 0,
        }, function ( r ) {
            if ( ! r.success ) { showNotice( 'error', r.data ); load(); return; }
            $( 'tr[data-id="' + id + '"]' ).toggleClass( 'nw-row-inactive', ! state );
        } );
    } );

    $( document ).on( 'click', '.nw-edit-btn', function () {
        const id  = $( this ).data( 'id' );
        const ach = allRows.find( function ( a ) { return a.id === id; } );
        if ( ach ) openModal( ach );
    } );

    $( '#nw-delete-btn' ).on( 'click', function () {
        if ( ! editId || ! confirm( 'Delete this achievement? This cannot be undone.' ) ) return;
        $.post( ajaxurl, {
            action         : 'nw_achievements_delete',
            nonce,
            achievement_id : editId,
        }, function ( r ) {
            if ( ! r.success ) { showNotice( 'error', r.data ); return; }
            showNotice( 'success', 'Achievement deleted.' );
            closeModal();
            load();
        } );
    } );

    /* ---------------------------------------------------------------- */
    /*  Helpers                                                          */
    /* ---------------------------------------------------------------- */

    function showNotice( type, msg ) {
        $( '#nw-notice' )
            .removeClass( 'nw-notice-success nw-notice-error' )
            .addClass( 'nw-notice-' + type )
            .text( msg )
            .show();
        setTimeout( function () { $( '#nw-notice' ).fadeOut(); }, 4000 );
    }

    function escH( s ) {
        return $( '<div>' ).text( String( s || '' ) ).html();
    }

    /* ---------------------------------------------------------------- */
    /*  Init                                                             */
    /* ---------------------------------------------------------------- */

    load();
} );
