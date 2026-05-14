<?php
/**
 * NeoWeaver — Checkout integration
 * Handles Field Agent selection during WooCommerce checkout.
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────
//  1. Helper: does the cart contain a NeoWeaver item?
// ─────────────────────────────────────────────
function neoweaver_cart_has_item(): bool {
    if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
        return false;
    }
    foreach ( WC()->cart->get_cart() as $cart_item ) {
        $product = $cart_item['data'] ?? null;
        if ( ! $product ) continue;
        // sprawdź post meta ORAZ atrybut produktu
        if ( get_post_meta( $product->get_id(), '_neoweaver_item_id', true ) ) {
            return true;
        }
        if ( method_exists( $product, 'get_attribute' ) && $product->get_attribute( 'neoweaver_item_id' ) ) {
            return true;
        }
    }
    return false;
}

// ─────────────────────────────────────────────
//  2. Helper: fetch player's active characters from Supabase
// ─────────────────────────────────────────────
function neoweaver_get_player_characters( int $wp_user_id ): array {
    if ( ! $wp_user_id ) {
        return [];
    }

    $supa_url = tw_supabase_url();
    $supa_key = tw_supabase_service_key();

    if ( ! $supa_url || ! $supa_key ) {
        return [];
    }

    $response = wp_remote_get(
        $supa_url . '/rest/v1/cyber_characters'
            . '?wp_user_id=eq.' . $wp_user_id
            . '&status=eq.ALIVE'
            . '&select=id,name',
        [
            'headers' => [
                'apikey'        => $supa_key,
                'Authorization' => 'Bearer ' . $supa_key,
            ],
            'timeout' => 10,
        ]
    );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        error_log( '[NeoWeaver] get_player_characters error: ' . wp_remote_retrieve_body( $response ) );
        return [];
    }

    return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
}

// ─────────────────────────────────────────────
//  3. Enqueue JS + pass data to it
// ─────────────────────────────────────────────
add_action( 'wp_enqueue_scripts', function () {
    if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
        return;
    }

    wp_enqueue_script(
        'neoweaver-checkout-block',
        NEOWEAVER_PLUGIN_URL . 'assets/js/public/checkout-block.js',
        [ 'jquery', 'wc-checkout' ],
        NEOWEAVER_VERSION,
        true
    );

    wp_localize_script( 'neoweaver-checkout-block', 'neoweaverCheckout', [
        'characters'   => neoweaver_get_player_characters( get_current_user_id() ),
        'hasNeoweaver' => neoweaver_cart_has_item() ? '1' : '0',
        'createUrl'    => home_url( '/new-agent/' ),
        'nonce'        => wp_create_nonce( 'neoweaver_checkout' ),
        'i18n'         => [
            'label'       => '⚔️ Which Field Agent receives this item?',
            'placeholder' => '— Choose your Field Agent —',
            'noAgents'    => '⚠️ You have no active Field Agents.',
            'createLink'  => 'Create a character',
            'required'    => '⚔️ Please select a Field Agent to receive the NeoWeaver item.',
        ],
    ] );
} );

// ─────────────────────────────────────────────
//  4. Validate on classic checkout submit
// ─────────────────────────────────────────────
add_action( 'woocommerce_checkout_process', function () {
    if ( ! neoweaver_cart_has_item() ) {
        return;
    }
    if ( empty( $_POST['neoweaver_character_id'] ) ) {
        wc_add_notice(
            '⚔️ Please select a Field Agent to receive the NeoWeaver item.',
            'error'
        );
    }
} );

// ─────────────────────────────────────────────
//  5. Save character_id to order meta (classic checkout)
// ─────────────────────────────────────────────
add_action( 'woocommerce_checkout_create_order', function ( $order ) {
    if ( ! empty( $_POST['neoweaver_character_id'] ) ) {
        $order->update_meta_data(
            '_neoweaver_character_id',
            sanitize_text_field( $_POST['neoweaver_character_id'] )
        );
    }
} );

// ─────────────────────────────────────────────
//  6. Save character_id to order meta (Blocks checkout fallback)
// ─────────────────────────────────────────────
add_action( 'woocommerce_store_api_checkout_order_processed', function ( $order ) {
    $character_id = WC()->session ? WC()->session->get( 'neoweaver_character_id' ) : null;
    if ( $character_id ) {
        $order->update_meta_data( '_neoweaver_character_id', $character_id );
        $order->save();
        WC()->session->__unset( 'neoweaver_character_id' );
    }
} );
