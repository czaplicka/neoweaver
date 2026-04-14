<?php
function neoweaver_get_player_characters( $wp_user_id ) {
    if ( ! $wp_user_id ) return [];

    $supa_url = tw_supabase_url();
    $supa_key = tw_supabase_service_key(); // service key bo czytamy dane usera po stronie serwera

    if ( ! $supa_url || ! $supa_key ) return [];

    $response = wp_remote_get(
        $supa_url . '/rest/v1/cyber_characters?wp_user_id=eq.' . intval( $wp_user_id ) . '&is_active=eq.true&select=id,name',
        [
            'headers' => [
                'apikey'        => $supa_key,
                'Authorization' => 'Bearer ' . $supa_key,
            ],
        ]
    );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        error_log( '[NeoWeaver] get_player_characters error: ' . wp_remote_retrieve_body( $response ) );
        return [];
    }

    return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
}
add_action( 'woocommerce_blocks_loaded', function() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) return;

    require_once plugin_dir_path( __FILE__ ) . '../includes/class-neoweaver-checkout-block.php';

    add_action( 'woocommerce_blocks_checkout_block_registration', function( $integration_registry ) {
        $integration_registry->register( new NeoWeaver_Checkout_Block_Integration() );
    } );
} );
add_shortcode( 'neoweaver_agent_select', 'neoweaver_agent_select_shortcode' );

function neoweaver_agent_select_shortcode() {
    // Pokaż tylko jeśli w koszyku jest produkt NeoWeaver
    $has_neoweaver = false;
    if ( function_exists( 'WC' ) && WC()->cart ) {
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'] ?? null;
            if ( $product && get_post_meta( $product->get_id(), '_neoweaver_item_id', true ) ) {
                $has_neoweaver = true;
                break;
            }
        }
    }

    if ( ! $has_neoweaver ) return '';

    // Pobierz postacie gracza
    $characters = function_exists( 'neoweaver_get_player_characters' )
        ? neoweaver_get_player_characters( get_current_user_id() )
        : [];

    ob_start();

    if ( empty( $characters ) ) {
        $create_url = home_url( '/new-agent/' );
        echo '<div class="neoweaver-agent-select woocommerce-info" style="margin:16px 0;">';
        echo '⚠️ You have no active Field Agents. ';
        echo '<a href="' . esc_url( $create_url ) . '">Create a character</a> before purchasing.';
        echo '</div>';
    } else {
        echo '<div class="neoweaver-agent-select" style="margin:16px 0;">';
        echo '<label for="neoweaver_character_id" style="display:block;font-weight:600;margin-bottom:6px;">';
        echo '⚔️ Which Field Agent receives this item?</label>';
        echo '<select name="neoweaver_character_id" id="neoweaver_character_id" class="woocommerce-select" required>';
        echo '<option value="">— Choose your Field Agent —</option>';
        foreach ( $characters as $char ) {
            echo '<option value="' . esc_attr( $char['id'] ) . '">' . esc_html( $char['name'] ) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    return ob_get_clean();
}
