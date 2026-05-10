<?php
add_shortcode('kingdom_info', function() {
    global $wpdb;

    $user_id = get_current_user_id();
    if (!$user_id) return "Zaloguj się.";

    $query = "
        SELECT 
            kingdom_name, 
            government_type,
            political_climate, 
            stability_score, 
            population_alive,
            territory_size
        FROM public.view_kingdom_status vks
        JOIN public.cyber_state_of_the_campaign s ON vks.kingdom_id = s.current_kingdom_id
        WHERE s.wp_user_id = %d
        LIMIT 1
    ";

    $data = $wpdb->get_row($wpdb->prepare($query, $user_id));

    if (!$data) {
        return "<div class='kingdom-card' style='--status-color: #555; padding: 20px; text-align: center;'>
                    <em style='color: #888;'>📡 Sygnał utracony: Brak danych o domenie...</em>
                </div>";
    }

    // Dobór ikony na podstawie ustroju
    switch (strtolower($data->government_type)) {
        case 'monarchy':    $icon = '👑'; break;
        case 'technocracy': $icon = '⚙️'; break;
        case 'theocracy':   $icon = '👁️'; break;
        case 'republic':    $icon = '🏛️'; break;
        case 'anarchy':     $icon = '💀'; break;
        case 'corporatocracy': $icon = '🏙️'; break;
        default:            $icon = '🚩'; break;
    }

    $color = ($data->political_climate == 'STABLE') ? '#00f2ff' : (($data->political_climate == 'UNSTABLE') ? '#ff0055' : '#ffaa00');
    $stability_percent = max(0, min(100, $data->stability_score));
?>
<style>
/* Kontener główny karty królestwa */
.kingdom-card {
    background: rgba(20, 20, 25, 0.85);
    border: 1px solid rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 4px; /* Bardziej kanciasty, techniczny wygląd */
    position: relative;
    overflow: hidden;
    font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    margin: 20px 0;
    transition: all 0.3s ease;
}

/* Górny pasek dekoracyjny */
.kingdom-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--status-color), transparent);
}

/* Nagłówek lokacji */
.kingdom-card h3 {
    text-transform: uppercase;
    letter-spacing: 2px;
    font-size: 1.1rem;
    color: #fff;
    text-shadow: 0 0 10px var(--status-color);
    margin-bottom: 15px !important;
}

/* Siatka statystyk */
.kingdom-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.stat-item {
    background: rgba(255, 255, 255, 0.03);
    padding: 10px;
    border-radius: 2px;
    border-left: 2px solid rgba(255, 255, 255, 0.1);
}

.stat-label {
    display: block;
    font-size: 0.65rem;
    text-transform: uppercase;
    color: #888;
    margin-bottom: 4px;
}

.stat-value {
    font-size: 0.9rem;
    font-weight: bold;
    color: #ddd;
}

/* Efekt skanowania (opcjonalny) */
.kingdom-card::after {
    content: "";
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(rgba(255,255,255,0.02) 50%, transparent 50%);
    background-size: 100% 4px;
    pointer-events: none;
    z-index: 1;
}
/* Kontener paska stabilności */
.stability-bar-container {
    width: 100%;
    height: 6px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 3px;
    margin: 8px 0 4px 0;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

/* Wypełnienie paska z poświatą neonową */
.stability-bar-fill {
    height: 100%;
    background: var(--status-color);
    box-shadow: 0 0 10px var(--status-color);
    transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
}

/* Animacja pulsowania paska dla klimatu UNSTABLE */
.stability-bar-fill::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
    animation: scan-light 2s infinite;
}

@keyframes scan-light {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}
</style>
<?php
    $output = "
    <div class='kingdom-card' style='--status-color: {$color};'>
        <div style='padding: 20px; position: relative; z-index: 2;'>
            <div style='display: flex; align-items: center; gap: 10px; margin-bottom: 15px;'>
                <span style='font-size: 1.5rem; text-shadow: 0 0 10px {$color};'>{$icon}</span>
                <h3 style='margin: 0 !important;'>{$data->kingdom_name}</h3>
            </div>
            
            <div class='kingdom-grid'>
                <div class='stat-item'>
                    <span class='stat-label'>Government</span>
                    <span class='stat-value'>{$data->government_type}</span>
                </div>
                <div class='stat-item'>
                    <span class='stat-label'>Climate</span>
                    <span class='stat-value' style='color:{$color}'>{$data->political_climate}</span>
                </div>
                <div class='stat-item' style='grid-column: span 2;'>
                    <span class='stat-label'>Stability Index</span>
                    <div class='stability-bar-container'>
                        <div class='stability-bar-fill' style='width: {$stability_percent}%;'></div>
                    </div>
                    <span class='stat-value' style='font-size: 0.7rem; opacity: 0.8;'>{$data->stability_score}% Cohesion</span>
                </div>
                <div class='stat-item'>
                    <span class='stat-label'>Population</span>
                    <span class='stat-value'>{$data->population_alive} souls</span>
                </div>
                <div class='stat-item'>
                    <span class='stat-label'>Domain Size</span>
                    <span class='stat-value'>{$data->territory_size} sectors</span>
                </div>
            </div>
        </div>
    </div>
    ";

    return $output;
});
