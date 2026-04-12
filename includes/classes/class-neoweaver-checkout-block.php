<?php
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

class NeoWeaver_Checkout_Block_Integration implements IntegrationInterface {

    public function get_name() {
        return 'neoweaver-character-select';
    }

    public function initialize() {
        $this->register_scripts();
        $this->extend_store_api();
    }

    private function register_scripts() {
        wp_register_script(
            'neoweaver-checkout-block',
            plugin_dir_url( __FILE__ ) . '../assets/checkout-block.js',
            [ 'wc-blocks-checkout', 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-components', 'wp-i18n' ],
            '1.0.0',
            true
        );

        // Przekaż dane PHP → JS
        $characters = $this->get_characters_for_current_user();
        wp_localize_script( 'neoweaver-checkout-block', 'neoweaverCheckout', [
            'characters'   => $characters,
            'hasNeoweaver' => $this->cart_has_neoweaver_item(),
            'createUrl'    => '/new-agent/',
        ] );
    }

    private function get_characters_for_current_user() {
        $user_id  = get_current_user_id();
        if ( ! $user_id ) return [];

        $supa_url = defined('SUPABASE_URL') ? SUPABASE_URL : DB_SUPABASE_URL;
        $supa_key = defined('SUPABASE_KEY') ? SUPABASE_KEY : DB_SUPABASE_KEY;

        $response = wp_remote_get(
            $supa_url . '/rest/v1/cyber_characters?wp_user_id=eq.' . $user_id . '&is_active=eq.true&select=id,name',
            [
                'headers' => [
                    'apikey'        => $supa_key,
                    'Authorization' => 'Bearer ' . $supa_key,
                ],
            ]
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return [];
        }

        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    private function cart_has_neoweaver_item() {
        if ( ! WC()->cart ) return false;
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( $cart_item['data']->get_attribute( 'neoweaver_item_id' ) ) return true;
        }
        return false;
    }

    public function get_script_handles() {
        return [ 'neoweaver-checkout-block' ];
    }

    public function get_editor_script_handles() {
        return [];
    }

    public function get_script_data() {
        return [];
    }

    // Rozszerzenie Store API — zapis character_id do zamówienia
    private function extend_store_api() {
        woocommerce_store_api_register_update_callback( [
            'namespace' => 'neoweaver-character-select',
            'callback'  => function( $data ) {
                if ( ! empty( $data['character_id'] ) ) {
                    WC()->session->set(
                        'neoweaver_character_id',
                        sanitize_text_field( $data['character_id'] )
                    );
                }
            },
        ] );
    }
}

// Zapisz character_id z session do meta zamówienia
add_action( 'woocommerce_store_api_checkout_order_processed', function( $order ) {
    $character_id = WC()->session->get( 'neoweaver_character_id' );
    if ( $character_id ) {
        $order->update_meta_data( '_neoweaver_character_id', $character_id );
        $order->save();
        WC()->session->__unset( 'neoweaver_character_id' );
    }
} );
