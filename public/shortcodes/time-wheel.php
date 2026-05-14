<?php
// 1. Rejestracja plików (bez automatycznego ładowania wszędzie)
function tw_register_time_wheel_assets() {
    wp_register_style(
        'tw-time-wheel',
        get_stylesheet_directory_uri() . '/assets/css/public/time-wheel.css',
        [],
        '1.0.0'
    );

    wp_register_script(
        'tw-time-wheel',
        get_stylesheet_directory_uri() . '/assets/js/public/time-wheel.js',
        [ 'jquery' ],
        '1.0.0',
        true
    );
}
add_action( 'wp_enqueue_scripts', 'tw_register_time_wheel_assets' );

// 2. Shortcode – ładuje pliki TYLKO tam, gdzie shortcode jest użyty
add_shortcode( 'tw_time_wheel', 'tw_display_time_wheel' );

function tw_display_time_wheel() {
    // enqueue tylko dla tej strony z shortcode
    wp_enqueue_style( 'tw-time-wheel' );
    wp_enqueue_script( 'tw-time-wheel' );

    $wp_user_id = get_current_user_id();
    $game_data  = get_user_game_data_from_supabase( $wp_user_id );

    $campaign_id = ( is_array( $game_data ) && ! empty( $game_data['active_campaign_id'] ) )
        ? (int) $game_data['active_campaign_id']
        : 1;

    $supabase_url = tw_supabase_url();
    $anon_key     = tw_supabase_anon_key();

    if ( empty( $supabase_url ) ) return '';

    $url      = trailingslashit( $supabase_url ) . 'rest/v1/cyber_world_state?campaign_id=eq.' . $campaign_id;
    $response = wp_remote_get( $url, [
        'headers' => [
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
        ],
    ] );

    if ( is_wp_error( $response ) ) return '';

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $body ) || ! is_array( $body ) || ! isset( $body[0] ) ) {
        return "<div id='tw-clock-container' data-campaign-id='" . esc_attr( $campaign_id ) . "'>Brak danych czasu dla kampanii " . esc_html( $campaign_id ) . "</div>";
    }

    $data            = $body[0];
    $hour            = (int) ( $data['current_hour']  ?? 0 );
    $current_weather = $data['current_weather'] ?? 'Sun';
    $next_weather    = $data['next_weather']    ?? 'Sun';
    $season          = $data['current_season']  ?? 'Spring';

    $season_colors = [
        'Spring' => '#adff00',
        'Summer' => '#ffcc00',
        'Autumn' => '#ff5500',
        'Winter' => '#00ffff',
    ];
    $icons = [
        'Sun'    => '☀️',
        'Cloudy' => '☁️',
        'Rain'   => '🌧️',
        'Fog'    => '🌫️',
    ];

    $c_color      = $season_colors[ $season ] ?? '#adff00';
    $weather_icon = $icons[ $current_weather ] ?? '☀️';
    $next_icon    = $icons[ $next_weather ]    ?? '☀️';

    ob_start(); ?>
    <div id="tw-clock-container" data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>">
        <div class="tw-clock-wrapper" style="--season-color: <?php echo esc_attr( $c_color ); ?>;">
            <div class="tw-pointer">▼</div>
            <div class="tw-main-disk" id="tw-time-disk" data-hour="<?php echo esc_attr( $hour ); ?>"></div>
            <div class="tw-center-hub">
                <span id="tw-weather-icon" style="font-size:24px;line-height:1;"><?php echo $weather_icon; ?></span>
                <span id="tw-weather-label" style="font-size:7px;color:var(--season-color);"><?php echo esc_html( strtoupper( $current_weather ) ); ?></span>
            </div>
            <div class="tw-forecast-bubble">
                <span style="font-size:7px;color:var(--season-color);text-transform:uppercase;">Next</span>
                <span id="tw-next-weather"><?php echo $next_icon; ?></span>
            </div>
            <div class="tw-season-tag" id="tw-season-name"><?php echo esc_html( $season ); ?></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
