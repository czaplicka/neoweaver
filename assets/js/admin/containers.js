/* global ajaxurl, NWContainers */

jQuery( function ( $ ) {

    var nonce = NWContainers.nonce;
    var all   = [];

    /* ---------------------------------------------------------------- */
    /*  Helpers                                                          */
    /* ---------------------------------------------------------------- */

    function esc( s ) {
        return $( '<span>' ).text( s || '' ).html();
    }

    function notice( msg, type ) {
        var el = $( '#nw-notice' );
        el.attr( 'class', 'nw-notice nw-notice-' + type ).text( msg ).show();
        setTimeout( function () { el.fadeOut( 300 ); }, 3500 );
    }

    function updateStats( data ) {
        var active = data.filter( function ( r ) { return r.is_active !== false; } ).length;
        $( '#nw-total' ).text( data.length );
        $( '#nw-active' ).text( active );
        $( '#nw-inactive' ).text( data.length - active );
    }

    var rarityClass = {
        common:    'nw-rarity-common',
        uncommon:  'nw-rarity-uncommon',
        rare:      'nw-rarity-rare',
        epic:      'nw-rarity-epic',
        legendary: 'nw-rarity-legendary'
    };

    /* ---------------------------------------------------------------- */
    /*  Table rendering                                                  */
    /* ---------------------------------------------------------------- */

    function renderTable( data ) {
        var tbody = $( '#nw-containers-tbody' );

        if ( ! data.length ) {
            tbody.html( '<tr><td colspan="7" style="text-align:center;padding:32px;color:#555;">No containers found.</td></tr>' );
            return;
        }

        tbody.html( data.map( function ( r ) {
            var active  = r.is_active !== false;
            var sizes   = Array.isArray( r.allowed_sizes ) ? r.allowed_sizes : [];
            var sizesH  = sizes.map( function ( s ) {
                return '<span class="nw-size-tag">' + esc( s ) + '</span>';
            } ).join( '' );
            var rarCls  = rarityClass[ r.rarity ] || 'nw-rarity-common';
            var imgH    = r.img_url
                ? '<img src="' + esc( r.img_url ) + '" class="nw-cont-img" loading="lazy" onerror="this.style.display=\'none\'">'
                : '<div class="nw-cont-img-placeholder">🎒</div>';
            var descSnippet = r.description
                ? esc( r.description.substring( 0, 50 ) + ( r.description.length > 50 ? '\u2026' : '' ) )
                : '';

            return '<tr data-id="' + r.id + '" class="' + ( active ? '' : 'nw-row-inactive' ) + '">'
                + '<td>' + imgH + '</td>'
                + '<td><div class="nw-cont-name">' + esc( r.name ) + '</div>'
                + '<div class="nw-cont-sub">' + descSnippet + '</div></td>'
                + '<td><span class="nw-rarity ' + rarCls + '">' + esc( r.rarity || 'common' ) + '</span></td>'
                + '<td><span class="nw-slots-badge">' + ( r.total_slots || '?' ) + '</span></td>'
                + '<td><div class="nw-sizes">' + sizesH + '</div></td>'
                + '<td><label class="nw-toggle">'
                + '<input type="checkbox" class="nw-active-toggle" data-id="' + r.id + '" ' + ( active ? 'checked' : '' ) + '>'
                + '<span class="nw-toggle-slider"></span></label></td>'
                + '<td><div class="nw-row-actions">'
                + '<button class="nw-action-btn nw-edit-btn" data-id="' + r.id + '">Edit</button>'
                + '</div></td>'
                + '</tr>';
        } ).join( '' ) );
    }

    /* ---------------------------------------------------------------- */
    /*  Load                                                             */
    /* ---------------------------------------------------------------- */

    function loadAll() {
        $( '#nw-containers-tbody' ).html(
            '<tr class="nw-loading-row"><td colspan="7"><div class="nw-spinner"></div> Loading\u2026</td></tr>'
        );
        $.post( ajaxurl, { action: 'nw_containers_get_all', nonce: nonce }, function ( res ) {
            if ( ! res.success ) { notice( 'Error: ' + res.data, 'error' ); return; }
            all = res.data || [];
            renderTable( all );
            updateStats( all );
        } ).fail( function () { notice( 'Request failed.', 'error' ); } );
    }

    /* ---------------------------------------------------------------- */
    /*  Toggle active                                                    */
    /* ---------------------------------------------------------------- */

    $( document ).on( 'change', '.nw-active-toggle', function () {
        var id  = $( this ).data( 'id' );
        var val = $( this ).is( ':checked' );
        var row = $( this ).closest( 'tr' );

        $.post( ajaxurl, {
            action:       'nw_containers_toggle',
            nonce:        nonce,
            container_id: id,
            is_active:    val ? 1 : 0
        }, function ( res ) {
            if ( res.success ) {
                row.toggleClass( 'nw-row-inactive', ! val );
                all = all.map( function ( r ) {
                    if ( r.id === id ) r.is_active = val;
                    return r;
                } );
                updateStats( all );
                notice( ( val ? 'Activated' : 'Deactivated' ) + '.', 'success' );
            } else {
                notice( 'Toggle failed: ' + res.data, 'error' );
                row.find( '.nw-active-toggle' ).prop( 'checked', ! val );
            }
        } );
    } );

    /* ---------------------------------------------------------------- */
    /*  Modal                                                            */
    /* ---------------------------------------------------------------- */

    function openModal( id ) {
        $( '#nw-container-form' )[ 0 ].reset();
        $( '#nw-field-id' ).val( '' );
        $( '#nw-field-total_slots' ).val( 5 );
        $( '#nw-val-total_slots' ).text( 5 );
        $( "input[name='allowed_sizes[]']" ).prop( 'checked', true );

        if ( id ) {
            var r = all.find( function ( x ) { return x.id === id; } );
            if ( r ) {
                $( '#nw-field-id' ).val( r.id );
                $( '#nw-field-name' ).val( r.name || '' );
                $( '#nw-field-description' ).val( r.description || '' );
                $( '#nw-field-img_url' ).val( r.img_url || '' );
                $( '#nw-field-rarity' ).val( r.rarity || 'common' );
                $( '#nw-field-total_slots' ).val( r.total_slots || 5 );
                $( '#nw-val-total_slots' ).text( r.total_slots || 5 );
                $( '#nw-field-parent_id' ).val( r.parent_id || '' );
                $( '#nw-field-is_active' ).prop( 'checked', r.is_active !== false );

                var sizes = Array.isArray( r.allowed_sizes ) ? r.allowed_sizes : [];
                $( "input[name='allowed_sizes[]']" ).each( function () {
                    $( this ).prop( 'checked', sizes.indexOf( $( this ).val() ) !== -1 );
                } );
            }
            $( '#nw-modal-title' ).text( 'Edit Container' );
            $( '#nw-save-label' ).text( 'Save Changes' );
        } else {
            $( '#nw-modal-title' ).text( 'New Container' );
            $( '#nw-save-label' ).text( 'Create Container' );
        }

        $( '#nw-modal-overlay' ).fadeIn( 150 );
    }

    /* ---------------------------------------------------------------- */
    /*  Events                                                           */
    /* ---------------------------------------------------------------- */

    $( document ).on( 'input', '#nw-field-total_slots', function () {
        $( '#nw-val-total_slots' ).text( $( this ).val() );
    } );

    $( '#nw-modal-close, #nw-cancel-btn' ).on( 'click', function () {
        $( '#nw-modal-overlay' ).fadeOut( 150 );
    } );

    $( '#nw-modal-overlay' ).on( 'click', function ( e ) {
        if ( $( e.target ).is( '#nw-modal-overlay' ) ) {
            $( '#nw-modal-overlay' ).fadeOut( 150 );
        }
    } );

    $( document ).on( 'click', '.nw-edit-btn', function () {
        openModal( $( this ).data( 'id' ) );
    } );

    $( '#nw-add-btn' ).on( 'click', function () { openModal( null ); } );
    $( '#nw-refresh-btn' ).on( 'click', loadAll );

    /* ---------------------------------------------------------------- */
    /*  Save                                                             */
    /* ---------------------------------------------------------------- */

    $( '#nw-save-btn' ).on( 'click', function () {
        if ( ! $( '#nw-field-name' ).val().trim() ) {
            notice( 'Name is required.', 'error' );
            return;
        }

        var btn = $( this );
        btn.prop( 'disabled', true );
        $( '#nw-save-label' ).text( 'Saving\u2026' );

        var sizes = [];
        $( "input[name='allowed_sizes[]']:checked" ).each( function () {
            sizes.push( $( this ).val() );
        } );
        if ( ! sizes.length ) sizes = [ 'tiny', 'small', 'medium', 'large' ];

        var fd = {
            action: 'nw_containers_save',
            nonce:  nonce,
            container: {
                id:            $( '#nw-field-id' ).val(),
                name:          $( '#nw-field-name' ).val(),
                description:   $( '#nw-field-description' ).val(),
                img_url:       $( '#nw-field-img_url' ).val(),
                rarity:        $( '#nw-field-rarity' ).val(),
                total_slots:   $( '#nw-field-total_slots' ).val(),
                parent_id:     $( '#nw-field-parent_id' ).val(),
                allowed_sizes: sizes.join( ',' ),
                is_active:     $( '#nw-field-is_active' ).is( ':checked' ) ? 1 : 0
            }
        };

        $.post( ajaxurl, fd, function ( res ) {
            btn.prop( 'disabled', false );
            $( '#nw-save-label' ).text( 'Save Changes' );
            if ( res.success ) {
                notice( 'Container saved!', 'success' );
                $( '#nw-modal-overlay' ).fadeOut( 150 );
                loadAll();
            } else {
                notice( 'Error: ' + ( res.data || 'Unknown' ), 'error' );
            }
        } ).fail( function () {
            btn.prop( 'disabled', false );
            $( '#nw-save-label' ).text( 'Save Changes' );
            notice( 'Request failed.', 'error' );
        } );
    } );

    /* ---------------------------------------------------------------- */
    /*  Init                                                             */
    /* ---------------------------------------------------------------- */

    loadAll();

} );
