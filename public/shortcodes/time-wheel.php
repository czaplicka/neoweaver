<?php
/**
 * NeoWeaver – Time Wheel Assets
 *
 * Registers and conditionally enqueues the Time Wheel CSS + JS,
 * then exposes config data via wp_localize_script().
 *
 * Enqueue strategy:
 *  - wp_register_*  runs always (init / wp_enqueue_scripts)
 *  - actual enqueue happens only when the shortcode is rendered
 *    (shortcode callback calls wp_enqueue_* on demand)
 *
 * @package Neoweaver
 */

/* ==========================================================================
   1. REGISTER (always, once)
   ========================================================================== */
add_action( 'wp_enqueue_scripts', 'neoweaver_register_time_wheel_assets' );

function neoweaver_register_time_wheel_assets(): void {
    $plugin_url = plugin_dir_url( __FILE__ );
    $plugin_dir = plugin_dir_path( __FILE__ );

    // --- CSS ---
    $css_rel  = 'assets/css/public/time-wheel.css';
    $css_path = $plugin_dir . $css_rel;
    $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : '1.0.0';

    wp_register_style(
        'neoweaver-time-wheel',
        $plugin_url . $css_rel,
        array(),
        $css_ver
    );

    // --- JS ---
    $js_rel  = 'assets/js/public/time-wheel.js';
    $js_path = $plugin_dir . $js_rel;
    $js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : '1.0.0';

    wp_register_script(
        'neoweaver-time-wheel',
        $plugin_url . $js_rel,
        array(),   // no jQuery dependency
        $js_ver,
        true       // footer
    );
}

/* ==========================================================================
   2. SHORTCODE  [tw_time_wheel]
   ========================================================================== */
add_shortcode( 'tw_time_wheel', 'neoweaver_render_time_wheel' );

function neoweaver_render_time_wheel(): string {

    // --- Gate: must be logged in ---
    $wp_user_id = get_current_user_id();
    if ( ! $wp_user_id ) {
        return '';
    }

    // --- Gate: Supabase credentials must exist ---
    if ( ! function_exists( 'tw_supabase_url' ) || ! tw_supabase_url() ) {
        return '';
    }
    if ( ! function_exists( 'tw_supabase_anon_key' ) || ! tw_supabase_anon_key() ) {
        return '';
    }

    $supabase_url = tw_supabase_url();
    $anon_key     = tw_supabase_anon_key();

    // --- Resolve active campaign ---
    $game_data   = get_user_game_data_from_supabase( $wp_user_id );
    $campaign_id = ( is_array( $game_data ) && ! empty( $game_data['active_campaign_id'] ) )
        ? (int) $game_data['active_campaign_id']
        : 1;

    // --- Fetch world state from Supabase ---
    $url      = trailingslashit( $supabase_url )
              . 'rest/v1/cyber_world_state?campaign_id=eq.' . $campaign_id;

    $response = wp_remote_get( $url, array(
        'headers' => array(
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
        ),
    ) );

    if ( is_wp_error( $response ) ) {
        return '';
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $body ) || ! is_array( $body ) || ! isset( $body[0] ) ) {
        return sprintf(
            "<div id='tw-clock-container' data-campaign-id='%s'>No time data for campaign %s</div>",
            esc_attr( $campaign_id ),
            esc_html( $campaign_id )
        );
    }

    // --- Parse data ---
    $data            = $body[0];
    $hour            = (int) ( $data['current_hour']  ?? 0 );
    $current_weather = $data['current_weather'] ?? 'Sun';
    $next_weather    = $data['next_weather']    ?? 'Sun';
    $season          = $data['current_season']  ?? 'Spring';

    $season_colors = array(
        'Spring' => '#adff00',
        'Summer' => '#ffcc00',
        'Autumn' => '#ff5500',
        'Winter' => '#00ffff',
    );
    $weather_icons = array(
        'Sun'    => '☀️',
        'Cloudy' => '☁️',
        'Rain'   => '🌧️',
        'Fog'    => '🌫️',
    );

    $season_color = $season_colors[ $season ] ?? '#adff00';
    $weather_icon = $weather_icons[ $current_weather ] ?? '☀️';
    $next_icon    = $weather_icons[ $next_weather ]    ?? '☀️';

    // --- Enqueue assets (on-demand, only this page) ---
    wp_enqueue_style( 'neoweaver-time-wheel' );
    wp_enqueue_script( 'neoweaver-time-wheel' );

    // Pass dynamic config to JS (avoids inline JS / nonces needed separately)
    wp_localize_script(
        'neoweaver-time-wheel',
        'twClockConfig',
        array(
            'supabaseUrl'  => $supabase_url,
            'anonKey'      => $anon_key,
            'campaignId'   => $campaign_id,
            'initialHour'  => $hour,
            'season'       => $season,
            'seasonColor'  => $season_color,
            'weather'      => $current_weather,
            'nextWeather'  => $next_weather,
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'tw_time_wheel' ),
        )
    );

    // --- Render HTML ---
    ob_start(); ?>
    <div id="tw-clock-container" data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>">
        <div class="tw-clock-wrapper" style="--season-color: <?php echo esc_attr( $season_color ); ?>;">

            <div class="tw-pointer">▼</div>

            <div class="tw-main-disk" id="tw-time-disk"
                 data-hour="<?php echo esc_attr( $hour ); ?>"></div>

            <div class="tw-center-hub">
                <span id="tw-weather-icon"
                      style="font-size:24px;line-height:1;"
                      aria-label="<?php echo esc_attr( $current_weather ); ?>"
                ><?php echo $weather_icon; ?></span>
                <span id="tw-weather-label"
                      style="font-size:7px;color:var(--season-color);"
                ><?php echo esc_html( strtoupper( $current_weather ) ); ?></span>
            </div>

            <div class="tw-forecast-bubble">
                <span style="font-size:7px;color:var(--season-color);text-transform:uppercase;">Next</span>
                <span id="tw-next-weather"
                      aria-label="<?php echo esc_attr( $next_weather ); ?>"
                ><?php echo $next_icon; ?></span>
            </div>

            <div class="tw-season-tag" id="tw-season-name"><?php echo esc_html( $season ); ?></div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}
