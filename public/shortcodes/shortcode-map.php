<?php
// ─── Enqueue map.js + D3 tylko na adventure template ─────────────────────────
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_page_template( 'templates/adventure.php' ) ) return;

    // D3.js z CDN — zarejestrowany przez WP, nie inline <script>
    wp_enqueue_script(
        'd3js',
        'https://d3js.org/d3.v7.min.js',
        [],
        '7',
        true
    );

    wp_enqueue_script(
        'nw-map',
        NEOWEAVER_PLUGIN_URL . 'assets/js/map.js',
        [ 'jquery', 'neoweaver-header-node', 'd3js' ], // d3js jako dependency
        NEOWEAVER_VERSION,
        true
    );
} );

// ─── Shortcode [cyber_active_map] ─────────────────────────────────────────────
function tw_render_active_game_map() {
    $wp_user_id = get_current_user_id();
    if ( ! $wp_user_id ) {
        return '<div style="padding:20px;color:red;">[ACCESS DENIED]: Link not established.</div>';
    }

    ob_start();
    ?>
    <div id="tw-map-container" style="width:100%;height:100%;min-height:400px;background:rgba(5,5,12,0.4);backdrop-filter:blur(8px);position:relative;overflow:hidden;">

        <div style="position:absolute;inset:0;background-image:linear-gradient(rgba(173,255,0,0.07) 1px,transparent 1px),linear-gradient(90deg,rgba(173,255,0,0.07) 1px,transparent 1px);background-size:60px 60px;pointer-events:none;"></div>

        <svg id="cyber-map" style="width:100%;height:100%;position:relative;z-index:10;cursor:grab;"></svg>

        <div id="map-legend-container" class="tw-map-legend"></div>

        <div id="location-card" style="position:absolute;top:15px;right:15px;width:240px;background:rgba(0,0,0,0.9);border:1px solid #adff00;padding:12px;display:none;color:#fff;font-family:'Chakra Petch',monospace;z-index:20;box-shadow:0 4px 15px rgba(0,0,0,0.8);backdrop-filter:blur(10px);">
            <h4 id="loc-title" style="color:#adff00;margin:0 0 8px 0;border-bottom:1px solid #333;padding-bottom:5px;font-size:1rem;text-transform:uppercase;"></h4>
            <div id="loc-kingdom" style="font-size:0.7rem;color:#888;margin-bottom:8px;text-transform:uppercase;letter-spacing:1px;font-weight:bold;"></div>
            <p id="loc-desc" style="font-size:0.8rem;line-height:1.4;color:#ccc;margin:0;"></p>
            <div id="loc-status" style="font-size:0.7rem;color:#adff00;font-weight:bold;margin-top:10px;border-top:1px solid #222;padding-top:5px;"></div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'cyber_active_map', 'tw_render_active_game_map' );
