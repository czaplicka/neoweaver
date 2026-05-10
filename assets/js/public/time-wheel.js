(function () {
    var container    = document.getElementById('tw-clock-container');
    if (!container) return;

    var disk         = document.getElementById('tw-time-disk');
    var weatherIcon  = document.getElementById('tw-weather-icon');
    var weatherLabel = document.getElementById('tw-weather-label');
    var nextWeather  = document.getElementById('tw-next-weather');
    var seasonName   = document.getElementById('tw-season-name');
    var wrapper      = container.querySelector('.tw-clock-wrapper');
    var campaignId   = container.dataset.campaignId;

    var ICONS  = { Sun: '☀️', Cloudy: '☁️', Rain: '🌧️', Fog: '🌫️' };
    var COLORS = { Spring: '#adff00', Summer: '#ffcc00', Autumn: '#ff5500', Winter: '#00ffff' };

    if (disk) {
        var initialHour = parseInt(disk.dataset.hour, 10) || 0;
        disk.style.transform = 'rotate(-' + (initialHour * 15) + 'deg)';
    }

    var refreshTimer = null;
    function scheduleRefresh() {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(doRefresh, 150);
    }

    function doRefresh() {
        var supabase = window.twSupabase;
        if (!supabase || !campaignId) return;

        supabase
            .from('cyber_world_state')
            .select('*')
            .eq('campaign_id', campaignId)
            .maybeSingle()
            .then(function (result) {
                var data = result.data;
                if (result.error || !data) return;

                if (disk)         disk.style.transform    = 'rotate(-' + (data.current_hour * 15) + 'deg)';
                if (weatherIcon)  weatherIcon.textContent = ICONS[data.current_weather] || '☀️';
                if (weatherLabel) weatherLabel.textContent = (data.current_weather || '').toUpperCase();
                if (nextWeather)  nextWeather.textContent  = ICONS[data.next_weather] || '☀️';
                if (seasonName)   seasonName.textContent   = data.current_season || '';

                if (wrapper && COLORS[data.current_season]) {
                    wrapper.style.setProperty('--season-color', COLORS[data.current_season]);
                }
            });
    }

    function initSubscription() {
        var supabase = window.twSupabase;
        if (!supabase || !campaignId) return;

        supabase
            .channel('public:cyber_world_state:' + campaignId)
            .on('postgres_changes', {
                event: 'UPDATE',
                schema: 'public',
                table: 'cyber_world_state',
                filter: 'campaign_id=eq.' + campaignId
            }, function () {
                scheduleRefresh();
            })
            .subscribe();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSubscription);
    } else {
        initSubscription();
    }
}());
