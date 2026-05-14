/**
 * NeoWeaver — Checkout Field Agent selector
 * Injects the character <select> into the WooCommerce checkout form.
 *
 * Depends on: jQuery, neoweaverCheckout (wp_localize_script)
 */
( function ( $ ) {
    'use strict';

    const cfg = window.neoweaverCheckout || {};

    if ( cfg.hasNeoweaver !== '1' ) {
        return;
    }

    // ── Build the HTML block ─────────────────────────────────────
    function buildAgentSelect() {
        const i18n = cfg.i18n || {};

        if ( ! cfg.characters || cfg.characters.length === 0 ) {
            return $( '<div>', {
                class: 'neoweaver-agent-select woocommerce-info',
                css:   { margin: '16px 0' },
                html:  i18n.noAgents + ' <a href="' + cfg.createUrl + '">'
                       + i18n.createLink + '</a> before purchasing.',
            } );
        }

        const $wrap   = $( '<div>', { class: 'neoweaver-agent-select', css: { margin: '16px 0' } } );
        const $label  = $( '<label>', {
            for:  'neoweaver_character_id',
            css:  { display: 'block', fontWeight: '600', marginBottom: '6px' },
            text: i18n.label,
        } );
        const $select = $( '<select>', {
            name:     'neoweaver_character_id',
            id:       'neoweaver_character_id',
            class:    'woocommerce-select',
            required: true,
        } ).append( $( '<option>', { value: '', text: i18n.placeholder } ) );

        $.each( cfg.characters, function ( _, char ) {
            $select.append( $( '<option>', { value: char.id, text: char.name } ) );
        } );

        return $wrap.append( $label, $select );
    }

    // ── Inject into the form ─────────────────────────────────────
    function inject() {
        if ( $( '#neoweaver_character_id' ).length ) {
            return; // already injected
        }

        const $target = $( '#place_order' );
        if ( $target.length ) {
            buildAgentSelect().insertBefore( $target );
            return;
        }

        // fallback: after order review
        const $review = $( '#order_review, .woocommerce-checkout-review-order' ).last();
        if ( $review.length ) {
            buildAgentSelect().insertAfter( $review );
        }
    }

    // ── Client-side validation ───────────────────────────────────
    $( 'form.checkout' ).on( 'submit', function () {
        if ( cfg.characters && cfg.characters.length > 0 ) {
            if ( ! $( '#neoweaver_character_id' ).val() ) {
                return false;
            }
        }
    } );

    // ── Init ─────────────────────────────────────────────────────
    $( function () {
        inject();
        // Re-inject after WC AJAX updates (shipping, coupon, etc.)
        $( document.body ).on( 'updated_checkout', inject );
    } );

} )( jQuery );
