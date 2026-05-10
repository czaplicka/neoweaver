<?php
add_shortcode('tw_weaver_list', 'tw_display_weaver_list');

function tw_display_weaver_list() {

    // 1. Get character ID via CORE handler
    $char_id = tw_get_current_character_id();

    if ( ! $char_id || $char_id == 0 ) {
        return '<div class="tw-weaver-error">No active character found. Please select a character first.</div>';
    }

    // 2. Get Supabase credentials via CORE functions
    $supabase_url = tw_supabase_url();
    $anon_key     = tw_supabase_anon_key();

    if ( empty( $supabase_url ) || empty( $anon_key ) ) {
        return '<p>Configuration Error: Supabase credentials missing.</p>';
    }

    // 3. Fetch Weaves for this character
    $url = add_query_arg(
        [
            'character_id' => 'eq.' . intval( $char_id ), // BUG FIX: cast to int, prevents injection
            'is_consumed'  => 'eq.false',                 // BUG FIX: was 'is.false' — use eq.false for non-nullable booleans
            'select'       => '*',
        ],
        trailingslashit( $supabase_url ) . 'rest/v1/cyber_weaves'
    );

    $response = wp_remote_get( $url, [
        'headers' => [
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
            'Content-Type'  => 'application/json',
        ],
        'timeout'   => apply_filters( 'tw_supabase_timeout', 15 ),       // OPTIMIZATION: filterable for testing
        'sslverify' => apply_filters( 'tw_supabase_sslverify', true ),   // OPTIMIZATION: filterable for local dev
    ] );

    // BUG FIX: check for WP transport errors
    if ( is_wp_error( $response ) ) {
        return '<p>Connection Error: ' . esc_html( $response->get_error_message() ) . '</p>';
    }

    // BUG FIX: check HTTP status code — previously a 401/500 would silently parse an error body
    $status_code = wp_remote_retrieve_response_code( $response );
    if ( $status_code < 200 || $status_code >= 300 ) {
        return '<p>API Error: Received HTTP ' . intval( $status_code ) . '.</p>';
    }

    $weaves = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $weaves ) || ! is_array( $weaves ) ) {
        return '<div class="tw-no-weaves">Your Weaver pouch is empty. Dissolve items or complete quests to gain Weaves.</div>';
    }

    // 4. Enqueue CSS only once per page load
    // OPTIMIZATION: prevents duplicate <style> blocks when shortcode is used multiple times
    tw_weaver_enqueue_styles();

    // 5. Build HTML output
    $output  = '<div class="tw-weaver-container">';
    $output .= '<div class="tw-weaver-grid">';

    foreach ( $weaves as $weave ) {
        $rarity = strtolower( $weave['rarity'] ?? 'common' );

        // BUG FIX: ENT_QUOTES added — safe for use in both attribute and text contexts
        $tag  = htmlspecialchars( $weave['tag_reference'] ?? 'General', ENT_QUOTES, 'UTF-8' );
        $name = htmlspecialchars( $weave['name']          ?? 'Unknown Weave', ENT_QUOTES, 'UTF-8' );
        $desc = htmlspecialchars( $weave['description']   ?? '', ENT_QUOTES, 'UTF-8' );

        // OPTIMIZATION: whitelist rarity to prevent arbitrary CSS class injection
        $allowed_rarities = [ 'common', 'uncommon', 'rare', 'epic', 'legendary' ];
        $rarity_class     = in_array( $rarity, $allowed_rarities, true ) ? $rarity : 'common';

        $output .= "
        <div class='tw-weaver-card rarity-{$rarity_class}'>
            <div class='tw-weaver-header'>
                <span class='tw-weaver-name'>{$name}</span>
                <span class='tw-weaver-tag'>#{$tag}</span>
            </div>
            <div class='tw-weaver-desc'>{$desc}</div>
            <div class='tw-weaver-footer'>
                <span class='tw-rarity-label'>{$rarity_class}</span>
            </div>
        </div>";
    }

    $output .= '</div></div>';

    return $output;
}

/**
 * Enqueue weaver styles once per page load.
 * Uses a static flag so multiple shortcode instances don't duplicate CSS.
 */
function tw_weaver_enqueue_styles() {
    static $styles_added = false; // OPTIMIZATION: static flag prevents duplicate injection

    if ( $styles_added ) {
        return;
    }
    $styles_added = true;

    // Register a dummy handle if not already registered, then append inline CSS
    if ( ! wp_style_is( 'tw-weaver', 'registered' ) ) {
        wp_register_style( 'tw-weaver', false, [], null ); // false src = inline-only handle
    }
    wp_enqueue_style( 'tw-weaver' );

    wp_add_inline_style( 'tw-weaver', '
        .tw-weaver-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            padding: 10px 0;
        }
        .tw-weaver-card {
            background: rgba(20, 20, 20, 0.9);
            border: 1px solid #333;
            border-radius: 4px;
            padding: 15px;
            position: relative;
            font-family: "Chakra Petch", sans-serif;
            transition: all 0.3s ease;
        }
        .tw-weaver-card:hover {
            border-color: #adff00;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(173, 255, 0, 0.1);
        }
        .tw-weaver-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            border-bottom: 1px solid #222;
            padding-bottom: 8px;
        }
        .tw-weaver-name {
            font-weight: 700;
            color: #fff;
            font-size: 1rem;
            text-transform: uppercase;
        }
        .tw-weaver-tag {
            color: #adff00;
            font-size: 0.75rem;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .tw-weaver-desc {
            font-size: 0.85rem;
            color: #bbb;
            line-height: 1.4;
            min-height: 40px;
        }
        .tw-weaver-footer {
            margin-top: 10px;
            text-align: right;
        }
        .tw-rarity-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            padding: 2px 6px;
            background: #222;
            border-radius: 2px;
            color: #888;
        }
        .rarity-common    { border-top: 3px solid #888; }
        .rarity-uncommon  { border-top: 3px solid #00ff88; }
        .rarity-rare      { border-top: 3px solid #0088ff; }
        .rarity-epic      { border-top: 3px solid #a033ff; }
        .rarity-legendary { border-top: 3px solid #ffaa00; }
        .tw-no-weaves, .tw-weaver-error {
            padding: 20px;
            background: #111;
            border: 1px dashed #333;
            color: #666;
            text-align: center;
        }
    ' );
}
