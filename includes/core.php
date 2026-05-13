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
    $base = plugin_dir_path( dirname( __FILE__ ) ) . 'public/shortcodes/';

    require_once $base . 'shortcode-world-creator.php';
    add_shortcode( 'tw_world_creator', 'neoweaver_shortcode_world_creator' );

    require_once $base . 'shortcode-character-creator.php';
    add_shortcode( 'tw_character_creator', 'neoweaver_shortcode_character_creator' );

    require_once $base . 'shortcode-campaign-creator.php';
    add_shortcode( 'tw_campaign_creator', 'neoweaver_shortcode_campaign_creator' );
}
}
$neoweaver_core = new Neoweaver_Core();
$neoweaver_core->run();
