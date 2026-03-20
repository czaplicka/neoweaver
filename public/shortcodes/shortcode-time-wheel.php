<?php
add_shortcode('tw_time_wheel', 'tw_display_time_wheel');

function tw_display_time_wheel() {
    $wp_user_id = get_current_user_id();
    $game_data  = get_user_game_data_from_supabase( $wp_user_id );

    // Bug fix: null-safe access before using array key
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

    if ( empty( $body ) ) {
        return "<div id='tw-clock-container' data-campaign-id='" . esc_attr( $campaign_id ) . "'>Brak danych czasu dla kampanii " . esc_html( $campaign_id ) . "</div>";
    }

    $data            = $body[0];
    $hour            = (int) ( $data['current_hour']  ?? 0 );
    $current_weather = $data['current_weather'] ?? 'Sun';
    $next_weather    = $data['next_weather']    ?? 'Sun';
    $season          = $data['current_season']  ?? 'Spring';

    // Bug fix: rotation is computed once, used consistently — no duplication
    $rotation = $hour * 15;

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

    // Bug fix: CSS is printed only once per page via wp_add_inline_style / did_action guard
    $style_id = 'tw-time-wheel-styles';
    if ( ! wp_style_is( $style_id, 'done' ) ) {
        // Attach to an already-enqueued handle, or register a dummy one
        if ( ! wp_style_is( 'tw-base', 'registered' ) ) {
            wp_register_style( 'tw-base', false );
        }
        wp_enqueue_style( 'tw-base' );
        wp_add_inline_style( 'tw-base', tw_time_wheel_css() );
    }

    ob_start(); ?>
    <div id="tw-clock-container" data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>">
        <div class="tw-clock-wrapper" style="--season-color: <?php echo esc_attr( $c_color ); ?>;">
            <div class="tw-pointer">▼</div>
            <?php
            // Bug fix: only set the CSS custom property; the <style> block already defines
            // the base transform. Inline style is removed so the CSS transition on
            // .tw-main-disk fires correctly on first load too.
            ?>
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

    <script>
    (function () {
        // Bug fix: cache all DOM references once; guard against missing elements
        var container    = document.getElementById('tw-clock-container');
        var disk         = document.getElementById('tw-time-disk');
        var weatherIcon  = document.getElementById('tw-weather-icon');
        var weatherLabel = document.getElementById('tw-weather-label');
        var nextWeather  = document.getElementById('tw-next-weather');
        var seasonName   = document.getElementById('tw-season-name');
        var wrapper      = container && container.querySelector('.tw-clock-wrapper');

        var campaignId   = container && container.dataset.campaignId;

        var ICONS  = { Sun: '☀️', Cloudy: '☁️', Rain: '🌧️', Fog: '🌫️' };
        var COLORS = { Spring: '#adff00', Summer: '#ffcc00', Autumn: '#ff5500', Winter: '#00ffff' };

        // Bug fix: apply initial rotation via JS (keeps CSS transition intact on first load)
        if ( disk ) {
            var initialHour = parseInt( disk.dataset.hour, 10 ) || 0;
            disk.style.transform = 'rotate(-' + ( initialHour * 15 ) + 'deg)';
        }

        // Bug fix: debounce so rapid realtime updates don't stack
        var refreshTimer = null;
        function scheduleRefresh() {
            clearTimeout( refreshTimer );
            refreshTimer = setTimeout( doRefresh, 150 );
        }

        function doRefresh() {
            var supabase = window.twSupabase;
            if ( ! supabase || ! campaignId ) return;

            supabase
                .from('cyber_world_state')
                .select('*')
                .eq('campaign_id', campaignId)
                .maybeSingle()
                .then(function ( result ) {
                    var data = result.data;
                    if ( result.error || ! data ) return;

                    if ( disk )         disk.style.transform    = 'rotate(-' + ( data.current_hour * 15 ) + 'deg)';
                    if ( weatherIcon )  weatherIcon.textContent = ICONS[ data.current_weather ] || '☀️';
                    if ( weatherLabel ) weatherLabel.textContent = ( data.current_weather || '' ).toUpperCase();
                    if ( nextWeather )  nextWeather.textContent  = ICONS[ data.next_weather ] || '☀️';
                    if ( seasonName )   seasonName.textContent   = data.current_season || '';

                    if ( wrapper && COLORS[ data.current_season ] ) {
                        wrapper.style.setProperty('--season-color', COLORS[ data.current_season ]);
                    }
                } );
        }

        // Bug fix: subscribe only once; guard against twSupabase not yet ready
        function initSubscription() {
            var supabase = window.twSupabase;
            if ( ! supabase || ! campaignId ) return;

            supabase
                .channel('public:cyber_world_state:' + campaignId)
                .on('postgres_changes', {
                    event: 'UPDATE',
                    schema: 'public',
                    table: 'cyber_world_state',
                    filter: 'campaign_id=eq.' + campaignId
                }, function ( payload ) {
                    console.log('🌍 World State Updated!', payload.new);
                    scheduleRefresh();
                })
                .subscribe();
        }

        if ( document.readyState === 'loading' ) {
            document.addEventListener('DOMContentLoaded', initSubscription);
        } else {
            // Bug fix: if DOMContentLoaded already fired (e.g. shortcode loaded via AJAX),
            // call immediately instead of relying on an event that already passed
            initSubscription();
        }
    }());
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Returns the shared CSS string for the time wheel.
 * Extracted so it is output only once per page load.
 */
function tw_time_wheel_css() {
    return '
    .tw-clock-wrapper {
        position: relative;
        width: 180px;
        height: 180px;
        margin-bottom: -90px;
        margin-left: -30px;
        font-family: "Chakra Petch", sans-serif;
        z-index: 999;
    }
    .tw-main-disk {
        position: absolute;
        width: 140px;
        height: 140px;
        border-radius: 50%;
        border: 3px solid var(--season-color, #adff00);
        background: conic-gradient(from 0deg, #050505 0deg 75deg, #ff8800 75deg 150deg, #44ccff 150deg 285deg, #220044 285deg 360deg);
        /* Bug fix: no inline transform duplicate — transition works from first render */
        transition: transform 2s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: inset 0 0 15px rgba(0,0,0,0.8), 0 0 15px color-mix(in srgb, var(--season-color, #adff00) 27%, transparent);
    }
    .tw-center-hub {
        position: absolute;
        top: 70px; left: 70px;
        transform: translate(-50%, -50%);
        width: 54px; height: 54px;
        background: #000;
        border: 2px solid var(--season-color, #adff00);
        border-radius: 50%;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        z-index: 1001;
    }
    .tw-forecast-bubble {
        position: absolute;
        top: 10px; right: 10px;
        width: 40px; height: 40px;
        background: rgba(0,0,0,0.8);
        border: 1px solid var(--season-color, #adff00);
        border-radius: 50%;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        z-index: 1005; font-size: 14px;
    }
    .tw-season-tag {
        position: absolute;
        top: 110px; right: 0;
        background: var(--season-color, #adff00);
        color: #000; padding: 1px 6px;
        font-size: 10px; font-weight: bold;
        transform: rotate(-10deg); z-index: 1006;
    }
    .tw-pointer {
        position: absolute;
        top: -15px; left: 70px;
        transform: translateX(-50%);
        color: var(--season-color, #adff00);
        font-size: 12px; z-index: 1010; overflow: visible;
    }
    ';
}
