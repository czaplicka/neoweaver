<?php
/*
Plugin Name: NeoWeaver Core
Description: Core systems for the NeoWeaver AI realm game (characters, worlds, campaigns).
Version: 0.1.0
Author: Monika Czaplicka
Text Domain: neoweaver
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NEOWEAVER_CORE_VERSION', '0.1.0' );
define( 'NEOWEAVER_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'NEOWEAVER_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once NEOWEAVER_CORE_PATH . 'includes/class-neoweaver-core.php';

function neoweaver_core_run() {
    $plugin = new Neoweaver_Core();
    $plugin->run();
}
neoweaver_core_run();
