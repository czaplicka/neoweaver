<?php
/**
 * 1. Rejestracja assetów (CSS i JS)
 * Skrypty ładowane są tylko wtedy, gdy na stronie znajduje się shortcode.
 */
function neoweaver_enqueue_services_assets() {
    if ( is_admin() ) {
        return;
    }

    global $post;
    // Sprawdzamy, czy post istnieje i czy zawiera nasz shortcode
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'neoweave_services' ) ) {
        
        // Zakładamy strukturę: /twoj-plugin/public/assets/...
        // dirname( __FILE__, 2 ) cofa się o dwa poziomy do głównego folderu pluginu
        $plugin_url = plugin_dir_url( dirname( __FILE__, 1 ) );

        // Enqueue CSS
        wp_enqueue_style(
            'neoweave-services-css',
            $plugin_url . 'public/assets/css/services.css',
            [],
            '1.1.0'
        );

        // Enqueue JS
        wp_enqueue_script(
            'neoweave-services-js',
            $plugin_url . 'public/assets/js/services.js',
            ['jquery'],
            '1.1.0',
            true // Ładowanie w stopce
        );

        // Opcjonalnie: Przekazanie zmiennych z PHP do JS (np. AJAX URL)
        wp_localize_script('neoweave-services-js', 'neoData', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('neo_service_nonce')
        ]);
    }
}
// Ważne: używamy hooka wp_enqueue_scripts
add_action( 'wp_enqueue_scripts', 'neoweaver_enqueue_services_assets' );


/**
 * 2. Definicja Shortcode [neoweave_services]
 */
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

    <div id="neo-service-modal" class="neo-modal-overlay" style="display: none;">
        <div class="neo-modal-container">
            <div class="crt-overlay"></div>
            
            <div class="neo-modal-header">
                <span class="terminal-prefix">CMD: <span id="neo-npc-name">SERVICE_LINK</span></span>
                <div class="close-modal" onclick="closeServiceModal()">[X]</div>
            </div>

            <div class="neo-modal-body">
                <div id="neo-npc-avatar" style="display:none; width: 50px; height: 50px; background-size: cover; border: 1px solid #adff00; margin: 10px auto;"></div>
                
                <div id="neo-services-list" class="services-grid">
                    </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('neoweave_services', 'neoweave_service_modal_shortcode');
