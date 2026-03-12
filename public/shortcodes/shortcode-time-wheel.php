add_shortcode('tw_time_wheel', 'tw_display_time_wheel');

function tw_display_time_wheel() {
    // 1. Pobieranie dynamicznego campaign_id dla zalogowanego gracza
    $wp_user_id = get_current_user_id();
    $game_data = get_user_game_data_from_supabase($wp_user_id);
    
    // Jeśli brak aktywnej kampanii, domyślnie 1, ale pobieramy z sesji
    $campaign_id = ($game_data['active_campaign_id'] > 0) ? $game_data['active_campaign_id'] : 1;

    $supabase_url = tw_supabase_url();
    $anon_key = tw_supabase_anon_key();

    if (empty($supabase_url)) return "";

    $url = trailingslashit($supabase_url) . "rest/v1/cyber_world_state?campaign_id=eq." . $campaign_id;
    $response = wp_remote_get($url, [
        'headers' => ['apikey' => $anon_key, 'Authorization' => 'Bearer ' . $anon_key]
    ]);

    if (is_wp_error($response)) return "";
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    // Zabezpieczenie przed brakiem danych w tabeli dla danej kampanii
    if (empty($body)) {
        return "<div id='tw-clock-container' data-campaign-id='$campaign_id'>Brak danych czasu dla kampanii $campaign_id</div>";
    }

    $data = $body[0];
    $hour = $data['current_hour'] ?? 0;
    $current_weather = $data['current_weather'] ?? 'Sun';
    $next_weather = $data['next_weather'] ?? 'Sun';
    $season = $data['current_season'] ?? 'Spring';

    $rotation = $hour * 15;
    $season_colors = [
        'Spring' => '#adff00', 'Summer' => '#ffcc00', 
        'Autumn' => '#ff5500', 'Winter' => '#00ffff'
    ];
    $c_color = $season_colors[$season] ?? '#adff00';
    $icons = ['Sun'=>'☀️', 'Cloudy'=>'☁️', 'Rain'=>'🌧️', 'Fog'=>'🌫️'];
    
    ob_start(); ?>
    <div id="tw-clock-container" data-campaign-id="<?php echo $campaign_id; ?>">
        <style>
            .tw-clock-wrapper {
                position: relative;
                width: 180px;
                height: 180px;
                margin-bottom: -90px;
                margin-left: -30px;
                font-family: 'Chakra Petch', sans-serif;
                z-index: 999;
            }
            .tw-main-disk {
                position: absolute;
                width: 140px;
                height: 140px;
                border-radius: 50%;
                border: 3px solid var(--season-color, <?php echo $c_color; ?>);
                background: conic-gradient(from 0deg, #050505 0deg 75deg, #ff8800 75deg 150deg, #44ccff 150deg 285deg, #220044 285deg 360deg);
                transform: rotate(-<?php echo $rotation; ?>deg);
                transition: transform 2s cubic-bezier(0.4, 0, 0.2, 1);
                box-shadow: inset 0 0 15px rgba(0,0,0,0.8), 0 0 15px var(--season-color, <?php echo $c_color; ?>)44;
            }
            .tw-center-hub {
                position: absolute;
                top: 70px; left: 70px;
                transform: translate(-50%, -50%);
                width: 54px; height: 54px;
                background: #000;
                border: 2px solid var(--season-color, <?php echo $c_color; ?>);
                border-radius: 50%;
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                z-index: 1001;
            }
            .tw-forecast-bubble {
                position: absolute;
                top: 10px; right: 10px;
                width: 40px; height: 40px;
                background: rgba(0,0,0,0.8);
                border: 1px solid var(--season-color, <?php echo $c_color; ?>);
                border-radius: 50%;
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                z-index: 1005; font-size: 14px;
            }
            .tw-season-tag {
                position: absolute;
                top: 110px; right: 0;
                background: var(--season-color, <?php echo $c_color; ?>);
                color: #000; padding: 1px 6px;
                font-size: 10px; font-weight: bold;
                transform: rotate(-10deg); z-index: 1006;
            }
            .tw-pointer { position: absolute; top: -15px; left: 70px; transform: translateX(-50%); color: var(--season-color, <?php echo $c_color; ?>); font-size: 12px; z-index: 1010; overflow: visible; }
        </style>

        <div class="tw-clock-wrapper" style="--season-color: <?php echo $c_color; ?>;">
            <div class="tw-pointer">▼</div>
            <div class="tw-main-disk" id="tw-time-disk" style="transform: rotate(-<?php echo $rotation; ?>deg);"></div>
            <div class="tw-center-hub">
                <span id="tw-weather-icon" style="font-size: 24px; line-height: 1;"><?php echo $icons[$current_weather] ?? '☀️'; ?></span>
                <span id="tw-weather-label" style="font-size: 7px; color: var(--season-color);"><?php echo strtoupper($current_weather); ?></span>
            </div>
            <div class="tw-forecast-bubble">
                <span style="font-size: 7px; color: var(--season-color); text-transform: uppercase;">Next</span>
                <span id="tw-next-weather"><?php echo $icons[$next_weather] ?? '☀️'; ?></span>
            </div>
            <div class="tw-season-tag" id="tw-season-name"><?php echo $season; ?></div>
        </div>
    </div>
<script>async function refreshTwClock() {
    const supabase = window.twSupabase;
    const clockContainer = document.querySelector('#tw-clock-container');
    const campaignId = clockContainer?.dataset.campaignId;

    if (!supabase || !campaignId || !clockContainer) return;

    console.log('🔄 Refreshing world state for campaign:', campaignId);

    const { data, error } = await supabase
        .from('cyber_world_state')
        .select('*')
        .eq('campaign_id', campaignId)
        .maybeSingle();

    if (error || !data) return;

    const icons = {'Sun':'☀️', 'Cloudy':'☁️', 'Rain':'🌧️', 'Fog':'🌫️'};
    const colors = {'Spring': '#adff00', 'Summer': '#ffcc00', 'Autumn': '#ff5500', 'Winter': '#00ffff'};

    const disk = document.querySelector('#tw-time-disk');
    const weatherIcon = document.querySelector('#tw-weather-icon');
    const weatherLabel = document.querySelector('#tw-weather-label');
    const nextWeather = document.querySelector('#tw-next-weather');
    const seasonName = document.querySelector('#tw-season-name');
    const wrapper = document.querySelector('.tw-clock-wrapper');

    if (disk) disk.style.transform = `rotate(-${data.current_hour * 15}deg)`;
    if (weatherIcon) weatherIcon.innerText = icons[data.current_weather] || '☀️';
    if (weatherLabel) weatherLabel.innerText = (data.current_weather || '').toUpperCase();
    if (nextWeather) nextWeather.innerText = icons[data.next_weather] || '☀️';
    if (seasonName) seasonName.innerText = data.current_season;
    
    if (wrapper && colors[data.current_season]) {
        wrapper.style.setProperty('--season-color', colors[data.current_season]);
    }
}
document.addEventListener('DOMContentLoaded', () => {
    const supabase = window.twSupabase;
    const campaignId = document.querySelector('#tw-clock-container')?.dataset.campaignId;

    if (supabase && campaignId) {
        // Subskrypcja na żywo zmian w world_state
        supabase
            .channel('public:cyber_world_state')
            .on('postgres_changes', { 
                event: 'UPDATE', 
                schema: 'public', 
                table: 'cyber_world_state',
                filter: `campaign_id=eq.${campaignId}` 
            }, payload => {
                console.log('🌍 World State Updated!', payload.new);
                refreshTwClock(); // Wywołuje Twoją funkcję odświeżania wizualnego
            })
            .subscribe();
    }
});
</script>
    <?php
    return ob_get_clean();
}