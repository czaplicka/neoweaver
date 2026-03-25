<?php
// 1. Rejestracja skryptów i styli
function neoweaver_enqueue_services_assets() {
    if ( is_admin() ) {
        return;
    }

    // Sprawdzamy czy shortcode jest obecny w treści postu
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'neoweave_services_panel' ) ) {
        
        $plugin_url = plugin_dir_url( dirname( __FILE__, 1 ) );

        // CSS
        wp_enqueue_style(
            'neoweave-services-css',
            $plugin_url . 'public/assets/css/services.css',
            [],
            '1.0.1'
        );

        // JS - poprawione z style na script
        wp_enqueue_script(
            'neoweave-services-js',
            $plugin_url . 'public/assets/js/services.js',
            ['jquery'],
            '1.0.1',
            true
        );
function neoweave_service_modal_shortcode() {
    ob_start();
    ?>
    <div id="neo-service-trigger" class="neo-service-icon" style="display: none;" onclick="toggleNeoModal()">
        <svg viewBox="0 0 24 24" fill="none" stroke="#adff00" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
            <path d="M13 8h2m-5 0h.01M9 12h.01M12 12h.01M15 12h.01" stroke-width="3"></path>
        </svg>
        <span class="pulse-ring"></span>
    </div>

    <div id="neo-service-modal" class="neo-modal-overlay">
        <div class="neo-modal-container">
            <div class="crt-overlay"></div>
            <div class="neo-modal-header">
                <span class="terminal-prefix">CMD: SERVICE_LINK</span>
                <div class="close-modal" onclick="toggleNeoModal()">[X]</div>
            </div>
            <div class="neo-modal-body">
                <div id="neo-services-list" class="services-grid">
                    </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('neoweave_services', 'neoweave_service_modal_shortcode');
