<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Neoweaver_Core {

    public function __construct() {}

    public function run() {
        // Shortcodes
        add_action( 'init', [ $this, 'register_shortcodes' ] );
    }

    public function register_shortcodes(): void {
        // World Creator
        require_once plugin_dir_path( __FILE__ ) . 'shortcodes/world-creator.php';
        add_shortcode( 'tw_world_creator', 'neoweaver_shortcode_world_creator' );
        require_once plugin_dir_path( __FILE__ ) . 'shortcodes/character-creator.php';
        add_shortcode( 'tw_character_creator', 'neoweaver_shortcode_character_creator' );
                require_once plugin_dir_path( __FILE__ ) . 'shortcodes/campaign-creator.php';
        add_shortcode( 'tw_campaign_creator', 'neoweaver_shortcode_campaign_creator' );
    }
}
