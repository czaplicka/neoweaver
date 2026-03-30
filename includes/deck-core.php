<?php
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
        return;
    }
    wp_enqueue_script(
        'nw-deck-core',
        NEOWEAVER_PLUGIN_URL . 'assets/js/deck-core.js',
        [ 'jquery' ],
        NEOWEAVER_VERSION,
        true
    );
} );
