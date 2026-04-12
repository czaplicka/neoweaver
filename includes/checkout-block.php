<?php
add_action( 'woocommerce_blocks_loaded', function() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) ) return;

    require_once plugin_dir_path( __FILE__ ) . '../includes/class-neoweaver-checkout-block.php';

    add_action( 'woocommerce_blocks_checkout_block_registration', function( $integration_registry ) {
        $integration_registry->register( new NeoWeaver_Checkout_Block_Integration() );
    } );
} );
