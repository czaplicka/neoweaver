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
